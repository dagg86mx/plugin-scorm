<?php
/**
 * Official documents (boletas and constancias): templates, PDF generation, history,
 * verification and sale of paid documents through Tutor.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Documents {

	const TEMPLATE    = 'ra_template';
	const PRODUCT_META = '_ra_doc_template';
	const PAGE        = 'ra-documents';
	const FOLDER      = 'ra-documentos';

	const STATUS_AVAILABLE = 'available'; // Paid, not generated yet.
	const STATUS_ISSUED    = 'issued';
	const STATUS_REVOKED   = 'revoked';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'maybe_create_defaults' ), 20 );
		add_action( 'add_meta_boxes_' . self::TEMPLATE, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::TEMPLATE, array( __CLASS__, 'save_template' ), 10, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_template' ) );
		add_filter( 'manage_' . self::TEMPLATE . '_posts_columns', array( __CLASS__, 'template_columns' ) );
		add_action( 'manage_' . self::TEMPLATE . '_posts_custom_column', array( __CLASS__, 'template_column' ), 10, 2 );

		add_action( 'tutor_after_enrolled', array( __CLASS__, 'on_enrolled' ), 30, 3 );
		add_filter( 'tutor_can_purchase_course', array( __CLASS__, 'purchase_filter' ), 25, 2 );
		add_filter( 'tutor_allow_course_enrollment', array( __CLASS__, 'purchase_filter' ), 25, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_products' ) );
		add_action( 'tutor_course/single/before/inner-wrap', array( __CLASS__, 'product_notice' ), 4 );

		add_action( 'admin_post_ra_generate_document', array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_ra_download_document', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_ra_admin_issue', array( __CLASS__, 'handle_admin_issue' ) );
		add_action( 'admin_post_ra_revoke_document', array( __CLASS__, 'handle_revoke' ) );
		add_action( 'admin_post_ra_preview_template', array( __CLASS__, 'handle_preview' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_verify' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 25 );
		add_shortcode( 'reticula_documentos', array( __CLASS__, 'shortcode' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ra_documents';
	}

	public static function types() {
		return array(
			'constancia' => __( 'Certificate of studies (constancia)', 'reticula-academica' ),
			'boleta'     => __( 'Report card (boleta)', 'reticula-academica' ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Templates
	 * ------------------------------------------------------------------ */

	public static function register() {
		$cap = Post_Types::capability();
		register_post_type(
			self::TEMPLATE,
			array(
				'labels'       => array(
					'name'          => __( 'Document templates', 'reticula-academica' ),
					'singular_name' => __( 'Document template', 'reticula-academica' ),
					'all_items'     => __( 'Document templates', 'reticula-academica' ),
					'add_new'       => __( 'Add template', 'reticula-academica' ),
					'add_new_item'  => __( 'Add document template', 'reticula-academica' ),
					'edit_item'     => __( 'Edit document template', 'reticula-academica' ),
					'not_found'     => __( 'No templates found.', 'reticula-academica' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . Curriculum::PROGRAM,
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor' ),
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

	public static function template_settings( $template_id ) {
		$type   = (string) get_post_meta( $template_id, '_ra_type', true );
		$paper  = (string) get_post_meta( $template_id, '_ra_paper', true );
		$orient = (string) get_post_meta( $template_id, '_ra_orientation', true );
		$progs  = get_post_meta( $template_id, '_ra_programs', true );
		return array(
			'type'        => isset( self::types()[ $type ] ) ? $type : 'constancia',
			'price'       => (float) get_post_meta( $template_id, '_ra_price', true ),
			'prefix'      => (string) get_post_meta( $template_id, '_ra_prefix', true ),
			'paper'       => in_array( $paper, array( 'letter', 'a4', 'legal' ), true ) ? $paper : 'letter',
			'orientation' => 'landscape' === $orient ? 'landscape' : 'portrait',
			'background'  => absint( get_post_meta( $template_id, '_ra_background', true ) ),
			'programs'    => is_array( $progs ) ? array_map( 'absint', $progs ) : array(),
			'product'     => absint( get_post_meta( $template_id, '_ra_product_course', true ) ),
			'margin'      => (string) get_post_meta( $template_id, '_ra_margin', true ),
		);
	}

	/**
	 * Published templates available for a program.
	 *
	 * @return \WP_Post[]
	 */
	public static function templates_for( $program_id ) {
		$out = array();
		foreach ( get_posts( array( 'post_type' => self::TEMPLATE, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ) ) ) as $template ) {
			$settings = self::template_settings( $template->ID );
			if ( ! $settings['programs'] || in_array( (int) $program_id, $settings['programs'], true ) ) {
				$out[] = $template;
			}
		}
		return $out;
	}

	public static function placeholders() {
		return array(
			'institucion'                  => __( 'Institution name', 'reticula-academica' ),
			'logo'                         => __( 'Institution logo (image)', 'reticula-academica' ),
			'alumno'                       => __( 'Student full name', 'reticula-academica' ),
			'nombre'                       => __( 'First name', 'reticula-academica' ),
			'apellidos'                    => __( 'Last name', 'reticula-academica' ),
			'matricula'                    => __( 'Student ID', 'reticula-academica' ),
			'correo'                       => __( 'Email', 'reticula-academica' ),
			'carrera'                      => __( 'Program name', 'reticula-academica' ),
			'clave_carrera'                => __( 'Program code', 'reticula-academica' ),
			'nivel'                        => __( 'Level (e.g. Licenciatura)', 'reticula-academica' ),
			'modalidad'                    => __( 'Modality', 'reticula-academica' ),
			'rvoe'                         => __( 'RVOE number', 'reticula-academica' ),
			'rvoe_fecha'                   => __( 'RVOE date', 'reticula-academica' ),
			'tetramestre'                  => __( 'Term name (current or selected)', 'reticula-academica' ),
			'tetramestre_numero'           => __( 'Term number', 'reticula-academica' ),
			'tetramestre_ordinal'          => __( 'Term number in words (primer, segundo…)', 'reticula-academica' ),
			'periodo'                      => __( 'School period (from Settings)', 'reticula-academica' ),
			'estatus'                      => __( 'Inscription status', 'reticula-academica' ),
			'avance'                       => __( 'Program progress %', 'reticula-academica' ),
			'promedio_general'             => __( 'General average', 'reticula-academica' ),
			'tabla_calificaciones'         => __( 'Grades table with every evaluation (boleta)', 'reticula-academica' ),
			'tabla_calificaciones_resumen' => __( 'Grades table, one row per course', 'reticula-academica' ),
			'fecha_emision'                => __( 'Issue date, generated automatically (19 de octubre de 2026)', 'reticula-academica' ),
			'fecha'                        => __( 'Issue date (19/10/2026)', 'reticula-academica' ),
			'lugar_fecha'                  => __( 'City and issue date (Ciudad de México, a 19 de octubre de 2026)', 'reticula-academica' ),
			'firmas'                       => __( 'Both signatures side by side', 'reticula-academica' ),
			'firma_1'                      => __( 'Signature 1 block (image, name, position)', 'reticula-academica' ),
			'firma_2'                      => __( 'Signature 2 block (image, name, position)', 'reticula-academica' ),
			'firma_1_nombre'               => __( 'Signature 1 name', 'reticula-academica' ),
			'firma_1_cargo'                => __( 'Signature 1 position', 'reticula-academica' ),
			'firma_2_nombre'               => __( 'Signature 2 name', 'reticula-academica' ),
			'firma_2_cargo'                => __( 'Signature 2 position', 'reticula-academica' ),
			'folio'                        => __( 'Document number', 'reticula-academica' ),
			'verificacion_url'             => __( 'Public verification link', 'reticula-academica' ),
		);
	}

	public static function meta_boxes() {
		add_meta_box( 'ra-template-settings', __( 'Document settings', 'reticula-academica' ), array( __CLASS__, 'render_settings_box' ), self::TEMPLATE, 'side', 'high' );
		add_meta_box( 'ra-template-fields', __( 'Available fields', 'reticula-academica' ), array( __CLASS__, 'render_fields_box' ), self::TEMPLATE, 'normal', 'default' );
	}

	public static function render_settings_box( $post ) {
		wp_nonce_field( 'ra_save_template', 'ra_template_nonce' );
		$s = self::template_settings( $post->ID );
		?>
		<p><label><strong><?php esc_html_e( 'Type', 'reticula-academica' ); ?></strong><br>
			<select name="ra_type" style="width:100%">
				<?php foreach ( self::types() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select></label></p>
		<p><label><strong><?php esc_html_e( 'Price', 'reticula-academica' ); ?></strong><br>
			<input type="number" name="ra_price" min="0" step="0.01" value="<?php echo esc_attr( $s['price'] ); ?>" class="small-text"></label>
			<br><span class="description"><?php esc_html_e( '0 = free. With a price, a sale product is created automatically and each purchase allows one document.', 'reticula-academica' ); ?></span></p>
		<?php if ( $s['product'] && get_post( $s['product'] ) ) : ?>
			<p><?php esc_html_e( 'Sale product:', 'reticula-academica' ); ?> <a href="<?php echo esc_url( get_permalink( $s['product'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $s['product'] ) ); ?></a> (<?php echo esc_html( get_post_status( $s['product'] ) ); ?>)</p>
		<?php endif; ?>
		<p><label><strong><?php esc_html_e( 'Folio prefix', 'reticula-academica' ); ?></strong><br>
			<input type="text" name="ra_prefix" value="<?php echo esc_attr( $s['prefix'] ); ?>" class="small-text" placeholder="CON"></label></p>
		<p><label><strong><?php esc_html_e( 'Paper', 'reticula-academica' ); ?></strong><br>
			<select name="ra_paper">
				<option value="letter" <?php selected( $s['paper'], 'letter' ); ?>><?php esc_html_e( 'Letter', 'reticula-academica' ); ?></option>
				<option value="a4" <?php selected( $s['paper'], 'a4' ); ?>>A4</option>
				<option value="legal" <?php selected( $s['paper'], 'legal' ); ?>><?php esc_html_e( 'Legal', 'reticula-academica' ); ?></option>
			</select>
			<select name="ra_orientation">
				<option value="portrait" <?php selected( $s['orientation'], 'portrait' ); ?>><?php esc_html_e( 'Portrait', 'reticula-academica' ); ?></option>
				<option value="landscape" <?php selected( $s['orientation'], 'landscape' ); ?>><?php esc_html_e( 'Landscape', 'reticula-academica' ); ?></option>
			</select></label></p>
		<p><label><strong><?php esc_html_e( 'Page margin', 'reticula-academica' ); ?></strong><br>
			<input type="text" name="ra_margin" value="<?php echo esc_attr( $s['margin'] ); ?>" class="small-text" placeholder="2cm"></label></p>
		<p><strong><?php esc_html_e( 'Background image (letterhead)', 'reticula-academica' ); ?></strong></p>
		<div class="ra-image-field">
			<input type="hidden" name="ra_background" value="<?php echo esc_attr( $s['background'] ); ?>">
			<?php $bg = $s['background'] ? wp_get_attachment_image_url( $s['background'], 'medium' ) : ''; ?>
			<img src="<?php echo esc_url( (string) $bg ); ?>" style="max-width:100%;<?php echo $bg ? '' : 'display:none'; ?>" alt="">
			<button type="button" class="button ra-pick-image"><?php esc_html_e( 'Choose image', 'reticula-academica' ); ?></button>
			<button type="button" class="button-link ra-clear-image"><?php esc_html_e( 'Remove', 'reticula-academica' ); ?></button>
		</div>
		<p><strong><?php esc_html_e( 'Programs', 'reticula-academica' ); ?></strong><br>
			<select name="ra_programs[]" multiple size="4" style="width:100%">
				<?php foreach ( Curriculum::programs( true ) as $program ) : ?>
					<option value="<?php echo esc_attr( $program->ID ); ?>" <?php selected( in_array( (int) $program->ID, $s['programs'], true ) ); ?>><?php echo esc_html( get_the_title( $program ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php esc_html_e( 'None selected = every program.', 'reticula-academica' ); ?></span></p>
		<?php if ( 'auto-draft' !== $post->post_status ) : ?>
			<p><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ra_preview_template&template=' . $post->ID ), 'ra_preview_' . $post->ID ) ); ?>"><?php esc_html_e( 'Preview PDF', 'reticula-academica' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	public static function render_fields_box() {
		echo '<p>' . esc_html__( 'Write these fields in the template (with braces). They are replaced with the student’s data when the PDF is generated.', 'reticula-academica' ) . '</p><table class="widefat striped"><tbody>';
		foreach ( self::placeholders() as $key => $label ) {
			echo '<tr><td style="width:240px"><code>{' . esc_html( $key ) . '}</code></td><td>' . esc_html( $label ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function save_template( $post_id, $post ) {
		if ( ! isset( $_POST['ra_template_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ra_template_nonce'] ) ), 'ra_save_template' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( Post_Types::capability() ) ) {
			return;
		}
		$type = isset( $_POST['ra_type'] ) ? sanitize_key( $_POST['ra_type'] ) : 'constancia';
		update_post_meta( $post_id, '_ra_type', isset( self::types()[ $type ] ) ? $type : 'constancia' );
		update_post_meta( $post_id, '_ra_price', isset( $_POST['ra_price'] ) ? max( 0, (float) $_POST['ra_price'] ) : 0 );
		update_post_meta( $post_id, '_ra_prefix', isset( $_POST['ra_prefix'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['ra_prefix'] ) ) ) ) : '' );
		update_post_meta( $post_id, '_ra_paper', isset( $_POST['ra_paper'] ) ? sanitize_key( $_POST['ra_paper'] ) : 'letter' );
		update_post_meta( $post_id, '_ra_orientation', isset( $_POST['ra_orientation'] ) ? sanitize_key( $_POST['ra_orientation'] ) : 'portrait' );
		update_post_meta( $post_id, '_ra_margin', isset( $_POST['ra_margin'] ) ? preg_replace( '/[^0-9a-z.\s]/', '', sanitize_text_field( wp_unslash( $_POST['ra_margin'] ) ) ) : '' );
		update_post_meta( $post_id, '_ra_background', isset( $_POST['ra_background'] ) ? absint( $_POST['ra_background'] ) : 0 );
		update_post_meta( $post_id, '_ra_programs', isset( $_POST['ra_programs'] ) ? array_map( 'absint', (array) $_POST['ra_programs'] ) : array() );
		self::sync_product( $post_id );
	}

	/**
	 * Keep the sale product (a Tutor course; plus a WooCommerce product when Tutor sells through WooCommerce) in sync.
	 */
	public static function sync_product( $template_id ) {
		$template = get_post( $template_id );
		$s        = self::template_settings( $template_id );
		$course   = $s['product'] && get_post( $s['product'] ) ? $s['product'] : 0;
		$active   = $s['price'] > 0 && 'publish' === $template->post_status;

		if ( ! $active ) {
			if ( $course && 'publish' === get_post_status( $course ) ) {
				wp_update_post( array( 'ID' => $course, 'post_status' => 'draft' ) );
			}
			return;
		}
		$data = array(
			'post_type'    => Tutor::course_post_type(),
			'post_title'   => $template->post_title,
			'post_status'  => 'publish',
			'post_content' => sprintf(
				/* translators: %s: document name. */
				__( 'Payment for one "%s" document. After paying, generate and download the PDF in My documents.', 'reticula-academica' ),
				$template->post_title
			),
		);
		if ( $course ) {
			$data['ID'] = $course;
			wp_update_post( $data );
		} else {
			$data['post_author'] = get_current_user_id();
			$course              = wp_insert_post( $data );
			update_post_meta( $template_id, '_ra_product_course', $course );
		}
		update_post_meta( $course, self::PRODUCT_META, $template_id );
		update_post_meta( $course, '_tutor_course_price_type', 'paid' );
		update_post_meta( $course, 'tutor_course_price', $s['price'] );

		// WooCommerce monetization: Tutor sells the WooCommerce product linked to the course.
		if ( function_exists( 'tutor_utils' ) && 'wc' === tutor_utils()->get_option( 'monetize_by' ) && class_exists( '\WC_Product_Simple' ) ) {
			$product_id = absint( get_post_meta( $course, '_tutor_course_product_id', true ) );
			$product    = $product_id ? wc_get_product( $product_id ) : null;
			if ( ! $product ) {
				$product = new \WC_Product_Simple();
			}
			$product->set_name( $template->post_title );
			$product->set_regular_price( (string) $s['price'] );
			$product->set_virtual( true );
			$product->set_sold_individually( true );
			$product->set_catalog_visibility( 'hidden' );
			$product->set_status( 'publish' );
			$product_id = $product->save();
			update_post_meta( $product_id, '_tutor_product', 'yes' );
			update_post_meta( $course, '_tutor_course_product_id', $product_id );
		}
		do_action( 'ra_document_product_synced', $template_id, $course );
	}

	public static function on_trash_template( $post_id ) {
		if ( get_post_type( $post_id ) === self::TEMPLATE ) {
			$course = absint( get_post_meta( $post_id, '_ra_product_course', true ) );
			if ( $course && get_post( $course ) ) {
				wp_update_post( array( 'ID' => $course, 'post_status' => 'draft' ) );
			}
		}
	}

	public static function template_columns( $columns ) {
		$date = $columns['date'];
		unset( $columns['date'] );
		$columns['ra_type']   = __( 'Type', 'reticula-academica' );
		$columns['ra_price']  = __( 'Price', 'reticula-academica' );
		$columns['ra_issued'] = __( 'Issued', 'reticula-academica' );
		$columns['date']      = $date;
		return $columns;
	}

	public static function template_column( $column, $post_id ) {
		global $wpdb;
		$s = self::template_settings( $post_id );
		if ( 'ra_type' === $column ) {
			echo esc_html( self::types()[ $s['type'] ] );
		} elseif ( 'ra_price' === $column ) {
			echo $s['price'] > 0 ? esc_html( ( $s['product'] ? Tutor::price_label( $s['product'] ) : '' ) ?: number_format( $s['price'], 2 ) ) : esc_html__( 'Free', 'reticula-academica' ); // phpcs:ignore WordPress.PHP.DisallowShortTernary
		} elseif ( 'ra_issued' === $column ) {
			$table = self::table();
			echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE template_id = %d AND status = %s", $post_id, self::STATUS_ISSUED ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Create the two starter templates once.
	 */
	public static function maybe_create_defaults() {
		if ( get_option( 'ra_default_templates' ) || ! is_admin() ) {
			return;
		}
		update_option( 'ra_default_templates', 1 );
		$defaults = array(
			array(
				'title'  => 'Constancia de estudios',
				'type'   => 'constancia',
				'prefix' => 'CON',
				'price'  => 0,
				'file'   => 'constancia.html',
			),
			array(
				'title'  => 'Boleta de calificaciones',
				'type'   => 'boleta',
				'prefix' => 'BOL',
				'price'  => 0,
				'file'   => 'boleta.html',
			),
		);
		foreach ( $defaults as $default ) {
			$id = wp_insert_post(
				array(
					'post_type'    => self::TEMPLATE,
					'post_status'  => 'draft',
					'post_title'   => $default['title'],
					'post_content' => (string) file_get_contents( RA_DIR . 'templates/' . $default['file'] ), // phpcs:ignore WordPress.WP.AlternativeFunctions
				)
			);
			update_post_meta( $id, '_ra_type', $default['type'] );
			update_post_meta( $id, '_ra_prefix', $default['prefix'] );
			update_post_meta( $id, '_ra_price', $default['price'] );
			update_post_meta( $id, '_ra_paper', 'letter' );
			update_post_meta( $id, '_ra_orientation', 'portrait' );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Data for a document
	 * ------------------------------------------------------------------ */

	private static function image_data_uri( $attachment_id ) {
		$file = $attachment_id ? get_attached_file( $attachment_id ) : '';
		if ( ! $file || ! is_readable( $file ) ) {
			return '';
		}
		$type = wp_check_filetype( $file );
		if ( empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) ) {
			return '';
		}
		return 'data:' . $type['type'] . ';base64,' . base64_encode( (string) file_get_contents( $file ) ); // phpcs:ignore
	}

	private static function signature_block( $index ) {
		$name  = (string) Settings::get( 'sign' . $index . '_name' );
		$title = (string) Settings::get( 'sign' . $index . '_title' );
		$img   = self::image_data_uri( (int) Settings::get( 'sign' . $index . '_image' ) );
		if ( '' === $name && '' === $title && '' === $img ) {
			return '';
		}
		return '<div class="ra-firma" style="text-align:center">'
			. ( $img ? '<img src="' . esc_attr( $img ) . '" style="height:60px" alt=""><br>' : '<div style="height:60px"></div>' )
			. '<div style="border-top:1px solid #333;margin:0 20px;padding-top:4px"><strong>' . esc_html( $name ) . '</strong><br><span style="font-size:90%">' . esc_html( $title ) . '</span></div></div>';
	}

	/**
	 * Grades tables for a boleta.
	 *
	 * @param array $inscription Inscription row.
	 * @param int   $term_id     Only this term (0 = every term started so far).
	 * @return array [ detailed HTML, summary HTML, general average ]
	 */
	public static function grades_tables( array $inscription, $term_id = 0 ) {
		$progress = Inscriptions::progress( $inscription );
		$user_id  = (int) $inscription['user_id'];
		$border   = 'border:1px solid #cfd4dc;';
		$cell     = static function ( $content, $style = '', $tag = 'td', $colspan = 1 ) use ( $border ) {
			return '<' . $tag . ( $colspan > 1 ? ' colspan="' . (int) $colspan . '"' : '' ) . ' style="' . $border . 'padding:5px;' . $style . '">' . $content . '</' . $tag . '>';
		};
		$pct      = static function ( $weight ) {
			return rtrim( rtrim( number_format( (float) $weight, 2, '.', '' ), '0' ), '.' ) . '%';
		};
		$head_bg  = 'background:#f0f2f5;';
		$detail   = '';
		$summary  = '';
		$all      = array();

		foreach ( $progress['terms'] as $term ) {
			if ( $term_id ? (int) $term['id'] !== (int) $term_id : 'upcoming' === $term['status'] ) {
				continue;
			}
			$term_grades = array();
			$rows_d      = '';
			$rows_s      = '';
			foreach ( $term['courses'] as $course ) {
				$title = esc_html( get_the_title( $course['id'] ) );
				if ( 'exempt' === $course['status'] ) {
					$label   = esc_html__( 'Credited', 'reticula-academica' );
					$rows_s .= '<tr>' . $cell( $title ) . $cell( '—', 'text-align:center' ) . $cell( $label, 'text-align:center' ) . '</tr>';
					$rows_d .= '<tr>' . $cell( '<strong>' . $title . '</strong>', '', 'td', 2 ) . $cell( $label, 'text-align:center' ) . '</tr>';
					continue;
				}
				$grade         = Grades::course( $course['id'], $user_id );
				$term_grades[] = $grade;
				$all[]         = $grade;
				$value         = esc_html( Grades::format( $grade['grade'] ) );
				$status        = esc_html( Grades::status_label( $grade['status'] ) );
				$rows_s       .= '<tr>' . $cell( $title ) . $cell( $value, 'text-align:center' ) . $cell( $status, 'text-align:center' ) . '</tr>';
				$rows_d       .= '<tr>' . $cell( '<strong>' . $title . '</strong>', '', 'td', 2 ) . $cell( '<strong>' . $value . '</strong> · ' . $status, 'text-align:center' ) . '</tr>';
				foreach ( $grade['items'] as $item ) {
					if ( null === $item['score'] ) {
						$score = 'pending_review' === $item['state'] ? __( 'Under review', 'reticula-academica' ) : __( 'Not taken', 'reticula-academica' );
					} else {
						$score = Grades::format( $item['score'] );
					}
					$rows_d .= '<tr>' . $cell( esc_html( $item['title'] ), 'padding-left:18px;font-size:90%' ) . $cell( esc_html( $pct( $item['weight'] ) ), 'text-align:center;font-size:90%' ) . $cell( esc_html( $score ), 'text-align:center;font-size:90%' ) . '</tr>';
				}
			}
			$avg     = Grade_Calculator::average( $term_grades, (int) Settings::get( 'grade_decimals' ) );
			$avg_row = '<tr>' . $cell( '<strong>' . esc_html__( 'Term average', 'reticula-academica' ) . '</strong>', 'text-align:right', 'td', 2 ) . $cell( '<strong>' . esc_html( Grades::format( $avg ) ) . '</strong>', 'text-align:center' ) . '</tr>';
			$head    = '<tr><th colspan="3" style="background:#1f3a68;color:#fff;padding:6px;text-align:left">' . esc_html( $term['title'] ) . '</th></tr>';
			$detail .= $head . '<tr>' . $cell( esc_html__( 'Course / evaluation', 'reticula-academica' ), $head_bg . 'text-align:left', 'th' ) . $cell( esc_html__( 'Weight', 'reticula-academica' ), $head_bg . 'text-align:center;width:80px', 'th' ) . $cell( esc_html__( 'Grade', 'reticula-academica' ), $head_bg . 'text-align:center;width:150px', 'th' ) . '</tr>' . $rows_d . $avg_row;
			$summary .= $head . '<tr>' . $cell( esc_html__( 'Course', 'reticula-academica' ), $head_bg . 'text-align:left', 'th' ) . $cell( esc_html__( 'Grade', 'reticula-academica' ), $head_bg . 'text-align:center;width:90px', 'th' ) . $cell( esc_html__( 'Status', 'reticula-academica' ), $head_bg . 'text-align:center;width:120px', 'th' ) . '</tr>' . $rows_s . $avg_row;
		}
		$general = Grade_Calculator::average( $all, (int) Settings::get( 'grade_decimals' ) );
		$open    = '<table style="width:100%;border-collapse:collapse;font-size:10pt">';
		return array( $open . $detail . '</table>', $open . $summary . '</table>', $general );
	}

	/**
	 * Values of every placeholder for a student in a program.
	 */
	/**
	 * @param int    $user_id     Student.
	 * @param int    $program_id  Program.
	 * @param int    $term_id     Term for {tetramestre} (0 = current term).
	 * @param int    $timestamp   Issue time.
	 * @param string $folio       Folio.
	 * @param string $verify_url  Verification URL.
	 * @param int    $grades_term Restrict grade tables to one term (0 = all).
	 */
	public static function vars( $user_id, $program_id, $term_id = 0, $timestamp = 0, $folio = '', $verify_url = '', $grades_term = 0 ) {
		$user        = get_userdata( $user_id );
		$inscription = Inscriptions::find( $user_id, $program_id );
		$timestamp   = $timestamp ? $timestamp : current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$progress    = $inscription ? Inscriptions::progress( $inscription ) : null;
		$term_id     = $term_id ? $term_id : ( $progress ? (int) $progress['current_term_id'] : 0 );
		$term_number = $term_id ? (int) get_post_field( 'menu_order', $term_id ) : 0;
		$statuses    = Inscriptions::statuses();
		$logo        = self::image_data_uri( (int) Settings::get( 'logo_id' ) );
		$city        = (string) Settings::get( 'city' );
		$date_long   = Template_Renderer::long_date( $timestamp );
		$name        = $user ? trim( $user->first_name . ' ' . $user->last_name ) : '';
		$name        = '' !== $name ? $name : ( $user ? $user->display_name : '' );

		list( $detail, $summary, $general ) = $inscription ? self::grades_tables( $inscription, $grades_term ) : array( '', '', null );
		if ( $inscription && $grades_term ) {
			// The general average always covers the whole program.
			$general = self::grades_tables( $inscription, 0 )[2];
		}

		$sign1 = self::signature_block( 1 );
		$sign2 = self::signature_block( 2 );

		$vars = array(
			'institucion'                  => esc_html( (string) Settings::get( 'institution' ) ),
			'logo'                         => $logo ? '<img src="' . esc_attr( $logo ) . '" style="max-height:80px" alt="">' : '',
			'alumno'                       => esc_html( $name ),
			'nombre'                       => esc_html( $user ? $user->first_name : '' ),
			'apellidos'                    => esc_html( $user ? $user->last_name : '' ),
			'matricula'                    => esc_html( Student_Id::get( $user_id ) ),
			'correo'                       => esc_html( $user ? $user->user_email : '' ),
			'carrera'                      => esc_html( get_the_title( $program_id ) ),
			'clave_carrera'                => esc_html( (string) get_post_meta( $program_id, '_ra_code', true ) ),
			'nivel'                        => esc_html( (string) get_post_meta( $program_id, '_ra_level', true ) ),
			'modalidad'                    => esc_html( (string) get_post_meta( $program_id, '_ra_modality', true ) ),
			'rvoe'                         => esc_html( (string) get_post_meta( $program_id, '_ra_rvoe', true ) ),
			'rvoe_fecha'                   => esc_html( self::format_meta_date( (string) get_post_meta( $program_id, '_ra_rvoe_date', true ) ) ),
			'tetramestre'                  => esc_html( $term_id ? get_the_title( $term_id ) : '' ),
			'tetramestre_numero'           => esc_html( $term_number ? (string) $term_number : '' ),
			'tetramestre_ordinal'          => esc_html( $term_number ? Template_Renderer::ordinal( $term_number ) : '' ),
			'periodo'                      => esc_html( Settings::current_period( gmdate( 'Y-m-d', $timestamp ) ) ),
			'estatus'                      => esc_html( $inscription && isset( $statuses[ $inscription['status'] ] ) ? $statuses[ $inscription['status'] ] : '' ),
			'avance'                       => esc_html( $progress ? $progress['percent'] . '%' : '' ),
			'promedio_general'             => esc_html( Grades::format( $general ) ),
			'tabla_calificaciones'         => $detail,
			'tabla_calificaciones_resumen' => $summary,
			'fecha_emision'                => esc_html( $date_long ),
			'fecha'                        => esc_html( gmdate( 'd/m/Y', $timestamp ) ),
			'lugar_fecha'                  => esc_html( ( '' !== $city ? $city . ', a ' : '' ) . $date_long ),
			'firma_1'                      => $sign1,
			'firma_2'                      => $sign2,
			'firmas'                       => '<table style="width:100%;margin-top:30px"><tr><td style="width:50%;vertical-align:bottom">' . $sign1 . '</td><td style="width:50%;vertical-align:bottom">' . $sign2 . '</td></tr></table>',
			'firma_1_nombre'               => esc_html( (string) Settings::get( 'sign1_name' ) ),
			'firma_1_cargo'                => esc_html( (string) Settings::get( 'sign1_title' ) ),
			'firma_2_nombre'               => esc_html( (string) Settings::get( 'sign2_name' ) ),
			'firma_2_cargo'                => esc_html( (string) Settings::get( 'sign2_title' ) ),
			'folio'                        => esc_html( $folio ),
			'verificacion_url'             => esc_html( $verify_url ),
		);
		return apply_filters( 'ra_document_vars', $vars, $user_id, $program_id, $term_id );
	}

	private static function format_meta_date( $value ) {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return Template_Renderer::long_date( strtotime( $value . ' 12:00:00 UTC' ) );
		}
		return $value;
	}

	/* ------------------------------------------------------------------ *
	 * PDF
	 * ------------------------------------------------------------------ */

	public static function html( $template_id, array $vars ) {
		$s      = self::template_settings( $template_id );
		$body   = Template_Renderer::render( wpautop( (string) get_post_field( 'post_content', $template_id ) ), $vars );
		$margin = '' !== $s['margin'] ? $s['margin'] : '2cm';
		$bg     = self::image_data_uri( $s['background'] );
		$css    = '@page { margin: ' . $margin . '; } body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; color: #1d2327; line-height: 1.45; } h1,h2,h3 { line-height: 1.2; } table { page-break-inside: auto; } tr { page-break-inside: avoid; } .ra-bg { position: fixed; top: -' . $margin . '; left: -' . $margin . '; right: -' . $margin . '; bottom: -' . $margin . '; z-index: -1; } .ra-bg img { width: 100%; height: 100%; }';
		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
			. ( $bg ? '<div class="ra-bg"><img src="' . esc_attr( $bg ) . '" alt=""></div>' : '' )
			. $body . '</body></html>';
	}

	public static function pdf_available() {
		return file_exists( RA_DIR . 'vendor/autoload.php' );
	}

	/**
	 * Render HTML to PDF bytes.
	 *
	 * @return string|\WP_Error
	 */
	public static function pdf( $html, $paper = 'letter', $orientation = 'portrait' ) {
		if ( ! self::pdf_available() ) {
			return new \WP_Error( 'ra_no_pdf', __( 'The PDF library is missing from the plugin folder.', 'reticula-academica' ) );
		}
		require_once RA_DIR . 'vendor/autoload.php';
		$cache = self::storage_dir() . '/fonts';
		wp_mkdir_p( $cache );
		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isHtml5ParserEnabled', true );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'fontCache', $cache );
		$options->set( 'tempDir', get_temp_dir() );
		$options->set( 'chroot', array( RA_DIR ) );
		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( $paper, $orientation );
		$dompdf->render();
		return $dompdf->output();
	}

	/* ------------------------------------------------------------------ *
	 * Storage and records
	 * ------------------------------------------------------------------ */

	public static function storage_dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::FOLDER;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
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
	 * Documents of a user (issued and available credits).
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function available_credit( $user_id, $template_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND template_id = %d AND status = %s ORDER BY id ASC LIMIT 1", $user_id, $template_id, self::STATUS_AVAILABLE ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function verify_url( array $doc ) {
		return add_query_arg(
			array(
				'ra_verify' => rawurlencode( $doc['folio'] ),
				'k'         => $doc['verify_key'],
			),
			home_url( '/' )
		);
	}

	public static function download_url( $doc_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=ra_download_document&doc=' . absint( $doc_id ) ), 'ra_download_' . absint( $doc_id ) );
	}

	/**
	 * Generate a document: snapshot the data, render the PDF, store it and record it in the history.
	 *
	 * @param array $args user_id, template_id, program_id, term_id, source (free|purchase|admin), credit_id, issued_by.
	 * @return array|\WP_Error Document row.
	 */
	public static function issue( array $args ) {
		global $wpdb;
		$args        = wp_parse_args( $args, array( 'term_id' => 0, 'source' => 'free', 'credit_id' => 0, 'issued_by' => get_current_user_id() ) );
		$template_id = absint( $args['template_id'] );
		$user_id     = absint( $args['user_id'] );
		$program_id  = absint( $args['program_id'] );
		if ( get_post_type( $template_id ) !== self::TEMPLATE || ! get_userdata( $user_id ) || get_post_type( $program_id ) !== Curriculum::PROGRAM ) {
			return new \WP_Error( 'ra_doc', __( 'The document could not be generated: invalid data.', 'reticula-academica' ) );
		}
		$s     = self::template_settings( $template_id );
		$table = self::table();
		$now   = current_time( 'mysql', true );
		$key   = strtolower( wp_generate_password( 20, false, false ) );

		if ( $args['credit_id'] ) {
			$id = (int) $args['credit_id'];
			$wpdb->update( $table, array( 'verify_key' => $key ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				$table,
				array(
					'user_id'     => $user_id,
					'template_id' => $template_id,
					'doc_type'    => $s['type'],
					'program_id'  => $program_id,
					'status'      => 'pending',
					'source'      => sanitize_key( $args['source'] ),
					'verify_key'  => $key,
					'created_at'  => $now,
				)
			);
			$id = (int) $wpdb->insert_id;
		}
		$timestamp = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$folio     = Template_Renderer::folio( '' !== $s['prefix'] ? $s['prefix'] : strtoupper( substr( $s['type'], 0, 3 ) ), gmdate( 'Y', $timestamp ), $id );
		$doc       = array( 'folio' => $folio, 'verify_key' => $key );
		$term_id   = absint( $args['term_id'] );
		$vars      = self::vars( $user_id, $program_id, $term_id, $timestamp, $folio, self::verify_url( $doc ), 'boleta' === $s['type'] ? $term_id : 0 );
		$pdf       = self::pdf( self::html( $template_id, $vars ), $s['paper'], $s['orientation'] );
		if ( is_wp_error( $pdf ) ) {
			if ( ! $args['credit_id'] ) {
				$wpdb->delete( $table, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
			}
			return $pdf;
		}
		$relative = gmdate( 'Y', $timestamp ) . '/' . sanitize_file_name( $folio ) . '-' . wp_generate_password( 12, false, false ) . '.pdf';
		$path     = self::storage_dir() . '/' . $relative;
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$snapshot = array_map( 'wp_strip_all_tags', array_diff_key( $vars, array_flip( array( 'logo', 'tabla_calificaciones', 'tabla_calificaciones_resumen', 'firmas', 'firma_1', 'firma_2' ) ) ) );
		$wpdb->update( // phpcs:ignore WordPress.DB
			$table,
			array(
				'folio'      => $folio,
				'program_id' => $program_id,
				'term_id'    => $term_id,
				'status'     => self::STATUS_ISSUED,
				'file'       => $relative,
				'data'       => wp_json_encode( array( 'template_title' => get_the_title( $template_id ), 'vars' => $snapshot ) ),
				'issued_at'  => $now,
				'issued_by'  => absint( $args['issued_by'] ),
			),
			array( 'id' => $id )
		);
		$doc = self::get( $id );

		// A paid document consumed its purchase: remove the product enrollment so it can be bought again.
		if ( 'purchase' === $doc['source'] && $doc['enrollment_id'] && get_post( $doc['enrollment_id'] ) ) {
			wp_delete_post( $doc['enrollment_id'], true );
		}
		do_action( 'ra_document_issued', $doc );
		return $doc;
	}

	/* ------------------------------------------------------------------ *
	 * Purchases
	 * ------------------------------------------------------------------ */

	public static function product_template( $course_id ) {
		$template = absint( get_post_meta( $course_id, self::PRODUCT_META, true ) );
		return $template && get_post_type( $template ) === self::TEMPLATE ? $template : 0;
	}

	/**
	 * A purchase of a document product gives the student one document to generate.
	 */
	public static function on_enrolled( $course_id, $user_id, $enrollment_id = 0 ) {
		global $wpdb;
		$template_id = self::product_template( $course_id );
		if ( ! $template_id || ! $user_id ) {
			return;
		}
		$table = self::table();
		if ( $enrollment_id && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE enrollment_id = %d", $enrollment_id ) ) ) { // phpcs:ignore WordPress.DB
			return;
		}
		$s = self::template_settings( $template_id );
		$wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'user_id'       => $user_id,
				'template_id'   => $template_id,
				'doc_type'      => $s['type'],
				'status'        => self::STATUS_AVAILABLE,
				'source'        => 'purchase',
				'enrollment_id' => absint( $enrollment_id ),
				'created_at'    => current_time( 'mysql', true ),
			)
		);
		do_action( 'ra_document_purchased', (int) $wpdb->insert_id, $user_id, $template_id );
	}

	/**
	 * Inscriptions in which a student may request a template.
	 *
	 * @return array[]
	 */
	public static function eligible_inscriptions( $user_id, $template_id ) {
		$s   = self::template_settings( $template_id );
		$out = array();
		foreach ( Inscriptions::for_user( $user_id ) as $inscription ) {
			if ( in_array( $inscription['status'], array( Progress::STATUS_PENDING, Progress::STATUS_DROPPED ), true ) ) {
				continue;
			}
			if ( ! $s['programs'] || in_array( (int) $inscription['program_id'], $s['programs'], true ) ) {
				$out[] = $inscription;
			}
		}
		return $out;
	}

	public static function purchase_filter( $allowed, $course_id ) {
		if ( is_wp_error( $allowed ) || false === $allowed ) {
			return $allowed;
		}
		$template_id = self::product_template( $course_id );
		$user_id     = get_current_user_id();
		if ( ! $template_id || ! $user_id || user_can( $user_id, Post_Types::capability() ) ) {
			return $allowed;
		}
		if ( ! self::eligible_inscriptions( $user_id, $template_id ) ) {
			return new \WP_Error( 'ra_doc_not_eligible', __( 'This document is only available to students with an active program inscription.', 'reticula-academica' ) );
		}
		return $allowed;
	}

	/**
	 * Keep document products out of the course catalog and search.
	 */
	public static function hide_products( $query ) {
		if ( is_admin() || ! $query->is_main_query() || $query->is_singular() ) {
			return;
		}
		$type = Tutor::course_post_type();
		if ( $query->is_post_type_archive( $type ) || ( $query->is_search() ) ) {
			$meta   = (array) $query->get( 'meta_query' );
			$meta[] = array(
				'key'     => self::PRODUCT_META,
				'compare' => 'NOT EXISTS',
			);
			$query->set( 'meta_query', $meta );
		}
	}

	public static function product_notice() {
		$template_id = self::product_template( get_the_ID() );
		if ( ! $template_id ) {
			return;
		}
		echo '<div class="ra-course-notice"><p><strong>' . esc_html__( 'This is the payment for an official document.', 'reticula-academica' ) . '</strong></p><p>' . esc_html__( 'After paying, go to My documents to generate and download your PDF.', 'reticula-academica' ) . '</p><a class="ra-link" href="' . esc_url( self::documents_url() ) . '">' . esc_html__( 'My documents', 'reticula-academica' ) . ' &rarr;</a></div>';
	}

	public static function documents_url() {
		return Tutor::active() ? Tutor::dashboard_url( Frontend::DOCUMENTS_SLUG ) : home_url( '/' );
	}

	/* ------------------------------------------------------------------ *
	 * Handlers
	 * ------------------------------------------------------------------ */

	public static function handle_generate() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		$template_id = isset( $_POST['template'] ) ? absint( $_POST['template'] ) : 0;
		check_admin_referer( 'ra_generate_' . $template_id );
		$user_id    = get_current_user_id();
		$program_id = isset( $_POST['program'] ) ? absint( $_POST['program'] ) : 0;
		$term_id    = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$back       = wp_get_referer() ? wp_get_referer() : self::documents_url();

		$eligible = wp_list_pluck( self::eligible_inscriptions( $user_id, $template_id ), 'program_id' );
		if ( 'publish' !== get_post_status( $template_id ) || ! in_array( (string) $program_id, array_map( 'strval', $eligible ), true ) ) {
			wp_die( esc_html__( 'You cannot generate this document.', 'reticula-academica' ), 403 );
		}
		$s      = self::template_settings( $template_id );
		$credit = null;
		if ( $s['price'] > 0 ) {
			$credit = self::available_credit( $user_id, $template_id );
			if ( ! $credit ) {
				wp_safe_redirect( $s['product'] ? get_permalink( $s['product'] ) : $back );
				exit;
			}
		}
		$doc = self::issue(
			array(
				'user_id'     => $user_id,
				'template_id' => $template_id,
				'program_id'  => $program_id,
				'term_id'     => $term_id,
				'source'      => $credit ? 'purchase' : 'free',
				'credit_id'   => $credit ? (int) $credit['id'] : 0,
			)
		);
		if ( is_wp_error( $doc ) ) {
			wp_die( esc_html( $doc->get_error_message() ) );
		}
		self::stream( $doc );
	}

	public static function handle_download() {
		$id = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0;
		check_admin_referer( 'ra_download_' . $id );
		$doc = self::get( $id );
		if ( ! $doc || self::STATUS_ISSUED !== $doc['status'] || ( (int) $doc['user_id'] !== get_current_user_id() && ! current_user_can( Post_Types::capability() ) ) ) {
			wp_die( esc_html__( 'Document not available.', 'reticula-academica' ), 404 );
		}
		self::stream( $doc );
	}

	private static function stream( array $doc ) {
		$path = self::storage_dir() . '/' . $doc['file'];
		if ( ! $doc['file'] || ! is_readable( $path ) || false !== strpos( $doc['file'], '..' ) ) {
			wp_die( esc_html__( 'The PDF file could not be found.', 'reticula-academica' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $doc['folio'] ) . '.pdf"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function handle_preview() {
		$template_id = isset( $_GET['template'] ) ? absint( $_GET['template'] ) : 0;
		check_admin_referer( 'ra_preview_' . $template_id );
		if ( ! current_user_can( Post_Types::capability() ) ) {
			wp_die( '', 403 );
		}
		$s    = self::template_settings( $template_id );
		$vars = array();
		foreach ( self::placeholders() as $key => $label ) {
			$vars[ $key ] = '<span style="background:#fff3c4">{' . esc_html( $key ) . '}</span>';
		}
		// Use the most recent student so the preview shows real data; fall back to highlighted field names.
		list( $rows ) = Inscriptions::query( array(), 1 );
		if ( $rows ) {
			$vars = self::vars( (int) $rows[0]['user_id'], (int) $rows[0]['program_id'], 0, 0, 'VISTA-PREVIA', home_url( '/' ) );
		}
		$pdf = self::pdf( self::html( $template_id, $vars ), $s['paper'], $s['orientation'] );
		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ) );
		}
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="preview.pdf"' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function handle_admin_issue() {
		check_admin_referer( 'ra_admin_issue' );
		if ( ! current_user_can( Post_Types::capability() ) ) {
			wp_die( '', 403 );
		}
		$user = Admin::find_user( isset( $_POST['ra_user'] ) ? sanitize_text_field( wp_unslash( $_POST['ra_user'] ) ) : '' );
		if ( ! $user && isset( $_POST['ra_user_id'] ) ) {
			$user = get_userdata( absint( $_POST['ra_user_id'] ) );
		}
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . Curriculum::PROGRAM . '&page=' . self::PAGE );
		if ( ! $user ) {
			set_transient( 'ra_notice_' . get_current_user_id(), array( 'error', esc_html__( 'Student not found. Use the user ID, email or username.', 'reticula-academica' ) ), 120 );
			wp_safe_redirect( $back );
			exit;
		}
		$program_id = isset( $_POST['ra_program'] ) ? absint( $_POST['ra_program'] ) : 0;
		if ( ! $program_id ) {
			$inscriptions = Inscriptions::for_user( $user->ID );
			$program_id   = $inscriptions ? (int) $inscriptions[0]['program_id'] : 0;
		}
		$doc = self::issue(
			array(
				'user_id'     => $user->ID,
				'template_id' => isset( $_POST['ra_template'] ) ? absint( $_POST['ra_template'] ) : 0,
				'program_id'  => $program_id,
				'term_id'     => isset( $_POST['ra_term'] ) ? absint( $_POST['ra_term'] ) : 0,
				'source'      => 'admin',
			)
		);
		if ( is_wp_error( $doc ) ) {
			set_transient( 'ra_notice_' . get_current_user_id(), array( 'error', esc_html( $doc->get_error_message() ) ), 120 );
		} else {
			/* translators: 1: folio, 2: download link. */
			set_transient( 'ra_notice_' . get_current_user_id(), array( 'success', sprintf( esc_html__( 'Document %1$s generated. %2$s', 'reticula-academica' ), esc_html( $doc['folio'] ), '<a href="' . esc_url( self::download_url( $doc['id'] ) ) . '">' . esc_html__( 'Download PDF', 'reticula-academica' ) . '</a>' ) ), 120 );
		}
		wp_safe_redirect( $back );
		exit;
	}

	public static function handle_revoke() {
		$id = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0;
		check_admin_referer( 'ra_revoke_' . $id );
		if ( ! current_user_can( Post_Types::capability() ) ) {
			wp_die( '', 403 );
		}
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => self::STATUS_REVOKED ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		set_transient( 'ra_notice_' . get_current_user_id(), array( 'success', esc_html__( 'Document revoked. Its verification link now reports it as not valid.', 'reticula-academica' ) ), 120 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Public verification page: /?ra_verify=FOLIO&k=KEY
	 */
	public static function maybe_verify() {
		if ( ! isset( $_GET['ra_verify'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		global $wpdb;
		$folio = sanitize_text_field( wp_unslash( $_GET['ra_verify'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key   = isset( $_GET['k'] ) ? sanitize_key( $_GET['k'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$table = self::table();
		$doc   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE folio = %s", $folio ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$valid = $doc && hash_equals( (string) $doc['verify_key'], $key ) && self::STATUS_ISSUED === $doc['status'];
		$data  = $doc ? json_decode( (string) $doc['data'], true ) : array();
		status_header( $valid ? 200 : 404 );
		nocache_headers();
		?>
		<!DOCTYPE html>
		<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
		<title><?php esc_html_e( 'Document verification', 'reticula-academica' ); ?></title>
		<style>body{font-family:system-ui,sans-serif;background:#f4f5f7;margin:0;padding:24px;color:#1d2327}.box{max-width:560px;margin:40px auto;background:#fff;border-radius:10px;padding:24px;border-top:6px solid <?php echo $valid ? '#1c7d43' : '#b32d2e'; ?>}dt{color:#5b616f;font-size:13px;margin-top:10px}dd{margin:2px 0 0;font-weight:600}</style></head>
		<body><div class="box">
			<h1><?php echo $valid ? '&#10003; ' . esc_html__( 'Valid document', 'reticula-academica' ) : esc_html__( 'Document not valid', 'reticula-academica' ); ?></h1>
			<?php if ( $valid ) : ?>
				<dl>
					<dt><?php esc_html_e( 'Institution', 'reticula-academica' ); ?></dt><dd><?php echo esc_html( (string) Settings::get( 'institution' ) ); ?></dd>
					<dt><?php esc_html_e( 'Document', 'reticula-academica' ); ?></dt><dd><?php echo esc_html( $data['template_title'] ?? '' ); ?></dd>
					<dt><?php esc_html_e( 'Folio', 'reticula-academica' ); ?></dt><dd><?php echo esc_html( $doc['folio'] ); ?></dd>
					<dt><?php esc_html_e( 'Student', 'reticula-academica' ); ?></dt><dd><?php echo esc_html( ( $data['vars']['alumno'] ?? '' ) . ' · ' . ( $data['vars']['matricula'] ?? '' ) ); ?></dd>
					<dt><?php esc_html_e( 'Program', 'reticula-academica' ); ?></dt><dd><?php echo esc_html( $data['vars']['carrera'] ?? '' ); ?></dd>
					<dt><?php esc_html_e( 'Issued on', 'reticula-academica' ); ?></dt><dd><?php echo esc_html( $data['vars']['fecha_emision'] ?? '' ); ?></dd>
				</dl>
			<?php else : ?>
				<p><?php esc_html_e( 'The folio does not exist, the link is incomplete or the document was revoked.', 'reticula-academica' ); ?></p>
			<?php endif; ?>
		</div></body></html>
		<?php
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Student screen: "My documents"
	 * ------------------------------------------------------------------ */

	public static function shortcode() {
		return self::render_my_documents();
	}

	public static function render_my_documents() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Log in to see your documents.', 'reticula-academica' ) . '</p>';
		}
		$user_id      = get_current_user_id();
		$inscriptions = array_values(
			array_filter(
				Inscriptions::for_user( $user_id ),
				static function ( $row ) {
					return ! in_array( $row['status'], array( Progress::STATUS_PENDING, Progress::STATUS_DROPPED ), true );
				}
			)
		);
		ob_start();
		echo '<div class="ra-program ra-documents">';
		echo '<header class="ra-program-header"><div><p class="ra-eyebrow">' . esc_html__( 'My documents', 'reticula-academica' ) . '</p>';
		$matricula = Student_Id::get( $user_id );
		if ( $matricula ) {
			/* translators: %s: student ID. */
			echo '<p class="ra-meta">' . esc_html( sprintf( __( 'Student ID: %s', 'reticula-academica' ), $matricula ) ) . '</p>';
		}
		echo '</div></header>';

		if ( ! $inscriptions ) {
			echo '<div class="ra-next ra-next-empty"><p>' . esc_html__( 'Documents are available once your program inscription is active.', 'reticula-academica' ) . '</p></div>';
		}
		foreach ( $inscriptions as $inscription ) {
			$program_id = (int) $inscription['program_id'];
			$templates  = self::templates_for( $program_id );
			if ( ! $templates ) {
				continue;
			}
			$progress = Inscriptions::progress( $inscription );
			echo '<section class="ra-term"><header class="ra-term-header"><h3>' . esc_html( get_the_title( $program_id ) ) . '</h3></header><ul class="ra-courses">';
			foreach ( $templates as $template ) {
				$s      = self::template_settings( $template->ID );
				$credit = $s['price'] > 0 ? self::available_credit( $user_id, $template->ID ) : null;
				echo '<li class="ra-course"><span class="ra-course-icon" aria-hidden="true">&#128196;</span><div class="ra-course-main"><span class="ra-course-title">' . esc_html( get_the_title( $template ) ) . '</span>';
				if ( $s['price'] > 0 ) {
					$price = $s['product'] ? Tutor::price_label( $s['product'] ) : '';
					echo '<p class="ra-lock">' . ( $credit ? esc_html__( 'Paid · ready to generate', 'reticula-academica' ) : esc_html( $price ) ) . '</p>';
				} else {
					echo '<p class="ra-lock">' . esc_html__( 'Free', 'reticula-academica' ) . '</p>';
				}
				echo '</div><div class="ra-course-side">';
				if ( $s['price'] > 0 && ! $credit ) {
					echo '<a class="ra-btn ra-btn-small" href="' . esc_url( $s['product'] ? get_permalink( $s['product'] ) : '#' ) . '">' . esc_html__( 'Buy', 'reticula-academica' ) . '</a>';
				} else {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ra-generate-form">';
					wp_nonce_field( 'ra_generate_' . $template->ID );
					echo '<input type="hidden" name="action" value="ra_generate_document"><input type="hidden" name="template" value="' . esc_attr( $template->ID ) . '"><input type="hidden" name="program" value="' . esc_attr( $program_id ) . '">';
					if ( 'boleta' === $s['type'] ) {
						echo '<select name="term" aria-label="' . esc_attr__( 'Term', 'reticula-academica' ) . '"><option value="0">' . esc_html__( 'All terms', 'reticula-academica' ) . '</option>';
						foreach ( $progress['terms'] as $term ) {
							if ( ! in_array( $term['status'], array( 'upcoming', 'exempt' ), true ) ) {
								echo '<option value="' . esc_attr( $term['id'] ) . '">' . esc_html( $term['title'] ) . '</option>';
							}
						}
						echo '</select> ';
					}
					echo '<button type="submit" class="ra-btn ra-btn-small ra-btn-primary">' . esc_html__( 'Generate PDF', 'reticula-academica' ) . '</button></form>';
				}
				echo '</div></li>';
			}
			echo '</ul></section>';
		}

		// History.
		$history = array_filter(
			self::for_user( $user_id ),
			static function ( $doc ) {
				return self::STATUS_ISSUED === $doc['status'];
			}
		);
		echo '<section class="ra-term"><header class="ra-term-header"><h3>' . esc_html__( 'History', 'reticula-academica' ) . '</h3></header>';
		if ( ! $history ) {
			echo '<p class="ra-lock">' . esc_html__( 'You have not generated documents yet.', 'reticula-academica' ) . '</p>';
		} else {
			echo '<table class="ra-grade-table ra-history"><thead><tr><th>' . esc_html__( 'Folio', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Document', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Date', 'reticula-academica' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $history as $doc ) {
				echo '<tr><td>' . esc_html( $doc['folio'] ) . '</td><td>' . esc_html( get_the_title( $doc['template_id'] ) ) . '<br><span class="ra-lock">' . esc_html( get_the_title( $doc['program_id'] ) ) . '</span></td><td>' . esc_html( wp_date( 'd/m/Y H:i', strtotime( $doc['issued_at'] . ' UTC' ) ) ) . '</td><td><a class="ra-btn ra-btn-small" href="' . esc_url( self::download_url( $doc['id'] ) ) . '">' . esc_html__( 'Download PDF', 'reticula-academica' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section></div>';
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------ *
	 * Admin: documents history
	 * ------------------------------------------------------------------ */

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . Curriculum::PROGRAM, __( 'Issued documents', 'reticula-academica' ), __( 'Issued documents', 'reticula-academica' ), Post_Types::capability(), self::PAGE, array( __CLASS__, 'render_admin_page' ) );
	}

	public static function query( array $filters, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = self::table();
		$where = array( "d.status <> 'pending'" );
		$args  = array();
		if ( ! empty( $filters['template'] ) ) {
			$where[] = 'd.template_id = %d';
			$args[]  = absint( $filters['template'] );
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'd.status = %s';
			$args[]  = $filters['status'];
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'd.user_id = %d';
			$args[]  = absint( $filters['user_id'] );
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = "(d.folio LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s OR d.user_id IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ra_matricula' AND meta_value LIKE %s))";
			array_push( $args, $like, $like, $like, $like );
		}
		$sql    = "SELECT SQL_CALC_FOUND_ROWS d.*, u.display_name, u.user_email FROM {$table} d LEFT JOIN {$wpdb->users} u ON u.ID = d.user_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY d.id DESC LIMIT %d OFFSET %d';
		$args[] = (int) $limit;
		$args[] = (int) $offset;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array( (array) $rows, (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' ) ); // phpcs:ignore WordPress.DB
	}

	public static function issue_form( $user_id = 0 ) {
		$templates = get_posts( array( 'post_type' => self::TEMPLATE, 'post_status' => 'publish', 'posts_per_page' => -1 ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ra-issue-form">
			<?php wp_nonce_field( 'ra_admin_issue' ); ?>
			<input type="hidden" name="action" value="ra_admin_issue">
			<?php if ( $user_id ) : ?>
				<input type="hidden" name="ra_user_id" value="<?php echo esc_attr( $user_id ); ?>">
			<?php else : ?>
				<p><label><?php esc_html_e( 'Student (email, username, ID)', 'reticula-academica' ); ?><br><input type="text" name="ra_user" class="regular-text" required></label></p>
			<?php endif; ?>
			<p><label><?php esc_html_e( 'Template', 'reticula-academica' ); ?><br>
				<select name="ra_template" required>
					<?php foreach ( $templates as $template ) : ?>
						<option value="<?php echo esc_attr( $template->ID ); ?>"><?php echo esc_html( get_the_title( $template ) ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
			<p><label><?php esc_html_e( 'Program', 'reticula-academica' ); ?><br>
				<select name="ra_program">
					<option value="0"><?php esc_html_e( 'Student’s program', 'reticula-academica' ); ?></option>
					<?php foreach ( Curriculum::programs( true ) as $program ) : ?>
						<option value="<?php echo esc_attr( $program->ID ); ?>"><?php echo esc_html( get_the_title( $program ) ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
			<p class="description"><?php esc_html_e( 'Documents issued by staff are not charged.', 'reticula-academica' ); ?></p>
			<?php submit_button( __( 'Generate PDF', 'reticula-academica' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public static function history_table( array $rows ) {
		$types = self::types();
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Folio', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Student', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Document', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Origin', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Date', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Status', 'reticula-academica' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No documents yet.', 'reticula-academica' ) . '</td></tr>';
		}
		$sources  = array(
			'free'     => __( 'Free', 'reticula-academica' ),
			'purchase' => __( 'Purchase', 'reticula-academica' ),
			'admin'    => __( 'Staff', 'reticula-academica' ),
		);
		$statuses = array(
			self::STATUS_AVAILABLE => __( 'Paid, not generated', 'reticula-academica' ),
			self::STATUS_ISSUED    => __( 'Issued', 'reticula-academica' ),
			self::STATUS_REVOKED   => __( 'Revoked', 'reticula-academica' ),
		);
		foreach ( $rows as $doc ) {
			$actions = '';
			if ( self::STATUS_ISSUED === $doc['status'] ) {
				$actions = '<a href="' . esc_url( self::download_url( $doc['id'] ) ) . '">' . esc_html__( 'Download PDF', 'reticula-academica' ) . '</a> · <a href="' . esc_url( self::verify_url( $doc ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Verify', 'reticula-academica' ) . '</a> · <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ra_revoke_document&doc=' . $doc['id'] ), 'ra_revoke_' . $doc['id'] ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Revoke this document?', 'reticula-academica' ) ) . '\')" style="color:#b32d2e">' . esc_html__( 'Revoke', 'reticula-academica' ) . '</a>';
			}
			$date = $doc['issued_at'] ? $doc['issued_at'] : $doc['created_at'];
			printf(
				'<tr><td><strong>%1$s</strong></td><td>%2$s<br><span class="description">%3$s</span></td><td>%4$s<br><span class="description">%5$s</span></td><td>%6$s</td><td>%7$s</td><td>%8$s</td><td>%9$s</td></tr>',
				esc_html( $doc['folio'] ? $doc['folio'] : '—' ),
				esc_html( isset( $doc['display_name'] ) ? $doc['display_name'] : get_the_author_meta( 'display_name', $doc['user_id'] ) ),
				esc_html( Student_Id::get( $doc['user_id'] ) ),
				esc_html( get_the_title( $doc['template_id'] ) ),
				esc_html( isset( $types[ $doc['doc_type'] ] ) ? $types[ $doc['doc_type'] ] : $doc['doc_type'] ),
				esc_html( isset( $sources[ $doc['source'] ] ) ? $sources[ $doc['source'] ] : $doc['source'] ),
				esc_html( wp_date( 'd/m/Y H:i', strtotime( $date . ' UTC' ) ) ),
				esc_html( isset( $statuses[ $doc['status'] ] ) ? $statuses[ $doc['status'] ] : $doc['status'] ),
				$actions // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
		echo '</tbody></table>';
	}

	public static function render_admin_page() {
		if ( ! current_user_can( Post_Types::capability() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filters = array(
			'template' => isset( $_GET['template'] ) ? absint( $_GET['template'] ) : 0,
			'status'   => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		list( $rows, $total ) = self::query( $filters, 50, ( $paged - 1 ) * 50 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Issued documents', 'reticula-academica' ); ?></h1>
			<?php if ( ! self::pdf_available() ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The PDF library is missing from the plugin folder.', 'reticula-academica' ); ?></p></div>
			<?php endif; ?>
			<div class="ra-admin-panels">
				<div class="ra-panel"><h2><?php esc_html_e( 'Generate a document', 'reticula-academica' ); ?></h2><?php self::issue_form(); ?></div>
			</div>
			<form method="get">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( Curriculum::PROGRAM ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<select name="template"><option value="0"><?php esc_html_e( 'All templates', 'reticula-academica' ); ?></option>
					<?php foreach ( get_posts( array( 'post_type' => self::TEMPLATE, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $template ) : ?>
						<option value="<?php echo esc_attr( $template->ID ); ?>" <?php selected( $filters['template'], $template->ID ); ?>><?php echo esc_html( get_the_title( $template ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'reticula-academica' ); ?></option>
					<option value="issued" <?php selected( $filters['status'], 'issued' ); ?>><?php esc_html_e( 'Issued', 'reticula-academica' ); ?></option>
					<option value="available" <?php selected( $filters['status'], 'available' ); ?>><?php esc_html_e( 'Paid, not generated', 'reticula-academica' ); ?></option>
					<option value="revoked" <?php selected( $filters['status'], 'revoked' ); ?>><?php esc_html_e( 'Revoked', 'reticula-academica' ); ?></option>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Folio, student or ID', 'reticula-academica' ); ?>">
				<?php submit_button( __( 'Filter', 'reticula-academica' ), '', '', false ); ?>
			</form>
			<p>
				<?php
				/* translators: %d: number of documents. */
				echo esc_html( sprintf( _n( '%d document', '%d documents', $total, 'reticula-academica' ), $total ) );
				?>
			</p>
			<?php self::history_table( $rows ); ?>
			<?php
			$pages = (int) ceil( $total / 50 );
			if ( $pages > 1 ) {
				echo '<p>' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'current' => $paged, 'total' => $pages ) ) ) . '</p>';
			}
			?>
		</div>
		<?php
	}
}
