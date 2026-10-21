<?php
/**
 * Pure progress engine: given a program's terms, the learner's inscription and the
 * state of each course, works out term/course statuses, locks and the next step.
 *
 * No WordPress dependencies, so it can be unit tested in isolation.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || defined( 'RA_TESTING' ) || exit;

class Progress {

	// Ordering modes (per program).
	const MODE_ADVISORY = 'advisory'; // Order is only a recommendation.
	const MODE_TERM     = 'term';     // Courses of future terms cannot be bought yet.
	const MODE_STRICT   = 'strict';   // Each course requires the previous one to be completed.

	// Inscription statuses.
	const STATUS_PENDING   = 'pending';   // Waiting for the inscription fee.
	const STATUS_ACTIVE    = 'active';
	const STATUS_PAUSED    = 'paused';
	const STATUS_DROPPED   = 'dropped';
	const STATUS_GRADUATED = 'graduated';

	/**
	 * Compute progress.
	 *
	 * @param array $args {
	 *     @type array  $terms            Ordered list of [ id, title, number, fee_course, courses => int[] ].
	 *     @type int    $start_term_id    Terms before this one are not required (revalidated).
	 *     @type int    $current_term_id  Term the learner is currently in.
	 *     @type string $status           Inscription status.
	 *     @type int    $inscription_fee  Course ID that represents the inscription fee (0 = none).
	 *     @type string $mode             Ordering mode.
	 *     @type array  $courses          course_id => [ enrolled => bool, completed => bool, percent => int ].
	 *     @type bool   $tuition_blocked  A monthly payment is overdue: courses stay locked until it is paid.
	 * }
	 * @return array
	 */
	public static function compute( array $args ) {
		$args = array_merge(
			array(
				'terms'           => array(),
				'start_term_id'   => 0,
				'current_term_id' => 0,
				'status'          => self::STATUS_ACTIVE,
				'inscription_fee' => 0,
				'mode'            => self::MODE_ADVISORY,
				'courses'         => array(),
				'tuition_blocked' => false,
			),
			$args
		);

		$terms   = array_values( $args['terms'] );
		$states  = $args['courses'];
		$state   = static function ( $course_id ) use ( $states ) {
			$s = isset( $states[ $course_id ] ) ? $states[ $course_id ] : array();
			return array(
				'enrolled'  => ! empty( $s['enrolled'] ),
				'completed' => ! empty( $s['completed'] ),
				'percent'   => isset( $s['percent'] ) ? max( 0, min( 100, (int) $s['percent'] ) ) : 0,
			);
		};
		$paid    = static function ( $course_id ) use ( $state ) {
			return ! $course_id || $state( $course_id )['enrolled'] || $state( $course_id )['completed'];
		};

		$start_index = self::index_of( $terms, $args['start_term_id'], 0 );

		$inscription_paid = $paid( (int) $args['inscription_fee'] );
		$status           = $args['status'];
		if ( self::STATUS_PENDING === $status && $inscription_paid ) {
			$status = self::STATUS_ACTIVE;
		}
		$blocked_by_status = in_array( $status, array( self::STATUS_PENDING, self::STATUS_PAUSED, self::STATUS_DROPPED ), true );

		// Current term: the stored one (never before the start), advanced past fully completed terms.
		$current_index = max( $start_index, self::index_of( $terms, $args['current_term_id'], $start_index ) );
		$finished      = false;
		while ( $terms ) {
			if ( $current_index >= count( $terms ) ) {
				$finished = true;
				break;
			}
			$pending = false;
			foreach ( $terms[ $current_index ]['courses'] as $course_id ) {
				if ( ! $state( $course_id )['completed'] ) {
					$pending = true;
					break;
				}
			}
			if ( $pending ) {
				break;
			}
			++$current_index;
		}
		if ( $finished ) {
			$current_index = count( $terms ) - 1;
			if ( self::STATUS_ACTIVE === $status ) {
				$status = self::STATUS_GRADUATED;
			}
		}

		$out_terms       = array();
		$next            = null;
		$total           = 0;
		$completed_count = 0;
		$order_blocked   = false; // Strict mode: an earlier required course is not completed yet.

		foreach ( $terms as $index => $term ) {
			$exempt   = $index < $start_index;
			$fee_paid = $paid( (int) ( isset( $term['fee_course'] ) ? $term['fee_course'] : 0 ) );

			$courses        = array();
			$term_completed = true;
			$term_started   = false;
			foreach ( $term['courses'] as $course_id ) {
				$s = $state( $course_id );
				if ( ! $s['completed'] ) {
					$term_completed = false;
				}
				if ( $s['enrolled'] || $s['completed'] ) {
					$term_started = true;
				}

				if ( $exempt ) {
					$courses[] = array(
						'id'          => (int) $course_id,
						'status'      => $s['completed'] ? 'completed' : 'exempt',
						'percent'     => $s['percent'],
						'lock_reason' => '',
						'is_next'     => false,
					);
					continue;
				}

				++$total;
				$lock = '';
				if ( $s['completed'] ) {
					$course_status = 'completed';
					++$completed_count;
				} elseif ( $s['enrolled'] ) {
					$course_status = 'in_progress';
				} else {
					if ( $blocked_by_status ) {
						$lock = self::STATUS_PENDING === $status ? 'inscription' : 'status';
					} elseif ( $args['tuition_blocked'] ) {
						$lock = 'tuition';
					} elseif ( ! $fee_paid ) {
						$lock = 'fee';
					} elseif ( self::MODE_TERM === $args['mode'] && $index > $current_index ) {
						$lock = 'term';
					} elseif ( self::MODE_STRICT === $args['mode'] && $order_blocked ) {
						$lock = 'order';
					}
					$course_status = $lock ? 'locked' : 'available';
				}

				if ( ! $s['completed'] ) {
					$order_blocked = true;
				}

				$courses[] = array(
					'id'          => (int) $course_id,
					'status'      => $course_status,
					'percent'     => $s['completed'] ? 100 : $s['percent'],
					'lock_reason' => $lock,
					'is_next'     => false,
				);
			}

			if ( $exempt ) {
				$term_status = 'exempt';
			} elseif ( $term_completed && $term['courses'] ) {
				$term_status = 'completed';
			} elseif ( $index === $current_index && ! $finished ) {
				$term_status = 'current';
			} elseif ( $index < $current_index ) {
				// An earlier term with unfinished courses (e.g. the learner was moved forward by an admin).
				$term_status = 'behind';
			} else {
				$term_status = $term_started ? 'current' : 'upcoming';
			}

			$out_terms[] = array(
				'id'         => (int) $term['id'],
				'title'      => isset( $term['title'] ) ? $term['title'] : '',
				'number'     => isset( $term['number'] ) ? (int) $term['number'] : $index + 1,
				'status'     => $term_status,
				'fee_course' => (int) ( isset( $term['fee_course'] ) ? $term['fee_course'] : 0 ),
				'fee_paid'   => $fee_paid,
				'courses'    => $courses,
			);
		}

		// Next step.
		if ( self::STATUS_PENDING === $status ) {
			$next = array(
				'action'    => 'pay_inscription',
				'course_id' => (int) $args['inscription_fee'],
				'term_id'   => 0,
			);
		} elseif ( ! $blocked_by_status && ! $finished && $args['tuition_blocked'] ) {
			$next = array(
				'action'    => 'pay_tuition',
				'course_id' => 0,
				'term_id'   => 0,
			);
		} elseif ( ! $blocked_by_status && ! $finished ) {
			foreach ( $out_terms as $t_index => $term ) {
				if ( 'exempt' === $term['status'] ) {
					continue;
				}
				foreach ( $term['courses'] as $c_index => $course ) {
					if ( 'completed' === $course['status'] ) {
						continue;
					}
					if ( 'fee' === $course['lock_reason'] ) {
						$next = array(
							'action'    => 'pay_fee',
							'course_id' => $term['fee_course'],
							'term_id'   => $term['id'],
						);
					} else {
						$next = array(
							'action'    => 'in_progress' === $course['status'] ? 'continue' : ( 'available' === $course['status'] ? 'enroll' : 'wait' ),
							'course_id' => $course['id'],
							'term_id'   => $term['id'],
						);
						$out_terms[ $t_index ]['courses'][ $c_index ]['is_next'] = true;
					}
					break 2;
				}
			}
		}

		return array(
			'status'          => $status,
			'current_term_id' => $terms ? (int) $terms[ $current_index ]['id'] : 0,
			'finished'        => $finished,
			'terms'           => $out_terms,
			'next'            => $next,
			'total'           => $total,
			'completed'       => $completed_count,
			'percent'         => $total ? (int) floor( $completed_count * 100 / $total ) : 0,
			'tuition_blocked' => (bool) $args['tuition_blocked'],
		);
	}

	/**
	 * Whether a course is locked for the learner in a computed progress.
	 *
	 * @return string Lock reason, '' when the course can be bought/enrolled, null when not part of the program.
	 */
	public static function lock_reason( array $progress, $course_id ) {
		$found = null;
		foreach ( $progress['terms'] as $term ) {
			foreach ( $term['courses'] as $course ) {
				if ( (int) $course['id'] === (int) $course_id ) {
					// The same course can appear in several terms: it is allowed if any occurrence is.
					if ( 'locked' !== $course['status'] ) {
						return '';
					}
					$found = $course['lock_reason'];
				}
			}
		}
		return $found;
	}

	/**
	 * Locate a course in the program: [ term index, position (1-based), term size ].
	 */
	public static function locate( array $progress, $course_id ) {
		foreach ( $progress['terms'] as $t_index => $term ) {
			foreach ( $term['courses'] as $c_index => $course ) {
				if ( (int) $course['id'] === (int) $course_id ) {
					return array(
						'term'     => $term,
						'position' => $c_index + 1,
						'of'       => count( $term['courses'] ),
						'course'   => $course,
					);
				}
			}
		}
		return null;
	}

	private static function index_of( array $terms, $term_id, $default ) {
		foreach ( $terms as $index => $term ) {
			if ( (int) $term['id'] === (int) $term_id ) {
				return $index;
			}
		}
		return $default;
	}
}
