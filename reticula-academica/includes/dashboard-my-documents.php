<?php
/**
 * "My documents" tab inside the Tutor LMS student dashboard.
 *
 * @package ReticulaAcademica
 */

defined( 'ABSPATH' ) || exit;

echo ReticulaAcademica\Documents::render_my_documents(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendering.
