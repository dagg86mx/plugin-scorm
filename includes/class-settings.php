<?php
/**
 * Plugin settings.
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'tutor_scorm_settings';

	const RULE_COMPLETED = 'completed';
	const RULE_PASSED    = 'passed';
	const RULE_ANY       = 'any';

	public static function defaults() {
		return array(
			'completion_rule'      => self::RULE_COMPLETED,
			'player_mode'          => 'inline',
			'player_height'        => 640,
			'hide_complete_button' => 1,
			'autosave_interval'    => 60,
			'delete_on_uninstall'  => 0,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Capability required to upload and manage SCORM packages.
	 *
	 * SCORM packages contain arbitrary HTML and JavaScript served from the site origin,
	 * so by default only administrators may upload them.
	 */
	public static function capability() {
		return apply_filters( 'tutor_scorm_manage_capability', 'manage_options' );
	}

	public static function register() {
		register_setting(
			'tutor_scorm',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$rule     = isset( $input['completion_rule'] ) ? sanitize_key( $input['completion_rule'] ) : $defaults['completion_rule'];
		$mode     = isset( $input['player_mode'] ) ? sanitize_key( $input['player_mode'] ) : $defaults['player_mode'];

		return array(
			'completion_rule'      => in_array( $rule, array( self::RULE_COMPLETED, self::RULE_PASSED, self::RULE_ANY ), true ) ? $rule : $defaults['completion_rule'],
			'player_mode'          => in_array( $mode, array( 'inline', 'popup' ), true ) ? $mode : $defaults['player_mode'],
			'player_height'        => max( 200, min( 3000, absint( isset( $input['player_height'] ) ? $input['player_height'] : $defaults['player_height'] ) ) ),
			'hide_complete_button' => empty( $input['hide_complete_button'] ) ? 0 : 1,
			'autosave_interval'    => min( 600, absint( isset( $input['autosave_interval'] ) ? $input['autosave_interval'] : $defaults['autosave_interval'] ) ),
			'delete_on_uninstall'  => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::all();
		$n = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SCORM Settings', 'tutor-scorm' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'tutor_scorm' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tutor-scorm-rule"><?php esc_html_e( 'Lesson completion rule', 'tutor-scorm' ); ?></label></th>
						<td>
							<select id="tutor-scorm-rule" name="<?php echo esc_attr( $n ); ?>[completion_rule]">
								<option value="completed" <?php selected( $s['completion_rule'], 'completed' ); ?>><?php esc_html_e( 'Completed or passed (recommended)', 'tutor-scorm' ); ?></option>
								<option value="passed" <?php selected( $s['completion_rule'], 'passed' ); ?>><?php esc_html_e( 'Passed only', 'tutor-scorm' ); ?></option>
								<option value="any" <?php selected( $s['completion_rule'], 'any' ); ?>><?php esc_html_e( 'Completed, passed or failed', 'tutor-scorm' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'When every SCO of the package meets this rule, the Tutor LMS lesson is marked as complete for the student.', 'tutor-scorm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tutor-scorm-mode"><?php esc_html_e( 'Default player mode', 'tutor-scorm' ); ?></label></th>
						<td>
							<select id="tutor-scorm-mode" name="<?php echo esc_attr( $n ); ?>[player_mode]">
								<option value="inline" <?php selected( $s['player_mode'], 'inline' ); ?>><?php esc_html_e( 'Embedded in the lesson', 'tutor-scorm' ); ?></option>
								<option value="popup" <?php selected( $s['player_mode'], 'popup' ); ?>><?php esc_html_e( 'New window', 'tutor-scorm' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tutor-scorm-height"><?php esc_html_e( 'Player height (px)', 'tutor-scorm' ); ?></label></th>
						<td><input id="tutor-scorm-height" type="number" min="200" max="3000" name="<?php echo esc_attr( $n ); ?>[player_height]" value="<?php echo esc_attr( $s['player_height'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="tutor-scorm-autosave"><?php esc_html_e( 'Autosave interval (seconds)', 'tutor-scorm' ); ?></label></th>
						<td>
							<input id="tutor-scorm-autosave" type="number" min="0" max="600" name="<?php echo esc_attr( $n ); ?>[autosave_interval]" value="<?php echo esc_attr( $s['autosave_interval'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Saves progress periodically even if the content never calls Commit. 0 disables it.', 'tutor-scorm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tutor "Mark as complete" button', 'tutor-scorm' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[hide_complete_button]" value="1" <?php checked( $s['hide_complete_button'], 1 ); ?>> <?php esc_html_e( 'Hide it on lessons that contain a SCORM package (completion is reported by the package).', 'tutor-scorm' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'tutor-scorm' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'], 1 ); ?>> <?php esc_html_e( 'Delete packages, files and tracking data when the plugin is uninstalled.', 'tutor-scorm' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php self::render_xapi_migration(); ?>
		</div>
		<?php
	}

	/**
	 * Patterns used by common xAPI / Tin Can plugins inside lesson content.
	 */
	public static function xapi_patterns() {
		return apply_filters(
			'tutor_scorm_xapi_patterns',
			array(
				'[grassblade'      => 'GrassBlade xAPI Companion',
				'wp:grassblade'    => 'GrassBlade xAPI Companion',
				'[tincanny'        => 'Tin Canny Reporting',
				'[vc_snc'          => 'Tin Canny Reporting',
				'wp:tincanny'      => 'Tin Canny Reporting',
				'[h5p '            => 'H5P (xAPI)',
				'wp:h5p'           => 'H5P (xAPI)',
				'[xapi'            => 'xAPI',
				'[tin_can'         => 'xAPI / Tin Can',
				'tincan.xml'       => 'xAPI / Tin Can',
				'cmi5.xml'         => 'cmi5',
			)
		);
	}

	/**
	 * Lessons whose content still embeds xAPI content.
	 *
	 * @return array[] { post: WP_Post, sources: string[] }
	 */
	public static function find_xapi_lessons() {
		global $wpdb;
		$patterns = self::xapi_patterns();
		$where    = array();
		$args     = array( Tutor_Integration::lesson_post_type() );
		foreach ( array_keys( $patterns ) as $needle ) {
			$where[] = 'post_content LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $needle ) . '%';
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') AND (" . implode( ' OR ', $where ) . ') ORDER BY post_title LIMIT 500', $args ) ); // phpcs:ignore WordPress.DB

		$found = array();
		foreach ( $ids as $id ) {
			$post    = get_post( $id );
			$sources = array();
			foreach ( $patterns as $needle => $label ) {
				if ( false !== stripos( $post->post_content, $needle ) ) {
					$sources[ $label ] = $label;
				}
			}
			$found[] = array(
				'post'    => $post,
				'sources' => array_values( $sources ),
			);
		}
		return $found;
	}

	private static function render_xapi_migration() {
		if ( ! Plugin::tutor_active() ) {
			return;
		}
		$lessons = self::find_xapi_lessons();
		?>
		<hr>
		<h2><?php esc_html_e( 'Migration from xAPI', 'tutor-scorm' ); ?></h2>
		<p><?php esc_html_e( 'Export each course from your authoring tool as SCORM 1.2 or SCORM 2004, upload it as a SCORM package and attach it to the lesson. Then remove the xAPI shortcode or block from the lesson. No LRS is needed: progress is stored in WordPress.', 'tutor-scorm' ); ?></p>
		<?php if ( ! $lessons ) : ?>
			<p><strong><?php esc_html_e( 'No lessons with xAPI content were found.', 'tutor-scorm' ); ?></strong></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Lesson', 'tutor-scorm' ); ?></th>
						<th><?php esc_html_e( 'Course', 'tutor-scorm' ); ?></th>
						<th><?php esc_html_e( 'xAPI source', 'tutor-scorm' ); ?></th>
						<th><?php esc_html_e( 'SCORM package', 'tutor-scorm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $lessons as $item ) :
						$lesson     = $item['post'];
						$course_id  = Tutor_Integration::course_id_for_lesson( $lesson->ID );
						$package_id = Tutor_Integration::lesson_package_id( $lesson->ID );
						?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $lesson->ID ) ); ?>"><?php echo esc_html( get_the_title( $lesson ) ); ?></a></td>
							<td><?php echo $course_id ? esc_html( get_the_title( $course_id ) ) : '&mdash;'; ?></td>
							<td><?php echo esc_html( implode( ', ', $item['sources'] ) ); ?></td>
							<td><?php echo $package_id ? '<a href="' . esc_url( get_edit_post_link( $package_id ) ) . '">' . esc_html( get_the_title( $package_id ) ) . '</a>' : '<em>' . esc_html__( 'Not attached yet', 'tutor-scorm' ) . '</em>'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
