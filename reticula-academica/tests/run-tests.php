<?php
/**
 * Progress engine tests (no WordPress required): php reticula-academica/tests/run-tests.php
 */
define( 'RA_TESTING', true );
require dirname( __DIR__ ) . '/includes/class-progress.php';

use ReticulaAcademica\Progress;

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

echo ( $failures ? "\n{$failures} of {$count} checks failed.\n" : "All {$count} progress checks passed.\n" );
exit( $failures ? 1 : 0 );
