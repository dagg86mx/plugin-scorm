<?php
/**
 * Thin wrapper around the Tutor LMS API.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Tutor {

	/**
	 * Facts announced by Tutor hooks during this request. Tutor caches is_enrolled() per request,
	 * so right after an order is completed the cached value can still say "not enrolled".
	 *
	 * @var array
	 */
	private static $known = array();

	public static function remember( $course_id, $user_id, $fact ) {
		self::$known[ (int) $user_id ][ (int) $course_id ][ $fact ] = true;
	}

	private static function known( $course_id, $user_id, $fact ) {
		return ! empty( self::$known[ (int) $user_id ][ (int) $course_id ][ $fact ] );
	}

	public static function active() {
		return function_exists( 'tutor' ) && function_exists( 'tutor_utils' );
	}

	public static function course_post_type() {
		return ( self::active() && isset( tutor()->course_post_type ) ) ? tutor()->course_post_type : 'courses';
	}

	public static function is_enrolled( $course_id, $user_id ) {
		if ( self::known( $course_id, $user_id, 'enrolled' ) || self::known( $course_id, $user_id, 'completed' ) ) {
			return true;
		}
		return self::active() && $course_id && $user_id && (bool) tutor_utils()->is_enrolled( $course_id, $user_id );
	}

	public static function is_completed( $course_id, $user_id ) {
		if ( self::known( $course_id, $user_id, 'completed' ) ) {
			return true;
		}
		return self::active() && $course_id && $user_id && (bool) tutor_utils()->is_completed_course( $course_id, $user_id );
	}

	public static function percent( $course_id, $user_id ) {
		return self::active() ? (int) tutor_utils()->get_course_completed_percent( $course_id, $user_id ) : 0;
	}

	/**
	 * State of each course for a learner, in the format expected by Progress::compute().
	 *
	 * @param int[] $course_ids Course IDs.
	 * @param int   $user_id    Learner.
	 * @return array
	 */
	public static function course_states( array $course_ids, $user_id ) {
		$states = array();
		foreach ( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) as $course_id ) {
			$enrolled  = self::is_enrolled( $course_id, $user_id );
			$completed = $enrolled && self::is_completed( $course_id, $user_id );
			$states[ $course_id ] = array(
				'enrolled'  => $enrolled,
				'completed' => $completed,
				'percent'   => $enrolled ? self::percent( $course_id, $user_id ) : 0,
			);
		}
		return $states;
	}

	/**
	 * Formatted price of a course ("Gratis" for free courses).
	 */
	public static function price_label( $course_id ) {
		if ( ! self::active() || ! $course_id ) {
			return '';
		}
		$utils = tutor_utils();
		if ( method_exists( $utils, 'is_course_purchasable' ) && ! $utils->is_course_purchasable( $course_id ) ) {
			return __( 'Free', 'reticula-academica' );
		}
		if ( method_exists( $utils, 'get_course_price' ) ) {
			$price = $utils->get_course_price( $course_id );
			if ( $price ) {
				return wp_strip_all_tags( html_entity_decode( (string) $price ) );
			}
		}
		return '';
	}

	public static function dashboard_url( $page = '' ) {
		if ( self::active() && method_exists( tutor_utils(), 'tutor_dashboard_url' ) ) {
			return tutor_utils()->tutor_dashboard_url( $page );
		}
		return home_url( '/' );
	}
}
