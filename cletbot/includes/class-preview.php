<?php
/**
 * Secret preview links (/?cletbot_preview=TOKEN) that show the pending post to anyone holding
 * the link, without logging in, plus the optional "Publicar / Descartar" bar on that page.
 *
 * @package Cletbot
 */

namespace Cletbot;

defined( 'ABSPATH' ) || exit;

class Preview {

	const QUERY_VAR = 'cletbot_preview';

	/** Item being previewed in this request. */
	private static $item = 0;

	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'request', array( __CLASS__, 'request' ) );
		add_filter( 'the_posts', array( __CLASS__, 'the_posts' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ), 1 );
	}

	/** Creates the token the first time; later calls (after a revision) keep the same link and extend it. */
	public static function issue( $item ) {
		if ( ! get_post_meta( $item, '_cletbot_token', true ) ) {
			update_post_meta( $item, '_cletbot_token', wp_generate_password( 32, false ) );
		}
		update_post_meta( $item, '_cletbot_expires', time() + DAY_IN_SECONDS * max( 1, (int) Settings::get( 'preview_days' ) ) );
	}

	public static function url( $item ) {
		$token = get_post_meta( $item, '_cletbot_token', true );
		return $token ? add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) ) : '';
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** Turns /?cletbot_preview=TOKEN into a single-post query for the pending post. */
	public static function request( $vars ) {
		if ( empty( $vars[ self::QUERY_VAR ] ) || is_admin() ) {
			return $vars;
		}
		$item = Items::find_by_token( (string) $vars[ self::QUERY_VAR ] );
		if ( ! $item ) {
			return array( 'error' => '404' );
		}
		self::$item = $item;
		$post_id    = Items::post_id( $item );
		$state      = Items::state( $item );
		if ( 'published' === $state ) {
			return array( 'p' => $post_id );
		}
		if ( ! in_array( $state, array( 'awaiting', 'processing' ), true ) || (int) get_post_meta( $item, '_cletbot_expires', true ) < time() || ! get_post( $post_id ) ) {
			return array( 'error' => '404' );
		}
		return array(
			'p'               => $post_id,
			'post_type'       => 'post',
			'post_status'     => array( 'pending', 'draft', 'future', 'publish' ),
			self::QUERY_VAR   => $vars[ self::QUERY_VAR ],
		);
	}

	/** Present the post as published so themes render it normally (comments, dates…). */
	public static function the_posts( $posts, $query ) {
		if ( ! self::$item || ! $query->is_main_query() || count( $posts ) !== 1 ) {
			return $posts;
		}
		if ( (int) $posts[0]->ID === Items::post_id( self::$item ) && 'publish' !== $posts[0]->post_status ) {
			$posts[0]->post_status = 'publish';
			if ( '0000-00-00 00:00:00' === $posts[0]->post_date_gmt ) {
				$posts[0]->post_date_gmt = current_time( 'mysql', true );
			}
		}
		return $posts;
	}

	public static function template_redirect() {
		if ( ! self::$item ) {
			return;
		}
		$item  = self::$item;
		$state = Items::state( $item );

		if ( 'published' === $state ) {
			wp_safe_redirect( get_permalink( Items::post_id( $item ) ), 302 );
			exit;
		}

		remove_action( 'template_redirect', 'redirect_canonical' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'comments_open', '__return_false' );

		// "Publicar" / "Descartar" buttons. They POST (link previews and mail scanners only GET),
		// and the secret token in the URL is what authorizes them.
		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) && isset( $_POST['cletbot_decision'] ) && Settings::get( 'preview_buttons' ) ) { // phpcs:ignore
			$token = isset( $_POST['cletbot_token'] ) ? sanitize_text_field( wp_unslash( $_POST['cletbot_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! hash_equals( (string) get_post_meta( $item, '_cletbot_token', true ), $token ) || 'awaiting' !== $state ) {
				self::page( __( 'Este enlace ya no es válido.', 'cletbot' ) );
			}
			$decision = sanitize_key( wp_unslash( $_POST['cletbot_decision'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'approve' === $decision ) {
				Workflow::approve( $item, null, __( 'botón de la vista previa', 'cletbot' ) );
				wp_safe_redirect( get_permalink( Items::post_id( $item ) ), 303 );
				exit;
			}
			if ( 'reject' === $decision ) {
				Workflow::reject( $item, null, __( 'botón de la vista previa', 'cletbot' ) );
				self::page( __( 'La entrada se descartó. No se publicó nada.', 'cletbot' ) );
			}
		}

		if ( Settings::get( 'preview_buttons' ) || 'processing' === $state ) {
			add_action( 'wp_footer', array( __CLASS__, 'bar' ) );
			add_action( 'wp_head', array( __CLASS__, 'bar_css' ) );
		}
	}

	private static function page( $message ) {
		wp_die( '<p>' . esc_html( $message ) . '</p><p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a></p>', esc_html__( 'CLETBOT', 'cletbot' ), array( 'response' => 200 ) );
	}

	public static function bar_css() {
		echo '<style id="cletbot-preview-css">' . file_get_contents( CLETBOT_DIR . 'assets/css/preview.css' ) . '</style>'; // phpcs:ignore
	}

	public static function bar() {
		$item  = self::$item;
		$state = Items::state( $item );
		$token = get_post_meta( $item, '_cletbot_token', true );
		echo '<div class="cletbot-bar" role="region" aria-label="CLETBOT">';
		echo '<div class="cletbot-bar__text"><strong>' . esc_html__( 'Vista previa', 'cletbot' ) . '</strong> · ';
		if ( 'processing' === $state ) {
			echo esc_html__( 'Estoy aplicando cambios; recarga en un momento.', 'cletbot' ) . '</div></div>';
			return;
		}
		echo esc_html__( 'Esta entrada todavía no es pública.', 'cletbot' ) . ' <span class="cletbot-bar__code">' . esc_html( sprintf( /* translators: %s: code */ __( 'Código %s', 'cletbot' ), get_post_meta( $item, '_cletbot_code', true ) ) ) . '</span></div>';
		if ( Settings::get( 'preview_buttons' ) && 'awaiting' === $state ) {
			$action = esc_url( self::url( $item ) );
			echo '<form method="post" action="' . $action . '" class="cletbot-bar__actions">'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<input type="hidden" name="cletbot_token" value="' . esc_attr( $token ) . '">';
			echo '<button type="submit" name="cletbot_decision" value="reject" class="cletbot-btn cletbot-btn--ghost" onclick="return confirm(\'' . esc_js( __( '¿Descartar esta entrada?', 'cletbot' ) ) . '\')">' . esc_html__( 'Descartar', 'cletbot' ) . '</button>';
			echo '<button type="submit" name="cletbot_decision" value="approve" class="cletbot-btn" onclick="return confirm(\'' . esc_js( __( '¿Publicar esta entrada ahora?', 'cletbot' ) ) . '\')">' . esc_html__( 'Publicar', 'cletbot' ) . '</button>';
			echo '</form>';
		}
		echo '</div>';
	}
}
