/* Tutor SCORM admin: pick a ZIP from the Media Library. */
(function ($) {
	'use strict';

	$(function () {
		var frame;
		var l10n = window.TutorScormAdmin || {};

		$('#tutor-scorm-media').on('click', function (event) {
			event.preventDefault();
			if (!window.wp || !window.wp.media) {
				return;
			}
			if (!frame) {
				frame = window.wp.media({
					title: l10n.title || 'Select SCORM ZIP',
					button: { text: l10n.button || 'Use this file' },
					library: { type: 'application/zip' },
					multiple: false
				});
				frame.on('select', function () {
					var file = frame.state().get('selection').first().toJSON();
					$('#tutor-scorm-attachment').val(file.id);
					$('#tutor-scorm-attachment-name').text(file.filename || file.title);
					$('#tutor-scorm-zip').val('');
				});
			}
			frame.open();
		});

		$('#tutor-scorm-zip').on('change', function () {
			$('#tutor-scorm-attachment').val('');
			$('#tutor-scorm-attachment-name').text('');
		});
	});
})(jQuery);
