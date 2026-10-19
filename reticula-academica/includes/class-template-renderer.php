<?php
/**
 * Placeholder replacement and Spanish dates for document templates (no WordPress dependencies).
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || defined( 'RA_TESTING' ) || exit;

class Template_Renderer {

	/**
	 * Placeholders whose value is block-level HTML (tables, signature blocks…).
	 */
	const BLOCK_KEYS = array( 'tabla_calificaciones', 'tabla_calificaciones_resumen', 'tabla_boleta', 'firmas', 'firma_1', 'firma_2' );

	const MONTHS = array( 1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );

	/**
	 * Replace {placeholders}. Values must already be HTML-safe; unknown placeholders are left untouched.
	 *
	 * @param string $html Template HTML.
	 * @param array  $vars key => HTML value.
	 * @return string
	 */
	public static function render( $html, array $vars ) {
		// Block placeholders alone in a paragraph would produce <p><table>…</table></p>: drop the paragraph.
		foreach ( self::BLOCK_KEYS as $key ) {
			if ( isset( $vars[ $key ] ) ) {
				$html = preg_replace( '#<p[^>]*>\s*\{' . preg_quote( $key, '#' ) . '\}\s*</p>#u', '{' . $key . '}', $html );
			}
		}
		return preg_replace_callback(
			'/\{([a-z0-9_]+)\}/',
			static function ( $m ) use ( $vars ) {
				return array_key_exists( $m[1], $vars ) ? (string) $vars[ $m[1] ] : $m[0];
			},
			$html
		);
	}

	/**
	 * "19 de octubre de 2026".
	 */
	public static function long_date( $timestamp ) {
		$timestamp = (int) $timestamp;
		return (int) gmdate( 'j', $timestamp ) . ' de ' . self::MONTHS[ (int) gmdate( 'n', $timestamp ) ] . ' de ' . gmdate( 'Y', $timestamp );
	}

	/**
	 * Spanish ordinal for term numbers: 1 → "primer", 2 → "segundo"…
	 */
	public static function ordinal( $number ) {
		$words = array( 1 => 'primer', 'segundo', 'tercer', 'cuarto', 'quinto', 'sexto', 'séptimo', 'octavo', 'noveno', 'décimo', 'undécimo', 'duodécimo' );
		return isset( $words[ (int) $number ] ) ? $words[ (int) $number ] : $number . 'º';
	}

	/**
	 * Folio such as CON-2026-000123.
	 */
	public static function folio( $prefix, $year, $id ) {
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $prefix ) );
		return ( '' !== $prefix ? $prefix . '-' : '' ) . $year . '-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT );
	}
}
