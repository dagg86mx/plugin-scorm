<?php
/**
 * SCORM time interval helpers (CMITimespan for 1.2, ISO 8601 durations for 2004).
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || defined( 'TUTOR_SCORM_TESTING' ) || exit;

class Time {

	/**
	 * Convert a SCORM session time to seconds.
	 *
	 * @param string $value Either HHHH:MM:SS(.SS) or an ISO 8601 duration (PT1H2M3.5S).
	 * @return float Seconds, 0 when the value cannot be parsed.
	 */
	public static function to_seconds( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0.0;
		}
		if ( preg_match( '/^(\d+):(\d{1,2}):(\d{1,2}(?:\.\d+)?)$/', $value, $m ) ) {
			return (int) $m[1] * 3600 + (int) $m[2] * 60 + (float) $m[3];
		}
		if ( preg_match( '/^P(?:(\d+(?:\.\d+)?)Y)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)W)?(?:(\d+(?:\.\d+)?)D)?(?:T(?:(\d+(?:\.\d+)?)H)?(?:(\d+(?:\.\d+)?)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $value, $m ) && 'P' !== $value && 'PT' !== $value ) {
			$m = array_pad( $m, 8, '' );
			return (float) $m[1] * 31536000
				+ (float) $m[2] * 2592000
				+ (float) $m[3] * 604800
				+ (float) $m[4] * 86400
				+ (float) $m[5] * 3600
				+ (float) $m[6] * 60
				+ (float) $m[7];
		}
		return 0.0;
	}

	/**
	 * Format seconds as a SCORM 1.2 CMITimespan (HHHH:MM:SS.SS).
	 */
	public static function format_12( $seconds ) {
		$seconds = max( 0, (float) $seconds );
		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds - $hours * 3600 ) / 60 );
		$rest    = $seconds - $hours * 3600 - $minutes * 60;
		return sprintf( '%04d:%02d:%05.2f', min( $hours, 9999 ), $minutes, $rest );
	}

	/**
	 * Format seconds as an ISO 8601 duration used by SCORM 2004 (PT#H#M#S).
	 */
	public static function format_2004( $seconds ) {
		$seconds = max( 0, (float) $seconds );
		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds - $hours * 3600 ) / 60 );
		$rest    = round( $seconds - $hours * 3600 - $minutes * 60, 2 );
		return sprintf( 'PT%dH%dM%sS', $hours, $minutes, rtrim( rtrim( number_format( $rest, 2, '.', '' ), '0' ), '.' ) );
	}

	/**
	 * Human readable duration for reports (e.g. 1h 05m 09s).
	 */
	public static function human( $seconds ) {
		$seconds = (int) round( $seconds );
		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$secs    = $seconds % 60;
		if ( $hours ) {
			return sprintf( '%dh %02dm %02ds', $hours, $minutes, $secs );
		}
		if ( $minutes ) {
			return sprintf( '%dm %02ds', $minutes, $secs );
		}
		return sprintf( '%ds', $secs );
	}
}
