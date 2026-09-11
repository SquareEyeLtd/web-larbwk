/**
 * Admin behaviours for the events module meta boxes: repeaters, the
 * relationship picker (AJAX search) and the media-library photo field.
 *
 * The relationship and media bindings are exposed as
 * window.lawAdminFields.initAll( root ), because the Flagship screen
 * (assets/js/law-flagship-admin.js) adds session rows AFTER load, each with a
 * speaker picker and a photo field inside it. A picker that is only bound at
 * DOM ready would leave a cloned row with a dead search box.
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

	/* Relationship pickers. Idempotent and re-callable: a picker is bound once,
	   and one sitting inside a clone template is skipped entirely, because the
	   clone would inherit the "already bound" marker and never get handlers. */
	function initRel(picker) {
		if (picker.hasAttribute('data-law-rel-ready') || picker.closest('[data-law-row-template]')) {
			return;
		}
		picker.setAttribute('data-law-rel-ready', '1');

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

		/* The Role select, same options as law_field_relationship_row() in
		   fields.php (localised as roleChoices so the two cannot drift). A new
		   row starts on the blank "Select role" placeholder: there is no
		   default role (Denis, 9 September 2026). */
		function roleSelect(fieldName) {
			var choices = (window.lawEventsAdmin && lawEventsAdmin.roleChoices) || { speaker: 'Speaker' };
			var select = document.createElement('select');
			select.name = fieldName;
			select.className = 'law-rel-role';
			select.setAttribute('aria-label', 'Role at this event');
			var placeholder = document.createElement('option');
			placeholder.value = '';
			placeholder.textContent = 'Select role';
			select.appendChild(placeholder);
			Object.keys(choices).forEach(function (key) {
				var option = document.createElement('option');
				option.value = key;
				option.textContent = choices[key];
				select.appendChild(option);
			});
			return select.outerHTML;
		}

		/* Add a chosen row. `item` is the search result, which for a speaker
		   carries the prefill the endpoint worked out (role, organisation, job
		   title, photo and biography from that speaker's latest appearance, falling
		   back to their profile) so the committee edits values rather than typing
		   them again. Every field stays editable: what is saved is still the
		   appearance at THIS event or session. Values are assigned as properties
		   after the markup is built, never interpolated into the HTML string: the
		   biography is rich text and an organisation may legitimately contain a
		   quote or an ampersand. */
		function addItem(item) {
			var li = document.createElement('li');
			li.className = 'law-rel-item';
			var i = rowIndex();
			var fields = simple
				? '<input type="hidden" name="' + name + '[]" value="' + item.id + '">'
				: '<input type="hidden" name="' + name + '[' + i + '][speaker_id]" value="' + item.id + '">' +
					roleSelect(name + '[' + i + '][role]') +
					'<input type="text" name="' + name + '[' + i + '][organisation]" value="" placeholder="Organisation at this event" class="law-rel-org">' +
					'<input type="text" name="' + name + '[' + i + '][job_title]" value="" placeholder="Job title at this event" class="law-rel-job">' +
					// Same markup as law_field_relationship_photo() in fields.php.
					'<span class="law-rel-photo" data-law-rel-photo>' +
						'<input type="hidden" name="' + name + '[' + i + '][photo_id]" value="0" class="law-rel-photo-id">' +
						'<img class="law-rel-photo-thumb" src="" alt="" width="32" height="32" hidden>' +
						'<button type="button" class="button-link law-rel-photo-choose">Choose photo</button>' +
						'<button type="button" class="button-link law-rel-photo-clear" hidden>Remove photo</button>' +
					'</span>' +
					// Same markup as law_rich_text_field() in functions/events/rich-text.php.
					'<span class="law-rich-text"><textarea name="' + name + '[' + i + '][bio]" rows="3" ' +
						'aria-label="Biography for this event" class="law-rich-text__area law-rel-bio" data-law-rich></textarea></span>';
			li.innerHTML = '<span class="law-rel-title"></span>' + fields +
				'<button type="button" class="button-link-delete law-rel-remove" aria-label="Remove">×</button>';
			li.querySelector('.law-rel-title').textContent = item.title;
			if (!simple) {
				prefill(li, item);
			}
			chosen.appendChild(li);
			// After the append, never before it: TinyMCE measures and replaces an
			// element that has to already be in the document. The biography is set
			// on the textarea first, so the editor opens on it.
			if (window.lawRichText) { window.lawRichText.initAll(li); }
		}

		/* The chosen speaker's known values, written into the new row. */
		function prefill(li, item) {
			var role = li.querySelector('.law-rel-role');
			if (role && item.role) { role.value = item.role; }
			var org = li.querySelector('.law-rel-org');
			if (org) { org.value = item.organisation || ''; }
			var job = li.querySelector('.law-rel-job');
			if (job) { job.value = item.job_title || ''; }
			var bio = li.querySelector('.law-rel-bio');
			if (bio) { bio.value = item.bio || ''; }
			var photo = li.querySelector('[data-law-rel-photo]');
			if (photo && item.photo_id) { setPhoto(photo, item.photo_id, item.photo || ''); }
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
						// The name on its own line, with the job title and
						// organisation under it in small type. The post ID used to
						// be shown beside the name, which told a committee member
						// nothing about which of two similar names they were
						// picking (Denis, 11 September 2026).
						var name = document.createElement('span');
						name.className = 'law-rel-result-name';
						name.textContent = item.title;
						button.appendChild(name);
						var meta = [item.job_title, item.organisation].filter(function (part) {
							return part && String(part).trim() !== '';
						}).join(', ');
						if (meta) {
							var line = document.createElement('span');
							line.className = 'law-rel-result-meta';
							line.textContent = meta;
							button.appendChild(line);
						}
						button.addEventListener('click', function () {
							addItem(item);
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

		/* The per-appearance photo: a wp.media frame writes the attachment ID
		   into the row's hidden input (the speaker post's featured image is
		   only a fallback, so this is the photo the event actually shows). */
		function setPhoto(control, id, url) {
			control.querySelector('.law-rel-photo-id').value = id || 0;
			var thumb = control.querySelector('.law-rel-photo-thumb');
			thumb.src = url || '';
			thumb.hidden = !url;
			control.querySelector('.law-rel-photo-choose').textContent = url ? 'Change photo' : 'Choose photo';
			control.querySelector('.law-rel-photo-clear').hidden = !url;
		}

		picker.addEventListener('click', function (event) {
			if (event.target.classList.contains('law-rel-remove')) {
				var doomed = event.target.closest('.law-rel-item');
				// Detach the biography's editor before the row goes, or TinyMCE is
				// left holding an instance whose element no longer exists.
				if (window.lawRichText) {
					doomed.querySelectorAll('textarea[data-law-rich]').forEach(window.lawRichText.remove);
				}
				doomed.remove();
				return;
			}
			var control = event.target.closest('[data-law-rel-photo]');
			if (!control) {
				return;
			}
			if (event.target.classList.contains('law-rel-photo-clear')) {
				setPhoto(control, 0, '');
				return;
			}
			if (event.target.classList.contains('law-rel-photo-choose') && window.wp && wp.media) {
				var frame = wp.media({
					title: 'Speaker photo for this event',
					button: { text: 'Use this photo' },
					library: { type: 'image' },
					multiple: false
				});
				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					var sizes = attachment.sizes || {};
					var url = (sizes.thumbnail && sizes.thumbnail.url) || attachment.url;
					setPhoto(control, attachment.id, url);
				});
				frame.open();
			}
		});
	}

	/* Test mode: reveal the address field and validate it over AJAX. */
	(function () {
		var form = document.querySelector('[data-law-test-mode]');
		if (!form) {
			return;
		}

		var toggle = form.querySelector('[data-law-test-toggle]');
		var fields = form.querySelector('[data-law-test-fields]');
		var input = form.querySelector('[data-law-test-email]');
		var status = form.querySelector('[data-law-test-status]');
		var request = 0;

		toggle.addEventListener('change', function () {
			fields.hidden = !toggle.checked;
			if (toggle.checked) {
				input.focus();
				check();
			}
		});

		function show(level, message) {
			status.className = 'law-test-mode__status is-' + level;
			status.textContent = message;
		}

		function check() {
			var value = input.value.trim();
			if (!value) {
				show('idle', '');
				return;
			}
			var id = ++request;
			show('idle', 'Checking…');
			var url = lawEventsAdmin.ajaxUrl +
				'?action=law_events_check_email' +
				'&_wpnonce=' + encodeURIComponent(lawEventsAdmin.nonce) +
				'&email=' + encodeURIComponent(value);

			fetch(url, { credentials: 'same-origin' })
				.then(function (response) { return response.json(); })
				.then(function (payload) {
					// Ignore answers to superseded keystrokes.
					if (id !== request || !payload || !payload.success) {
						return;
					}
					show(payload.data.level, payload.data.message);
				})
				.catch(function () {
					if (id === request) {
						show('warning', 'Could not check the address just now.');
					}
				});
		}

		var timer = null;
		input.addEventListener('input', function () {
			window.clearTimeout(timer);
			timer = window.setTimeout(check, 400);
		});
		input.addEventListener('blur', check);

		if (toggle.checked && input.value) {
			check();
		}
	})();

	/* Media picker. Same idempotent, re-callable shape as initRel(). */
	function initMedia(field) {
		if (field.hasAttribute('data-law-media-ready') || field.closest('[data-law-row-template]')) {
			return;
		}
		field.setAttribute('data-law-media-ready', '1');

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
	}

	/* Bind everything on this screen, then expose the same entry point so a
	   script that inserts markup later can bind just its new subtree. */
	function initAll(root) {
		var scope = root || document;
		scope.querySelectorAll('[data-law-rel]').forEach(initRel);
		scope.querySelectorAll('[data-law-media]').forEach(initMedia);
	}

	initAll(document);

	window.lawAdminFields = { initRel: initRel, initMedia: initMedia, initAll: initAll };
})();
