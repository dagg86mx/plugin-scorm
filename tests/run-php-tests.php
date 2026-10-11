<?php
/**
 * Lightweight test runner (no WordPress required): php tests/run-php-tests.php
 */
define( 'TUTOR_SCORM_TESTING', true );
define( 'ABSPATH', __DIR__ . '/' );
define( 'GB_IN_BYTES', 1073741824 );

$GLOBALS['test_options'] = array();
function get_option( $name, $default = false ) { return isset( $GLOBALS['test_options'][ $name ] ) ? $GLOBALS['test_options'][ $name ] : $default; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function apply_filters( $tag, $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_check_invalid_utf8( $s ) { return $s; }

require dirname( __DIR__ ) . '/includes/class-manifest-parser.php';
require dirname( __DIR__ ) . '/includes/class-time.php';
require dirname( __DIR__ ) . '/includes/class-settings.php';
require dirname( __DIR__ ) . '/includes/class-storage.php';
require dirname( __DIR__ ) . '/includes/class-tracking.php';
require dirname( __DIR__ ) . '/includes/class-package-manager.php';

use TutorScorm\Manifest_Parser;
use TutorScorm\Time;
use TutorScorm\Storage;
use TutorScorm\Tracking;
use TutorScorm\Package_Manager;

$failures = 0;
$count    = 0;
function check( $label, $actual, $expected ) {
	global $failures, $count;
	++$count;
	if ( $actual !== $expected ) {
		++$failures;
		echo "FAIL: {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
	}
}

// Manifest parser: SCORM 1.2.
$m = Manifest_Parser::parse_file( __DIR__ . '/fixtures/manifest-12.xml' );
check( '1.2 version', $m['version'], '1.2' );
check( '1.2 title', $m['title'], 'Curso de prueba' );
check( '1.2 sco count', count( $m['scos'] ), 2 );
check( '1.2 sco href with xml:base', $m['scos'][0]['href'], 'content/index.html?x=1' );
check( '1.2 sco launch with parameters', $m['scos'][0]['launch'], 'content/index.html?x=1&lang=es' );
check( '1.2 masteryscore', $m['scos'][0]['masteryscore'], '80' );
check( '1.2 datafromlms', $m['scos'][0]['datafromlms'], 'launch-data' );
check( '1.2 nested depth', $m['scos'][0]['depth'], 1 );
check( '1.2 asset type', $m['scos'][1]['scormtype'], 'asset' );
check( '1.2 sco type', $m['scos'][0]['scormtype'], 'sco' );

// Manifest parser: SCORM 2004.
$m = Manifest_Parser::parse_file( __DIR__ . '/fixtures/manifest-2004.xml' );
check( '2004 version', $m['version'], '2004' );
check( '2004 default organization', $m['scos'][0]['identifier'], 'SCO_ID' );
check( '2004 count', count( $m['scos'] ), 1 );
check( '2004 launch', $m['scos'][0]['launch'], 'scormdriver/indexAPI.html' );
check( '2004 completion threshold', $m['scos'][0]['completion_threshold'], '0.8' );
check( '2004 scaled passing score', $m['scos'][0]['scaled_passing_score'], '0.7' );
check( '2004 max time', $m['scos'][0]['maxtimeallowed'], 'PT1H' );

// Manifest parser: rejects.
foreach ( array(
	'empty'   => '',
	'doctype' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><manifest>&e;</manifest>',
	'not ims' => '<foo/>',
	'no sco'  => '<manifest><organizations/><resources/></manifest>',
) as $label => $xml ) {
	$threw = false;
	try {
		Manifest_Parser::parse_string( $xml );
	} catch ( RuntimeException $e ) {
		$threw = true;
	}
	check( "parser rejects {$label}", $threw, true );
}

// Parameters.
check( 'params hash', Manifest_Parser::append_parameters( 'a.html', '#p1' ), 'a.html#p1' );
check( 'params amp', Manifest_Parser::append_parameters( 'a.html?x=1#h', '&y=2' ), 'a.html?x=1&y=2#h' );

// Time.
check( 'time 1.2 to seconds', Time::to_seconds( '0001:02:03.50' ), 3723.5 );
check( 'time 2004 to seconds', Time::to_seconds( 'PT1H2M3.5S' ), 3723.5 );
check( 'time 2004 days', Time::to_seconds( 'P1DT1S' ), 86401.0 );
check( 'time invalid', Time::to_seconds( 'banana' ), 0.0 );
check( 'time empty PT', Time::to_seconds( 'PT' ), 0.0 );
check( 'format 1.2', Time::format_12( 3723.5 ), '0001:02:03.50' );
check( 'format 2004', Time::format_2004( 3723.5 ), 'PT1H2M3.5S' );
check( 'format 2004 zero', Time::format_2004( 0 ), 'PT0H0M0S' );
check( 'human', Time::human( 3723 ), '1h 02m 03s' );

// ZIP entry filter.
check( 'safe html', Storage::is_safe_entry( 'story_content/index.html' ), true );
check( 'unsafe traversal', Storage::is_safe_entry( '../../wp-config.php' ), false );
check( 'unsafe traversal js', Storage::is_safe_entry( 'a/../../x.js' ), false );
check( 'unsafe absolute', Storage::is_safe_entry( '/etc/passwd' ), false );
check( 'unsafe windows', Storage::is_safe_entry( 'C:\\x.js' ), false );
check( 'unsafe php', Storage::is_safe_entry( 'lib/shell.PHP' ), false );
check( 'unsafe double ext', Storage::is_safe_entry( 'img/x.php.jpg' ), false );
check( 'unsafe phtml', Storage::is_safe_entry( 'x.phtml' ), false );
check( 'unsafe htaccess', Storage::is_safe_entry( 'sub/.htaccess' ), false );
check( 'unsafe user.ini', Storage::is_safe_entry( '.user.ini' ), false );
check( 'safe dotted name', Storage::is_safe_entry( 'mobile/jquery.min.js' ), true );

// Contained paths.
check( 'contained', Package_Manager::is_contained_path( 'a/b/../c.html' ), true );
check( 'escapes', Package_Manager::is_contained_path( 'a/../../c.html' ), false );
check( 'absolute', Package_Manager::is_contained_path( '/c.html' ), false );

// CMI sanitizing.
$clean = Tracking::sanitize_cmi(
	array(
		'cmi.core.lesson_status'   => 'completed',
		'cmi.suspend_data'         => str_repeat( 'x', 70000 ),
		'cmi.core._children'       => 'nope',
		'evil'                     => 'x',
		'cmi.bad key'              => 'x',
		'cmi.interactions.0.id'    => array( 'nested' ),
		'cmi.interactions.0.result' => 'correct',
	)
);
check( 'sanitize keys', array_keys( $clean ), array( 'cmi.core.lesson_status', 'cmi.suspend_data', 'cmi.interactions.0.result' ) );
check( 'sanitize length', strlen( $clean['cmi.suspend_data'] ), 65536 );

// Status derivation.
$d = Tracking::derive( '1.2', array( 'cmi.core.lesson_status' => 'passed', 'cmi.core.score.raw' => '85', 'cmi.core.score.max' => '100' ) );
check( '1.2 derived status', $d['status'], 'passed' );
check( '1.2 derived completion', $d['completion_status'], 'completed' );
check( '1.2 derived scaled', $d['score_scaled'], 0.85 );
$d = Tracking::derive( '1.2', array( 'cmi.core.lesson_status' => 'failed' ) );
check( '1.2 failed success', $d['success_status'], 'failed' );
$d = Tracking::derive( '2004', array( 'cmi.completion_status' => 'completed', 'cmi.success_status' => 'unknown', 'cmi.score.scaled' => '2' ) );
check( '2004 status', $d['status'], 'completed' );
check( '2004 scaled clamp', $d['score_scaled'], 1.0 );

// Completion rules.
check( 'rule completed: completed', Tracking::is_satisfied( 'completed', 'unknown', 'completed' ), true );
check( 'rule completed: passed', Tracking::is_satisfied( 'incomplete', 'passed', 'completed' ), true );
check( 'rule completed: failed', Tracking::is_satisfied( 'completed', 'failed', 'completed' ), false );
check( 'rule passed: completed', Tracking::is_satisfied( 'completed', 'unknown', 'passed' ), false );
check( 'rule passed: passed', Tracking::is_satisfied( 'completed', 'passed', 'passed' ), true );
check( 'rule any: failed', Tracking::is_satisfied( 'incomplete', 'failed', 'any' ), true );
check( 'rule any: incomplete', Tracking::is_satisfied( 'incomplete', 'unknown', 'any' ), false );
check( 'default rule from settings', Tracking::is_satisfied( 'completed', 'unknown' ), true );

echo ( $failures ? "\n{$failures} of {$count} checks failed.\n" : "All {$count} PHP checks passed.\n" );
exit( $failures ? 1 : 0 );
