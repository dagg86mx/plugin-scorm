<?php
/**
 * SCORM package post type, upload handling and admin screens.
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || exit;

class Package_Manager {

	const POST_TYPE   = 'tutor_scorm';
	const LESSON_META = '_tutor_scorm_package_id';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'form_enctype' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'before_delete' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'post_updated_messages', array( __CLASS__, 'updated_messages' ) );
	}

	public static function register_post_type() {
		$cap = Settings::capability();

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'SCORM Packages', 'tutor-scorm' ),
					'singular_name'      => __( 'SCORM Package', 'tutor-scorm' ),
					'menu_name'          => __( 'SCORM', 'tutor-scorm' ),
					'all_items'          => __( 'SCORM Packages', 'tutor-scorm' ),
					'add_new'            => __( 'Add SCORM Package', 'tutor-scorm' ),
					'add_new_item'       => __( 'Add SCORM Package', 'tutor-scorm' ),
					'edit_item'          => __( 'Edit SCORM Package', 'tutor-scorm' ),
					'new_item'           => __( 'New SCORM Package', 'tutor-scorm' ),
					'search_items'       => __( 'Search SCORM Packages', 'tutor-scorm' ),
					'not_found'          => __( 'No SCORM packages found.', 'tutor-scorm' ),
					'not_found_in_trash' => __( 'No SCORM packages found in Trash.', 'tutor-scorm' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => Plugin::tutor_active() ? 'tutor' : true,
				'menu_icon'       => 'dashicons-welcome-learn-more',
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'map_meta_cap'    => false,
				'capabilities'    => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'delete_posts'           => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'create_posts'           => $cap,
					'delete_others_posts'    => $cap,
					'delete_published_posts' => $cap,
					'edit_published_posts'   => $cap,
				),
				'rewrite'         => false,
				'query_var'       => false,
				'can_export'      => true,
				'delete_with_user' => false,
			)
		);
	}

	/**
	 * Load a package definition.
	 *
	 * @param int $package_id Package post ID.
	 * @return array|null
	 */
	public static function get( $package_id ) {
		$post = get_post( absint( $package_id ) );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$folder = (string) get_post_meta( $post->ID, '_tutor_scorm_dir', true );
		$scos   = get_post_meta( $post->ID, '_tutor_scorm_scos', true );
		if ( '' === $folder || ! is_array( $scos ) || ! $scos ) {
			return null;
		}
		$root = (string) get_post_meta( $post->ID, '_tutor_scorm_root', true );

		return array(
			'id'       => $post->ID,
			'title'    => get_the_title( $post ),
			'status'   => $post->post_status,
			'version'  => (string) get_post_meta( $post->ID, '_tutor_scorm_version', true ),
			'scos'     => $scos,
			'folder'   => $folder,
			'root'     => $root,
			'base_url' => Storage::package_url( $folder, $root ),
		);
	}

	/**
	 * Import a ZIP file into a package post.
	 *
	 * @param int    $package_id Package post ID.
	 * @param string $zip_path   Path to the ZIP file.
	 * @return array|\WP_Error Parsed manifest.
	 */
	public static function import( $package_id, $zip_path ) {
		return self::finish_import( $package_id, Storage::extract( $zip_path, $package_id ) );
	}

	/**
	 * Import an already extracted SCORM folder (used by course bundles).
	 *
	 * @param int    $package_id Package post ID.
	 * @param string $directory  Folder containing imsmanifest.xml.
	 * @return array|\WP_Error Parsed manifest.
	 */
	public static function import_directory( $package_id, $directory ) {
		return self::finish_import( $package_id, Storage::copy_directory( $directory, $package_id ) );
	}

	/**
	 * Parse the manifest of freshly stored files and attach them to the package.
	 *
	 * @param int            $package_id Package post ID.
	 * @param array|\WP_Error $extracted  Result of Storage::extract() / copy_directory().
	 * @return array|\WP_Error Parsed manifest.
	 */
	private static function finish_import( $package_id, $extracted ) {
		if ( is_wp_error( $extracted ) ) {
			return $extracted;
		}

		$path = Storage::package_path( $extracted['dir'], $extracted['root'] );
		try {
			$manifest = Manifest_Parser::parse_file( $path . '/imsmanifest.xml' );
		} catch ( \RuntimeException $e ) {
			Storage::delete_dir( $extracted['dir'] );
			/* translators: %s: parser error message. */
			return new \WP_Error( 'tutor_scorm_manifest', sprintf( __( 'Invalid SCORM manifest: %s', 'tutor-scorm' ), $e->getMessage() ) );
		}

		foreach ( $manifest['scos'] as $sco ) {
			if ( ! $sco['external'] && ! self::is_contained_path( $sco['href'] ) ) {
				Storage::delete_dir( $extracted['dir'] );
				return new \WP_Error( 'tutor_scorm_manifest', __( 'The manifest references files outside of the package.', 'tutor-scorm' ) );
			}
		}

		$old_folder = (string) get_post_meta( $package_id, '_tutor_scorm_dir', true );

		update_post_meta( $package_id, '_tutor_scorm_dir', $extracted['dir'] );
		update_post_meta( $package_id, '_tutor_scorm_root', $extracted['root'] );
		update_post_meta( $package_id, '_tutor_scorm_version', $manifest['version'] );
		update_post_meta( $package_id, '_tutor_scorm_scos', $manifest['scos'] );
		update_post_meta( $package_id, '_tutor_scorm_manifest_title', $manifest['title'] );
		update_post_meta( $package_id, '_tutor_scorm_imported_at', current_time( 'mysql' ) );
		update_post_meta( $package_id, '_tutor_scorm_skipped_files', (int) $extracted['skipped'] );

		if ( $old_folder && $old_folder !== $extracted['dir'] ) {
			Storage::delete_dir( $old_folder );
		}

		$post = get_post( $package_id );
		if ( $post && ( '' === trim( $post->post_title ) || __( 'Auto Draft' ) === $post->post_title ) ) { // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_title' => wp_strip_all_tags( $manifest['title'] ) ), array( 'ID' => $package_id ) );
			clean_post_cache( $package_id );
		}

		do_action( 'tutor_scorm_package_imported', $package_id, $manifest );

		return $manifest;
	}

	/**
	 * Whether a relative path stays inside the package root once normalized.
	 */
	public static function is_contained_path( $href ) {
		$path  = (string) wp_parse_url( $href, PHP_URL_PATH );
		$depth = 0;
		if ( '' === $path || '/' === $path[0] ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				--$depth;
			} elseif ( '' !== $segment && '.' !== $segment ) {
				++$depth;
			}
			if ( $depth < 0 ) {
				return false;
			}
		}
		return true;
	}

	public static function form_enctype( $post ) {
		if ( $post && self::POST_TYPE === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	public static function add_meta_boxes() {
		add_meta_box( 'tutor-scorm-package', __( 'SCORM package', 'tutor-scorm' ), array( __CLASS__, 'render_package_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'tutor-scorm-lessons', __( 'Tutor LMS lessons', 'tutor-scorm' ), array( __CLASS__, 'render_lessons_box' ), self::POST_TYPE, 'normal', 'default' );
	}

	public static function render_package_box( $post ) {
		wp_nonce_field( 'tutor_scorm_save_package', 'tutor_scorm_package_nonce' );
		$package = self::get( $post->ID );
		?>
		<div class="tutor-scorm-admin-box">
			<?php if ( $package ) : ?>
				<table class="widefat striped tutor-scorm-summary">
					<tbody>
						<tr><th><?php esc_html_e( 'SCORM version', 'tutor-scorm' ); ?></th><td><?php echo esc_html( '1.2' === $package['version'] ? 'SCORM 1.2' : 'SCORM 2004' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Manifest title', 'tutor-scorm' ); ?></th><td><?php echo esc_html( get_post_meta( $post->ID, '_tutor_scorm_manifest_title', true ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Imported', 'tutor-scorm' ); ?></th><td><?php echo esc_html( get_post_meta( $post->ID, '_tutor_scorm_imported_at', true ) ); ?></td></tr>
						<tr>
							<th><?php esc_html_e( 'Shortcode', 'tutor-scorm' ); ?></th>
							<td><input type="text" readonly class="regular-text code tutor-scorm-copy" value="<?php echo esc_attr( '[tutor_scorm id="' . $post->ID . '"]' ); ?>" onclick="this.select();"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Preview', 'tutor-scorm' ); ?></th>
							<td><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( Player::preview_url( $post->ID ) ); ?>"><?php esc_html_e( 'Open preview', 'tutor-scorm' ); ?></a></td>
						</tr>
					</tbody>
				</table>
				<h4><?php esc_html_e( 'Launchable items', 'tutor-scorm' ); ?></h4>
				<ol class="tutor-scorm-sco-list">
					<?php foreach ( $package['scos'] as $sco ) : ?>
						<li style="margin-left:<?php echo (int) $sco['depth'] * 16; ?>px">
							<strong><?php echo esc_html( $sco['title'] ); ?></strong>
							<code><?php echo esc_html( $sco['launch'] ); ?></code>
							<?php if ( 'asset' === $sco['scormtype'] ) : ?>
								<em>(<?php esc_html_e( 'asset', 'tutor-scorm' ); ?>)</em>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
				<?php
				$skipped = (int) get_post_meta( $post->ID, '_tutor_scorm_skipped_files', true );
				if ( $skipped ) :
					?>
					<p class="description">
						<?php
						/* translators: %d: number of files. */
						echo esc_html( sprintf( _n( '%d server-side script file was skipped for security reasons.', '%d server-side script files were skipped for security reasons.', $skipped, 'tutor-scorm' ), $skipped ) );
						?>
					</p>
				<?php endif; ?>
				<hr>
				<h4><?php esc_html_e( 'Replace package', 'tutor-scorm' ); ?></h4>
				<p class="description"><?php esc_html_e( 'Uploading a new version keeps the learners\' progress as long as the SCO identifiers do not change.', 'tutor-scorm' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Upload a ZIP file exported as SCORM 1.2 or SCORM 2004 from your authoring tool (Articulate Storyline/Rise, iSpring, Adobe Captivate, Elucidat, H5P, etc.).', 'tutor-scorm' ); ?></p>
			<?php endif; ?>

			<p>
				<label for="tutor-scorm-zip"><strong><?php esc_html_e( 'Upload ZIP', 'tutor-scorm' ); ?></strong></label><br>
				<input type="file" id="tutor-scorm-zip" name="tutor_scorm_zip" accept=".zip,application/zip">
				<span class="description">
					<?php
					/* translators: %s: maximum upload size. */
					echo esc_html( sprintf( __( 'Maximum upload size: %s.', 'tutor-scorm' ), size_format( wp_max_upload_size() ) ) );
					?>
				</span>
			</p>
			<p>
				<strong><?php esc_html_e( 'Or use a ZIP from the Media Library', 'tutor-scorm' ); ?></strong><br>
				<input type="hidden" id="tutor-scorm-attachment" name="tutor_scorm_attachment_id" value="">
				<button type="button" class="button" id="tutor-scorm-media"><?php esc_html_e( 'Select ZIP', 'tutor-scorm' ); ?></button>
				<span id="tutor-scorm-attachment-name" class="description"></span>
			</p>
		</div>
		<?php
	}

	public static function render_lessons_box( $post ) {
		if ( ! Plugin::tutor_active() ) {
			echo '<p>' . esc_html__( 'Activate Tutor LMS to attach this package to lessons. You can still embed it anywhere with the shortcode.', 'tutor-scorm' ) . '</p>';
			return;
		}
		$attached = self::attached_lessons( $post->ID );
		$lessons  = get_posts(
			array(
				'post_type'      => Tutor_Integration::lesson_post_type(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => (int) apply_filters( 'tutor_scorm_lesson_select_limit', 1000 ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$grouped  = array();
		foreach ( $lessons as $lesson ) {
			$course_id = Tutor_Integration::course_id_for_lesson( $lesson->ID );
			$course    = $course_id ? get_the_title( $course_id ) : __( '(no course)', 'tutor-scorm' );
			$grouped[ $course ][] = $lesson;
		}
		ksort( $grouped );
		?>
		<p><?php esc_html_e( 'Select the lessons that should display this package. The player is added below the lesson content and the lesson is completed automatically when the learner finishes the package.', 'tutor-scorm' ); ?></p>
		<select name="tutor_scorm_lessons[]" multiple size="10" class="tutor-scorm-lessons" style="width:100%">
			<?php foreach ( $grouped as $course => $items ) : ?>
				<optgroup label="<?php echo esc_attr( $course ); ?>">
					<?php foreach ( $items as $lesson ) : ?>
						<option value="<?php echo esc_attr( $lesson->ID ); ?>" <?php selected( in_array( $lesson->ID, $attached, true ) ); ?>><?php echo esc_html( get_the_title( $lesson ) . ' (#' . $lesson->ID . ')' ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endforeach; ?>
		</select>
		<input type="hidden" name="tutor_scorm_lessons_present" value="1">
		<p class="description"><?php esc_html_e( 'Hold Ctrl (Cmd on Mac) to select several lessons. Alternatively, paste the shortcode into the lesson content from the Tutor course builder.', 'tutor-scorm' ); ?></p>
		<?php
	}

	/**
	 * @return int[]
	 */
	public static function attached_lessons( $package_id ) {
		global $wpdb;
		// Direct query: get_posts( 'post_type' => 'any' ) skips Tutor lessons because they are excluded from search.
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_status <> 'trash'",
				self::LESSON_META,
				(string) absint( $package_id )
			)
		);
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['tutor_scorm_package_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tutor_scorm_package_nonce'] ) ), 'tutor_scorm_save_package' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( Settings::capability() ) ) {
			return;
		}

		$zip_path = '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file = isset( $_FILES['tutor_scorm_zip'] ) ? $_FILES['tutor_scorm_zip'] : null;
		if ( $file && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
			if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				self::notice( 'error', __( 'The ZIP file could not be uploaded. Check the server upload_max_filesize and post_max_size limits.', 'tutor-scorm' ) );
			} elseif ( 'zip' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) ) {
				self::notice( 'error', __( 'Please upload a .zip file.', 'tutor-scorm' ) );
			} else {
				$zip_path = $file['tmp_name'];
			}
		} elseif ( ! empty( $_POST['tutor_scorm_attachment_id'] ) ) {
			$attachment_id = absint( $_POST['tutor_scorm_attachment_id'] );
			$attached_file = get_attached_file( $attachment_id );
			if ( $attached_file && 'zip' === strtolower( pathinfo( $attached_file, PATHINFO_EXTENSION ) ) && is_readable( $attached_file ) ) {
				$zip_path = $attached_file;
			} else {
				self::notice( 'error', __( 'The selected Media Library file is not a ZIP archive.', 'tutor-scorm' ) );
			}
		}

		if ( $zip_path ) {
			$result = self::import( $post_id, $zip_path );
			if ( is_wp_error( $result ) ) {
				self::notice( 'error', $result->get_error_message() );
			} else {
				/* translators: 1: SCORM version, 2: number of launchable items. */
				self::notice( 'success', sprintf( __( 'SCORM %1$s package imported with %2$d launchable item(s).', 'tutor-scorm' ), $result['version'], count( $result['scos'] ) ) );
			}
		}

		if ( ! empty( $_POST['tutor_scorm_lessons_present'] ) ) {
			$selected = isset( $_POST['tutor_scorm_lessons'] ) ? array_map( 'absint', (array) $_POST['tutor_scorm_lessons'] ) : array();
			$selected = array_filter( $selected );
			foreach ( self::attached_lessons( $post_id ) as $lesson_id ) {
				if ( ! in_array( $lesson_id, $selected, true ) ) {
					delete_post_meta( $lesson_id, self::LESSON_META );
				}
			}
			foreach ( $selected as $lesson_id ) {
				if ( get_post_type( $lesson_id ) === Tutor_Integration::lesson_post_type() ) {
					update_post_meta( $lesson_id, self::LESSON_META, $post_id );
				}
			}
		}
	}

	public static function before_delete( $post_id ) {
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return;
		}
		$folder = (string) get_post_meta( $post_id, '_tutor_scorm_dir', true );
		if ( $folder ) {
			Storage::delete_dir( $folder );
		}
		foreach ( self::attached_lessons( $post_id ) as $lesson_id ) {
			delete_post_meta( $lesson_id, self::LESSON_META );
		}
		Tracking::delete_for_package( $post_id );
	}

	public static function notice( $type, $message ) {
		$key     = 'tutor_scorm_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( $key, $notices, 120 );
	}

	public static function admin_notices() {
		$key     = 'tutor_scorm_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) || ! $notices ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( 'success' === $notice['type'] ? 'success' : 'error' ),
				esc_html( $notice['message'] )
			);
		}
	}

	public static function columns( $columns ) {
		$date = isset( $columns['date'] ) ? $columns['date'] : null;
		unset( $columns['date'] );
		$columns['tutor_scorm_version']   = __( 'Version', 'tutor-scorm' );
		$columns['tutor_scorm_scos']      = __( 'Items', 'tutor-scorm' );
		$columns['tutor_scorm_lessons']   = __( 'Lessons', 'tutor-scorm' );
		$columns['tutor_scorm_shortcode'] = __( 'Shortcode', 'tutor-scorm' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	public static function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'tutor_scorm_version':
				$version = get_post_meta( $post_id, '_tutor_scorm_version', true );
				echo $version ? esc_html( 'SCORM ' . $version ) : '&mdash;';
				break;
			case 'tutor_scorm_scos':
				$scos = get_post_meta( $post_id, '_tutor_scorm_scos', true );
				echo esc_html( is_array( $scos ) ? count( $scos ) : 0 );
				break;
			case 'tutor_scorm_lessons':
				$links = array();
				foreach ( self::attached_lessons( $post_id ) as $lesson_id ) {
					$links[] = '<a href="' . esc_url( get_permalink( $lesson_id ) ) . '">' . esc_html( get_the_title( $lesson_id ) ) . '</a>';
				}
				echo $links ? wp_kses_post( implode( ', ', $links ) ) : '&mdash;';
				break;
			case 'tutor_scorm_shortcode':
				echo '<code>' . esc_html( '[tutor_scorm id="' . $post_id . '"]' ) . '</code>';
				break;
		}
	}

	public static function updated_messages( $messages ) {
		$messages[ self::POST_TYPE ] = array(
			1  => __( 'SCORM package updated.', 'tutor-scorm' ),
			4  => __( 'SCORM package updated.', 'tutor-scorm' ),
			6  => __( 'SCORM package published.', 'tutor-scorm' ),
			7  => __( 'SCORM package saved.', 'tutor-scorm' ),
			10 => __( 'SCORM package draft updated.', 'tutor-scorm' ),
		);
		return $messages;
	}
}
