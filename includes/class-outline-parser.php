<?php
/**
 * Parses course outline files (outline.json / outline.csv) used by course bundles.
 *
 * Normalized structure:
 *   array(
 *     'course' => array( 'title' => '', 'description' => '', 'excerpt' => '' ),
 *     'topics' => array(
 *       array( 'title' => '', 'summary' => '', 'lessons' => array(
 *         array( 'title' => '', 'content' => '', 'scorm' => 'path/in/bundle.zip' ),
 *       ) ),
 *     ),
 *   )
 *
 * This class has no WordPress dependencies so it can be unit tested in isolation.
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || defined( 'TUTOR_SCORM_TESTING' ) || exit;

class Outline_Parser {

	const MAX_LESSONS = 1000;

	/**
	 * File names recognised as outlines, in order of preference.
	 */
	const FILE_NAMES = array( 'outline.json', 'course.json', 'curso.json', 'outline.csv', 'course.csv', 'curso.csv', 'temario.csv', 'temario.json' );

	/**
	 * Key aliases (English and Spanish) accepted in JSON objects and CSV headers.
	 */
	const ALIASES = array(
		'course'      => array( 'course', 'curso' ),
		'title'       => array( 'title', 'titulo', 'título', 'name', 'nombre' ),
		'description' => array( 'description', 'descripcion', 'descripción' ),
		'excerpt'     => array( 'excerpt', 'extracto', 'resumen_corto' ),
		'topics'      => array( 'topics', 'temas', 'modules', 'modulos', 'módulos', 'sections', 'secciones' ),
		'topic'       => array( 'topic', 'tema', 'module', 'modulo', 'módulo', 'section', 'seccion', 'sección' ),
		'summary'     => array( 'summary', 'resumen', 'topic_summary', 'resumen_tema' ),
		'lessons'     => array( 'lessons', 'lecciones', 'items' ),
		'lesson'      => array( 'lesson', 'leccion', 'lección', 'lesson_title', 'titulo_leccion' ),
		'scorm'       => array( 'scorm', 'package', 'paquete', 'file', 'archivo', 'path', 'ruta', 'zip', 'folder', 'carpeta' ),
		'content'     => array( 'content', 'contenido', 'text', 'texto', 'lesson_content' ),
	);

	/**
	 * Parse an outline file by extension.
	 *
	 * @param string $path Absolute path.
	 * @return array Normalized outline.
	 * @throws \RuntimeException When the outline is invalid.
	 */
	public static function parse_file( $path ) {
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( 'The outline file could not be read.' );
		}
		$contents = (string) file_get_contents( $path );
		return 'csv' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ? self::parse_csv( $contents ) : self::parse_json( $contents );
	}

	/**
	 * @throws \RuntimeException When the JSON is invalid.
	 */
	public static function parse_json( $json ) {
		$json = self::to_utf8( $json );
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'The outline JSON is not valid: ' . json_last_error_msg() );
		}

		$course_data = self::pick( $data, 'course' );
		$course      = array(
			'title'       => '',
			'description' => '',
			'excerpt'     => '',
		);
		if ( is_array( $course_data ) ) {
			$course['title']       = self::text( self::pick( $course_data, 'title' ) );
			$course['description'] = self::text( self::pick( $course_data, 'description' ) );
			$course['excerpt']     = self::text( self::pick( $course_data, 'excerpt' ) );
		} elseif ( is_string( $course_data ) ) {
			$course['title'] = trim( $course_data );
		}
		if ( '' === $course['title'] ) {
			$course['title'] = self::text( self::pick( $data, 'title' ) );
		}
		if ( '' === $course['description'] ) {
			$course['description'] = self::text( self::pick( $data, 'description' ) );
		}

		$topics = array();
		$items  = self::pick( $data, 'topics' );
		if ( is_array( $items ) ) {
			foreach ( $items as $topic ) {
				if ( ! is_array( $topic ) ) {
					continue;
				}
				$lessons = array();
				foreach ( (array) self::pick( $topic, 'lessons' ) as $lesson ) {
					$lessons[] = self::lesson_from_array( $lesson );
				}
				$topics[] = array(
					'title'   => self::text( self::pick( $topic, 'title' ) ),
					'summary' => self::text( self::pick( $topic, 'summary' ) ),
					'lessons' => array_values( array_filter( $lessons ) ),
				);
			}
		}

		// Flat list of lessons, optionally carrying a "topic" field.
		$flat = self::pick( $data, 'lessons' );
		if ( ! $topics && is_array( $flat ) ) {
			$rows = array();
			foreach ( $flat as $lesson ) {
				if ( is_array( $lesson ) ) {
					$row          = self::lesson_from_array( $lesson );
					$row['topic'] = self::text( self::pick( $lesson, 'topic' ) );
					$rows[]       = $row;
				}
			}
			$topics = self::group_rows( $rows );
		}

		return self::finalize( $course, $topics );
	}

	/**
	 * Parse a CSV outline (one row per lesson). Comma or semicolon separated, header row required.
	 *
	 * @throws \RuntimeException When the CSV is invalid.
	 */
	public static function parse_csv( $csv ) {
		$csv   = self::to_utf8( $csv );
		$lines = preg_split( '/\r\n|\r|\n/', $csv );
		$first = '';
		foreach ( $lines as $line ) {
			if ( '' !== trim( $line ) ) {
				$first = $line;
				break;
			}
		}
		if ( '' === $first ) {
			throw new \RuntimeException( 'The outline CSV is empty.' );
		}
		$delimiter = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ( substr_count( $first, "\t" ) > substr_count( $first, ',' ) ? "\t" : ',' );

		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $handle, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $handle );

		$header  = null;
		$columns = array();
		$rows    = array();
		$course  = array(
			'title'       => '',
			'description' => '',
			'excerpt'     => '',
		);
		while ( false !== ( $record = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
			if ( null === $record || array( null ) === $record || '' === trim( implode( '', $record ) ) ) {
				continue;
			}
			if ( null === $header ) {
				$header = array_map( array( __CLASS__, 'normalize_key' ), $record );
				foreach ( array( 'course', 'topic', 'summary', 'lesson', 'title', 'scorm', 'content', 'description' ) as $canonical ) {
					foreach ( $header as $index => $name ) {
						if ( in_array( $name, array_map( array( __CLASS__, 'normalize_key' ), self::ALIASES[ $canonical ] ), true ) && ! isset( $columns[ $canonical ] ) ) {
							$columns[ $canonical ] = $index;
						}
					}
				}
				if ( ! isset( $columns['lesson'] ) && isset( $columns['title'] ) ) {
					$columns['lesson'] = $columns['title'];
				}
				if ( ! isset( $columns['lesson'] ) && ! isset( $columns['scorm'] ) ) {
					throw new \RuntimeException( 'The outline CSV needs a header row with at least the "lesson" or "scorm" column.' );
				}
				continue;
			}
			$get = static function ( $key ) use ( $columns, $record ) {
				return ( isset( $columns[ $key ] ) && isset( $record[ $columns[ $key ] ] ) ) ? trim( (string) $record[ $columns[ $key ] ] ) : '';
			};
			if ( '' === $course['title'] && '' !== $get( 'course' ) ) {
				$course['title'] = $get( 'course' );
			}
			if ( '' === $get( 'lesson' ) && '' === $get( 'scorm' ) ) {
				continue;
			}
			$rows[] = array(
				'topic'   => $get( 'topic' ),
				'summary' => $get( 'summary' ),
				'title'   => $get( 'lesson' ),
				'content' => '' !== $get( 'content' ) ? $get( 'content' ) : $get( 'description' ),
				'scorm'   => self::normalize_path( $get( 'scorm' ) ),
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return self::finalize( $course, self::group_rows( $rows ) );
	}

	/**
	 * Group flat lesson rows into topics, preserving first-appearance order.
	 */
	private static function group_rows( array $rows ) {
		$topics = array();
		$index  = array();
		foreach ( $rows as $row ) {
			$key = '' !== $row['topic'] ? $row['topic'] : "\0default";
			if ( ! isset( $index[ $key ] ) ) {
				$index[ $key ] = count( $topics );
				$topics[]      = array(
					'title'   => $row['topic'],
					'summary' => isset( $row['summary'] ) ? $row['summary'] : '',
					'lessons' => array(),
				);
			} elseif ( '' === $topics[ $index[ $key ] ]['summary'] && ! empty( $row['summary'] ) ) {
				$topics[ $index[ $key ] ]['summary'] = $row['summary'];
			}
			$topics[ $index[ $key ] ]['lessons'][] = array(
				'title'   => $row['title'],
				'content' => $row['content'],
				'scorm'   => $row['scorm'],
			);
		}
		return $topics;
	}

	private static function lesson_from_array( $lesson ) {
		if ( is_string( $lesson ) ) {
			// A bare string is a SCORM path.
			return array(
				'title'   => '',
				'content' => '',
				'scorm'   => self::normalize_path( $lesson ),
			);
		}
		if ( ! is_array( $lesson ) ) {
			return null;
		}
		$title = self::text( self::pick( $lesson, 'title' ) );
		if ( '' === $title ) {
			$title = self::text( self::pick( $lesson, 'lesson' ) );
		}
		$content = self::text( self::pick( $lesson, 'content' ) );
		if ( '' === $content ) {
			$content = self::text( self::pick( $lesson, 'description' ) );
		}
		return array(
			'title'   => $title,
			'content' => $content,
			'scorm'   => self::normalize_path( self::text( self::pick( $lesson, 'scorm' ) ) ),
		);
	}

	/**
	 * Drop empty topics and validate the result.
	 *
	 * @throws \RuntimeException When there are no lessons.
	 */
	private static function finalize( array $course, array $topics ) {
		$clean = array();
		$count = 0;
		foreach ( $topics as $topic ) {
			$lessons = array();
			foreach ( $topic['lessons'] as $lesson ) {
				if ( '' === $lesson['title'] && '' === $lesson['scorm'] ) {
					continue;
				}
				$lessons[] = $lesson;
			}
			if ( ! $lessons ) {
				continue;
			}
			$count        += count( $lessons );
			$topic['lessons'] = $lessons;
			$clean[]          = $topic;
		}
		if ( ! $count ) {
			throw new \RuntimeException( 'The outline does not contain any lesson.' );
		}
		if ( $count > self::MAX_LESSONS ) {
			throw new \RuntimeException( 'The outline contains too many lessons.' );
		}
		return array(
			'course' => $course,
			'topics' => $clean,
		);
	}

	/**
	 * Normalize a relative path inside the bundle. Returns '' for unsafe paths.
	 */
	public static function normalize_path( $path ) {
		$path = trim( str_replace( '\\', '/', (string) $path ) );
		if ( '' === $path || false !== strpos( $path, "\0" ) || preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $path ) ) {
			return '';
		}
		$parts = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return '';
			}
			$parts[] = $segment;
		}
		return implode( '/', $parts );
	}

	private static function pick( array $data, $canonical ) {
		$wanted = array_map( array( __CLASS__, 'normalize_key' ), self::ALIASES[ $canonical ] );
		foreach ( $data as $key => $value ) {
			if ( in_array( self::normalize_key( $key ), $wanted, true ) ) {
				return $value;
			}
		}
		return null;
	}

	public static function normalize_key( $key ) {
		$key = strtolower( trim( (string) $key ) );
		$key = strtr( $key, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u' ) );
		return preg_replace( '/[\s\-]+/', '_', $key );
	}

	private static function text( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Strip BOM and convert legacy encodings (e.g. CSV saved by Excel as Windows-1252).
	 */
	private static function to_utf8( $text ) {
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
		if ( ! preg_match( '//u', $text ) ) {
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
			} elseif ( function_exists( 'iconv' ) ) {
				$text = (string) iconv( 'Windows-1252', 'UTF-8//IGNORE', $text );
			}
		}
		return $text;
	}
}
