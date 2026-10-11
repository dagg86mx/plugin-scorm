<?php
/**
 * Tutor LMS integration: lesson attachment, enrollment checks and lesson completion.
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || exit;

class Tutor_Integration {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_lesson_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_lesson_meta_box' ), 10, 2 );
		// Tutor hides the lesson "Overview" tab (where the player lives) when the lesson has no text content.
		add_filter( 'tutor_has_lesson_content', array( __CLASS__, 'has_lesson_content' ), 10, 2 );
	}

	public static function has_lesson_content( $has_content, $lesson_id = 0 ) {
		return $has_content || ( $lesson_id && self::lesson_package_id( $lesson_id ) );
	}

	public static function lesson_post_type() {
		if ( function_exists( 'tutor' ) && isset( tutor()->lesson_post_type ) ) {
			return tutor()->lesson_post_type;
		}
		return 'lesson';
	}

	public static function course_post_type() {
		if ( function_exists( 'tutor' ) && isset( tutor()->course_post_type ) ) {
			return tutor()->course_post_type;
		}
		return 'courses';
	}

	public static function is_lesson( $post_id ) {
		return $post_id && get_post_type( $post_id ) === self::lesson_post_type();
	}

	public static function course_id_for_lesson( $lesson_id ) {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id ) {
			return 0;
		}
		if ( function_exists( 'tutor_utils' ) ) {
			$utils = tutor_utils();
			if ( method_exists( $utils, 'get_course_id_by' ) ) {
				return absint( $utils->get_course_id_by( 'lesson', $lesson_id ) );
			}
			if ( method_exists( $utils, 'get_course_id_by_lesson' ) ) {
				return absint( $utils->get_course_id_by_lesson( $lesson_id ) );
			}
		}
		// Fallback: lesson -> topic -> course.
		$topic_id = (int) wp_get_post_parent_id( $lesson_id );
		return $topic_id ? (int) wp_get_post_parent_id( $topic_id ) : 0;
	}

	/**
	 * Package attached to a lesson through the meta box / package screen.
	 */
	public static function lesson_package_id( $lesson_id ) {
		return absint( get_post_meta( $lesson_id, Package_Manager::LESSON_META, true ) );
	}

	/**
	 * Whether the lesson shows a SCORM package (meta or shortcode).
	 */
	public static function lesson_has_scorm( $lesson_id ) {
		if ( self::lesson_package_id( $lesson_id ) ) {
			return true;
		}
		$post = get_post( $lesson_id );
		return $post && has_shortcode( $post->post_content, Player::SHORTCODE );
	}

	public static function user_can_track_lesson( $user_id, $lesson_id ) {
		if ( ! self::is_lesson( $lesson_id ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$course_id = self::course_id_for_lesson( $lesson_id );
		if ( ! $course_id || ! function_exists( 'tutor_utils' ) ) {
			return false;
		}
		return (bool) tutor_utils()->is_enrolled( $course_id, $user_id );
	}

	public static function is_lesson_completed( $lesson_id, $user_id ) {
		if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'is_completed_lesson' ) ) {
			return (bool) tutor_utils()->is_completed_lesson( $lesson_id, $user_id );
		}
		return (bool) get_user_meta( $user_id, '_tutor_completed_lesson_id_' . $lesson_id, true );
	}

	/**
	 * Mark a Tutor LMS lesson as complete, replicating Tutor's own "Mark as complete" flow.
	 *
	 * @return bool True when the lesson was newly completed.
	 */
	public static function complete_lesson( $lesson_id, $user_id ) {
		if ( ! function_exists( 'tutor_utils' ) || ! self::is_lesson( $lesson_id ) ) {
			return false;
		}
		if ( self::is_lesson_completed( $lesson_id, $user_id ) ) {
			return false;
		}
		if ( ! apply_filters( 'tutor_validate_lesson_complete', true, $user_id, $lesson_id ) || ! apply_filters( 'tutor_scorm_should_complete_lesson', true, $lesson_id, $user_id ) ) {
			return false;
		}

		// Tutor redirects (and dies) from its own tutor_lesson_completed_after listener, which would break our JSON response.
		$detached = self::detach_redirecting_listeners( 'tutor_lesson_completed_after' );

		do_action( 'tutor_lesson_completed_before', $lesson_id );
		if ( class_exists( '\Tutor\Models\LessonModel' ) && method_exists( '\Tutor\Models\LessonModel', 'mark_lesson_complete' ) ) {
			\Tutor\Models\LessonModel::mark_lesson_complete( $lesson_id, $user_id );
		} elseif ( method_exists( tutor_utils(), 'mark_lesson_complete' ) ) {
			tutor_utils()->mark_lesson_complete( $lesson_id, $user_id );
		} else {
			update_user_meta( $user_id, '_tutor_completed_lesson_id_' . $lesson_id, function_exists( 'tutor_time' ) ? tutor_time() : time() );
		}
		do_action( 'tutor_lesson_completed_email_after', $lesson_id, $user_id );
		do_action( 'tutor_lesson_completed_after', $lesson_id, $user_id );

		foreach ( $detached as $listener ) {
			add_action( 'tutor_lesson_completed_after', $listener['callback'], $listener['priority'], $listener['args'] );
		}

		self::maybe_complete_course( self::course_id_for_lesson( $lesson_id ), $user_id );

		do_action( 'tutor_scorm_lesson_completed', $lesson_id, $user_id );

		return true;
	}

	/**
	 * Temporarily remove Tutor's own listeners that redirect the browser.
	 *
	 * @return array Removed listeners, to be restored afterwards.
	 */
	private static function detach_redirecting_listeners( $hook ) {
		global $wp_filter;
		$removed = array();
		if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof \WP_Hook ) ) {
			return $removed;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && is_object( $function[0] ) && 'TUTOR\\Lesson' === get_class( $function[0] ) && 'tutor_lesson_completed_after' === $function[1] ) {
					remove_action( $hook, $function, $priority );
					$removed[] = array(
						'callback' => $function,
						'priority' => $priority,
						'args'     => $callback['accepted_args'],
					);
				}
			}
		}
		return $removed;
	}

	/**
	 * Honour Tutor's "auto complete course when all lessons are completed" option right away.
	 */
	public static function maybe_complete_course( $course_id, $user_id ) {
		if ( ! $course_id || ! class_exists( '\Tutor\Models\CourseModel' ) ) {
			return;
		}
		if ( method_exists( '\Tutor\Models\CourseModel', 'can_autocomplete_course' ) && \Tutor\Models\CourseModel::can_autocomplete_course( $course_id, $user_id ) ) {
			\Tutor\Models\CourseModel::mark_course_as_completed( $course_id, $user_id );
		}
	}

	public static function add_lesson_meta_box() {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}
		add_meta_box( 'tutor-scorm-lesson', __( 'SCORM package', 'tutor-scorm' ), array( __CLASS__, 'render_lesson_meta_box' ), self::lesson_post_type(), 'side' );
	}

	public static function render_lesson_meta_box( $post ) {
		wp_nonce_field( 'tutor_scorm_lesson', 'tutor_scorm_lesson_nonce' );
		$current  = self::lesson_package_id( $post->ID );
		$packages = get_posts(
			array(
				'post_type'      => Package_Manager::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<select name="tutor_scorm_package_id" style="width:100%">
			<option value="0"><?php esc_html_e( '— None —', 'tutor-scorm' ); ?></option>
			<?php foreach ( $packages as $package ) : ?>
				<option value="<?php echo esc_attr( $package->ID ); ?>" <?php selected( $current, $package->ID ); ?>><?php echo esc_html( get_the_title( $package ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'The SCORM player is shown after the lesson content and completes the lesson automatically.', 'tutor-scorm' ); ?></p>
		<?php
	}

	public static function save_lesson_meta_box( $post_id, $post ) {
		if ( ! $post || self::lesson_post_type() !== $post->post_type ) {
			return;
		}
		if ( ! isset( $_POST['tutor_scorm_lesson_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tutor_scorm_lesson_nonce'] ) ), 'tutor_scorm_lesson' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( Settings::capability() ) ) {
			return;
		}
		$package_id = isset( $_POST['tutor_scorm_package_id'] ) ? absint( $_POST['tutor_scorm_package_id'] ) : 0;
		if ( $package_id && Package_Manager::POST_TYPE === get_post_type( $package_id ) ) {
			update_post_meta( $post_id, Package_Manager::LESSON_META, $package_id );
		} else {
			delete_post_meta( $post_id, Package_Manager::LESSON_META );
		}
	}
}
