/* Runtime tests (no browser needed): node tests/run-js-tests.js */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const context = {
	window: {},
	document: { readyState: 'complete', querySelectorAll: () => [], addEventListener() {} },
	Promise, JSON, Object, Number, String, Array, RegExp, isNaN, parseInt, Math
};
context.window.window = context.window;
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/js/scorm-player.js'), 'utf8'), context);
const Runtime = context.window.TutorScormRuntime;

function fakePlayer(version) {
	return {
		config: { version },
		saves: [],
		save(rt, finish) { this.saves.push({ finish, data: Object.assign({}, rt.data) }); },
		onInitialize() {},
		onTerminate() {},
		navValid: (what) => (what === 'continue' ? 'true' : 'unknown')
	};
}

let passed = 0;
function test(name, fn) {
	try { fn(); passed++; } catch (e) { console.error('FAIL: ' + name + '\n  ' + e.message); process.exitCode = 1; }
}

// ---------------- SCORM 1.2 ----------------
test('1.2 lifecycle and errors', () => {
	const p = fakePlayer('1.2');
	const rt = new Runtime(p, { id: 'a', cmi: {}, ro: { 'cmi.core.student_name': 'Doe, Jane', 'cmi.core.entry': 'ab-initio', 'cmi.student_data.mastery_score': '80', 'cmi.core.credit': 'credit' } });
	assert.strictEqual(rt.getValue('cmi.core.lesson_status'), '');
	assert.strictEqual(rt.error, 301);
	assert.strictEqual(rt.initialize(''), 'true');
	assert.strictEqual(rt.initialize(''), 'false');
	assert.strictEqual(rt.getValue('cmi.core.lesson_status'), 'incomplete');
	assert.strictEqual(rt.getValue('cmi.core.student_name'), 'Doe, Jane');
	assert.strictEqual(rt.getValue('cmi.core._children').split(',').includes('lesson_status'), true);
	assert.strictEqual(rt.setValue('cmi.core.student_name', 'x'), 'false');
	assert.strictEqual(rt.error, 403);
	assert.strictEqual(rt.getValue('cmi.core.exit'), '');
	assert.strictEqual(rt.error, 404);
	assert.strictEqual(rt.setValue('cmi.core.lesson_status', 'banana'), 'false');
	assert.strictEqual(rt.error, 405);
	assert.strictEqual(rt.setValue('cmi.core.session_time', '1:00'), 'false');
	assert.strictEqual(rt.setValue('cmi.core.session_time', '0000:01:30.5'), 'true');
	assert.strictEqual(rt.setValue('cmi.core._children', 'x'), 'false');
	assert.strictEqual(rt.error, 402);
	assert.strictEqual(rt.getValue('cmi.core.score._count'), '');
	assert.strictEqual(rt.error, 203);
});

test('1.2 arrays and count', () => {
	const rt = new Runtime(fakePlayer('1.2'), { id: 'a', cmi: {}, ro: {} });
	rt.initialize('');
	assert.strictEqual(rt.getValue('cmi.interactions._count'), '0');
	assert.strictEqual(rt.setValue('cmi.interactions.1.id', 'q2'), 'false'); // out of order
	assert.strictEqual(rt.setValue('cmi.interactions.0.id', 'q1'), 'true');
	assert.strictEqual(rt.setValue('cmi.interactions.0.result', 'correct'), 'true');
	assert.strictEqual(rt.setValue('cmi.interactions.1.id', 'q2'), 'true');
	assert.strictEqual(rt.getValue('cmi.interactions._count'), '2');
	assert.strictEqual(rt.getValue('cmi.interactions.0.id'), '');
	assert.strictEqual(rt.error, 404); // interactions are write-only in 1.2
	assert.strictEqual(rt.getValue('cmi.objectives.0.score._children'), 'raw,min,max');
});

test('1.2 mastery score turns completed into passed/failed', () => {
	const p = fakePlayer('1.2');
	const ro = { 'cmi.student_data.mastery_score': '80', 'cmi.core.credit': 'credit' };
	let rt = new Runtime(p, { id: 'a', cmi: {}, ro });
	rt.initialize('');
	rt.setValue('cmi.core.score.raw', '90');
	rt.setValue('cmi.core.lesson_status', 'completed');
	assert.strictEqual(rt.terminate(''), 'true');
	assert.strictEqual(p.saves.pop().data['cmi.core.lesson_status'], 'passed');
	assert.strictEqual(rt.getValue('cmi.core.lesson_status'), '');
	assert.strictEqual(rt.terminate(''), 'false');

	rt = new Runtime(p, { id: 'a', cmi: {}, ro });
	rt.initialize('');
	rt.setValue('cmi.core.score.raw', '50');
	rt.setValue('cmi.core.lesson_status', 'completed');
	rt.terminate('');
	assert.strictEqual(p.saves.pop().data['cmi.core.lesson_status'], 'failed');
});

