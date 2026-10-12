<?php
/**
 * Programs (carreras) and terms (tetramestres) data access.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Curriculum {

	const PROGRAM = 'ra_program';
	const TERM    = 'ra_term';

	private static $cache = array();

	public static function flush_cache() {
		self::$cache = array();
	}

	/**
	 * Ordered terms of a program, in the format used by Progress::compute().
	 *
	 * @param int  $program_id  Program ID.
	 * @param bool $include_all Include non-published terms (admin screens).
	 * @return array
	 */
	public static function terms( $program_id, $include_all = false ) {
		$key = 'terms_' . $program_id . '_' . (int) $include_all;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$posts = get_posts(
			array(
				'post_type'      => self::TERM,
				'post_parent'    => absint( $program_id ),
				'post_status'    => $include_all ? array( 'publish', 'draft', 'pending', 'private' ) : 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
			)
		);
		$terms = array();
		foreach ( $posts as $post ) {
			$terms[] = array(
				'id'         => $post->ID,
				'title'      => get_the_title( $post ),
				'number'     => (int) $post->menu_order,
				'fee_course' => absint( get_post_meta( $post->ID, '_ra_fee_course', true ) ),
				'courses'    => self::term_courses( $post->ID ),
			);
		}
		self::$cache[ $key ] = $terms;
		return $terms;
	}

	/**
	 * @return int[]
	 */
	public static function term_courses( $term_id ) {
		$courses = get_post_meta( $term_id, '_ra_courses', true );
		return is_array( $courses ) ? array_values( array_filter( array_map( 'absint', $courses ) ) ) : array();
	}

	public static function program_settings( $program_id ) {
		$mode = (string) get_post_meta( $program_id, '_ra_mode', true );
		return array(
			'fee_course'  => absint( get_post_meta( $program_id, '_ra_fee_course', true ) ),
			'mode'        => in_array( $mode, array( Progress::MODE_ADVISORY, Progress::MODE_TERM, Progress::MODE_STRICT ), true ) ? $mode : Progress::MODE_TERM,
			'self_enroll' => '0' !== (string) get_post_meta( $program_id, '_ra_self_enroll', true ),
		);
	}

	/**
	 * All course IDs a program needs to know about (term courses and fees).
	 *
	 * @return int[]
	 */
	public static function all_course_ids( $program_id ) {
		$ids = array( self::program_settings( $program_id )['fee_course'] );
		foreach ( self::terms( $program_id ) as $term ) {
			$ids[] = $term['fee_course'];
			$ids   = array_merge( $ids, $term['courses'] );
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Programs that include a course (as a term course or a fee).
	 *
	 * @return int[]
	 */
	public static function programs_with_course( $course_id ) {
		$found = array();
		foreach ( self::programs() as $program ) {
			if ( in_array( (int) $course_id, self::all_course_ids( $program->ID ), true ) ) {
				$found[] = $program->ID;
			}
		}
		return $found;
	}

	/**
	 * @return \WP_Post[]
	 */
	public static function programs( $include_all = false ) {
		$key = 'programs_' . (int) $include_all;
		if ( ! isset( self::$cache[ $key ] ) ) {
			self::$cache[ $key ] = get_posts(
				array(
					'post_type'      => self::PROGRAM,
					'post_status'    => $include_all ? array( 'publish', 'draft', 'pending', 'private' ) : 'publish',
					'posts_per_page' => -1,
					'orderby'        => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
				)
			);
		}
		return self::$cache[ $key ];
	}

	public static function first_term_id( $program_id ) {
		$terms = self::terms( $program_id );
		return $terms ? (int) $terms[0]['id'] : 0;
	}

	/**
	 * Tutor courses for admin selectors.
	 *
	 * @return \WP_Post[]
	 */
	public static function all_courses() {
		if ( ! isset( self::$cache['courses'] ) ) {
			self::$cache['courses'] = get_posts(
				array(
					'post_type'      => Tutor::course_post_type(),
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
		}
		return self::$cache['courses'];
	}
}
