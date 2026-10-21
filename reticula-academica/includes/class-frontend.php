<?php
/**
 * Student-facing screens: catalog, program page, "My program" (Tutor dashboard tab and shortcode)
 * and the notice shown on course pages.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Frontend {

	const DASHBOARD_SLUG = 'mi-carrera';
	const DOCUMENTS_SLUG = 'mis-documentos';
	const RECORDS_SLUG   = 'mi-expediente';
	const PAYMENTS_SLUG  = 'mis-pagos';
	const PROFILE_SLUG   = 'mis-datos';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_shortcode( 'reticula_carreras', array( __CLASS__, 'shortcode_catalog' ) );
		add_shortcode( 'reticula_mi_carrera', array( __CLASS__, 'shortcode_my_program' ) );
		add_shortcode( 'reticula_carrera', array( __CLASS__, 'shortcode_program' ) );
		add_filter( 'the_content', array( __CLASS__, 'program_content' ), 20 );
		add_filter( 'tutor_dashboard/nav_items', array( __CLASS__, 'dashboard_nav' ) );
		add_filter( 'load_dashboard_template_part_from_other_location', array( __CLASS__, 'dashboard_template' ) );
		add_action( 'tutor_course/single/before/inner-wrap', array( __CLASS__, 'course_notice' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_no_cache' ), 1 );
	}

	/**
	 * Student pages show live data (file status, documents, progress): keep them out of page caches.
	 */
	public static function maybe_no_cache() {
		$tab  = (string) get_query_var( 'tutor_dashboard_page' );
		$post = is_singular() ? get_post() : null;
		$live = in_array( $tab, array( self::DASHBOARD_SLUG, self::DOCUMENTS_SLUG, self::RECORDS_SLUG, self::PAYMENTS_SLUG, self::PROFILE_SLUG ), true );
		foreach ( array( 'reticula_expediente', 'reticula_documentos', 'reticula_mi_carrera', 'reticula_pagos', 'reticula_mis_datos' ) as $shortcode ) {
			$live = $live || ( $post && has_shortcode( (string) $post->post_content, $shortcode ) );
		}
		if ( $live ) {
			self::no_cache();
		}
	}

	public static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache…
		}
		do_action( 'litespeed_control_set_nocache', 'Retícula Académica: student data' );
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	public static function register_assets() {
		wp_register_style( 'reticula-academica', RA_URL . 'assets/css/reticula.css', array(), RA_VERSION );
		if ( is_singular( Curriculum::PROGRAM ) || is_post_type_archive( Curriculum::PROGRAM ) || ( Tutor::active() && function_exists( 'tutor_utils' ) && is_singular( Tutor::course_post_type() ) ) ) {
			wp_enqueue_style( 'reticula-academica' );
		}
	}

	private static function assets() {
		if ( ! wp_style_is( 'reticula-academica', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'reticula-academica' );
	}

	public static function maybe_flush_rewrite() {
		if ( get_option( 'ra_flush_rewrite' ) ) {
			delete_option( 'ra_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Tutor dashboard tab
	 * ------------------------------------------------------------------ */

	public static function dashboard_nav( $items ) {
		$new = array();
		foreach ( $items as $key => $item ) {
			$new[ $key ] = $item;
			if ( 'index' === $key ) {
				$new[ self::DASHBOARD_SLUG ] = array(
					'title'       => __( 'My program', 'reticula-academica' ),
					'icon'        => 'book',
					'active_icon' => 'book',
				);
			}
		}
		if ( ! isset( $new[ self::DASHBOARD_SLUG ] ) ) {
			$new[ self::DASHBOARD_SLUG ] = array(
				'title' => __( 'My program', 'reticula-academica' ),
				'icon'  => 'book',
			);
		}
		$with_docs = array();
		foreach ( $new as $key => $item ) {
			$with_docs[ $key ] = $item;
			if ( self::DASHBOARD_SLUG === $key ) {
				$with_docs[ self::DOCUMENTS_SLUG ] = array(
					'title'       => __( 'My documents', 'reticula-academica' ),
					'icon'        => 'certificate',
					'active_icon' => 'certificate',
				);
				$with_docs[ self::RECORDS_SLUG ]   = array(
					'title'       => __( 'My file', 'reticula-academica' ),
					'icon'        => 'document',
					'active_icon' => 'document',
				);
				$with_docs[ self::PAYMENTS_SLUG ]  = array(
					'title'       => __( 'My payments', 'reticula-academica' ),
					'icon'        => 'billing',
					'active_icon' => 'billing',
				);
				$with_docs[ self::PROFILE_SLUG ]   = array(
					'title'       => __( 'My data', 'reticula-academica' ),
					'icon'        => 'profile',
					'active_icon' => 'profile',
				);
			}
		}
		return $with_docs;
	}

	public static function dashboard_template( $location ) {
		if ( self::DASHBOARD_SLUG === get_query_var( 'tutor_dashboard_page' ) ) {
			return RA_DIR . 'includes/dashboard-my-program.php';
		}
		if ( self::DOCUMENTS_SLUG === get_query_var( 'tutor_dashboard_page' ) ) {
			self::assets();
			return RA_DIR . 'includes/dashboard-my-documents.php';
		}
		if ( self::RECORDS_SLUG === get_query_var( 'tutor_dashboard_page' ) ) {
			self::assets();
			return RA_DIR . 'includes/dashboard-my-file.php';
		}
		if ( self::PAYMENTS_SLUG === get_query_var( 'tutor_dashboard_page' ) ) {
			self::assets();
			return RA_DIR . 'includes/dashboard-my-payments.php';
		}
		if ( self::PROFILE_SLUG === get_query_var( 'tutor_dashboard_page' ) ) {
			self::assets();
			return RA_DIR . 'includes/dashboard-my-data.php';
		}
		return $location;
	}

	/* ------------------------------------------------------------------ *
	 * Shortcodes
	 * ------------------------------------------------------------------ */

	public static function shortcode_my_program( $atts ) {
		$atts = shortcode_atts( array( 'program' => 0 ), $atts, 'reticula_mi_carrera' );
		return self::render_my_programs( absint( $atts['program'] ) );
	}

	public static function shortcode_program( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'reticula_carrera' );
		return self::render_program_page( absint( $atts['id'] ) );
	}

	public static function shortcode_catalog() {
		self::assets();
		$programs = Curriculum::programs();
		if ( ! $programs ) {
			return '<p class="ra-empty">' . esc_html__( 'There are no programs available yet.', 'reticula-academica' ) . '</p>';
		}
		$user_id = get_current_user_id();
		ob_start();
		echo '<div class="ra-catalog">';
		foreach ( $programs as $program ) {
			$terms       = Curriculum::terms( $program->ID );
			$courses     = array_sum( array_map( 'count', wp_list_pluck( $terms, 'courses' ) ) );
			$inscription = $user_id ? Inscriptions::find( $user_id, $program->ID ) : null;
			?>
			<article class="ra-card">
				<?php if ( has_post_thumbnail( $program ) ) : ?>
					<a class="ra-card-thumb" href="<?php echo esc_url( get_permalink( $program ) ); ?>"><?php echo get_the_post_thumbnail( $program, 'medium_large' ); ?></a>
				<?php endif; ?>
				<div class="ra-card-body">
					<h3 class="ra-card-title"><a href="<?php echo esc_url( get_permalink( $program ) ); ?>"><?php echo esc_html( get_the_title( $program ) ); ?></a></h3>
					<p class="ra-meta">
						<?php
						/* translators: 1: number of terms, 2: number of courses. */
						echo esc_html( sprintf( __( '%1$d terms · %2$d courses', 'reticula-academica' ), count( $terms ), $courses ) );
						?>
					</p>
					<?php if ( has_excerpt( $program ) ) : ?>
						<p><?php echo esc_html( get_the_excerpt( $program ) ); ?></p>
					<?php endif; ?>
					<div class="ra-actions">
						<a class="ra-btn ra-btn-secondary" href="<?php echo esc_url( get_permalink( $program ) ); ?>"><?php esc_html_e( 'See curriculum', 'reticula-academica' ); ?></a>
						<?php if ( $inscription && Progress::STATUS_DROPPED !== $inscription['status'] ) : ?>
							<a class="ra-btn" href="<?php echo esc_url( Inscriptions::my_program_url( $program->ID ) ); ?>"><?php esc_html_e( 'My progress', 'reticula-academica' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</article>
			<?php
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------ *
	 * Program page (public curriculum + enrollment)
	 * ------------------------------------------------------------------ */

	public static function program_content( $content ) {
		if ( ! is_singular( Curriculum::PROGRAM ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		return $content . self::render_program_page( get_the_ID() );
	}

	public static function render_program_page( $program_id ) {
		if ( get_post_type( $program_id ) !== Curriculum::PROGRAM ) {
			return '';
		}
		self::assets();
		$user_id     = get_current_user_id();
		$inscription = $user_id ? Inscriptions::find( $user_id, $program_id ) : null;
		if ( $inscription && Progress::STATUS_DROPPED !== $inscription['status'] ) {
			return self::render_progress( Inscriptions::sync( $inscription ) );
		}

		$settings = Curriculum::program_settings( $program_id );
		$terms    = Curriculum::terms( $program_id );
		ob_start();
		?>
		<div class="ra-program">
			<div class="ra-enroll-box">
				<?php if ( ! $settings['self_enroll'] ) : ?>
					<p><?php esc_html_e( 'Enrollment in this program is managed by the school. Please contact us to join.', 'reticula-academica' ); ?></p>
				<?php elseif ( ! $user_id && Inscriptions::checkout_url( $program_id ) ) : ?>
					<p>
						<?php
						$price = Tutor::price_label( $settings['fee_course'] );
						/* translators: %s: price. */
						echo esc_html( $price ? sprintf( __( 'Inscription fee: %s. Pay it and create your account in the same step.', 'reticula-academica' ), $price ) : __( 'Pay the inscription and create your account in the same step.', 'reticula-academica' ) );
						?>
					</p>
					<a class="ra-btn ra-btn-primary" href="<?php echo esc_url( Inscriptions::checkout_url( $program_id ) ); ?>"><?php esc_html_e( 'Enroll in this program', 'reticula-academica' ); ?></a>
					<a class="ra-btn ra-btn-secondary" href="<?php echo esc_url( wp_login_url( get_permalink( $program_id ) ) ); ?>"><?php esc_html_e( 'I already have an account', 'reticula-academica' ); ?></a>
				<?php elseif ( ! $user_id ) : ?>
					<p><?php esc_html_e( 'Log in or create an account to enroll in this program.', 'reticula-academica' ); ?></p>
					<a class="ra-btn" href="<?php echo esc_url( wp_login_url( get_permalink( $program_id ) ) ); ?>"><?php esc_html_e( 'Log in to enroll', 'reticula-academica' ); ?></a>
				<?php else : ?>
					<?php if ( $settings['fee_course'] ) : ?>
						<p>
							<?php
							$price = Tutor::price_label( $settings['fee_course'] );
							/* translators: %s: price. */
							echo esc_html( $price ? sprintf( __( 'Inscription fee: %s. You will be taken to the checkout after enrolling.', 'reticula-academica' ), $price ) : __( 'You will be taken to pay the inscription fee after enrolling.', 'reticula-academica' ) );
							?>
						</p>
					<?php endif; ?>
					<a class="ra-btn ra-btn-primary" href="<?php echo esc_url( Inscriptions::enroll_url( $program_id ) ); ?>"><?php esc_html_e( 'Enroll in this program', 'reticula-academica' ); ?></a>
				<?php endif; ?>
			</div>

			<?php $rvoe = (string) get_post_meta( $program_id, '_ra_rvoe', true ); ?>
			<?php if ( '' !== $rvoe ) : ?>
				<p class="ra-meta ra-rvoe">
					<?php
					$rvoe_date = (string) get_post_meta( $program_id, '_ra_rvoe_date', true );
					$rvoe_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $rvoe_date ) ? $rvoe_date : '';
					/* translators: 1: RVOE number, 2: RVOE date. */
					echo esc_html( $rvoe_date ? sprintf( __( 'RVOE %1$s, %2$s', 'reticula-academica' ), $rvoe, Template_Renderer::long_date( strtotime( $rvoe_date . ' 12:00:00 UTC' ) ) ) : sprintf( __( 'RVOE %s', 'reticula-academica' ), $rvoe ) );
					?>
				</p>
			<?php endif; ?>
			<h2 class="ra-heading"><?php esc_html_e( 'Curriculum', 'reticula-academica' ); ?></h2>
			<?php foreach ( $terms as $term ) : ?>
				<section class="ra-term">
					<header class="ra-term-header">
						<span class="ra-term-number"><?php echo esc_html( (string) $term['number'] ); ?></span>
						<h3><?php echo esc_html( $term['title'] ); ?></h3>
					</header>
					<ol class="ra-courses">
						<?php foreach ( $term['courses'] as $course_id ) : ?>
							<li class="ra-course">
								<?php $code = Curriculum::course_code( $course_id ); ?>
								<?php if ( '' !== $code ) : ?>
									<span class="ra-code"><?php echo esc_html( $code ); ?></span>
								<?php endif; ?>
								<a class="ra-course-title" href="<?php echo esc_url( get_permalink( $course_id ) ); ?>"><?php echo esc_html( get_the_title( $course_id ) ); ?></a>
								<?php $price = Tutor::price_label( $course_id ); ?>
								<?php if ( $price ) : ?>
									<span class="ra-price"><?php echo esc_html( $price ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------ *
	 * My program
	 * ------------------------------------------------------------------ */

	public static function render_my_programs( $program_id = 0 ) {
		self::no_cache();
		self::assets();
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Log in to see your program.', 'reticula-academica' ) . ' <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'reticula-academica' ) . '</a></p>';
		}
		$inscriptions = Inscriptions::for_user( get_current_user_id() );
		if ( $program_id ) {
			$inscriptions = array_filter(
				$inscriptions,
				static function ( $row ) use ( $program_id ) {
					return (int) $row['program_id'] === (int) $program_id;
				}
			);
		}
		if ( ! $inscriptions ) {
			$catalog = get_post_type_archive_link( Curriculum::PROGRAM );
			return '<div class="ra-program"><div class="ra-next ra-next-empty"><p>' . esc_html__( 'You are not enrolled in any program yet.', 'reticula-academica' ) . '</p><a class="ra-btn ra-btn-primary" href="' . esc_url( $catalog ) . '">' . esc_html__( 'Browse programs', 'reticula-academica' ) . '</a></div></div>';
		}
		$html = '';
		foreach ( $inscriptions as $inscription ) {
			$html .= self::render_progress( Inscriptions::sync( $inscription ) );
		}
		return $html;
	}

	public static function term_status_label( $status ) {
		$labels = array(
			'completed' => __( 'Completed', 'reticula-academica' ),
			'current'   => __( 'In progress', 'reticula-academica' ),
			'upcoming'  => __( 'Upcoming', 'reticula-academica' ),
			'behind'    => __( 'Pending courses', 'reticula-academica' ),
			'exempt'    => __( 'Credited', 'reticula-academica' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Full progress view of an inscription.
	 */
	public static function render_progress( array $inscription ) {
		self::assets();
		$progress   = Inscriptions::progress( $inscription );
		$program_id = (int) $inscription['program_id'];
		$statuses   = Inscriptions::statuses();
		$current    = (int) $progress['current_term_id'];

		ob_start();
		?>
		<div class="ra-program ra-my-program" data-program="<?php echo esc_attr( $program_id ); ?>">
			<header class="ra-program-header">
				<div>
					<p class="ra-eyebrow"><?php esc_html_e( 'My program', 'reticula-academica' ); ?></p>
					<h2 class="ra-program-title"><a href="<?php echo esc_url( get_permalink( $program_id ) ); ?>"><?php echo esc_html( get_the_title( $program_id ) ); ?></a></h2>
					<p class="ra-meta">
						<span class="ra-badge ra-badge-<?php echo esc_attr( $progress['status'] ); ?>"><?php echo esc_html( isset( $statuses[ $progress['status'] ] ) ? $statuses[ $progress['status'] ] : $progress['status'] ); ?></span>
						<?php if ( $current && ! $progress['finished'] ) : ?>
							<?php
							/* translators: %s: term title. */
							echo esc_html( sprintf( __( 'Current term: %s', 'reticula-academica' ), get_the_title( $current ) ) );
							?>
						<?php endif; ?>
						<?php $matricula = Student_Id::get( (int) $inscription['user_id'] ); ?>
						<?php if ( $matricula ) : ?>
							<span class="ra-matricula">
								<?php
								/* translators: %s: student ID. */
								echo esc_html( sprintf( __( 'Student ID: %s', 'reticula-academica' ), $matricula ) );
								?>
							</span>
						<?php endif; ?>
						<?php
						$general = Grades::program_average( $inscription );
						if ( null !== $general ) {
							/* translators: %s: average grade. */
							echo '<span class="ra-average">' . esc_html( sprintf( __( 'Average: %s', 'reticula-academica' ), Grades::format( $general ) ) ) . '</span>';
						}
						?>
						<a class="ra-link" href="<?php echo esc_url( Documents::documents_url() ); ?>"><?php esc_html_e( 'My documents', 'reticula-academica' ); ?> &rarr;</a>
						<?php $record = Records::file_of( (int) $inscription['user_id'] ); ?>
						<?php if ( $record['items'] ) : ?>
							<a class="ra-link" href="<?php echo esc_url( Records::my_file_url() ); ?>"><?php esc_html_e( 'My file', 'reticula-academica' ); ?><?php echo $record['summary']['outstanding'] ? ' <span class="ra-badge ra-badge-rec-rejected">' . esc_html( (string) count( $record['summary']['outstanding'] ) ) . '</span>' : ''; ?> &rarr;</a>
						<?php endif; ?>
						<?php if ( ! empty( $inscription['billing_start'] ) ) : ?>
							<?php $tuition = Tuition::summary( (int) $inscription['id'] ); ?>
							<a class="ra-link" href="<?php echo esc_url( Tuition::my_payments_url() ); ?>"><?php esc_html_e( 'My payments', 'reticula-academica' ); ?><?php echo ( $tuition['overdue'] || $tuition['due'] ) ? ' <span class="ra-badge ' . ( $tuition['overdue'] ? 'ra-badge-pay-overdue' : 'ra-badge-pay-due' ) . '">' . esc_html( Tuition::money( $tuition['balance'] ) ) . '</span>' : ''; ?> &rarr;</a>
						<?php endif; ?>
					</p>
				</div>
				<div class="ra-overall" aria-label="<?php esc_attr_e( 'Program progress', 'reticula-academica' ); ?>">
					<strong><?php echo esc_html( $progress['percent'] . '%' ); ?></strong>
					<span>
						<?php
						/* translators: 1: completed courses, 2: total courses. */
						echo esc_html( sprintf( __( '%1$d of %2$d courses', 'reticula-academica' ), $progress['completed'], $progress['total'] ) );
						?>
					</span>
					<div class="ra-bar"><span style="width:<?php echo esc_attr( $progress['percent'] ); ?>%"></span></div>
				</div>
			</header>

			<?php self::render_next_step( $progress, $inscription ); ?>

			<div class="ra-timeline">
				<?php foreach ( $progress['terms'] as $term ) : ?>
					<section class="ra-term ra-term-<?php echo esc_attr( $term['status'] ); ?>">
						<header class="ra-term-header">
							<span class="ra-term-number"><?php echo 'completed' === $term['status'] ? '&#10003;' : esc_html( (string) $term['number'] ); ?></span>
							<h3><?php echo esc_html( $term['title'] ); ?></h3>
							<span class="ra-badge ra-badge-term-<?php echo esc_attr( $term['status'] ); ?>"><?php echo esc_html( self::term_status_label( $term['status'] ) ); ?></span>
						</header>
						<?php if ( $term['fee_course'] && 'exempt' !== $term['status'] ) : ?>
							<p class="ra-fee <?php echo $term['fee_paid'] ? 'is-paid' : ''; ?>">
								<?php if ( $term['fee_paid'] ) : ?>
									&#10003; <?php esc_html_e( 'Term fee paid', 'reticula-academica' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Term fee pending', 'reticula-academica' ); ?>
									<?php $price = Tutor::price_label( $term['fee_course'] ); ?>
									<?php echo $price ? '&middot; ' . esc_html( $price ) : ''; ?>
								<?php endif; ?>
							</p>
						<?php endif; ?>
						<ol class="ra-courses">
							<?php foreach ( $term['courses'] as $course ) : ?>
								<?php self::render_course_row( $course, (int) $inscription['user_id'] ); ?>
							<?php endforeach; ?>
						</ol>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_next_step( array $progress, array $inscription ) {
		$next = $progress['next'];
		echo '<div class="ra-next">';
		if ( Progress::STATUS_GRADUATED === $progress['status'] ) {
			echo '<p class="ra-next-label">' . esc_html__( 'Congratulations!', 'reticula-academica' ) . '</p>';
			echo '<p class="ra-next-title">' . esc_html__( 'You have completed every course of your program.', 'reticula-academica' ) . '</p>';
		} elseif ( in_array( $progress['status'], array( Progress::STATUS_PAUSED, Progress::STATUS_DROPPED ), true ) ) {
			echo '<p class="ra-next-title">' . esc_html__( 'Your inscription is not active. Please contact the school to continue.', 'reticula-academica' ) . '</p>';
		} elseif ( $next && 'pay_tuition' === $next['action'] ) {
			$tuition = Tuition::summary( (int) $inscription['id'] );
			echo '<p class="ra-next-label">' . esc_html__( 'Your next step', 'reticula-academica' ) . ' &middot; ' . esc_html__( 'Pay your overdue tuition', 'reticula-academica' ) . '</p>';
			echo '<p class="ra-next-title">' . esc_html( Tuition::money( $tuition['balance'] ) ) . ' <span class="ra-lock">' . esc_html__( 'Your courses are locked until you pay.', 'reticula-academica' ) . '</span></p>';
			echo '<a class="ra-btn ra-btn-primary" href="' . esc_url( Tuition::my_payments_url() ) . '">' . esc_html__( 'Pay now', 'reticula-academica' ) . '</a>';
		} elseif ( $next ) {
			$course_id = (int) $next['course_id'];
			$labels    = array(
				'pay_inscription' => array( __( 'Pay your inscription', 'reticula-academica' ), __( 'Pay now', 'reticula-academica' ) ),
				/* translators: %s: term title. */
				'pay_fee'         => array( sprintf( __( 'Pay the fee of %s', 'reticula-academica' ), get_the_title( $next['term_id'] ) ), __( 'Pay now', 'reticula-academica' ) ),
				'continue'        => array( __( 'Continue your course', 'reticula-academica' ), __( 'Continue', 'reticula-academica' ) ),
				'enroll'          => array( __( 'Your next course', 'reticula-academica' ), __( 'Enroll', 'reticula-academica' ) ),
				'wait'            => array( __( 'Your next course', 'reticula-academica' ), __( 'View course', 'reticula-academica' ) ),
			);
			$label     = isset( $labels[ $next['action'] ] ) ? $labels[ $next['action'] ] : $labels['wait'];
			$price     = in_array( $next['action'], array( 'pay_inscription', 'pay_fee', 'enroll' ), true ) ? Tutor::price_label( $course_id ) : '';
			echo '<p class="ra-next-label">' . esc_html__( 'Your next step', 'reticula-academica' ) . ' &middot; ' . esc_html( $label[0] ) . '</p>';
			echo '<p class="ra-next-title">' . esc_html( get_the_title( $course_id ) ) . ( $price ? ' <span class="ra-price">' . esc_html( $price ) . '</span>' : '' ) . '</p>';
			echo '<a class="ra-btn ra-btn-primary" href="' . esc_url( get_permalink( $course_id ) ) . '">' . esc_html( $label[1] ) . '</a>';
		}
		echo '</div>';
	}

	private static function render_course_row( array $course, $user_id = 0 ) {
		$course_id = (int) $course['id'];
		$grade     = ( $user_id && in_array( $course['status'], array( 'in_progress', 'completed' ), true ) ) ? Grades::course( $course_id, $user_id ) : null;
		$classes   = 'ra-course ra-course-' . $course['status'] . ( $course['is_next'] ? ' is-next' : '' );
		?>
		<li class="<?php echo esc_attr( $classes ); ?>">
			<span class="ra-course-icon" aria-hidden="true"><?php echo wp_kses_post( self::icon( $course['status'] ) ); ?></span>
			<div class="ra-course-main">
				<?php $code = Curriculum::course_code( $course_id ); ?>
				<?php if ( '' !== $code ) : ?>
					<span class="ra-code"><?php echo esc_html( $code ); ?></span>
				<?php endif; ?>
				<a class="ra-course-title" href="<?php echo esc_url( get_permalink( $course_id ) ); ?>"><?php echo esc_html( get_the_title( $course_id ) ); ?></a>
				<?php if ( $course['is_next'] ) : ?>
					<span class="ra-badge ra-badge-next"><?php esc_html_e( 'Next', 'reticula-academica' ); ?></span>
				<?php endif; ?>
				<?php if ( 'in_progress' === $course['status'] ) : ?>
					<div class="ra-bar ra-bar-small"><span style="width:<?php echo esc_attr( $course['percent'] ); ?>%"></span></div>
				<?php elseif ( 'locked' === $course['status'] ) : ?>
					<p class="ra-lock"><?php echo esc_html( Gating::message( $course['lock_reason'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( $grade && $grade['items'] ) : ?>
					<?php echo self::grade_html( $grade, false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php endif; ?>
			</div>
			<div class="ra-course-side">
				<?php
				switch ( $course['status'] ) {
					case 'completed':
						echo '<span class="ra-state">' . esc_html__( 'Completed', 'reticula-academica' ) . '</span>';
						break;
					case 'in_progress':
						echo '<span class="ra-state">' . esc_html( $course['percent'] . '%' ) . '</span> <a class="ra-btn ra-btn-small" href="' . esc_url( get_permalink( $course_id ) ) . '">' . esc_html__( 'Continue', 'reticula-academica' ) . '</a>';
						break;
					case 'available':
						$price = Tutor::price_label( $course_id );
						echo ( $price ? '<span class="ra-price">' . esc_html( $price ) . '</span> ' : '' ) . '<a class="ra-btn ra-btn-small" href="' . esc_url( get_permalink( $course_id ) ) . '">' . esc_html__( 'Enroll', 'reticula-academica' ) . '</a>';
						break;
					case 'exempt':
						echo '<span class="ra-state">' . esc_html__( 'Credited', 'reticula-academica' ) . '</span>';
						break;
					default:
						echo '<span class="ra-state">' . esc_html__( 'Locked', 'reticula-academica' ) . '</span>';
				}
				?>
			</div>
		</li>
		<?php
	}

	/**
	 * Course grade for students: with the quiz breakdown, or only the final grade (Settings).
	 */
	private static function grade_html( array $grade, $open ) {
		/* translators: 1: grade, 2: status. */
		$label = esc_html( sprintf( __( 'Grade: %1$s · %2$s', 'reticula-academica' ), Grades::format( isset( $grade['final'] ) ? $grade['final'] : $grade['grade'] ), Grades::status_label( $grade['status'] ) ) );
		if ( ! Settings::get( 'grade_show_items' ) ) {
			return '<p class="ra-grade-final">' . $label . '</p>';
		}
		return '<details class="ra-grade-details"' . ( $open ? ' open' : '' ) . '><summary>' . $label . '</summary>' . Grades::breakdown_html( $grade ) . '</details>';
	}

	private static function icon( $status ) {
		$icons = array(
			'completed'   => '&#10003;',
			'in_progress' => '&#9654;',
			'available'   => '&#9675;',
			'locked'      => '&#128274;',
			'exempt'      => '&#8212;',
		);
		return isset( $icons[ $status ] ) ? $icons[ $status ] : '';
	}

	/* ------------------------------------------------------------------ *
	 * Course page notice
	 * ------------------------------------------------------------------ */

	public static function course_notice() {
		$user_id   = get_current_user_id();
		$course_id = get_the_ID();
		if ( ! $user_id || ! $course_id ) {
			return;
		}
		foreach ( Inscriptions::for_user( $user_id ) as $inscription ) {
			$progress = Inscriptions::progress( $inscription );
			$location = Progress::locate( $progress, $course_id );
			if ( ! $location ) {
				continue;
			}
			self::assets();
			$course = $location['course'];
			?>
			<div class="ra-course-notice ra-course-notice-<?php echo esc_attr( $course['status'] ); ?>">
				<p class="ra-eyebrow">
					<?php
					/* translators: 1: program, 2: term, 3: position, 4: courses in term. */
					echo esc_html( sprintf( __( '%1$s · %2$s · Course %3$d of %4$d', 'reticula-academica' ), get_the_title( $inscription['program_id'] ), $location['term']['title'], $location['position'], $location['of'] ) );
					?>
				</p>
				<?php if ( 'locked' === $course['status'] ) : ?>
					<p><strong><?php echo esc_html( Gating::message( $course['lock_reason'] ) ); ?></strong>
						<?php if ( 'tuition' === $course['lock_reason'] ) : ?>
							<a class="ra-link" href="<?php echo esc_url( Tuition::my_payments_url() ); ?>"><?php esc_html_e( 'Pay now', 'reticula-academica' ); ?> &rarr;</a>
						<?php endif; ?>
					</p>
				<?php elseif ( ! empty( $progress['tuition_blocked'] ) && 'in_progress' === $course['status'] ) : ?>
					<p><strong><?php echo esc_html( Gating::message( 'tuition' ) ); ?></strong> <a class="ra-link" href="<?php echo esc_url( Tuition::my_payments_url() ); ?>"><?php esc_html_e( 'Pay now', 'reticula-academica' ); ?> &rarr;</a></p>
				<?php elseif ( $course['is_next'] ) : ?>
					<p><strong><?php esc_html_e( 'This is your next course.', 'reticula-academica' ); ?></strong></p>
				<?php elseif ( 'completed' === $course['status'] && $progress['next'] && (int) $progress['next']['course_id'] !== $course_id ) : ?>
					<p>
						<?php esc_html_e( 'You completed this course. Next:', 'reticula-academica' ); ?>
						<a href="<?php echo esc_url( get_permalink( $progress['next']['course_id'] ) ); ?>"><?php echo esc_html( get_the_title( $progress['next']['course_id'] ) ); ?></a>
					</p>
				<?php endif; ?>
				<?php if ( in_array( $course['status'], array( 'in_progress', 'completed' ), true ) ) : ?>
					<?php $grade = Grades::course( $course_id, $user_id ); ?>
					<?php if ( $grade['items'] ) : ?>
						<?php echo self::grade_html( $grade, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php endif; ?>
				<?php endif; ?>
				<a class="ra-link" href="<?php echo esc_url( Inscriptions::my_program_url( $inscription['program_id'] ) ); ?>"><?php esc_html_e( 'See my program', 'reticula-academica' ); ?> &rarr;</a>
			</div>
			<?php
			return;
		}
	}
}
