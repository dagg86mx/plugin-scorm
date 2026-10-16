<?php
/**
 * Email channel: reads a mailbox over IMAP every 5 minutes, or receives emails from an
 * inbound-parse webhook (Mailgun, SendGrid, Postmark…), and answers with wp_mail().
 *
 * @package Cletbot
 */

namespace Cletbot;

defined( 'ABSPATH' ) || exit;

class Email {

	const CRON_HOOK = 'cletbot_check_mail';
	const MAX_PER_RUN = 20;
	const MAX_ATTACHMENT_BYTES = 30000000;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'poll' ) );
		add_action( 'admin_post_cletbot_check_mail', array( __CLASS__, 'manual_poll' ) );
	}

	public static function schedules( $schedules ) {
		$schedules['cletbot_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Cada 5 minutos (CLETBOT)', 'cletbot' ),
		);
		return $schedules;
	}

	public static function reschedule( $settings = null ) {
		$settings = $settings ? $settings : Settings::all();
		$next     = wp_next_scheduled( self::CRON_HOOK );
		if ( ! empty( $settings['email_imap'] ) && ! $next ) {
			wp_schedule_event( time() + 60, 'cletbot_5min', self::CRON_HOOK );
		} elseif ( empty( $settings['email_imap'] ) && $next ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	public static function imap_ready() {
		return function_exists( 'imap_open' ) && Settings::get( 'email_imap' ) && Settings::get( 'imap_host' ) && Settings::get( 'imap_user' ) && Settings::get( 'imap_pass' );
	}

	/* ------------------------------------------------------------------ sending */

	/**
	 * @param array $reply { message_id, subject, code, title }
	 */
	public static function send( $to, $text, array $reply = array() ) {
		if ( ! empty( $reply['code'] ) ) {
			$subject = sprintf( '[CLETBOT #%s] %s', $reply['code'], isset( $reply['title'] ) ? wp_specialchars_decode( $reply['title'], ENT_QUOTES ) : '' );
		} elseif ( ! empty( $reply['subject'] ) ) {
			$subject = preg_match( '/^\s*re\s*:/i', $reply['subject'] ) ? $reply['subject'] : 'Re: ' . $reply['subject'];
		} else {
			$subject = 'CLETBOT · ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}
		// The messages are written for WhatsApp; in email, *bold* reads fine but looks better without asterisks.
		$body    = preg_replace( '/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '$1', $text );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$reply_to = Settings::get( 'email_reply_to' );
		if ( ! $reply_to && is_email( Settings::get( 'imap_user' ) ) ) {
			$reply_to = Settings::get( 'imap_user' );
		}
		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		if ( ! empty( $reply['message_id'] ) ) {
			$mid       = '<' . trim( $reply['message_id'], '<> ' ) . '>';
			$headers[] = 'In-Reply-To: ' . $mid;
			$headers[] = 'References: ' . $mid;
		}
		return wp_mail( $to, $subject, $body, $headers );
	}

	/* ------------------------------------------------------------------ IMAP */

	public static function manual_poll() {
		check_admin_referer( 'cletbot_check_mail' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		$count = self::poll();
		wp_safe_redirect( add_query_arg( array( 'page' => Settings::PAGE, 'tab' => 'log', 'checked' => (int) $count ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @return int messages read. */
	public static function poll() {
		if ( ! self::imap_ready() ) {
			return 0;
		}
		if ( get_transient( 'cletbot_polling' ) ) {
			return 0;
		}
		set_transient( 'cletbot_polling', 1, 4 * MINUTE_IN_SECONDS );

		$flags = '/imap/' . ( 'ssl' === Settings::get( 'imap_secure' ) ? 'ssl' : ( 'tls' === Settings::get( 'imap_secure' ) ? 'tls' : 'notls' ) );
		$box   = '{' . Settings::get( 'imap_host' ) . ':' . (int) Settings::get( 'imap_port' ) . $flags . '}' . Settings::get( 'imap_folder' );
		$imap  = @imap_open( $box, Settings::get( 'imap_user' ), Settings::get( 'imap_pass' ), 0, 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $imap ) {
			$err = imap_last_error();
			imap_errors();
			Log::error( __( 'No se pudo conectar al buzón', 'cletbot' ), $err );
			delete_transient( 'cletbot_polling' );
			return 0;
		}
		$uids  = imap_search( $imap, 'UNSEEN', SE_UID );
		$count = 0;
		foreach ( array_slice( $uids ? $uids : array(), 0, self::MAX_PER_RUN ) as $uid ) {
			// Mark as read first: a broken message must not be retried forever.
			imap_setflag_full( $imap, (string) $uid, '\\Seen', ST_UID );
			try {
				$msg = self::read_imap_message( $imap, $uid );
				if ( $msg ) {
					Workflow::receive( $msg );
				}
			} catch ( \Throwable $e ) {
				Log::error( $e->getMessage(), 'IMAP uid ' . $uid );
			}
			++$count;
		}
		imap_errors();
		imap_close( $imap );
		delete_transient( 'cletbot_polling' );
		return $count;
	}

	private static function read_imap_message( $imap, $uid ) {
		$raw_headers = imap_fetchheader( $imap, $uid, FT_UID );
		$header      = imap_rfc822_parse_headers( $raw_headers );
		if ( ! $header || empty( $header->from ) ) {
			return null;
		}
		$from_obj = $header->from[0];
		$address  = strtolower( $from_obj->mailbox . '@' . $from_obj->host );
		$name     = isset( $from_obj->personal ) ? self::decode_header( $from_obj->personal ) : '';
		$subject  = isset( $header->subject ) ? self::decode_header( $header->subject ) : '';

		// Never answer bounces, auto-replies or our own messages.
		if ( preg_match( '/^(mailer-daemon|postmaster|no-?reply)@/i', $address ) || preg_match( '/^Auto-Submitted:\s*auto-/mi', $raw_headers ) || preg_match( '/^X-Autoreply:/mi', $raw_headers ) ) {
			return null;
		}
		if ( Settings::get( 'email_verify' ) && ! Parser::email_auth_passed( $raw_headers, $address ) ) {
			Log::info( __( 'Correo ignorado: no pasó la verificación DKIM/DMARC', 'cletbot' ), $address );
			return null;
		}
		$structure = imap_fetchstructure( $imap, $uid, FT_UID );
		$parts     = array(
			'plain'       => '',
			'html'        => '',
			'attachments' => array(),
		);
		$tmp = trailingslashit( self::tmp_dir() ) . 'm' . $uid . '-' . wp_generate_password( 8, false );
		self::walk( $imap, $uid, $structure, '', $parts, $tmp );

		$text = '' !== trim( $parts['plain'] ) ? $parts['plain'] : Extractor::html_to_text( $parts['html'] );
		return array(
			'channel'     => 'email',
			'sender'      => $address,
			'sender_name' => $name,
			'subject'     => $subject,
			'text'        => Parser::strip_quoted_reply( Extractor::clean( $text ) ),
			'attachments' => $parts['attachments'],
			'reply'       => array(
				'message_id' => isset( $header->message_id ) ? $header->message_id : '',
				'subject'    => $subject,
			),
			'external_id' => isset( $header->message_id ) ? $header->message_id : 'uid-' . $uid,
		);
	}

	private static function walk( $imap, $uid, $part, $section, array &$out, $tmp ) {
		if ( ! empty( $part->parts ) ) {
			foreach ( $part->parts as $i => $sub ) {
				self::walk( $imap, $uid, $sub, ( '' === $section ? '' : $section . '.' ) . ( $i + 1 ), $out, $tmp );
			}
			return;
		}
		$section  = '' === $section ? '1' : $section;
		$params   = array();
		foreach ( array( 'parameters', 'dparameters' ) as $prop ) {
			if ( ! empty( $part->$prop ) ) {
				foreach ( $part->$prop as $p ) {
					$params[ strtolower( $p->attribute ) ] = $p->value;
				}
			}
		}
		$filename = '';
		foreach ( array( 'filename', 'filename*', 'name', 'name*' ) as $key ) {
			if ( ! empty( $params[ $key ] ) ) {
				$filename = self::decode_header( preg_replace( "/^utf-8''/i", '', rawurldecode( $params[ $key ] ) ) );
				break;
			}
		}
		$types   = array( 'text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'model', 'other' );
		$mime    = ( isset( $types[ $part->type ] ) ? $types[ $part->type ] : 'application' ) . '/' . strtolower( isset( $part->subtype ) ? $part->subtype : 'octet-stream' );
		$is_file = '' !== $filename || ( isset( $part->disposition ) && 'attachment' === strtolower( $part->disposition ) );

		$data = imap_fetchbody( $imap, $uid, $section, FT_UID | FT_PEEK );
		if ( 3 === (int) $part->encoding ) {
			$data = base64_decode( $data ); // phpcs:ignore
		} elseif ( 4 === (int) $part->encoding ) {
			$data = quoted_printable_decode( $data );
		}

		if ( ! $is_file && 0 === strpos( $mime, 'text/' ) ) {
			$charset = isset( $params['charset'] ) ? $params['charset'] : 'UTF-8';
			if ( strtoupper( $charset ) !== 'UTF-8' && function_exists( 'mb_convert_encoding' ) ) {
				$data = @mb_convert_encoding( $data, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			if ( 'text/html' === $mime ) {
				$out['html'] .= $data;
			} else {
				$out['plain'] .= $data;
			}
			return;
		}
		if ( ! $is_file || strlen( $data ) > self::MAX_ATTACHMENT_BYTES || 'other' === Extractor::kind( $filename, $mime ) ) {
			return;
		}
		// Skip tiny inline images (logos and signatures).
		if ( 0 === strpos( $mime, 'image/' ) && strlen( $data ) < 15000 ) {
			return;
		}
		$path = $tmp . '-' . count( $out['attachments'] ) . '-' . sanitize_file_name( $filename ? $filename : 'adjunto' );
		file_put_contents( $path, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out['attachments'][] = array(
			'name' => $filename ? $filename : basename( $path ),
			'mime' => $mime,
			'path' => $path,
		);
	}

	private static function decode_header( $value ) {
		if ( function_exists( 'iconv_mime_decode' ) ) {
			$decoded = @iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $decoded ) {
				return trim( $decoded );
			}
		}
		$out = '';
		foreach ( imap_mime_header_decode( $value ) as $piece ) {
			$out .= 'default' === $piece->charset ? $piece->text : @mb_convert_encoding( $piece->text, 'UTF-8', $piece->charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return trim( $out );
	}

	public static function tmp_dir() {
		$dir = Items::tmp_root() . '/mail-' . wp_hash( 'cletbot-mail' );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/* ------------------------------------------------------------------ inbound webhook */

	/**
	 * POST /wp-json/cletbot/v1/email?key=… — accepts the field names used by Mailgun
	 * (sender, subject, body-plain, stripped-text, message-headers), SendGrid
	 * (from, subject, text, html, headers) and Postmark JSON (From, Subject, TextBody, Attachments).
	 */
	public static function webhook( \WP_REST_Request $request ) {
		$key = (string) $request->get_param( 'key' );
		if ( '' === $key || ! hash_equals( (string) Settings::get( 'email_key' ), $key ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 403 );
		}
		$p    = $request->get_json_params();
		$p    = is_array( $p ) && $p ? $p : $request->get_body_params();
		$pick = function ( array $names ) use ( $p ) {
			foreach ( $names as $n ) {
				if ( ! empty( $p[ $n ] ) && is_string( $p[ $n ] ) ) {
					return $p[ $n ];
				}
			}
			return '';
		};
		$from    = $pick( array( 'sender', 'from', 'From', 'FromFull' ) );
		$address = Parser::email_address( $from );
		if ( ! is_email( $address ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'from' ), 400 );
		}
		$subject = $pick( array( 'subject', 'Subject' ) );
		$text    = $pick( array( 'stripped-text', 'body-plain', 'text', 'TextBody', 'StrippedTextReply' ) );
		if ( '' === trim( $text ) ) {
			$text = Extractor::html_to_text( $pick( array( 'stripped-html', 'body-html', 'html', 'HtmlBody' ) ) );
		}

		$headers = $pick( array( 'message-headers', 'headers', 'Headers' ) );
		if ( '' === $headers && isset( $p['Headers'] ) && is_array( $p['Headers'] ) ) {
			foreach ( $p['Headers'] as $h ) {
				$headers .= $h['Name'] . ': ' . $h['Value'] . "\n";
			}
		}
		$decoded = json_decode( $headers, true );
		if ( is_array( $decoded ) ) {
			$headers = '';
			foreach ( $decoded as $pair ) {
				$headers .= ( is_array( $pair ) ? $pair[0] . ': ' . $pair[1] : '' ) . "\n";
			}
		}
		$verified = Parser::email_auth_passed( $headers, $address );
		if ( ! $verified && 'pass' === strtolower( $pick( array( 'dkim_result', 'X-Mailgun-Dkim-Check-Result' ) ) ) ) {
			$verified = true;
		}
		if ( ! $verified && preg_match( '/@' . preg_quote( substr( strrchr( $address, '@' ), 1 ), '/' ) . '\s*:\s*pass/i', $pick( array( 'dkim' ) ) ) ) {
			$verified = true; // SendGrid: dkim = "{@example.com : pass}".
		}
		if ( Settings::get( 'email_verify' ) && ! $verified ) {
			Log::info( __( 'Correo ignorado: no pasó la verificación DKIM/DMARC', 'cletbot' ), $address );
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$attachments = array();
		$tmp         = trailingslashit( self::tmp_dir() ) . 'w' . wp_generate_password( 8, false );
		foreach ( $request->get_file_params() as $file ) {
			foreach ( self::flatten_files( $file ) as $f ) {
				if ( UPLOAD_ERR_OK !== (int) $f['error'] || $f['size'] > self::MAX_ATTACHMENT_BYTES || 'other' === Extractor::kind( $f['name'], $f['type'] ) ) {
					continue;
				}
				$path = $tmp . '-' . count( $attachments ) . '-' . sanitize_file_name( $f['name'] );
				if ( move_uploaded_file( $f['tmp_name'], $path ) ) {
					$attachments[] = array( 'name' => $f['name'], 'mime' => $f['type'], 'path' => $path );
				}
			}
		}
		if ( isset( $p['Attachments'] ) && is_array( $p['Attachments'] ) ) { // Postmark JSON.
			foreach ( $p['Attachments'] as $a ) {
				$data = base64_decode( isset( $a['Content'] ) ? $a['Content'] : '' ); // phpcs:ignore
				$name = isset( $a['Name'] ) ? $a['Name'] : 'adjunto';
				$mime = isset( $a['ContentType'] ) ? $a['ContentType'] : '';
				if ( $data && strlen( $data ) <= self::MAX_ATTACHMENT_BYTES && 'other' !== Extractor::kind( $name, $mime ) ) {
					$path = $tmp . '-' . count( $attachments ) . '-' . sanitize_file_name( $name );
					file_put_contents( $path, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					$attachments[] = array( 'name' => $name, 'mime' => $mime, 'path' => $path );
				}
			}
		}

		$message_id = $pick( array( 'Message-Id', 'message-id', 'MessageID' ) );
		if ( '' === $message_id && preg_match( '/^Message-ID:\s*(\S+)/mi', $headers, $m ) ) {
			$message_id = $m[1];
		}
		Workflow::receive(
			array(
				'channel'     => 'email',
				'sender'      => $address,
				'sender_name' => trim( preg_replace( '/<[^>]*>/', '', $from ), " \"'" ),
				'subject'     => $subject,
				'text'        => Parser::strip_quoted_reply( Extractor::clean( $text ) ),
				'attachments' => $attachments,
				'reply'       => array(
					'message_id' => $message_id,
					'subject'    => $subject,
				),
				'external_id' => '' !== $message_id ? $message_id : md5( $address . $subject . $text ),
			)
		);
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** $_FILES entries can be single files or arrays of files. */
	private static function flatten_files( array $file ) {
		if ( ! is_array( $file['name'] ) ) {
			return array( $file );
		}
		$out = array();
		foreach ( array_keys( $file['name'] ) as $i ) {
			$out[] = array(
				'name'     => $file['name'][ $i ],
				'type'     => $file['type'][ $i ],
				'tmp_name' => $file['tmp_name'][ $i ],
				'error'    => $file['error'][ $i ],
				'size'     => $file['size'][ $i ],
			);
		}
		return $out;
	}
}
