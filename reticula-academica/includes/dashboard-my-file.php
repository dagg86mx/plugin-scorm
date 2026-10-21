<?php
/**
 * "My file" (expediente) tab inside the Tutor LMS student dashboard.
 *
 * @package ReticulaAcademica
 */

defined( 'ABSPATH' ) || exit;

echo ReticulaAcademica\Records::render_my_file(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendering.
