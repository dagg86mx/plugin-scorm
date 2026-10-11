<?php
/**
 * Front-end SCORM player: shortcode, lesson injection and launch configuration.
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || exit;

class Player {

	const SHORTCODE = 'tutor_scorm';

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'the_content', array( __CLASS__, 'append_to_lesson' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'lesson_styles' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_preview' ) );
	}

	public static function register_assets() {
		wp_register_style( 'tutor-scorm-player', TUTOR_SCORM_URL . 'assets/css/player.css', array(), TUTOR_SCORM_VERSION );
		wp_register_script( 'tutor-scorm-player', TUTOR_SCORM_URL . 'assets/js/scorm-player.js', array(), TUTOR_SCORM_VERSION, true );
		wp_localize_script(
			'tutor-scorm-player',
			'TutorScormL10n',
			array(
				'saving'         => __( 'Saving…', 'tutor-scorm' ),
				'saved'          => __( 'Progress saved', 'tutor-scorm' ),
				'saveError'      => __( 'Progress could not be saved. Check your connection.', 'tutor-scorm' ),
				'lessonComplete' => __( 'Lesson completed', 'tutor-scorm' ),
				'launch'         => __( 'Launch course', 'tutor-scorm' ),
				'relaunch'       => __( 'Open course again', 'tutor-scorm' ),
				'popupOpen'      => __( 'The course is open in a new window. Keep this page open to save your progress.', 'tutor-scorm' ),
				'popupBlocked'   => __( 'Your browser blocked the course window. Allow pop-ups for this site and try again.', 'tutor-scorm' ),
				'previous'       => __( 'Previous', 'tutor-scorm' ),
				'next'           => __( 'Next', 'tutor-scorm' ),
				'fullscreen'     => __( 'Full screen', 'tutor-scorm' ),
				'exitFullscreen' => __( 'Exit full screen', 'tutor-scorm' ),
				'preview'        => __( 'Preview mode: progress is not saved.', 'tutor-scorm' ),
				'guest'          => __( 'Log in to save your progress.', 'tutor-scorm' ),
				'statuses'       => array(
					'not attempted' => __( 'Not started', 'tutor-scorm' ),
					'unknown'       => __( 'Not started', 'tutor-scorm' ),
					'incomplete'    => __( 'In progress', 'tutor-scorm' ),
					'browsed'       => __( 'Browsed', 'tutor-scorm' ),
					'completed'     => __( 'Completed', 'tutor-scorm' ),
					'passed'        => __( 'Passed', 'tutor-scorm' ),
					'failed'        => __( 'Failed', 'tutor-scorm' ),
				),
			)
		);

		// Enqueue early when we already know the current page shows a package (avoids a flash of unstyled player).
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$post    = get_post( $post_id );
			if ( $post && ( has_shortcode( $post->post_content, self::SHORTCODE ) || ( Tutor_Integration::is_lesson( $post_id ) && Tutor_Integration::lesson_package_id( $post_id ) ) ) ) {
				wp_enqueue_style( 'tutor-scorm-player' );
				wp_enqueue_script( 'tutor-scorm-player' );
			}
		}
	}

	/**
	 * [tutor_scorm id="123" height="640" mode="inline|popup"]
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'     => 0,
				'height' => '',
				'mode'   => '',
			),
			$atts,
			self::SHORTCODE
		);

		$post_id   = get_the_ID();
		$lesson_id = Tutor_Integration::is_lesson( $post_id ) ? (int) $post_id : 0;

		return self::render(
			absint( $atts['id'] ),
			array(
				'lesson_id' => $lesson_id,
				'height'    => $atts['height'],
				'mode'      => $atts['mode'],
			)
		);
	}

	/**
	 * Append the attached package to the Tutor lesson content.
	 */
	public static function append_to_lesson( $content ) {
		if ( ( is_admin() && ! wp_doing_ajax() ) || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || doing_filter( 'get_the_excerpt' ) ) {
			return $content;
		}
		$lesson_id = get_the_ID();
		if ( ! Tutor_Integration::is_lesson( $lesson_id ) ) {
			return $content;
		}
		$package_id = Tutor_Integration::lesson_package_id( $lesson_id );
		if ( ! $package_id || false !== strpos( $content, 'tutor-scorm-player' ) ) {
			return $content;
		}
		return $content . self::render( $package_id, array( 'lesson_id' => $lesson_id ) );
	}

	/**
	 * Hide Tutor's manual "Mark as complete" button on SCORM lessons.
	 */
	public static function lesson_styles() {
		if ( ! is_singular( Tutor_Integration::lesson_post_type() ) || ! Settings::get( 'hide_complete_button' ) ) {
			return;
		}
		if ( ! Tutor_Integration::lesson_has_scorm( get_queried_object_id() ) ) {
			return;
		}
		echo "<style id=\"tutor-scorm-hide-complete\">form:has(input[name=\"tutor_action\"][value=\"tutor_complete_lesson\"]),.tutor-lesson-complete-btn,.tutor-topbar-complete-btn{display:none!important}</style>\n";
	}

	public static function preview_url( $package_id ) {
		return add_query_arg(
			array(
				'tutor_scorm_preview' => absint( $package_id ),
				'_wpnonce'            => wp_create_nonce( 'tutor_scorm_preview_' . absint( $package_id ) ),
			),
			home_url( '/' )
		);
	}

	public static function maybe_render_preview() {
		if ( empty( $_GET['tutor_scorm_preview'] ) ) {
			return;
		}
		$package_id = absint( $_GET['tutor_scorm_preview'] );
		$nonce      = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! current_user_can( Settings::capability() ) || ! wp_verify_nonce( $nonce, 'tutor_scorm_preview_' . $package_id ) ) {
			wp_die( esc_html__( 'You are not allowed to preview this package.', 'tutor-scorm' ), 403 );
		}
		// template_redirect runs before wp_enqueue_scripts, so register the assets now.
		self::register_assets();
		$player = self::render(
			$package_id,
			array(
				'preview' => true,
				'height'  => 'auto',
			)
		);
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( get_the_title( $package_id ) ); ?></title>
			<?php wp_print_styles( array( 'tutor-scorm-player' ) ); ?>
		</head>
		<body class="tutor-scorm-preview-page">
			<?php echo $player; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render(). ?>
			<?php wp_print_scripts( array( 'tutor-scorm-player' ) ); ?>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Render the player.
	 *
	 * @param int   $package_id Package ID.
	 * @param array $args       lesson_id, height, mode, preview.
	 * @return string
	 */
	public static function render( $package_id, array $args = array() ) {
		$args    = wp_parse_args(
			$args,
			array(
				'lesson_id' => 0,
				'height'    => '',
				'mode'      => '',
				'preview'   => false,
			)
		);
		$package = Package_Manager::get( $package_id );

		if ( ! $package || ( 'publish' !== $package['status'] && ! current_user_can( Settings::capability() ) ) ) {
			return current_user_can( Settings::capability() )
				? '<div class="tutor-scorm-notice">' . esc_html__( 'SCORM package not found or not imported yet.', 'tutor-scorm' ) . '</div>'
				: '';
		}

		wp_enqueue_style( 'tutor-scorm-player' );
		wp_enqueue_script( 'tutor-scorm-player' );

		$mode   = in_array( $args['mode'], array( 'inline', 'popup' ), true ) ? $args['mode'] : Settings::get( 'player_mode' );
		$height = $args['height'];
		if ( 'auto' !== $height ) {
			$height = absint( $height ) ? absint( $height ) : (int) Settings::get( 'player_height' );
		}

		$config = self::config( $package, absint( $args['lesson_id'] ), (bool) $args['preview'] );
		$config['mode'] = $mode;

		$classes = 'tutor-scorm-player tutor-scorm-mode-' . $mode . ( 'auto' === $height ? ' tutor-scorm-fill' : '' );
		$style   = 'auto' === $height ? '' : '--tutor-scorm-height:' . $height . 'px';

		ob_start();
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" style="<?php echo esc_attr( $style ); ?>" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="tutor-scorm-toolbar">
				<div class="tutor-scorm-nav"></div>
				<div class="tutor-scorm-status" aria-live="polite"></div>
				<button type="button" class="tutor-scorm-btn tutor-scorm-fullscreen" hidden><?php esc_html_e( 'Full screen', 'tutor-scorm' ); ?></button>
			</div>
			<div class="tutor-scorm-stage">
				<noscript><?php esc_html_e( 'JavaScript is required to play this course.', 'tutor-scorm' ); ?></noscript>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build the runtime configuration consumed by assets/js/scorm-player.js.
	 */
	public static function config( array $package, $lesson_id, $preview = false ) {
		$user     = wp_get_current_user();
		$user_id  = (int) $user->ID;
		$tracking = $user_id && ! $preview;
		$rows     = $tracking ? Tracking::get_rows( $user_id, $package['id'] ) : array();
		$version  = $package['version'];

		$name = trim( $user->last_name . ', ' . $user->first_name, ', ' );
		if ( '' === $name || false === strpos( $name, ',' ) ) {
			$name = $user_id ? $user->display_name : __( 'Guest', 'tutor-scorm' );
		}

		$scos = array();
		foreach ( $package['scos'] as $sco ) {
			$row = isset( $rows[ $sco['identifier'] ] ) ? $rows[ $sco['identifier'] ] : null;
			$cmi = $row ? $row['cmi'] : array();

			$entry = 'ab-initio';
			if ( $row ) {
				$entry = ( isset( $cmi[ Tracking::exit_key( $version ) ] ) && 'suspend' === $cmi[ Tracking::exit_key( $version ) ] ) ? 'resume' : '';
			}
			// Write-only, per-session values are never sent back to the content.
			unset( $cmi[ Tracking::exit_key( $version ) ], $cmi[ Tracking::session_time_key( $version ) ] );

			$total = $row ? (float) $row['total_seconds'] : 0;

			if ( '1.2' === $version ) {
				$ro = array(
					'cmi.core.student_id'              => $user_id ? (string) $user_id : 'guest',
					'cmi.core.student_name'            => $name,
					'cmi.core.credit'                  => 'credit',
					'cmi.core.entry'                   => $entry,
					'cmi.core.total_time'              => Time::format_12( $total ),
					'cmi.core.lesson_mode'             => 'normal',
					'cmi.launch_data'                  => $sco['datafromlms'],
					'cmi.comments_from_lms'            => '',
					'cmi.student_data.mastery_score'   => $sco['masteryscore'],
					'cmi.student_data.max_time_allowed' => $sco['maxtimeallowed'],
					'cmi.student_data.time_limit_action' => $sco['timelimitaction'],
				);
			} else {
				$ro = array(
					'cmi.learner_id'           => $user_id ? (string) $user_id : 'guest',
					'cmi.learner_name'         => $name,
					'cmi.credit'               => 'credit',
					'cmi.entry'                => $entry,
					'cmi.mode'                 => 'normal',
					'cmi.total_time'           => Time::format_2004( $total ),
					'cmi.launch_data'          => $sco['datafromlms'],
					'cmi.completion_threshold' => $sco['completion_threshold'],
					'cmi.scaled_passing_score' => $sco['scaled_passing_score'],
					'cmi.max_time_allowed'     => $sco['maxtimeallowed'],
					'cmi.time_limit_action'    => '' !== $sco['timelimitaction'] ? $sco['timelimitaction'] : 'continue,no message',
				);
			}

			$scos[] = array(
				'id'        => $sco['identifier'],
				'title'     => $sco['title'],
				'url'       => self::launch_url( $package, $sco ),
				'type'      => $sco['scormtype'],
				'depth'     => (int) $sco['depth'],
				'status'    => $row ? $row['status'] : ( '1.2' === $version ? 'not attempted' : 'unknown' ),
				'satisfied' => $row ? Tracking::is_satisfied( $row['completion_status'], $row['success_status'] ) : false,
				'cmi'       => (object) $cmi,
				'ro'        => apply_filters( 'tutor_scorm_runtime_values', $ro, $sco, $package, $user ),
			);
		}

		// Resume on the first unfinished SCO.
		$start = 0;
		foreach ( $scos as $index => $sco ) {
			if ( ! $sco['satisfied'] && 'sco' === $sco['type'] ) {
				$start = $index;
				break;
			}
		}

		return apply_filters(
			'tutor_scorm_player_config',
			array(
				'restUrl'         => esc_url_raw( rest_url( REST_Controller::NAMESPACE . '/commit' ) ),
				'nonce'           => $tracking ? wp_create_nonce( 'wp_rest' ) : '',
				'packageId'       => (int) $package['id'],
				'lessonId'        => (int) $lesson_id,
				'token'           => $tracking ? Tracking::token( $user_id, $package['id'], $lesson_id ) : '',
				'tracking'        => $tracking,
				'preview'         => (bool) $preview,
				'guest'           => ! $user_id,
				'version'         => $version,
				'title'           => $package['title'],
				'autosave'        => (int) Settings::get( 'autosave_interval' ),
				'lessonCompleted' => $tracking && $lesson_id ? Tutor_Integration::is_lesson_completed( $lesson_id, $user_id ) : false,
				'start'           => $start,
				'scos'            => $scos,
			),
			$package,
			$lesson_id
		);
	}

	private static function launch_url( array $package, array $sco ) {
		if ( $sco['external'] ) {
			return $sco['launch'];
		}
		return $package['base_url'] . '/' . str_replace( ' ', '%20', ltrim( $sco['launch'], '/' ) );
	}
}
