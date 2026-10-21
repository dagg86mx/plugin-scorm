<?php
/**
 * Student file (expediente): documents the school requests (birth certificate, high school certificate…),
 * uploads by the student, validation by staff and email reminders.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Records {

	const REQUIREMENT = 'ra_requirement';
	const PAGE        = 'ra-records';
	const FOLDER      = 'ra-expedientes';
	const CRON        = 'ra_records_daily';
	const REMINDED    = '_ra_records_reminded';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'send_reminders' ) );
		add_action( 'add_meta_boxes_' . self::REQUIREMENT, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::REQUIREMENT, array( __CLASS__, 'save_requirement' ), 10, 2 );
		add_filter( 'manage_' . self::REQUIREMENT . '_posts_columns', array( __CLASS__, 'requirement_columns' ) );
		add_action( 'manage_' . self::REQUIREMENT . '_posts_custom_column', array( __CLASS__, 'requirement_column' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_post_ra_upload_record', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_ra_review_record', array( __CLASS__, 'handle_review' ) );
		add_action( 'admin_post_ra_record_file', array( __CLASS__, 'handle_file' ) );
		add_action( 'admin_post_ra_record_remind', array( __CLASS__, 'handle_remind' ) );
		add_action( 'deleted_user', array( __CLASS__, 'delete_for_user' ) );
		add_shortcode( 'reticula_expediente', array( __CLASS__, 'shortcode' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ra_record_files';
	}

	public static function statuses() {
		return array(
			Record_Rules::MISSING  => __( 'Not uploaded', 'reticula-academica' ),
			Record_Rules::PENDING  => __( 'Validating', 'reticula-academica' ),
			Record_Rules::APPROVED => __( 'Validated', 'reticula-academica' ),
			Record_Rules::REJECTED => __( 'Rejected', 'reticula-academica' ),
			Record_Rules::REPLACED => __( 'Replaced', 'reticula-academica' ),
		);
	}

	public static function status_badge( $status ) {
		$labels = self::statuses();
		return '<span class="ra-badge ra-badge-rec-' . esc_attr( $status ) . '">' . esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) . '</span>';
	}

	/* ------------------------------------------------------------------ *
	 * Requested documents (requirements)
	 * ------------------------------------------------------------------ */

	public static function register() {
		$cap = Post_Types::capability();
		register_post_type(
			self::REQUIREMENT,
			array(
				'labels'       => array(
					'name'          => __( 'Requested documents', 'reticula-academica' ),
					'singular_name' => __( 'Requested document', 'reticula-academica' ),
					'all_items'     => __( 'Requested documents', 'reticula-academica' ),
					'add_new'       => __( 'Add requested document', 'reticula-academica' ),
					'add_new_item'  => __( 'Add requested document', 'reticula-academica' ),
					'edit_item'     => __( 'Edit requested document', 'reticula-academica' ),
					'search_items'  => __( 'Search requested documents', 'reticula-academica' ),
					'not_found'     => __( 'No requested documents yet.', 'reticula-academica' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . Curriculum::PROGRAM,
				'supports'     => array( 'title', 'editor', 'page-attributes' ),
				'map_meta_cap' => false,
				'capabilities' => array(
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
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
	}

	public static function requirement_settings( $id ) {
		$programs = get_post_meta( $id, '_ra_req_programs', true );
		$max      = (float) get_post_meta( $id, '_ra_req_max_mb', true );
		return array(
			'required' => '0' !== (string) get_post_meta( $id, '_ra_req_required', true ),
			'programs' => is_array( $programs ) ? array_map( 'intval', $programs ) : array(),
			'types'    => Record_Rules::extensions( (string) get_post_meta( $id, '_ra_req_types', true ) ),
			'max_mb'   => $max > 0 ? $max : 5,
		);
	}

	/**
	 * Maximum upload size of a requirement in bytes (never above the server limit).
	 */
	public static function max_bytes( array $settings ) {
		return (int) min( $settings['max_mb'] * 1048576, wp_max_upload_size() );
	}

	public static function meta_boxes() {
		add_meta_box( 'ra-requirement', __( 'Requirement settings', 'reticula-academica' ), array( __CLASS__, 'render_requirement_box' ), self::REQUIREMENT, 'side' );
	}

	public static function render_requirement_box( $post ) {
		$s = self::requirement_settings( $post->ID );
		wp_nonce_field( 'ra_requirement', 'ra_requirement_nonce' );
		?>
		<p><label><input type="checkbox" name="ra_req_required" value="1" <?php checked( $s['required'] ); ?>> <?php esc_html_e( 'Required (students get reminders until it is covered)', 'reticula-academica' ); ?></label></p>
		<p><strong><?php esc_html_e( 'Requested in', 'reticula-academica' ); ?></strong><br>
			<span class="description"><?php esc_html_e( 'None checked = every program.', 'reticula-academica' ); ?></span></p>
		<p>
			<?php foreach ( Curriculum::programs() as $program ) : ?>
				<label style="display:block"><input type="checkbox" name="ra_req_programs[]" value="<?php echo esc_attr( $program->ID ); ?>" <?php checked( in_array( (int) $program->ID, $s['programs'], true ) ); ?>> <?php echo esc_html( get_the_title( $program ) ); ?></label>
			<?php endforeach; ?>
		</p>
		<p><strong><?php esc_html_e( 'Accepted files', 'reticula-academica' ); ?></strong><br>
			<?php foreach ( array( 'pdf' => 'PDF', 'jpg' => 'JPG', 'png' => 'PNG', 'webp' => 'WEBP', 'doc' => 'DOC', 'docx' => 'DOCX' ) as $ext => $label ) : ?>
				<label style="margin-right:8px"><input type="checkbox" name="ra_req_types[]" value="<?php echo esc_attr( $ext ); ?>" <?php checked( in_array( $ext, $s['types'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
		</p>
		<p><label><?php esc_html_e( 'Maximum size (MB)', 'reticula-academica' ); ?><br><input type="number" name="ra_req_max_mb" min="0.1" step="0.1" value="<?php echo esc_attr( $s['max_mb'] ); ?>" class="small-text"></label>
			<br><span class="description">
				<?php
				/* translators: %s: size such as 8 MB. */
				echo esc_html( sprintf( __( 'Server limit: %s', 'reticula-academica' ), size_format( wp_max_upload_size() ) ) );
				?>
			</span></p>
		<p class="description"><?php esc_html_e( 'The description written in the editor is shown to the student as instructions. Order: "Order" field in Page attributes.', 'reticula-academica' ); ?></p>
		<?php
	}

	public static function save_requirement( $post_id, $post ) {
		if ( ! isset( $_POST['ra_requirement_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ra_requirement_nonce'] ) ), 'ra_requirement' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'revision' === $post->post_type || ! current_user_can( Post_Types::capability() ) ) {
			return;
		}
		$programs = isset( $_POST['ra_req_programs'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ra_req_programs'] ) ) ) ) : array();
		$types    = isset( $_POST['ra_req_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['ra_req_types'] ) ) : array();
		update_post_meta( $post_id, '_ra_req_required', empty( $_POST['ra_req_required'] ) ? '0' : '1' );
		update_post_meta( $post_id, '_ra_req_programs', $programs );
		update_post_meta( $post_id, '_ra_req_types', implode( ',', Record_Rules::extensions( implode( ',', $types ) ) ) );
		update_post_meta( $post_id, '_ra_req_max_mb', max( 0.1, (float) ( isset( $_POST['ra_req_max_mb'] ) ? wp_unslash( $_POST['ra_req_max_mb'] ) : 5 ) ) );
	}

	public static function requirement_columns( $columns ) {
		$date = isset( $columns['date'] ) ? $columns['date'] : null;
		unset( $columns['date'] );
		$columns['ra_required'] = __( 'Required', 'reticula-academica' );
		$columns['ra_programs'] = __( 'Programs', 'reticula-academica' );
		$columns['ra_types']    = __( 'Accepted files', 'reticula-academica' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	public static function requirement_column( $column, $post_id ) {
		$s = self::requirement_settings( $post_id );
		if ( 'ra_required' === $column ) {
			echo $s['required'] ? esc_html__( 'Yes', 'reticula-academica' ) : esc_html__( 'Optional', 'reticula-academica' );
		} elseif ( 'ra_programs' === $column ) {
			echo $s['programs'] ? esc_html( implode( ', ', array_map( 'get_the_title', $s['programs'] ) ) ) : esc_html__( 'All programs', 'reticula-academica' );
		} elseif ( 'ra_types' === $column ) {
			echo esc_html( strtoupper( implode( ', ', array_diff( $s['types'], array( 'jpeg' ) ) ) ) . ' · ' . size_format( self::max_bytes( $s ) ) );
		}
	}

	/**
	 * Published requirements, in order.
	 *
	 * @return \WP_Post[]
	 */
	public static function requirements() {
		return get_posts(
			array(
				'post_type'      => self::REQUIREMENT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
			)
		);
	}

	/**
	 * Program ids a student is enrolled in (any status but dropped).
	 */
	public static function user_programs( $user_id ) {
		return array_map( 'intval', wp_list_pluck( Inscriptions::for_user( $user_id ), 'program_id' ) );
	}

	/**
	 * Requirements that apply to a student. Users without inscriptions have no file.
	 *
	 * @return \WP_Post[]
	 */
	public static function requirements_for( $user_id, $requirements = null ) {
		$programs = self::user_programs( $user_id );
		if ( ! $programs ) {
			return array();
		}
		$requirements = null === $requirements ? self::requirements() : $requirements;
		return array_values(
			array_filter(
				$requirements,
				static function ( $req ) use ( $programs ) {
					return Record_Rules::applies( self::requirement_settings( $req->ID )['programs'], $programs );
				}
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Uploaded files
	 * ------------------------------------------------------------------ */

	public static function storage_dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::FOLDER;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Every upload of a student, newest first.
	 */
	public static function history( $user_id ) {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Latest upload per requirement.
	 *
	 * @return array requirement_id => row
	 */
	public static function latest( $user_id ) {
		$latest = array();
		foreach ( array_reverse( self::history( $user_id ) ) as $row ) {
			$latest[ (int) $row['requirement_id'] ] = $row;
		}
		return $latest;
	}

	/**
	 * The student's file: each requirement with its latest upload, plus the coverage summary.
	 *
	 * @return array items (requirement, settings, file, status), summary.
	 */
	public static function file_of( $user_id ) {
		$latest   = self::latest( $user_id );
		$items    = array();
		$required = array();
		$statuses = array();
		foreach ( self::requirements_for( $user_id ) as $req ) {
			$s      = self::requirement_settings( $req->ID );
			$file   = isset( $latest[ $req->ID ] ) ? $latest[ $req->ID ] : null;
			$status = $file ? $file['status'] : Record_Rules::MISSING;

			$items[]               = array(
				'requirement' => $req,
				'settings'    => $s,
				'file'        => $file,
				'status'      => $status,
			);
			$required[ $req->ID ]  = $s['required'];
			$statuses[ $req->ID ]  = $status;
		}
		return array(
			'items'   => $items,
			'summary' => Record_Rules::summary( $required, $statuses ),
		);
	}

	public static function file_url( $id, $download = false ) {
		$url = admin_url( 'admin-post.php?action=ra_record_file&file=' . absint( $id ) . ( $download ? '&dl=1' : '' ) );
		return wp_nonce_url( $url, 'ra_record_file_' . absint( $id ) );
	}

	public static function my_file_url() {
		return Tutor::active() ? Tutor::dashboard_url( Frontend::RECORDS_SLUG ) : home_url( '/' );
	}

	public static function admin_url( array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => Curriculum::PROGRAM,
					'page'      => self::PAGE,
				),
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Validate and store an uploaded file, and add it to the student's file.
	 *
	 * @param int   $user_id        Student.
	 * @param int   $requirement_id Requested document.
	 * @param array $upload         Entry of $_FILES.
	 * @param array $args           status (pending|approved), reviewed_by, note.
	 * @return array|\WP_Error New row.
	 */
	public static function store( $user_id, $requirement_id, array $upload, array $args = array() ) {
		global $wpdb;
		$s = self::requirement_settings( $requirement_id );
		if ( empty( $upload['tmp_name'] ) || ! empty( $upload['error'] ) ) {
			$too_big = isset( $upload['error'] ) && in_array( (int) $upload['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			return new \WP_Error( $too_big ? 'too_big' : 'no_file', $too_big ? self::message( 'too_big' ) : self::message( 'no_file' ) );
		}
		if ( ! is_uploaded_file( $upload['tmp_name'] ) ) {
			return new \WP_Error( 'no_file', self::message( 'no_file' ) );
		}
		$size = (int) filesize( $upload['tmp_name'] );
		if ( $size <= 0 ) {
			return new \WP_Error( 'no_file', self::message( 'no_file' ) );
		}
		if ( $size > self::max_bytes( $s ) ) {
			return new \WP_Error( 'too_big', self::message( 'too_big' ) );
		}
		$mimes = array_intersect_key( Record_Rules::TYPES, array_flip( $s['types'] ) );
		$check = wp_check_filetype_and_ext( $upload['tmp_name'], (string) $upload['name'], $mimes );
		$ext   = $check['ext'] ? strtolower( $check['ext'] ) : '';
		if ( ! $ext || ! isset( $mimes[ $ext ] ) ) {
			return new \WP_Error( 'type', self::message( 'type' ) );
		}
		// wp_check_filetype_and_ext() trusts the extension of documents; check the content too when possible.
		if ( 'pdf' === $ext ) {
			$head = (string) file_get_contents( $upload['tmp_name'], false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === strpos( $head, '%PDF-' ) ) {
				return new \WP_Error( 'type', self::message( 'type' ) );
			}
		}

		$dir = self::storage_dir() . '/' . (int) $user_id;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$name = (int) $requirement_id . '-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 16, false ) ) . '.' . $ext;
		$path = $dir . '/' . $name;
		if ( ! move_uploaded_file( $upload['tmp_name'], $path ) ) {
			return new \WP_Error( 'save', self::message( 'save' ) );
		}
		chmod( $path, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$table  = self::table();
		$status = isset( $args['status'] ) && Record_Rules::APPROVED === $args['status'] ? Record_Rules::APPROVED : Record_Rules::PENDING;
		$now    = current_time( 'mysql', true );
		// A pending upload that is replaced leaves the validation queue but stays in the history.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE user_id = %d AND requirement_id = %d AND status = %s", Record_Rules::REPLACED, $user_id, $requirement_id, Record_Rules::PENDING ) ); // phpcs:ignore WordPress.DB
		$wpdb->insert(
			$table,
			array(
				'user_id'        => (int) $user_id,
				'requirement_id' => (int) $requirement_id,
				'file'           => (int) $user_id . '/' . $name,
				'original_name'  => sanitize_file_name( wp_basename( (string) $upload['name'] ) ),
				'mime'           => $mimes[ $ext ],
				'size'           => $size,
				'status'         => $status,
				'note'           => isset( $args['note'] ) ? (string) $args['note'] : '',
				'uploaded_by'    => get_current_user_id(),
				'created_at'     => $now,
				'reviewed_at'    => Record_Rules::APPROVED === $status ? $now : null,
				'reviewed_by'    => Record_Rules::APPROVED === $status ? get_current_user_id() : 0,
			)
		);
		$row = self::get( (int) $wpdb->insert_id );
		do_action( 'ra_record_uploaded', $row );
		return $row;
	}

	/**
	 * Validate or reject an upload.
	 */
	public static function review( $id, $status, $note = '' ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row || ! in_array( $status, array( Record_Rules::APPROVED, Record_Rules::REJECTED ), true ) || Record_Rules::REPLACED === $row['status'] ) {
			return false;
		}
		$wpdb->update(
			self::table(),
			array(
				'status'      => $status,
				'note'        => (string) $note,
				'reviewed_at' => current_time( 'mysql', true ),
				'reviewed_by' => get_current_user_id(),
			),
			array( 'id' => (int) $id )
		);
		$row = self::get( $id );
		do_action( 'ra_record_reviewed', $row );
		if ( Settings::get( 'records_review_emails' ) ) {
			self::email_review( $row );
		}
		return $row;
	}

	public static function delete_for_user( $user_id ) {
		global $wpdb;
		$table = self::table();
		$dir   = self::storage_dir() . '/' . (int) $user_id;
		foreach ( self::history( $user_id ) as $row ) {
			if ( false === strpos( $row['file'], '..' ) ) {
				wp_delete_file( self::storage_dir() . '/' . $row['file'] );
			}
		}
		if ( is_dir( $dir ) ) {
			wp_delete_file( $dir . '/index.html' );
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		$wpdb->delete( $table, array( 'user_id' => (int) $user_id ) );
		delete_user_meta( $user_id, self::REMINDED );
	}

	/* ------------------------------------------------------------------ *
	 * Handlers
	 * ------------------------------------------------------------------ */

	public static function message( $code ) {
		$messages = array(
			'uploaded' => __( 'Document uploaded. Its status is "Validating" until the school reviews it.', 'reticula-academica' ),
			'no_file'  => __( 'Choose a file to upload.', 'reticula-academica' ),
			'too_big'  => __( 'The file is larger than the allowed size.', 'reticula-academica' ),
			'type'     => __( 'This type of file is not accepted for this document.', 'reticula-academica' ),
			'save'     => __( 'The file could not be saved. Please try again.', 'reticula-academica' ),
			'locked'   => __( 'This document is already validated.', 'reticula-academica' ),
			'denied'   => __( 'This document is not requested in your file.', 'reticula-academica' ),
		);
		return isset( $messages[ $code ] ) ? $messages[ $code ] : '';
	}

	private static function redirect_back( $fallback, $code ) {
		$back = wp_get_referer() ? wp_get_referer() : $fallback;
		// A unique URL so no cache can show the page as it was before the upload.
		$url = add_query_arg(
			array(
				'ra_rec' => $code,
				'ra_t'   => time(),
			),
			remove_query_arg( array( 'ra_rec', 'ra_t' ), $back )
		);
		wp_safe_redirect( $url . '#ra-records' );
		exit;
	}

	public static function handle_upload() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		$requirement_id = isset( $_POST['requirement'] ) ? absint( $_POST['requirement'] ) : 0;
		check_admin_referer( 'ra_upload_record_' . $requirement_id );
		$is_admin = current_user_can( Post_Types::capability() );
		$user_id  = ( $is_admin && ! empty( $_POST['user'] ) ) ? absint( $_POST['user'] ) : get_current_user_id();
		$fallback = $is_admin && get_current_user_id() !== $user_id ? self::admin_url( array( 'user' => $user_id ) ) : self::my_file_url();

		$applies = wp_list_pluck( self::requirements_for( $user_id ), 'ID' );
		if ( ! in_array( $requirement_id, array_map( 'intval', $applies ), true ) ) {
			self::redirect_back( $fallback, 'denied' );
		}
		$latest = self::latest( $user_id );
		$status = isset( $latest[ $requirement_id ] ) ? $latest[ $requirement_id ]['status'] : Record_Rules::MISSING;
		if ( ! $is_admin && ! Record_Rules::can_upload( $status ) ) {
			self::redirect_back( $fallback, 'locked' );
		}
		$upload = isset( $_FILES['ra_file'] ) && is_array( $_FILES['ra_file'] ) ? $_FILES['ra_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in store().
		$args   = array();
		if ( $is_admin && get_current_user_id() !== $user_id ) {
			// Staff uploading a document the student delivered in person: already validated.
			$args = array(
				'status' => Record_Rules::APPROVED,
				'note'   => __( 'Uploaded by the school.', 'reticula-academica' ),
			);
		}
		$row = self::store( $user_id, $requirement_id, $upload, $args );
		if ( is_wp_error( $row ) ) {
			self::redirect_back( $fallback, $row->get_error_code() );
		}
		if ( Record_Rules::PENDING === $row['status'] ) {
			self::email_staff( $row );
		}
		self::redirect_back( $fallback, 'uploaded' );
	}

	public static function handle_review() {
		$id = isset( $_POST['file'] ) ? absint( $_POST['file'] ) : 0;
		check_admin_referer( 'ra_review_record_' . $id );
		if ( ! current_user_can( Post_Types::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage students.', 'reticula-academica' ), 403 );
		}
		$status = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$row    = self::review( $id, $status, $note );
		if ( $row ) {
			$message = Record_Rules::APPROVED === $status ? __( 'Document validated.', 'reticula-academica' ) : __( 'Document rejected. The student was asked to upload it again.', 'reticula-academica' );
			set_transient( 'ra_notice_' . get_current_user_id(), array( 'success', esc_html( $message ) ), 120 );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::admin_url() );
		exit;
	}

	public static function handle_file() {
		$id = isset( $_GET['file'] ) ? absint( $_GET['file'] ) : 0;
		check_admin_referer( 'ra_record_file_' . $id );
		$row = self::get( $id );
		if ( ! $row || ( (int) $row['user_id'] !== get_current_user_id() && ! current_user_can( Post_Types::capability() ) ) ) {
			wp_die( esc_html__( 'Document not available.', 'reticula-academica' ), 404 );
		}
		$path = self::storage_dir() . '/' . $row['file'];
		if ( false !== strpos( $row['file'], '..' ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The file could not be found.', 'reticula-academica' ), 404 );
		}
		$ext    = strtolower( pathinfo( $row['file'], PATHINFO_EXTENSION ) );
		$inline = empty( $_GET['dl'] ) && in_array( $ext, array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ), true );
		$name   = sanitize_file_name( trim( Student_Id::get( (int) $row['user_id'] ) . ' ' . get_the_title( (int) $row['requirement_id'] ) ) ) . '.' . $ext;
		nocache_headers();
		header( 'Content-Type: ' . ( isset( Record_Rules::TYPES[ $ext ] ) ? Record_Rules::TYPES[ $ext ] : 'application/octet-stream' ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function handle_remind() {
		$user_id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		check_admin_referer( 'ra_record_remind_' . $user_id );
		if ( ! current_user_can( Post_Types::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage students.', 'reticula-academica' ), 403 );
		}
		$file = self::file_of( $user_id );
		$sent = ! $file['summary']['complete'] && self::email_reminder( $user_id, $file );
		set_transient( 'ra_notice_' . get_current_user_id(), $sent ? array( 'success', esc_html__( 'Reminder sent.', 'reticula-academica' ) ) : array( 'warning', esc_html__( 'No reminder was sent: the file is complete or the email could not be sent.', 'reticula-academica' ) ), 120 );
		wp_safe_redirect( self::admin_url( array( 'user' => $user_id ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Emails and reminders
	 * ------------------------------------------------------------------ */

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function institution() {
		$name = (string) Settings::get( 'institution' );
		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	public static function mail( $to, $subject, $body ) {
		$from_name = self::institution();
		$filter    = static function () use ( $from_name ) {
			return $from_name;
		};
		add_filter( 'wp_mail_from_name', $filter );
		$sent = wp_mail( $to, wp_specialchars_decode( $subject, ENT_QUOTES ), $body );
		remove_filter( 'wp_mail_from_name', $filter );
		return $sent;
	}

	/**
	 * Fill {nombre}, {matricula}, {institucion}, {documentos} and {enlace}.
	 */
	public static function fill( $text, $user_id, $documents = '' ) {
		$user = get_userdata( $user_id );
		return strtr(
			(string) $text,
			array(
				'{nombre}'      => $user ? ( $user->first_name ? $user->first_name : $user->display_name ) : '',
				'{matricula}'   => Student_Id::get( $user_id ),
				'{institucion}' => self::institution(),
				'{documentos}'  => $documents,
				'{enlace}'      => self::my_file_url(),
			)
		);
	}

	/**
	 * Reminder with the documents that are missing or rejected.
	 */
	public static function email_reminder( $user_id, $file = null ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		$file  = $file ? $file : self::file_of( $user_id );
		$lines = array();
		foreach ( $file['items'] as $item ) {
			if ( in_array( (int) $item['requirement']->ID, $file['summary']['outstanding'], true ) ) {
				$lines[] = '- ' . get_the_title( $item['requirement'] ) . ( Record_Rules::REJECTED === $item['status'] ? ' (' . __( 'rejected, upload it again', 'reticula-academica' ) . ( '' !== (string) $item['file']['note'] ? ': ' . $item['file']['note'] : '' ) . ')' : '' );
			}
		}
		if ( ! $lines ) {
			return false;
		}
		$list = implode( "\n", $lines );
		$sent = self::mail( $user->user_email, self::fill( Settings::get( 'records_subject' ), $user_id, $list ), self::fill( Settings::get( 'records_body' ), $user_id, $list ) );
		if ( $sent ) {
			update_user_meta( $user_id, self::REMINDED, time() );
		}
		return $sent;
	}

	public static function email_review( array $row ) {
		$user = get_userdata( (int) $row['user_id'] );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		$title = get_the_title( (int) $row['requirement_id'] );
		if ( Record_Rules::APPROVED === $row['status'] ) {
			/* translators: %s: document name. */
			$subject = sprintf( __( 'Your document "%s" was validated', 'reticula-academica' ), $title );
			/* translators: %s: document name. */
			$body = sprintf( __( 'Hello {nombre}, your document "%s" was reviewed and validated.', 'reticula-academica' ), $title );
		} else {
			/* translators: %s: document name. */
			$subject = sprintf( __( 'Your document "%s" was rejected', 'reticula-academica' ), $title );
			/* translators: %s: document name. */
			$body = sprintf( __( 'Hello {nombre}, your document "%s" was reviewed and rejected. Please upload it again.', 'reticula-academica' ), $title );
			if ( '' !== (string) $row['note'] ) {
				/* translators: %s: reason. */
				$body .= "\n\n" . sprintf( __( 'Reason: %s', 'reticula-academica' ), $row['note'] );
			}
		}
		$body .= "\n\n" . __( 'See your file: {enlace}', 'reticula-academica' ) . "\n\n{institucion}";
		return self::mail( $user->user_email, $subject, self::fill( $body, (int) $row['user_id'] ) );
	}

	public static function email_staff( array $row ) {
		$to = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'records_notify' ) ) ), 'is_email' );
		if ( ! $to ) {
			return false;
		}
		$user = get_userdata( (int) $row['user_id'] );
		$name = $user ? $user->display_name : '#' . $row['user_id'];
		/* translators: 1: document name, 2: student name. */
		$subject = sprintf( __( 'New document to validate: %1$s of %2$s', 'reticula-academica' ), get_the_title( (int) $row['requirement_id'] ), $name );
		/* translators: 1: student name, 2: student ID, 3: document name. */
		$body  = sprintf( __( '%1$s (%2$s) uploaded "%3$s".', 'reticula-academica' ), $name, Student_Id::get( (int) $row['user_id'] ), get_the_title( (int) $row['requirement_id'] ) );
		$body .= "\n\n" . __( 'Validate it here:', 'reticula-academica' ) . ' ' . self::admin_url( array( 'user' => (int) $row['user_id'] ) );
		return self::mail( $to, $subject, $body );
	}

	/**
	 * Daily: remind students whose required documents are missing or rejected, every N days.
	 *
	 * @return int Reminders sent.
	 */
	public static function send_reminders() {
		global $wpdb;
		$days = (int) Settings::get( 'records_reminder_days' );
		if ( $days <= 0 ) {
			return 0;
		}
		$requirements = self::requirements();
		if ( ! $requirements ) {
			return 0;
		}
		$inscriptions = Installer::table();
		$files        = self::table();
		$students     = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, MIN(created_at) AS since FROM {$inscriptions} WHERE status <> %s GROUP BY user_id", Progress::STATUS_DROPPED ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$programs     = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, program_id FROM {$inscriptions} WHERE status <> %s", Progress::STATUS_DROPPED ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$programs[ (int) $row['user_id'] ][] = (int) $row['program_id'];
		}
		$statuses = array();
		foreach ( (array) $wpdb->get_results( "SELECT user_id, requirement_id, status FROM {$files} ORDER BY id ASC", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$statuses[ (int) $row['user_id'] ][ (int) $row['requirement_id'] ] = $row['status'];
		}
		$settings = array();
		foreach ( $requirements as $req ) {
			$settings[ $req->ID ] = self::requirement_settings( $req->ID );
		}

		$now   = time();
		$sent  = 0;
		$limit = (int) apply_filters( 'ra_records_reminders_per_run', 100 );
		foreach ( (array) $students as $student ) {
			$user_id  = (int) $student['user_id'];
			$required = array();
			foreach ( $settings as $id => $s ) {
				if ( Record_Rules::applies( $s['programs'], isset( $programs[ $user_id ] ) ? $programs[ $user_id ] : array() ) ) {
					$required[ $id ] = $s['required'];
				}
			}
			$summary = Record_Rules::summary( $required, isset( $statuses[ $user_id ] ) ? $statuses[ $user_id ] : array() );
			if ( $summary['complete'] ) {
				continue;
			}
			$anchor = (int) strtotime( $student['since'] . ' UTC' );
			$last   = (int) get_user_meta( $user_id, self::REMINDED, true );
			if ( ! Record_Rules::reminder_due( $anchor, $last, $days, $now ) ) {
				continue;
			}
			if ( self::email_reminder( $user_id ) ) {
				++$sent;
			}
			if ( $sent >= $limit ) {
				break;
			}
		}
		return $sent;
	}

	/* ------------------------------------------------------------------ *
	 * Student view
	 * ------------------------------------------------------------------ */

	public static function shortcode() {
		return self::render_my_file();
	}

	public static function notice_html() {
		$code = isset( $_GET['ra_rec'] ) ? sanitize_key( wp_unslash( $_GET['ra_rec'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$text = $code ? self::message( $code ) : '';
		return $text ? '<p class="ra-rec-notice' . ( 'uploaded' === $code ? ' is-success' : ' is-error' ) . '" role="status">' . esc_html( $text ) . '</p>' : '';
	}

	public static function summary_text( array $summary ) {
		/* translators: 1: validated documents, 2: requested documents. */
		return sprintf( __( '%1$d of %2$d documents validated', 'reticula-academica' ), $summary['approved'], $summary['total'] );
	}

	public static function render_my_file() {
		Frontend::no_cache();
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Log in to see your file.', 'reticula-academica' ) . '</p>';
		}
		$user_id = get_current_user_id();
		$file    = self::file_of( $user_id );
		ob_start();
		echo '<div class="ra-program ra-documents ra-records" id="ra-records">';
		// Coming back with the browser's back button must not show an old copy of the statuses.
		echo '<script>window.addEventListener("pageshow",function(e){if(e.persisted){location.reload();}});</script>';
		echo '<header class="ra-program-header"><div><p class="ra-eyebrow">' . esc_html__( 'My file', 'reticula-academica' ) . '</p>';
		$matricula = Student_Id::get( $user_id );
		if ( $matricula ) {
			/* translators: %s: student ID. */
			echo '<p class="ra-meta">' . esc_html( sprintf( __( 'Student ID: %s', 'reticula-academica' ), $matricula ) ) . '</p>';
		}
		echo '</div>';
		if ( $file['items'] ) {
			$pct = $file['summary']['total'] ? (int) round( 100 * $file['summary']['approved'] / $file['summary']['total'] ) : 0;
			echo '<div class="ra-overall"><strong>' . esc_html( $pct . '%' ) . '</strong><span>' . esc_html( self::summary_text( $file['summary'] ) ) . '</span><div class="ra-bar"><span style="width:' . esc_attr( $pct ) . '%"></span></div></div>';
		}
		echo '</header>';
		echo self::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! empty( $_GET['ra_step'] ) && $file['items'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="ra-next ra-welcome"><div><p class="ra-next-label">' . esc_html__( 'Step 2 of 2', 'reticula-academica' ) . '</p><p class="ra-next-title">' . esc_html__( 'Upload the documents of your file', 'reticula-academica' ) . '</p><p class="ra-lock">' . esc_html__( 'You can do it now or later: you can start your courses without them.', 'reticula-academica' ) . '</p></div><a class="ra-btn ra-btn-secondary" href="' . esc_url( Inscriptions::my_program_url() ) . '">' . esc_html__( 'Go to my program', 'reticula-academica' ) . ' &rarr;</a></div>';
		}

		if ( ! self::user_programs( $user_id ) ) {
			echo '<div class="ra-next ra-next-empty"><p>' . esc_html__( 'Your file is available once you are enrolled in a program.', 'reticula-academica' ) . '</p></div></div>';
			return (string) ob_get_clean();
		}
		if ( ! $file['items'] ) {
			echo '<div class="ra-next ra-next-empty"><p>' . esc_html__( 'The school has not requested any documents.', 'reticula-academica' ) . '</p></div></div>';
			return (string) ob_get_clean();
		}
		if ( $file['summary']['outstanding'] ) {
			/* translators: %d: number of documents. */
			echo '<div class="ra-next ra-rec-alert"><p class="ra-next-title">' . esc_html( sprintf( _n( 'You have %d required document to deliver', 'You have %d required documents to deliver', count( $file['summary']['outstanding'] ), 'reticula-academica' ), count( $file['summary']['outstanding'] ) ) ) . '</p><p class="ra-next-label">' . esc_html__( 'Upload them below. The school reviews each one and you will receive an email with the result.', 'reticula-academica' ) . '</p></div>';
		} elseif ( $file['summary']['pending'] ) {
			echo '<div class="ra-next"><p class="ra-next-title">' . esc_html__( 'Your documents are being validated', 'reticula-academica' ) . '</p><p class="ra-next-label">' . esc_html__( 'You will receive an email when the school reviews them.', 'reticula-academica' ) . '</p></div>';
		}

		echo '<section class="ra-term"><ul class="ra-courses">';
		foreach ( $file['items'] as $item ) {
			self::render_item( $item, $user_id, false );
		}
		echo '</ul></section></div>';
		return (string) ob_get_clean();
	}

	/**
	 * One requested document with its status and upload form (student view and admin file view).
	 */
	private static function render_item( array $item, $user_id, $admin ) {
		$req    = $item['requirement'];
		$s      = $item['settings'];
		$file   = $item['file'];
		$status = $item['status'];
		$accept = '.' . implode( ',.', $s['types'] );
		echo '<li class="ra-course ra-rec ra-rec-' . esc_attr( $status ) . '"><span class="ra-course-icon" aria-hidden="true">' . ( Record_Rules::APPROVED === $status ? '&#10003;' : '&#128196;' ) . '</span><div class="ra-course-main">';
		echo '<span class="ra-course-title">' . esc_html( get_the_title( $req ) ) . '</span> ' . self::status_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! $s['required'] ) {
			echo ' <span class="ra-lock">' . esc_html__( '(optional)', 'reticula-academica' ) . '</span>';
		}
		$help = trim( wp_strip_all_tags( $req->post_content ) );
		if ( '' !== $help ) {
			echo '<p class="ra-lock">' . esc_html( $help ) . '</p>';
		}
		if ( $file ) {
			/* translators: %s: date. */
			echo '<p class="ra-lock">' . esc_html( sprintf( __( 'Uploaded on %s', 'reticula-academica' ), wp_date( 'd/m/Y H:i', strtotime( $file['created_at'] . ' UTC' ) ) ) ) . ' · <a href="' . esc_url( self::file_url( $file['id'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $file['original_name'] ) . '</a></p>';
			if ( Record_Rules::REJECTED === $status && '' !== (string) $file['note'] ) {
				/* translators: %s: reason. */
				echo '<p class="ra-rec-reason">' . esc_html( sprintf( __( 'Reason: %s', 'reticula-academica' ), $file['note'] ) ) . '</p>';
			}
		}
		echo '</div><div class="ra-course-side">';
		if ( $admin && $file ) {
			self::review_form( $file );
		}
		if ( $admin || Record_Rules::can_upload( $status ) ) {
			$label = $admin ? __( 'Upload for the student', 'reticula-academica' ) : ( Record_Rules::REJECTED === $status ? __( 'Upload again', 'reticula-academica' ) : ( $file ? __( 'Replace file', 'reticula-academica' ) : __( 'Upload', 'reticula-academica' ) ) );
			echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ra-rec-upload">';
			wp_nonce_field( 'ra_upload_record_' . $req->ID );
			echo '<input type="hidden" name="action" value="ra_upload_record"><input type="hidden" name="requirement" value="' . esc_attr( $req->ID ) . '">';
			if ( $admin ) {
				echo '<input type="hidden" name="user" value="' . esc_attr( $user_id ) . '">';
			}
			echo '<input type="file" name="ra_file" required accept="' . esc_attr( $accept ) . '" aria-label="' . esc_attr( get_the_title( $req ) ) . '">';
			echo '<button type="submit" class="ra-btn ra-btn-small' . ( $admin ? '' : ' ra-btn-primary' ) . '">' . esc_html( $label ) . '</button>';
			/* translators: 1: file types, 2: maximum size. */
			echo '<span class="ra-lock">' . esc_html( sprintf( __( '%1$s · up to %2$s', 'reticula-academica' ), strtoupper( implode( ', ', array_diff( $s['types'], array( 'jpeg' ) ) ) ), size_format( self::max_bytes( $s ) ) ) ) . '</span>';
			echo '</form>';
		}
		echo '</div></li>';
	}

	private static function review_form( array $file ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ra-rec-review">';
		wp_nonce_field( 'ra_review_record_' . $file['id'] );
		echo '<input type="hidden" name="action" value="ra_review_record"><input type="hidden" name="file" value="' . esc_attr( $file['id'] ) . '">';
		echo '<input type="text" name="note" placeholder="' . esc_attr__( 'Reason (if rejected)', 'reticula-academica' ) . '" aria-label="' . esc_attr__( 'Reason (if rejected)', 'reticula-academica' ) . '">';
		echo '<button type="submit" name="decision" value="' . esc_attr( Record_Rules::APPROVED ) . '" class="button button-primary">' . esc_html__( 'Validate', 'reticula-academica' ) . '</button> ';
		echo '<button type="submit" name="decision" value="' . esc_attr( Record_Rules::REJECTED ) . '" class="button">' . esc_html__( 'Reject', 'reticula-academica' ) . '</button>';
		echo '</form>';
	}

	/* ------------------------------------------------------------------ *
	 * Admin: validation queue and student file
	 * ------------------------------------------------------------------ */

	public static function menu() {
		$count = self::count_pending();
		$title = __( 'Student files', 'reticula-academica' ) . ( $count ? ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>' : '' );
		add_submenu_page( 'edit.php?post_type=' . Curriculum::PROGRAM, __( 'Student files', 'reticula-academica' ), $title, Post_Types::capability(), self::PAGE, array( __CLASS__, 'render_admin_page' ) );
	}

	public static function count_pending() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", Record_Rules::PENDING ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Uploads for the validation queue (latest of each document; replaced uploads are excluded).
	 *
	 * @return array [ rows, total ]
	 */
	public static function query( array $filters, $limit = 30, $offset = 0 ) {
		global $wpdb;
		$table = self::table();
		$where = array( 'f.status <> %s' );
		$args  = array( Record_Rules::REPLACED );
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'f.status = %s';
			$args[]  = $filters['status'];
		}
		if ( ! empty( $filters['requirement'] ) ) {
			$where[] = 'f.requirement_id = %d';
			$args[]  = absint( $filters['requirement'] );
		}
		if ( ! empty( $filters['program'] ) ) {
			$where[] = 'f.user_id IN (SELECT user_id FROM ' . Installer::table() . ' WHERE program_id = %d)';
			$args[]  = absint( $filters['program'] );
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = "(u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR f.user_id IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ra_matricula' AND meta_value LIKE %s))";
			array_push( $args, $like, $like, $like, $like );
		}
		// Only the latest upload of each document counts (an old rejection is history once a new file arrives).
		$where[] = "f.id = (SELECT MAX(f2.id) FROM {$table} f2 WHERE f2.user_id = f.user_id AND f2.requirement_id = f.requirement_id)";
		$sql     = "SELECT SQL_CALC_FOUND_ROWS f.*, u.display_name, u.user_email FROM {$table} f LEFT JOIN {$wpdb->users} u ON u.ID = f.user_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY f.created_at ASC, f.id ASC LIMIT %d OFFSET %d';
		$args[]  = (int) $limit;
		$args[]  = (int) $offset;
		$rows    = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$total   = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' ); // phpcs:ignore WordPress.DB
		return array( (array) $rows, $total );
	}

	public static function render_admin_page() {
		if ( ! current_user_can( Post_Types::capability() ) ) {
			return;
		}
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $user_id ) {
			self::render_admin_file( $user_id );
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : Record_Rules::PENDING;
		$status  = in_array( $status, array( Record_Rules::PENDING, Record_Rules::APPROVED, Record_Rules::REJECTED, 'all' ), true ) ? $status : Record_Rules::PENDING;
		$filters = array(
			'status'      => 'all' === $status ? '' : $status,
			'program'     => isset( $_GET['program'] ) ? absint( $_GET['program'] ) : 0,
			'requirement' => isset( $_GET['requirement'] ) ? absint( $_GET['requirement'] ) : 0,
			'search'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$per_page          = 30;
		list( $rows, $total ) = self::query( $filters, $per_page, ( $paged - 1 ) * $per_page );
		$labels            = self::statuses();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Student files', 'reticula-academica' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::REQUIREMENT ) ); ?>"><?php esc_html_e( 'Requested documents', 'reticula-academica' ); ?></a>
			<hr class="wp-header-end">
			<ul class="subsubsub">
				<?php
				$views = array(
					Record_Rules::PENDING  => $labels[ Record_Rules::PENDING ],
					Record_Rules::REJECTED => $labels[ Record_Rules::REJECTED ],
					Record_Rules::APPROVED => $labels[ Record_Rules::APPROVED ],
					'all'                  => __( 'All', 'reticula-academica' ),
				);
				$links = array();
				foreach ( $views as $key => $label ) {
					$extra   = Record_Rules::PENDING === $key ? ' <span class="count">(' . self::count_pending() . ')</span>' : '';
					$links[] = '<li><a href="' . esc_url( self::admin_url( array( 'status' => $key ) ) ) . '"' . ( $status === $key ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . $extra . '</a>';
				}
				echo implode( ' | </li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</ul>
			<form method="get" class="ra-filters" style="clear:both;padding-top:8px">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( Curriculum::PROGRAM ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<select name="program">
					<option value="0"><?php esc_html_e( 'All programs', 'reticula-academica' ); ?></option>
					<?php foreach ( Curriculum::programs() as $program ) : ?>
						<option value="<?php echo esc_attr( $program->ID ); ?>" <?php selected( $filters['program'], $program->ID ); ?>><?php echo esc_html( get_the_title( $program ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="requirement">
					<option value="0"><?php esc_html_e( 'All documents', 'reticula-academica' ); ?></option>
					<?php foreach ( self::requirements() as $req ) : ?>
						<option value="<?php echo esc_attr( $req->ID ); ?>" <?php selected( $filters['requirement'], $req->ID ); ?>><?php echo esc_html( get_the_title( $req ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search students', 'reticula-academica' ); ?>">
				<?php submit_button( __( 'Filter', 'reticula-academica' ), 'secondary', '', false ); ?>
			</form>
			<?php if ( ! self::requirements() ) : ?>
				<div class="notice notice-info inline"><p>
					<?php esc_html_e( 'Start by creating the documents you will request (birth certificate, high school certificate…).', 'reticula-academica' ); ?>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . self::REQUIREMENT ) ); ?>"><?php esc_html_e( 'Add requested document', 'reticula-academica' ); ?></a>
				</p></div>
			<?php endif; ?>
			<table class="widefat striped ra-rec-table">
				<thead><tr>
					<th><?php esc_html_e( 'Student', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Document', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'File', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Uploaded', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Status', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'reticula-academica' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php echo Record_Rules::PENDING === $status ? esc_html__( 'There are no documents waiting for validation.', 'reticula-academica' ) : esc_html__( 'No documents found.', 'reticula-academica' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( self::admin_url( array( 'user' => $row['user_id'] ) ) ); ?>"><?php echo esc_html( $row['display_name'] ? $row['display_name'] : '#' . $row['user_id'] ); ?></a></strong>
							<br><span class="description"><?php echo esc_html( trim( Student_Id::get( (int) $row['user_id'] ) . ' · ' . $row['user_email'], ' ·' ) ); ?></span>
						</td>
						<td><?php echo esc_html( get_the_title( (int) $row['requirement_id'] ) ); ?></td>
						<td><a href="<?php echo esc_url( self::file_url( $row['id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'reticula-academica' ); ?></a> · <a href="<?php echo esc_url( self::file_url( $row['id'], true ) ); ?>"><?php esc_html_e( 'Download', 'reticula-academica' ); ?></a><br><span class="description"><?php echo esc_html( $row['original_name'] . ' · ' . size_format( (int) $row['size'] ) ); ?></span></td>
						<td><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $row['created_at'] . ' UTC' ) ) ); ?></td>
						<td><?php echo self::status_badge( $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( Record_Rules::REJECTED === $row['status'] && '' !== (string) $row['note'] ) : ?>
								<br><span class="description"><?php echo esc_html( $row['note'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php self::review_form( $row ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / $per_page );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
			}
			?>
		</div>
		<?php
	}

	private static function render_admin_file( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			echo '<div class="wrap"><p>' . esc_html__( 'The student does not exist.', 'reticula-academica' ) . '</p></div>';
			return;
		}
		$file    = self::file_of( $user_id );
		$history = self::history( $user_id );
		$labels  = self::statuses();
		$last    = (int) get_user_meta( $user_id, self::REMINDED, true );
		?>
		<div class="wrap">
			<h1>
				<?php
				/* translators: %s: student name. */
				echo esc_html( sprintf( __( 'File of %s', 'reticula-academica' ), $user->display_name ) );
				?>
			</h1>
			<p><a href="<?php echo esc_url( self::admin_url() ); ?>">&larr; <?php esc_html_e( 'Back to student files', 'reticula-academica' ); ?></a></p>
			<?php echo self::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p>
				<?php echo esc_html( trim( Student_Id::get( $user_id ) . ' · ' . $user->user_email, ' ·' ) ); ?>
				<?php if ( $file['items'] ) : ?>
					· <strong><?php echo esc_html( self::summary_text( $file['summary'] ) ); ?></strong>
				<?php endif; ?>
			</p>
			<?php if ( ! $file['items'] ) : ?>
				<p><?php echo self::user_programs( $user_id ) ? esc_html__( 'The school has not requested any documents.', 'reticula-academica' ) : esc_html__( 'The student is not enrolled in any program.', 'reticula-academica' ); ?></p>
			<?php else : ?>
				<div class="ra-documents ra-records ra-panel" style="max-width:none">
					<ul class="ra-courses">
						<?php
						foreach ( $file['items'] as $item ) {
							self::render_item( $item, $user_id, true );
						}
						?>
					</ul>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
					<?php wp_nonce_field( 'ra_record_remind_' . $user_id ); ?>
					<input type="hidden" name="action" value="ra_record_remind">
					<input type="hidden" name="user" value="<?php echo esc_attr( $user_id ); ?>">
					<?php submit_button( __( 'Send reminder now', 'reticula-academica' ), 'secondary', 'submit', false, $file['summary']['complete'] ? array( 'disabled' => 'disabled' ) : array() ); ?>
					<span class="description">
						<?php
						echo $last
							/* translators: %s: date. */
							? esc_html( sprintf( __( 'Last reminder: %s', 'reticula-academica' ), wp_date( 'd/m/Y H:i', $last ) ) )
							: esc_html__( 'No reminders sent yet.', 'reticula-academica' );
						?>
					</span>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'History', 'reticula-academica' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Document', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'File', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Uploaded', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Status', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Reviewed', 'reticula-academica' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $history ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'The student has not uploaded documents yet.', 'reticula-academica' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $history as $row ) : ?>
					<?php $reviewer = $row['reviewed_by'] ? get_userdata( (int) $row['reviewed_by'] ) : null; ?>
					<tr>
						<td><?php echo esc_html( get_the_title( (int) $row['requirement_id'] ) ); ?></td>
						<td><a href="<?php echo esc_url( self::file_url( $row['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['original_name'] ); ?></a> <span class="description">(<?php echo esc_html( size_format( (int) $row['size'] ) ); ?>)</span></td>
						<td><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $row['created_at'] . ' UTC' ) ) ); ?></td>
						<td><?php echo self::status_badge( $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( '' !== (string) $row['note'] ) : ?>
								<br><span class="description"><?php echo esc_html( $row['note'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $row['reviewed_at'] ? esc_html( wp_date( 'd/m/Y H:i', strtotime( $row['reviewed_at'] . ' UTC' ) ) . ( $reviewer ? ' · ' . $reviewer->display_name : '' ) ) : '&mdash;'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
