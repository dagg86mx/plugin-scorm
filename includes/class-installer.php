<?php
/**
 * Database installation and upgrades.
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || exit;

class Installer {

	const DB_VERSION = '1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tutor_scorm_tracking';
	}

	public static function activate() {
		self::create_tables();
		Storage::ensure_base_dir();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'tutor_scorm_db_version' ) !== self::DB_VERSION ) {
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
  package_id bigint(20) unsigned NOT NULL,
  sco_id varchar(191) NOT NULL DEFAULT '',
  lesson_id bigint(20) unsigned NOT NULL DEFAULT 0,
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  scorm_version varchar(10) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'not attempted',
  completion_status varchar(20) NOT NULL DEFAULT '',
  success_status varchar(20) NOT NULL DEFAULT '',
  score_raw decimal(10,2) DEFAULT NULL,
  score_min decimal(10,2) DEFAULT NULL,
  score_max decimal(10,2) DEFAULT NULL,
  score_scaled decimal(7,4) DEFAULT NULL,
  total_seconds int(10) unsigned NOT NULL DEFAULT 0,
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  cmi_data longtext NULL,
  completed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_sco (user_id,package_id,sco_id),
  KEY package_id (package_id),
  KEY lesson_id (lesson_id)
) {$charset};"
		);

		update_option( 'tutor_scorm_db_version', self::DB_VERSION );
	}
}
