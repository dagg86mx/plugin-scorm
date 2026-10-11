/**
 * Tutor SCORM player and runtime (SCORM 1.2 "API" and SCORM 2004 "API_1484_11").
 *
 * The page hosting the player exposes the SCORM API objects on window; the SCO is
 * loaded in an iframe (or a pop-up window) and discovers them through window.parent
 * or window.opener, as required by the SCORM Run-Time Environment specification.
 */
(function () {
	'use strict';

	var L10N = window.TutorScormL10n || { statuses: {} };

	/* ------------------------------------------------------------------ *
	 * Data model definitions
	 * ------------------------------------------------------------------ */

	var ERRORS_12 = {
		0: 'No error',
		101: 'General exception',
		201: 'Invalid argument error',
		202: 'Element cannot have children',
		203: 'Element not an array - cannot have count',
		301: 'Not initialized',
		401: 'Not implemented error',
		402: 'Invalid set value, element is a keyword',
		403: 'Element is read only',
		404: 'Element is write only',
		405: 'Incorrect data type'
	};

	var ERRORS_2004 = {
		0: 'No error',
		101: 'General exception',
		102: 'General initialization failure',
		103: 'Already initialized',
		104: 'Content instance terminated',
		111: 'General termination failure',
		112: 'Termination before initialization',
		113: 'Termination after termination',
		122: 'Retrieve data before initialization',
		123: 'Retrieve data after termination',
		132: 'Store data before initialization',
		133: 'Store data after termination',
		142: 'Commit before initialization',
		143: 'Commit after termination',
		201: 'General argument error',
		301: 'General get failure',
		351: 'General set failure',
		391: 'General commit failure',
		401: 'Undefined data model element',
		402: 'Unimplemented data model element',
		403: 'Data model element value not initialized',
		404: 'Data model element is read only',
		405: 'Data model element is write only',
		406: 'Data model element type mismatch',
		407: 'Data model element value out of range',
		408: 'Data model dependency not established'
	};

	var MODEL_12 = {
		version: '3.4',
		children: {
			'cmi.core._children': 'student_id,student_name,lesson_location,credit,lesson_status,entry,score,total_time,lesson_mode,exit,session_time',
			'cmi.core.score._children': 'raw,min,max',
			'cmi.objectives._children': 'id,score,status',
			'cmi.objectives.n.score._children': 'raw,min,max',
			'cmi.student_data._children': 'mastery_score,max_time_allowed,time_limit_action',
			'cmi.student_preference._children': 'audio,language,speed,text',
			'cmi.interactions._children': 'id,objectives,time,type,correct_responses,weighting,student_response,result,latency'
		},
		arrays: /^cmi\.(objectives|interactions|interactions\.\d+\.objectives|interactions\.\d+\.correct_responses)$/,
		writeOnly: /^cmi\.(core\.exit|core\.session_time|interactions\.\d+\.(id|time|type|weighting|student_response|result|latency|objectives\.\d+\.id|correct_responses\.\d+\.pattern))$/,
		defaults: {
			'cmi.core.lesson_status': 'not attempted',
			'cmi.core.lesson_location': '',
			'cmi.core.score.raw': '',
			'cmi.core.score.min': '',
			'cmi.core.score.max': '',
			'cmi.suspend_data': '',
			'cmi.comments': '',
			'cmi.student_preference.audio': '0',
			'cmi.student_preference.language': '',
			'cmi.student_preference.speed': '0',
			'cmi.student_preference.text': '0'
		},
		vocab: {
			'cmi.core.lesson_status': ['passed', 'completed', 'failed', 'incomplete', 'browsed', 'not attempted'],
			'cmi.core.exit': ['time-out', 'suspend', 'logout', ''],
			'cmi.objectives.n.status': ['passed', 'completed', 'failed', 'incomplete', 'browsed', 'not attempted'],
			'cmi.interactions.n.type': ['true-false', 'choice', 'fill-in', 'matching', 'performance', 'sequencing', 'likert', 'numeric'],
			'cmi.interactions.n.result': ['correct', 'wrong', 'unanticipated', 'neutral', /^-?\d+(\.\d+)?$/]
		},
		numeric: /^cmi\.(core\.score\.(raw|min|max)|objectives\.\d+\.score\.(raw|min|max))$/,
		timespan: /^cmi\.core\.session_time$/,
		timespanFormat: /^\d{2,4}:\d{2}:\d{2}(\.\d{1,2})?$/
	};

	var MODEL_2004 = {
		version: '1.0',
		children: {
			'cmi.comments_from_learner._children': 'comment,location,timestamp',
			'cmi.comments_from_lms._children': 'comment,location,timestamp',
			'cmi.interactions._children': 'id,type,objectives,timestamp,correct_responses,weighting,learner_response,result,latency,description',
			'cmi.learner_preference._children': 'audio_level,language,delivery_speed,audio_captioning',
			'cmi.objectives._children': 'id,score,success_status,completion_status,progress_measure,description',
			'cmi.objectives.n.score._children': 'scaled,raw,min,max',
			'cmi.score._children': 'scaled,raw,min,max'
		},
		arrays: /^cmi\.(comments_from_learner|comments_from_lms|interactions|objectives|interactions\.\d+\.objectives|interactions\.\d+\.correct_responses)$/,
		writeOnly: /^cmi\.(exit|session_time)$/,
		readOnly: /^cmi\.(_version|completion_threshold|credit|entry|launch_data|learner_id|learner_name|max_time_allowed|mode|scaled_passing_score|time_limit_action|total_time|comments_from_lms\..*)$/,
		defaults: {
			'cmi.completion_status': 'unknown',
			'cmi.success_status': 'unknown',
			'cmi.location': '',
			'cmi.progress_measure': '',
			'cmi.score.scaled': '',
			'cmi.score.raw': '',
			'cmi.score.min': '',
			'cmi.score.max': '',
			'cmi.suspend_data': '',
			'cmi.learner_preference.audio_level': '1',
			'cmi.learner_preference.language': '',
			'cmi.learner_preference.delivery_speed': '1',
			'cmi.learner_preference.audio_captioning': '0'
		},
		vocab: {
			'cmi.completion_status': ['completed', 'incomplete', 'not attempted', 'unknown'],
			'cmi.success_status': ['passed', 'failed', 'unknown'],
			'cmi.exit': ['time-out', 'suspend', 'logout', 'normal', ''],
			'cmi.objectives.n.success_status': ['passed', 'failed', 'unknown'],
			'cmi.objectives.n.completion_status': ['completed', 'incomplete', 'not attempted', 'unknown'],
			'cmi.interactions.n.type': ['true-false', 'choice', 'fill-in', 'long-fill-in', 'matching', 'performance', 'sequencing', 'likert', 'numeric', 'other'],
			'cmi.interactions.n.result': ['correct', 'incorrect', 'unanticipated', 'neutral', /^-?\d+(\.\d+)?$/]
		},
		ranges: {
			'cmi.score.scaled': [-1, 1],
			'cmi.objectives.n.score.scaled': [-1, 1],
			'cmi.progress_measure': [0, 1],
			'cmi.objectives.n.progress_measure': [0, 1],
			'cmi.learner_preference.audio_level': [0, null],
			'cmi.learner_preference.delivery_speed': [0, null]
		},
		numeric: /^cmi\.(score\.(scaled|raw|min|max)|objectives\.\d+\.score\.(scaled|raw|min|max)|progress_measure|objectives\.\d+\.progress_measure|learner_preference\.(audio_level|delivery_speed)|interactions\.\d+\.weighting)$/,
		timespan: /^cmi\.session_time$/,
		timespanFormat: /^P(?=\w)(\d+(\.\d+)?Y)?(\d+(\.\d+)?M)?(\d+(\.\d+)?D)?(T(?=\d)(\d+(\.\d+)?H)?(\d+(\.\d+)?M)?(\d+(\.\d+)?S)?)?$/
	};

	function normalizeKey(element) {
		return element.replace(/\.\d+(?=\.|$)/g, '.n');
	}

	function inVocab(list, value) {
		for (var i = 0; i < list.length; i++) {
			if (list[i] instanceof RegExp ? list[i].test(value) : list[i] === value) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ *
	 * Runtime: one instance per SCO launch
	 * ------------------------------------------------------------------ */

	function Runtime(player, sco) {
		this.player = player;
		this.sco = sco;
		this.v12 = player.config.version === '1.2';
		this.model = this.v12 ? MODEL_12 : MODEL_2004;
		this.ro = sco.ro || {};
		this.data = {};
		this.state = 0; // 0 = not initialized, 1 = running, 2 = terminated.
		this.error = 0;
		this.dirty = false;
		this.newSession = true;
		this.navRequest = '_none_';

		var key;
		for (key in this.model.defaults) {
			this.data[key] = this.model.defaults[key];
		}
		for (key in sco.cmi || {}) {
			this.data[key] = String(sco.cmi[key]);
		}
	}

	Runtime.prototype.fail = function (code, result) {
		this.error = code;
		return result;
	};

	Runtime.prototype.ok = function (result) {
		this.error = 0;
		return result;
	};

	Runtime.prototype.initialize = function (param) {
		if (param !== '' && param !== undefined && param !== null) {
			return this.fail(201, 'false');
		}
		if (this.state === 1) {
			return this.fail(this.v12 ? 101 : 103, 'false');
		}
		if (this.state === 2) {
			return this.fail(this.v12 ? 101 : 104, 'false');
		}
		this.state = 1;
		if (this.v12 && this.data['cmi.core.lesson_status'] === 'not attempted') {
			this.data['cmi.core.lesson_status'] = 'incomplete';
			this.dirty = true;
		}
		this.player.onInitialize(this);
		return this.ok('true');
	};

	Runtime.prototype.terminate = function (param) {
		if (param !== '' && param !== undefined && param !== null) {
			return this.fail(201, 'false');
		}
		if (this.state === 0) {
			return this.fail(this.v12 ? 301 : 112, 'false');
		}
		if (this.state === 2) {
			return this.fail(this.v12 ? 101 : 113, 'false');
		}
		this.applyRules();
		this.state = 2;
		this.player.save(this, true);
		this.player.onTerminate(this);
		return this.ok('true');
	};

	Runtime.prototype.commit = function (param) {
		if (param !== '' && param !== undefined && param !== null) {
			return this.fail(201, 'false');
		}
		if (this.state === 0) {
			return this.fail(this.v12 ? 301 : 142, 'false');
		}
		if (this.state === 2) {
			return this.fail(this.v12 ? 101 : 143, 'false');
		}
		this.applyRules();
		this.player.save(this, false);
		return this.ok('true');
	};

	Runtime.prototype.count = function (base) {
		var prefix = base + '.';
		var max = -1;
		for (var key in this.data) {
			if (key.indexOf(prefix) === 0) {
				var index = parseInt(key.substring(prefix.length), 10);
				if (!isNaN(index) && index > max) {
					max = index;
				}
			}
		}
		return max + 1;
	};

	Runtime.prototype.getValue = function (element) {
		if (this.state === 0) {
			return this.fail(this.v12 ? 301 : 122, '');
		}
		if (this.state === 2) {
			return this.fail(this.v12 ? 101 : 123, '');
		}
		element = String(element === undefined || element === null ? '' : element);
		if (element === '') {
			return this.fail(this.v12 ? 201 : 301, '');
		}
		if (element === 'cmi._version') {
			return this.ok(this.model.version);
		}
		if (/\._children$/.test(element)) {
			var children = this.model.children[normalizeKey(element)];
			return children ? this.ok(children) : this.fail(this.v12 ? 202 : 301, '');
		}
		if (/\._count$/.test(element)) {
			var base = element.slice(0, -7);
			return this.model.arrays.test(base) ? this.ok(String(this.count(base))) : this.fail(this.v12 ? 203 : 301, '');
		}
		if (Object.prototype.hasOwnProperty.call(this.ro, element)) {
			return this.ok(String(this.ro[element]));
		}
		if (this.model.writeOnly.test(element)) {
			return this.fail(this.v12 ? 404 : 405, '');
		}
		if (!this.v12) {
			if (element.indexOf('adl.nav.request_valid.') === 0) {
				return this.ok(this.player.navValid(element.substring(22)));
			}
			if (element === 'adl.nav.request') {
				return this.ok(this.navRequest);
			}
			if (element === 'cmi.completion_status') {
				return this.ok(this.evaluateCompletion());
			}
			if (element === 'cmi.success_status') {
				return this.ok(this.evaluateSuccess());
			}
		}
		if (!/^cmi\./.test(element)) {
			return this.fail(this.v12 ? 401 : 401, '');
		}
		return this.ok(Object.prototype.hasOwnProperty.call(this.data, element) ? this.data[element] : '');
	};

	Runtime.prototype.setValue = function (element, value) {
		if (this.state === 0) {
			return this.fail(this.v12 ? 301 : 132, 'false');
		}
		if (this.state === 2) {
			return this.fail(this.v12 ? 101 : 133, 'false');
		}
		element = String(element === undefined || element === null ? '' : element);
		value = value === undefined || value === null ? '' : String(value);

		if (element === '') {
			return this.fail(this.v12 ? 201 : 351, 'false');
		}
		if (/\._(children|count|version)$/.test(element)) {
			return this.fail(this.v12 ? 402 : 404, 'false');
		}
		if (!this.v12 && element === 'adl.nav.request') {
			if (!/^(continue|previous|choice|jump|exit|exitAll|abandon|abandonAll|suspendAll|_none_|\{target=[^}]+\}(choice|jump))$/.test(value)) {
				return this.fail(406, 'false');
			}
			this.navRequest = value;
			return this.ok('true');
		}
		if (Object.prototype.hasOwnProperty.call(this.ro, element) || (this.model.readOnly && this.model.readOnly.test(element))) {
			return this.fail(this.v12 ? 403 : 404, 'false');
		}
		if (!/^cmi\.[A-Za-z0-9_.]+$/.test(element)) {
			return this.fail(this.v12 ? 201 : 401, 'false');
		}
		if (this.v12 && /^cmi\.core\.(student_id|student_name|credit|entry|total_time|lesson_mode)$|^cmi\.(launch_data|comments_from_lms|student_data\..*)$/.test(element)) {
			return this.fail(403, 'false');
		}

		// Array elements must be set in order: index n requires 0..n-1 to exist.
		var arrayMatch = element.match(/^(cmi\.(?:objectives|interactions|comments_from_learner))\.(\d+)\./);
		if (arrayMatch && parseInt(arrayMatch[2], 10) > this.count(arrayMatch[1])) {
			return this.fail(this.v12 ? 201 : 351, 'false');
		}

		var key = normalizeKey(element);
		var vocab = this.model.vocab[key];
		if (vocab && !inVocab(vocab, value)) {
			return this.fail(this.v12 ? 405 : 406, 'false');
		}
		if (this.model.numeric.test(element)) {
			if (value === '' && this.v12) {
				// SCORM 1.2 allows clearing scores.
			} else if (value === '' || isNaN(Number(value))) {
				return this.fail(this.v12 ? 405 : 406, 'false');
			} else if (!this.v12 && this.model.ranges[key]) {
				var range = this.model.ranges[key];
				var number = Number(value);
				if ((range[0] !== null && number < range[0]) || (range[1] !== null && number > range[1])) {
					return this.fail(407, 'false');
				}
			}
		}
		if (this.model.timespan.test(element) && !this.model.timespanFormat.test(value)) {
			return this.fail(this.v12 ? 405 : 406, 'false');
		}

		this.data[element] = value;
		this.dirty = true;
		return this.ok('true');
	};

	/** SCORM 2004: evaluate completion_status against completion_threshold. */
	Runtime.prototype.evaluateCompletion = function () {
		var threshold = this.ro['cmi.completion_threshold'];
		var progress = this.data['cmi.progress_measure'];
		if (threshold !== undefined && threshold !== '' && progress !== undefined && progress !== '') {
			return Number(progress) >= Number(threshold) ? 'completed' : 'incomplete';
		}
		return this.data['cmi.completion_status'] || 'unknown';
	};

	/** SCORM 2004: evaluate success_status against scaled_passing_score. */
	Runtime.prototype.evaluateSuccess = function () {
		var passing = this.ro['cmi.scaled_passing_score'];
		var scaled = this.data['cmi.score.scaled'];
		if (passing !== undefined && passing !== '' && scaled !== undefined && scaled !== '') {
			return Number(scaled) >= Number(passing) ? 'passed' : 'failed';
		}
		return this.data['cmi.success_status'] || 'unknown';
	};

	/** LMS-side status rules applied before data is persisted. */
	Runtime.prototype.applyRules = function () {
		if (this.v12) {
			var mastery = this.ro['cmi.student_data.mastery_score'];
			var raw = this.data['cmi.core.score.raw'];
			var status = this.data['cmi.core.lesson_status'];
			if (mastery !== undefined && mastery !== '' && raw !== '' && !isNaN(Number(raw)) && this.ro['cmi.core.credit'] === 'credit' && status !== 'passed' && status !== 'failed' && status !== 'incomplete' && status !== 'not attempted') {
				this.data['cmi.core.lesson_status'] = Number(raw) >= Number(mastery) ? 'passed' : 'failed';
			}
		} else {
			this.data['cmi.completion_status'] = this.evaluateCompletion();
			this.data['cmi.success_status'] = this.evaluateSuccess();
		}
	};

	Runtime.prototype.errorString = function (code) {
		var table = this.v12 ? ERRORS_12 : ERRORS_2004;
		code = parseInt(code, 10);
		return table[code] || '';
	};

	/* ------------------------------------------------------------------ *
	 * API facades exposed on window (stable objects delegating to the active runtime)
	 * ------------------------------------------------------------------ */

	function idle(v12) {
		return {
			initialize: function () { return 'false'; },
			terminate: function () { return 'false'; },
			commit: function () { return 'false'; },
			getValue: function () { return ''; },
			setValue: function () { return 'false'; },
			error: v12 ? 301 : 122,
			errorString: function () { return ''; }
		};
	}

	function installApis(player) {
		var current = function (v12) {
			return player.runtime || idle(v12);
		};

		window.API = {
			LMSInitialize: function (p) { return current(true).initialize(p); },
			LMSFinish: function (p) { return current(true).terminate(p); },
			LMSGetValue: function (e) { return current(true).getValue(e); },
			LMSSetValue: function (e, v) { return current(true).setValue(e, v); },
			LMSCommit: function (p) { return current(true).commit(p); },
			LMSGetLastError: function () { return String(current(true).error); },
			LMSGetErrorString: function (c) { return current(true).errorString(c); },
			LMSGetDiagnostic: function (c) { return current(true).errorString(c === '' || c === undefined ? current(true).error : c); }
		};

		window.API_1484_11 = {
			version: '1.0',
			Initialize: function (p) { return current(false).initialize(p); },
			Terminate: function (p) { return current(false).terminate(p); },
			GetValue: function (e) { return current(false).getValue(e); },
			SetValue: function (e, v) { return current(false).setValue(e, v); },
			Commit: function (p) { return current(false).commit(p); },
			GetLastError: function () { return String(current(false).error); },
			GetErrorString: function (c) { return current(false).errorString(c); },
			GetDiagnostic: function (c) { return current(false).errorString(c === '' || c === undefined ? current(false).error : c); }
		};
	}

	/* ------------------------------------------------------------------ *
	 * Player UI
	 * ------------------------------------------------------------------ */

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined) {
			node.textContent = text;
		}
		return node;
	}

	function Player(root, config) {
		this.root = root;
		this.config = config;
		this.scos = config.scos || [];
		this.index = -1;
		this.runtime = null;
		this.queue = Promise.resolve();
		this.popup = null;
		this.lessonCompleted = !!config.lessonCompleted;

		this.stage = root.querySelector('.tutor-scorm-stage');
		this.statusNode = root.querySelector('.tutor-scorm-status');
		this.navNode = root.querySelector('.tutor-scorm-nav');
		this.fullscreenBtn = root.querySelector('.tutor-scorm-fullscreen');

		installApis(this);
		this.buildNav();
		this.bindEvents();

		if (config.preview) {
			this.setStatus(L10N.preview, 'info');
		} else if (config.guest) {
			this.setStatus(L10N.guest, 'info');
		} else if (this.lessonCompleted) {
			this.setStatus(L10N.lessonComplete, 'success');
		}

		var start = Math.max(0, Math.min(this.scos.length - 1, config.start || 0));
		if (config.mode === 'popup') {
			this.renderPopupLauncher(start);
		} else {
			this.launch(start);
		}
	}

	Player.prototype.buildNav = function () {
		if (this.scos.length < 2) {
			return;
		}
		var self = this;
		this.prevBtn = el('button', 'tutor-scorm-btn', L10N.previous || 'Previous');
		this.prevBtn.type = 'button';
		this.select = el('select', 'tutor-scorm-select');
		this.nextBtn = el('button', 'tutor-scorm-btn', L10N.next || 'Next');
		this.nextBtn.type = 'button';

		this.scos.forEach(function (sco, i) {
			var option = el('option', '', new Array(sco.depth + 1).join('  ') + sco.title);
			option.value = String(i);
			self.select.appendChild(option);
		});
		this.refreshNavLabels();

		this.prevBtn.addEventListener('click', function () { self.go(self.index - 1); });
		this.nextBtn.addEventListener('click', function () { self.go(self.index + 1); });
		this.select.addEventListener('change', function () { self.go(parseInt(self.select.value, 10)); });

		this.navNode.appendChild(this.prevBtn);
		this.navNode.appendChild(this.select);
		this.navNode.appendChild(this.nextBtn);
	};

	Player.prototype.refreshNavLabels = function () {
		if (!this.select) {
			return;
		}
		var self = this;
		this.scos.forEach(function (sco, i) {
			var label = new Array(sco.depth + 1).join('  ') + (sco.satisfied ? '✓ ' : '') + sco.title;
			self.select.options[i].textContent = label;
		});
	};

	Player.prototype.bindEvents = function () {
		var self = this;

		window.addEventListener('pagehide', function () { self.unload(); });

		if (this.config.autosave > 0 && this.config.tracking) {
			window.setInterval(function () {
				if (self.runtime && self.runtime.state === 1 && self.runtime.dirty) {
					self.runtime.applyRules();
					self.save(self.runtime, false);
				}
			}, this.config.autosave * 1000);
		}

		var fsTarget = this.root;
		if (this.fullscreenBtn && (fsTarget.requestFullscreen || fsTarget.webkitRequestFullscreen) && this.config.mode !== 'popup') {
			this.fullscreenBtn.hidden = false;
			this.fullscreenBtn.addEventListener('click', function () {
				var active = document.fullscreenElement || document.webkitFullscreenElement;
				if (active) {
					(document.exitFullscreen || document.webkitExitFullscreen).call(document);
				} else {
					(fsTarget.requestFullscreen || fsTarget.webkitRequestFullscreen).call(fsTarget);
				}
			});
			var onChange = function () {
				var active = document.fullscreenElement || document.webkitFullscreenElement;
				self.root.classList.toggle('is-fullscreen', !!active);
				self.fullscreenBtn.textContent = active ? (L10N.exitFullscreen || 'Exit full screen') : (L10N.fullscreen || 'Full screen');
			};
			document.addEventListener('fullscreenchange', onChange);
			document.addEventListener('webkitfullscreenchange', onChange);
		}
	};

	/** Close the running session (if any) so its data and session time are stored. */
	Player.prototype.closeRuntime = function () {
		var runtime = this.runtime;
		if (runtime && runtime.state === 1) {
			runtime.applyRules();
			runtime.state = 2;
			this.save(runtime, true);
		}
	};

	/**
	 * Remove the content frame. Detaching an iframe runs its unload handlers synchronously,
	 * which gives the SCO the chance to call LMSFinish/Terminate with its final data.
	 */
	Player.prototype.unloadFrame = function () {
		var frame = this.stage.querySelector('iframe');
		if (frame) {
			frame.parentNode.removeChild(frame);
		}
	};

	Player.prototype.unload = function () {
		this.unloading = true;
		this.unloadFrame();
		this.closeRuntime();
		if (this.popup && !this.popup.closed) {
			try { this.popup.close(); } catch (e) { /* ignore */ }
		}
	};

	Player.prototype.go = function (index) {
		if (index < 0 || index >= this.scos.length || index === this.index) {
			if (this.select) {
				this.select.value = String(this.index);
			}
			return;
		}
		this.launch(index);
	};

	Player.prototype.launch = function (index) {
		if (this.config.mode !== 'popup') {
			this.unloadFrame();
		}
		this.closeRuntime();
		var sco = this.scos[index];
		this.index = index;
		this.runtime = new Runtime(this, sco);

		if (this.select) {
			this.select.value = String(index);
			this.prevBtn.disabled = index === 0;
			this.nextBtn.disabled = index === this.scos.length - 1;
		}

		if (this.config.mode === 'popup') {
			this.openPopup(sco);
		} else {
			this.stage.innerHTML = '';
			var frame = el('iframe', 'tutor-scorm-frame');
			frame.setAttribute('allowfullscreen', '');
			frame.setAttribute('allow', 'fullscreen; autoplay; encrypted-media; microphone; camera');
			frame.title = sco.title || this.config.title;
			frame.src = sco.url;
			this.stage.appendChild(frame);
		}

		if (sco.type === 'asset') {
			// Assets never call the API: record the visit straight away.
			this.save(this.runtime, true, true);
		}
	};

	Player.prototype.renderPopupLauncher = function (index) {
		var self = this;
		this.stage.innerHTML = '';
		var box = el('div', 'tutor-scorm-launcher');
		var button = el('button', 'tutor-scorm-btn tutor-scorm-btn-primary', L10N.launch || 'Launch course');
		button.type = 'button';
		button.addEventListener('click', function () { self.launch(self.index >= 0 ? self.index : index); });
		this.launcherMessage = el('p', 'tutor-scorm-launcher-message');
		box.appendChild(button);
		box.appendChild(this.launcherMessage);
		this.stage.appendChild(box);
		this.launcherButton = button;
		this.index = index;
		if (this.select) {
			this.select.value = String(index);
		}
	};

	Player.prototype.openPopup = function (sco) {
		var self = this;
		if (this.popup && !this.popup.closed) {
			this.popup.location.href = sco.url;
		} else {
			this.popup = window.open(sco.url, 'tutor_scorm_' + this.config.packageId, 'width=1280,height=800,resizable=yes,scrollbars=yes');
		}
		if (!this.popup) {
			this.launcherMessage.textContent = L10N.popupBlocked;
			return;
		}
		this.launcherMessage.textContent = L10N.popupOpen;
		this.launcherButton.textContent = L10N.relaunch || L10N.launch;
		window.clearInterval(this.popupWatch);
		this.popupWatch = window.setInterval(function () {
			if (self.popup && self.popup.closed) {
				window.clearInterval(self.popupWatch);
				self.closeRuntime();
				self.launcherMessage.textContent = '';
			}
		}, 1000);
		try { this.popup.focus(); } catch (e) { /* ignore */ }
	};

	Player.prototype.onInitialize = function (runtime) {
		if (runtime.sco.status === 'not attempted' || runtime.sco.status === 'unknown') {
			runtime.sco.status = 'incomplete';
		}
	};

	/** SCORM 2004 navigation requests issued before Terminate. */
	Player.prototype.onTerminate = function (runtime) {
		var request = runtime.navRequest;
		var self = this;
		if (!request || request === '_none_') {
			return;
		}
		var target = null;
		if (request === 'continue') {
			target = this.index + 1;
		} else if (request === 'previous') {
			target = this.index - 1;
		} else {
			var match = request.match(/^\{target=([^}]+)\}(choice|jump)$/);
			if (match) {
				this.scos.forEach(function (sco, i) {
					if (sco.id === match[1]) {
						target = i;
					}
				});
			}
		}
		if (target !== null && target >= 0 && target < this.scos.length) {
			// Let the SCO finish its own Terminate call before navigating away.
			window.setTimeout(function () { self.launch(target); }, 50);
		}
	};

	Player.prototype.navValid = function (what) {
		if (what === 'continue') {
			return this.index < this.scos.length - 1 ? 'true' : 'false';
		}
		if (what === 'previous') {
			return this.index > 0 ? 'true' : 'false';
		}
		var match = what.match(/^(choice|jump)\.\{target=([^}]+)\}$/);
		if (match) {
			for (var i = 0; i < this.scos.length; i++) {
				if (this.scos[i].id === match[2]) {
					return 'true';
				}
			}
			return 'false';
		}
		return 'unknown';
	};

	/**
	 * Persist runtime data.
	 *
	 * @param {Runtime} runtime
	 * @param {boolean} finish   Session is ending (session time is added to total time).
	 * @param {boolean} asset    Launch of a non-SCO asset.
	 */
	Player.prototype.save = function (runtime, finish, asset) {
		if (!this.config.tracking) {
			runtime.dirty = false;
			return;
		}
		var self = this;
		var payload = {
			package_id: this.config.packageId,
			lesson_id: this.config.lessonId,
			sco_id: runtime.sco.id,
			token: this.config.token,
			cmi: runtime.data,
			finish: !!finish,
			new_session: runtime.newSession,
			asset: !!asset
		};
		runtime.newSession = false;
		runtime.dirty = false;

		var body = JSON.stringify(payload);
		var send = function () {
			return fetch(self.config.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: body.length < 60000,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': self.config.nonce
				},
				body: body
			}).then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return response.json();
			}).then(function (result) {
				self.handleResult(runtime, result, finish);
			}).catch(function () {
				runtime.dirty = true;
				self.setStatus(L10N.saveError, 'error');
			});
		};

		if (this.unloading) {
			// The page is going away: send right now (keepalive lets the request outlive the page).
			send();
			return;
		}
		this.setStatus(L10N.saving, 'info');
		this.queue = this.queue.then(send, send);
	};

	Player.prototype.handleResult = function (runtime, result, finish) {
		var sco = runtime.sco;
		sco.status = result.status;
		sco.satisfied = !!result.satisfied;
		if (finish) {
			// Keep the stored state for a relaunch of this SCO in the same page view.
			var cmi = {};
			for (var key in runtime.data) {
				if (!/^cmi\.(core\.)?(session_time|exit)$/.test(key)) {
					cmi[key] = runtime.data[key];
				}
			}
			sco.cmi = cmi;
			var exitValue = runtime.data[this.config.version === '1.2' ? 'cmi.core.exit' : 'cmi.exit'];
			sco.ro[this.config.version === '1.2' ? 'cmi.core.entry' : 'cmi.entry'] = exitValue === 'suspend' ? 'resume' : '';
			if (result.total_time) {
				sco.ro[this.config.version === '1.2' ? 'cmi.core.total_time' : 'cmi.total_time'] = result.total_time;
			}
		}
		this.refreshNavLabels();

		if (result.lesson_completed) {
			this.setStatus(L10N.lessonComplete, 'success');
		} else {
			var label = (L10N.statuses && L10N.statuses[result.status]) || result.status;
			this.setStatus((L10N.saved || 'Saved') + ' · ' + label, 'info');
		}

		if (result.lesson_just_completed && !this.lessonCompleted) {
			this.lessonCompleted = true;
			this.root.dispatchEvent(new CustomEvent('tutor-scorm:lesson-completed', {
				bubbles: true,
				detail: { lessonId: this.config.lessonId, packageId: this.config.packageId }
			}));
			this.markTutorSidebar();
		}
	};

	/** Tick the lesson in Tutor's course sidebar, when present. */
	Player.prototype.markTutorSidebar = function () {
		var lessonId = this.config.lessonId;
		if (!lessonId) {
			return;
		}
		var selectors = [
			'[data-lesson-id="' + lessonId + '"] input[type="checkbox"]',
			'a[href*="lesson"][data-lesson-id="' + lessonId + '"] input[type="checkbox"]'
		];
		selectors.forEach(function (selector) {
			Array.prototype.forEach.call(document.querySelectorAll(selector), function (input) {
				input.checked = true;
			});
		});
	};

	Player.prototype.setStatus = function (message, type) {
		if (!this.statusNode || !message) {
			return;
		}
		this.statusNode.textContent = message;
		this.statusNode.setAttribute('data-type', type || 'info');
	};

	/* ------------------------------------------------------------------ *
	 * Boot
	 * ------------------------------------------------------------------ */

	function boot() {
		var nodes = document.querySelectorAll('.tutor-scorm-player[data-config]');
		if (!nodes.length) {
			return;
		}
		// The SCORM API is a single global object per window, so only one player can run per page.
		for (var i = 0; i < nodes.length; i++) {
			if (i > 0) {
				nodes[i].parentNode.removeChild(nodes[i]);
				continue;
			}
			try {
				var config = JSON.parse(nodes[i].getAttribute('data-config'));
				nodes[i].removeAttribute('data-config');
				window.TutorScormPlayer = new Player(nodes[i], config);
			} catch (e) {
				if (window.console) {
					window.console.error('Tutor SCORM:', e);
				}
			}
		}
	}

	// Exposed for automated tests.
	window.TutorScormRuntime = Runtime;

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
