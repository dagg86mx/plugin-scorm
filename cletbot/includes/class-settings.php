<?php
/**
 * Plugin options (one array in wp_options) and the CLETBOT → Ajustes screen.
 *
 * @package Cletbot
 */

namespace Cletbot;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'cletbot_settings';
	const PAGE   = 'cletbot-settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			'senders'          => '',
			'approvers'        => '',
			'default_category' => (int) get_option( 'default_category' ),
			'create_category'  => 0,
			'author'           => 0,
			'attach_original'  => 0,
			'preview_days'     => 7,
			'preview_buttons'  => 1,
			'ack'              => 1,

			'ai_key'           => '',
			'ai_model'         => AI::DEFAULT_MODEL,
			'ai_effort'        => 'medium',
			'ai_fallbacks'     => 1,
			'guidelines'       => '',

			'email_imap'       => 0,
			'imap_host'        => '',
			'imap_port'        => 993,
			'imap_secure'      => 'ssl',
			'imap_user'        => '',
			'imap_pass'        => '',
			'imap_folder'      => 'INBOX',
			'email_reply_to'   => '',
			'email_verify'     => 1,
			'email_key'        => '',

			'wa_phone_id'      => '',
			'wa_token'         => '',
			'wa_app_secret'    => '',
			'wa_verify_token'  => '',
			'wa_version'       => 'v21.0',
			'wa_group_seconds' => 20,
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
			$changed     = false;
			foreach ( array( 'email_key', 'wa_verify_token' ) as $secret ) {
				if ( '' === self::$cache[ $secret ] ) {
					self::$cache[ $secret ] = wp_generate_password( 32, false );
					$changed                = true;
				}
			}
			if ( $changed ) {
				update_option( self::OPTION, self::$cache, false );
			}
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function flush() {
		self::$cache = null;
	}

	public static function senders() {
		return Parser::parse_sender_list( self::get( 'senders' ) );
	}

	public static function approvers() {
		return Parser::parse_sender_list( self::get( 'approvers' ) );
	}

	/* ------------------------------------------------------------------ admin screen */

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_setting(
			'cletbot',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $input ) {
		$old = self::all();
		$in  = is_array( $input ) ? wp_unslash( $input ) : array();
		$out = $old;

		foreach ( array( 'senders', 'approvers', 'guidelines' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? sanitize_textarea_field( $in[ $k ] ) : '';
		}
		foreach ( array( 'ai_model', 'imap_host', 'imap_user', 'imap_folder', 'wa_phone_id', 'wa_version' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : '';
		}
		$out['email_reply_to'] = isset( $in['email_reply_to'] ) ? sanitize_email( $in['email_reply_to'] ) : '';
		foreach ( array( 'default_category', 'author', 'preview_days', 'imap_port', 'wa_group_seconds' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? absint( $in[ $k ] ) : 0;
		}
		$out['preview_days']     = max( 1, min( 90, $out['preview_days'] ) );
		$out['wa_group_seconds'] = min( 120, $out['wa_group_seconds'] );
		foreach ( array( 'create_category', 'attach_original', 'preview_buttons', 'ack', 'ai_fallbacks', 'email_imap', 'email_verify' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$out['ai_effort']   = isset( $in['ai_effort'] ) && in_array( $in['ai_effort'], array( 'low', 'medium', 'high', 'xhigh', 'max' ), true ) ? $in['ai_effort'] : 'medium';
		$out['imap_secure'] = isset( $in['imap_secure'] ) && in_array( $in['imap_secure'], array( 'ssl', 'tls', 'none' ), true ) ? $in['imap_secure'] : 'ssl';
		if ( '' === $out['ai_model'] ) {
			$out['ai_model'] = AI::DEFAULT_MODEL;
		}
		if ( '' === $out['wa_version'] ) {
			$out['wa_version'] = 'v21.0';
		}
		// Secrets: an empty field keeps the stored value, so they never have to be printed back into the form.
		foreach ( array( 'ai_key', 'imap_pass', 'wa_token', 'wa_app_secret' ) as $k ) {
			if ( isset( $in[ $k ] ) && '' !== trim( $in[ $k ] ) ) {
				$out[ $k ] = trim( $in[ $k ] );
			}
			if ( ! empty( $in[ $k . '_clear' ] ) ) {
				$out[ $k ] = '';
			}
		}
		foreach ( array( 'email_key', 'wa_verify_token' ) as $k ) {
			if ( ! empty( $in[ 'regen_' . $k ] ) ) {
				$out[ $k ] = wp_generate_password( 32, false );
			}
		}
		self::$cache = $out;
		Email::reschedule( $out );
		return $out;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = self::all();
		$name = self::OPTION;
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = array(
			'general'  => __( 'General', 'cletbot' ),
			'ai'       => __( 'Inteligencia artificial', 'cletbot' ),
			'email'    => __( 'Correo', 'cletbot' ),
			'whatsapp' => __( 'WhatsApp', 'cletbot' ),
			'log'      => __( 'Registro', 'cletbot' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		echo '<div class="wrap cletbot-settings"><h1>CLETBOT</h1>';
		self::status_box( $s );
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $key ) ),
				$tab === $key ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'log' === $tab ) {
			Log::render();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="options.php">';
		settings_fields( 'cletbot' );
		// Fields of the other tabs travel as hidden inputs so saving one tab doesn't wipe the rest.
		$visible = self::tab_fields( $tab );
		foreach ( $s as $k => $v ) {
			if ( in_array( $k, $visible, true ) || in_array( $k, array( 'ai_key', 'imap_pass', 'wa_token', 'wa_app_secret', 'email_key', 'wa_verify_token' ), true ) ) {
				continue;
			}
			printf( '<input type="hidden" name="%s[%s]" value="%s">', esc_attr( $name ), esc_attr( $k ), esc_attr( $v ) );
		}
		echo '<table class="form-table" role="presentation">';
		call_user_func( array( __CLASS__, 'tab_' . $tab ), $s, $name );
		echo '</table>';
		submit_button();
		echo '</form>';

		if ( 'email' === $tab && ! empty( $s['email_imap'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'cletbot_check_mail' );
			echo '<input type="hidden" name="action" value="cletbot_check_mail">';
			submit_button( __( 'Revisar el buzón ahora', 'cletbot' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	private static function tab_fields( $tab ) {
		$map = array(
			'general'  => array( 'senders', 'approvers', 'default_category', 'create_category', 'author', 'attach_original', 'preview_days', 'preview_buttons', 'ack' ),
			'ai'       => array( 'ai_model', 'ai_effort', 'ai_fallbacks', 'guidelines' ),
			'email'    => array( 'email_imap', 'imap_host', 'imap_port', 'imap_secure', 'imap_user', 'imap_folder', 'email_reply_to', 'email_verify' ),
			'whatsapp' => array( 'wa_phone_id', 'wa_version', 'wa_group_seconds' ),
		);
		return isset( $map[ $tab ] ) ? $map[ $tab ] : array();
	}

	private static function status_box( $s ) {
		$senders = self::senders();
		$items   = array(
			array( count( $senders['emails'] ) + count( $senders['phones'] ) > 0, __( 'Remitentes autorizados', 'cletbot' ) ),
			array( AI::enabled(), __( 'Claude (IA)', 'cletbot' ) ),
			array( Email::imap_ready() || ! empty( $s['email_key'] ), __( 'Correo', 'cletbot' ) ),
			array( WhatsApp::ready(), __( 'WhatsApp', 'cletbot' ) ),
		);
		echo '<p class="cletbot-status">';
		foreach ( $items as $item ) {
			printf( '<span class="%s">%s %s</span> ', $item[0] ? 'ok' : 'off', $item[0] ? '●' : '○', esc_html( $item[1] ) );
		}
		echo '</p>';
	}

	private static function row( $label, $html, $help = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function text( $name, $key, $value, $type = 'text', $class = 'regular-text' ) {
		return sprintf( '<input type="%s" class="%s" name="%s[%s]" value="%s" autocomplete="off">', esc_attr( $type ), esc_attr( $class ), esc_attr( $name ), esc_attr( $key ), esc_attr( $value ) );
	}

	private static function textarea( $name, $key, $value, $rows = 5 ) {
		return sprintf( '<textarea class="large-text code" rows="%d" name="%s[%s]">%s</textarea>', (int) $rows, esc_attr( $name ), esc_attr( $key ), esc_textarea( $value ) );
	}

	private static function check( $name, $key, $value, $label ) {
		return sprintf( '<label><input type="checkbox" name="%s[%s]" value="1"%s> %s</label>', esc_attr( $name ), esc_attr( $key ), checked( $value, 1, false ), esc_html( $label ) );
	}

	private static function secret( $name, $key, $value, $placeholder = '' ) {
		$html = sprintf( '<input type="password" class="regular-text" name="%s[%s]" value="" autocomplete="new-password" placeholder="%s">', esc_attr( $name ), esc_attr( $key ), esc_attr( $value ? '•••••••• ' . __( '(guardado)', 'cletbot' ) : $placeholder ) );
		if ( $value ) {
			$html .= ' ' . self::check( $name, $key . '_clear', 0, __( 'Borrar', 'cletbot' ) );
		}
		return $html;
	}

	private static function copy_field( $value ) {
		return sprintf( '<input type="text" class="large-text code" readonly value="%s" onfocus="this.select()">', esc_attr( $value ) );
	}

	private static function tab_general( $s, $n ) {
		self::row(
			__( 'Remitentes autorizados', 'cletbot' ),
			self::textarea( $n, 'senders', $s['senders'], 6 ),
			__( 'Uno por línea: correos (<code>ana@ejemplo.com</code>, o <code>@ejemplo.com</code> para todo un dominio) y números de WhatsApp con código de país (<code>+52 55 1234 5678</code>). Los mensajes de cualquier otro remitente se ignoran.', 'cletbot' )
		);
		self::row(
			__( 'Pueden aprobar además', 'cletbot' ),
			self::textarea( $n, 'approvers', $s['approvers'], 3 ),
			__( 'Quien envía el material siempre puede aprobarlo. Aquí puedes añadir personas (correo o WhatsApp) que pueden aprobar o descartar cualquier envío respondiendo con su código.', 'cletbot' )
		);
		$cats = wp_dropdown_categories(
			array(
				'name'         => $n . '[default_category]',
				'selected'     => $s['default_category'],
				'hide_empty'   => 0,
				'hierarchical' => 1,
				'echo'         => 0,
			)
		);
		self::row( __( 'Categoría por defecto', 'cletbot' ), $cats, __( 'Se usa cuando no indican categoría y la IA no está activa. Con IA, Claude elige la categoría más adecuada entre las existentes.', 'cletbot' ) );
		self::row( __( 'Categorías nuevas', 'cletbot' ), self::check( $n, 'create_category', $s['create_category'], __( 'Crear la categoría si la indicada no existe (si no, se usa la por defecto)', 'cletbot' ) ) );
		$authors = wp_dropdown_users(
			array(
				'name'             => $n . '[author]',
				'selected'         => $s['author'],
				'capability'       => array( 'edit_posts' ),
				'show_option_none' => __( '— Usuario según el correo del remitente, o el primer administrador —', 'cletbot' ),
				'option_none_value' => 0,
				'echo'             => 0,
			)
		);
		self::row( __( 'Autor de las entradas', 'cletbot' ), $authors );
		self::row( __( 'Archivo original', 'cletbot' ), self::check( $n, 'attach_original', $s['attach_original'], __( 'Añadir al final de la entrada un enlace para descargar el PDF o documento recibido', 'cletbot' ) ) );
		self::row( __( 'Vista previa', 'cletbot' ), self::text( $n, 'preview_days', $s['preview_days'], 'number', 'small-text' ) . ' ' . esc_html__( 'días de validez del enlace', 'cletbot' ) . '<br>' . self::check( $n, 'preview_buttons', $s['preview_buttons'], __( 'Mostrar botones «Publicar» y «Descartar» en la vista previa', 'cletbot' ) ) );
		self::row( __( 'Acuse de recibo', 'cletbot' ), self::check( $n, 'ack', $s['ack'], __( 'Responder «Recibido, preparando la vista previa…» en cuanto llega el material', 'cletbot' ) ) );
	}

	private static function tab_ai( $s, $n ) {
		$key_field = defined( 'CLETBOT_ANTHROPIC_API_KEY' )
			? '<em>' . esc_html__( 'Definida en wp-config.php (CLETBOT_ANTHROPIC_API_KEY).', 'cletbot' ) . '</em>'
			: self::secret( $n, 'ai_key', $s['ai_key'], 'sk-ant-…' );
		self::row( __( 'API key de Anthropic', 'cletbot' ), $key_field, __( 'Créala en <a href="https://platform.claude.com/" target="_blank" rel="noopener">platform.claude.com</a>. Sin API key, CLETBOT publica el texto tal como llega (con formato básico) y no analiza PDF ni imágenes.', 'cletbot' ) );
		self::row( __( 'Modelo', 'cletbot' ), self::text( $n, 'ai_model', $s['ai_model'] ), __( 'Por defecto <code>claude-opus-5-5</code>. Puedes usar <code>claude-sonnet-5-5</code> o <code>claude-haiku-5-5</code> para reducir costo.', 'cletbot' ) );
		$efforts = array(
			'low'    => __( 'Baja (más rápido y económico)', 'cletbot' ),
			'medium' => __( 'Media (recomendado)', 'cletbot' ),
			'high'   => __( 'Alta', 'cletbot' ),
			'xhigh'  => __( 'Muy alta', 'cletbot' ),
		);
		$sel = '<select name="' . esc_attr( $n ) . '[ai_effort]">';
		foreach ( $efforts as $k => $label ) {
			$sel .= '<option value="' . esc_attr( $k ) . '"' . selected( $s['ai_effort'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$sel .= '</select>';
		self::row( __( 'Esfuerzo', 'cletbot' ), $sel );
		self::row( __( 'Modelo de respaldo', 'cletbot' ), self::check( $n, 'ai_fallbacks', $s['ai_fallbacks'], __( 'Si el modelo rechaza el material por sus filtros de seguridad, reintentar automáticamente con otro modelo', 'cletbot' ) ) );
		self::row( __( 'Lineamientos editoriales', 'cletbot' ), self::textarea( $n, 'guidelines', $s['guidelines'], 8 ), __( 'Tono, longitud, a quién va dirigido el blog, palabras que se usan o se evitan, llamadas a la acción… Claude los aplica a todas las entradas.', 'cletbot' ) );
	}

	private static function tab_email( $s, $n ) {
		$imap_ok = function_exists( 'imap_open' );
		self::row(
			__( 'Buzón IMAP', 'cletbot' ),
			self::check( $n, 'email_imap', $s['email_imap'], __( 'Revisar un buzón cada 5 minutos', 'cletbot' ) ) . ( $imap_ok ? '' : '<p class="description" style="color:#b32d2e">' . esc_html__( 'La extensión IMAP de PHP no está instalada en este servidor. Usa el webhook de correo entrante o pide a tu hosting que la active.', 'cletbot' ) . '</p>' ),
			__( 'Crea un correo exclusivo (p. ej. <code>blog@tudominio.com</code>) y escribe aquí sus datos de acceso. Los mensajes procesados se marcan como leídos.', 'cletbot' )
		);
		self::row( __( 'Servidor', 'cletbot' ), self::text( $n, 'imap_host', $s['imap_host'] ) . ' ' . esc_html__( 'Puerto', 'cletbot' ) . ' ' . self::text( $n, 'imap_port', $s['imap_port'], 'number', 'small-text' ), __( 'Gmail: <code>imap.gmail.com</code>, 993 (requiere una contraseña de aplicación).', 'cletbot' ) );
		$sec = '<select name="' . esc_attr( $n ) . '[imap_secure]">';
		foreach ( array( 'ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => __( 'Ninguna', 'cletbot' ) ) as $k => $label ) {
			$sec .= '<option value="' . esc_attr( $k ) . '"' . selected( $s['imap_secure'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		self::row( __( 'Cifrado', 'cletbot' ), $sec . '</select>' );
		self::row( __( 'Usuario', 'cletbot' ), self::text( $n, 'imap_user', $s['imap_user'] ) );
		self::row( __( 'Contraseña', 'cletbot' ), self::secret( $n, 'imap_pass', $s['imap_pass'] ) );
		self::row( __( 'Carpeta', 'cletbot' ), self::text( $n, 'imap_folder', $s['imap_folder'] ) );
		self::row( __( 'Responder desde', 'cletbot' ), self::text( $n, 'email_reply_to', $s['email_reply_to'], 'email' ), __( 'Dirección «Reply-To» de los correos de CLETBOT, para que las respuestas («SI», «NO», «CAMBIOS: …») lleguen al buzón que se revisa. Si lo dejas vacío se usa el usuario IMAP.', 'cletbot' ) );
		self::row( __( 'Verificar remitente', 'cletbot' ), self::check( $n, 'email_verify', $s['email_verify'], __( 'Aceptar solo correos con DKIM o DMARC válidos (recomendado: evita que alguien se haga pasar por un remitente autorizado)', 'cletbot' ) ) );
		$url = add_query_arg( 'key', $s['email_key'], rest_url( 'cletbot/v1/email' ) );
		self::row(
			__( 'Webhook de correo entrante', 'cletbot' ),
			self::copy_field( $url ) . '<br>' . self::check( $n, 'regen_email_key', 0, __( 'Generar una clave nueva', 'cletbot' ) ),
			__( 'Alternativa al buzón IMAP: configura el «inbound parse / routes» de Mailgun, SendGrid o Postmark para que envíe los correos a esta URL (POST, multipart con adjuntos).', 'cletbot' )
		);
	}

	private static function tab_whatsapp( $s, $n ) {
		echo '<tr><td colspan="2"><p>' . wp_kses_post( __( 'Usa la <strong>API de WhatsApp Cloud</strong> de Meta: crea una app en <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com</a>, añade el producto WhatsApp, registra el número y suscribe el webhook al campo <code>messages</code>.', 'cletbot' ) ) . '</p></td></tr>';
		self::row( __( 'URL del webhook', 'cletbot' ), self::copy_field( rest_url( 'cletbot/v1/whatsapp' ) ) );
		self::row( __( 'Token de verificación', 'cletbot' ), self::copy_field( $s['wa_verify_token'] ) . '<br>' . self::check( $n, 'regen_wa_verify_token', 0, __( 'Generar uno nuevo', 'cletbot' ) ) );
		self::row( __( 'Phone number ID', 'cletbot' ), self::text( $n, 'wa_phone_id', $s['wa_phone_id'] ) );
		self::row( __( 'Token de acceso', 'cletbot' ), self::secret( $n, 'wa_token', $s['wa_token'] ), __( 'Usa un token permanente de un usuario del sistema con el permiso <code>whatsapp_business_messaging</code>.', 'cletbot' ) );
		self::row( __( 'App secret', 'cletbot' ), self::secret( $n, 'wa_app_secret', $s['wa_app_secret'] ), __( 'Con él se comprueba que cada aviso del webhook viene de Meta. Es obligatorio.', 'cletbot' ) );
		self::row( __( 'Versión de la Graph API', 'cletbot' ), self::text( $n, 'wa_version', $s['wa_version'], 'text', 'small-text' ) );
		self::row( __( 'Agrupar mensajes', 'cletbot' ), self::text( $n, 'wa_group_seconds', $s['wa_group_seconds'], 'number', 'small-text' ) . ' ' . esc_html__( 'segundos', 'cletbot' ), __( 'En WhatsApp es común mandar el documento y luego un mensaje con la categoría. Lo que llegue del mismo número en este lapso se junta en una sola entrada.', 'cletbot' ) );
	}
}
