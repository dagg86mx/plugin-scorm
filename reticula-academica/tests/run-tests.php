<?php
/**
 * Progress engine tests (no WordPress required): php reticula-academica/tests/run-tests.php
 */
define( 'RA_TESTING', true );
require dirname( __DIR__ ) . '/includes/class-progress.php';
require dirname( __DIR__ ) . '/includes/class-grade-calculator.php';
require dirname( __DIR__ ) . '/includes/class-template-renderer.php';

use ReticulaAcademica\Progress;
use ReticulaAcademica\Grade_Calculator as GC;
use ReticulaAcademica\Template_Renderer as TR;

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

// Program: T1 [101, 102], T2 [201, 202] (fee 900), T3 [301].
$terms = array(
	array( 'id' => 1, 'title' => 'T1', 'number' => 1, 'fee_course' => 0, 'courses' => array( 101, 102 ) ),
	array( 'id' => 2, 'title' => 'T2', 'number' => 2, 'fee_course' => 900, 'courses' => array( 201, 202 ) ),
	array( 'id' => 3, 'title' => 'T3', 'number' => 3, 'fee_course' => 0, 'courses' => array( 301 ) ),
);
$statuses = function ( $p ) {
	$out = array();
	foreach ( $p['terms'] as $t ) {
		foreach ( $t['courses'] as $c ) {
			$out[ $c['id'] ] = $c['status'] . ( $c['lock_reason'] ? ':' . $c['lock_reason'] : '' );
		}
	}
	return $out;
};

// 1. Pending inscription fee: everything locked, next = pay inscription.
$p = Progress::compute( array( 'terms' => $terms, 'current_term_id' => 1, 'status' => 'pending', 'inscription_fee' => 800, 'mode' => 'strict' ) );
check( 'pending status', $p['status'], 'pending' );
check( 'pending next', $p['next'], array( 'action' => 'pay_inscription', 'course_id' => 800, 'term_id' => 0 ) );
check( 'pending locks', $statuses( $p )[101], 'locked:inscription' );

// 2. Fee paid (enrolled in fee course) activates inscription. Strict: only the first course available.
$p = Progress::compute( array( 'terms' => $terms, 'current_term_id' => 1, 'status' => 'pending', 'inscription_fee' => 800, 'mode' => 'strict', 'courses' => array( 800 => array( 'enrolled' => true ) ) ) );
check( 'fee paid activates', $p['status'], 'active' );
check( 'strict statuses', $statuses( $p ), array( 101 => 'available', 102 => 'locked:order', 201 => 'locked:fee', 202 => 'locked:fee', 301 => 'locked:order' ) );
check( 'strict next enroll', $p['next'], array( 'action' => 'enroll', 'course_id' => 101, 'term_id' => 1 ) );
check( 'next flagged', $p['terms'][0]['courses'][0]['is_next'], true );
check( 'current term', $p['current_term_id'], 1 );
check( 'term status current', $p['terms'][0]['status'], 'current' );
check( 'term status upcoming', $p['terms'][1]['status'], 'upcoming' );

// 3. In progress course -> continue; percent.
$courses = array( 101 => array( 'enrolled' => true, 'percent' => 40 ) );
$p       = Progress::compute( array( 'terms' => $terms, 'current_term_id' => 1, 'mode' => 'strict', 'courses' => $courses ) );
check( 'continue next', $p['next']['action'], 'continue' );
check( 'percent carried', $p['terms'][0]['courses'][0]['percent'], 40 );

// 4. T1 completed -> auto advance to T2, T2 fee unpaid -> next pay fee.
$courses = array( 101 => array( 'completed' => true ), 102 => array( 'completed' => true ) );
$p       = Progress::compute( array( 'terms' => $terms, 'current_term_id' => 1, 'mode' => 'strict', 'courses' => $courses ) );
check( 'advanced to T2', $p['current_term_id'], 2 );
check( 'T1 completed', $p['terms'][0]['status'], 'completed' );
check( 'pay term fee', $p['next'], array( 'action' => 'pay_fee', 'course_id' => 900, 'term_id' => 2 ) );
check( 'overall percent', array( $p['completed'], $p['total'], $p['percent'] ), array( 2, 5, 40 ) );

// 5. T2 fee paid: term mode allows both T2 courses but not T3.
$courses[900] = array( 'enrolled' => true );
$p            = Progress::compute( array( 'terms' => $terms, 'current_term_id' => 1, 'mode' => 'term', 'courses' => $courses ) );
check( 'term mode statuses', $statuses( $p ), array( 101 => 'completed', 102 => 'completed', 201 => 'available', 202 => 'available', 301 => 'locked:term' ) );
// Advisory mode: nothing locked by order.
$p = Progress::compute( array( 'terms' => $terms, 'current_term_id' => 1, 'mode' => 'advisory', 'courses' => $courses ) );
check( 'advisory T3 available', $statuses( $p )[301], 'available' );
check( 'lock_reason helper', Progress::lock_reason( $p, 301 ), '' );
check( 'lock_reason not in program', Progress::lock_reason( $p, 999 ), null );

