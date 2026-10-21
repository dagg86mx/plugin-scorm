<?php
/**
 * Student file (expediente) rules: which requirements apply, coverage and reminders (no WordPress dependencies).
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || defined( 'RA_TESTING' ) || exit;

class Record_Rules {

	const MISSING  = 'missing';  // Never uploaded.
	const PENDING  = 'pending';  // Uploaded, waiting for validation ("Validando").
	const APPROVED = 'approved'; // Validated.
	const REJECTED = 'rejected'; // Rejected: the student must upload it again.
	const REPLACED = 'replaced'; // A pending upload replaced by a newer one (history only).

	/**
	 * File types students may upload: extension => MIME type.
	 */
	const TYPES = array(
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	);

	/**
	 * A requirement without programs applies to every student.
	 *
	 * @param int[] $requirement_programs Programs the requirement is limited to.
	 * @param int[] $user_programs        Programs the student is enrolled in.
	 */
	public static function applies( array $requirement_programs, array $user_programs ) {
		$requirement_programs = array_filter( array_map( 'intval', $requirement_programs ) );
		if ( ! $requirement_programs ) {
			return true;
		}
		return (bool) array_intersect( $requirement_programs, array_map( 'intval', $user_programs ) );
	}

	/**
	 * Uploaded (being validated) and validated documents are covered; missing and rejected ones are not.
	 */
	public static function covered( $status ) {
		return in_array( $status, array( self::PENDING, self::APPROVED ), true );
	}

	/**
	 * Whether the student can upload (or replace) the document.
	 */
	public static function can_upload( $status ) {
		return self::APPROVED !== $status;
	}

	/**
	 * Coverage of a student file.
	 *
	 * @param array $requirements requirement_id => bool required.
	 * @param array $statuses     requirement_id => status of the latest upload (absent = missing).
	 * @return array total, approved, pending, rejected, missing, outstanding (required ids not covered), complete.
	 */
	public static function summary( array $requirements, array $statuses ) {
		$out = array(
			'total'       => count( $requirements ),
			'approved'    => 0,
			'pending'     => 0,
			'rejected'    => 0,
			'missing'     => 0,
			'outstanding' => array(),
		);
		foreach ( $requirements as $id => $required ) {
			$status = isset( $statuses[ $id ] ) ? $statuses[ $id ] : self::MISSING;
			if ( isset( $out[ $status ] ) && 'total' !== $status ) {
				++$out[ $status ];
			}
			if ( $required && ! self::covered( $status ) ) {
				$out['outstanding'][] = $id;
			}
		}
		$out['complete'] = ! $out['outstanding'];
		return $out;
	}

	/**
	 * Reminders go out every $days days, counted from the last reminder or, for the first one, from the inscription.
	 *
	 * @param int $anchor Inscription timestamp.
	 * @param int $last   Last reminder timestamp (0 = never).
	 * @param int $days   Interval in days (0 = reminders off).
	 * @param int $now    Current timestamp.
	 */
	public static function reminder_due( $anchor, $last, $days, $now ) {
		$days = (int) $days;
		if ( $days <= 0 ) {
			return false;
		}
		$from = max( (int) $anchor, (int) $last );
		return $now - $from >= $days * 86400;
	}

	/**
	 * Allowed extensions from a comma separated list, limited to the safe types.
	 *
	 * @return string[]
	 */
	public static function extensions( $list ) {
		$list = array_map( 'trim', explode( ',', strtolower( (string) $list ) ) );
		$list = array_values( array_intersect( $list, array_keys( self::TYPES ) ) );
		if ( in_array( 'jpg', $list, true ) && ! in_array( 'jpeg', $list, true ) ) {
			$list[] = 'jpeg';
		}
		return $list ? $list : array( 'pdf', 'jpg', 'jpeg', 'png' );
	}
}
