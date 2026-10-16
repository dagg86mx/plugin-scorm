<?php
/**
 * Every message or file that arrives becomes a "CLETBOT item" (private post type) that tracks
 * its state: queued → processing → awaiting (preview sent) → published / rejected, or error.
 *
 * @package Cletbot
 */

namespace Cletbot;

defined( 'ABSPATH' ) || exit;

class Items {

	const POST_TYPE = 'cletbot_item';
	const MENU      = 'edit.php?post_type=cletbot_item';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'cleanup_files' ) );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Envíos', 'cletbot' ),
					'singular_name' => __( 'Envío', 'cletbot' ),
					'search_items'  => __( 'Buscar envíos', 'cletbot' ),
					'not_found'     => __( 'Todavía no ha llegado nada.', 'cletbot' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => self::MENU,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}

	public static function states() {
		return array(
			'queued'     => __( 'En cola', 'cletbot' ),
			'processing' => __( 'Preparando', 'cletbot' ),
			'awaiting'   => __( 'Esperando aprobación', 'cletbot' ),
			'published'  => __( 'Publicada', 'cletbot' ),
			'rejected'   => __( 'Descartada', 'cletbot' ),
			'error'      => __( 'Error', 'cletbot' ),
		);
	}

	/* ------------------------------------------------------------------ data */

	public static function create( array $msg ) {
		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => self::summary( $msg ),
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_cletbot_channel', $msg['channel'] );
		update_post_meta( $id, '_cletbot_sender', $msg['sender'] );
		update_post_meta( $id, '_cletbot_sender_name', isset( $msg['sender_name'] ) ? $msg['sender_name'] : '' );
		self::set_payload(
			$id,
			array(
				'text'        => isset( $msg['text'] ) ? $msg['text'] : '',
				'subject'     => isset( $msg['subject'] ) ? $msg['subject'] : '',
				'attachments' => isset( $msg['attachments'] ) ? $msg['attachments'] : array(),
				'reply'       => isset( $msg['reply'] ) ? $msg['reply'] : array(),
			)
		);
		self::set_state( $id, 'queued' );
		return $id;
	}

	/** WhatsApp: a follow-up message from the same person joins the item that is still waiting in the queue. */
	public static function merge( $id, array $msg ) {
		$payload = self::payload( $id );
		if ( ! empty( $msg['text'] ) ) {
			$payload['text'] = trim( $payload['text'] . "\n\n" . $msg['text'] );
		}
		if ( ! empty( $msg['attachments'] ) ) {
			$payload['attachments'] = array_merge( $payload['attachments'], $msg['attachments'] );
		}
		self::set_payload( $id, $payload );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => self::summary(
					array(
						'text'        => $payload['text'],
						'subject'     => $payload['subject'],
						'attachments' => $payload['attachments'],
					)
				),
			)
		);
	}

	public static function payload( $id ) {
		$p = get_post_meta( $id, '_cletbot_payload', true );
		return array_merge(
			array(
				'text'        => '',
				'subject'     => '',
				'attachments' => array(),
				'reply'       => array(),
			),
			is_array( $p ) ? $p : array()
		);
	}

	public static function set_payload( $id, array $payload ) {
		update_post_meta( $id, '_cletbot_payload', wp_slash( $payload ) );
	}

	public static function state( $id ) {
		return (string) get_post_meta( $id, '_cletbot_state', true );
	}

	public static function set_state( $id, $state, $error = '' ) {
		update_post_meta( $id, '_cletbot_state', $state );
		if ( 'error' === $state ) {
			update_post_meta( $id, '_cletbot_error', $error );
		} else {
			delete_post_meta( $id, '_cletbot_error' );
		}
	}

	public static function post_id( $id ) {
		return (int) get_post_meta( $id, '_cletbot_post_id', true );
	}

	public static function find_by_code( $code ) {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_cletbot_code', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => strtoupper( $code ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	public static function find_by_token( $token ) {
		if ( ! is_string( $token ) || strlen( $token ) < 20 ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_cletbot_token', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $token, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * @param string|string[] $states
	 * @return int[] newest first.
	 */
	public static function by_sender( $sender, $states, $limit = 20 ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'orderby'        => 'date',
					'order'          => 'DESC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'   => '_cletbot_sender',
							'value' => $sender,
						),
						array(
							'key'     => '_cletbot_state',
							'value'   => (array) $states,
							'compare' => 'IN',
						),
					),
				)
			)
		);
	}

	public static function awaiting( $limit = 50 ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'meta_key'       => '_cletbot_state', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => 'awaiting', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}

	private static function summary( array $msg ) {
		$subject = isset( $msg['subject'] ) ? trim( $msg['subject'] ) : '';
		if ( '' !== $subject ) {
			return wp_trim_words( $subject, 12, '…' );
		}
		$text = isset( $msg['text'] ) ? trim( $msg['text'] ) : '';
		if ( '' !== $text ) {
			return wp_trim_words( $text, 12, '…' );
		}
		if ( ! empty( $msg['attachments'] ) ) {
			return implode( ', ', wp_list_pluck( $msg['attachments'], 'name' ) );
		}
		return __( '(sin contenido)', 'cletbot' );
	}

	/** Temporary files downloaded for an item. */
	public static function tmp_dir( $id ) {
		$dir = self::tmp_root() . '/' . (int) $id . '-' . wp_hash( 'cletbot-tmp-' . $id );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/** uploads/cletbot-tmp, closed to the web: files people send stay private until they're approved. */
	public static function tmp_root() {
		$upload = wp_upload_dir();
		$root   = trailingslashit( $upload['basedir'] ) . 'cletbot-tmp';
		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $root . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $root;
	}

	public static function cleanup_files( $id ) {
		if ( get_post_type( $id ) !== self::POST_TYPE ) {
			return;
		}
		$dir = self::tmp_root() . '/' . (int) $id . '-' . wp_hash( 'cletbot-tmp-' . $id );
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/* ------------------------------------------------------------------ admin list */

	public static function columns( $cols ) {
		return array(
			'cb'             => $cols['cb'],
			'title'          => __( 'Envío', 'cletbot' ),
			'cletbot_state'  => __( 'Estado', 'cletbot' ),
			'cletbot_from'   => __( 'Remitente', 'cletbot' ),
			'cletbot_post'   => __( 'Entrada', 'cletbot' ),
			'cletbot_code'   => __( 'Código', 'cletbot' ),
			'date'           => $cols['date'],
		);
	}

	public static function column( $col, $id ) {
		switch ( $col ) {
			case 'cletbot_state':
				$states = self::states();
				$state  = self::state( $id );
				printf( '<span class="cletbot-state cletbot-state-%s">%s</span>', esc_attr( $state ), esc_html( isset( $states[ $state ] ) ? $states[ $state ] : $state ) );
				$err = get_post_meta( $id, '_cletbot_error', true );
				if ( $err ) {
					echo '<br><small>' . esc_html( $err ) . '</small>';
				}
				break;
			case 'cletbot_from':
				$channel = get_post_meta( $id, '_cletbot_channel', true );
				$name    = get_post_meta( $id, '_cletbot_sender_name', true );
				echo esc_html( ( 'whatsapp' === $channel ? 'WhatsApp' : __( 'Correo', 'cletbot' ) ) . ': ' . ( $name ? $name . ' · ' : '' ) . get_post_meta( $id, '_cletbot_sender', true ) );
				break;
			case 'cletbot_post':
				$post_id = self::post_id( $id );
				if ( $post_id && get_post( $post_id ) ) {
					printf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $post_id ) ), esc_html( get_the_title( $post_id ) ) );
					if ( 'awaiting' === self::state( $id ) ) {
						printf( ' · <a href="%s" target="_blank">%s</a>', esc_url( Preview::url( $id ) ), esc_html__( 'Vista previa', 'cletbot' ) );
					}
				} else {
					echo '—';
				}
				break;
			case 'cletbot_code':
				echo '<code>' . esc_html( get_post_meta( $id, '_cletbot_code', true ) ) . '</code>';
				break;
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'], $actions['edit'] );
		$state = self::state( $post->ID );
		$base  = admin_url( 'admin-post.php?action=cletbot_item&item=' . $post->ID );
		if ( in_array( $state, array( 'error', 'queued' ), true ) ) {
			$actions['cletbot_retry'] = sprintf( '<a href="%s">%s</a>', esc_url( wp_nonce_url( $base . '&do=retry', 'cletbot_item_' . $post->ID ) ), esc_html__( 'Reintentar', 'cletbot' ) );
		}
		if ( 'awaiting' === $state ) {
			$actions['cletbot_publish'] = sprintf( '<a href="%s">%s</a>', esc_url( wp_nonce_url( $base . '&do=approve', 'cletbot_item_' . $post->ID ) ), esc_html__( 'Publicar', 'cletbot' ) );
			$actions['cletbot_reject']  = sprintf( '<a href="%s">%s</a>', esc_url( wp_nonce_url( $base . '&do=reject', 'cletbot_item_' . $post->ID ) ), esc_html__( 'Descartar', 'cletbot' ) );
		}
		return $actions;
	}
}
