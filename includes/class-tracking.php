<?php
/**
 * Learner tracking storage (CMI data, status, score and time per SCO).
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || exit;

class Tracking {

	const MAX_KEYS         = 5000;
	const MAX_VALUE_LENGTH = 65536;

	/**
	 * HMAC binding a player instance to a user, package and lesson.
	 */
	public static function token( $user_id, $package_id, $lesson_id ) {
		return hash_hmac( 'sha256', absint( $user_id ) . '|' . absint( $package_id ) . '|' . absint( $lesson_id ), wp_salt( 'auth' ) . 'tutor_scorm' );
	}

	public static function verify_token( $token, $user_id, $package_id, $lesson_id ) {
		return is_string( $token ) && hash_equals( self::token( $user_id, $package_id, $lesson_id ), $token );
	}

	/**
	 * Tracking rows of a user for a package, keyed by SCO identifier.
	 *
	 * @return array
	 */
	public static function get_rows( $user_id, $package_id ) {
		global $wpdb;
		$table = Installer::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND package_id = %d", $user_id, $package_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$row['cmi']              = self::decode( $row['cmi_data'] );
			$out[ $row['sco_id'] ] = $row;
		}
		return $out;
	}

	public static function get_row( $user_id, $package_id, $sco_id ) {
		global $wpdb;
		$table = Installer::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND package_id = %d AND sco_id = %s", $user_id, $package_id, $sco_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $row ) {
			$row['cmi'] = self::decode( $row['cmi_data'] );
		}
		return $row;
	}

	private static function decode( $json ) {
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Keep only well-formed CMI keys with scalar values.
	 *
	 * @param mixed $raw Raw CMI map from the client.
	 * @return array
	 */
	public static function sanitize_cmi( $raw ) {
		$clean = array();
		if ( ! is_array( $raw ) ) {
			return $clean;
		}
		foreach ( $raw as $key => $value ) {
			if ( count( $clean ) >= self::MAX_KEYS ) {
				break;
			}
			$key = (string) $key;
			if ( strlen( $key ) > 255 || ! preg_match( '/^cmi\.[A-Za-z0-9_.]+$/', $key ) ) {
				continue;
			}
			if ( preg_match( '/\._(children|count|version)$/', $key ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = (string) $value;
			if ( strlen( $value ) > self::MAX_VALUE_LENGTH ) {
				$value = substr( $value, 0, self::MAX_VALUE_LENGTH );
			}
			// Strip invalid UTF-8 so the JSON column can always be encoded.
			$clean[ $key ] = wp_check_invalid_utf8( $value, true );
		}
		return $clean;
	}

	public static function session_time_key( $version ) {
		return '1.2' === $version ? 'cmi.core.session_time' : 'cmi.session_time';
	}

	public static function exit_key( $version ) {
		return '1.2' === $version ? 'cmi.core.exit' : 'cmi.exit';
	}

	/**
	 * Derive normalized status and score fields from CMI data.
	 *
	 * @return array
	 */
	public static function derive( $version, array $cmi ) {
		$num = static function ( $key ) use ( $cmi ) {
			return ( isset( $cmi[ $key ] ) && is_numeric( $cmi[ $key ] ) ) ? (float) $cmi[ $key ] : null;
		};

		if ( '1.2' === $version ) {
			$status = isset( $cmi['cmi.core.lesson_status'] ) ? strtolower( $cmi['cmi.core.lesson_status'] ) : 'not attempted';
			if ( ! in_array( $status, array( 'passed', 'completed', 'failed', 'incomplete', 'browsed', 'not attempted' ), true ) ) {
				$status = 'incomplete';
			}
			$raw    = $num( 'cmi.core.score.raw' );
			$min    = $num( 'cmi.core.score.min' );
			$max    = $num( 'cmi.core.score.max' );
			$scaled = null;
			if ( null !== $raw ) {
				$scaled = ( null !== $max && $max > 0 ) ? $raw / $max : $raw / 100;
				$scaled = (float) max( -1, min( 1, $scaled ) );
			}
			return array(
				'status'            => $status,
				'completion_status' => in_array( $status, array( 'completed', 'passed', 'failed' ), true ) ? 'completed' : ( 'not attempted' === $status ? 'not attempted' : 'incomplete' ),
				'success_status'    => in_array( $status, array( 'passed', 'failed' ), true ) ? $status : 'unknown',
				'score_raw'         => $raw,
				'score_min'         => $min,
				'score_max'         => $max,
				'score_scaled'      => $scaled,
			);
		}

		$completion = isset( $cmi['cmi.completion_status'] ) ? strtolower( $cmi['cmi.completion_status'] ) : 'unknown';
		$success    = isset( $cmi['cmi.success_status'] ) ? strtolower( $cmi['cmi.success_status'] ) : 'unknown';
		if ( ! in_array( $completion, array( 'completed', 'incomplete', 'not attempted', 'unknown' ), true ) ) {
			$completion = 'unknown';
		}
		if ( ! in_array( $success, array( 'passed', 'failed', 'unknown' ), true ) ) {
			$success = 'unknown';
		}
		$scaled = $num( 'cmi.score.scaled' );
		return array(
			'status'            => in_array( $success, array( 'passed', 'failed' ), true ) ? $success : $completion,
			'completion_status' => $completion,
			'success_status'    => $success,
			'score_raw'         => $num( 'cmi.score.raw' ),
			'score_min'         => $num( 'cmi.score.min' ),
			'score_max'         => $num( 'cmi.score.max' ),
			'score_scaled'      => null !== $scaled ? (float) max( -1, min( 1, $scaled ) ) : null,
		);
	}

	/**
	 * Whether a tracking row satisfies the configured completion rule.
	 */
	public static function is_satisfied( $completion, $success, $rule = null ) {
		$rule = $rule ? $rule : Settings::get( 'completion_rule' );
		switch ( $rule ) {
			case Settings::RULE_PASSED:
				$satisfied = 'passed' === $success;
				break;
			case Settings::RULE_ANY:
				$satisfied = 'completed' === $completion || in_array( $success, array( 'passed', 'failed' ), true );
				break;
			default:
				$satisfied = ( 'completed' === $completion || 'passed' === $success ) && 'failed' !== $success;
		}
		return (bool) apply_filters( 'tutor_scorm_is_satisfied', $satisfied, $completion, $success, $rule );
	}

	/**
	 * Persist a commit coming from the player.
	 *
	 * @param array $args {
	 *     @type int    $user_id
	 *     @type array  $package
	 *     @type array  $sco
	 *     @type int    $lesson_id
	 *     @type int    $course_id
	 *     @type array  $cmi
	 *     @type bool   $finish
	 *     @type bool   $new_session
	 *     @type bool   $asset
	 * }
	 * @return array Saved row.
	 */
	public static function save( array $args ) {
		global $wpdb;
		$table    = Installer::table();
		$package  = $args['package'];
		$sco      = $args['sco'];
		$version  = $package['version'];
		$user_id  = absint( $args['user_id'] );
		$existing = self::get_row( $user_id, $package['id'], $sco['identifier'] );
		$now      = current_time( 'mysql', true );

		$cmi         = $args['cmi'];
		$session_key = self::session_time_key( $version );
		$seconds     = $existing ? (float) $existing['total_seconds'] : 0.0;

		// A previous session that was never terminated still holds its session time: account for it now.
		if ( ! empty( $args['new_session'] ) && $existing && ! empty( $existing['cmi'][ $session_key ] ) ) {
			$seconds += Time::to_seconds( $existing['cmi'][ $session_key ] );
		}
		if ( ! empty( $args['finish'] ) && ! empty( $cmi[ $session_key ] ) ) {
			$seconds += Time::to_seconds( $cmi[ $session_key ] );
			unset( $cmi[ $session_key ] );
		}

		if ( ! empty( $args['asset'] ) ) {
			// Assets cannot talk to the runtime: launching them counts as completing them.
			$cmi[ '1.2' === $version ? 'cmi.core.lesson_status' : 'cmi.completion_status' ] = 'completed';
		}

		$derived   = self::derive( $version, $cmi );
		$satisfied = self::is_satisfied( $derived['completion_status'], $derived['success_status'] );

		$data = array(
			'lesson_id'         => absint( $args['lesson_id'] ) ? absint( $args['lesson_id'] ) : ( $existing ? (int) $existing['lesson_id'] : 0 ),
			'course_id'         => absint( $args['course_id'] ) ? absint( $args['course_id'] ) : ( $existing ? (int) $existing['course_id'] : 0 ),
			'scorm_version'     => $version,
			'status'            => $derived['status'],
			'completion_status' => $derived['completion_status'],
			'success_status'    => $derived['success_status'],
			'score_raw'         => $derived['score_raw'],
			'score_min'         => $derived['score_min'],
			'score_max'         => $derived['score_max'],
			'score_scaled'      => $derived['score_scaled'],
			'total_seconds'     => (int) min( 4294967295, round( $seconds ) ),
			'attempts'          => ( $existing ? (int) $existing['attempts'] : 0 ) + ( ! empty( $args['new_session'] ) ? 1 : 0 ),
			'cmi_data'          => wp_json_encode( $cmi ),
			'updated_at'        => $now,
		);
		if ( $satisfied && ( ! $existing || empty( $existing['completed_at'] ) ) ) {
			$data['completed_at'] = $now;
		}

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing['id'] ) ); // phpcs:ignore WordPress.DB
			$data['id'] = (int) $existing['id'];
		} else {
			$data['user_id']    = $user_id;
			$data['package_id'] = (int) $package['id'];
			$data['sco_id']     = $sco['identifier'];
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB
			$data['id'] = (int) $wpdb->insert_id;
		}

		$data['cmi']       = $cmi;
		$data['satisfied'] = $satisfied;

		do_action( 'tutor_scorm_tracking_saved', $data, $args );

		return $data;
	}

	/**
	 * Whether every SCO of the package is satisfied for the user.
	 */
	public static function package_satisfied( $user_id, array $package ) {
		$rows = self::get_rows( $user_id, $package['id'] );
		$scos = array_filter(
			$package['scos'],
			static function ( $sco ) {
				return 'sco' === $sco['scormtype'];
			}
		);
		if ( ! $scos ) {
			$scos = $package['scos'];
		}
		foreach ( $scos as $sco ) {
			$row = isset( $rows[ $sco['identifier'] ] ) ? $rows[ $sco['identifier'] ] : null;
			if ( ! $row || ! self::is_satisfied( $row['completion_status'], $row['success_status'] ) ) {
				return false;
			}
		}
		return true;
	}

	public static function delete_for_package( $package_id ) {
		global $wpdb;
		$wpdb->delete( Installer::table(), array( 'package_id' => absint( $package_id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	public static function delete_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( Installer::table(), array( 'user_id' => absint( $user_id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	public static function delete_ids( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$table        = Installer::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB
	}
}
