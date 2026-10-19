<?php
/**
 * Program (carrera) and term (tetramestre) post types and their edit screens.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Post_Types {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . Curriculum::PROGRAM, array( __CLASS__, 'save_program' ) );
		add_action( 'save_post_' . Curriculum::TERM, array( __CLASS__, 'save_term' ) );
		add_filter( 'manage_' . Curriculum::PROGRAM . '_posts_columns', array( __CLASS__, 'program_columns' ) );
		add_action( 'manage_' . Curriculum::PROGRAM . '_posts_custom_column', array( __CLASS__, 'program_column' ), 10, 2 );
		add_filter( 'manage_' . Curriculum::TERM . '_posts_columns', array( __CLASS__, 'term_columns' ) );
		add_action( 'manage_' . Curriculum::TERM . '_posts_custom_column', array( __CLASS__, 'term_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'term_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'term_query' ) );
	}

	public static function capability() {
		return apply_filters( 'ra_manage_capability', 'manage_options' );
	}

	public static function register() {
		$cap  = self::capability();
		$caps = array(
			'edit_post'              => $cap,
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
		);

		register_post_type(
			Curriculum::PROGRAM,
			array(
				'labels'       => array(
					'name'          => __( 'Programs', 'reticula-academica' ),
					'singular_name' => __( 'Program', 'reticula-academica' ),
					'menu_name'     => __( 'Academic Grid', 'reticula-academica' ),
					'all_items'     => __( 'Programs', 'reticula-academica' ),
					'add_new'       => __( 'Add program', 'reticula-academica' ),
					'add_new_item'  => __( 'Add program', 'reticula-academica' ),
					'edit_item'     => __( 'Edit program', 'reticula-academica' ),
					'view_item'     => __( 'View program', 'reticula-academica' ),
					'search_items'  => __( 'Search programs', 'reticula-academica' ),
					'not_found'     => __( 'No programs found.', 'reticula-academica' ),
				),
				'public'       => true,
				'has_archive'  => _x( 'programs', 'URL slug', 'reticula-academica' ),
				'rewrite'      => array( 'slug' => _x( 'program', 'URL slug', 'reticula-academica' ) ),
				'menu_icon'    => 'dashicons-welcome-learn-more',
				'menu_position' => 56,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
				'show_in_rest' => true,
				'map_meta_cap' => false,
				'capabilities' => $caps + array( 'read_post' => 'read' ),
			)
		);

		register_post_type(
			Curriculum::TERM,
			array(
				'labels'       => array(
					'name'          => __( 'Terms', 'reticula-academica' ),
					'singular_name' => __( 'Term', 'reticula-academica' ),
					'all_items'     => __( 'Terms', 'reticula-academica' ),
					'add_new'       => __( 'Add term', 'reticula-academica' ),
					'add_new_item'  => __( 'Add term', 'reticula-academica' ),
					'edit_item'     => __( 'Edit term', 'reticula-academica' ),
					'search_items'  => __( 'Search terms', 'reticula-academica' ),
					'not_found'     => __( 'No terms found.', 'reticula-academica' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . Curriculum::PROGRAM,
				'supports'     => array( 'title', 'editor' ),
				'map_meta_cap' => false,
				'capabilities' => $caps + array( 'read_post' => $cap ),
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
	}

	public static function meta_boxes() {
		add_meta_box( 'ra-program-settings', __( 'Enrollment and fees', 'reticula-academica' ), array( __CLASS__, 'render_program_settings' ), Curriculum::PROGRAM, 'side' );
		add_meta_box( 'ra-program-official', __( 'Official data (RVOE)', 'reticula-academica' ), array( __CLASS__, 'render_program_official' ), Curriculum::PROGRAM, 'side' );
		add_meta_box( 'ra-program-terms', __( 'Terms of this program', 'reticula-academica' ), array( __CLASS__, 'render_program_terms' ), Curriculum::PROGRAM, 'normal', 'high' );
		add_meta_box( 'ra-term-settings', __( 'Term', 'reticula-academica' ), array( __CLASS__, 'render_term_settings' ), Curriculum::TERM, 'side', 'high' );
		add_meta_box( 'ra-term-courses', __( 'Courses (in the order they should be taken)', 'reticula-academica' ), array( __CLASS__, 'render_term_courses' ), Curriculum::TERM, 'normal', 'high' );
	}

	/**
	 * Select listing Tutor courses.
	 */
	private static function course_select( $name, $selected, $placeholder, $id = '' ) {
		printf( '<select name="%1$s" id="%2$s" class="ra-course-select" style="width:100%%">', esc_attr( $name ), esc_attr( $id ? $id : $name ) );
		printf( '<option value="0">%s</option>', esc_html( $placeholder ) );
		foreach ( Curriculum::all_courses() as $course ) {
			$status = 'publish' === $course->post_status ? '' : ' (' . $course->post_status . ')';
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $course->ID, selected( (int) $selected, (int) $course->ID, false ), esc_html( get_the_title( $course ) . $status . ' #' . $course->ID ) );
		}
		echo '</select>';
	}

	public static function render_program_settings( $post ) {
		wp_nonce_field( 'ra_save_program', 'ra_program_nonce' );
		$settings = Curriculum::program_settings( $post->ID );
		?>
		<p>
			<label for="ra_fee_course"><strong><?php esc_html_e( 'Inscription fee', 'reticula-academica' ); ?></strong></label>
			<?php self::course_select( 'ra_fee_course', $settings['fee_course'], __( '— No inscription fee —', 'reticula-academica' ) ); ?>
			<span class="description"><?php esc_html_e( 'A paid Tutor course that represents the inscription. When the student buys it (with Tutor checkout), the inscription becomes active.', 'reticula-academica' ); ?></span>
		</p>
		<p>
			<label for="ra_mode"><strong><?php esc_html_e( 'Course order', 'reticula-academica' ); ?></strong></label>
			<select name="ra_mode" id="ra_mode" style="width:100%">
				<option value="advisory" <?php selected( $settings['mode'], 'advisory' ); ?>><?php esc_html_e( 'Recommended only (no restrictions)', 'reticula-academica' ); ?></option>
				<option value="term" <?php selected( $settings['mode'], 'term' ); ?>><?php esc_html_e( 'By term (future terms locked)', 'reticula-academica' ); ?></option>
				<option value="strict" <?php selected( $settings['mode'], 'strict' ); ?>><?php esc_html_e( 'Strict (finish each course before the next)', 'reticula-academica' ); ?></option>
			</select>
		</p>
		<p>
			<label><input type="checkbox" name="ra_self_enroll" value="1" <?php checked( $settings['self_enroll'] ); ?>> <?php esc_html_e( 'Students can enroll themselves', 'reticula-academica' ); ?></label>
		</p>
		<?php
	}

	public static function official_fields() {
		return array(
			'_ra_rvoe'      => array( __( 'RVOE number', 'reticula-academica' ), 'text', '' ),
			'_ra_rvoe_date' => array( __( 'RVOE date', 'reticula-academica' ), 'date', '' ),
			'_ra_code'      => array( __( 'Program code', 'reticula-academica' ), 'text', '' ),
			'_ra_cct'       => array( __( 'CCT (work center code)', 'reticula-academica' ), 'text', '' ),
			'_ra_level'     => array( __( 'Level', 'reticula-academica' ), 'text', 'Licenciatura' ),
			'_ra_modality'  => array( __( 'Modality', 'reticula-academica' ), 'text', 'No escolarizada' ),
		);
	}

	public static function render_program_official( $post ) {
		foreach ( self::official_fields() as $key => $field ) {
			$value = (string) get_post_meta( $post->ID, $key, true );
			printf(
				'<p><label><strong>%1$s</strong><br><input type="%2$s" name="ra_official[%3$s]" value="%4$s" placeholder="%5$s" style="width:100%%"></label></p>',
				esc_html( $field[0] ),
				esc_attr( $field[1] ),
				esc_attr( $key ),
				esc_attr( $value ),
				esc_attr( $field[2] )
			);
		}
		echo '<p class="description">' . esc_html__( 'Used in documents: {rvoe}, {rvoe_fecha}, {clave_carrera}, {cct}, {nivel}, {modalidad}.', 'reticula-academica' ) . '</p>';
	}

	public static function render_program_terms( $post ) {
		$terms = Curriculum::terms( $post->ID, true );
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the program first, then add its terms.', 'reticula-academica' ) . '</p>';
			return;
		}
		if ( ! $terms ) {
			echo '<p>' . esc_html__( 'This program has no terms yet.', 'reticula-academica' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Term', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Courses', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Term fee', 'reticula-academica' ) . '</th></tr></thead><tbody>';
			foreach ( $terms as $term ) {
				$titles = array_map(
					static function ( $id ) {
						return esc_html( get_the_title( $id ) );
					},
					$term['courses']
				);
				printf(
					'<tr><td>%1$d</td><td><a href="%2$s"><strong>%3$s</strong></a></td><td>%4$s</td><td>%5$s</td></tr>',
					(int) $term['number'],
					esc_url( get_edit_post_link( $term['id'] ) ),
					esc_html( $term['title'] ),
					$titles ? '<ol style="margin:0 0 0 1.2em">' . implode( '', array_map( static fn( $t ) => '<li>' . $t . '</li>', $titles ) ) . '</ol>' : '&mdash;', // phpcs:ignore WordPress.Security.EscapeOutput
					$term['fee_course'] ? esc_html( get_the_title( $term['fee_course'] ) ) : '&mdash;'
				);
			}
			echo '</tbody></table>';
		}
		printf(
			'<p><a class="button button-primary" href="%s">%s</a></p>',
			esc_url( admin_url( 'post-new.php?post_type=' . Curriculum::TERM . '&ra_program=' . $post->ID ) ),
			esc_html__( 'Add term', 'reticula-academica' )
		);
	}

	public static function render_term_settings( $post ) {
		wp_nonce_field( 'ra_save_term', 'ra_term_nonce' );
		$program_id = $post->post_parent ? $post->post_parent : ( isset( $_GET['ra_program'] ) ? absint( $_GET['ra_program'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$number     = $post->menu_order;
		if ( ! $number && $program_id ) {
			$number = count( Curriculum::terms( $program_id, true ) ) + 1;
		}
		?>
		<p>
			<label for="ra_program"><strong><?php esc_html_e( 'Program', 'reticula-academica' ); ?></strong></label>
			<select name="ra_program" id="ra_program" style="width:100%" required>
				<option value=""><?php esc_html_e( '— Select —', 'reticula-academica' ); ?></option>
				<?php foreach ( Curriculum::programs( true ) as $program ) : ?>
					<option value="<?php echo esc_attr( $program->ID ); ?>" <?php selected( $program_id, $program->ID ); ?>><?php echo esc_html( get_the_title( $program ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="ra_number"><strong><?php esc_html_e( 'Term number', 'reticula-academica' ); ?></strong></label>
			<input type="number" min="1" max="99" name="ra_number" id="ra_number" value="<?php echo esc_attr( max( 1, (int) $number ) ); ?>" class="small-text">
		</p>
		<p>
			<label for="ra_term_fee"><strong><?php esc_html_e( 'Term fee (optional)', 'reticula-academica' ); ?></strong></label>
			<?php self::course_select( 'ra_term_fee', get_post_meta( $post->ID, '_ra_fee_course', true ), __( '— No fee —', 'reticula-academica' ), 'ra_term_fee' ); ?>
			<span class="description"><?php esc_html_e( 'Paid Tutor course (e.g. re-enrollment). The courses of this term stay locked until the student buys it.', 'reticula-academica' ); ?></span>
		</p>
		<?php
	}

	public static function render_term_courses( $post ) {
		$courses = Curriculum::term_courses( $post->ID );
		?>
		<ol id="ra-course-list" class="ra-course-list">
			<?php foreach ( $courses as $course_id ) : ?>
				<li class="ra-course-item" data-id="<?php echo esc_attr( $course_id ); ?>">
					<span class="dashicons dashicons-menu ra-handle" aria-hidden="true"></span>
					<input type="text" class="ra-course-code" name="ra_codes[<?php echo esc_attr( $course_id ); ?>]" value="<?php echo esc_attr( Curriculum::course_code( $course_id ) ); ?>" placeholder="<?php esc_attr_e( 'Code', 'reticula-academica' ); ?>" aria-label="<?php esc_attr_e( 'Course code', 'reticula-academica' ); ?>">
					<span class="ra-course-title"><?php echo esc_html( get_the_title( $course_id ) . ' #' . $course_id ); ?></span>
					<input type="hidden" name="ra_courses[]" value="<?php echo esc_attr( $course_id ); ?>">
					<button type="button" class="button-link ra-remove" aria-label="<?php esc_attr_e( 'Remove', 'reticula-academica' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="ra-empty" <?php echo $courses ? 'hidden' : ''; ?>><?php esc_html_e( 'No courses yet.', 'reticula-academica' ); ?></p>
		<div class="ra-add-course">
			<?php self::course_select( 'ra_add_course', 0, __( '— Choose a Tutor course —', 'reticula-academica' ), 'ra-add-course' ); ?>
			<button type="button" class="button" id="ra-add-course-btn"><?php esc_html_e( 'Add course', 'reticula-academica' ); ?></button>
		</div>
		<input type="hidden" name="ra_courses_present" value="1">
		<p class="description"><?php esc_html_e( 'Drag to reorder. Students see this order as the path to follow, and the program setting decides whether it is enforced.', 'reticula-academica' ); ?></p>
		<p class="description"><?php esc_html_e( 'Code: the official key of each course (e.g. MAT-101). It belongs to the course, so it is the same in every program, and it is printed in report cards.', 'reticula-academica' ); ?></p>
		<?php
	}

	private static function can_save( $post_id, $nonce_field, $action ) {
		return isset( $_POST[ $nonce_field ] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $action )
			&& ! wp_is_post_autosave( $post_id )
			&& ! wp_is_post_revision( $post_id )
			&& current_user_can( self::capability() );
	}

	public static function save_program( $post_id ) {
		if ( ! self::can_save( $post_id, 'ra_program_nonce', 'ra_save_program' ) ) {
			return;
		}
		$mode = isset( $_POST['ra_mode'] ) ? sanitize_key( $_POST['ra_mode'] ) : Progress::MODE_TERM;
		update_post_meta( $post_id, '_ra_fee_course', isset( $_POST['ra_fee_course'] ) ? absint( $_POST['ra_fee_course'] ) : 0 );
		update_post_meta( $post_id, '_ra_mode', in_array( $mode, array( Progress::MODE_ADVISORY, Progress::MODE_TERM, Progress::MODE_STRICT ), true ) ? $mode : Progress::MODE_TERM );
		update_post_meta( $post_id, '_ra_self_enroll', empty( $_POST['ra_self_enroll'] ) ? '0' : '1' );
		$official = isset( $_POST['ra_official'] ) ? (array) wp_unslash( $_POST['ra_official'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( array_keys( self::official_fields() ) as $key ) {
			if ( isset( $official[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( $official[ $key ] ) );
			}
		}
	}

	public static function save_term( $post_id ) {
		if ( ! self::can_save( $post_id, 'ra_term_nonce', 'ra_save_term' ) ) {
			return;
		}
		$program = isset( $_POST['ra_program'] ) ? absint( $_POST['ra_program'] ) : 0;
		$number  = isset( $_POST['ra_number'] ) ? max( 1, absint( $_POST['ra_number'] ) ) : 1;
		if ( $program && get_post_type( $program ) !== Curriculum::PROGRAM ) {
			$program = 0;
		}

		// Update parent/order without re-triggering this hook.
		remove_action( 'save_post_' . Curriculum::TERM, array( __CLASS__, 'save_term' ) );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_parent' => $program,
				'menu_order'  => $number,
			)
		);
		add_action( 'save_post_' . Curriculum::TERM, array( __CLASS__, 'save_term' ) );

		update_post_meta( $post_id, '_ra_fee_course', isset( $_POST['ra_term_fee'] ) ? absint( $_POST['ra_term_fee'] ) : 0 );
		if ( ! empty( $_POST['ra_courses_present'] ) ) {
			$courses = isset( $_POST['ra_courses'] ) ? array_map( 'absint', (array) $_POST['ra_courses'] ) : array();
			$courses = array_values( array_unique( array_filter( $courses ) ) );
			update_post_meta( $post_id, '_ra_courses', $courses );
			$codes = isset( $_POST['ra_codes'] ) ? (array) wp_unslash( $_POST['ra_codes'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			foreach ( $courses as $course_id ) {
				if ( isset( $codes[ $course_id ] ) ) {
					update_post_meta( $course_id, Curriculum::CODE_META, strtoupper( sanitize_text_field( $codes[ $course_id ] ) ) );
				}
			}
		}
		Curriculum::flush_cache();
	}

	public static function program_columns( $columns ) {
		$date = $columns['date'];
		unset( $columns['date'] );
		$columns['ra_rvoe']     = __( 'RVOE', 'reticula-academica' );
		$columns['ra_terms']    = __( 'Terms', 'reticula-academica' );
		$columns['ra_courses']  = __( 'Courses', 'reticula-academica' );
		$columns['ra_students'] = __( 'Students', 'reticula-academica' );
		$columns['ra_mode']     = __( 'Course order', 'reticula-academica' );
		$columns['date']        = $date;
		return $columns;
	}

	public static function program_column( $column, $post_id ) {
		$terms = Curriculum::terms( $post_id, true );
		switch ( $column ) {
			case 'ra_rvoe':
				$rvoe = (string) get_post_meta( $post_id, '_ra_rvoe', true );
				$code = (string) get_post_meta( $post_id, '_ra_code', true );
				echo '' !== $rvoe ? '<code>' . esc_html( $rvoe ) . '</code>' : '<span style="color:#b32d2e">' . esc_html__( 'Missing', 'reticula-academica' ) . '</span>';
				echo '' !== $code ? '<br><span class="description">' . esc_html( $code ) . '</span>' : '';
				break;
			case 'ra_terms':
				echo (int) count( $terms );
				break;
			case 'ra_courses':
				echo (int) array_sum( array_map( 'count', wp_list_pluck( $terms, 'courses' ) ) );
				break;
			case 'ra_students':
				list( , $total ) = Inscriptions::query( array( 'program_id' => $post_id ), 1 );
				printf( '<a href="%s">%d</a>', esc_url( admin_url( 'admin.php?page=' . Admin::PAGE . '&program_id=' . $post_id ) ), (int) $total );
				break;
			case 'ra_mode':
				$labels = array(
					'advisory' => __( 'Recommended', 'reticula-academica' ),
					'term'     => __( 'By term', 'reticula-academica' ),
					'strict'   => __( 'Strict', 'reticula-academica' ),
				);
				echo esc_html( $labels[ Curriculum::program_settings( $post_id )['mode'] ] );
				break;
		}
	}

	public static function term_columns( $columns ) {
		$date = $columns['date'];
		unset( $columns['date'] );
		$columns['ra_program'] = __( 'Program', 'reticula-academica' );
		$columns['ra_number']  = '#';
		$columns['ra_courses'] = __( 'Courses', 'reticula-academica' );
		$columns['date']       = $date;
		return $columns;
	}

	public static function term_column( $column, $post_id ) {
		$post = get_post( $post_id );
		switch ( $column ) {
			case 'ra_program':
				echo $post->post_parent ? '<a href="' . esc_url( get_edit_post_link( $post->post_parent ) ) . '">' . esc_html( get_the_title( $post->post_parent ) ) . '</a>' : '&mdash;';
				break;
			case 'ra_number':
				echo (int) $post->menu_order;
				break;
			case 'ra_courses':
				echo (int) count( Curriculum::term_courses( $post_id ) );
				break;
		}
	}

	public static function term_filter( $post_type ) {
		if ( Curriculum::TERM !== $post_type ) {
			return;
		}
		$current = isset( $_GET['ra_program'] ) ? absint( $_GET['ra_program'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<select name="ra_program"><option value="0">' . esc_html__( 'All programs', 'reticula-academica' ) . '</option>';
		foreach ( Curriculum::programs( true ) as $program ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $program->ID, selected( $current, $program->ID, false ), esc_html( get_the_title( $program ) ) );
		}
		echo '</select>';
	}

	public static function term_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || Curriculum::TERM !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( ! empty( $_GET['ra_program'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query->set( 'post_parent', absint( $_GET['ra_program'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! $query->get( 'orderby' ) ) {
			$query->set( 'orderby', array( 'parent' => 'ASC', 'menu_order' => 'ASC' ) );
		}
	}
}