// 6. Everything completed -> graduated.
$all = array();
foreach ( array( 101, 102, 201, 202, 301 ) as $id ) {
	$all[ $id ] = array( 'completed' => true );
}
$p = Progress::compute( array( 'terms' => $terms, 'current_term_id' => 2, 'mode' => 'strict', 'courses' => $all ) );
check( 'graduated', array( $p['status'], $p['finished'], $p['next'], $p['percent'] ), array( 'graduated', true, null, 100 ) );

// 7. Started at T2 (revalidated T1): T1 exempt and excluded from totals; strict order starts at T2.
$p = Progress::compute( array( 'terms' => $terms, 'start_term_id' => 2, 'current_term_id' => 2, 'mode' => 'strict', 'courses' => array( 900 => array( 'enrolled' => true ) ) ) );
check( 'exempt term', $p['terms'][0]['status'], 'exempt' );
check( 'exempt courses', $statuses( $p )[101], 'exempt' );
check( 'start term first course available', $statuses( $p )[201], 'available' );
check( 'totals exclude exempt', $p['total'], 3 );
check( 'current is start', $p['current_term_id'], 2 );

// 8. Admin moved learner to T3 while T2 unfinished: T2 shown as behind.
$p = Progress::compute( array( 'terms' => $terms, 'current_term_id' => 3, 'mode' => 'term', 'courses' => array( 101 => array( 'completed' => true ), 102 => array( 'completed' => true ), 900 => array( 'enrolled' => true ) ) ) );
check( 'behind term', $p['terms'][1]['status'], 'behind' );
check( 'T3 available in term mode', $statuses( $p )[301], 'available' );

// 9. Paused inscription locks unbought courses but keeps enrolled ones.
$p = Progress::compute( array( 'terms' => $terms, 'current_term_id' => 1, 'status' => 'paused', 'courses' => array( 101 => array( 'enrolled' => true ) ) ) );
check( 'paused keeps enrolled', $statuses( $p )[101], 'in_progress' );
check( 'paused locks others', $statuses( $p )[102], 'locked:status' );
check( 'paused no next', $p['next'], null );

// 10. Locate helper.
$loc = Progress::locate( $p, 202 );
check( 'locate', array( $loc['term']['id'], $loc['position'], $loc['of'] ), array( 2, 2, 2 ) );

// 11. Empty program.
$p = Progress::compute( array( 'terms' => array() ) );
check( 'empty program', array( $p['total'], $p['next'], $p['status'], $p['finished'] ), array( 0, null, 'active', false ) );


// ---------------- Grades ----------------
$q = function ( $n, $score, $state = 'graded' ) {
	return array( 'key' => 'quiz:' . $n, 'title' => 'Quiz ' . $n, 'type' => 'quiz', 'score' => $score, 'state' => $state );
};
// 5 quizzes at 20% each.
$items = array( $q( 1, 100 ), $q( 2, 90 ), $q( 3, 80 ), $q( 4, 70 ), $q( 5, 60 ) );
$g     = GC::course( $items, array() );
check( 'equal weights grade', $g['grade'], 8.0 );
check( 'equal weights each 20%', array_column( $g['items'], 'weight' ), array( 20.0, 20.0, 20.0, 20.0, 20.0 ) );
check( 'passed', $g['status'], 'passed' );
check( 'item score in scale', $g['items'][1]['score'], 9.0 );

// Custom weights: final exam 60%.
$g = GC::course( $items, array( 'quiz:1' => 10, 'quiz:2' => 10, 'quiz:3' => 10, 'quiz:4' => 10, 'quiz:5' => 60 ) );
check( 'weighted grade (exactly passing)', $g['grade'], 7.0 );
check( 'passing grade is inclusive', $g['status'], 'passed' );
// Weights not adding to 100 are normalized; weight 0 excludes.
$g = GC::course( $items, array( 'quiz:1' => 1, 'quiz:2' => 1, 'quiz:3' => 0 ) );
check( 'normalized weights', array( $g['grade'], count( $g['items'] ) ), array( 9.5, 2 ) );