test('1.2 resume keeps stored data', () => {
	const rt = new Runtime(fakePlayer('1.2'), { id: 'a', cmi: { 'cmi.suspend_data': 'abc', 'cmi.core.lesson_location': 'slide-4', 'cmi.core.lesson_status': 'incomplete' }, ro: { 'cmi.core.entry': 'resume' } });
	rt.initialize('');
	assert.strictEqual(rt.getValue('cmi.suspend_data'), 'abc');
	assert.strictEqual(rt.getValue('cmi.core.lesson_location'), 'slide-4');
	assert.strictEqual(rt.getValue('cmi.core.entry'), 'resume');
});

// ---------------- SCORM 2004 ----------------
test('2004 lifecycle and errors', () => {
	const p = fakePlayer('2004');
	const rt = new Runtime(p, { id: 'a', cmi: {}, ro: { 'cmi.learner_name': 'Jane', 'cmi.completion_threshold': '', 'cmi.scaled_passing_score': '' } });
	assert.strictEqual(rt.getValue('cmi.location'), '');
	assert.strictEqual(rt.error, 122);
	assert.strictEqual(rt.terminate(''), 'false');
	assert.strictEqual(rt.error, 112);
	assert.strictEqual(rt.initialize(''), 'true');
	assert.strictEqual(rt.initialize(''), 'false');
	assert.strictEqual(rt.error, 103);
	assert.strictEqual(rt.getValue('cmi._version'), '1.0');
	assert.strictEqual(rt.getValue('cmi.completion_status'), 'unknown');
	assert.strictEqual(rt.setValue('cmi.learner_name', 'x'), 'false');
	assert.strictEqual(rt.error, 404);
	assert.strictEqual(rt.getValue('cmi.session_time'), '');
	assert.strictEqual(rt.error, 405);
	assert.strictEqual(rt.setValue('cmi.score.scaled', '1.5'), 'false');
	assert.strictEqual(rt.error, 407);
	assert.strictEqual(rt.setValue('cmi.score.scaled', 'abc'), 'false');
	assert.strictEqual(rt.error, 406);
	assert.strictEqual(rt.setValue('cmi.session_time', 'PT1M30S'), 'true');
	assert.strictEqual(rt.setValue('cmi.session_time', '00:01:30'), 'false');
	assert.strictEqual(rt.setValue('cmi.exit', 'suspend'), 'true');
	assert.strictEqual(rt.setValue('cmi.interactions.0.id', 'q1'), 'true');
	assert.strictEqual(rt.getValue('cmi.interactions.0.id'), 'q1');
	assert.strictEqual(rt.getValue('cmi.interactions._count'), '1');
	assert.strictEqual(rt.getValue('adl.nav.request_valid.continue'), 'true');
	assert.strictEqual(rt.setValue('adl.nav.request', 'continue'), 'true');
	assert.strictEqual(rt.setValue('adl.nav.request', 'bogus'), 'false');
	assert.strictEqual(rt.commit(''), 'true');
	assert.strictEqual(p.saves.length, 1);
	assert.strictEqual(p.saves[0].finish, false);
	assert.strictEqual(rt.terminate(''), 'true');
	assert.strictEqual(p.saves[1].finish, true);
	assert.strictEqual(rt.getValue('cmi.location'), '');
	assert.strictEqual(rt.error, 123);
	assert.strictEqual(rt.commit(''), 'false');
	assert.strictEqual(rt.error, 143);
	assert.strictEqual(rt.errorString('143'), 'Commit after termination');
});

test('2004 threshold and passing score evaluation', () => {
	const p = fakePlayer('2004');
	const rt = new Runtime(p, { id: 'a', cmi: {}, ro: { 'cmi.completion_threshold': '0.8', 'cmi.scaled_passing_score': '0.7' } });
	rt.initialize('');
	rt.setValue('cmi.progress_measure', '0.5');
	rt.setValue('cmi.completion_status', 'completed');
	assert.strictEqual(rt.getValue('cmi.completion_status'), 'incomplete');
	rt.setValue('cmi.progress_measure', '0.9');
	assert.strictEqual(rt.getValue('cmi.completion_status'), 'completed');
	rt.setValue('cmi.score.scaled', '0.65');
	assert.strictEqual(rt.getValue('cmi.success_status'), 'failed');
	rt.setValue('cmi.score.scaled', '0.75');
	rt.terminate('');
	const saved = p.saves.pop().data;
	assert.strictEqual(saved['cmi.completion_status'], 'completed');
	assert.strictEqual(saved['cmi.success_status'], 'passed');
});

console.log(process.exitCode ? 'JS runtime tests failed.' : 'All ' + passed + ' JS runtime test groups passed.');
