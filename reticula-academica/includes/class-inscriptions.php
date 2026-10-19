<?php
/**
 * Learner inscriptions in programs: storage, progress sync and self-service enrollment.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Inscriptions {

	private static $progress_cache = array();

	public static function init() {
		add_action( 'tutor_course_complete_after', array( __CLASS__, 'on_course_completed' ), 20, 2 );
		add_action( 'tutor_after_enrolled', array( __CLASS__, 'on_enrolled' ), 20, 2 );
		add_action( 'deleted_user', array( __CLASS__, 'delete_for_user' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
		add_action( 'admin_post_ra_enroll_program', array( __CLASS__, 'handle_self_enroll' ) );
		add_action( 'admin_post_nopriv_ra_enroll_program', array( __CLASS__, 'handle_self_enroll' ) );
	}

	public static function statuses() {
		return array(
			Progress::STATUS_PENDING   => __( 'Pending payment', 'reticula-academica' ),
			Progress::STATUS_ACTIVE    => __( 'Active', 'reticula-academica' ),
			Progress::STATUS_PAUSED    => __( 'Paused', 'reticula-academica' ),
			Progress::STATUS_DROPPED   => __( 'Dropped', 'reticula-academica' ),
			Progress::STATUS_GRADUATED => __( 'Graduated', 'reticula-academica' ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Storage
	 * ------------------------------------------------------------------ */

	public static function get( $id ) {
		global $wpdb;
		$table = Installer::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function find( $user_id, $program_id ) {
		global $wpdb;
		$table = Installer::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND program_id = %d", $user_id, $program_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return array[]
	 */
	public static function for_user( $user_id, $include_dropped = false ) {
		global $wpdb;
		$table = Installer::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at ASC", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_values(
			array_filter(
				(array) $rows,
				static function ( $row ) use ( $include_dropped ) {
					return $include_dropped || Progress::STATUS_DROPPED !== $row['status'];
				}
			)
		);
	}

	/**
	 * Admin listing.
	 *
	 * @return array [ rows, total ]
	 */
	public static function query( array $filters = array(), $limit = 0, $offset = 0 ) {
		global $wpdb;
		$table = Installer::table();
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['program_id'] ) ) {
			$where[] = 'i.program_id = %d';
			$args[]  = absint( $filters['program_id'] );
		}
		if ( ! empty( $filters['term_id'] ) ) {
			$where[] = 'i.current_term_id = %d';
			$args[]  = absint( $filters['term_id'] );
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'i.status = %s';
			$args[]  = (string) $filters['status'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = "(u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR i.user_id IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ra_matricula' AND meta_value LIKE %s))";
			array_push( $args, $like, $like, $like, $like );
		}
		$sql = "SELECT SQL_CALC_FOUND_ROWS i.*, u.display_name, u.user_email FROM {$table} i LEFT JOIN {$wpdb->users} u ON u.ID = i.user_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY i.updated_at DESC, i.id DESC';
		if ( $limit ) {
			$sql   .= ' LIMIT %d OFFSET %d';
			$args[] = (int) $limit;
			$args[] = (int) $offset;
		}
		$rows  = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		$total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' ); // phpcs:ignore WordPress.DB
		return array( (array) $rows, $total );
	}

	/**
	 * Create (or reactivate) an inscription.
	 *
	 * @param int   $user_id    Learner.
	 * @param int   $program_id Program.
	 * @param array $args       start_term_id, current_term_id, status, source, notes.
	 * @return array|\WP_Error Inscription row.
	 */
	public static function create( $user_id, $program_id, array $args = array() ) {
		global $wpdb;
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );
		if ( ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'ra_user', __( 'The student does not exist.', 'reticula-academica' ) );
		}
		if ( get_post_type( $program_id ) !== Curriculum::PROGRAM ) {
			return new \WP_Error( 'ra_program', __( 'The program does not exist.', 'reticula-academica' ) );
		}

		$terms    = wp_list_pluck( Curriculum::terms( $program_id, true ), 'id' );
		$start    = ! empty( $args['start_term_id'] ) && in_array( (int) $args['start_term_id'], array_map( 'intval', $terms ), true ) ? (int) $args['start_term_id'] : ( $terms ? (int) $terms[0] : 0 );
		$current  = ! empty( $args['current_term_id'] ) && in_array( (int) $args['current_term_id'], array_map( 'intval', $terms ), true ) ? (int) $args['current_term_id'] : $start;
		$settings = Curriculum::program_settings( $program_id );
		$status   = isset( $args['status'] ) && isset( self::statuses()[ $args['status'] ] ) ? $args['status'] : '';
		if ( '' === $status ) {
			$status = ( $settings['fee_course'] && ! Tutor::is_enrolled( $settings['fee_course'], $user_id ) ) ? Progress::STATUS_PENDING : Progress::STATUS_ACTIVE;
		}
		$now  = current_time( 'mysql', true );
		$data = array(
			'start_term_id'   => $start,
			'current_term_id' => $current,
			'status'          => $status,
			'source'          => isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'admin',
			'notes'           => isset( $args['notes'] ) ? sanitize_textarea_field( $args['notes'] ) : null,
			'updated_at'      => $now,
		);

		$existing = self::find( $user_id, $program_id );
		if ( $existing ) {
			$wpdb->update( Installer::table(), $data, array( 'id' => $existing['id'] ) ); // phpcs:ignore WordPress.DB
			$id = (int) $existing['id'];
		} else {
			$data['user_id']    = $user_id;
			$data['program_id'] = $program_id;
			$data['created_at'] = $now;
			$wpdb->insert( Installer::table(), $data ); // phpcs:ignore WordPress.DB
			$id = (int) $wpdb->insert_id;
		}
		$row = self::sync( self::get( $id ) );
		do_action( 'ra_inscription_saved', $row, $existing ? 'updated' : 'created' );
		return $row;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$allowed = array_intersect_key( $data, array_flip( array( 'start_term_id', 'current_term_id', 'status', 'notes' ) ) );
		if ( isset( $allowed['status'] ) && ! isset( self::statuses()[ $allowed['status'] ] ) ) {
			unset( $allowed['status'] );
		}
		if ( isset( $allowed['status'] ) && Progress::STATUS_GRADUATED !== $allowed['status'] ) {
			$allowed['completed_at'] = null;
		}
		$allowed['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( Installer::table(), $allowed, array( 'id' => absint( $id ) ) ); // phpcs:ignore WordPress.DB
		unset( self::$progress_cache[ $id ] );
		return self::sync( self::get( $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Installer::table(), array( 'id' => absint( $id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	public static function delete_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( Installer::table(), array( 'user_id' => absint( $user_id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	public static function on_delete_post( $post_id ) {
		global $wpdb;
		if ( get_post_type( $post_id ) === Curriculum::PROGRAM ) {
			$wpdb->delete( Installer::table(), array( 'program_id' => absint( $post_id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		}
	}

	/* ------------------------------------------------------------------ *
	 * Progress
	 * ------------------------------------------------------------------ */

	/**
	 * Computed progress of an inscription (see Progress::compute()).
	 */
	public static function progress( array $inscription ) {
		$id = (int) $inscription['id'];
		if ( isset( self::$progress_cache[ $id ] ) ) {
			return self::$progress_cache[ $id ];
		}
		$program_id = (int) $inscription['program_id'];
		$settings   = Curriculum::program_settings( $program_id );
		$progress   = Progress::compute(
			array(
				'terms'           => Curriculum::terms( $program_id ),
				'start_term_id'   => (int) $inscription['start_term_id'],
				'current_term_id' => (int) $inscription['current_term_id'],
				'status'          => $inscription['status'],
				'inscription_fee' => $settings['fee_course'],
				'mode'            => $settings['mode'],
				'courses'         => Tutor::course_states( Curriculum::all_course_ids( $program_id ), (int) $inscription['user_id'] ),
			)
		);
		self::$progress_cache[ $id ] = apply_filters( 'ra_progress', $progress, $inscription );
		return self::$progress_cache[ $id ];
	}

	/**
	 * Persist derived values (current term, status, progress, next course) and fire events.
	 *
	 * @return array Updated inscription.
	 */
	public static function sync( $inscription ) {
		global $wpdb;
		if ( ! $inscription ) {
			return $inscription;
		}
		unset( self::$progress_cache[ (int) $inscription['id'] ] );
		$progress = self::progress( $inscription );

		$changes = array();
		if ( (int) $progress['current_term_id'] !== (int) $inscription['current_term_id'] && $progress['current_term_id'] ) {
			$changes['current_term_id'] = $progress['current_term_id'];
		}
		if ( $progress['status'] !== $inscription['status'] ) {
			$changes['status'] = $progress['status'];
			if ( Progress::STATUS_GRADUATED === $progress['status'] ) {
				$changes['completed_at'] = current_time( 'mysql', true );
			}
		}
		if ( (int) $progress['percent'] !== (int) $inscription['progress'] ) {
			$changes['progress'] = $progress['percent'];
		}
		$next_course = $progress['next'] ? (int) $progress['next']['course_id'] : 0;
		if ( $next_course !== (int) $inscription['next_course_id'] ) {
			$changes['next_course_id'] = $next_course;
		}
		if ( ! $changes ) {
			return $inscription;
		}

		$changes['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( Installer::table(), $changes, array( 'id' => $inscription['id'] ) ); // phpcs:ignore WordPress.DB
		$updated = array_merge( $inscription, $changes );

		if ( isset( $changes['current_term_id'] ) ) {
			do_action( 'ra_term_changed', $updated, (int) $inscription['current_term_id'], (int) $changes['current_term_id'] );
		}
		if ( isset( $changes['status'] ) ) {
			if ( Progress::STATUS_ACTIVE === $changes['status'] && Progress::STATUS_PENDING === $inscription['status'] ) {
				do_action( 'ra_inscription_activated', $updated );
			}
			if ( Progress::STATUS_GRADUATED === $changes['status'] ) {
				do_action( 'ra_program_completed', $updated );
			}
		}
		return $updated;
	}

	public static function sync_user( $user_id ) {
		Curriculum::flush_cache();
		foreach ( self::for_user( $user_id ) as $inscription ) {
			self::sync( $inscription );
		}
	}

	public static function on_enrolled( $course_id, $user_id ) {
		if ( $user_id ) {
			Tutor::remember( $course_id, $user_id, 'enrolled' );
			self::sync_user( (int) $user_id );
		}
	}

	public static function on_course_completed( $course_id, $user_id ) {
		if ( $user_id ) {
			Tutor::remember( $course_id, $user_id, 'completed' );
			self::sync_user( (int) $user_id );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Self-service enrollment
	 * ------------------------------------------------------------------ */

	public static function enroll_url( $program_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=ra_enroll_program&program=' . absint( $program_id ) ), 'ra_enroll_' . absint( $program_id ) );
	}

	public static function my_program_url( $program_id = 0 ) {
		$url = Tutor::active() ? Tutor::dashboard_url( Frontend::DASHBOARD_SLUG ) : get_permalink( $program_id );
		return apply_filters( 'ra_my_program_url', $url, $program_id );
	}

	public static function handle_self_enroll() {
		$program_id = isset( $_GET['program'] ) ? absint( $_GET['program'] ) : 0;
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( get_permalink( $program_id ) ) );
			exit;
		}
		check_admin_referer( 'ra_enroll_' . $program_id );
		if ( get_post_type( $program_id ) !== Curriculum::PROGRAM || 'publish' !== get_post_status( $program_id ) ) {
			wp_die( esc_html__( 'The program does not exist.', 'reticula-academica' ), 404 );
		}
		$settings = Curriculum::program_settings( $program_id );
		$user_id  = get_current_user_id();
		$existing = self::find( $user_id, $program_id );

		if ( ! $existing || Progress::STATUS_DROPPED === $existing['status'] ) {
			if ( ! $settings['self_enroll'] ) {
				wp_die( esc_html__( 'Enrollment in this program is managed by the school. Please contact us.', 'reticula-academica' ), 403 );
			}
			$existing = self::create(
				$user_id,
				$program_id,
				array( 'source' => 'self' )
			);
			if ( is_wp_error( $existing ) ) {
				wp_die( esc_html( $existing->get_error_message() ) );
			}
		}

		$target = self::my_program_url( $program_id );
		if ( Progress::STATUS_PENDING === $existing['status'] && $settings['fee_course'] ) {
			$target = get_permalink( $settings['fee_course'] );
		}
		wp_safe_redirect( $target );
		exit;
	}
}
