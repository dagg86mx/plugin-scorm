<?php
/**
 * Tuition (colegiaturas) rules: payment calendar, grace period and late fees (no WordPress dependencies).
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || defined( 'RA_TESTING' ) || exit;

class Tuition_Rules {

	const PENDING   = 'pending';
	const PAID      = 'paid';
	const CANCELLED = 'cancelled';

	// Moment of an unpaid payment.
	const UPCOMING = 'upcoming'; // Due date not reached.
	const DUE      = 'due';      // Due today or within the grace days: no late fee yet.
	const OVERDUE  = 'overdue';  // Grace period over: late fee and courses locked.

	/**
	 * Payment day and first due date. Inscriptions up to the cutoff day (15) pay on that same day of each
	 * month starting immediately; later ones move to day 1 of the next month.
	 *
	 * @param string $start  Y-m-d.
	 * @param int    $cutoff Last day that keeps its own day.
	 * @return array [ day, first due Y-m-d ]
	 */
	public static function first_due( $start, $cutoff = 15 ) {
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $start ) );
		if ( $d <= $cutoff ) {
			return array( $d, sprintf( '%04d-%02d-%02d', $y, $m, $d ) );
		}
		$next = self::add_months( sprintf( '%04d-%02d-01', $y, $m ), 1, 1 );
		return array( 1, $next );
	}

	/**
	 * Same day $months later (clamped to the end of shorter months).
	 */
	public static function add_months( $date, $months, $day ) {
		list( $y, $m ) = array_map( 'intval', explode( '-', $date ) );
		$index = $y * 12 + ( $m - 1 ) + (int) $months;
		$y     = intdiv( $index, 12 );
		$m     = $index % 12 + 1;
		$last  = (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) );
		return sprintf( '%04d-%02d-%02d', $y, $m, min( (int) $day, $last ) );
	}

	/**
	 * Monthly payments of a program, term after term without pauses.
	 *
	 * @param string $start  Billing start (Y-m-d).
	 * @param array  $terms  Ordered [ id, title, months, amount ] (terms before the student's start term excluded).
	 * @param int    $cutoff Cutoff day.
	 * @return array[] [ term_id, seq (1..months), months, due (Y-m-d), amount ]
	 */
	public static function schedule( $start, array $terms, $cutoff = 15 ) {
		list( $day, $first ) = self::first_due( $start, $cutoff );
		$out = array();
		$n   = 0;
		foreach ( $terms as $term ) {
			$months = max( 0, (int) $term['months'] );
			$amount = round( max( 0, (float) $term['amount'] ), 2 );
			if ( ! $months || $amount <= 0 ) {
				continue;
			}
			for ( $i = 1; $i <= $months; $i++ ) {
				$out[] = array(
					'term_id' => (int) $term['id'],
					'seq'     => $i,
					'months'  => $months,
					'due'     => self::add_months( $first, $n, $day ),
					'amount'  => $amount,
				);
				++$n;
			}
		}
		return $out;
	}

	/**
	 * Last day to pay without late fee.
	 */
	public static function grace_until( $due, $grace_days ) {
		return gmdate( 'Y-m-d', strtotime( $due . ' 00:00:00 UTC' ) + max( 0, (int) $grace_days ) * 86400 );
	}

	/**
	 * Moment of an unpaid payment on a given day.
	 */
	public static function moment( $due, $today, $grace_days ) {
		if ( $today < $due ) {
			return self::UPCOMING;
		}
		return $today > self::grace_until( $due, $grace_days ) ? self::OVERDUE : self::DUE;
	}

	/**
	 * Total to pay for a payment row.
	 */
	public static function total( array $payment ) {
		return round( (float) $payment['amount'] + (float) $payment['late_fee'], 2 );
	}

	/**
	 * Summary of a student's payments.
	 *
	 * @param array[] $payments Rows with status, due, amount, late_fee.
	 * @return array overdue (rows), due (rows within grace), next (first upcoming row), balance (overdue + due totals), blocked.
	 */
	public static function summary( array $payments, $today, $grace_days ) {
		$out = array(
			'overdue' => array(),
			'due'     => array(),
			'next'    => null,
			'balance' => 0.0,
			'paid'    => 0,
			'total'   => 0,
		);
		usort(
			$payments,
			static function ( $a, $b ) {
				return strcmp( $a['due'], $b['due'] );
			}
		);
		foreach ( $payments as $p ) {
			if ( self::CANCELLED === $p['status'] ) {
				continue;
			}
			++$out['total'];
			if ( self::PAID === $p['status'] ) {
				++$out['paid'];
				continue;
			}
			$moment = self::moment( $p['due'], $today, $grace_days );
			if ( self::UPCOMING === $moment ) {
				if ( null === $out['next'] ) {
					$out['next'] = $p;
				}
				continue;
			}
			$out[ self::OVERDUE === $moment ? 'overdue' : 'due' ][] = $p;
			$out['balance'] += self::total( $p );
		}
		$out['balance'] = round( $out['balance'], 2 );
		$out['blocked'] = (bool) $out['overdue'];
		return $out;
	}
}
