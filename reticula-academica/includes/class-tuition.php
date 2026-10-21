<?php
/**
 * Tuition (colegiaturas): monthly payments per term, grace days, late fees, online payment through
 * WooCommerce (any gateway), manual payments recorded by staff, course locks and reminders.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Tuition {

	const PAGE  = 'ra-payments';
	const CRON  = 'ra_tuition_daily';
	const SINCE = 'ra_tuition_since'; // Inscriptions created before tuition existed start billing only when staff say so.

	private static $summaries = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'daily' ) );
		add_action( 'ra_inscription_saved', array( __CLASS__, 'maybe_start' ), 20 );
		add_action( 'ra_inscription_updated', array( __CLASS__, 'maybe_start' ), 20 );
		add_action( 'ra_inscription_activated', array( __CLASS__, 'maybe_start' ), 20 );
		add_action( 'admin_post_ra_pay_tuition', array( __CLASS__, 'handle_pay' ) );
		add_action( 'admin_post_ra_tuition', array( __CLASS__, 'handle_admin' ) );
		add_action( 'admin_post_ra_tuition_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status' ), 20, 3 );
		add_filter( 'woocommerce_get_return_url', array( __CLASS__, 'return_url' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'block_content' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 23 );
		add_action( 'deleted_user', array( __CLASS__, 'delete_for_user' ) );
		add_shortcode( 'reticula_pagos', array( __CLASS__, 'render_my_payments' ) );
		if ( ! get_option( self::SINCE ) ) {
			update_option( self::SINCE, current_time( 'mysql', true ), false );
		}
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ra_payments';
	}

	public static function today() {
		return current_time( 'Y-m-d' );
	}

	public static function grace() {
		return max( 0, (int) Settings::get( 'tuition_grace_days' ) );
	}

	public static function money( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		}
		return '$' . number_format_i18n( (float) $amount, 2 );
	}

	public static function date_label( $date ) {
		$dt = date_create( $date . ' 12:00:00', wp_timezone() );
		if ( ! $dt ) {
			return $date;
		}
		// Spanish sites: "7 de noviembre de 2026" even when WordPress core has no Spanish translation installed.
		if ( 0 === strpos( determine_locale(), 'es' ) ) {
			return Template_Renderer::long_date( strtotime( $date . ' 12:00:00 UTC' ) );
		}
		return wp_date( get_option( 'date_format' ), $dt->getTimestamp() );
	}

	public static function woo() {
		return function_exists( 'wc_create_order' );
	}

	/**
	 * Monthly billing of a term.
	 */
	public static function term_billing( $term_id ) {
		return array(
			'months' => max( 0, (int) get_post_meta( $term_id, '_ra_months', true ) ),
			'amount' => max( 0, (float) get_post_meta( $term_id, '_ra_monthly_amount', true ) ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Calendar
	 * ------------------------------------------------------------------ */

	/**
	 * Start billing a new or newly activated inscription (today), when its program has billed terms.
	 */
	public static function maybe_start( $inscription ) {
		if ( ! is_array( $inscription ) || ! empty( $inscription['billing_start'] ) || Progress::STATUS_ACTIVE !== $inscription['status'] ) {
			return;
		}
		if ( strtotime( $inscription['created_at'] . ' UTC' ) < strtotime( (string) get_option( self::SINCE ) . ' UTC' ) ) {
			return; // Existing students: staff set their billing start.
		}
		self::start( (int) $inscription['id'], self::today() );
	}

	/**
	 * Set (or change) the billing start of an inscription and build its calendar.
	 */
	public static function start( $inscription_id, $date ) {
		global $wpdb;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return false;
		}
		$wpdb->update( Installer::table(), array( 'billing_start' => $date ), array( 'id' => (int) $inscription_id ) ); // phpcs:ignore WordPress.DB
		return self::sync( Inscriptions::get( $inscription_id ) );
	}

	/**
	 * Billed terms of an inscription, from its start term on.
	 */
	public static function billed_terms( array $inscription ) {
		$terms = Curriculum::terms( (int) $inscription['program_id'] );
		$out   = array();
		$on    = false;
		foreach ( $terms as $term ) {
			$on = $on || (int) $term['id'] === (int) $inscription['start_term_id'] || ! $inscription['start_term_id'];
			if ( $on ) {
				$out[] = array_merge( array( 'id' => (int) $term['id'], 'title' => $term['title'] ), self::term_billing( $term['id'] ) );
			}
		}
		return $out;
	}

	/**
	 * Bring the stored payments in line with the calendar: pending payments get the current dates and amounts,
	 * missing ones are added and pending ones no longer in the calendar are removed. Paid and cancelled ones stay.
	 *
	 * @return int Payments in the calendar.
	 */
	public static function sync( $inscription ) {
		global $wpdb;
		if ( ! $inscription || empty( $inscription['billing_start'] ) ) {
			return 0;
		}
		$table    = self::table();
		$plan     = Tuition_Rules::schedule( $inscription['billing_start'], self::billed_terms( $inscription ), (int) Settings::get( 'tuition_cutoff_day' ) );
		$existing = array();
		foreach ( self::for_inscription( (int) $inscription['id'] ) as $row ) {
			$existing[ $row['term_id'] . '-' . $row['seq'] ] = $row;
		}
		$now  = current_time( 'mysql', true );
		$keep = array();
		foreach ( $plan as $item ) {
			$key          = $item['term_id'] . '-' . $item['seq'];
			$keep[ $key ] = true;
			if ( isset( $existing[ $key ] ) ) {
				$row = $existing[ $key ];
				if ( Tuition_Rules::PENDING === $row['status'] && ( $row['due_date'] !== $item['due'] || (float) $row['amount'] !== (float) $item['amount'] || (int) $row['months'] !== (int) $item['months'] ) ) {
					$wpdb->update( $table, array( 'due_date' => $item['due'], 'amount' => $item['amount'], 'months' => $item['months'], 'updated_at' => $now ), array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB
				}
				continue;
			}
			$wpdb->insert( // phpcs:ignore WordPress.DB
				$table,
				array(
					'user_id'        => (int) $inscription['user_id'],
					'inscription_id' => (int) $inscription['id'],
					'program_id'     => (int) $inscription['program_id'],
					'term_id'        => $item['term_id'],
					'seq'            => $item['seq'],
					'months'         => $item['months'],
					'due_date'       => $item['due'],
					'amount'         => $item['amount'],
					'late_fee'       => 0,
					'status'         => Tuition_Rules::PENDING,
					'created_at'     => $now,
					'updated_at'     => $now,
				)
			);
		}
		foreach ( $existing as $key => $row ) {
			if ( ! isset( $keep[ $key ] ) && Tuition_Rules::PENDING === $row['status'] ) {
				$wpdb->delete( $table, array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB
			}
		}
		self::$summaries = array();
		return count( $plan );
	}

	/* ------------------------------------------------------------------ *
	 * Payments
	 * ------------------------------------------------------------------ */

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function for_inscription( $inscription_id ) {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE inscription_id = %d ORDER BY due_date ASC, id ASC", $inscription_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Payments of a student's current inscriptions (dropped ones excluded), with late fees applied.
	 */
	public static function for_user( $user_id ) {
		$rows = array();
		foreach ( Inscriptions::for_user( $user_id ) as $inscription ) {
			$rows = array_merge( $rows, self::for_inscription( (int) $inscription['id'] ) );
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $a['due_date'], $b['due_date'] );
			}
		);
		return self::apply_late_fees( $rows );
	}

	private static function rule_rows( array $rows ) {
		return array_map(
			static function ( $r ) {
				return array_merge( $r, array( 'due' => $r['due_date'] ) );
			},
			$rows
		);
	}

	/**
	 * Add the late fee to pending payments past the grace days (once; staff can waive it).
	 *
	 * @param array[] $rows Payments.
	 * @param bool    $notify Email the student.
	 * @return array[] Rows, updated.
	 */
	public static function apply_late_fees( array $rows, $notify = true ) {
		global $wpdb;
		$fee   = round( max( 0, (float) Settings::get( 'tuition_late_fee' ) ), 2 );
		$today = self::today();
		foreach ( $rows as $i => $row ) {
			if ( Tuition_Rules::PENDING !== $row['status'] || ! empty( $row['late_waived'] ) || (float) $row['late_fee'] > 0 || $fee <= 0 ) {
				continue;
			}
			if ( Tuition_Rules::OVERDUE !== Tuition_Rules::moment( $row['due_date'], $today, self::grace() ) ) {
				continue;
			}
			$wpdb->update( self::table(), array( 'late_fee' => $fee, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB
			$rows[ $i ]['late_fee'] = $fee;
			if ( $notify ) {
				self::email_late( $rows[ $i ] );
			}
		}
		return $rows;
	}

	/**
	 * Summary for an inscription (or every inscription of a user with $user_id).
	 */
	public static function summary( $inscription_id = 0, $user_id = 0 ) {
		$key = $inscription_id ? 'i' . $inscription_id : 'u' . $user_id;
		if ( ! isset( self::$summaries[ $key ] ) ) {
			$rows                    = $inscription_id ? self::apply_late_fees( self::for_inscription( $inscription_id ) ) : self::for_user( $user_id );
			self::$summaries[ $key ] = Tuition_Rules::summary( self::rule_rows( $rows ), self::today(), self::grace() );
		}
		return self::$summaries[ $key ];
	}

	/**
	 * Whether an inscription's courses are locked for an overdue payment.
	 */
	public static function blocked( array $inscription ) {
		if ( ! Settings::get( 'tuition_block' ) || empty( $inscription['billing_start'] ) ) {
			return false;
		}
		return self::summary( (int) $inscription['id'] )['blocked'];
	}

	public static function concept( array $row ) {
		/* translators: 1: payment number, 2: payments in the term, 3: term. */
		return sprintf( __( 'Tuition %1$d of %2$d · %3$s', 'reticula-academica' ), $row['seq'], $row['months'], get_the_title( (int) $row['term_id'] ) );
	}

	/**
	 * Mark payments as paid.
	 */
	public static function mark_paid( array $ids, $method, $reference = '', $order_id = 0, $date = '' ) {
		global $wpdb;
		$paid_at = $date ? get_gmt_from_date( $date . ' 12:00:00' ) : current_time( 'mysql', true );
		$done    = array();
		foreach ( $ids as $id ) {
			$row = self::get( $id );
			if ( ! $row || Tuition_Rules::PAID === $row['status'] ) {
				continue;
			}
			$wpdb->update( // phpcs:ignore WordPress.DB
				self::table(),
				array(
					'status'     => Tuition_Rules::PAID,
					'method'     => sanitize_key( $method ),
					'reference'  => (string) $reference,
					'order_id'   => (int) $order_id ? (int) $order_id : (int) $row['order_id'],
					'paid_at'    => $paid_at,
					'paid_by'    => get_current_user_id(),
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $id )
			);
			$done[] = (int) $id;
			$user   = (int) $row['user_id'];
		}
		self::$summaries = array();
		if ( $done ) {
			Inscriptions::sync_user( $user );
			do_action( 'ra_tuition_paid', $done, $method );
		}
		return $done;
	}

	public static function methods() {
		return array(
			'online'   => __( 'Online', 'reticula-academica' ),
			'transfer' => __( 'Bank transfer', 'reticula-academica' ),
			'deposit'  => __( 'Bank deposit', 'reticula-academica' ),
			'cash'     => __( 'Cash', 'reticula-academica' ),
			'card'     => __( 'Card at the school', 'reticula-academica' ),
			'other'    => __( 'Other', 'reticula-academica' ),
		);
	}

	public static function delete_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => (int) $user_id ) ); // phpcs:ignore WordPress.DB
	}

	/* ------------------------------------------------------------------ *
	 * Online payment (WooCommerce)
	 * ------------------------------------------------------------------ */

	public static function pay_url( $ids ) {
		$ids = is_array( $ids ) ? implode( ',', array_map( 'absint', $ids ) ) : (string) $ids;
		return wp_nonce_url( admin_url( 'admin-post.php?action=ra_pay_tuition&ids=' . $ids ), 'ra_pay_tuition' );
	}

	public static function my_payments_url() {
		return Tutor::active() ? Tutor::dashboard_url( Frontend::PAYMENTS_SLUG ) : home_url( '/' );
	}

	public static function handle_pay() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		check_admin_referer( 'ra_pay_tuition' );
		$user_id = get_current_user_id();
		if ( ! self::woo() ) {
			wp_die( esc_html__( 'Online payments are not available. Please contact the school.', 'reticula-academica' ) );
		}
		$ids  = isset( $_GET['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) : array();
		$rows = array();
		foreach ( self::for_user( $user_id ) as $row ) {
			if ( in_array( (int) $row['id'], $ids, true ) && Tuition_Rules::PENDING === $row['status'] ) {
				$rows[] = $row;
			}
		}
		if ( ! $rows ) {
			wp_safe_redirect( self::my_payments_url() );
			exit;
		}
		$order = self::order_for( $user_id, $rows );
		if ( is_wp_error( $order ) ) {
			wp_die( esc_html( $order->get_error_message() ) );
		}
		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	/**
	 * WooCommerce order for some payments: the pending order already created for exactly these payments and
	 * amounts, or a new one (one fee line per payment and one per late fee).
	 *
	 * @return \WC_Order|\WP_Error
	 */
	public static function order_for( $user_id, array $rows ) {
		global $wpdb;
		$ids   = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$total = 0.0;
		foreach ( $rows as $row ) {
			$total += Tuition_Rules::total( $row );
		}
		$total    = round( $total, 2 );
		$order_id = (int) $rows[0]['order_id'];
		if ( $order_id && count( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'order_id' ) ) ) ) === 1 ) {
			$order = wc_get_order( $order_id );
			if ( $order && $order->has_status( array( 'pending', 'failed' ) ) && abs( (float) $order->get_total() - $total ) < 0.01 && (string) $order->get_meta( '_ra_payment_ids' ) === implode( ',', $ids ) ) {
				return $order;
			}
		}

		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		foreach ( $rows as $row ) {
			$lines = array( array( self::concept( $row ) . ' · ' . get_the_title( (int) $row['program_id'] ), (float) $row['amount'] ) );
			if ( (float) $row['late_fee'] > 0 ) {
				/* translators: %s: tuition concept. */
				$lines[] = array( sprintf( __( 'Late fee · %s', 'reticula-academica' ), self::concept( $row ) ), (float) $row['late_fee'] );
			}
			foreach ( $lines as $line ) {
				$fee = new \WC_Order_Item_Fee();
				$fee->set_name( $line[0] );
				$fee->set_amount( $line[1] );
				$fee->set_total( $line[1] );
				$fee->set_tax_status( 'none' );
				$order->add_item( $fee );
			}
		}
		$user = get_userdata( $user_id );
		if ( $user ) {
			$order->set_billing_email( $user->user_email );
			$order->set_billing_first_name( $user->first_name ? $user->first_name : $user->display_name );
			$order->set_billing_last_name( $user->last_name );
		}
		$order->update_meta_data( '_ra_payment_ids', implode( ',', $ids ) );
		$order->set_created_via( 'reticula-academica' );
		$order->calculate_totals( false );
		$order->set_status( 'pending' );
		$order->save();
		foreach ( $ids as $id ) {
			$wpdb->update( self::table(), array( 'order_id' => $order->get_id() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		}
		return $order;
	}

	/**
	 * Paid orders mark their payments as paid; refunded orders put them back as pending.
	 */
	public static function on_order_status( $order_id, $from, $to ) {
		global $wpdb;
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$ids   = $order ? array_filter( array_map( 'absint', explode( ',', (string) $order->get_meta( '_ra_payment_ids' ) ) ) ) : array();
		if ( ! $ids ) {
			return;
		}
		if ( in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			$reference = trim( $order->get_payment_method_title() . ' ' . $order->get_transaction_id() );
			/* translators: %d: order number. */
			$reference = trim( sprintf( __( 'Order #%d', 'reticula-academica' ), $order->get_id() ) . ' · ' . $reference, ' ·' );
			self::mark_paid( $ids, 'online', $reference, $order->get_id() );
		} elseif ( 'refunded' === $to ) {
			foreach ( $ids as $id ) {
				$wpdb->update( self::table(), array( 'status' => Tuition_Rules::PENDING, 'paid_at' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id, 'order_id' => $order->get_id() ) ); // phpcs:ignore WordPress.DB
			}
			self::$summaries = array();
		}
	}

	public static function return_url( $url, $order ) {
		if ( $order && is_a( $order, 'WC_Order' ) && $order->get_meta( '_ra_payment_ids' ) ) {
			return add_query_arg( array( 'ra_paid' => $order->get_id(), 'ra_t' => time() ), self::my_payments_url() );
		}
		return $url;
	}

	/* ------------------------------------------------------------------ *
	 * Course lock
	 * ------------------------------------------------------------------ */

	/**
	 * Lessons, quizzes and assignments of a program with an overdue payment send the student to "My payments".
	 */
	public static function block_content() {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! Settings::get( 'tuition_block' ) || ! is_singular() || ! Tutor::active() || current_user_can( Post_Types::capability() ) ) {
			return;
		}
		$post  = get_post();
		if ( ! $post || ! in_array( $post->post_type, Tutor::content_post_types(), true ) ) {
			return;
		}
		$course_id = method_exists( tutor_utils(), 'get_course_id_by_content' ) ? (int) tutor_utils()->get_course_id_by_content( $post ) : 0;
		if ( ! $course_id ) {
			return;
		}
		foreach ( Inscriptions::for_user( $user_id ) as $inscription ) {
			if ( self::blocked( $inscription ) && in_array( $course_id, Curriculum::all_course_ids( (int) $inscription['program_id'] ), true ) ) {
				wp_safe_redirect( add_query_arg( array( 'ra_blocked' => 1, 'ra_t' => time() ), self::my_payments_url() ) );
				exit;
			}
		}
	}

	/* ------------------------------------------------------------------ *
	 * Emails and daily tasks
	 * ------------------------------------------------------------------ */

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Daily: late fees (with their notice) and reminders before the due date.
	 *
	 * @return array [ late fees applied, reminders sent ]
	 */
	public static function daily() {
		global $wpdb;
		$table = self::table();
		$ins   = Installer::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.* FROM {$table} p JOIN {$ins} i ON i.id = p.inscription_id WHERE p.status = %s AND i.status <> %s ORDER BY p.due_date ASC", Tuition_Rules::PENDING, Progress::STATUS_DROPPED ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$before = array_column( $rows, 'late_fee', 'id' );
		$rows   = self::apply_late_fees( $rows );
		$late   = 0;
		foreach ( $rows as $row ) {
			if ( (float) $row['late_fee'] > (float) $before[ $row['id'] ] ) {
				++$late;
			}
		}
		$sent  = 0;
		$days  = max( 0, (int) Settings::get( 'tuition_notice_days' ) );
		$today = self::today();
		$limit = gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) + $days * DAY_IN_SECONDS );
		if ( Settings::get( 'tuition_emails' ) ) {
			foreach ( $rows as $row ) {
				if ( empty( $row['notified_at'] ) && $row['due_date'] >= $today && $row['due_date'] <= $limit && self::email_upcoming( $row ) ) {
					$wpdb->update( $table, array( 'notified_at' => current_time( 'mysql', true ) ), array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB
					++$sent;
				}
			}
		}
		return array( $late, $sent );
	}

	private static function send( $user_id, $subject, $body ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) || ! Settings::get( 'tuition_emails' ) ) {
			return false;
		}
		$name = $user->first_name ? $user->first_name : $user->display_name;
		/* translators: %s: student first name. */
		$body = sprintf( __( 'Hello %s,', 'reticula-academica' ), $name ) . "\n\n" . $body . "\n\n" . __( 'See your payments:', 'reticula-academica' ) . ' ' . self::my_payments_url() . "\n\n" . Records::institution();
		return Records::mail( $user->user_email, $subject, $body );
	}

	public static function email_upcoming( array $row ) {
		/* translators: %s: due date. */
		$subject = sprintf( __( 'Your tuition is due on %s', 'reticula-academica' ), self::date_label( $row['due_date'] ) );
		/* translators: 1: concept, 2: amount, 3: due date, 4: last day without late fee. */
		$body = sprintf( __( '%1$s for %2$s is due on %3$s. Pay by %4$s to avoid the late fee.', 'reticula-academica' ), self::concept( $row ), self::money( $row['amount'] ), self::date_label( $row['due_date'] ), self::date_label( Tuition_Rules::grace_until( $row['due_date'], self::grace() ) ) );
		return self::send( (int) $row['user_id'], $subject, $body );
	}

	public static function email_late( array $row ) {
		$subject = __( 'Late fee applied to your tuition', 'reticula-academica' );
		/* translators: 1: concept, 2: due date, 3: late fee, 4: total. */
		$body = sprintf( __( '%1$s (due on %2$s) was not paid within the grace days, so a late fee of %3$s was added. Total to pay: %4$s.', 'reticula-academica' ), self::concept( $row ), self::date_label( $row['due_date'] ), self::money( $row['late_fee'] ), self::money( Tuition_Rules::total( $row ) ) );
		if ( Settings::get( 'tuition_block' ) ) {
			$body .= "\n\n" . __( 'Your courses are locked until the payment is made.', 'reticula-academica' );
		}
		return self::send( (int) $row['user_id'], $subject, $body );
	}

	/* ------------------------------------------------------------------ *
	 * Student view
	 * ------------------------------------------------------------------ */

	private static function row_state( array $row ) {
		if ( Tuition_Rules::PENDING !== $row['status'] ) {
			return $row['status'];
		}
		return Tuition_Rules::moment( $row['due_date'], self::today(), self::grace() );
	}

	public static function state_badge( array $row ) {
		$state  = self::row_state( $row );
		$labels = array(
			Tuition_Rules::PAID      => __( 'Paid', 'reticula-academica' ),
			Tuition_Rules::CANCELLED => __( 'Cancelled', 'reticula-academica' ),
			Tuition_Rules::UPCOMING  => __( 'Upcoming', 'reticula-academica' ),
			Tuition_Rules::DUE       => __( 'To pay', 'reticula-academica' ),
			Tuition_Rules::OVERDUE   => __( 'Overdue', 'reticula-academica' ),
		);
		return '<span class="ra-badge ra-badge-pay-' . esc_attr( $state ) . '">' . esc_html( $labels[ $state ] ) . '</span>';
	}

	public static function render_my_payments() {
		Frontend::no_cache();
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Log in to see your payments.', 'reticula-academica' ) . '</p>';
		}
		$user_id = get_current_user_id();
		$rows    = self::for_user( $user_id );
		$sum     = Tuition_Rules::summary( self::rule_rows( $rows ), self::today(), self::grace() );
		$grace   = self::grace();
		ob_start();
		echo '<div class="ra-program ra-documents ra-payments" id="ra-payments">';
		echo '<script>window.addEventListener("pageshow",function(e){if(e.persisted){location.reload();}});</script>';
		echo '<header class="ra-program-header"><div><p class="ra-eyebrow">' . esc_html__( 'My payments', 'reticula-academica' ) . '</p>';
		$matricula = Student_Id::get( $user_id );
		if ( $matricula ) {
			/* translators: %s: student ID. */
			echo '<p class="ra-meta">' . esc_html( sprintf( __( 'Student ID: %s', 'reticula-academica' ), $matricula ) ) . '</p>';
		}
		echo '</div>';
		if ( $sum['total'] ) {
			$pct = (int) round( 100 * $sum['paid'] / $sum['total'] );
			/* translators: 1: paid payments, 2: total payments. */
			echo '<div class="ra-overall"><strong>' . esc_html( $sum['paid'] . '/' . $sum['total'] ) . '</strong><span>' . esc_html__( 'payments made', 'reticula-academica' ) . '</span><div class="ra-bar"><span style="width:' . esc_attr( $pct ) . '%"></span></div></div>';
		}
		echo '</header>';

		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['ra_paid'] ) ) {
			echo '<p class="ra-rec-notice is-success" role="status">' . esc_html__( 'Thank you! Your payment was received. If it does not show as paid yet, it will once the payment is confirmed.', 'reticula-academica' ) . '</p>';
		} elseif ( ! empty( $_GET['ra_blocked'] ) && $sum['blocked'] ) {
			echo '<p class="ra-rec-notice is-error" role="status">' . esc_html__( 'Your courses are locked because of an overdue payment. Pay it to continue studying.', 'reticula-academica' ) . '</p>';
		}
		// phpcs:enable

		if ( ! $rows ) {
			echo '<div class="ra-next ra-next-empty"><p>' . esc_html__( 'You have no tuition payments scheduled.', 'reticula-academica' ) . '</p></div></div>';
			return (string) ob_get_clean();
		}

		$owed = array_merge( $sum['overdue'], $sum['due'] );
		if ( $sum['overdue'] ) {
			echo '<div class="ra-next ra-pay-alert"><div><p class="ra-next-label">' . esc_html__( 'Overdue balance (includes late fees)', 'reticula-academica' ) . '</p><p class="ra-next-title">' . esc_html( self::money( $sum['balance'] ) ) . '</p>';
			if ( Settings::get( 'tuition_block' ) ) {
				echo '<p class="ra-lock">' . esc_html__( 'Your courses are locked until you pay.', 'reticula-academica' ) . '</p>';
			}
			echo '</div>' . self::pay_button( wp_list_pluck( $owed, 'id' ), __( 'Pay now', 'reticula-academica' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( $sum['due'] ) {
			$first = $sum['due'][0];
			/* translators: %s: date. */
			echo '<div class="ra-next"><div><p class="ra-next-label">' . esc_html( sprintf( __( 'Pay by %s to avoid the late fee', 'reticula-academica' ), self::date_label( Tuition_Rules::grace_until( $first['due_date'], $grace ) ) ) ) . '</p><p class="ra-next-title">' . esc_html( self::money( $sum['balance'] ) ) . '</p></div>' . self::pay_button( wp_list_pluck( $owed, 'id' ), __( 'Pay now', 'reticula-academica' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( $sum['next'] ) {
			/* translators: %s: date. */
			echo '<div class="ra-next"><div><p class="ra-next-label">' . esc_html( sprintf( __( 'Next payment: %s', 'reticula-academica' ), self::date_label( $sum['next']['due_date'] ) ) ) . '</p><p class="ra-next-title">' . esc_html( self::money( $sum['next']['amount'] ) ) . '</p></div>' . self::pay_button( array( $sum['next']['id'] ), __( 'Pay in advance', 'reticula-academica' ), true ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo '<div class="ra-next"><p class="ra-next-title">' . esc_html__( 'You are up to date with your payments.', 'reticula-academica' ) . '</p></div>';
		}

		echo '<section class="ra-term"><table class="ra-grade-table ra-pay-table"><thead><tr><th>' . esc_html__( 'Concept', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Due date', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Amount', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Late fee', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Total', 'reticula-academica' ) . '</th><th>' . esc_html__( 'Status', 'reticula-academica' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			if ( Tuition_Rules::CANCELLED === $row['status'] ) {
				continue;
			}
			$state = self::row_state( $row );
			echo '<tr class="ra-pay-' . esc_attr( $state ) . '"><td>' . esc_html( self::concept( $row ) ) . '</td><td>' . esc_html( self::date_label( $row['due_date'] ) );
			if ( Tuition_Rules::DUE === $state ) {
				/* translators: %s: date. */
				echo '<br><span class="ra-lock">' . esc_html( sprintf( __( 'No late fee until %s', 'reticula-academica' ), self::date_label( Tuition_Rules::grace_until( $row['due_date'], $grace ) ) ) ) . '</span>';
			}
			echo '</td><td>' . esc_html( self::money( $row['amount'] ) ) . '</td><td>' . ( (float) $row['late_fee'] > 0 ? esc_html( self::money( $row['late_fee'] ) ) : '&mdash;' ) . '</td><td><strong>' . esc_html( self::money( Tuition_Rules::total( $row ) ) ) . '</strong></td><td>' . self::state_badge( $row ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( Tuition_Rules::PAID === $row['status'] && $row['paid_at'] ) {
				echo '<br><span class="ra-lock">' . esc_html( wp_date( 'd/m/Y', strtotime( $row['paid_at'] . ' UTC' ) ) ) . '</span>';
			}
			echo '</td><td>';
			if ( Tuition_Rules::PENDING === $row['status'] ) {
				echo self::pay_button( array( $row['id'] ), __( 'Pay', 'reticula-academica' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput
			} elseif ( Tuition_Rules::PAID === $row['status'] && $row['order_id'] && function_exists( 'wc_get_order' ) && wc_get_order( (int) $row['order_id'] ) ) {
				echo '<a class="ra-link" href="' . esc_url( wc_get_order( (int) $row['order_id'] )->get_view_order_url() ) . '">' . esc_html__( 'Receipt', 'reticula-academica' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></section>';
		if ( ! self::woo() ) {
			echo '<p class="ra-lock">' . esc_html__( 'To pay, contact the school. Your payment will show here once it is recorded.', 'reticula-academica' ) . '</p>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	private static function pay_button( array $ids, $label, $small = false ) {
		if ( ! self::woo() || ! $ids ) {
			return '';
		}
		return '<a class="ra-btn ra-btn-primary' . ( $small ? ' ra-btn-small' : '' ) . '" href="' . esc_url( self::pay_url( $ids ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/* ------------------------------------------------------------------ *
	 * Admin
	 * ------------------------------------------------------------------ */

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . Curriculum::PROGRAM, __( 'Tuition', 'reticula-academica' ), __( 'Tuition', 'reticula-academica' ), Post_Types::capability(), self::PAGE, array( __CLASS__, 'render_admin_page' ) );
	}

	public static function admin_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => Curriculum::PROGRAM, 'page' => self::PAGE ), $args ), admin_url( 'edit.php' ) );
	}

	private static function back( $fallback ) {
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $fallback );
		exit;
	}

	private static function notice( $type, $text ) {
		set_transient( 'ra_notice_' . get_current_user_id(), array( $type, esc_html( $text ) ), 120 );
	}

	public static function handle_admin() {
		check_admin_referer( 'ra_tuition' );
		if ( ! current_user_can( Post_Types::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage students.', 'reticula-academica' ), 403 );
		}
		global $wpdb;
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$id  = isset( $_POST['payment'] ) ? absint( $_POST['payment'] ) : 0;
		$row = $id ? self::get( $id ) : null;
		$now = current_time( 'mysql', true );
		switch ( $do ) {
			case 'start':
				$inscription = Inscriptions::get( isset( $_POST['inscription'] ) ? absint( $_POST['inscription'] ) : 0 );
				$date        = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
				if ( $inscription && self::start( (int) $inscription['id'], $date ) ) {
					self::notice( 'success', __( 'Payment calendar updated.', 'reticula-academica' ) );
				} else {
					self::notice( 'warning', __( 'No payments were generated: check the date and that the terms have months and a monthly amount.', 'reticula-academica' ) );
				}
				break;
			case 'start_all':
				$date  = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : self::today();
				$count = 0;
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Installer::table() . ' WHERE status = %s AND billing_start IS NULL', Progress::STATUS_ACTIVE ), ARRAY_A ) as $inscription ) { // phpcs:ignore WordPress.DB
					if ( self::start( (int) $inscription['id'], $date ) ) {
						++$count;
					}
				}
				/* translators: %d: number of students. */
				self::notice( 'success', sprintf( __( 'Payment calendar created for %d students.', 'reticula-academica' ), $count ) );
				break;
			case 'resync_all':
				$count = 0;
				foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Installer::table() . ' WHERE billing_start IS NOT NULL', ARRAY_A ) as $inscription ) { // phpcs:ignore WordPress.DB
					self::sync( $inscription );
					++$count;
				}
				/* translators: %d: number of students. */
				self::notice( 'success', sprintf( __( 'Pending payments of %d students updated with the current amounts.', 'reticula-academica' ), $count ) );
				break;
			case 'pay':
				if ( $row ) {
					$method = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : 'other';
					$ref    = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
					$date   = isset( $_POST['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field( wp_unslash( $_POST['date'] ) ) ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
					self::mark_paid( array( $id ), isset( self::methods()[ $method ] ) ? $method : 'other', $ref, 0, $date );
					self::notice( 'success', __( 'Payment recorded.', 'reticula-academica' ) );
				}
				break;
			case 'waive':
				if ( $row ) {
					$wpdb->update( self::table(), array( 'late_fee' => 0, 'late_waived' => 1, 'updated_at' => $now ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
					self::notice( 'success', __( 'Late fee waived.', 'reticula-academica' ) );
				}
				break;
			case 'cancel':
				if ( $row && Tuition_Rules::PENDING === $row['status'] ) {
					$wpdb->update( self::table(), array( 'status' => Tuition_Rules::CANCELLED, 'updated_at' => $now ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
					self::notice( 'success', __( 'Payment cancelled.', 'reticula-academica' ) );
				}
				break;
			case 'reopen':
				if ( $row && Tuition_Rules::PENDING !== $row['status'] ) {
					$wpdb->update( self::table(), array( 'status' => Tuition_Rules::PENDING, 'paid_at' => null, 'method' => '', 'reference' => '', 'updated_at' => $now ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
					self::notice( 'success', __( 'Payment set back to pending.', 'reticula-academica' ) );
				}
				break;
		}
		self::$summaries = array();
		if ( $row ) {
			Inscriptions::sync_user( (int) $row['user_id'] );
		}
		self::back( self::admin_url() );
	}

	private static function action_form( $do, array $row, $label, $class = 'button-link', $extra = '', $confirm = '' ) {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ra-pay-action"' . ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : '' ) . '>';
		$html .= wp_nonce_field( 'ra_tuition', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="ra_tuition"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="payment" value="' . esc_attr( $row['id'] ) . '">';
		$html .= $extra . '<button type="submit" class="button ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
		return $html;
	}

	/**
	 * Payments panel inside the student (inscription) screen.
	 */
	public static function render_inscription_panel( array $inscription ) {
		$rows   = self::apply_late_fees( self::for_inscription( (int) $inscription['id'] ), false );
		$billed = array_filter(
			self::billed_terms( $inscription ),
			static function ( $t ) {
				return $t['months'] && $t['amount'] > 0;
			}
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ra-pay-start">
			<?php wp_nonce_field( 'ra_tuition' ); ?>
			<input type="hidden" name="action" value="ra_tuition"><input type="hidden" name="do" value="start">
			<input type="hidden" name="inscription" value="<?php echo esc_attr( $inscription['id'] ); ?>">
			<label><?php esc_html_e( 'Billing start', 'reticula-academica' ); ?>
				<input type="date" name="start" value="<?php echo esc_attr( $inscription['billing_start'] ? $inscription['billing_start'] : self::today() ); ?>" required></label>
			<?php submit_button( $inscription['billing_start'] ? __( 'Update calendar', 'reticula-academica' ) : __( 'Start billing', 'reticula-academica' ), 'secondary', 'submit', false ); ?>
			<p class="description">
				<?php
				if ( ! $billed ) {
					esc_html_e( 'The terms of this program have no months or monthly amount: edit each term to set them.', 'reticula-academica' );
				} else {
					/* translators: %d: cutoff day. */
					echo esc_html( sprintf( __( 'Up to day %d of the month, payments fall on that day starting immediately; later, on day 1 of the next month. "Update calendar" also applies changed amounts to pending payments.', 'reticula-academica' ), (int) Settings::get( 'tuition_cutoff_day' ) ) );
				}
				?>
			</p>
		</form>
		<?php if ( $rows ) : ?>
			<table class="widefat striped ra-pay-admin">
				<thead><tr>
					<th><?php esc_html_e( 'Concept', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Due date', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Late fee', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Status', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'reticula-academica' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( self::concept( $row ) ); ?></td>
						<td><?php echo esc_html( self::date_label( $row['due_date'] ) ); ?></td>
						<td><?php echo esc_html( self::money( $row['amount'] ) ); ?></td>
						<td><?php echo (float) $row['late_fee'] > 0 ? esc_html( self::money( $row['late_fee'] ) ) : ( $row['late_waived'] ? esc_html__( 'Waived', 'reticula-academica' ) : '&mdash;' ); ?></td>
						<td><?php echo self::state_badge( $row ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( Tuition_Rules::PAID === $row['status'] ) : ?>
								<br><span class="description"><?php echo esc_html( trim( wp_date( 'd/m/Y', strtotime( $row['paid_at'] . ' UTC' ) ) . ' · ' . ( isset( self::methods()[ $row['method'] ] ) ? self::methods()[ $row['method'] ] : $row['method'] ) . ( $row['reference'] ? ' · ' . $row['reference'] : '' ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							if ( Tuition_Rules::PENDING === $row['status'] ) {
								$methods = '';
								foreach ( self::methods() as $key => $label ) {
									if ( 'online' !== $key ) {
										$methods .= '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
									}
								}
								$extra = '<select name="method">' . $methods . '</select> <input type="text" name="reference" placeholder="' . esc_attr__( 'Reference', 'reticula-academica' ) . '" size="10"> <input type="date" name="date" value="' . esc_attr( self::today() ) . '"> ';
								echo self::action_form( 'pay', $row, __( 'Record payment', 'reticula-academica' ), 'button-primary', $extra ); // phpcs:ignore WordPress.Security.EscapeOutput
								if ( (float) $row['late_fee'] > 0 ) {
									echo self::action_form( 'waive', $row, __( 'Waive late fee', 'reticula-academica' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
								echo self::action_form( 'cancel', $row, __( 'Cancel', 'reticula-academica' ), 'button-link-delete', '', __( 'Cancel this payment? The student will no longer owe it.', 'reticula-academica' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
							} else {
								echo self::action_form( 'reopen', $row, __( 'Set back to pending', 'reticula-academica' ), 'button-link', '', __( 'Set this payment back to pending?', 'reticula-academica' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Payments for the admin list.
	 *
	 * @return array [ rows, total, sum ]
	 */
	public static function query( array $f, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = self::table();
		$ins   = Installer::table();
		$where = array( 'i.status <> %s' );
		$args  = array( Progress::STATUS_DROPPED );
		$today = self::today();
		$limit_date = gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) - self::grace() * DAY_IN_SECONDS ); // Due before this date = overdue.
		switch ( $f['view'] ) {
			case 'overdue':
				$where[] = 'p.status = %s AND p.due_date < %s';
				array_push( $args, Tuition_Rules::PENDING, $limit_date );
				break;
			case 'pending':
				$where[] = 'p.status = %s AND p.due_date >= %s';
				array_push( $args, Tuition_Rules::PENDING, $limit_date );
				break;
			case 'paid':
				$where[] = 'p.status = %s';
				$args[]  = Tuition_Rules::PAID;
				break;
		}
		if ( ! empty( $f['program'] ) ) {
			$where[] = 'p.program_id = %d';
			$args[]  = absint( $f['program'] );
		}
		if ( ! empty( $f['from'] ) ) {
			$where[] = ( 'paid' === $f['view'] ? 'DATE(p.paid_at)' : 'p.due_date' ) . ' >= %s';
			$args[]  = $f['from'];
		}
		if ( ! empty( $f['to'] ) ) {
			$where[] = ( 'paid' === $f['view'] ? 'DATE(p.paid_at)' : 'p.due_date' ) . ' <= %s';
			$args[]  = $f['to'];
		}
		if ( ! empty( $f['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $f['search'] ) . '%';
			$where[] = "(u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR p.user_id IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ra_matricula' AND meta_value LIKE %s))";
			array_push( $args, $like, $like, $like, $like );
		}
		$from  = "FROM {$table} p JOIN {$ins} i ON i.id = p.inscription_id LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id WHERE " . implode( ' AND ', $where );
		$order = 'paid' === $f['view'] ? 'p.paid_at DESC' : 'p.due_date ASC';
		$sum   = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(p.amount + p.late_fee) {$from}", $args ) ); // phpcs:ignore WordPress.DB
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $args ) ); // phpcs:ignore WordPress.DB
		$sql   = "SELECT p.*, u.display_name, u.user_email, i.id AS inscription {$from} ORDER BY {$order}, p.id ASC";
		if ( $limit ) {
			$sql   .= ' LIMIT %d OFFSET %d';
			$args[] = (int) $limit;
			$args[] = (int) $offset;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array( $rows, $total, $sum );
	}

	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification
		$date = static function ( $key ) {
			$v = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'overdue';
		return array(
			'view'    => in_array( $view, array( 'overdue', 'pending', 'paid', 'all' ), true ) ? $view : 'overdue',
			'program' => isset( $_GET['program'] ) ? absint( $_GET['program'] ) : 0,
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'from'    => $date( 'from' ),
			'to'      => $date( 'to' ),
		);
		// phpcs:enable
	}

	public static function render_admin_page() {
		if ( ! current_user_can( Post_Types::capability() ) ) {
			return;
		}
		global $wpdb;
		// Late fees are also applied here so the list is right even if WP-Cron has not run today.
		$pending = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE status = %s AND late_fee = 0 AND late_waived = 0 AND due_date < %s', Tuition_Rules::PENDING, self::today() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		self::apply_late_fees( $pending );
		$f        = self::filters();
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$per_page = 50;
		list( $rows, $total, $sum ) = self::query( $f, $per_page, ( $paged - 1 ) * $per_page );
		$missing  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table() . ' WHERE status = %s AND billing_start IS NULL', Progress::STATUS_ACTIVE ) ); // phpcs:ignore WordPress.DB
		$views    = array(
			'overdue' => __( 'Overdue', 'reticula-academica' ),
			'pending' => __( 'To collect', 'reticula-academica' ),
			'paid'    => __( 'Paid', 'reticula-academica' ),
			'all'     => __( 'All', 'reticula-academica' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tuition', 'reticula-academica' ); ?></h1>
			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $views as $key => $label ) {
					$links[] = '<li><a href="' . esc_url( self::admin_url( array( 'view' => $key ) ) ) . '"' . ( $f['view'] === $key ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
				}
				echo implode( ' | </li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</ul>
			<form method="get" style="clear:both;padding-top:8px" class="ra-filters">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( Curriculum::PROGRAM ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( $f['view'] ); ?>">
				<select name="program">
					<option value="0"><?php esc_html_e( 'All programs', 'reticula-academica' ); ?></option>
					<?php foreach ( Curriculum::programs() as $program ) : ?>
						<option value="<?php echo esc_attr( $program->ID ); ?>" <?php selected( $f['program'], $program->ID ); ?>><?php echo esc_html( get_the_title( $program ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<label><?php echo 'paid' === $f['view'] ? esc_html__( 'Paid from', 'reticula-academica' ) : esc_html__( 'Due from', 'reticula-academica' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>"></label>
				<label><?php esc_html_e( 'to', 'reticula-academica' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>"></label>
				<input type="search" name="s" value="<?php echo esc_attr( $f['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search students', 'reticula-academica' ); ?>">
				<?php submit_button( __( 'Filter', 'reticula-academica' ), 'secondary', '', false ); ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( $f, array( 'action' => 'ra_tuition_export' ) ), admin_url( 'admin-post.php' ) ), 'ra_tuition_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'reticula-academica' ); ?></a>
			</form>
			<p>
				<?php
				/* translators: 1: number of payments, 2: amount. */
				echo esc_html( sprintf( _n( '%1$d payment · %2$s', '%1$d payments · %2$s', $total, 'reticula-academica' ), $total, self::money( $sum ) ) );
				?>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Student', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Concept', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Due date', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Total', 'reticula-academica' ); ?></th>
					<th><?php esc_html_e( 'Status', 'reticula-academica' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No payments found.', 'reticula-academica' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( Admin::url( array( 'action' => 'edit', 'id' => $row['inscription'] ) ) . '#ra-tuition' ); ?>"><?php echo esc_html( $row['display_name'] ? $row['display_name'] : '#' . $row['user_id'] ); ?></a></strong>
							<br><span class="description"><?php echo esc_html( trim( Student_Id::get( (int) $row['user_id'] ) . ' · ' . get_the_title( (int) $row['program_id'] ), ' ·' ) ); ?></span></td>
						<td><?php echo esc_html( self::concept( $row ) ); ?></td>
						<td><?php echo esc_html( self::date_label( $row['due_date'] ) ); ?></td>
						<td><?php echo esc_html( self::money( Tuition_Rules::total( $row ) ) ); ?><?php echo (float) $row['late_fee'] > 0 ? '<br><span class="description">' . esc_html( sprintf( /* translators: %s: late fee. */ __( 'includes %s late fee', 'reticula-academica' ), self::money( $row['late_fee'] ) ) ) . '</span>' : ''; ?></td>
						<td><?php echo self::state_badge( $row ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( Tuition_Rules::PAID === $row['status'] ) : ?>
								<br><span class="description"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $row['paid_at'] . ' UTC' ) ) . ' · ' . ( isset( self::methods()[ $row['method'] ] ) ? self::methods()[ $row['method'] ] : $row['method'] ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / $per_page );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
			}
			?>
			<hr>
			<h2><?php esc_html_e( 'Calendars', 'reticula-academica' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ra_tuition' ); ?>
				<input type="hidden" name="action" value="ra_tuition"><input type="hidden" name="do" value="start_all">
				<p>
					<?php
					/* translators: %d: number of students. */
					echo esc_html( sprintf( _n( '%d active student has no payment calendar (enrolled before tuition was set up).', '%d active students have no payment calendar (enrolled before tuition was set up).', $missing, 'reticula-academica' ), $missing ) );
					?>
				</p>
				<label><?php esc_html_e( 'Billing start', 'reticula-academica' ); ?> <input type="date" name="start" value="<?php echo esc_attr( self::today() ); ?>"></label>
				<?php submit_button( __( 'Create their calendars', 'reticula-academica' ), 'secondary', 'submit', false, $missing ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px" onsubmit="return confirm('<?php echo esc_js( __( 'Apply the current months and monthly amounts of every term to all pending payments?', 'reticula-academica' ) ); ?>');">
				<?php wp_nonce_field( 'ra_tuition' ); ?>
				<input type="hidden" name="action" value="ra_tuition"><input type="hidden" name="do" value="resync_all">
				<?php submit_button( __( 'Apply term changes to pending payments', 'reticula-academica' ), 'secondary', 'submit', false ); ?>
				<span class="description"><?php esc_html_e( 'Changing the months or the monthly amount of a term does not change existing calendars until you press this button (or "Update calendar" on a student).', 'reticula-academica' ); ?></span>
			</form>
		</div>
		<?php
	}

	public static function handle_export() {
		check_admin_referer( 'ra_tuition_export' );
		if ( ! current_user_can( Post_Types::capability() ) ) {
			wp_die( '', 403 );
		}
		list( $rows ) = self::query( self::filters(), 0 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="colegiaturas-' . self::today() . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'matricula', 'alumno', 'correo', 'carrera', 'concepto', 'vence', 'monto', 'recargo', 'total', 'estado', 'pagado', 'metodo', 'referencia' ) );
		foreach ( $rows as $row ) {
			$cells = array( Student_Id::get( (int) $row['user_id'] ), $row['display_name'], $row['user_email'], get_the_title( (int) $row['program_id'] ), self::concept( $row ), $row['due_date'], $row['amount'], $row['late_fee'], Tuition_Rules::total( $row ), self::row_state( $row ), $row['paid_at'] ? get_date_from_gmt( $row['paid_at'], 'Y-m-d' ) : '', $row['method'], $row['reference'] );
			// Spreadsheet formula injection: neutralize cells starting with =, +, - or @.
			$cells = array_map(
				static function ( $v ) {
					$v = (string) $v;
					return preg_match( '/^[=+\-@]/', $v ) && ! is_numeric( $v ) ? "'" . $v : $v;
				},
				$cells
			);
			fputcsv( $out, $cells );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
