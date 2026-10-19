/* Retícula Académica: ordered course list on the term edit screen. */
(function ($) {
	'use strict';
	$(function () {
		// Image pickers (logo, signatures, letterhead).
		$(document).on('click', '.ra-pick-image', function (event) {
			event.preventDefault();
			var $box = $(this).closest('.ra-image-field');
			if (!window.wp || !window.wp.media) {
				return;
			}
			var frame = window.wp.media({ title: (window.RAAdmin || {}).choose || 'Choose image', library: { type: 'image' }, multiple: false });
			frame.on('select', function () {
				var file = frame.state().get('selection').first().toJSON();
				$box.find('input[type=hidden]').val(file.id);
				$box.find('img').attr('src', (file.sizes && file.sizes.medium ? file.sizes.medium.url : file.url)).show();
			});
			frame.open();
		});
		$(document).on('click', '.ra-clear-image', function (event) {
			event.preventDefault();
			var $box = $(this).closest('.ra-image-field');
			$box.find('input[type=hidden]').val('0');
			$box.find('img').attr('src', '').hide();
		});

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
