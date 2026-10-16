<?php
/**
 * The conversation: receive → prepare the post as "pending review" → send the preview link →
 * publish, discard or revise depending on the answer.
 *
 * Channels (Email, WhatsApp) hand every incoming message to Workflow::receive() as:
 *
 *     channel     'email' | 'whatsapp'
 *     sender      email address or phone (digits)
 *     sender_name display name, if known
 *     text        message body (for email, already without the quoted reply)
 *     subject     email subject ('' for WhatsApp)
 *     attachments [ { name, mime, path } | { name, mime, media_id } ]
 *     reply       channel data needed to answer (email: message_id, subject)
 *     external_id unique id of the message, to ignore redeliveries
 *
 * @package Cletbot
 */

namespace Cletbot;

defined( 'ABSPATH' ) || exit;

class Workflow {

	const CRON_HOOK = 'cletbot_process_item';

	/** Items to run at the end of this request, after the response is sent (see run_at_shutdown()). */
	private static $shutdown_queue = array();

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'process' ) );
		add_action( 'admin_post_cletbot_item', array( __CLASS__, 'admin_action' ) );
	}

	/* ------------------------------------------------------------------ inbound */

	public static function receive( array $msg ) {
		$msg = array_merge(
			array(
				'channel'     => '',
				'sender'      => '',
				'sender_name' => '',
				'text'        => '',
				'subject'     => '',
				'attachments' => array(),
				'reply'       => array(),
				'external_id' => '',
			),
			$msg
		);

		if ( '' !== $msg['external_id'] ) {
			$seen = 'cletbot_seen_' . md5( $msg['channel'] . '|' . $msg['external_id'] );
			if ( get_transient( $seen ) ) {
				return;
			}
			set_transient( $seen, 1, 3 * DAY_IN_SECONDS );
		}

		$senders   = Settings::senders();
		$approvers = Settings::approvers();
		if ( ! Parser::sender_in_list( $msg['sender'], $senders ) && ! Parser::sender_in_list( $msg['sender'], $approvers ) ) {
			Log::info( __( 'Mensaje ignorado: remitente no autorizado', 'cletbot' ), $msg['channel'] . ' ' . $msg['sender'] );
			self::cleanup_attachments( $msg['attachments'] );
			return;
		}

		$target = array(
			'channel' => $msg['channel'],
			'to'      => $msg['sender'],
			'reply'   => $msg['reply'],
		);

		// Replies about a pending post.
		$code = 'email' === $msg['channel'] ? Parser::code_from_subject( $msg['subject'] ) : '';
		if ( empty( $msg['attachments'] ) ) {
			$cmd = Parser::parse_command( $msg['text'] );
			if ( ! $cmd && '' !== $code && '' !== trim( $msg['text'] ) ) {
				// Any other text answering a preview email is taken as requested changes.
				$cmd = array( 'action' => 'revise', 'code' => $code, 'arg' => trim( $msg['text'] ) );
			}
			if ( ! $cmd ) {
				// "Categoría: Eventos" alone, right after a preview: change the category of the pending post.
				$parsed = Parser::parse_submission( $msg['text'] );
				if ( '' !== $parsed['category'] && '' === $parsed['body'] && '' === $parsed['title'] && ( '' !== $code || Items::by_sender( $msg['sender'], 'awaiting', 1 ) ) ) {
					$cmd = array( 'action' => 'category', 'code' => $code, 'arg' => $parsed['category'] );
				}
			}
			if ( $cmd ) {
				if ( '' === $cmd['code'] ) {
					$cmd['code'] = $code;
				}
				self::command( $cmd, $msg, $target );
				return;
			}
		}

		if ( ! Parser::sender_in_list( $msg['sender'], $senders ) ) {
			// Approvers who aren't senders can only answer.
			self::send( $target, __( 'Puedes aprobar o descartar envíos, pero no enviar material nuevo. Escribe AYUDA para ver los comandos.', 'cletbot' ) );
			return;
		}

		if ( '' === trim( $msg['text'] ) && empty( $msg['attachments'] ) ) {
			return;
		}

		// WhatsApp: join messages sent one after the other (document, then "Categoría: …").
		$window = 'whatsapp' === $msg['channel'] ? (int) Settings::get( 'wa_group_seconds' ) : 0;
		if ( $window > 0 ) {
			foreach ( Items::by_sender( $msg['sender'], 'queued', 1 ) as $queued ) {
				if ( (int) get_post_meta( $queued, '_cletbot_due', true ) > time() - 5 ) {
					Items::merge( $queued, $msg );
					self::schedule( $queued, $window );
					return;
				}
			}
		}

		$item = Items::create( $msg );
		if ( is_wp_error( $item ) ) {
			Log::error( $item->get_error_message() );
			return;
		}
		Log::info( __( 'Material recibido', 'cletbot' ), '#' . $item . ' ' . $msg['channel'] . ' ' . $msg['sender'] );
		if ( Settings::get( 'ack' ) ) {
			self::send( $target, __( '📥 Recibido. Estoy preparando la vista previa de la entrada…', 'cletbot' ) );
		}
		self::schedule( $item, $window );
	}

	/**
	 * Processing runs in the background: a WP-Cron event, and also right after the current
	 * response is sent when PHP-FPM allows it (so it doesn't wait for the next visit).
	 */
	public static function schedule( $item, $delay = 0 ) {
		$due = time() + max( 0, (int) $delay );
		update_post_meta( $item, '_cletbot_due', $due );
		wp_clear_scheduled_hook( self::CRON_HOOK, array( (int) $item ) );
		wp_schedule_single_event( $due, self::CRON_HOOK, array( (int) $item ) );
		self::$shutdown_queue[ (int) $item ] = true;
		if ( 1 === count( self::$shutdown_queue ) ) {
			add_action( 'shutdown', array( __CLASS__, 'run_at_shutdown' ), 1000 );
		}
	}

	public static function run_at_shutdown() {
		if ( ! function_exists( 'fastcgi_finish_request' ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			if ( ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
				spawn_cron();
			}
			return;
		}
		fastcgi_finish_request();
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		foreach ( array_keys( self::$shutdown_queue ) as $item ) {
			$due = (int) get_post_meta( $item, '_cletbot_due', true );
			if ( $due > time() ) {
				sleep( min( 120, $due - time() ) );
			}
			wp_cache_delete( $item, 'post_meta' );
			// Another message may have arrived meanwhile and pushed the time back: its own request will run it.
			if ( (int) get_post_meta( $item, '_cletbot_due', true ) <= time() ) {
				self::process( $item );
			}
		}
	}

	/* ------------------------------------------------------------------ commands */

	private static function command( array $cmd, array $msg, array $target ) {
		$sender = $msg['sender'];

		if ( 'help' === $cmd['action'] ) {
			self::send( $target, self::help_text() );
			return;
		}
		if ( 'status' === $cmd['action'] ) {
			$ids = self::visible_awaiting( $sender );
			if ( ! $ids ) {
				self::send( $target, __( 'No tienes entradas pendientes de aprobación.', 'cletbot' ) );
				return;
			}
			$lines = array( __( 'Entradas pendientes:', 'cletbot' ) );
			foreach ( $ids as $id ) {
				$lines[] = '• ' . get_post_meta( $id, '_cletbot_code', true ) . ' — ' . get_the_title( Items::post_id( $id ) ) . "\n  " . Preview::url( $id );
			}
			self::send( $target, implode( "\n", $lines ) );
			return;
		}

		$item = self::resolve( $cmd, $sender, $target );
		if ( ! $item ) {
			return;
		}
		$item_target = self::target( $item );
		// Answer on the channel where the command came from, keeping the email thread if it's the same one.
		if ( $item_target['channel'] === $target['channel'] && $item_target['to'] === $target['to'] && empty( $target['reply'] ) ) {
			$target = $item_target;
		}

		switch ( $cmd['action'] ) {
			case 'approve':
				self::approve( $item, $target, $sender );
				break;
			case 'reject':
				self::reject( $item, $target, $sender );
				break;
			case 'category':
				self::change_category( $item, $cmd['arg'], $target );
				break;
			case 'revise':
				if ( ! AI::enabled() ) {
					self::send( $target, sprintf( /* translators: %s: edit link */ __( 'Para aplicar cambios automáticamente hace falta configurar la IA. Puedes editar la entrada aquí: %s', 'cletbot' ), get_edit_post_link( Items::post_id( $item ), 'raw' ) ) );
					return;
				}
				update_post_meta( $item, '_cletbot_revision', wp_slash( $cmd['arg'] ) );
				update_post_meta( $item, '_cletbot_revision_target', wp_slash( $target ) );
				Items::set_state( $item, 'processing' );
				self::send( $target, __( '✍️ Entendido, estoy aplicando los cambios…', 'cletbot' ) );
				self::schedule( $item );
				break;
		}
	}

	/** Which item does "SI" / "NO K7M2Q" refer to? */
	private static function resolve( array $cmd, $sender, array $target ) {
		$code = strtoupper( (string) $cmd['code'] );
		if ( '' !== $code ) {
			$item = Items::find_by_code( $code );
			if ( ! $item || ! self::may_answer( $item, $sender ) ) {
				self::send( $target, sprintf( /* translators: %s: code */ __( 'No encontré ninguna entrada con el código %s.', 'cletbot' ), $code ) );
				return 0;
			}
			$state = Items::state( $item );
			if ( 'awaiting' !== $state ) {
				$states = Items::states();
				self::send( $target, sprintf( /* translators: 1: code, 2: state */ __( 'La entrada %1$s ya no está pendiente (estado: %2$s).', 'cletbot' ), $code, isset( $states[ $state ] ) ? $states[ $state ] : $state ) );
				return 0;
			}
			return $item;
		}

		$own = Items::by_sender( $sender, 'awaiting', 5 );
		if ( ! $own ) {
			$own = self::visible_awaiting( $sender );
		}
		if ( ! $own ) {
			$busy = Items::by_sender( $sender, array( 'queued', 'processing' ), 1 );
			self::send( $target, $busy ? __( 'Todavía estoy preparando la vista previa; en cuanto esté lista te la envío.', 'cletbot' ) : __( 'No tienes entradas pendientes de aprobación. Escribe AYUDA para ver cómo funciona.', 'cletbot' ) );
			return 0;
		}
		if ( count( $own ) > 1 && in_array( $cmd['action'], array( 'approve', 'reject' ), true ) ) {
			$lines = array( __( 'Tienes varias entradas pendientes. Responde con el código, por ejemplo «SI ABCDE»:', 'cletbot' ) );
			foreach ( $own as $id ) {
				$lines[] = '• ' . get_post_meta( $id, '_cletbot_code', true ) . ' — ' . get_the_title( Items::post_id( $id ) );
			}
			self::send( $target, implode( "\n", $lines ) );
			return 0;
		}
		return $own[0];
	}

	private static function may_answer( $item, $sender ) {
		return get_post_meta( $item, '_cletbot_sender', true ) === $sender || Parser::sender_in_list( $sender, Settings::approvers() );
	}

	private static function visible_awaiting( $sender ) {
		if ( Parser::sender_in_list( $sender, Settings::approvers() ) ) {
			return Items::awaiting( 10 );
		}
		return Items::by_sender( $sender, 'awaiting', 10 );
	}

	/* ------------------------------------------------------------------ actions */

	public static function approve( $item, $target = null, $by = '' ) {
		$post_id = Items::post_id( $item );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			Items::set_state( $item, 'error', __( 'La entrada ya no existe.', 'cletbot' ) );
			return false;
		}
		$now    = current_time( 'mysql' );
		$result = wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => 'publish',
				'post_date'     => $now,
				'post_date_gmt' => get_gmt_from_date( $now ),
				'edit_date'     => true,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			Log::error( $result->get_error_message(), '#' . $item );
			return false;
		}
		Items::set_state( $item, 'published' );
		update_post_meta( $item, '_cletbot_decided_by', $by );
		Log::info( __( 'Entrada publicada', 'cletbot' ), '#' . $item . ' → ' . $post_id . ( $by ? ' (' . $by . ')' : '' ) );
		self::send( $target ? $target : self::target( $item ), sprintf( /* translators: 1: title, 2: URL */ __( "🎉 Publicada: *%1\$s*\n%2\$s", 'cletbot' ), get_the_title( $post_id ), get_permalink( $post_id ) ) );
		return true;
	}

	public static function reject( $item, $target = null, $by = '' ) {
		$post_id = Items::post_id( $item );
		if ( $post_id && get_post( $post_id ) ) {
			wp_trash_post( $post_id );
		}
		Items::set_state( $item, 'rejected' );
		update_post_meta( $item, '_cletbot_decided_by', $by );
		Log::info( __( 'Entrada descartada', 'cletbot' ), '#' . $item . ( $by ? ' (' . $by . ')' : '' ) );
		self::send( $target ? $target : self::target( $item ), __( '🗑️ Listo, descarté la entrada. No se publicó nada.', 'cletbot' ) );
		return true;
	}

	private static function change_category( $item, $name, array $target ) {
		$post_id = Items::post_id( $item );
		$cat     = self::category_id( $name, '' );
		wp_set_post_categories( $post_id, array( $cat ) );
		self::send( $target, sprintf( /* translators: 1: category, 2: preview URL, 3: code */ __( "Cambié la categoría a *%1\$s*.\nVista previa: %2\$s\nResponde *SI* para publicarla o *NO* para descartarla. (Código %3\$s)", 'cletbot' ), get_cat_name( $cat ), Preview::url( $item ), get_post_meta( $item, '_cletbot_code', true ) ) );
	}

	/* ------------------------------------------------------------------ processing */

	public static function process( $item ) {
		$item = (int) $item;
		if ( get_post_type( $item ) !== Items::POST_TYPE ) {
			return;
		}
		if ( ! self::lock( $item ) ) {
			return;
		}
		try {
			$state = Items::state( $item );
			if ( 'queued' === $state ) {
				self::prepare( $item );
			} elseif ( 'processing' === $state && Items::post_id( $item ) ) {
				self::revise( $item );
			}
		} catch ( \Throwable $e ) {
			Items::set_state( $item, 'error', $e->getMessage() );
			Log::error( $e->getMessage(), '#' . $item );
			self::send( self::target( $item ), __( '⚠️ Tuve un problema al preparar la entrada. Un administrador puede revisarlo en CLETBOT → Envíos.', 'cletbot' ) );
		}
		self::unlock( $item );
	}

	private static function lock( $item ) {
		$key = 'cletbot_lock_' . $item;
		if ( add_option( $key, time(), '', 'no' ) ) {
			return true;
		}
		if ( (int) get_option( $key ) < time() - 15 * MINUTE_IN_SECONDS ) {
			delete_option( $key );
			return add_option( $key, time(), '', 'no' );
		}
		return false;
	}

	private static function unlock( $item ) {
		delete_option( 'cletbot_lock_' . $item );
	}

	private static function prepare( $item ) {
		Items::set_state( $item, 'processing' );
		// A previous attempt that failed half way may have left a pending post behind.
		$stale = Items::post_id( $item );
		if ( $stale && get_post( $stale ) && 'publish' !== get_post_status( $stale ) ) {
			wp_delete_post( $stale, true );
			delete_post_meta( $item, '_cletbot_post_id' );
		}
		$payload = Items::payload( $item );
		$channel = get_post_meta( $item, '_cletbot_channel', true );
		$parsed  = Parser::parse_submission( $payload['text'], $payload['subject'] );

		// Files: download (WhatsApp) and read them.
		$docs   = array();
		$images = array();
		$files  = array();
		foreach ( $payload['attachments'] as $att ) {
			$path = isset( $att['path'] ) ? $att['path'] : '';
			if ( '' === $path && ! empty( $att['media_id'] ) && 'whatsapp' === $channel ) {
				$path = WhatsApp::download( $att['media_id'], Items::tmp_dir( $item ), $att['name'] );
				if ( is_wp_error( $path ) ) {
					Log::error( $path->get_error_message(), '#' . $item );
					continue;
				}
			}
			if ( '' === $path || ! is_readable( $path ) ) {
				continue;
			}
			$kind = Extractor::kind( $att['name'], $att['mime'] );
			if ( 'image' === $kind ) {
				$images[] = array( 'path' => $path, 'name' => $att['name'], 'mime' => $att['mime'] );
				continue;
			}
			if ( 'other' === $kind ) {
				continue;
			}
			$doc         = Extractor::extract( $path, $att['name'], $att['mime'] );
			$doc['name'] = $att['name'];
			$doc['mime'] = $att['mime'];
			$doc['data'] = '';
			if ( 'pdf' === $kind && AI::enabled() && filesize( $path ) <= AI::MAX_PDF_BYTES ) {
				$doc['data'] = base64_encode( file_get_contents( $path ) ); // phpcs:ignore
			}
			$docs[]  = $doc;
			$files[] = array( 'path' => $path, 'name' => $att['name'] );
		}

		$body_text = $parsed['body'];
		if ( '' === trim( $body_text ) && ! $docs && ! $images ) {
			throw new \RuntimeException( __( 'El mensaje no tiene contenido que publicar.', 'cletbot' ) );
		}

		$post     = null;
		$ai_error = '';
		if ( AI::enabled() ) {
			$ai_docs = $docs;
			foreach ( array_slice( $images, 0, 5 ) as $img ) {
				if ( filesize( $img['path'] ) <= AI::MAX_IMAGE_BYTES && in_array( $img['mime'], array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
					$ai_docs[] = array(
						'name' => $img['name'],
						'kind' => 'image',
						'mime' => $img['mime'],
						'text' => '',
						'html' => '',
						'data' => base64_encode( file_get_contents( $img['path'] ) ), // phpcs:ignore
					);
				}
			}
			$result = AI::compose( array_merge( self::ai_context(), array(
				'wanted_category' => $parsed['category'],
				'wanted_title'    => $parsed['title'],
				'wanted_tags'     => $parsed['tags'],
				'instructions'    => $parsed['instructions'],
				'text'            => $body_text,
				'documents'       => $ai_docs,
			) ) );
			if ( is_wp_error( $result ) ) {
				$ai_error = $result->get_error_message();
				Log::error( $ai_error, '#' . $item );
			} else {
				$post = $result;
			}
		}
		if ( ! $post ) {
			$post = self::basic_post( $parsed, $docs );
		}

		$category = self::category_id( '' !== $parsed['category'] ? $parsed['category'] : '', $post['category'] );
		$tags     = array_values( array_unique( array_merge( $parsed['tags'], $post['tags'] ) ) );
		$author   = self::author( get_post_meta( $item, '_cletbot_sender', true ) );

		$post_id = wp_insert_post(
			array(
				'post_type'      => 'post',
				'post_status'    => 'pending',
				'post_title'     => '' !== $post['title'] ? $post['title'] : __( 'Nueva entrada', 'cletbot' ),
				'post_name'      => sanitize_title( $post['slug'] ),
				'post_content'   => wp_kses_post( $post['content_html'] ),
				'post_excerpt'   => $post['excerpt'],
				'post_author'    => $author,
				'post_category'  => array( $category ),
				'tags_input'     => $tags,
				'comment_status' => get_default_comment_status( 'post' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( $post_id->get_error_message() );
		}
		update_post_meta( $item, '_cletbot_post_id', $post_id );
		update_post_meta( $post_id, '_cletbot_item', $item );
		if ( '' !== $post['meta_description'] ) {
			update_post_meta( $post_id, '_cletbot_meta_description', $post['meta_description'] );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $post['meta_description'] );
			update_post_meta( $post_id, 'rank_math_description', $post['meta_description'] );
		}

		self::add_media( $post_id, $images, $files );
		Items::cleanup_files( $item );
		self::cleanup_attachments( $payload['attachments'] );

		$code = Parser::generate_code();
		while ( Items::find_by_code( $code ) ) {
			$code = Parser::generate_code();
		}
		update_post_meta( $item, '_cletbot_code', $code );
		Preview::issue( $item );
		update_post_meta( $item, '_cletbot_notes', $post['notes'] );
		Items::set_state( $item, 'awaiting' );
		Log::info( __( 'Vista previa enviada', 'cletbot' ), '#' . $item . ' → ' . $post_id . ' ' . $code );

		$notes = array();
		if ( '' !== $post['notes'] ) {
			$notes[] = '📝 ' . $post['notes'];
		}
		if ( '' !== $ai_error ) {
			$notes[] = __( '⚠️ No pude usar la IA esta vez, así que la entrada lleva el texto tal como llegó.', 'cletbot' );
		}
		self::send( self::target( $item ), self::preview_message( $item, $notes ) );
	}

	private static function revise( $item ) {
		$post_id  = Items::post_id( $item );
		$wp_post  = get_post( $post_id );
		$changes  = (string) get_post_meta( $item, '_cletbot_revision', true );
		$target   = get_post_meta( $item, '_cletbot_revision_target', true );
		$target   = is_array( $target ) && ! empty( $target['channel'] ) ? $target : self::target( $item );
		$cats     = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
		$payload  = Items::payload( $item );
		$parsed   = Parser::parse_submission( $payload['text'], $payload['subject'] );
		$current  = array(
			'title'            => $wp_post->post_title,
			'slug'             => $wp_post->post_name,
			'excerpt'          => $wp_post->post_excerpt,
			'content_html'     => $wp_post->post_content,
			'category'         => $cats ? $cats[0] : '',
			'tags'             => wp_get_post_tags( $post_id, array( 'fields' => 'names' ) ),
			'meta_description' => (string) get_post_meta( $post_id, '_cletbot_meta_description', true ),
		);

		$result = AI::compose( array_merge( self::ai_context(), array(
			'current'  => $current,
			'revision' => $changes,
			'text'     => $parsed['body'],
		) ) );
		if ( is_wp_error( $result ) ) {
			Items::set_state( $item, 'awaiting' );
			Log::error( $result->get_error_message(), '#' . $item );
			self::send( $target, sprintf( /* translators: 1: error, 2: preview URL */ __( '⚠️ No pude aplicar los cambios (%1$s). La vista previa sigue siendo la anterior: %2$s', 'cletbot' ), $result->get_error_message(), Preview::url( $item ) ) );
			return;
		}
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => '' !== $result['title'] ? $result['title'] : $wp_post->post_title,
				'post_name'    => '' !== $result['slug'] ? sanitize_title( $result['slug'] ) : $wp_post->post_name,
				'post_content' => wp_kses_post( $result['content_html'] ),
				'post_excerpt' => $result['excerpt'],
			)
		);
		if ( '' !== $result['category'] && ( ! $cats || Parser::normalize( $result['category'] ) !== Parser::normalize( $cats[0] ) ) ) {
			wp_set_post_categories( $post_id, array( self::category_id( '', $result['category'] ) ) );
		}
		wp_set_post_tags( $post_id, $result['tags'], false );
		if ( '' !== $result['meta_description'] ) {
			update_post_meta( $post_id, '_cletbot_meta_description', $result['meta_description'] );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $result['meta_description'] );
			update_post_meta( $post_id, 'rank_math_description', $result['meta_description'] );
		}
		delete_post_meta( $item, '_cletbot_revision' );
		delete_post_meta( $item, '_cletbot_revision_target' );
		Preview::issue( $item );
		Items::set_state( $item, 'awaiting' );
		Log::info( __( 'Cambios aplicados', 'cletbot' ), '#' . $item );
		self::send( $target, self::preview_message( $item, '' !== $result['notes'] ? array( '📝 ' . $result['notes'] ) : array(), true ) );
	}

	private static function ai_context() {
		$categories = array();
		foreach ( get_categories( array( 'hide_empty' => false ) ) as $cat ) {
			$categories[] = $cat->name;
		}
		return array(
			'site_name'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_description' => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
			'guidelines'       => (string) Settings::get( 'guidelines' ),
			'categories'       => $categories,
		);
	}

	/** Without AI: the message text (or the document) as is, with basic formatting. */
	private static function basic_post( array $parsed, array $docs ) {
		$html  = '' !== trim( $parsed['body'] ) ? Parser::text_to_html( $parsed['body'] ) : '';
		$first = '';
		foreach ( $docs as $doc ) {
			$html .= '' !== trim( $doc['html'] ) ? "\n" . $doc['html'] : ( '' !== trim( $doc['text'] ) ? "\n" . Parser::text_to_html( $doc['text'] ) : '' );
			if ( '' === $first ) {
				$first = '' !== trim( $doc['text'] ) ? $doc['text'] : preg_replace( '/\.[^.]+$/', '', $doc['name'] );
			}
		}
		$title = '' !== $parsed['title'] ? $parsed['title'] : Parser::guess_title( '' !== trim( $parsed['body'] ) ? $parsed['body'] : $first, __( 'Nueva entrada', 'cletbot' ) );
		// If the title is just the first line, don't repeat it as the first paragraph.
		$html = preg_replace( '#^\s*<p>' . preg_quote( esc_html( $title ), '#' ) . '</p>\s*#u', '', trim( $html ) );
		return array(
			'title'            => $title,
			'slug'             => '',
			'excerpt'          => '',
			'content_html'     => $html,
			'category'         => '',
			'tags'             => array(),
			'meta_description' => '',
			'notes'            => '',
		);
	}

	/**
	 * The category the sender asked for, else the one the AI picked, else the default one.
	 */
	private static function category_id( $wanted, $suggested ) {
		$cats = array();
		foreach ( get_categories( array( 'hide_empty' => false ) ) as $cat ) {
			$cats[] = array( 'id' => $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug );
		}
		if ( '' !== trim( $wanted ) ) {
			$id = Parser::match_category( $wanted, $cats );
			if ( $id ) {
				return $id;
			}
			if ( Settings::get( 'create_category' ) ) {
				$term = wp_insert_term( sanitize_text_field( $wanted ), 'category' );
				if ( ! is_wp_error( $term ) ) {
					Log::info( __( 'Categoría creada', 'cletbot' ), $wanted );
					return (int) $term['term_id'];
				}
			}
		}
		if ( '' !== trim( $suggested ) ) {
			$id = Parser::match_category( $suggested, $cats );
			if ( $id ) {
				return $id;
			}
		}
		$default = (int) Settings::get( 'default_category' );
		return $default && term_exists( $default, 'category' ) ? $default : (int) get_option( 'default_category' );
	}

	private static function author( $sender ) {
		$fixed = (int) Settings::get( 'author' );
		if ( $fixed && get_userdata( $fixed ) ) {
			return $fixed;
		}
		if ( is_email( $sender ) ) {
			$user = get_user_by( 'email', $sender );
			if ( $user && user_can( $user, 'edit_posts' ) ) {
				return $user->ID;
			}
		}
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) );
		return $admins ? (int) $admins[0] : 1;
	}

	/** Photos go to the media library (the first one becomes the featured image); documents optionally get a download link. */
	private static function add_media( $post_id, array $images, array $files ) {
		if ( ! $images && ! ( $files && Settings::get( 'attach_original' ) ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$gallery = array();
		foreach ( $images as $i => $img ) {
			$id = self::sideload( $img['path'], $img['name'], $post_id );
			if ( ! $id ) {
				continue;
			}
			if ( ! has_post_thumbnail( $post_id ) ) {
				set_post_thumbnail( $post_id, $id );
			} else {
				$gallery[] = $id;
			}
		}
		$append = '';
		if ( $gallery ) {
			$append .= "\n\n" . '[gallery ids="' . implode( ',', $gallery ) . '"]';
		}
		if ( Settings::get( 'attach_original' ) ) {
			foreach ( $files as $file ) {
				$id = self::sideload( $file['path'], $file['name'], $post_id );
				if ( $id ) {
					$append .= "\n\n" . sprintf( '<p><a href="%s">%s</a></p>', esc_url( wp_get_attachment_url( $id ) ), esc_html( sprintf( /* translators: %s: file name */ __( 'Descargar %s', 'cletbot' ), $file['name'] ) ) );
				}
			}
		}
		if ( '' !== $append ) {
			$post = get_post( $post_id );
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $post->post_content . $append,
				)
			);
		}
	}

	private static function sideload( $path, $name, $post_id ) {
		// media_handle_sideload() moves the file, so give it a copy.
		$tmp = wp_tempnam( $name );
		if ( ! $tmp || ! copy( $path, $tmp ) ) {
			return 0;
		}
		$id = media_handle_sideload(
			array(
				'name'     => sanitize_file_name( $name ),
				'tmp_name' => $tmp,
			),
			$post_id
		);
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			Log::error( $id->get_error_message(), $name );
			return 0;
		}
		return (int) $id;
	}

	/* ------------------------------------------------------------------ messages */

	private static function preview_message( $item, array $notes = array(), $revised = false ) {
		$post_id = Items::post_id( $item );
		$cats    = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
		$code    = get_post_meta( $item, '_cletbot_code', true );
		$lines   = array(
			$revised ? __( '✅ Apliqué los cambios:', 'cletbot' ) : __( '✅ Listo, preparé esta entrada:', 'cletbot' ),
			'*' . get_the_title( $post_id ) . '*',
			sprintf( /* translators: %s: category */ __( 'Categoría: %s', 'cletbot' ), $cats ? implode( ', ', $cats ) : '—' ),
			'',
			sprintf( /* translators: %s: URL */ __( '👀 Vista previa: %s', 'cletbot' ), Preview::url( $item ) ),
		);
		if ( $notes ) {
			$lines[] = '';
			$lines   = array_merge( $lines, $notes );
		}
		$lines[] = '';
		$lines[] = __( '¿La publico? Responde:', 'cletbot' );
		$lines[] = __( '• *SI* para publicarla', 'cletbot' );
		$lines[] = __( '• *NO* para descartarla', 'cletbot' );
		$lines[] = __( '• *CAMBIOS: …* para pedir ajustes (p. ej. «CAMBIOS: hazla más corta»)', 'cletbot' );
		$lines[] = __( '• *CATEGORÍA: …* para cambiar la categoría', 'cletbot' );
		$lines[] = '';
		$lines[] = sprintf( /* translators: %s: code */ __( 'Código: %s', 'cletbot' ), $code );
		return implode( "\n", $lines );
	}

	public static function help_text() {
		return implode(
			"\n",
			array(
				__( '*CLETBOT* publica en el blog lo que me envíes.', 'cletbot' ),
				'',
				__( '1. Mándame el texto, un PDF, un Word o fotos. Puedes indicar al inicio:', 'cletbot' ),
				__( '   Categoría: Noticias', 'cletbot' ),
				__( '   Título: … (opcional)', 'cletbot' ),
				__( '   Etiquetas: … (opcional)', 'cletbot' ),
				__( '   Notas: indicaciones para redactarla (opcional)', 'cletbot' ),
				__( '2. Te respondo con el enlace a la vista previa.', 'cletbot' ),
				__( '3. Responde *SI* para publicar, *NO* para descartar, *CAMBIOS: …* para ajustarla o *CATEGORÍA: …* para cambiarla.', 'cletbot' ),
				'',
				__( 'Escribe *PENDIENTES* para ver lo que falta aprobar.', 'cletbot' ),
			)
		);
	}

	/* ------------------------------------------------------------------ sending */

	public static function target( $item ) {
		$payload = Items::payload( $item );
		$reply   = $payload['reply'];
		$code    = get_post_meta( $item, '_cletbot_code', true );
		if ( $code ) {
			$reply['code']  = $code;
			$reply['title'] = get_the_title( Items::post_id( $item ) );
		}
		return array(
			'channel' => get_post_meta( $item, '_cletbot_channel', true ),
			'to'      => get_post_meta( $item, '_cletbot_sender', true ),
			'reply'   => $reply,
		);
	}

	public static function send( array $target, $text ) {
		$text = (string) apply_filters( 'cletbot_message', $text, $target );
		if ( 'whatsapp' === $target['channel'] ) {
			$ok = WhatsApp::send( $target['to'], $text );
		} elseif ( 'email' === $target['channel'] ) {
			$ok = Email::send( $target['to'], $text, isset( $target['reply'] ) ? (array) $target['reply'] : array() );
		} else {
			$ok = false;
		}
		if ( ! $ok ) {
			Log::error( __( 'No se pudo enviar la respuesta', 'cletbot' ), $target['channel'] . ' ' . $target['to'] );
		}
		return $ok;
	}

	private static function cleanup_attachments( array $attachments ) {
		foreach ( $attachments as $att ) {
			if ( ! empty( $att['path'] ) && file_exists( $att['path'] ) ) {
				@unlink( $att['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/* ------------------------------------------------------------------ admin buttons */

	public static function admin_action() {
		$item = isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'cletbot_item_' . $item );
		if ( ! current_user_can( 'publish_posts' ) || get_post_type( $item ) !== Items::POST_TYPE ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'cletbot' ), 403 );
		}
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		$by = wp_get_current_user()->user_login;
		if ( 'approve' === $do && 'awaiting' === Items::state( $item ) ) {
			self::approve( $item, null, $by );
		} elseif ( 'reject' === $do && 'awaiting' === Items::state( $item ) ) {
			self::reject( $item, null, $by );
		} elseif ( 'retry' === $do ) {
			Items::set_state( $item, 'queued' );
			self::unlock( $item );
			self::schedule( $item );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Items::POST_TYPE ) );
		exit;
	}
}
