<?php
/**
 * Database table for program inscriptions.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

defined( 'ABSPATH' ) || exit;

class Installer {

	const DB_VERSION = '2';

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
		$ids = $wpdb->prefix . 'ra_student_ids';
		dbDelta(
			"CREATE TABLE {$ids} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  number bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_id (user_id),
  UNIQUE KEY number (number)
) {$charset};"
		);

		$docs = $wpdb->prefix . 'ra_documents';
		dbDelta(
			"CREATE TABLE {$docs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  folio varchar(40) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL,
  template_id bigint(20) unsigned NOT NULL,
  doc_type varchar(20) NOT NULL DEFAULT 'constancia',
  program_id bigint(20) unsigned NOT NULL DEFAULT 0,
  term_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'issued',
  source varchar(20) NOT NULL DEFAULT 'free',
  enrollment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  file varchar(255) NOT NULL DEFAULT '',
  data longtext NULL,
  verify_key varchar(32) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  issued_at datetime DEFAULT NULL,
  issued_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY folio (folio),
  KEY template_id (template_id),
  KEY enrollment_id (enrollment_id),
  KEY status (status)
) {$charset};"
		);

		update_option( 'ra_db_version', self::DB_VERSION );
		do_action( 'ra_installed' );
	}
}
