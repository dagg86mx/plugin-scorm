<?php
/**
 * Course grades from Tutor quiz attempts (and optionally Tutor SCORM scores), weights screen.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Grades {

	const META     = '_ra_weights';
	const EXT_META = 'ra_ext_grades';
	const PAGE = 'ra-grading';

	private static $cache = array();

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_ra_save_weights', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ra_save_ext', array( __CLASS__, 'handle_save_ext' ) );
	}

	public static function options( $final = false ) {
		return array(
			'scale'    => (int) Settings::get( 'grade_scale' ),
			'passing'  => (float) Settings::get( 'grade_passing' ),
			'decimals' => (int) Settings::get( 'grade_decimals' ),
			'final'    => $final,
		);
	}

	/**
	 * Gradable items of a course: Tutor quizzes, plus SCORM lessons when enabled.
	 *
	 * @return array[] [ key, title, type, id ]
	 */
	public static function items( $course_id ) {
		$key = 'items_' . $course_id;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$items  = array();
		$topics = get_posts(
			array(
				'post_type'      => 'topics',
				'post_parent'    => $course_id,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
				'fields'         => 'ids',
			)
		);
		$quiz_type   = (string) Tutor::config( 'quiz_post_type', 'tutor_quiz' );
		$lesson_type = Tutor::lesson_post_type();
		$with_scorm  = Settings::get( 'grade_scorm' ) && self::scorm_available();
		foreach ( $topics as $topic_id ) {
			$children = get_posts(
				array(
					'post_type'      => array( $quiz_type, $lesson_type ),
					'post_parent'    => $topic_id,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
				)
			);
			foreach ( $children as $child ) {
				if ( $quiz_type === $child->post_type ) {
					$items[] = array(
						'key'   => 'quiz:' . $child->ID,
						'title' => get_the_title( $child ),
						'type'  => 'quiz',
						'id'    => $child->ID,
					);
				} elseif ( $with_scorm && get_post_meta( $child->ID, '_tutor_scorm_package_id', true ) ) {
					$items[] = array(
						'key'   => 'scorm:' . $child->ID,
						'title' => get_the_title( $child ),
						'type'  => 'scorm',
						'id'    => $child->ID,
						'pkg'   => (int) get_post_meta( $child->ID, '_tutor_scorm_package_id', true ),
					);
				}
			}
		}
		self::$cache[ $key ] = $items;
		return $items;
	}

	public static function weights( $course_id ) {
		$weights = get_post_meta( $course_id, self::META, true );
		return is_array( $weights ) ? array_map( 'floatval', $weights ) : array();
	}

	private static function scorm_available() {
		global $wpdb;
		if ( ! isset( self::$cache['scorm_table'] ) ) {
			$table                       = $wpdb->prefix . 'tutor_scorm_tracking';
			self::$cache['scorm_table'] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		}
		return self::$cache['scorm_table'];
	}

	/**
	 * Score (0-100) and state of an item for a learner.
	 */
	private static function item_score( array $item, $user_id ) {
		global $wpdb;
		if ( 'quiz' === $item['type'] ) {
			$table = $wpdb->prefix . 'tutor_quiz_attempts';
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT total_marks, earned_marks, attempt_status, attempt_ended_at FROM {$table} WHERE quiz_id = %d AND user_id = %d ORDER BY attempt_ended_at ASC, attempt_id ASC", $item['id'], $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$scores  = array();
			$pending = false;
			foreach ( (array) $rows as $row ) {
				if ( in_array( $row['attempt_status'], array( 'attempt_ended', 'attempt_timeout' ), true ) && (float) $row['total_marks'] > 0 ) {
					$scores[] = max( 0, min( 100, (float) $row['earned_marks'] / (float) $row['total_marks'] * 100 ) );
				} elseif ( 'review_required' === $row['attempt_status'] ) {
					$pending = true;
				}
			}
			if ( $scores ) {
				return array( 'last' === Settings::get( 'grade_attempt' ) ? end( $scores ) : max( $scores ), 'graded' );
			}
			return array( null, $pending ? 'pending_review' : 'not_taken' );
		}
		if ( 'scorm' === $item['type'] && self::scorm_available() ) {
			$table = $wpdb->prefix . 'tutor_scorm_tracking';
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT score_raw, score_max, score_scaled FROM {$table} WHERE package_id = %d AND user_id = %d", $item['pkg'], $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$scores = array();
			foreach ( (array) $rows as $row ) {
				if ( null !== $row['score_scaled'] && '' !== $row['score_scaled'] ) {
					$scores[] = max( 0, min( 100, (float) $row['score_scaled'] * 100 ) );
				} elseif ( null !== $row['score_raw'] && '' !== $row['score_raw'] ) {
					$max      = (float) $row['score_max'] > 0 ? (float) $row['score_max'] : 100;
					$scores[] = max( 0, min( 100, (float) $row['score_raw'] / $max * 100 ) );
				}
			}
			return $scores ? array( array_sum( $scores ) / count( $scores ), 'graded' ) : array( null, 'not_taken' );
		}
		return array( null, 'not_taken' );
	}

	/**
	 * Grade of a learner in a course.
	 */
	public static function course( $course_id, $user_id ) {
		$key = 'grade_' . $course_id . '_' . $user_id;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$items = array();
		foreach ( self::items( $course_id ) as $item ) {
			list( $score, $state ) = self::item_score( $item, $user_id );
			$items[]               = array(
				'key'   => $item['key'],
				'title' => $item['title'],
				'type'  => $item['type'],
				'score' => $score,
				'state' => $state,
			);
		}
		$result = Grade_Calculator::course( $items, self::weights( $course_id ), self::options( Tutor::is_completed( $course_id, $user_id ) ) );
		$result = Grade_Calculator::with_final( $result, self::ext( $course_id, $user_id ), (float) Settings::get( 'grade_passing' ) );
		self::$cache[ $key ] = apply_filters( 'ra_course_grade', $result, $course_id, $user_id );
		return self::$cache[ $key ];
	}

	/**
	 * General average of a student in a program (courses with a final grade, credited courses excluded).
	 */
	public static function program_average( array $inscription ) {
		$grades = array();
		foreach ( Inscriptions::progress( $inscription )['terms'] as $term ) {
			foreach ( $term['courses'] as $course ) {
				if ( 'exempt' !== $course['status'] ) {
					$grades[] = self::course( $course['id'], (int) $inscription['user_id'] );
				}
			}
		}
		return Grade_Calculator::average( $grades, (int) Settings::get( 'grade_decimals' ) );
	}

	/**
	 * Extraordinary exam grade (EXT) captured by staff, or null.
	 */
	public static function ext( $course_id, $user_id ) {
		$all = get_user_meta( $user_id, self::EXT_META, true );
		return ( is_array( $all ) && isset( $all[ $course_id ] ) && '' !== $all[ $course_id ] ) ? (float) $all[ $course_id ] : null;
	}

	/**
	 * Staff form: extraordinary grades of a student in a program.
	 */
	public static function ext_form( array $inscription ) {
		$user_id = (int) $inscription['user_id'];
		$scale   = (int) Settings::get( 'grade_scale' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ra_save_ext_' . $user_id ); ?>
			<input type="hidden" name="action" value="ra_save_ext">
			<input type="hidden" name="user" value="<?php echo esc_attr( $user_id ); ?>">
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Code', 'reticula-academica' ); ?></th><th><?php esc_html_e( 'Course', 'reticula-academica' ); ?></th><th>F</th><th>EXT</th><th>CF</th></tr></thead>
				<tbody>
					<?php foreach ( Inscriptions::progress( $inscription )['terms'] as $term ) : ?>
						<?php
						if ( 'exempt' === $term['status'] ) {
							continue;
						}
						?>
						<tr><th colspan="5"><?php echo esc_html( $term['title'] ); ?></th></tr>
						<?php foreach ( $term['courses'] as $course ) : ?>
							<?php $grade = self::course( $course['id'], $user_id ); ?>
							<tr>
								<td><code><?php echo esc_html( Curriculum::course_code( $course['id'] ) ); ?></code></td>
								<td><?php echo esc_html( get_the_title( $course['id'] ) ); ?></td>
								<td><?php echo esc_html( self::format( $grade['grade'] ) ); ?></td>
								<td><input type="number" step="0.01" min="0" max="<?php echo esc_attr( $scale ); ?>" name="ext[<?php echo esc_attr( $course['id'] ); ?>]" value="<?php echo esc_attr( null === $grade['ext'] ? '' : $grade['ext'] ); ?>" class="small-text"></td>
								<td><strong><?php echo esc_html( null === $grade['cf'] ? '—' : (string) $grade['cf'] ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'F: ordinary grade (weighted average of quizzes). EXT: extraordinary exam, replaces F when filled. CF: official final grade (integer).', 'reticula-academica' ); ?></p>
			<?php submit_button( __( 'Save extraordinary grades', 'reticula-academica' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public static function handle_save_ext() {
		$user_id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		check_admin_referer( 'ra_save_ext_' . $user_id );
		if ( ! current_user_can( Post_Types::capability() ) ) {
			wp_die( '', 403 );
		}
		$scale = (float) Settings::get( 'grade_scale' );
		$ext   = array();
		foreach ( (array) ( isset( $_POST['ext'] ) ? wp_unslash( $_POST['ext'] ) : array() ) as $course_id => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$value = trim( (string) $value );
			if ( '' !== $value && is_numeric( $value ) ) {
				$ext[ absint( $course_id ) ] = max( 0, min( $scale, (float) $value ) );
			}
		}
		update_user_meta( $user_id, self::EXT_META, $ext );
		do_action( 'ra_ext_grades_saved', $user_id, $ext );
		set_transient( 'ra_notice_' . get_current_user_id(), array( 'success', esc_html__( 'Extraordinary grades saved.', 'reticula-academica' ) ), 120 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	public static function status_label( $status ) {
		$labels = array(
			Grade_Calculator::STATUS_NONE    => __( 'No grade yet', 'reticula-academica' ),
			Grade_Calculator::STATUS_PARTIAL => __( 'In progress', 'reticula-academica' ),
			Grade_Calculator::STATUS_PASSED  => __( 'Passed', 'reticula-academica' ),
			Grade_Calculator::STATUS_FAILED  => __( 'Failed', 'reticula-academica' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	public static function format( $value ) {
		return Grade_Calculator::format( $value, (int) Settings::get( 'grade_decimals' ) );
	}

	/**
	 * Breakdown table (HTML) of a course grade, used in "My program", course pages and documents.
	 */
	public static function breakdown_html( array $grade ) {
		if ( ! $grade['items'] ) {
			return '<p class="ra-muted">' . esc_html__( 'This course has no graded evaluations.', 'reticula-academica' ) . '</p>';
		}
		$states = array(
			'pending_review' => __( 'Under review', 'reticula-academica' ),
			'not_taken'      => __( 'Not taken', 'reticula-academica' ),
		);
		$html = '<table class="ra-grade-table"><thead><tr><th>' . esc_html__( 'Evaluation', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Weight', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Grade', 'reticula-academica' ) . '</th></tr></thead><tbody>';
		foreach ( $grade['items'] as $item ) {
			$score = null === $item['score'] ? ( isset( $states[ $item['state'] ] ) ? $states[ $item['state'] ] : '—' ) : self::format( $item['score'] );
			$html .= '<tr><td>' . esc_html( $item['title'] ) . '</td><td>' . esc_html( rtrim( rtrim( number_format( $item['weight'], 2, '.', '' ), '0' ), '.' ) . '%' ) . '</td><td>' . esc_html( $score ) . '</td></tr>';
		}
		$html .= '</tbody><tfoot><tr><th colspan="2">' . esc_html__( 'Course grade', 'reticula-academica' ) . '</th><th>' . esc_html( self::format( $grade['grade'] ) ) . '</th></tr>';
		if ( isset( $grade['ext'] ) && null !== $grade['ext'] ) {
			$html .= '<tr><th colspan="2">' . esc_html__( 'Extraordinary exam', 'reticula-academica' ) . '</th><th>' . esc_html( self::format( $grade['ext'] ) ) . '</th></tr>';
		}
		$html .= '</tfoot></table>';
		return $html;
	}

	/* ------------------------------------------------------------------ *
	 * Weights screen
	 * ------------------------------------------------------------------ */

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . Curriculum::PROGRAM, __( 'Grading', 'reticula-academica' ), __( 'Grading', 'reticula-academica' ), Post_Types::capability(), self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	public static function render_page() {
		if ( ! current_user_can( Post_Types::capability() ) ) {
			return;
		}
		$course_id = isset( $_GET['course'] ) ? absint( $_GET['course'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$courses   = array();
		foreach ( Curriculum::programs( true ) as $program ) {
			foreach ( Curriculum::terms( $program->ID, true ) as $term ) {
				foreach ( $term['courses'] as $id ) {
					$courses[ $id ] = get_the_title( $program ) . ' › ' . $term['title'] . ' › ' . get_the_title( $id );
				}
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Grading', 'reticula-academica' ); ?></h1>
			<p><?php esc_html_e( 'Choose how much each evaluation of a course is worth. The course grade is the weighted average of the student’s quiz results. Without a configuration, every quiz weighs the same.', 'reticula-academica' ); ?></p>
			<form method="get">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( Curriculum::PROGRAM ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<select name="course" onchange="this.form.submit()">
					<option value="0"><?php esc_html_e( '— Choose a course —', 'reticula-academica' ); ?></option>
					<?php foreach ( $courses as $id => $label ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $course_id, $id ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><?php submit_button( __( 'Open', 'reticula-academica' ), 'secondary', '', false ); ?></noscript>
			</form>
			<?php
			if ( ! $course_id ) {
				echo '</div>';
				return;
			}
			$items   = self::items( $course_id );
			$weights = self::weights( $course_id );
			if ( ! $items ) {
				echo '<p>' . esc_html__( 'This course has no quizzes yet. Add quizzes in the Tutor course builder.', 'reticula-academica' ) . '</p></div>';
				return;
			}
			$quizzes = count( wp_list_pluck( array_filter( $items, static fn( $i ) => 'quiz' === $i['type'] ), 'key' ) );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ra-weights-form">
				<?php wp_nonce_field( 'ra_save_weights_' . $course_id ); ?>
				<input type="hidden" name="action" value="ra_save_weights">
				<input type="hidden" name="course" value="<?php echo esc_attr( $course_id ); ?>">
				<table class="widefat striped" style="max-width:720px">
					<thead><tr><th><?php esc_html_e( 'Evaluation', 'reticula-academica' ); ?></th><th><?php esc_html_e( 'Type', 'reticula-academica' ); ?></th><th style="width:140px"><?php esc_html_e( 'Weight (%)', 'reticula-academica' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $items as $item ) : ?>
							<?php
							if ( $weights ) {
								$value = isset( $weights[ $item['key'] ] ) ? $weights[ $item['key'] ] : 0;
							} else {
								$value = 'quiz' === $item['type'] && $quizzes ? round( 100 / $quizzes, 2 ) : 0;
							}
							?>
							<tr>
								<td><?php echo esc_html( $item['title'] ); ?></td>
								<td><?php echo 'quiz' === $item['type'] ? esc_html__( 'Quiz', 'reticula-academica' ) : 'SCORM'; ?></td>
								<td><input type="number" min="0" max="100" step="0.01" name="weights[<?php echo esc_attr( $item['key'] ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="small-text ra-weight"> %</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot><tr><th colspan="2"><?php esc_html_e( 'Total', 'reticula-academica' ); ?></th><th><span class="ra-weight-total"></span> %</th></tr></tfoot>
				</table>
				<p class="description"><?php esc_html_e( 'Weights are normalized if they do not add up to 100%. Use 0 to leave an evaluation out.', 'reticula-academica' ); ?></p>
				<p>
					<?php submit_button( __( 'Save weights', 'reticula-academica' ), 'primary', 'submit', false ); ?>
					<button type="submit" name="reset" value="1" class="button"><?php esc_html_e( 'Reset (all quizzes weigh the same)', 'reticula-academica' ); ?></button>
				</p>
			</form>
			<script>
			( function () {
				var inputs = document.querySelectorAll( '.ra-weight' ), out = document.querySelector( '.ra-weight-total' );
				function sum() { var t = 0; inputs.forEach( function ( i ) { t += parseFloat( i.value ) || 0; } ); out.textContent = Math.round( t * 100 ) / 100; out.style.color = Math.abs( t - 100 ) < 0.01 ? '' : '#b32d2e'; }
				inputs.forEach( function ( i ) { i.addEventListener( 'input', sum ); } ); sum();
			} )();
			</script>
		</div>
		<?php
	}

	public static function handle_save() {
		$course_id = isset( $_POST['course'] ) ? absint( $_POST['course'] ) : 0;
		check_admin_referer( 'ra_save_weights_' . $course_id );
		if ( ! current_user_can( Post_Types::capability() ) ) {
			wp_die( '', 403 );
		}
		if ( ! empty( $_POST['reset'] ) ) {
			delete_post_meta( $course_id, self::META );
		} else {
			$valid   = wp_list_pluck( self::items( $course_id ), 'key' );
			$weights = array();
			foreach ( (array) ( isset( $_POST['weights'] ) ? wp_unslash( $_POST['weights'] ) : array() ) as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( in_array( $key, $valid, true ) ) {
					$weights[ $key ] = max( 0, min( 100, (float) $value ) );
				}
			}
			update_post_meta( $course_id, self::META, $weights );
		}
		set_transient( 'ra_notice_' . get_current_user_id(), array( 'success', esc_html__( 'Weights saved.', 'reticula-academica' ) ), 120 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Curriculum::PROGRAM . '&page=' . self::PAGE . '&course=' . $course_id ) );
		exit;
	}
}
