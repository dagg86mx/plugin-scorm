<?php
/**
 * Admin reports: learner progress per SCO and CSV export.
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Reports {

	public static function init() {
		add_action( 'admin_post_tutor_scorm_export', array( __CLASS__, 'export_csv' ) );
	}

	public static function capability() {
		return apply_filters( 'tutor_scorm_reports_capability', 'manage_options' );
	}

	/**
	 * Build WHERE clause and arguments from request filters.
	 *
	 * @return array [ string $where, array $args ]
	 */
	public static function filters_sql() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $_REQUEST['package_id'] ) ) {
			$where[] = 't.package_id = %d';
			$args[]  = absint( $_REQUEST['package_id'] );
		}
		if ( ! empty( $_REQUEST['course_id'] ) ) {
			$where[] = 't.course_id = %d';
			$args[]  = absint( $_REQUEST['course_id'] );
		}
		if ( ! empty( $_REQUEST['status'] ) ) {
			$where[] = 't.status = %s';
			$args[]  = sanitize_text_field( wp_unslash( $_REQUEST['status'] ) );
		}
		if ( ! empty( $_REQUEST['s'] ) ) {
			$like    = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) . '%';
			$where[] = '(u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		// phpcs:enable
		return array( implode( ' AND ', $where ), $args );
	}

	public static function query( $limit = 0, $offset = 0, $orderby = 'updated_at', $order = 'DESC' ) {
		global $wpdb;
		$table              = Installer::table();
		list( $where, $args ) = self::filters_sql();

		$allowed = array( 'updated_at', 'status', 'score_raw', 'total_seconds', 'attempts', 'display_name' );
		$orderby = in_array( $orderby, $allowed, true ) ? $orderby : 'updated_at';
		$order   = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$column  = 'display_name' === $orderby ? 'u.display_name' : 't.' . $orderby;

		$sql = "SELECT SQL_CALC_FOUND_ROWS t.*, u.display_name, u.user_email, p.post_title AS package_title
			FROM {$table} t
			LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id
			LEFT JOIN {$wpdb->posts} p ON p.ID = t.package_id
			WHERE {$where}
			ORDER BY {$column} {$order}, t.id DESC";
		if ( $limit ) {
			$sql   .= ' LIMIT %d OFFSET %d';
			$args[] = (int) $limit;
			$args[] = (int) $offset;
		}
		$prepared = $args ? $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB
		$rows     = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB
		$total    = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' ); // phpcs:ignore WordPress.DB
		return array( $rows, $total );
	}

	public static function sco_title( $package_id, $sco_id ) {
		static $cache = array();
		if ( ! isset( $cache[ $package_id ] ) ) {
			$cache[ $package_id ] = array();
			$scos                 = get_post_meta( $package_id, '_tutor_scorm_scos', true );
			foreach ( is_array( $scos ) ? $scos : array() as $sco ) {
				$cache[ $package_id ][ $sco['identifier'] ] = $sco['title'];
			}
		}
		return isset( $cache[ $package_id ][ $sco_id ] ) ? $cache[ $package_id ][ $sco_id ] : $sco_id;
	}

	public static function format_score( $row ) {
		if ( null === $row['score_raw'] || '' === $row['score_raw'] ) {
			if ( null !== $row['score_scaled'] && '' !== $row['score_scaled'] ) {
				return round( (float) $row['score_scaled'] * 100 ) . '%';
			}
			return '';
		}
		$score = rtrim( rtrim( (string) $row['score_raw'], '0' ), '.' );
		if ( null !== $row['score_max'] && '' !== $row['score_max'] && (float) $row['score_max'] > 0 ) {
			$score .= ' / ' . rtrim( rtrim( (string) $row['score_max'], '0' ), '.' );
		}
		return $score;
	}

	public static function status_label( $status ) {
		$labels = array(
			'not attempted' => __( 'Not started', 'tutor-scorm' ),
			'unknown'       => __( 'Not started', 'tutor-scorm' ),
			'incomplete'    => __( 'In progress', 'tutor-scorm' ),
			'browsed'       => __( 'Browsed', 'tutor-scorm' ),
			'completed'     => __( 'Completed', 'tutor-scorm' ),
			'passed'        => __( 'Passed', 'tutor-scorm' ),
			'failed'        => __( 'Failed', 'tutor-scorm' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	public static function render_page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$table = new Reports_List_Table();
		$table->process_bulk_action();
		$table->prepare_items();

		$packages = get_posts(
			array(
				'post_type'      => Package_Manager::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$current_package = isset( $_REQUEST['package_id'] ) ? absint( $_REQUEST['package_id'] ) : 0;
		$current_status  = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : '';
		$page            = isset( $_REQUEST['page'] ) ? sanitize_key( $_REQUEST['page'] ) : 'tutor-scorm-reports';
		// phpcs:enable

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'tutor_scorm_export',
					'package_id' => $current_package,
					'status'     => $current_status,
					's'          => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				),
				admin_url( 'admin-post.php' )
			),
			'tutor_scorm_export'
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'SCORM Reports', 'tutor-scorm' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'tutor-scorm' ); ?></a>
			<hr class="wp-header-end">
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>">
				<?php if ( isset( $_REQUEST['post_type'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<input type="hidden" name="post_type" value="<?php echo esc_attr( sanitize_key( $_REQUEST['post_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
				<?php endif; ?>
				<div class="alignleft actions" style="margin-bottom:8px">
					<select name="package_id">
						<option value="0"><?php esc_html_e( 'All packages', 'tutor-scorm' ); ?></option>
						<?php foreach ( $packages as $package ) : ?>
							<option value="<?php echo esc_attr( $package->ID ); ?>" <?php selected( $current_package, $package->ID ); ?>><?php echo esc_html( get_the_title( $package ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="status">
						<option value=""><?php esc_html_e( 'All statuses', 'tutor-scorm' ); ?></option>
						<?php foreach ( array( 'not attempted', 'incomplete', 'browsed', 'completed', 'passed', 'failed', 'unknown' ) as $status ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $current_status, $status ); ?>><?php echo esc_html( self::status_label( $status ) . ( 'unknown' === $status ? ' (2004)' : '' ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Filter', 'tutor-scorm' ), '', 'filter_action', false ); ?>
				</div>
				<?php
				$table->search_box( __( 'Search learners', 'tutor-scorm' ), 'tutor-scorm-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	public static function export_csv() {
		if ( ! current_user_can( self::capability() ) || ! check_admin_referer( 'tutor_scorm_export' ) ) {
			wp_die( esc_html__( 'You are not allowed to export this report.', 'tutor-scorm' ), 403 );
		}
		list( $rows ) = self::query();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=scorm-report-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- UTF-8 BOM for Excel.
		fputcsv( $out, array( 'user_id', 'name', 'email', 'package', 'sco', 'course', 'lesson', 'status', 'completion_status', 'success_status', 'score_raw', 'score_max', 'score_scaled', 'time_seconds', 'attempts', 'completed_at_utc', 'updated_at_utc' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_cell' ),
					array(
						$row['user_id'],
						$row['display_name'],
						$row['user_email'],
						$row['package_title'],
						self::sco_title( $row['package_id'], $row['sco_id'] ),
						$row['course_id'] ? get_the_title( $row['course_id'] ) : '',
						$row['lesson_id'] ? get_the_title( $row['lesson_id'] ) : '',
						$row['status'],
						$row['completion_status'],
						$row['success_status'],
						$row['score_raw'],
						$row['score_max'],
						$row['score_scaled'],
						$row['total_seconds'],
						$row['attempts'],
						$row['completed_at'],
						$row['updated_at'],
					)
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Neutralize spreadsheet formulas (CSV injection).
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}

class Reports_List_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'tutor_scorm_attempt',
				'plural'   => 'tutor_scorm_attempts',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox">',
			'display_name'  => __( 'Learner', 'tutor-scorm' ),
			'package'       => __( 'Package / item', 'tutor-scorm' ),
			'lesson'        => __( 'Course / lesson', 'tutor-scorm' ),
			'status'        => __( 'Status', 'tutor-scorm' ),
			'score_raw'     => __( 'Score', 'tutor-scorm' ),
			'total_seconds' => __( 'Time', 'tutor-scorm' ),
			'attempts'      => __( 'Sessions', 'tutor-scorm' ),
			'updated_at'    => __( 'Last activity', 'tutor-scorm' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'display_name'  => array( 'display_name', false ),
			'status'        => array( 'status', false ),
			'score_raw'     => array( 'score_raw', true ),
			'total_seconds' => array( 'total_seconds', true ),
			'attempts'      => array( 'attempts', true ),
			'updated_at'    => array( 'updated_at', true ),
		);
	}

	protected function get_bulk_actions() {
		return array( 'reset' => __( 'Reset progress', 'tutor-scorm' ) );
	}

	public function process_bulk_action() {
		if ( 'reset' !== $this->current_action() || empty( $_REQUEST['attempt'] ) ) {
			return;
		}
		check_admin_referer( 'bulk-' . $this->_args['plural'] );
		if ( ! current_user_can( Reports::capability() ) ) {
			return;
		}
		$deleted = Tracking::delete_ids( array_map( 'absint', (array) $_REQUEST['attempt'] ) );
		/* translators: %d: number of records. */
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d record reset.', '%d records reset.', $deleted, 'tutor-scorm' ), $deleted ) ) . '</p></div>';
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'tutor_scorm_reports_per_page', 30 );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'updated_at';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'desc';
		// phpcs:enable
		list( $rows, $total ) = Reports::query( $per_page, ( $this->get_pagenum() - 1 ) * $per_page, $orderby, $order );

		$this->items           = $rows;
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	public function no_items() {
		esc_html_e( 'No SCORM activity recorded yet.', 'tutor-scorm' );
	}

	protected function column_cb( $item ) {
		return '<input type="checkbox" name="attempt[]" value="' . esc_attr( $item['id'] ) . '">';
	}

	protected function column_display_name( $item ) {
		$name = $item['display_name'] ? $item['display_name'] : '#' . $item['user_id'];
		return '<strong><a href="' . esc_url( get_edit_user_link( $item['user_id'] ) ) . '">' . esc_html( $name ) . '</a></strong><br><span class="description">' . esc_html( $item['user_email'] ) . '</span>';
	}

	protected function column_package( $item ) {
		return '<a href="' . esc_url( get_edit_post_link( $item['package_id'] ) ) . '">' . esc_html( $item['package_title'] ) . '</a><br><span class="description">' . esc_html( Reports::sco_title( $item['package_id'], $item['sco_id'] ) ) . '</span>';
	}

	protected function column_lesson( $item ) {
		if ( ! $item['lesson_id'] ) {
			return '&mdash;';
		}
		$course = $item['course_id'] ? esc_html( get_the_title( $item['course_id'] ) ) . '<br>' : '';
		return $course . '<a href="' . esc_url( get_permalink( $item['lesson_id'] ) ) . '"><span class="description">' . esc_html( get_the_title( $item['lesson_id'] ) ) . '</span></a>';
	}

	protected function column_status( $item ) {
		$satisfied = Tracking::is_satisfied( $item['completion_status'], $item['success_status'] );
		$output    = esc_html( Reports::status_label( $item['status'] ) );
		if ( $satisfied ) {
			$output = '<span style="color:#1c7d43;font-weight:600">&#10003; ' . $output . '</span>';
		}
		return $output;
	}

	protected function column_score_raw( $item ) {
		$score = Reports::format_score( $item );
		return '' === $score ? '&mdash;' : esc_html( $score );
	}

	protected function column_total_seconds( $item ) {
		return esc_html( Time::human( (int) $item['total_seconds'] ) );
	}

	protected function column_updated_at( $item ) {
		$timestamp = strtotime( $item['updated_at'] . ' UTC' );
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) );
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}
}
