<?php
/**
 * WhatsApp channel through Meta's WhatsApp Cloud API: webhook for incoming messages
 * (text, documents, photos) and text replies.
 *
 * @package Cletbot
 */

namespace Cletbot;

defined( 'ABSPATH' ) || exit;

class WhatsApp {

	const GRAPH = 'https://graph.facebook.com/';
	const MAX_MEDIA_BYTES = 100000000;

	public static function ready() {
		return Settings::get( 'wa_phone_id' ) && Settings::get( 'wa_token' ) && Settings::get( 'wa_app_secret' );
	}

	private static function graph_url( $path ) {
		return self::GRAPH . rawurlencode( (string) Settings::get( 'wa_version' ) ) . '/' . ltrim( $path, '/' );
	}

	private static function auth() {
		return array( 'Authorization' => 'Bearer ' . Settings::get( 'wa_token' ) );
	}

	/* ------------------------------------------------------------------ webhook */

	/** GET: Meta checks the URL once with hub.mode=subscribe and our verify token. */
	public static function verify( \WP_REST_Request $request ) {
		$mode      = (string) $request->get_param( 'hub_mode' );
		$token     = (string) $request->get_param( 'hub_verify_token' );
		$challenge = (string) $request->get_param( 'hub_challenge' );
		if ( 'subscribe' === $mode && '' !== $token && hash_equals( (string) Settings::get( 'wa_verify_token' ), $token ) ) {
			Log::info( __( 'Webhook de WhatsApp verificado', 'cletbot' ) );
			// Meta expects the challenge back as plain text, not JSON.
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo preg_replace( '/[^A-Za-z0-9_\-]/', '', $challenge ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		return new \WP_REST_Response( 'Forbidden', 403 );
	}

	/** POST: message notifications, signed with the app secret. */
	public static function receive( \WP_REST_Request $request ) {
		$raw       = $request->get_body();
		$signature = (string) $request->get_header( 'x_hub_signature_256' );
		if ( ! self::valid_signature( $raw, $signature, (string) Settings::get( 'wa_app_secret' ) ) ) {
			Log::error( __( 'Aviso de WhatsApp con firma no válida', 'cletbot' ) );
			return new \WP_REST_Response( array( 'ok' => false ), 401 );
		}
		$data = json_decode( $raw, true );
		foreach ( self::messages( is_array( $data ) ? $data : array() ) as $msg ) {
			Workflow::receive( $msg );
		}
		// Always 200 quickly, or Meta keeps redelivering.
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public static function valid_signature( $raw, $header, $secret ) {
		if ( '' === $secret || 0 !== strpos( $header, 'sha256=' ) ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $raw, $secret ), substr( $header, 7 ) );
	}

	/**
	 * Webhook payload → Workflow messages. Pure: covered by tests/run-tests.php.
	 *
	 * @return array[]
	 */
	public static function messages( array $data ) {
		$out = array();
		foreach ( isset( $data['entry'] ) ? (array) $data['entry'] : array() as $entry ) {
			foreach ( isset( $entry['changes'] ) ? (array) $entry['changes'] : array() as $change ) {
				$value = isset( $change['value'] ) ? $change['value'] : array();
				$names = array();
				foreach ( isset( $value['contacts'] ) ? (array) $value['contacts'] : array() as $c ) {
					if ( isset( $c['wa_id'] ) ) {
						$names[ $c['wa_id'] ] = isset( $c['profile']['name'] ) ? $c['profile']['name'] : '';
					}
				}
				foreach ( isset( $value['messages'] ) ? (array) $value['messages'] : array() as $m ) {
					if ( empty( $m['from'] ) || empty( $m['type'] ) ) {
						continue;
					}
					$text        = '';
					$attachments = array();
					switch ( $m['type'] ) {
						case 'text':
							$text = isset( $m['text']['body'] ) ? $m['text']['body'] : '';
							break;
						case 'button':
							$text = isset( $m['button']['text'] ) ? $m['button']['text'] : '';
							break;
						case 'interactive':
							$text = isset( $m['interactive']['button_reply']['title'] ) ? $m['interactive']['button_reply']['title'] : '';
							break;
						case 'document':
						case 'image':
							$media = $m[ $m['type'] ];
							$text  = isset( $media['caption'] ) ? $media['caption'] : '';
							$mime  = isset( $media['mime_type'] ) ? $media['mime_type'] : '';
							$name  = isset( $media['filename'] ) ? $media['filename'] : $m['type'] . '-' . substr( md5( $media['id'] ), 0, 8 ) . self::extension( $mime );
							$attachments[] = array(
								'name'     => $name,
								'mime'     => $mime,
								'media_id' => $media['id'],
							);
							break;
						default:
							continue 2; // Audio, stickers, locations… are ignored.
					}
					$from  = (string) $m['from'];
					$out[] = array(
						'channel'     => 'whatsapp',
						'sender'      => Parser::normalize_phone( $from ),
						'sender_name' => isset( $names[ $from ] ) ? $names[ $from ] : '',
						'text'        => trim( $text ),
						'subject'     => '',
						'attachments' => $attachments,
						'reply'       => array(),
						'external_id' => isset( $m['id'] ) ? $m['id'] : '',
					);
				}
			}
		}
		return $out;
	}

	private static function extension( $mime ) {
		$map = array(
			'image/jpeg'      => '.jpg',
			'image/png'       => '.png',
			'image/webp'      => '.webp',
			'application/pdf' => '.pdf',
			'application/msword' => '.doc',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
			'text/plain'      => '.txt',
		);
		$mime = strtolower( trim( explode( ';', (string) $mime )[0] ) );
		return isset( $map[ $mime ] ) ? $map[ $mime ] : '';
	}

	/* ------------------------------------------------------------------ media & replies */

	/**
	 * Downloads a document or photo sent to the number.
	 *
	 * @return string|\WP_Error local path.
	 */
	public static function download( $media_id, $dir, $name ) {
		$meta = wp_remote_get( self::graph_url( rawurlencode( $media_id ) ), array( 'headers' => self::auth(), 'timeout' => 30 ) );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$info = json_decode( wp_remote_retrieve_body( $meta ), true );
		if ( empty( $info['url'] ) ) {
			return new \WP_Error( 'cletbot_wa_media', __( 'WhatsApp no entregó el archivo.', 'cletbot' ) . ' ' . wp_remote_retrieve_body( $meta ) );
		}
		if ( ! empty( $info['file_size'] ) && (int) $info['file_size'] > self::MAX_MEDIA_BYTES ) {
			return new \WP_Error( 'cletbot_wa_media', __( 'El archivo es demasiado grande.', 'cletbot' ) );
		}
		$path = trailingslashit( $dir ) . wp_unique_filename( $dir, sanitize_file_name( $name ) );
		$file = wp_remote_get(
			$info['url'],
			array(
				'headers'  => self::auth(),
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $path,
			)
		);
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $file ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'cletbot_wa_media', 'HTTP ' . wp_remote_retrieve_response_code( $file ) );
		}
		return $path;
	}

	public static function send( $to, $text ) {
		if ( ! self::ready() ) {
			return false;
		}
		$to = (string) apply_filters( 'cletbot_whatsapp_recipient', Parser::normalize_phone( $to ) );
		$response = wp_remote_post(
			self::graph_url( rawurlencode( Settings::get( 'wa_phone_id' ) ) . '/messages' ),
			array(
				'headers' => array_merge( self::auth(), array( 'Content-Type' => 'application/json' ) ),
				'timeout' => 20,
				'body'    => wp_json_encode(
					array(
						'messaging_product' => 'whatsapp',
						'recipient_type'    => 'individual',
						'to'                => $to,
						'type'              => 'text',
						'text'              => array(
							'preview_url' => true,
							'body'        => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 4096 ) : substr( $text, 0, 4096 ),
						),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			Log::error( $response->get_error_message(), 'WhatsApp ' . $to );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			Log::error( 'WhatsApp API HTTP ' . $code, wp_remote_retrieve_body( $response ) );
			return false;
		}
		return true;
	}
}
