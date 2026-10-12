/* Retícula Académica: ordered course list on the term edit screen. */
(function ($) {
	'use strict';
	$(function () {
		var $list = $('#ra-course-list');
		var $empty = $('.ra-empty');
		var l10n = window.RAAdmin || {};
		if (!$list.length) {
			return;
		}
		$list.sortable({ handle: '.ra-handle', axis: 'y', placeholder: 'ra-course-placeholder' });

		function refresh() {
			$empty.prop('hidden', $list.children().length > 0);
		}

		$('#ra-add-course-btn').on('click', function () {
			var $select = $('#ra-add-course');
			var id = $select.val();
			if (!id || id === '0' || $list.find('[data-id="' + id + '"]').length) {
				return;
			}
			var title = $select.find('option:selected').text();
			var $item = $('<li class="ra-course-item"/>').attr('data-id', id)
				.append('<span class="dashicons dashicons-menu ra-handle" aria-hidden="true"></span>')
				.append($('<span class="ra-course-title"/>').text(title))
				.append($('<input type="hidden" name="ra_courses[]"/>').val(id))
				.append($('<button type="button" class="button-link ra-remove"/>').attr('aria-label', l10n.remove || 'Remove').html('<span class="dashicons dashicons-no-alt"></span>'));
			$list.append($item);
			$select.val('0');
			refresh();
		});

		$list.on('click', '.ra-remove', function () {
			$(this).closest('li').remove();
			refresh();
		});
	});
})(jQuery);
