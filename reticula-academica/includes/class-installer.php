<?php
/**
 * Database table for program inscriptions.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Installer {

	const DB_VERSION = '1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ra_inscriptions';
	}

	public static function activate() {
		self::create_tables();
		// Tutor builds its dashboard rewrite rules from the nav items, which we extend: flush on the next request.
		update_option( 'ra_flush_rewrite', 1 );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'ra_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  program_id bigint(20) unsigned NOT NULL,
  start_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
  current_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  source varchar(20) NOT NULL DEFAULT 'admin',
  progress tinyint(3) unsigned NOT NULL DEFAULT 0,
  next_course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  notes text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_program (user_id,program_id),
  KEY program_id (program_id),
  KEY status (status)
) {$charset};"
		);
		update_option( 'ra_db_version', self::DB_VERSION );
	}
}