// Missing evaluation: partial grade over what was taken, zero once final.
$partial = array( $q( 1, 100 ), $q( 2, 80 ), $q( 3, null, 'not_taken' ) );
$g       = GC::course( $partial, array() );
check( 'partial status', array( $g['status'], $g['grade'], $g['missing'] ), array( 'in_progress', 9.0, 1 ) );
$g = GC::course( $partial, array(), array( 'final' => true ) );
check( 'final counts missing as 0', array( $g['status'], $g['grade'] ), array( 'failed', 6.0 ) );
$g = GC::course( array( $q( 1, 90 ), $q( 2, null, 'pending_review' ) ), array(), array( 'final' => true ) );
check( 'under review never counts as zero', array( $g['status'], $g['grade'] ), array( 'in_progress', 9.0 ) );
$g = GC::course( array( $q( 1, null, 'pending_review' ) ), array() );
check( 'nothing graded', array( $g['status'], $g['grade'] ), array( 'none', null ) );
// Scale 100 and passing 60.
$g = GC::course( $items, array(), array( 'scale' => 100, 'passing' => 60, 'decimals' => 0 ) );
check( 'scale 100', array( $g['grade'], $g['status'] ), array( 80.0, 'passed' ) );
// SCORM items weigh 0 unless configured.
$scorm = array( 'key' => 'scorm:9', 'title' => 'SCORM', 'type' => 'scorm', 'score' => 0, 'state' => 'graded' );
$g     = GC::course( array( $q( 1, 100 ), $scorm ), array() );
check( 'scorm default excluded', $g['grade'], 10.0 );
$g = GC::course( array( $q( 1, 100 ), $scorm ), array( 'quiz:1' => 50, 'scorm:9' => 50 ) );
check( 'scorm configured', $g['grade'], 5.0 );
check( 'average of final grades', GC::average( array( array( 'status' => 'passed', 'grade' => 9.0 ), array( 'status' => 'failed', 'grade' => 6.0 ), array( 'status' => 'in_progress', 'grade' => 1.0 ) ) ), 7.5 );
check( 'format', array( GC::format( 8, 1 ), GC::format( null, 1 ) ), array( '8.0', '—' ) );

// Final grade (CF) and extraordinary exams.
$f = function ( $grade, $status = 'passed' ) { return array( 'grade' => $grade, 'status' => $status, 'items' => array() ); };
check( 'CF rounds passing grades', GC::with_final( $f( 9.8 ), null, 7 )['cf'], 10 );
check( 'CF keeps 9', GC::with_final( $f( 9.0 ), null, 7 )['cf'], 9 );
check( 'CF 6.0 with passing 6', GC::with_final( $f( 6.0 ), null, 6 )['cf'], 6 );
check( 'CF never rounds a failing grade up', GC::with_final( $f( 6.8, 'failed' ), null, 7 )['cf'], 6 );
check( 'CF 6.5 fails with passing 7', GC::with_final( $f( 6.5, 'failed' ), null, 7 )['cf'], 6 );
$x = GC::with_final( $f( 5.0, 'failed' ), 8, 7 );
check( 'EXT replaces F', array( $x['final'], $x['cf'], $x['status'], $x['grade'] ), array( 8.0, 8, 'passed', 5.0 ) );
check( 'no grade, no CF', GC::with_final( $f( null, 'none' ), null, 7 )['cf'], null );
check( 'average uses EXT', GC::average( array( $x, GC::with_final( $f( 9.0 ), null, 7 ) ) ), 8.5 );
// Reference report card: 9.00, 9.80, 6.00 → cycle average 8.3, CF 9, 10, 6 (passing 6).
$ref = array_map( function ( $g ) use ( $f ) { return GC::with_final( $f( $g ), null, 6 ); }, array( 9.0, 9.8, 6.0 ) );
check( 'reference CFs', array_column( $ref, 'cf' ), array( 9, 10, 6 ) );
check( 'reference cycle average', GC::average( $ref ), 8.3 );

// ---------------- Templates ----------------
check( 'render', TR::render( 'Hola {alumno}, {matricula}. {desconocido}', array( 'alumno' => 'Ana', 'matricula' => 'UNTGU-10000' ) ), 'Hola Ana, UNTGU-10000. {desconocido}' );
check( 'block placeholder unwrapped', TR::render( "<p>{tabla_calificaciones}</p>\n<p>x</p>", array( 'tabla_calificaciones' => '<table></table>' ) ), "<table></table>\n<p>x</p>" );
check( 'long date', TR::long_date( gmmktime( 12, 0, 0, 10, 19, 2026 ) ), '19 de octubre de 2026' );
check( 'ordinal', array( TR::ordinal( 1 ), TR::ordinal( 3 ), TR::ordinal( 15 ) ), array( 'primer', 'tercer', '15º' ) );
check( 'folio', TR::folio( 'con', 2026, 42 ), 'CON-2026-000042' );

echo ( $failures ? "\n{$failures} of {$count} checks failed.\n" : "All {$count} checks passed.\n" );
exit( $failures ? 1 : 0 );
