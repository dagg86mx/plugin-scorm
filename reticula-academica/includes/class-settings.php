<?php
/**
 * Plugin settings: institution, student ID (matrícula), signatures, grading and school periods.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'ra_settings';
	const PAGE   = 'ra-settings';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ra_assign_missing_ids', array( __CLASS__, 'handle_assign_missing' ) );
	}

	public static function defaults() {
		return array(
			'institution'      => get_bloginfo( 'name' ),
			'logo_id'          => 0,
			'city'             => '',
			'cct'              => '',
			'seal_image'       => 0,
			'qr_url'           => '',
			'id_prefix'        => 'UNTGU-',
			'id_start'         => 10000,
			'id_assign'        => 'inscription',
			'sign1_name'       => '',
			'sign1_title'      => '',
			'sign1_image'      => 0,
			'sign2_name'       => '',
			'sign2_title'      => '',
			'sign2_image'      => 0,
			'grade_scale'      => 10,
			'grade_passing'    => 7,
			'grade_decimals'   => 1,
			'grade_attempt'    => 'best',
			'grade_scorm'      => 1,
			'periods'          => '',
			'grade_show_items' => 1,
			'records_reminder_days' => 30,
			'records_notify'   => get_option( 'admin_email' ),
			'records_review_emails' => 1,
			'records_subject'  => __( 'Pending documents in your student file', 'reticula-academica' ),
			'records_body'     => __( "Hello {nombre},\n\nYour student file at {institucion} is still missing these documents:\n\n{documentos}\n\nUpload them here: {enlace}\n\nThank you.", 'reticula-academica' ),
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function register() {
		register_setting(
			'ra_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$d     = self::defaults();
		$text  = static function ( $key ) use ( $input, $d ) {
			return isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : $d[ $key ];
		};
		$scale = isset( $input['grade_scale'] ) ? (int) $input['grade_scale'] : 10;
		$scale = in_array( $scale, array( 10, 100 ), true ) ? $scale : 10;

		return array(
			'institution'    => $text( 'institution' ),
			'logo_id'        => absint( isset( $input['logo_id'] ) ? $input['logo_id'] : 0 ),
			'city'           => $text( 'city' ),
			'cct'            => strtoupper( $text( 'cct' ) ),
			'seal_image'     => absint( isset( $input['seal_image'] ) ? $input['seal_image'] : 0 ),
			'qr_url'         => isset( $input['qr_url'] ) ? esc_url_raw( str_replace( array( '{folio}', '{verificacion_url}' ), array( 'RAFOLIO', 'RAVERIFY' ), wp_unslash( $input['qr_url'] ) ) ) : '',
			'id_prefix'      => preg_replace( '/[^A-Za-z0-9\-_\/\.]/', '', $text( 'id_prefix' ) ),
			'id_start'       => max( 1, absint( isset( $input['id_start'] ) ? $input['id_start'] : $d['id_start'] ) ),
			'id_assign'      => ( isset( $input['id_assign'] ) && 'registration' === $input['id_assign'] ) ? 'registration' : 'inscription',
			'sign1_name'     => $text( 'sign1_name' ),
			'sign1_title'    => $text( 'sign1_title' ),
			'sign1_image'    => absint( isset( $input['sign1_image'] ) ? $input['sign1_image'] : 0 ),
			'sign2_name'     => $text( 'sign2_name' ),
			'sign2_title'    => $text( 'sign2_title' ),
			'sign2_image'    => absint( isset( $input['sign2_image'] ) ? $input['sign2_image'] : 0 ),
			'grade_scale'    => $scale,
			'grade_passing'  => max( 0, min( $scale, (float) ( isset( $input['grade_passing'] ) ? $input['grade_passing'] : $d['grade_passing'] ) ) ),
			'grade_decimals' => max( 0, min( 2, absint( isset( $input['grade_decimals'] ) ? $input['grade_decimals'] : 1 ) ) ),
			'grade_attempt'  => ( isset( $input['grade_attempt'] ) && 'last' === $input['grade_attempt'] ) ? 'last' : 'best',
			'grade_scorm'    => empty( $input['grade_scorm'] ) ? 0 : 1,
			'periods'        => isset( $input['periods'] ) ? sanitize_textarea_field( wp_unslash( $input['periods'] ) ) : '',
			'grade_show_items' => empty( $input['grade_show_items'] ) ? 0 : 1,
			'records_reminder_days' => min( 365, absint( isset( $input['records_reminder_days'] ) ? $input['records_reminder_days'] : $d['records_reminder_days'] ) ),
			'records_notify'   => implode( ', ', array_filter( array_map( 'sanitize_email', explode( ',', isset( $input['records_notify'] ) ? wp_unslash( $input['records_notify'] ) : '' ) ) ) ),
			'records_review_emails' => empty( $input['records_review_emails'] ) ? 0 : 1,
			'records_subject'  => $text( 'records_subject' ),
			'records_body'     => isset( $input['records_body'] ) ? sanitize_textarea_field( wp_unslash( $input['records_body'] ) ) : $d['records_body'],
		);
	}

	/**
	 * School periods parsed from "Name | YYYY-MM-DD | YYYY-MM-DD" lines.
	 *
	 * @return array[] [ name, start, end ]
	 */
	public static function periods() {
		$periods = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( 'periods' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( '' === $parts[0] ) {
				continue;
			}
			$periods[] = array(
				'name'  => $parts[0],
				'start' => isset( $parts[1] ) ? $parts[1] : '',
				'end'   => isset( $parts[2] ) ? $parts[2] : '',
			);
		}
		return $periods;
	}

	/**
	 * Name of the school period that contains a date (defaults to today).
	 */
	public static function current_period( $date = '' ) {
		$date = $date ? $date : current_time( 'Y-m-d' );
		foreach ( self::periods() as $period ) {
			if ( $period['start'] && $period['end'] && $date >= $period['start'] && $date <= $period['end'] ) {
				return $period['name'];
			}
		}
		return '';
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . Curriculum::PROGRAM, __( 'Settings', 'reticula-academica' ), __( 'Settings', 'reticula-academica' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::PAGE ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'ra-admin', RA_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable' ), RA_VERSION, true );
			wp_localize_script( 'ra-admin', 'RAAdmin', array( 'remove' => __( 'Remove', 'reticula-academica' ), 'choose' => __( 'Choose image', 'reticula-academica' ) ) );
		}
	}

	private static function image_field( $key, $value ) {
		$url = $value ? wp_get_attachment_image_url( $value, 'medium' ) : '';
		printf(
			'<div class="ra-image-field"><input type="hidden" name="%1$s[%2$s]" value="%3$d"><img src="%4$s" style="max-height:70px;%5$s" alt=""><br><button type="button" class="button ra-pick-image">%6$s</button> <button type="button" class="button-link ra-clear-image">%7$s</button></div>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			(int) $value,
			esc_url( (string) $url ),
			$url ? '' : 'display:none',
			esc_html__( 'Choose image', 'reticula-academica' ),
			esc_html__( 'Remove', 'reticula-academica' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::all();
		$n = self::OPTION;
		$f = static function ( $key, $type = 'text', $class = 'regular-text' ) use ( $s, $n ) {
			printf( '<input type="%1$s" name="%2$s[%3$s]" id="ra-%3$s" value="%4$s" class="%5$s"%6$s>', esc_attr( $type ), esc_attr( $n ), esc_attr( $key ), esc_attr( $s[ $key ] ), esc_attr( $class ), 'number' === $type ? ' step="any"' : '' );
		};
		$next = Student_Id::format( Student_Id::next_number() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Academic Grid settings', 'reticula-academica' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'ra_settings' ); ?>

				<h2><?php esc_html_e( 'Institution', 'reticula-academica' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="ra-institution"><?php esc_html_e( 'Institution name', 'reticula-academica' ); ?></label></th><td><?php $f( 'institution' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Logo', 'reticula-academica' ); ?></th><td><?php self::image_field( 'logo_id', $s['logo_id'] ); ?><p class="description"><?php esc_html_e( 'Printed in documents with {logo}. Each template can use its own logo and university name.', 'reticula-academica' ); ?></p></td></tr>
					<tr><th><label for="ra-city"><?php esc_html_e( 'City (for document dates)', 'reticula-academica' ); ?></label></th><td><?php $f( 'city' ); ?> <p class="description"><?php esc_html_e( 'Example: Ciudad de México. Documents print "Ciudad de México, a 19 de octubre de 2026".', 'reticula-academica' ); ?></p></td></tr>
					<tr><th><label for="ra-cct"><?php esc_html_e( 'CCT (work center code)', 'reticula-academica' ); ?></label></th><td><?php $f( 'cct', 'text', 'regular-text' ); ?><p class="description"><?php esc_html_e( 'Default for {cct}. Each program can have its own.', 'reticula-academica' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Institutional seal', 'reticula-academica' ); ?></th><td><?php self::image_field( 'seal_image', $s['seal_image'] ); ?><p class="description"><?php esc_html_e( 'Printed with {sello}.', 'reticula-academica' ); ?></p></td></tr>
					<tr><th><label for="ra-qr_url"><?php esc_html_e( 'QR code link', 'reticula-academica' ); ?></label></th><td>
						<input type="url" name="<?php echo esc_attr( $n ); ?>[qr_url]" id="ra-qr_url" value="<?php echo esc_attr( str_replace( array( 'RAFOLIO', 'RAVERIFY' ), array( '{folio}', '{verificacion_url}' ), $s['qr_url'] ) ); ?>" class="large-text" placeholder="https://wa.me/5215512345678?text=Verificar%20{folio}">
						<p class="description"><?php esc_html_e( 'Where the {qr} code of documents points, e.g. the WhatsApp of School Services. {folio} is replaced with the document number. Empty = the public verification page of the document.', 'reticula-academica' ); ?></p>
					</td></tr>
				</table>

				<h2><?php esc_html_e( 'Student ID (matrícula)', 'reticula-academica' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="ra-id_prefix"><?php esc_html_e( 'Prefix', 'reticula-academica' ); ?></label></th><td><?php $f( 'id_prefix', 'text', 'small-text' ); ?></td></tr>
					<tr><th><label for="ra-id_start"><?php esc_html_e( 'First number', 'reticula-academica' ); ?></label></th><td><?php $f( 'id_start', 'number', 'small-text' ); ?>
						<p class="description">
							<?php
							/* translators: %s: next student ID. */
							echo esc_html( sprintf( __( 'IDs are consecutive. Next ID to be assigned: %s', 'reticula-academica' ), $next ) );
							?>
						</p></td></tr>
					<tr><th><?php esc_html_e( 'Assign automatically', 'reticula-academica' ); ?></th><td>
						<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[id_assign]" value="inscription" <?php checked( $s['id_assign'], 'inscription' ); ?>> <?php esc_html_e( 'When the student is enrolled in a program', 'reticula-academica' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[id_assign]" value="registration" <?php checked( $s['id_assign'], 'registration' ); ?>> <?php esc_html_e( 'When any user registers', 'reticula-academica' ); ?></label>
					</td></tr>
				</table>

				<h2><?php esc_html_e( 'Signatures for documents', 'reticula-academica' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php foreach ( array( 1, 2 ) as $i ) : ?>
						<tr>
							<th>
								<?php
								/* translators: %d: signature number. */
								echo esc_html( sprintf( __( 'Signature %d', 'reticula-academica' ), $i ) );
								?>
							</th>
							<td>
								<p><label><?php esc_html_e( 'Name', 'reticula-academica' ); ?><br><?php $f( 'sign' . $i . '_name' ); ?></label></p>
								<p><label><?php esc_html_e( 'Position', 'reticula-academica' ); ?><br><?php $f( 'sign' . $i . '_title' ); ?></label></p>
								<p><?php esc_html_e( 'Signature image (PNG with transparent background recommended)', 'reticula-academica' ); ?></p>
								<?php self::image_field( 'sign' . $i . '_image', $s[ 'sign' . $i . '_image' ] ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Grades', 'reticula-academica' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="ra-grade_scale"><?php esc_html_e( 'Grade scale', 'reticula-academica' ); ?></label></th><td>
						<select name="<?php echo esc_attr( $n ); ?>[grade_scale]" id="ra-grade_scale">
							<option value="10" <?php selected( (int) $s['grade_scale'], 10 ); ?>>0 – 10</option>
							<option value="100" <?php selected( (int) $s['grade_scale'], 100 ); ?>>0 – 100</option>
						</select></td></tr>
					<tr><th><label for="ra-grade_passing"><?php esc_html_e( 'Minimum passing grade', 'reticula-academica' ); ?></label></th><td><?php $f( 'grade_passing', 'number', 'small-text' ); ?></td></tr>
					<tr><th><label for="ra-grade_decimals"><?php esc_html_e( 'Decimals', 'reticula-academica' ); ?></label></th><td><?php $f( 'grade_decimals', 'number', 'small-text' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Quiz attempt that counts', 'reticula-academica' ); ?></th><td>
						<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[grade_attempt]" value="best" <?php checked( $s['grade_attempt'], 'best' ); ?>> <?php esc_html_e( 'Best attempt', 'reticula-academica' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[grade_attempt]" value="last" <?php checked( $s['grade_attempt'], 'last' ); ?>> <?php esc_html_e( 'Last attempt', 'reticula-academica' ); ?></label>
					</td></tr>
					<tr><th><?php esc_html_e( 'Quiz breakdown for students', 'reticula-academica' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[grade_show_items]" value="1" <?php checked( $s['grade_show_items'], 1 ); ?>> <?php esc_html_e( 'Show each quiz, its weight and grade in "My program" and on course pages. Unchecked: only the final grade of each course.', 'reticula-academica' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'SCORM evaluations', 'reticula-academica' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[grade_scorm]" value="1" <?php checked( $s['grade_scorm'], 1 ); ?>> <?php esc_html_e( 'Offer SCORM lessons (Tutor SCORM plugin) as gradable items in the weighting screen', 'reticula-academica' ); ?></label></td></tr>
				</table>

				<h2><?php esc_html_e( 'Student files', 'reticula-academica' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="ra-records_reminder_days"><?php esc_html_e( 'Reminder every (days)', 'reticula-academica' ); ?></label></th><td><?php $f( 'records_reminder_days', 'number', 'small-text' ); ?>
						<p class="description"><?php esc_html_e( 'Students with required documents missing or rejected get an email every this many days, counted from their inscription. 0 = no reminders. Documents being validated count as delivered.', 'reticula-academica' ); ?></p></td></tr>
					<tr><th><label for="ra-records_subject"><?php esc_html_e( 'Reminder subject', 'reticula-academica' ); ?></label></th><td><?php $f( 'records_subject', 'text', 'large-text' ); ?></td></tr>
					<tr><th><label for="ra-records_body"><?php esc_html_e( 'Reminder message', 'reticula-academica' ); ?></label></th><td>
						<textarea name="<?php echo esc_attr( $n ); ?>[records_body]" id="ra-records_body" rows="8" class="large-text"><?php echo esc_textarea( $s['records_body'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Fields: {nombre}, {matricula}, {institucion}, {documentos} (list of pending documents), {enlace} (link to the student file).', 'reticula-academica' ); ?></p></td></tr>
					<tr><th><label for="ra-records_notify"><?php esc_html_e( 'Notify new uploads to', 'reticula-academica' ); ?></label></th><td><?php $f( 'records_notify', 'text', 'large-text' ); ?>
						<p class="description"><?php esc_html_e( 'Emails separated by commas that receive a notice when a student uploads a document. Empty = no notices.', 'reticula-academica' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Review result', 'reticula-academica' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[records_review_emails]" value="1" <?php checked( $s['records_review_emails'], 1 ); ?>> <?php esc_html_e( 'Email the student when a document is validated or rejected (with the reason)', 'reticula-academica' ); ?></label></td></tr>
				</table>

				<h2><?php esc_html_e( 'School periods', 'reticula-academica' ); ?></h2>
				<p><?php esc_html_e( 'One per line: name | start date | end date. The current period fills the {periodo} field of documents.', 'reticula-academica' ); ?></p>
				<textarea name="<?php echo esc_attr( $n ); ?>[periods]" rows="5" class="large-text code" placeholder="Septiembre-Diciembre 2026 | 2026-09-01 | 2026-12-31"><?php echo esc_textarea( $s['periods'] ); ?></textarea>
				<?php
				$current = self::current_period();
				if ( $current ) {
					/* translators: %s: period name. */
					echo '<p class="description">' . esc_html( sprintf( __( 'Current period: %s', 'reticula-academica' ), $current ) ) . '</p>';
				}
				submit_button();
				?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Students without ID', 'reticula-academica' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ra_assign_missing_ids' ); ?>
				<input type="hidden" name="action" value="ra_assign_missing_ids">
				<p><?php esc_html_e( 'Assigns a consecutive ID to every student enrolled in a program that does not have one yet (in enrollment order).', 'reticula-academica' ); ?></p>
				<?php submit_button( __( 'Assign missing IDs', 'reticula-academica' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_assign_missing() {
		check_admin_referer( 'ra_assign_missing_ids' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		$count = Student_Id::assign_missing();
		/* translators: %d: number of students. */
		set_transient( 'ra_notice_' . get_current_user_id(), array( 'success', esc_html( sprintf( __( '%d student IDs assigned.', 'reticula-academica' ), $count ) ) ), 120 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Curriculum::PROGRAM . '&page=' . self::PAGE ) );
		exit;
	}
}
