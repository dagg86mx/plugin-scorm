<?php
/**
 * "My program" tab inside the Tutor LMS student dashboard.
 *
 * @package ReticulaAcademica
 */

defined( 'ABSPATH' ) || exit;

echo ReticulaAcademica\Frontend::render_my_programs(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendering.
