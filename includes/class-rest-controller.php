<?php
/**
 * REST endpoint receiving SCORM commits from the player.
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || exit;

class REST_Controller {

	const NAMESPACE = 'tutor-scorm/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/commit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'commit' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'package_id'  => array(
						'type'     => 'integer',
						'required' => true,
					),
					'lesson_id'   => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'sco_id'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'token'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'cmi'         => array(
						'type'    => 'object',
						'default' => array(),
					),
					'finish'      => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'new_session' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'asset'       => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	public static function commit( \WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$package_id = absint( $request['package_id'] );
		$lesson_id  = absint( $request['lesson_id'] );

		if ( ! Tracking::verify_token( $request['token'], $user_id, $package_id, $lesson_id ) ) {
			return new \WP_Error( 'tutor_scorm_forbidden', __( 'Invalid tracking token.', 'tutor-scorm' ), array( 'status' => 403 ) );
		}

		$package = Package_Manager::get( $package_id );
		if ( ! $package ) {
			return new \WP_Error( 'tutor_scorm_not_found', __( 'SCORM package not found.', 'tutor-scorm' ), array( 'status' => 404 ) );
		}

		$sco = null;
		foreach ( $package['scos'] as $candidate ) {
			if ( $candidate['identifier'] === (string) $request['sco_id'] ) {
				$sco = $candidate;
				break;
			}
		}
		if ( ! $sco ) {
			return new \WP_Error( 'tutor_scorm_bad_sco', __( 'Unknown SCO.', 'tutor-scorm' ), array( 'status' => 400 ) );
		}

		$can_complete = $lesson_id && Tutor_Integration::user_can_track_lesson( $user_id, $lesson_id );

		$row = Tracking::save(
			array(
				'user_id'     => $user_id,
				'package'     => $package,
				'sco'         => $sco,
				'lesson_id'   => $lesson_id,
				'course_id'   => $lesson_id ? Tutor_Integration::course_id_for_lesson( $lesson_id ) : 0,
				'cmi'         => Tracking::sanitize_cmi( $request['cmi'] ),
				'finish'      => (bool) $request['finish'],
				'new_session' => (bool) $request['new_session'],
				'asset'       => (bool) $request['asset'] && 'asset' === $sco['scormtype'],
			)
		);

		$lesson_completed = false;
		$newly_completed  = false;
		if ( $can_complete ) {
			if ( Tracking::package_satisfied( $user_id, $package ) ) {
				$newly_completed = Tutor_Integration::complete_lesson( $lesson_id, $user_id );
			}
			$lesson_completed = Tutor_Integration::is_lesson_completed( $lesson_id, $user_id );
		}

		return rest_ensure_response(
			array(
				'ok'                   => true,
				'status'               => $row['status'],
				'satisfied'            => (bool) $row['satisfied'],
				'total_time'           => '1.2' === $package['version'] ? Time::format_12( $row['total_seconds'] ) : Time::format_2004( $row['total_seconds'] ),
				'lesson_completed'     => $lesson_completed,
				'lesson_just_completed' => $newly_completed,
			)
		);
	}
}
