/**
 * Admin behaviours for the events module meta boxes: repeaters, the
 * relationship picker (AJAX search) and the media-library photo field.
 */
(function () {
	'use strict';

	/* Repeaters: clone the hidden template row. */
	document.querySelectorAll('[data-law-repeater]').forEach(function (repeater) {
		var body = repeater.querySelector('[data-law-rows]');
		var addButton = repeater.querySelector('.law-row-add');

		function nextIndex() {
			return body.querySelectorAll('tr:not([data-law-template])').length;
		}

		addButton.addEventListener('click', function () {
			var template = body.querySelector('[data-law-template]');
			var row = template.cloneNode(true);
			row.removeAttribute('data-law-template');
			row.style.display = '';
			var i = nextIndex();
			row.querySelectorAll('input[data-law-name]').forEach(function (input) {
				input.name = input.getAttribute('data-law-name').replace('__i__', String(i));
				input.removeAttribute('data-law-name');
				input.value = '';
			});
			body.insertBefore(row, template);
		});

		repeater.addEventListener('click', function (event) {
			if (event.target.classList.contains('law-row-remove')) {
				event.target.closest('tr').remove();
			}
		});
	});

	/* Relationship pickers. */
	document.querySelectorAll('[data-law-rel]').forEach(function (picker) {
		var search = picker.querySelector('.law-rel-search');
		var results = picker.querySelector('.law-rel-results');
		var chosen = picker.querySelector('[data-law-rel-chosen]');
		var name = picker.getAttribute('data-law-rel-name');
		var type = picker.getAttribute('data-law-rel-type');
		var simple = picker.getAttribute('data-law-rel-simple') === '1';
		var timer = null;

		function rowIndex() {
			return chosen.querySelectorAll('.law-rel-item').length + Date.now() % 1000;
		}

		function addItem(id, title) {
			var li = document.createElement('li');
			li.className = 'law-rel-item';
			var i = rowIndex();
			var fields = simple
				? '<input type="hidden" name="' + name + '[]" value="' + id + '">'
				: '<input type="hidden" name="' + name + '[' + i + '][speaker_id]" value="' + id + '">' +
					'<input type="text" name="' + name + '[' + i + '][role]" value="" placeholder="Role (Speaker / Moderator / Host)" class="law-rel-role">' +
					'<input type="text" name="' + name + '[' + i + '][organisation_override]" value="" placeholder="Organisation override" class="law-rel-org">';
			li.innerHTML = '<span class="law-rel-title"></span>' + fields +
				'<button type="button" class="button-link-delete law-rel-remove" aria-label="Remove">×</button>';
			li.querySelector('.law-rel-title').textContent = title;
			chosen.appendChild(li);
		}

		search.addEventListener('input', function () {
			clearTimeout(timer);
			var q = search.value.trim();
			if (q.length < 2) { results.hidden = true; return; }
			timer = setTimeout(function () {
				var url = lawEventsAdmin.ajaxUrl + '?action=law_events_search_posts&post_type=' + encodeURIComponent(type) +
					'&q=' + encodeURIComponent(q) + '&_ajax_nonce=' + lawEventsAdmin.nonce;
				fetch(url).then(function (r) { return r.json(); }).then(function (data) {
					results.innerHTML = '';
					(data.data || []).forEach(function (item) {
						var li = document.createElement('li');
						var button = document.createElement('button');
						button.type = 'button';
						button.className = 'button-link';
						button.textContent = item.title + ' (#' + item.id + ')';
						button.addEventListener('click', function () {
							addItem(item.id, item.title);
							results.hidden = true;
							search.value = '';
						});
						li.appendChild(button);
						results.appendChild(li);
					});
					results.hidden = results.children.length === 0;
				});
			}, 250);
		});

		picker.addEventListener('click', function (event) {
			if (event.target.classList.contains('law-rel-remove')) {
				event.target.closest('.law-rel-item').remove();
			}
		});
	});

	/* Media picker. */
	document.querySelectorAll('[data-law-media]').forEach(function (field) {
		var input = field.querySelector('input[type=hidden]');
		var preview = field.querySelector('.law-media-preview');
		var removeButton = field.querySelector('.law-media-remove');
		var frame = null;

		field.querySelector('.law-media-choose').addEventListener('click', function () {
			frame = frame || wp.media({ title: 'Choose photo', multiple: false, library: { type: 'image' } });
			frame.off('select').on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				input.value = attachment.id;
				var url = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
				preview.innerHTML = '<img src="' + url + '" style="max-width:80px;height:auto">';
				removeButton.style.display = '';
			});
			frame.open();
		});

		removeButton.addEventListener('click', function () {
			input.value = '0';
			preview.innerHTML = '';
			removeButton.style.display = 'none';
		});
	});
})();
