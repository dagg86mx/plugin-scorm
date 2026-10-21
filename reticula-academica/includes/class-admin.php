<?php
/**
 * Admin: students per program (assign, import, edit, export) and assets.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Admin {

	const PAGE = 'ra-students';

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ra_assign', array( __CLASS__, 'handle_assign' ) );
		add_action( 'admin_post_ra_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_ra_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_ra_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu() {
		self::$hook = (string) add_submenu_page(
			'edit.php?post_type=' . Curriculum::PROGRAM,
			__( 'Students', 'reticula-academica' ),
			__( 'Students', 'reticula-academica' ),
			Post_Types::capability(),
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => Curriculum::PROGRAM, 'page' => self::PAGE ), $args ), admin_url( 'edit.php' ) );
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( $screen && Curriculum::TERM === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_script( 'ra-admin', RA_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable' ), RA_VERSION, true );
			wp_localize_script( 'ra-admin', 'RAAdmin', array( 'remove' => __( 'Remove', 'reticula-academica' ), 'code' => __( 'Code', 'reticula-academica' ) ) );
			wp_enqueue_style( 'ra-admin', RA_URL . 'assets/css/admin.css', array(), RA_VERSION );
		}
		if ( $screen && Documents::TEMPLATE === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'ra-admin', RA_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable' ), RA_VERSION, true );
			wp_localize_script( 'ra-admin', 'RAAdmin', array( 'remove' => __( 'Remove', 'reticula-academica' ), 'choose' => __( 'Choose image', 'reticula-academica' ) ) );
		}
		if ( $hook === self::$hook || false !== strpos( (string) $hook, Documents::PAGE ) || false !== strpos( (string) $hook, Records::PAGE ) ) {
			wp_enqueue_style( 'ra-admin', RA_URL . 'assets/css/admin.css', array(), RA_VERSION );
			wp_enqueue_style( 'reticula-academica', RA_URL . 'assets/css/reticula.css', array(), RA_VERSION );
		}
	}

	private static function notice( $type, $message ) {
		set_transient( 'ra_notice_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	public static function notices() {
		$notice = get_transient( 'ra_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'ra_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), wp_kses_post( $notice[1] ) );
		}
	}

	private static function check( $action ) {
		check_admin_referer( $action );
		if ( ! current_user_can( Post_Types::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage students.', 'reticula-academica' ), 403 );
		}
	}

	/**
	 * Find a user by ID, email or login.
	 */
	public static function find_user( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( ctype_digit( $value ) ) {
			$user = get_userdata( (int) $value );
		} elseif ( is_email( $value ) ) {
			$user = get_user_by( 'email', $value );
		} else {
			$user = get_user_by( 'login', $value );
		}
		return $user ? $user : null;
	}

	/**
	 * Find a program by ID, slug or title.
	 */
	public static function find_program( $value ) {
		$value = trim( (string) $value );
		foreach ( Curriculum::programs( true ) as $program ) {
			if ( (string) $program->ID === $value || $program->post_name === sanitize_title( $value ) || 0 === strcasecmp( $program->post_title, $value ) ) {
				return $program;
			}
		}
		return null;
	}

	/**
	 * Find a term of a program by ID, number or title.
	 */
	public static function find_term( $program_id, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		foreach ( Curriculum::terms( $program_id, true ) as $term ) {
			if ( (string) $term['id'] === $value || (string) $term['number'] === $value || 0 === strcasecmp( $term['title'], $value ) ) {
				return (int) $term['id'];
			}
		}
		return 0;
	}

	/* ------------------------------------------------------------------ *
	 * Handlers
	 * ------------------------------------------------------------------ */

	public static function handle_assign() {
		self::check( 'ra_assign' );
		$user       = self::find_user( isset( $_POST['ra_user'] ) ? sanitize_text_field( wp_unslash( $_POST['ra_user'] ) ) : '' );
		$program_id = isset( $_POST['ra_program'] ) ? absint( $_POST['ra_program'] ) : 0;
		$term       = isset( $_POST['ra_term'] ) ? absint( $_POST['ra_term'] ) : 0;
		if ( ! $user ) {
			self::notice( 'error', __( 'Student not found. Use the user ID, email or username.', 'reticula-academica' ) );
		} else {
			$result = Inscriptions::create(
				$user->ID,
				$program_id,
				array(
					'start_term_id' => $term,
					'status'        => isset( $_POST['ra_status'] ) ? sanitize_key( $_POST['ra_status'] ) : '',
					'notes'         => isset( $_POST['ra_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ra_notes'] ) ) : '',
					'source'        => 'admin',
				)
			);
			if ( is_wp_error( $result ) ) {
				self::notice( 'error', $result->get_error_message() );
			} else {
				/* translators: 1: student, 2: program. */
				self::notice( 'success', esc_html( sprintf( __( '%1$s was assigned to %2$s.', 'reticula-academica' ), $user->display_name, get_the_title( $program_id ) ) ) );
			}
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function handle_update() {
		self::check( 'ra_update' );
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$row = Inscriptions::get( $id );
		if ( ! $row ) {
			wp_safe_redirect( self::url() );
			exit;
		}
		if ( ! empty( $_POST['ra_delete'] ) ) {
			Inscriptions::delete( $id );
			self::notice( 'success', esc_html__( 'Inscription deleted.', 'reticula-academica' ) );
			wp_safe_redirect( self::url() );
			exit;
		}
		$terms = array_map( 'intval', wp_list_pluck( Curriculum::terms( $row['program_id'], true ), 'id' ) );
		$data  = array(
			'status' => isset( $_POST['ra_status'] ) ? sanitize_key( $_POST['ra_status'] ) : $row['status'],
			'notes'  => isset( $_POST['ra_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ra_notes'] ) ) : $row['notes'],
		);
		foreach ( array( 'start_term_id' => 'ra_start_term', 'current_term_id' => 'ra_current_term' ) as $column => $field ) {
			$value = isset( $_POST[ $field ] ) ? absint( $_POST[ $field ] ) : 0;
			if ( in_array( $value, $terms, true ) ) {
				$data[ $column ] = $value;
			}
		}
		Inscriptions::update( $id, $data );
		self::notice( 'success', esc_html__( 'Inscription updated.', 'reticula-academica' ) );
		wp_safe_redirect( self::url( array( 'action' => 'edit', 'id' => $id ) ) );
		exit;
	}

	/**
	 * CSV import: student (ID, email or username), program (ID, slug or title), term (number, ID or title), status.
	 */
	public static function handle_import() {
		self::check( 'ra_import' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file = isset( $_FILES['ra_csv'] ) ? $_FILES['ra_csv'] : null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::notice( 'error', esc_html__( 'Choose a CSV file to import.', 'reticula-academica' ) );
			wp_safe_redirect( self::url() );
			exit;
		}
		$report = self::import_csv( (string) file_get_contents( $file['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$msg    = sprintf(
			/* translators: 1: imported rows, 2: rows with errors. */
			esc_html__( 'Import finished: %1$d students assigned, %2$d rows with errors.', 'reticula-academica' ),
			$report['ok'],
			count( $report['errors'] )
		);
		if ( $report['errors'] ) {
			$msg .= '<br>' . implode( '<br>', array_map( 'esc_html', array_slice( $report['errors'], 0, 20 ) ) );
		}
		self::notice( $report['errors'] ? 'warning' : 'success', $msg );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * @return array { ok: int, errors: string[] }
	 */
	public static function import_csv( $csv ) {
		$csv = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		if ( ! preg_match( '//u', $csv ) && function_exists( 'mb_convert_encoding' ) ) {
			$csv = mb_convert_encoding( $csv, 'UTF-8', 'Windows-1252' );
		}
		$lines     = preg_split( '/\r\n|\r|\n/', trim( $csv ) );
		$delimiter = ( isset( $lines[0] ) && substr_count( $lines[0], ';' ) > substr_count( $lines[0], ',' ) ) ? ';' : ',';
		$header    = null;
		$ok        = 0;
		$errors    = array();
		$aliases   = array(
			'user'    => array( 'student', 'alumno', 'email', 'correo', 'user', 'usuario' ),
			'program' => array( 'program', 'carrera', 'programa' ),
			'term'    => array( 'term', 'tetramestre', 'periodo', 'cuatrimestre', 'semestre' ),
			'status'  => array( 'status', 'estado' ),
		);
		foreach ( $lines as $number => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = array_map( 'trim', str_getcsv( $line, $delimiter, '"', '\\' ) );
			if ( null === $header ) {
				$header = array();
				foreach ( $cells as $index => $name ) {
					$name = strtolower( remove_accents( $name ) );
					foreach ( $aliases as $key => $names ) {
						if ( in_array( $name, $names, true ) ) {
							$header[ $key ] = $index;
						}
					}
				}
				if ( ! isset( $header['user'], $header['program'] ) ) {
					// No header row: assume student, program, term, status.
					$header = array( 'user' => 0, 'program' => 1, 'term' => 2, 'status' => 3 );
				} else {
					continue;
				}
			}
			$get     = static function ( $key ) use ( $header, $cells ) {
				return ( isset( $header[ $key ] ) && isset( $cells[ $header[ $key ] ] ) ) ? $cells[ $header[ $key ] ] : '';
			};
			$line_no = $number + 1;
			$user    = self::find_user( $get( 'user' ) );
			$program = self::find_program( $get( 'program' ) );
			if ( ! $user ) {
				/* translators: 1: line number, 2: value. */
				$errors[] = sprintf( __( 'Line %1$d: student "%2$s" not found.', 'reticula-academica' ), $line_no, $get( 'user' ) );
				continue;
			}
			if ( ! $program ) {
				/* translators: 1: line number, 2: value. */
				$errors[] = sprintf( __( 'Line %1$d: program "%2$s" not found.', 'reticula-academica' ), $line_no, $get( 'program' ) );
				continue;
			}
			$term = self::find_term( $program->ID, $get( 'term' ) );
			if ( '' !== $get( 'term' ) && ! $term ) {
				/* translators: 1: line number, 2: value. */
				$errors[] = sprintf( __( 'Line %1$d: term "%2$s" not found in the program.', 'reticula-academica' ), $line_no, $get( 'term' ) );
				continue;
			}
			$status_map = array(
				'activo'    => Progress::STATUS_ACTIVE,
				'activa'    => Progress::STATUS_ACTIVE,
				'pendiente' => Progress::STATUS_PENDING,
				'pausado'   => Progress::STATUS_PAUSED,
				'baja'      => Progress::STATUS_DROPPED,
				'egresado'  => Progress::STATUS_GRADUATED,
			);
			$status     = strtolower( remove_accents( $get( 'status' ) ) );
			$status     = isset( $status_map[ $status ] ) ? $status_map[ $status ] : $status;
			$result     = Inscriptions::create(
				$user->ID,
				$program->ID,
				array(
					'start_term_id' => $term,
					'status'        => $status,
					'source'        => 'import',
				)
			);
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( 'Line %d: %s', $line_no, $result->get_error_message() );
			} else {
				++$ok;
			}
		}
		return array(
			'ok'     => $ok,
			'errors' => $errors,
		);
	}

	public static function handle_export() {
		self::check( 'ra_export' );
		list( $rows ) = Inscriptions::query( self::filters() );
		$statuses     = Inscriptions::statuses();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=alumnos-reticula-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'user_id', 'matricula', 'nombre', 'email', 'carrera', 'tetramestre_inicio', 'tetramestre_actual', 'estado', 'avance_pct', 'siguiente_curso', 'origen', 'alta_utc', 'egreso_utc' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array_map(
					static function ( $value ) {
						$value = (string) $value;
						return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) ? "'" . $value : $value;
					},
					array(
						$row['user_id'],
						Student_Id::get( $row['user_id'] ),
						$row['display_name'],
						$row['user_email'],
						get_the_title( $row['program_id'] ),
						$row['start_term_id'] ? get_the_title( $row['start_term_id'] ) : '',
						$row['current_term_id'] ? get_the_title( $row['current_term_id'] ) : '',
						isset( $statuses[ $row['status'] ] ) ? $statuses[ $row['status'] ] : $row['status'],
						$row['progress'],
						$row['next_course_id'] ? get_the_title( $row['next_course_id'] ) : '',
						$row['source'],
						$row['created_at'],
						$row['completed_at'],
					)
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		return array(
			'program_id' => isset( $_REQUEST['program_id'] ) ? absint( $_REQUEST['program_id'] ) : 0,
			'term_id'    => isset( $_REQUEST['term_id'] ) ? absint( $_REQUEST['term_id'] ) : 0,
			'status'     => isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : '',
			'search'     => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
		);
		// phpcs:enable
	}

	/* ------------------------------------------------------------------ *
	 * Screens
	 * ------------------------------------------------------------------ */

	private static function term_options( $selected = 0, $program_id = 0, $placeholder = '' ) {
		if ( $placeholder ) {
			printf( '<option value="0">%s</option>', esc_html( $placeholder ) );
		}
		foreach ( Curriculum::programs( true ) as $program ) {
			if ( $program_id && (int) $program->ID !== (int) $program_id ) {
				continue;
			}
			printf( '<optgroup label="%s" data-program="%d">', esc_attr( get_the_title( $program ) ), (int) $program->ID );
			foreach ( Curriculum::terms( $program->ID, true ) as $term ) {
				printf( '<option value="%1$d" data-program="%2$d" %3$s>%4$s</option>', (int) $term['id'], (int) $program->ID, selected( (int) $selected, (int) $term['id'], false ), esc_html( $term['number'] . '. ' . $term['title'] ) );
			}
			echo '</optgroup>';
		}
	}

	public static function render_page() {
		if ( ! current_user_can( Post_Types::capability() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'], $_GET['id'] ) && 'edit' === $_GET['action'] ) {
			self::render_edit( absint( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$filters  = self::filters();
		$table    = new Students_Table();
		$table->prepare_items();
		$statuses = Inscriptions::statuses();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Students', 'reticula-academica' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'ra_export' ), array_filter( $filters ) ), admin_url( 'admin-post.php' ) ), 'ra_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'reticula-academica' ); ?></a>
			<hr class="wp-header-end">

			<div class="ra-admin-panels">
				<form class="ra-panel" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<h2><?php esc_html_e( 'Assign a student', 'reticula-academica' ); ?></h2>
					<?php wp_nonce_field( 'ra_assign' ); ?>
					<input type="hidden" name="action" value="ra_assign">
					<p><label><?php esc_html_e( 'Student (email, username or ID)', 'reticula-academica' ); ?><br><input type="text" name="ra_user" class="regular-text" required></label></p>
					<p>
						<label><?php esc_html_e( 'Program', 'reticula-academica' ); ?><br>
							<select name="ra_program" id="ra-assign-program" required>
								<option value=""><?php esc_html_e( '— Select —', 'reticula-academica' ); ?></option>
								<?php foreach ( Curriculum::programs( true ) as $program ) : ?>
									<option value="<?php echo esc_attr( $program->ID ); ?>"><?php echo esc_html( get_the_title( $program ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<p>
						<label><?php esc_html_e( 'Starts in term', 'reticula-academica' ); ?><br>
							<select name="ra_term" id="ra-assign-term"><?php self::term_options( 0, 0, __( 'First term', 'reticula-academica' ) ); ?></select>
						</label>
						<br><span class="description"><?php esc_html_e( 'Earlier terms are considered credited (e.g. transfer students).', 'reticula-academica' ); ?></span>
					</p>
					<p>
						<label><?php esc_html_e( 'Status', 'reticula-academica' ); ?><br>
							<select name="ra_status">
								<option value=""><?php esc_html_e( 'Automatic (pending until the inscription fee is paid)', 'reticula-academica' ); ?></option>
								<option value="active"><?php esc_html_e( 'Active (no inscription fee required)', 'reticula-academica' ); ?></option>
							</select>
						</label>
					</p>
					<p><label><?php esc_html_e( 'Notes', 'reticula-academica' ); ?><br><textarea name="ra_notes" rows="2" class="large-text"></textarea></label></p>
					<?php submit_button( __( 'Assign', 'reticula-academica' ), 'primary', 'submit', false ); ?>
				</form>

				<form class="ra-panel" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<h2><?php esc_html_e( 'Import from CSV', 'reticula-academica' ); ?></h2>
					<?php wp_nonce_field( 'ra_import' ); ?>
					<input type="hidden" name="action" value="ra_import">
					<p><?php esc_html_e( 'Columns: alumno (email, username or ID), carrera (ID, slug or title), tetramestre (number, optional), estado (optional: activo, pendiente, pausado, baja).', 'reticula-academica' ); ?></p>
					<pre class="ra-code">alumno,carrera,tetramestre,estado
ana@example.com,Ingeniería en Software,1,
luis@example.com,Ingeniería en Software,3,activo</pre>
					<p><input type="file" name="ra_csv" accept=".csv,text/csv" required></p>
					<?php submit_button( __( 'Import', 'reticula-academica' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<form method="get">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( Curriculum::PROGRAM ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<div class="alignleft actions">
					<select name="program_id">
						<option value="0"><?php esc_html_e( 'All programs', 'reticula-academica' ); ?></option>
						<?php foreach ( Curriculum::programs( true ) as $program ) : ?>
							<option value="<?php echo esc_attr( $program->ID ); ?>" <?php selected( $filters['program_id'], $program->ID ); ?>><?php echo esc_html( get_the_title( $program ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="term_id"><?php self::term_options( $filters['term_id'], 0, __( 'All terms', 'reticula-academica' ) ); ?></select>
					<select name="status">
						<option value=""><?php esc_html_e( 'All statuses', 'reticula-academica' ); ?></option>
						<?php foreach ( $statuses as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Filter', 'reticula-academica' ), '', 'filter_action', false ); ?>
				</div>
				<?php
				$table->search_box( __( 'Search students', 'reticula-academica' ), 'ra-search' );
				$table->display();
				?>
			</form>
		</div>
		<script>
		( function () {
			var program = document.getElementById( 'ra-assign-program' );
			var term = document.getElementById( 'ra-assign-term' );
			if ( ! program || ! term ) { return; }
			program.addEventListener( 'change', function () {
				Array.prototype.forEach.call( term.querySelectorAll( 'optgroup' ), function ( group ) {
					group.hidden = program.value !== '' && group.getAttribute( 'data-program' ) !== program.value;
				} );
				term.value = '0';
			} );
		} )();
		</script>
		<?php
	}

	private static function render_edit( $id ) {
		$row = Inscriptions::get( $id );
		if ( ! $row ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Inscription not found.', 'reticula-academica' ) . '</p></div>';
			return;
		}
		$row  = Inscriptions::sync( $row );
		$user = get_userdata( $row['user_id'] );
		?>
		<div class="wrap">
			<h1>
				<?php
				/* translators: 1: student, 2: program. */
				echo esc_html( sprintf( __( '%1$s in %2$s', 'reticula-academica' ), $user ? $user->display_name : '#' . $row['user_id'], get_the_title( $row['program_id'] ) ) );
				?>
			</h1>
			<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'Back to students', 'reticula-academica' ); ?></a></p>
			<div class="ra-admin-panels">
				<form class="ra-panel" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ra_update' ); ?>
					<input type="hidden" name="action" value="ra_update">
					<input type="hidden" name="id" value="<?php echo esc_attr( $row['id'] ); ?>">
					<p><label><?php esc_html_e( 'Status', 'reticula-academica' ); ?><br>
						<select name="ra_status">
							<?php foreach ( Inscriptions::statuses() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></label></p>
					<p><label><?php esc_html_e( 'Starts in term', 'reticula-academica' ); ?><br><select name="ra_start_term"><?php self::term_options( $row['start_term_id'], $row['program_id'] ); ?></select></label></p>
					<p><label><?php esc_html_e( 'Current term', 'reticula-academica' ); ?><br><select name="ra_current_term"><?php self::term_options( $row['current_term_id'], $row['program_id'] ); ?></select></label>
					<br><span class="description"><?php esc_html_e( 'The current term advances automatically when every course of the term is completed.', 'reticula-academica' ); ?></span></p>
					<p><label><?php esc_html_e( 'Notes', 'reticula-academica' ); ?><br><textarea name="ra_notes" rows="3" class="large-text"><?php echo esc_textarea( (string) $row['notes'] ); ?></textarea></label></p>
					<p>
						<?php submit_button( __( 'Save', 'reticula-academica' ), 'primary', 'submit', false ); ?>
						<button type="submit" name="ra_delete" value="1" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this inscription? Course enrollments in Tutor are not affected.', 'reticula-academica' ) ); ?>');"><?php esc_html_e( 'Delete inscription', 'reticula-academica' ); ?></button>
					</p>
				</form>
				<div class="ra-panel">
					<h2><?php esc_html_e( 'Documents', 'reticula-academica' ); ?></h2>
					<p>
						<?php
						$matricula = Student_Id::get( $row['user_id'] );
						/* translators: %s: student ID. */
						echo $matricula ? esc_html( sprintf( __( 'Student ID: %s', 'reticula-academica' ), $matricula ) ) : esc_html__( 'No student ID yet.', 'reticula-academica' );
						?>
					</p>
					<?php Documents::issue_form( (int) $row['user_id'] ); ?>
				</div>
				<div class="ra-panel">
					<h2><?php esc_html_e( 'Student file', 'reticula-academica' ); ?></h2>
					<?php $record = Records::file_of( (int) $row['user_id'] ); ?>
					<p><?php echo $record['items'] ? esc_html( Records::summary_text( $record['summary'] ) ) : esc_html__( 'The school has not requested any documents.', 'reticula-academica' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( Records::admin_url( array( 'user' => (int) $row['user_id'] ) ) ); ?>"><?php esc_html_e( 'Open file', 'reticula-academica' ); ?></a></p>
				</div>
				<div class="ra-panel ra-panel-wide">
					<h2><?php esc_html_e( 'Grades and extraordinary exams', 'reticula-academica' ); ?></h2>
					<?php Grades::ext_form( $row ); ?>
				</div>
				<div class="ra-panel ra-panel-wide">
					<h2><?php esc_html_e( 'Document history', 'reticula-academica' ); ?></h2>
					<?php
					list( $docs ) = Documents::query( array( 'user_id' => (int) $row['user_id'] ), 100 );
					Documents::history_table( $docs );
					?>
				</div>
				<div class="ra-panel ra-panel-wide">
					<h2><?php esc_html_e( 'What the student sees', 'reticula-academica' ); ?></h2>
					<?php echo Frontend::render_progress( $row ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			</div>
		</div>
		<?php
	}
}

class Students_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'ra_inscription',
				'plural'   => 'ra_inscriptions',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'student'  => __( 'Student', 'reticula-academica' ),
			'id'       => __( 'Student ID', 'reticula-academica' ),
			'program'  => __( 'Program', 'reticula-academica' ),
			'term'     => __( 'Current term', 'reticula-academica' ),
			'progress' => __( 'Progress', 'reticula-academica' ),
			'next'     => __( 'Next course', 'reticula-academica' ),
			'status'   => __( 'Status', 'reticula-academica' ),
			'record'   => __( 'Student file', 'reticula-academica' ),
			'source'   => __( 'Origin', 'reticula-academica' ),
		);
	}

	public function prepare_items() {
		$per_page              = 30;
		list( $rows, $total )  = Inscriptions::query( Admin::filters(), $per_page, ( $this->get_pagenum() - 1 ) * $per_page );
		$this->items           = $rows;
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	public function no_items() {
		esc_html_e( 'No students assigned yet.', 'reticula-academica' );
	}

	protected function column_student( $item ) {
		$edit = Admin::url(
			array(
				'action' => 'edit',
				'id'     => $item['id'],
			)
		);
		return '<strong><a href="' . esc_url( $edit ) . '">' . esc_html( $item['display_name'] ? $item['display_name'] : '#' . $item['user_id'] ) . '</a></strong><br><span class="description">' . esc_html( $item['user_email'] ) . '</span>'
			. $this->row_actions( array( 'edit' => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit / view progress', 'reticula-academica' ) . '</a>' ) );
	}

	protected function column_id( $item ) {
		$id = Student_Id::get( $item['user_id'] );
		return $id ? '<code>' . esc_html( $id ) . '</code>' : '&mdash;';
	}

	protected function column_program( $item ) {
		return esc_html( get_the_title( $item['program_id'] ) );
	}

	protected function column_term( $item ) {
		return $item['current_term_id'] ? esc_html( get_the_title( $item['current_term_id'] ) ) : '&mdash;';
	}

	protected function column_progress( $item ) {
		return '<div class="ra-bar ra-bar-small" style="max-width:120px"><span style="width:' . (int) $item['progress'] . '%"></span></div> ' . (int) $item['progress'] . '%';
	}

	protected function column_next( $item ) {
		return $item['next_course_id'] ? '<a href="' . esc_url( get_permalink( $item['next_course_id'] ) ) . '">' . esc_html( get_the_title( $item['next_course_id'] ) ) . '</a>' : '&mdash;';
	}

	protected function column_status( $item ) {
		$statuses = Inscriptions::statuses();
		return '<span class="ra-badge ra-badge-' . esc_attr( $item['status'] ) . '">' . esc_html( isset( $statuses[ $item['status'] ] ) ? $statuses[ $item['status'] ] : $item['status'] ) . '</span>';
	}

	protected function column_record( $item ) {
		$file = Records::file_of( (int) $item['user_id'] );
		if ( ! $file['items'] ) {
			return '&mdash;';
		}
		$s     = $file['summary'];
		$badge = $s['outstanding'] ? 'rejected' : ( $s['pending'] ? 'pending' : 'approved' );
		return '<a href="' . esc_url( Records::admin_url( array( 'user' => $item['user_id'] ) ) ) . '"><span class="ra-badge ra-badge-rec-' . esc_attr( $badge ) . '">' . esc_html( $s['approved'] . '/' . $s['total'] ) . '</span></a>'
			/* translators: %d: number of documents. */
			. ( $s['pending'] ? '<br><span class="description">' . esc_html( sprintf( _n( '%d to validate', '%d to validate', $s['pending'], 'reticula-academica' ), $s['pending'] ) ) . '</span>' : '' );
	}

	protected function column_source( $item ) {
		$labels = array(
			'self'   => __( 'Self-service', 'reticula-academica' ),
			'admin'  => __( 'Admin', 'reticula-academica' ),
			'import' => __( 'CSV import', 'reticula-academica' ),
		);
		return esc_html( isset( $labels[ $item['source'] ] ) ? $labels[ $item['source'] ] : $item['source'] );
	}
}
