<?php
/**
 * General data of the student (datos generales): configurable fields, the first-login step that asks for them,
 * and the welcome flow after paying the inscription (account → data → documents → program).
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Profile {

	const OPTION = 'ra_profile_fields';
	const META   = 'ra_pf_';
	const DONE   = '_ra_profile_done';
	const TYPES  = array( 'text', 'textarea', 'date', 'select', 'tel', 'email', 'number' );

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'template_redirect', array( __CLASS__, 'gate' ), 6 );
		add_action( 'admin_post_ra_save_profile', array( __CLASS__, 'handle_save' ) );
		add_filter( 'ra_document_vars', array( __CLASS__, 'document_vars' ), 10, 2 );
		add_shortcode( 'reticula_mis_datos', array( __CLASS__, 'render_form' ) );
	}

	public static function default_fields() {
		$f = static function ( $key, $label, $type = 'text', $required = true, $options = '' ) {
			return compact( 'key', 'label', 'type', 'required', 'options' );
		};
		return array(
			$f( 'nombre', __( 'First name(s)', 'reticula-academica' ) ),
			$f( 'apellido_paterno', __( 'First last name', 'reticula-academica' ) ),
			$f( 'apellido_materno', __( 'Second last name', 'reticula-academica' ), 'text', false ),
			$f( 'curp', 'CURP' ),
			$f( 'fecha_nacimiento', __( 'Date of birth', 'reticula-academica' ), 'date' ),
			$f( 'sexo', __( 'Sex', 'reticula-academica' ), 'select', true, __( 'Female, Male', 'reticula-academica' ) ),
			$f( 'telefono', __( 'Phone', 'reticula-academica' ), 'tel' ),
			$f( 'direccion', __( 'Address', 'reticula-academica' ), 'textarea' ),
			$f( 'ciudad_estado', __( 'City and state', 'reticula-academica' ) ),
			$f( 'codigo_postal', __( 'Postal code', 'reticula-academica' ) ),
			$f( 'escuela_procedencia', __( 'Previous school', 'reticula-academica' ) ),
			$f( 'contacto_emergencia', __( 'Emergency contact', 'reticula-academica' ) ),
			$f( 'telefono_emergencia', __( 'Emergency phone', 'reticula-academica' ), 'tel' ),
		);
	}

	/**
	 * @return array[] key, label, type, required, options.
	 */
	public static function fields() {
		$saved = get_option( self::OPTION, null );
		return is_array( $saved ) ? $saved : self::default_fields();
	}

	public static function register_setting() {
		register_setting(
			'ra_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_fields' ),
			)
		);
	}

	/**
	 * Fields from the settings table. Keys of existing fields never change, so saved data stays linked.
	 */
	public static function sanitize_fields( $input ) {
		$out  = array();
		$seen = array();
		foreach ( is_array( $input ) ? $input : array() as $row ) {
			$label = isset( $row['label'] ) ? sanitize_text_field( wp_unslash( $row['label'] ) ) : '';
			if ( '' === $label || ! empty( $row['remove'] ) ) {
				continue;
			}
			$key = isset( $row['key'] ) && '' !== $row['key'] ? sanitize_key( $row['key'] ) : substr( str_replace( '-', '_', sanitize_title( remove_accents( $label ) ) ), 0, 40 );
			$key = '' !== $key ? $key : 'campo';
			$base = $key;
			for ( $i = 2; isset( $seen[ $key ] ); $i++ ) {
				$key = $base . '_' . $i;
			}
			$seen[ $key ] = true;
			$type         = isset( $row['type'] ) && in_array( $row['type'], self::TYPES, true ) ? $row['type'] : 'text';
			$out[]        = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $row['required'] ),
				'options'  => isset( $row['options'] ) ? sanitize_text_field( wp_unslash( $row['options'] ) ) : '',
			);
		}
		return $out;
	}

	public static function options( array $field ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $field['options'] ) ), 'strlen' ) );
	}

	public static function value( $user_id, $key ) {
		return (string) get_user_meta( $user_id, self::META . $key, true );
	}

	/**
	 * Required fields still empty.
	 *
	 * @return string[] Labels.
	 */
	public static function missing( $user_id ) {
		$missing = array();
		foreach ( self::fields() as $field ) {
			if ( $field['required'] && '' === trim( self::value( $user_id, $field['key'] ) ) ) {
				$missing[] = $field['label'];
			}
		}
		return $missing;
	}

	/**
	 * Students (with an inscription) must fill in their data before using the platform.
	 */
	public static function must_complete( $user_id ) {
		if ( ! $user_id || ! Settings::get( 'profile_required' ) || user_can( $user_id, Post_Types::capability() ) || ! Inscriptions::for_user( $user_id ) ) {
			return false;
		}
		return (bool) self::missing( $user_id );
	}

	public static function form_url() {
		return Tutor::active() ? Tutor::dashboard_url( Frontend::PROFILE_SLUG ) : home_url( '/' );
	}

	/**
	 * Step 1: send students with missing data to "My data" (payments and their checkout stay reachable).
	 */
	public static function gate() {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! Tutor::active() || ! self::must_complete( $user_id ) ) {
			return;
		}
		$tab        = (string) get_query_var( 'tutor_dashboard_page' );
		$dashboard  = method_exists( tutor_utils(), 'is_tutor_frontend_dashboard' ) && tutor_utils()->is_tutor_frontend_dashboard();
		$is_content = is_singular( Tutor::content_post_types() );
		if ( ( $dashboard && ! in_array( $tab, array( Frontend::PROFILE_SLUG, Frontend::PAYMENTS_SLUG, 'logout', 'settings' ), true ) ) || $is_content ) {
			wp_safe_redirect( add_query_arg( array( 'ra_welcome' => 1, 'ra_t' => time() ), self::form_url() ) );
			exit;
		}
	}

	/**
	 * Save the form (the student's own data, or any student's for staff).
	 */
	public static function handle_save() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		check_admin_referer( 'ra_save_profile' );
		$staff   = current_user_can( Post_Types::capability() );
		$user_id = ( $staff && ! empty( $_POST['user'] ) ) ? absint( $_POST['user'] ) : get_current_user_id();
		$input   = isset( $_POST['ra_pf'] ) && is_array( $_POST['ra_pf'] ) ? wp_unslash( $_POST['ra_pf'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.
		$errors  = array();
		$clean   = array();
		foreach ( self::fields() as $field ) {
			$raw   = isset( $input[ $field['key'] ] ) ? (string) $input[ $field['key'] ] : '';
			$value = 'textarea' === $field['type'] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
			$value = trim( $value );
			if ( '' !== $value ) {
				if ( 'email' === $field['type'] && ! is_email( $value ) ) {
					/* translators: %s: field label. */
					$errors[] = sprintf( __( '%s is not a valid email.', 'reticula-academica' ), $field['label'] );
				} elseif ( 'date' === $field['type'] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
					/* translators: %s: field label. */
					$errors[] = sprintf( __( '%s is not a valid date.', 'reticula-academica' ), $field['label'] );
				} elseif ( 'select' === $field['type'] && ! in_array( $value, self::options( $field ), true ) ) {
					$value = '';
				} elseif ( 'curp' === $field['key'] ) {
					$value = strtoupper( $value );
					if ( ! preg_match( '/^[A-Z][AEIOUX][A-Z]{2}\d{6}[HMX][A-Z]{2}[B-DF-HJ-NP-TV-Z]{3}[A-Z0-9]\d$/', $value ) ) {
						$errors[] = __( 'The CURP does not look right: it has 18 characters (e.g. GAPA850101MDFRRN09).', 'reticula-academica' );
					}
				}
			}
			if ( $field['required'] && '' === $value && ! $staff ) {
				/* translators: %s: field label. */
				$errors[] = sprintf( __( '%s is required.', 'reticula-academica' ), $field['label'] );
			}
			$clean[ $field['key'] ] = $value;
		}
		$back = wp_get_referer() ? wp_get_referer() : self::form_url();
		if ( $errors && $staff && get_current_user_id() !== $user_id ) {
			set_transient( 'ra_notice_' . get_current_user_id(), array( 'error', esc_html( implode( ' ', $errors ) ) ), 120 );
			wp_safe_redirect( $back );
			exit;
		}
		if ( $errors ) {
			set_transient( 'ra_profile_' . get_current_user_id(), array( 'errors' => $errors, 'values' => $clean ), 300 );
			wp_safe_redirect( add_query_arg( array( 'ra_t' => time() ), remove_query_arg( array( 'ra_saved', 'ra_t' ), $back ) ) . '#ra-profile' );
			exit;
		}
		foreach ( $clean as $key => $value ) {
			update_user_meta( $user_id, self::META . $key, $value );
		}
		self::sync_user_names( $user_id );
		do_action( 'ra_profile_saved', $user_id, $clean );

		if ( ! $staff && ! get_user_meta( $user_id, self::DONE, true ) ) {
			update_user_meta( $user_id, self::DONE, time() );
			// Step 2: documents (optional at this point).
			$file = Records::file_of( $user_id );
			if ( $file['items'] && ! $file['summary']['complete'] ) {
				wp_safe_redirect( add_query_arg( array( 'ra_step' => 2, 'ra_t' => time() ), Records::my_file_url() ) );
				exit;
			}
			wp_safe_redirect( add_query_arg( 'ra_t', time(), Inscriptions::my_program_url() ) );
			exit;
		}
		if ( $staff && get_current_user_id() !== $user_id ) {
			set_transient( 'ra_notice_' . get_current_user_id(), array( 'success', esc_html__( 'General data saved.', 'reticula-academica' ) ), 120 );
		}
		wp_safe_redirect( add_query_arg( array( 'ra_saved' => 1, 'ra_t' => time() ), remove_query_arg( array( 'ra_saved', 'ra_t', 'ra_welcome' ), $back ) ) . '#ra-profile' );
		exit;
	}

	/**
	 * Names used by WordPress, Tutor and the documents follow the general data.
	 */
	public static function sync_user_names( $user_id ) {
		$first = self::value( $user_id, 'nombre' );
		$last  = trim( self::value( $user_id, 'apellido_paterno' ) . ' ' . self::value( $user_id, 'apellido_materno' ) );
		if ( '' === $first && '' === $last ) {
			return;
		}
		$user = get_userdata( $user_id );
		$data = array( 'ID' => $user_id );
		if ( '' !== $first ) {
			$data['first_name'] = $first;
		}
		if ( '' !== $last ) {
			$data['last_name'] = $last;
		}
		$full = trim( $first . ' ' . $last );
		if ( $user && '' !== $full && in_array( $user->display_name, array( $user->user_login, $user->user_email, $user->user_nicename, '' ), true ) ) {
			$data['display_name'] = $full;
		}
		wp_update_user( $data );
	}

	/**
	 * Every field is available in document templates as {key} (e.g. {curp}, {fecha_nacimiento}).
	 */
	public static function document_vars( $vars, $user_id ) {
		foreach ( self::fields() as $field ) {
			if ( ! isset( $vars[ $field['key'] ] ) ) {
				$vars[ $field['key'] ] = esc_html( self::value( $user_id, $field['key'] ) );
			}
		}
		return $vars;
	}

	/**
	 * The fields, for the student form and the staff panel.
	 */
	public static function fields_html( $user_id, array $values = array() ) {
		$html = '<div class="ra-profile-fields">';
		foreach ( self::fields() as $field ) {
			$key   = $field['key'];
			$value = array_key_exists( $key, $values ) ? $values[ $key ] : self::value( $user_id, $key );
			$id    = 'ra-pf-' . $key;
			$name  = 'ra_pf[' . $key . ']';
			$req   = $field['required'] ? ' required' : '';
			$html .= '<p class="ra-field ra-field-' . esc_attr( $field['type'] ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . ( $field['required'] ? ' <span class="ra-required" aria-hidden="true">*</span>' : '' ) . '</label>';
			if ( 'textarea' === $field['type'] ) {
				$html .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3"' . $req . '>' . esc_textarea( $value ) . '</textarea>';
			} elseif ( 'select' === $field['type'] ) {
				$html .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req . '><option value="">' . esc_html__( '— Select —', 'reticula-academica' ) . '</option>';
				foreach ( self::options( $field ) as $option ) {
					$html .= '<option value="' . esc_attr( $option ) . '"' . selected( $value, $option, false ) . '>' . esc_html( $option ) . '</option>';
				}
				$html .= '</select>';
			} else {
				$extra = 'curp' === $key ? ' maxlength="18" style="text-transform:uppercase" autocomplete="off"' : '';
				$html .= '<input type="' . esc_attr( $field['type'] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $req . $extra . '>';
			}
			$html .= '</p>';
		}
		return $html . '</div>';
	}

	public static function render_form() {
		Frontend::no_cache();
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Log in to see your data.', 'reticula-academica' ) . '</p>';
		}
		$user_id = get_current_user_id();
		$flash   = get_transient( 'ra_profile_' . $user_id );
		if ( $flash ) {
			delete_transient( 'ra_profile_' . $user_id );
		}
		$first = ! get_user_meta( $user_id, self::DONE, true ) && self::missing( $user_id );
		ob_start();
		echo '<div class="ra-program ra-documents ra-profile" id="ra-profile">';
		echo '<header class="ra-program-header"><div><p class="ra-eyebrow">' . esc_html__( 'My data', 'reticula-academica' ) . '</p>';
		$matricula = Student_Id::get( $user_id );
		if ( $matricula ) {
			/* translators: %s: student ID. */
			echo '<p class="ra-meta">' . esc_html( sprintf( __( 'Student ID: %s', 'reticula-academica' ), $matricula ) ) . '</p>';
		}
		echo '</div></header>';
		if ( $first ) {
			echo '<div class="ra-next ra-welcome"><div><p class="ra-next-label">' . esc_html__( 'Welcome! Step 1 of 2', 'reticula-academica' ) . '</p><p class="ra-next-title">' . esc_html__( 'Fill in your general data to start', 'reticula-academica' ) . '</p><p class="ra-lock">' . esc_html__( 'Then you can upload the documents of your file.', 'reticula-academica' ) . '</p></div></div>';
		}
		if ( $flash && ! empty( $flash['errors'] ) ) {
			echo '<div class="ra-rec-notice is-error" role="alert"><ul>';
			foreach ( $flash['errors'] as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		} elseif ( ! empty( $_GET['ra_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<p class="ra-rec-notice is-success" role="status">' . esc_html__( 'Your data was saved.', 'reticula-academica' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ra-term ra-profile-form">';
		wp_nonce_field( 'ra_save_profile' );
		echo '<input type="hidden" name="action" value="ra_save_profile">';
		echo self::fields_html( $user_id, $flash && isset( $flash['values'] ) ? $flash['values'] : array() ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><button type="submit" class="ra-btn ra-btn-primary">' . ( $first ? esc_html__( 'Save and continue', 'reticula-academica' ) : esc_html__( 'Save', 'reticula-academica' ) ) . '</button></p>';
		echo '</form></div>';
		return (string) ob_get_clean();
	}

	/**
	 * Staff panel in the student screen.
	 */
	public static function render_admin_panel( $user_id ) {
		$missing = self::missing( $user_id );
		echo '<p>' . ( $missing ? esc_html( sprintf( /* translators: %s: list of fields. */ __( 'Missing: %s', 'reticula-academica' ), implode( ', ', $missing ) ) ) : esc_html__( 'Complete.', 'reticula-academica' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ra-profile-admin">';
		wp_nonce_field( 'ra_save_profile' );
		echo '<input type="hidden" name="action" value="ra_save_profile"><input type="hidden" name="user" value="' . esc_attr( $user_id ) . '">';
		echo str_replace( ' required', '', self::fields_html( $user_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		submit_button( __( 'Save general data', 'reticula-academica' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Settings table to edit the fields.
	 */
	public static function render_settings() {
		$types = array(
			'text'     => __( 'Text', 'reticula-academica' ),
			'textarea' => __( 'Long text', 'reticula-academica' ),
			'date'     => __( 'Date', 'reticula-academica' ),
			'select'   => __( 'List of options', 'reticula-academica' ),
			'tel'      => __( 'Phone', 'reticula-academica' ),
			'email'    => __( 'Email', 'reticula-academica' ),
			'number'   => __( 'Number', 'reticula-academica' ),
		);
		$rows   = self::fields();
		$rows[] = array( 'key' => '', 'label' => '', 'type' => 'text', 'required' => false, 'options' => '' );
		$n      = self::OPTION;
		echo '<table class="widefat ra-profile-settings"><thead><tr><th>' . esc_html__( 'Field', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Type', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Options (comma separated)', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Required', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Remove', 'reticula-academica' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $i => $row ) {
			$p = $n . '[' . $i . ']';
			echo '<tr><td><input type="hidden" name="' . esc_attr( $p ) . '[key]" value="' . esc_attr( $row['key'] ) . '"><input type="text" class="regular-text" name="' . esc_attr( $p ) . '[label]" value="' . esc_attr( $row['label'] ) . '" placeholder="' . esc_attr__( 'New field…', 'reticula-academica' ) . '">';
			if ( $row['key'] ) {
				echo '<br><code>{' . esc_html( $row['key'] ) . '}</code>';
			}
			echo '</td><td><select name="' . esc_attr( $p ) . '[type]">';
			foreach ( $types as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '"' . selected( $row['type'], $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td><td><input type="text" name="' . esc_attr( $p ) . '[options]" value="' . esc_attr( $row['options'] ) . '"></td>';
			echo '<td><input type="checkbox" name="' . esc_attr( $p ) . '[required]" value="1"' . checked( ! empty( $row['required'] ), true, false ) . '></td>';
			echo '<td>' . ( $row['key'] ? '<input type="checkbox" name="' . esc_attr( $p ) . '[remove]" value="1">' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Fill in the empty row to add a field (save to get another empty row). The code in braces can be used in document templates. Removing a field hides it but keeps the data already captured.', 'reticula-academica' ) . '</p>';
	}
}
