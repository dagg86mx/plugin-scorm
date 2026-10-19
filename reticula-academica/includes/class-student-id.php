<?php
/**
 * Student ID (matrícula): consecutive, unique, stored in user meta and shown in the profile.
 *
 * Numbers are reserved in a table with a UNIQUE index, so two simultaneous
 * registrations can never receive the same ID.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Student_Id {

	const META = 'ra_matricula';

	public static function init() {
		add_action( 'ra_inscription_saved', array( __CLASS__, 'on_inscription' ), 5 );
		add_action( 'user_register', array( __CLASS__, 'on_register' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );
		add_action( 'pre_user_query', array( __CLASS__, 'users_search' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ra_student_ids';
	}

	public static function format( $number ) {
		return Settings::get( 'id_prefix' ) . $number;
	}

	public static function get( $user_id ) {
		return (string) get_user_meta( $user_id, self::META, true );
	}

	/**
	 * Next number that would be assigned.
	 */
	public static function next_number() {
		global $wpdb;
		$table = self::table();
		$max   = (int) $wpdb->get_var( "SELECT MAX(number) FROM {$table}" ); // phpcs:ignore WordPress.DB
		return max( (int) Settings::get( 'id_start' ), $max + 1 );
	}

	/**
	 * Assign an ID to a user if they do not have one yet.
	 *
	 * @return string The user's ID.
	 */
	public static function ensure( $user_id ) {
		global $wpdb;
		$user_id  = absint( $user_id );
		$existing = self::get( $user_id );
		if ( ! $user_id || '' !== $existing ) {
			return $existing;
		}
		$table = self::table();
		$row   = $wpdb->get_var( $wpdb->prepare( "SELECT number FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB
		if ( $row ) {
			$id = self::format( $row );
			update_user_meta( $user_id, self::META, $id );
			return $id;
		}
		// The UNIQUE index on "number" makes concurrent requests fail instead of duplicating: retry with the next one.
		for ( $try = 0; $try < 10; $try++ ) {
			$number = self::next_number();
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert(
				$table,
				array(
					'user_id'    => $user_id,
					'number'     => $number,
					'created_at' => current_time( 'mysql', true ),
				)
			); // phpcs:ignore WordPress.DB
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				$id = self::format( $number );
				update_user_meta( $user_id, self::META, $id );
				do_action( 'ra_student_id_assigned', $user_id, $id );
				return $id;
			}
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT number FROM {$table} WHERE user_id = %d", $user_id ) ) ) { // phpcs:ignore WordPress.DB
				return self::ensure( $user_id );
			}
		}
		return '';
	}

	public static function on_inscription( $inscription ) {
		if ( is_array( $inscription ) && ! empty( $inscription['user_id'] ) ) {
			self::ensure( (int) $inscription['user_id'] );
		}
	}

	public static function on_register( $user_id ) {
		if ( 'registration' === Settings::get( 'id_assign' ) ) {
			self::ensure( $user_id );
		}
	}

	/**
	 * Give an ID to every enrolled student without one, oldest inscription first.
	 */
	public static function assign_missing() {
		global $wpdb;
		$table = Installer::table();
		$users = $wpdb->get_col( "SELECT user_id FROM {$table} GROUP BY user_id ORDER BY MIN(created_at) ASC, MIN(id) ASC" ); // phpcs:ignore WordPress.DB
		$count = 0;
		foreach ( $users as $user_id ) {
			if ( '' === self::get( $user_id ) && '' !== self::ensure( $user_id ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Find the user that owns an ID.
	 */
	public static function find_user( $id ) {
		$users = get_users(
			array(
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return $users ? (int) $users[0] : 0;
	}

	public static function profile_field( $user ) {
		$id       = self::get( $user->ID );
		$can_edit = current_user_can( Post_Types::capability() );
		?>
		<h2><?php esc_html_e( 'Academic record', 'reticula-academica' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="ra_matricula"><?php esc_html_e( 'Student ID (matrícula)', 'reticula-academica' ); ?></label></th>
				<td>
					<?php if ( $can_edit ) : ?>
						<?php wp_nonce_field( 'ra_matricula_' . $user->ID, 'ra_matricula_nonce' ); ?>
						<input type="text" name="ra_matricula" id="ra_matricula" value="<?php echo esc_attr( $id ); ?>" class="regular-text" placeholder="<?php echo esc_attr( self::format( self::next_number() ) ); ?>">
						<p class="description"><?php esc_html_e( 'Leave empty and check the box to assign the next consecutive ID.', 'reticula-academica' ); ?></p>
						<?php if ( '' === $id ) : ?>
							<label><input type="checkbox" name="ra_matricula_auto" value="1"> <?php esc_html_e( 'Assign the next consecutive ID', 'reticula-academica' ); ?></label>
						<?php endif; ?>
					<?php else : ?>
						<strong><?php echo $id ? esc_html( $id ) : '&mdash;'; ?></strong>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_profile( $user_id ) {
		if ( ! current_user_can( Post_Types::capability() ) || ! isset( $_POST['ra_matricula_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ra_matricula_nonce'] ) ), 'ra_matricula_' . $user_id ) ) {
			return;
		}
		$new = isset( $_POST['ra_matricula'] ) ? sanitize_text_field( wp_unslash( $_POST['ra_matricula'] ) ) : '';
		if ( '' === $new ) {
			if ( ! empty( $_POST['ra_matricula_auto'] ) ) {
				self::ensure( $user_id );
			}
			return;
		}
		if ( $new === self::get( $user_id ) ) {
			return;
		}
		$owner = self::find_user( $new );
		if ( $owner && $owner !== (int) $user_id ) {
			add_action(
				'user_profile_update_errors',
				static function ( $errors ) {
					$errors->add( 'ra_matricula', __( 'That student ID already belongs to another user.', 'reticula-academica' ) );
				}
			);
			return;
		}
		update_user_meta( $user_id, self::META, $new );
	}

	public static function users_column( $columns ) {
		$columns['ra_matricula'] = __( 'Student ID', 'reticula-academica' );
		return $columns;
	}

	public static function users_column_value( $value, $column, $user_id ) {
		return 'ra_matricula' === $column ? esc_html( self::get( $user_id ) ) : $value;
	}

	/**
	 * Let the Users screen search by student ID.
	 */
	public static function users_search( $query ) {
		global $wpdb;
		if ( ! is_admin() || empty( $query->query_vars['search'] ) ) {
			return;
		}
		$term = trim( $query->query_vars['search'], '*' );
		if ( '' === $term || false === stripos( $term, (string) Settings::get( 'id_prefix' ) ) ) {
			return;
		}
		$query->query_where .= $wpdb->prepare( " OR {$wpdb->users}.ID IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s)", self::META, $term );
	}
}
