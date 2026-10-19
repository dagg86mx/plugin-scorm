<?php
/**
 * Pure weighted-average grade calculator (no WordPress dependencies).
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || defined( 'RA_TESTING' ) || exit;

class Grade_Calculator {

	const STATUS_NONE    = 'none';        // Nothing to grade yet.
	const STATUS_PARTIAL = 'in_progress'; // Some evaluations still missing.
	const STATUS_PASSED  = 'passed';
	const STATUS_FAILED  = 'failed';

	/**
	 * Compute a course grade.
	 *
	 * @param array $items    List of [ key, title, type, score (0-100 or null), state (graded|pending_review|not_taken) ].
	 * @param array $weights  key => weight. Empty array = every quiz weighs the same (other item types weigh 0).
	 * @param array $options  scale (10|100), passing (in scale units), decimals, final (bool: course finished, missing items count as 0).
	 * @return array
	 */
	public static function course( array $items, array $weights, array $options = array() ) {
		$options = array_merge(
			array(
				'scale'    => 10,
				'passing'  => 7,
				'decimals' => 1,
				'final'    => false,
			),
			$options
		);
		$scale = (float) $options['scale'];

		// Resolve weights.
		$resolved = array();
		foreach ( $items as $item ) {
			if ( $weights ) {
				$w = isset( $weights[ $item['key'] ] ) ? (float) $weights[ $item['key'] ] : 0.0;
			} else {
				$w = ( 'quiz' === $item['type'] ) ? 1.0 : 0.0;
			}
			$resolved[ $item['key'] ] = max( 0.0, $w );
		}
		$total_weight = array_sum( $resolved );

		$rows        = array();
		$sum         = 0.0;
		$graded_w    = 0.0;
		$missing     = 0;
		$reviewing   = 0;
		$missing_w   = 0.0;
		$any_graded  = false;
		foreach ( $items as $item ) {
			$w = $resolved[ $item['key'] ];
			if ( $w <= 0 ) {
				continue;
			}
			$share  = $total_weight > 0 ? $w / $total_weight * 100 : 0;
			$graded = 'graded' === $item['state'] && null !== $item['score'];
			if ( $graded ) {
				$any_graded = true;
				$sum       += $w * (float) $item['score'];
				$graded_w  += $w;
			} elseif ( 'pending_review' === $item['state'] ) {
				++$reviewing; // Waiting for the teacher: never counted as zero.
			} else {
				++$missing;
				$missing_w += $w;
			}
			$rows[] = array(
				'key'          => $item['key'],
				'title'        => $item['title'],
				'type'         => $item['type'],
				'state'        => $item['state'],
				'weight'       => round( $share, 2 ),
				'score'        => $graded ? self::to_scale( $item['score'], $scale, $options['decimals'] ) : null,
				'contribution' => $graded ? self::to_scale( $item['score'] * $share / 100, $scale, 2 ) : null,
			);
		}

		if ( ! $rows || ! $any_graded ) {
			return array(
				'grade'   => null,
				'percent' => null,
				'status'  => self::STATUS_NONE,
				'items'   => $rows,
				'missing' => $missing + $reviewing,
			);
		}

		// Evaluations not taken count as zero once the course is final; before that the grade reflects what was taken.
		// Evaluations under review are always left out until they are graded.
		$denominator = $graded_w + ( $options['final'] ? $missing_w : 0 );
		$percent     = $denominator > 0 ? $sum / $denominator : 0;
		$grade       = self::to_scale( $percent, $scale, $options['decimals'] );

		if ( $reviewing || ( $missing && ! $options['final'] ) ) {
			$status = self::STATUS_PARTIAL;
		} else {
			$status = $grade >= (float) $options['passing'] ? self::STATUS_PASSED : self::STATUS_FAILED;
		}

		return array(
			'grade'   => $grade,
			'percent' => round( $percent, 2 ),
			'status'  => $status,
			'items'   => $rows,
			'missing' => $missing + $reviewing,
		);
	}

	/**
	 * Apply an extraordinary exam (EXT) and compute the official final grade (CF).
	 *
	 * F is the ordinary grade (weighted average). When an EXT grade exists it replaces F.
	 * CF is an integer: passing grades are rounded half up (9.8 → 10), failing grades are
	 * truncated so rounding can never turn a failing grade into a passing one (6.8 → 6 when 7 passes).
	 *
	 * @param array      $result  Result of course().
	 * @param float|null $ext     Extraordinary grade in scale units, or null.
	 * @param float      $passing Minimum passing grade.
	 * @return array $result with ext, final and cf keys (status updated when EXT exists).
	 */
	public static function with_final( array $result, $ext, $passing ) {
		$result['ext']   = ( null === $ext || '' === $ext ) ? null : (float) $ext;
		$result['final'] = null !== $result['ext'] ? $result['ext'] : $result['grade'];
		if ( null !== $result['ext'] ) {
			$result['status'] = $result['ext'] >= (float) $passing ? self::STATUS_PASSED : self::STATUS_FAILED;
		}
		if ( null === $result['final'] ) {
			$result['cf'] = null;
		} elseif ( $result['final'] >= (float) $passing ) {
			$result['cf'] = (int) floor( $result['final'] + 0.5 );
		} else {
			$result['cf'] = (int) min( floor( $result['final'] ), ceil( (float) $passing ) - 1 );
		}
		return $result;
	}

	/**
	 * Average of course grades (only courses with a final status).
	 *
	 * @param array $grades List of results from course().
	 * @return float|null
	 */
	public static function average( array $grades, $decimals = 1 ) {
		$values = array();
		foreach ( $grades as $grade ) {
			if ( isset( $grade['status'] ) && in_array( $grade['status'], array( self::STATUS_PASSED, self::STATUS_FAILED ), true ) ) {
				$values[] = (float) ( isset( $grade['final'] ) && null !== $grade['final'] ? $grade['final'] : $grade['grade'] );
			}
		}
		return $values ? round( array_sum( $values ) / count( $values ), $decimals ) : null;
	}

	public static function to_scale( $percent, $scale, $decimals ) {
		return round( (float) $percent * (float) $scale / 100, (int) $decimals );
	}

	public static function format( $value, $decimals ) {
		return null === $value ? '—' : number_format( (float) $value, (int) $decimals, '.', '' );
	}
}
