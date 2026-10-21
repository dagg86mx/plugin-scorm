<?php
/**
 * "My data" (general data) tab inside the Tutor LMS student dashboard.
 *
 * @package ReticulaAcademica
 */

defined( 'ABSPATH' ) || exit;

echo ReticulaAcademica\Profile::render_form(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendering.
