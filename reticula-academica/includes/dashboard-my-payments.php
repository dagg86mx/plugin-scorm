<?php
/**
 * "My payments" (tuition) tab inside the Tutor LMS student dashboard.
 *
 * @package ReticulaAcademica
 */

defined( 'ABSPATH' ) || exit;

echo ReticulaAcademica\Tuition::render_my_payments(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendering.
