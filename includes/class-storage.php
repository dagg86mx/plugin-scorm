<?php
/**
 * Package file storage and safe ZIP extraction.
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || exit;

class Storage {

	const FOLDER = 'tutor-scorm';

	/**
	 * Extensions that could be executed by the web server. They are never extracted.
	 */
	const BLOCKED_EXTENSIONS = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'pgif', 'shtml', 'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'ini' );

	const BLOCKED_FILENAMES = array( '.htaccess', '.htpasswd', '.user.ini', 'web.config', 'php.ini' );

	public static function base_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::FOLDER;
	}

	public static function base_url() {
		$uploads = wp_upload_dir( null, false );
		return set_url_scheme( trailingslashit( $uploads['baseurl'] ) . self::FOLDER );
	}

	/**
	 * Create the storage directory and harden it against script execution.
	 */
	public static function ensure_base_dir() {
		$dir = self::base_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules  = "# Tutor SCORM: never execute scripts inside SCORM packages.\n";
			$rules .= "<IfModule mod_php.c>\n\tphp_flag engine off\n</IfModule>\n";
			$rules .= "<IfModule mod_php7.c>\n\tphp_flag engine off\n</IfModule>\n";
			$rules .= "<IfModule mod_php8.c>\n\tphp_flag engine off\n</IfModule>\n";
			$rules .= "<FilesMatch \"\\.(php[0-9]?|phtml|phar|pht|phps|cgi|pl|py|sh|asp|aspx|jsp)$\">\n";
			$rules .= "\t<IfModule mod_authz_core.c>\n\t\tRequire all denied\n\t</IfModule>\n";
			$rules .= "\t<IfModule !mod_authz_core.c>\n\t\tOrder allow,deny\n\t\tDeny from all\n\t</IfModule>\n";
			$rules .= "</FilesMatch>\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return true;
	}

	/**
	 * Check whether a ZIP entry name is safe to extract.
	 *
	 * @param string $name Entry name.
	 * @return bool
	 */
	public static function is_safe_entry( $name ) {
		$name = str_replace( '\\', '/', (string) $name );
		if ( '' === $name || false !== strpos( $name, "\0" ) ) {
			return false;
		}
		if ( '/' === $name[0] || preg_match( '#^[a-z]:#i', $name ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}
		$basename = strtolower( basename( $name ) );
		if ( in_array( $basename, self::BLOCKED_FILENAMES, true ) ) {
			return false;
		}
		// Check every extension of the file name, so "evil.php.jpg" style tricks are also rejected.
		$parts = explode( '.', $basename );
		array_shift( $parts );
		foreach ( $parts as $extension ) {
			if ( in_array( $extension, self::BLOCKED_EXTENSIONS, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Extract a SCORM ZIP into a fresh directory.
	 *
	 * @param string $zip_path   Path to the ZIP file.
	 * @param int    $package_id Package post ID.
	 * @return array|\WP_Error { dir: relative folder name, root: sub folder containing imsmanifest.xml, skipped: int }
	 */
	public static function extract( $zip_path, $package_id ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'tutor_scorm_no_zip', __( 'The PHP ZipArchive extension is required to import SCORM packages.', 'tutor-scorm' ) );
		}
		if ( ! self::ensure_base_dir() ) {
			return new \WP_Error( 'tutor_scorm_storage', __( 'The SCORM storage folder could not be created in wp-content/uploads.', 'tutor-scorm' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'tutor_scorm_bad_zip', __( 'The uploaded file is not a valid ZIP archive.', 'tutor-scorm' ) );
		}

		$max_size      = (int) apply_filters( 'tutor_scorm_max_uncompressed_size', 2 * GB_IN_BYTES );
		$total_size    = 0;
		$entries       = array();
		$skipped       = 0;
		$manifest_root  = null;
		$manifest_depth = PHP_INT_MAX;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! $stat ) {
				continue;
			}
			$name = $stat['name'];
			if ( '/' === substr( $name, -1 ) ) {
				continue;
			}
			// Ignore macOS metadata folders.
			if ( 0 === strpos( $name, '__MACOSX/' ) ) {
				continue;
			}
			if ( ! self::is_safe_entry( $name ) ) {
				++$skipped;
				continue;
			}
			$total_size += (int) $stat['size'];
			$entries[]   = $name;

			$normalized = str_replace( '\\', '/', $name );
			if ( 'imsmanifest.xml' === strtolower( basename( $normalized ) ) ) {
				// Prefer the shallowest manifest (packages zipped with an enclosing folder).
				$folder = dirname( $normalized );
				$folder = ( '.' === $folder ) ? '' : $folder;
				$depth  = ( '' === $folder ) ? 0 : substr_count( $folder, '/' ) + 1;
				if ( null === $manifest_root || $depth < $manifest_depth ) {
					$manifest_root  = $folder;
					$manifest_depth = $depth;
				}
			}
		}

		if ( null === $manifest_root ) {
			$zip->close();
			return new \WP_Error( 'tutor_scorm_no_manifest', __( 'imsmanifest.xml was not found. Make sure you export the course as a SCORM 1.2 or SCORM 2004 package.', 'tutor-scorm' ) );
		}
		if ( $total_size > $max_size ) {
			$zip->close();
			return new \WP_Error( 'tutor_scorm_too_big', __( 'The package is too large once uncompressed.', 'tutor-scorm' ) );
		}

		$folder = absint( $package_id ) . '-' . strtolower( wp_generate_password( 16, false, false ) );
		$target = self::base_dir() . '/' . $folder;
		if ( ! wp_mkdir_p( $target ) ) {
			$zip->close();
			return new \WP_Error( 'tutor_scorm_storage', __( 'The package folder could not be created.', 'tutor-scorm' ) );
		}

		$ok = $zip->extractTo( $target, $entries );
		$zip->close();
		if ( ! $ok ) {
			self::delete_dir( $folder );
			return new \WP_Error( 'tutor_scorm_extract', __( 'The package could not be extracted.', 'tutor-scorm' ) );
		}

		return array(
			'dir'     => $folder,
			'root'    => $manifest_root,
			'skipped' => $skipped,
		);
	}

	public static function package_path( $folder, $root = '' ) {
		return self::base_dir() . '/' . $folder . ( '' !== $root ? '/' . $root : '' );
	}

	public static function package_url( $folder, $root = '' ) {
		$segments = array_map( 'rawurlencode', array_filter( explode( '/', trim( $root, '/' ) ), 'strlen' ) );
		return self::base_url() . '/' . rawurlencode( $folder ) . ( $segments ? '/' . implode( '/', $segments ) : '' );
	}

	/**
	 * Recursively delete a package folder. Only folders directly inside the storage dir can be removed.
	 *
	 * @param string $folder Folder name relative to the storage dir.
	 */
	public static function delete_dir( $folder ) {
		$folder = basename( (string) $folder );
		if ( '' === $folder || '.' === $folder || '..' === $folder ) {
			return;
		}
		$path = self::base_dir() . '/' . $folder;
		if ( ! is_dir( $path ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore
			}
		}
		@rmdir( $path ); // phpcs:ignore
	}
}
