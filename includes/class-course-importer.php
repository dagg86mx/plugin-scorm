<?php
/**
 * Course bundle importer: one ZIP with an outline file and a SCORM package per lesson.
 *
 * Bundle layout:
 *   outline.csv | outline.json      Course structure (see README).
 *   modulo-1/leccion-1.zip          A SCORM package as a ZIP...
 *   modulo-1/leccion-2/             ...or as an extracted folder containing imsmanifest.xml.
 *
 * Without an outline file every SCORM ZIP/folder at the bundle root becomes a lesson.
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || exit;

class Course_Importer {

	const PAGE   = 'tutor-scorm-import';
	const INBOX  = 'tutor-scorm-inbox';
	const ACTION = 'tutor_scorm_import_course';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_tutor_scorm_outline_template', array( __CLASS__, 'download_template' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'tutor-scorm import-course', array( __CLASS__, 'cli_import' ) );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Import
	 * ------------------------------------------------------------------ */

	/**
	 * Import a course bundle.
	 *
	 * @param string $zip_path Bundle ZIP.
	 * @param array  $options  course_id (0 = new course), status (draft|publish|pending|private), default_title.
	 * @return array|\WP_Error Import report.
	 */
	public static function import_bundle( $zip_path, array $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'course_id'     => 0,
				'status'        => 'draft',
				'default_title' => '',
			)
		);
		if ( ! Plugin::tutor_active() ) {
			return new \WP_Error( 'tutor_scorm_no_tutor', __( 'Tutor LMS must be active to import courses.', 'tutor-scorm' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_raise_memory_limit( 'admin' );

		$temp = Storage::extract_bundle( $zip_path );
		if ( is_wp_error( $temp ) ) {
			return $temp;
		}

		try {
			$report = self::import_directory( $temp, $options );
		} finally {
			Storage::delete_dir( basename( $temp ) );
		}
		return $report;
	}

	/**
	 * Import from an extracted bundle folder.
	 *
	 * @return array|\WP_Error
	 */
	public static function import_directory( $directory, array $options ) {
		$root    = self::content_root( $directory );
		$outline = null;
		$warnings = array();

		$outline_file = self::find_outline( $root );
		if ( $outline_file ) {
			try {
				$outline = Outline_Parser::parse_file( $outline_file );
			} catch ( \RuntimeException $e ) {
				/* translators: 1: file name, 2: error message. */
				return new \WP_Error( 'tutor_scorm_outline', sprintf( __( 'The outline file %1$s is not valid: %2$s', 'tutor-scorm' ), basename( $outline_file ), $e->getMessage() ) );
			}
		} else {
			$outline = self::auto_outline( $root );
			if ( ! $outline ) {
				return new \WP_Error( 'tutor_scorm_outline', __( 'No outline file (outline.csv or outline.json) and no SCORM packages were found in the ZIP.', 'tutor-scorm' ) );
			}
			$warnings[] = __( 'No outline file was found: one lesson was created for each SCORM package in the ZIP.', 'tutor-scorm' );
		}

		// Course.
		$course_id      = absint( $options['course_id'] );
		$created_course = false;
		if ( $course_id ) {
			if ( get_post_type( $course_id ) !== Tutor_Integration::course_post_type() ) {
				return new \WP_Error( 'tutor_scorm_course', __( 'The selected course does not exist.', 'tutor-scorm' ) );
			}
		} else {
			$title = '' !== $outline['course']['title'] ? $outline['course']['title'] : $options['default_title'];
			$title = '' !== $title ? $title : __( 'Imported SCORM course', 'tutor-scorm' );
			$course_id = wp_insert_post(
				array(
					'post_type'    => Tutor_Integration::course_post_type(),
					'post_title'   => wp_strip_all_tags( $title ),
					'post_content' => wp_kses_post( $outline['course']['description'] ),
					'post_excerpt' => wp_strip_all_tags( $outline['course']['excerpt'] ),
					'post_status'  => self::sanitize_status( $options['status'] ),
					'post_author'  => get_current_user_id(),
				),
				true
			);
			if ( is_wp_error( $course_id ) ) {
				return $course_id;
			}
			update_post_meta( $course_id, '_tutor_course_price_type', 'free' );
			$created_course = true;
		}

		$topic_order = self::count_children( $course_id, Tutor_Integration::topic_post_type() );
		$packages    = array(); // Source path => package ID, so a package shared by several lessons is imported once.
		$lessons     = array();
		$topic_count = 0;

		foreach ( $outline['topics'] as $topic ) {
			++$topic_order;
			$topic_title = '' !== $topic['title'] ? $topic['title'] : get_the_title( $course_id );
			$topic_id    = wp_insert_post(
				array(
					'post_type'    => Tutor_Integration::topic_post_type(),
					'post_title'   => wp_strip_all_tags( $topic_title ),
					'post_content' => wp_kses_post( $topic['summary'] ),
					'post_status'  => 'publish',
					'post_author'  => get_current_user_id(),
					'post_parent'  => $course_id,
					'menu_order'   => $topic_order,
				),
				true
			);
			if ( is_wp_error( $topic_id ) ) {
				$warnings[] = $topic_id->get_error_message();
				continue;
			}
			++$topic_count;

			$lesson_order = 0;
			foreach ( $topic['lessons'] as $lesson ) {
				++$lesson_order;
				$lessons[] = self::import_lesson( $root, $lesson, $topic_id, $topic_title, $lesson_order, $packages );
			}
		}

		do_action( 'tutor_scorm_course_imported', $course_id, $lessons );

		return array(
			'course_id'      => $course_id,
			'course_title'   => get_the_title( $course_id ),
			'created_course' => $created_course,
			'topics'         => $topic_count,
			'lessons'        => $lessons,
			'warnings'       => $warnings,
			'outline'        => $outline_file ? basename( $outline_file ) : '',
		);
	}

	/**
	 * Create one lesson and attach its SCORM package.
	 */
	private static function import_lesson( $root, array $lesson, $topic_id, $topic_title, $order, array &$packages ) {
		$row = array(
			'topic'      => $topic_title,
			'title'      => $lesson['title'],
			'scorm'      => $lesson['scorm'],
			'lesson_id'  => 0,
			'package_id' => 0,
			'status'     => 'ok',
			'message'    => '',
		);

		$package_id = 0;
		$manifest   = null;
		if ( '' !== $lesson['scorm'] || '' === $lesson['title'] ) {
			$key = '' !== $lesson['scorm'] ? $lesson['scorm'] : '.';
			if ( isset( $packages[ $key ] ) ) {
				$package_id = $packages[ $key ];
			} else {
				$result = self::create_package( $root, $lesson['scorm'], $lesson['title'] );
				if ( is_wp_error( $result ) ) {
					$row['status']  = 'error';
					$row['message'] = $result->get_error_message();
				} else {
					$package_id       = $result['package_id'];
					$manifest         = $result['manifest'];
					$packages[ $key ] = $package_id;
				}
			}
		} else {
			$row['status']  = 'warning';
			$row['message'] = __( 'No SCORM package was specified for this lesson.', 'tutor-scorm' );
		}

		$title = $lesson['title'];
		if ( '' === $title ) {
			$title = $package_id ? get_the_title( $package_id ) : ( $manifest ? $manifest['title'] : basename( $lesson['scorm'] ) );
		}
		$row['title'] = $title;

		$lesson_id = wp_insert_post(
			array(
				'post_type'      => Tutor_Integration::lesson_post_type(),
				'post_title'     => wp_slash( wp_strip_all_tags( $title ) ),
				'post_name'      => sanitize_title( $title ),
				'post_content'   => wp_slash( wp_kses_post( $lesson['content'] ) ),
				'post_status'    => 'publish',
				'comment_status' => 'open',
				'post_author'    => get_current_user_id(),
				'post_parent'    => $topic_id,
				'menu_order'     => $order,
			),
			true
		);
		if ( is_wp_error( $lesson_id ) ) {
			$row['status']  = 'error';
			$row['message'] = $lesson_id->get_error_message();
			return $row;
		}
		do_action( 'tutor/lesson/created', $lesson_id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- Tutor LMS hook.

		if ( $package_id ) {
			update_post_meta( $lesson_id, Package_Manager::LESSON_META, $package_id );
		}
		$row['lesson_id']  = $lesson_id;
		$row['package_id'] = $package_id;
		return $row;
	}

	/**
	 * Create a SCORM package post from a ZIP or folder inside the bundle.
	 *
	 * @return array|\WP_Error { package_id, manifest }
	 */
	private static function create_package( $root, $relative, $title ) {
		$source = self::resolve_source( $root, $relative );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$package_id = wp_insert_post(
			array(
				'post_type'   => Package_Manager::POST_TYPE,
				'post_title'  => wp_strip_all_tags( $title ),
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $package_id ) ) {
			return $package_id;
		}

		$manifest = 'zip' === $source['type']
			? Package_Manager::import( $package_id, $source['path'] )
			: Package_Manager::import_directory( $package_id, $source['path'] );

		if ( is_wp_error( $manifest ) ) {
			wp_delete_post( $package_id, true );
			return $manifest;
		}
		return array(
			'package_id' => $package_id,
			'manifest'   => $manifest,
		);
	}

	/**
	 * Resolve a path from the outline to a ZIP file or a folder inside the bundle.
	 *
	 * @return array|\WP_Error { type: zip|dir, path }
	 */
	private static function resolve_source( $root, $relative ) {
		$relative = Outline_Parser::normalize_path( $relative );
		$path     = '' === $relative ? $root : $root . '/' . $relative;
		$real     = realpath( $path );
		$base     = realpath( $root );

		if ( ! $real || ! $base || ( $real !== $base && 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) ) {
			// Allow "leccion-1" to match "leccion-1.zip".
			if ( '' !== $relative && is_file( $path . '.zip' ) ) {
				return array(
					'type' => 'zip',
					'path' => $path . '.zip',
				);
			}
			/* translators: %s: path inside the ZIP. */
			return new \WP_Error( 'tutor_scorm_missing', sprintf( __( 'The SCORM package "%s" was not found in the ZIP.', 'tutor-scorm' ), $relative ) );
		}
		if ( is_file( $real ) && 'zip' === strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ) ) {
			return array(
				'type' => 'zip',
				'path' => $real,
			);
		}
		if ( is_dir( $real ) ) {
			return array(
				'type' => 'dir',
				'path' => $real,
			);
		}
		/* translators: %s: path inside the ZIP. */
		return new \WP_Error( 'tutor_scorm_missing', sprintf( __( '"%s" is not a ZIP file or a folder.', 'tutor-scorm' ), $relative ) );
	}

	/**
	 * Bundles are often zipped with an enclosing folder: use it as root when it is the only entry.
	 */
	private static function content_root( $directory ) {
		$root = rtrim( $directory, '/' );
		for ( $level = 0; $level < 2; $level++ ) {
			if ( self::find_outline( $root ) || file_exists( $root . '/imsmanifest.xml' ) ) {
				break;
			}
			$entries = array_values( array_diff( (array) scandir( $root ), array( '.', '..', '__MACOSX', '.DS_Store' ) ) );
			if ( 1 === count( $entries ) && is_dir( $root . '/' . $entries[0] ) ) {
				$root .= '/' . $entries[0];
			} else {
				break;
			}
		}
		return $root;
	}

	private static function find_outline( $root ) {
		$files = array();
		foreach ( (array) scandir( $root ) as $entry ) {
			$files[ strtolower( $entry ) ] = $entry;
		}
		foreach ( Outline_Parser::FILE_NAMES as $name ) {
			if ( isset( $files[ $name ] ) && is_file( $root . '/' . $files[ $name ] ) ) {
				return $root . '/' . $files[ $name ];
			}
		}
		return null;
	}

	/**
	 * Outline built from the SCORM packages found at the bundle root.
	 */
	private static function auto_outline( $root ) {
		$lessons = array();
		if ( file_exists( $root . '/imsmanifest.xml' ) ) {
			$lessons[] = array(
				'title'   => '',
				'content' => '',
				'scorm'   => '',
			);
		} else {
			$entries = array_values( array_diff( (array) scandir( $root ), array( '.', '..', '__MACOSX' ) ) );
			natcasesort( $entries );
			foreach ( $entries as $entry ) {
				$path   = $root . '/' . $entry;
				$is_zip = is_file( $path ) && 'zip' === strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
				$is_dir = is_dir( $path ) && self::folder_has_manifest( $path );
				if ( $is_zip || $is_dir ) {
					$lessons[] = array(
						'title'   => '',
						'content' => '',
						'scorm'   => $entry,
					);
				}
			}
		}
		if ( ! $lessons ) {
			return null;
		}
		return array(
			'course' => array(
				'title'       => '',
				'description' => '',
				'excerpt'     => '',
			),
			'topics' => array(
				array(
					'title'   => '',
					'summary' => '',
					'lessons' => $lessons,
				),
			),
		);
	}

	private static function folder_has_manifest( $folder ) {
		if ( file_exists( $folder . '/imsmanifest.xml' ) ) {
			return true;
		}
		foreach ( (array) glob( $folder . '/*/imsmanifest.xml' ) as $file ) {
			if ( $file ) {
				return true;
			}
		}
		return false;
	}

	private static function count_children( $parent_id, $post_type ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s AND post_status <> 'trash'", $parent_id, $post_type ) ); // phpcs:ignore WordPress.DB
	}

	private static function sanitize_status( $status ) {
		return in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ? $status : 'draft';
	}

	/* ------------------------------------------------------------------ *
	 * Admin screen
	 * ------------------------------------------------------------------ */

	public static function inbox_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::INBOX;
	}

	/**
	 * ZIP files uploaded by FTP/SFTP to wp-content/uploads/tutor-scorm-inbox/ (for bundles above the upload limit).
	 */
	public static function inbox_files() {
		$dir = self::inbox_dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = array();
		foreach ( (array) glob( $dir . '/*.zip' ) as $file ) {
			if ( $file && is_file( $file ) ) {
				$files[] = basename( $file );
			}
		}
		natcasesort( $files );
		return array_values( $files );
	}

	public static function render_page() {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}
		$report_key = 'tutor_scorm_import_report_' . get_current_user_id();
		$report     = get_transient( $report_key );
		if ( $report ) {
			delete_transient( $report_key );
		}
		$courses = get_posts(
			array(
				'post_type'      => Tutor_Integration::course_post_type(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$inbox   = self::inbox_files();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import SCORM course', 'tutor-scorm' ); ?></h1>

			<?php if ( is_array( $report ) ) : ?>
				<?php self::render_report( $report ); ?>
			<?php endif; ?>

			<p><?php esc_html_e( 'Upload a ZIP that contains an outline file and one SCORM package per lesson. The course, its topics and lessons are created in Tutor LMS and each lesson gets its SCORM package.', 'tutor-scorm' ); ?></p>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php?action=' . self::ACTION ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tutor-scorm-zip"><?php esc_html_e( 'Course ZIP', 'tutor-scorm' ); ?></label></th>
						<td>
							<input type="file" id="tutor-scorm-zip" name="tutor_scorm_bundle" accept=".zip,application/zip">
							<p class="description">
								<?php
								/* translators: %s: maximum upload size. */
								echo esc_html( sprintf( __( 'Maximum upload size: %s.', 'tutor-scorm' ), size_format( wp_max_upload_size() ) ) );
								?>
							</p>
							<p>
								<input type="hidden" id="tutor-scorm-attachment" name="tutor_scorm_attachment_id" value="">
								<button type="button" class="button" id="tutor-scorm-media"><?php esc_html_e( 'Select ZIP', 'tutor-scorm' ); ?></button>
								<span id="tutor-scorm-attachment-name" class="description"></span>
							</p>
							<?php if ( $inbox ) : ?>
								<p>
									<label for="tutor-scorm-inbox"><?php esc_html_e( 'Or a ZIP uploaded by FTP:', 'tutor-scorm' ); ?></label>
									<select id="tutor-scorm-inbox" name="tutor_scorm_inbox_file">
										<option value=""><?php esc_html_e( '— None —', 'tutor-scorm' ); ?></option>
										<?php foreach ( $inbox as $file ) : ?>
											<option value="<?php echo esc_attr( $file ); ?>"><?php echo esc_html( $file ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
							<?php endif; ?>
							<p class="description">
								<?php
								/* translators: %s: folder path. */
								echo esc_html( sprintf( __( 'For very large courses, upload the ZIP by FTP to %s or use WP-CLI: wp tutor-scorm import-course course.zip', 'tutor-scorm' ), 'wp-content/uploads/' . self::INBOX . '/' ) );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tutor-scorm-course"><?php esc_html_e( 'Destination', 'tutor-scorm' ); ?></label></th>
						<td>
							<select id="tutor-scorm-course" name="tutor_scorm_course_id">
								<option value="0"><?php esc_html_e( 'Create a new course', 'tutor-scorm' ); ?></option>
								<?php foreach ( $courses as $course ) : ?>
									<option value="<?php echo esc_attr( $course->ID ); ?>">
										<?php
										/* translators: %s: course title. */
										echo esc_html( sprintf( __( 'Add to: %s', 'tutor-scorm' ), get_the_title( $course ) ) );
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tutor-scorm-status"><?php esc_html_e( 'New course status', 'tutor-scorm' ); ?></label></th>
						<td>
							<select id="tutor-scorm-status" name="tutor_scorm_status">
								<option value="draft"><?php esc_html_e( 'Draft (review before publishing)', 'tutor-scorm' ); ?></option>
								<option value="publish"><?php esc_html_e( 'Published', 'tutor-scorm' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Import course', 'tutor-scorm' ) ); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'ZIP structure', 'tutor-scorm' ); ?></h2>
			<pre style="background:#fff;border:1px solid #dcdcde;padding:12px;max-width:900px;overflow:auto">mi-curso.zip
├── outline.csv            <?php esc_html_e( '(or outline.json)', 'tutor-scorm' ); ?>

├── modulo-1/
│   ├── introduccion.zip   <?php esc_html_e( '← SCORM exported from your authoring tool', 'tutor-scorm' ); ?>

│   └── conceptos/         <?php esc_html_e( '← or the same SCORM already unzipped (with imsmanifest.xml)', 'tutor-scorm' ); ?>

└── modulo-2/
    └── evaluacion.zip</pre>
			<p><?php esc_html_e( 'outline.csv has one row per lesson. Columns: course, topic, lesson, scorm (path inside the ZIP) and content (optional text shown above the player). Spanish headers (curso, tema, leccion, scorm, contenido) also work. Separate with commas or semicolons.', 'tutor-scorm' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tutor_scorm_outline_template&format=csv' ), 'tutor_scorm_outline_template' ) ); ?>"><?php esc_html_e( 'Download outline.csv template', 'tutor-scorm' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tutor_scorm_outline_template&format=json' ), 'tutor_scorm_outline_template' ) ); ?>"><?php esc_html_e( 'Download outline.json template', 'tutor-scorm' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'If the ZIP has no outline file, one lesson is created for each SCORM ZIP or folder found at the top level, in alphabetical order.', 'tutor-scorm' ); ?></p>
		</div>
		<?php
	}

	private static function render_report( array $report ) {
		if ( ! empty( $report['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $report['error'] ) . '</p></div>';
			return;
		}
		$errors = count(
			array_filter(
				$report['lessons'],
				static function ( $row ) {
					return 'ok' !== $row['status'];
				}
			)
		);
		$class  = $errors ? 'notice-warning' : 'notice-success';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?>">
			<p>
				<strong>
					<?php
					/* translators: 1: course title, 2: topics, 3: lessons. */
					echo esc_html( sprintf( __( 'Course "%1$s": %2$d topic(s) and %3$d lesson(s) imported.', 'tutor-scorm' ), $report['course_title'], $report['topics'], count( $report['lessons'] ) ) );
					?>
				</strong>
				<a href="<?php echo esc_url( get_edit_post_link( $report['course_id'] ) ); ?>"><?php esc_html_e( 'Edit course', 'tutor-scorm' ); ?></a>
				&middot;
				<a href="<?php echo esc_url( get_permalink( $report['course_id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View course', 'tutor-scorm' ); ?></a>
			</p>
			<?php foreach ( $report['warnings'] as $warning ) : ?>
				<p><?php echo esc_html( $warning ); ?></p>
			<?php endforeach; ?>
		</div>
		<table class="widefat striped tutor-scorm-import-report" style="max-width:1100px;margin-bottom:24px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Topic', 'tutor-scorm' ); ?></th>
					<th><?php esc_html_e( 'Lesson', 'tutor-scorm' ); ?></th>
					<th><?php esc_html_e( 'SCORM package', 'tutor-scorm' ); ?></th>
					<th><?php esc_html_e( 'Result', 'tutor-scorm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $report['lessons'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['topic'] ); ?></td>
						<td>
							<?php if ( $row['lesson_id'] ) : ?>
								<a href="<?php echo esc_url( get_permalink( $row['lesson_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['title'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $row['title'] ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $row['package_id'] ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $row['package_id'] ) ); ?>"><?php echo esc_html( get_the_title( $row['package_id'] ) ); ?></a>
							<?php endif; ?>
							<br><code><?php echo esc_html( $row['scorm'] ); ?></code>
						</td>
						<td class="tutor-scorm-result-<?php echo esc_attr( $row['status'] ); ?>">
							<?php echo 'ok' === $row['status'] ? '&#10003;' : esc_html( $row['message'] ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function handle_upload() {
		$redirect = admin_url( 'admin.php?page=' . self::PAGE );
		$key      = 'tutor_scorm_import_report_' . get_current_user_id();

		// When the request exceeds post_max_size PHP discards the whole body, including the nonce.
		if ( empty( $_POST ) && ! empty( $_SERVER['CONTENT_LENGTH'] ) ) {
			set_transient( $key, array( 'error' => __( 'The ZIP is larger than the server allows (post_max_size). Upload it by FTP to wp-content/uploads/tutor-scorm-inbox/ or use WP-CLI.', 'tutor-scorm' ) ), 300 );
			wp_safe_redirect( $redirect );
			exit;
		}
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to import courses.', 'tutor-scorm' ), 403 );
		}

		$zip_path      = '';
		$default_title = '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file = isset( $_FILES['tutor_scorm_bundle'] ) ? $_FILES['tutor_scorm_bundle'] : null;
		$error = '';
		if ( $file && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
			if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				$error = __( 'The ZIP file could not be uploaded. Check the server upload_max_filesize and post_max_size limits.', 'tutor-scorm' );
			} elseif ( 'zip' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) ) {
				$error = __( 'Please upload a .zip file.', 'tutor-scorm' );
			} else {
				$zip_path      = $file['tmp_name'];
				$default_title = self::title_from_filename( $file['name'] );
			}
		} elseif ( ! empty( $_POST['tutor_scorm_attachment_id'] ) ) {
			$attached = get_attached_file( absint( $_POST['tutor_scorm_attachment_id'] ) );
			if ( $attached && 'zip' === strtolower( pathinfo( $attached, PATHINFO_EXTENSION ) ) && is_readable( $attached ) ) {
				$zip_path      = $attached;
				$default_title = self::title_from_filename( $attached );
			} else {
				$error = __( 'The selected Media Library file is not a ZIP archive.', 'tutor-scorm' );
			}
		} elseif ( ! empty( $_POST['tutor_scorm_inbox_file'] ) ) {
			$name = basename( sanitize_file_name( wp_unslash( $_POST['tutor_scorm_inbox_file'] ) ) );
			if ( in_array( $name, self::inbox_files(), true ) ) {
				$zip_path      = self::inbox_dir() . '/' . $name;
				$default_title = self::title_from_filename( $name );
			}
		}
		if ( ! $zip_path && ! $error ) {
			$error = __( 'Choose a ZIP file to import.', 'tutor-scorm' );
		}

		if ( $error ) {
			set_transient( $key, array( 'error' => $error ), 300 );
		} else {
			$report = self::import_bundle(
				$zip_path,
				array(
					'course_id'     => isset( $_POST['tutor_scorm_course_id'] ) ? absint( $_POST['tutor_scorm_course_id'] ) : 0,
					'status'        => isset( $_POST['tutor_scorm_status'] ) ? sanitize_key( $_POST['tutor_scorm_status'] ) : 'draft',
					'default_title' => $default_title,
				)
			);
			set_transient( $key, is_wp_error( $report ) ? array( 'error' => $report->get_error_message() ) : $report, 300 );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	private static function title_from_filename( $name ) {
		$title = pathinfo( (string) $name, PATHINFO_FILENAME );
		return trim( ucfirst( preg_replace( '/[-_]+/', ' ', $title ) ) );
	}

	public static function download_template() {
		check_admin_referer( 'tutor_scorm_outline_template' );
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( '', 403 );
		}
		$format = isset( $_GET['format'] ) && 'json' === $_GET['format'] ? 'json' : 'csv';
		nocache_headers();
		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=outline.json' );
			echo wp_json_encode( self::template_json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=outline.csv' );
			echo self::template_csv(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		exit;
	}

	public static function template_csv() {
		return "\xEF\xBB\xBF" . "curso,tema,leccion,scorm,contenido\n"
			. "Mi curso,Módulo 1: Fundamentos,Introducción,modulo-1/introduccion.zip,\"Bienvenido al curso.\"\n"
			. ",Módulo 1: Fundamentos,Conceptos clave,modulo-1/conceptos,\n"
			. ",Módulo 2: Práctica,Evaluación final,modulo-2/evaluacion.zip,Necesitas un 80% para aprobar.\n";
	}

	public static function template_json() {
		return array(
			'course' => array(
				'title'       => 'Mi curso',
				'description' => '<p>Descripción del curso.</p>',
			),
			'topics' => array(
				array(
					'title'   => 'Módulo 1: Fundamentos',
					'summary' => 'Resumen del módulo.',
					'lessons' => array(
						array(
							'title'   => 'Introducción',
							'scorm'   => 'modulo-1/introduccion.zip',
							'content' => '<p>Bienvenido al curso.</p>',
						),
						array(
							'title' => 'Conceptos clave',
							'scorm' => 'modulo-1/conceptos',
						),
					),
				),
				array(
					'title'   => 'Módulo 2: Práctica',
					'lessons' => array(
						array(
							'title'   => 'Evaluación final',
							'scorm'   => 'modulo-2/evaluacion.zip',
							'content' => '<p>Necesitas un 80% para aprobar.</p>',
						),
					),
				),
			),
		);
	}

	/* ------------------------------------------------------------------ *
	 * WP-CLI
	 * ------------------------------------------------------------------ */

	/**
	 * Import a course bundle ZIP (outline + one SCORM package per lesson) into Tutor LMS.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the course ZIP.
	 *
	 * [--course=<id>]
	 * : Add the topics and lessons to an existing course instead of creating one.
	 *
	 * [--status=<status>]
	 * : Status of the new course.
	 * ---
	 * default: draft
	 * options:
	 *   - draft
	 *   - publish
	 *   - pending
	 *   - private
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp tutor-scorm import-course curso.zip --user=admin --status=publish
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public static function cli_import( $args, $assoc_args ) {
		$file = $args[0];
		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( "Cannot read {$file}" );
		}
		if ( ! get_current_user_id() ) {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);
			if ( $admins ) {
				wp_set_current_user( (int) $admins[0] );
			}
		}
		$report = self::import_bundle(
			$file,
			array(
				'course_id'     => isset( $assoc_args['course'] ) ? absint( $assoc_args['course'] ) : 0,
				'status'        => isset( $assoc_args['status'] ) ? $assoc_args['status'] : 'draft',
				'default_title' => self::title_from_filename( $file ),
			)
		);
		if ( is_wp_error( $report ) ) {
			\WP_CLI::error( $report->get_error_message() );
		}
		foreach ( $report['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
		\WP_CLI\Utils\format_items( 'table', $report['lessons'], array( 'topic', 'title', 'lesson_id', 'package_id', 'status', 'message' ) );
		\WP_CLI::success( sprintf( 'Course #%d "%s": %d topic(s), %d lesson(s).', $report['course_id'], $report['course_title'], $report['topics'], count( $report['lessons'] ) ) );
	}
}
