<?php
/**
 * Small activity log (last 200 events) shown in CLETBOT → Ajustes → Registro.
 *
 * @package Cletbot
 */

namespace Cletbot;

defined( 'ABSPATH' ) || exit;

class Log {

	const OPTION = 'cletbot_log';
	const MAX    = 200;

	public static function add( $level, $message, $context = '' ) {
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'level'   => $level,
				'message' => wp_strip_all_tags( (string) $message ),
				'context' => is_scalar( $context ) ? (string) $context : wp_json_encode( $context ),
			)
		);
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
		if ( 'error' === $level && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'CLETBOT: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	public static function info( $message, $context = '' ) {
		self::add( 'info', $message, $context );
	}

	public static function error( $message, $context = '' ) {
		self::add( 'error', $message, $context );
	}

	public static function render() {
		$log = get_option( self::OPTION, array() );
		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'Todavía no hay actividad.', 'cletbot' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped cletbot-log"><thead><tr><th>' . esc_html__( 'Fecha', 'cletbot' ) . '</th><th>' . esc_html__( 'Evento', 'cletbot' ) . '</th><th>' . esc_html__( 'Detalle', 'cletbot' ) . '</th></tr></thead><tbody>';
		foreach ( $log as $row ) {
			printf(
				'<tr class="level-%s"><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
				esc_attr( $row['level'] ),
				esc_html( wp_date( 'Y-m-d H:i:s', $row['time'] ) ),
				esc_html( $row['message'] ),
				esc_html( $row['context'] )
			);
		}
		echo '</tbody></table>';
	}
}
