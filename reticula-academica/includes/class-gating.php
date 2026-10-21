<?php
/**
 * Enforces the program order: blocks enrolling in / buying locked courses.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Gating {

	public static function init() {
		add_filter( 'tutor_allow_course_enrollment', array( __CLASS__, 'filter' ), 20, 2 );
		add_filter( 'tutor_can_purchase_course', array( __CLASS__, 'filter' ), 20, 2 );
	}

	/**
	 * Lock reason for the current user and a course, or '' when allowed.
	 *
	 * Courses that are not part of any of the learner's programs are never restricted.
	 *
	 * @return array|null [ reason, inscription ] or null when allowed.
	 */
	public static function lock_for( $user_id, $course_id ) {
		if ( ! $user_id || user_can( $user_id, Post_Types::capability() ) ) {
			return null;
		}
		$lock = null;
		foreach ( Inscriptions::for_user( $user_id ) as $inscription ) {
			$reason = Progress::lock_reason( Inscriptions::progress( $inscription ), $course_id );
			if ( '' === $reason ) {
				return null; // Allowed by at least one program.
			}
			if ( null !== $reason && null === $lock ) {
				$lock = array( $reason, $inscription );
			}
		}
		return apply_filters( 'ra_course_lock', $lock, $user_id, $course_id );
	}

	public static function message( $reason ) {
		$messages = array(
			'inscription' => __( 'Pay your program inscription to unlock this course.', 'reticula-academica' ),
			'fee'         => __( 'Pay the term fee to unlock the courses of this term.', 'reticula-academica' ),
			'tuition'     => __( 'You have an overdue tuition payment. Pay it to unlock your courses.', 'reticula-academica' ),
			'term'        => __( 'This course belongs to a later term of your program.', 'reticula-academica' ),
			'order'       => __( 'Complete the previous course of your program first.', 'reticula-academica' ),
			'status'      => __( 'Your program inscription is not active.', 'reticula-academica' ),
		);
		return isset( $messages[ $reason ] ) ? $messages[ $reason ] : __( 'This course is not available yet.', 'reticula-academica' );
	}

	public static function filter( $allowed, $course_id ) {
		if ( is_wp_error( $allowed ) || false === $allowed ) {
			return $allowed; // Already blocked by someone else.
		}
		$lock = self::lock_for( get_current_user_id(), $course_id );
		if ( $lock ) {
			return new \WP_Error( 'ra_locked_' . $lock[0], self::message( $lock[0] ) );
		}
		return $allowed;
	}
}
