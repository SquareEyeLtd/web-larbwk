/**
 * The Flagship screen's sessions repeater and its "Add new speaker" rows
 * (functions/events/admin/flagship-screen.php).
 *
 * Deliberately not built on law-admin.js's own repeater, which clones
 * `input[data-law-name]` only and would leave a session's select, rich-text
 * description and nested speaker picker unnamed, and whose index is a row
 * count, so removing a middle row and adding another posts two rows into one
 * slot. This follows the front-end pattern in assets/js/event-form.js instead:
 * a monotonic counter on the wrapper, every [data-name] renamed, and TinyMCE
 * attached only after the row is in the document.
 */
(function () {
	'use strict';

	var wrap = document.querySelector('[data-law-flagship-sessions]');
	if (!wrap) {
		return;
	}

	var addButton = document.querySelector('.law-row-add');

	/* The next session index. Monotonic, and seeded from the highest index
	   already on the page rather than a row count, because a form re-rendered
	   after a validation error can have non-contiguous indexes. */
	function nextIndex() {
		var index = parseInt(wrap.getAttribute('data-law-counter') || '', 10);
		if (isNaN(index)) {
			index = 0;
			wrap.querySelectorAll('[data-law-row]:not([data-law-row-template]) [name]').forEach(function (field) {
				var match = field.name.match(/\[sessions\]\[(\d+)\]/);
				if (match) {
					index = Math.max(index, parseInt(match[1], 10) + 1);
				}
			});
		}
		wrap.setAttribute('data-law-counter', String(index + 1));
		return index;
	}

	function addSession() {
		var template = wrap.querySelector('[data-law-row-template]');
		if (!template) {
			return;
		}
		var row = template.cloneNode(true);
		row.removeAttribute('data-law-row-template');
		row.hidden = false;

		var index = nextIndex();

		row.querySelectorAll('[data-name]').forEach(function (field) {
			field.name = field.getAttribute('data-name').replace('__i__', String(index));
			field.removeAttribute('data-name');
			if (field.type === 'checkbox' || field.type === 'radio') {
				field.checked = false;
			} else if (field.tagName === 'SELECT') {
				field.selectedIndex = 0;
			} else if (field.type !== 'file') {
				field.value = '';
			}
		});

		/* The speaker picker inside the row posts under the session's own index,
		   so its base name carries the placeholder too. */
		row.querySelectorAll('[data-law-rel-name]').forEach(function (picker) {
			picker.setAttribute(
				'data-law-rel-name',
				picker.getAttribute('data-law-rel-name').replace('__i__', String(index))
			);
		});

		/* Defensive: a clone must never inherit an "already bound" marker, or the
		   new row's picker and photo field would get no handlers at all. */
		row.querySelectorAll('[data-law-rel-ready]').forEach(function (el) {
			el.removeAttribute('data-law-rel-ready');
		});
		row.querySelectorAll('[data-law-media-ready]').forEach(function (el) {
			el.removeAttribute('data-law-media-ready');
		});

		/* The template's textareas carry the template's ids, and an id has to be
		   unique before an editor can attach to it. */
		row.querySelectorAll('textarea[data-law-rich]').forEach(function (field) {
			field.removeAttribute('id');
		});

		wrap.insertBefore(row, template);

		/* After the insert, never before it: both TinyMCE and wp.media measure
		   and replace elements that have to already be in the document. */
		if (window.lawRichText) {
			window.lawRichText.initAll(row);
		}
		if (window.lawAdminFields) {
			window.lawAdminFields.initAll(row);
		}

		var first = row.querySelector('input[type="text"]');
		if (first) {
			first.focus();
		}
	}

	if (addButton) {
		addButton.addEventListener('click', addSession);
	}

	/* One delegated listener, so it also covers rows added after load. */
	wrap.addEventListener('click', function (event) {
		var remove = event.target.closest('.law-row-remove');
		if (remove) {
			var doomed = remove.closest('[data-law-row]');
			if (!doomed) {
				return;
			}
			/* Detach every editor in the row before the DOM goes, or TinyMCE is
			   left holding instances whose elements no longer exist. */
			if (window.lawRichText) {
				doomed.querySelectorAll('textarea[data-law-rich]').forEach(window.lawRichText.remove);
			}
			doomed.remove();
			return;
		}

		var addNew = event.target.closest('.law-rel-add-new');
		if (!addNew) {
			return;
		}

		var picker = addNew.closest('[data-law-rel]');
		var source = document.getElementById('law-flagship-new-speaker');
		if (!picker || !source) {
			return;
		}
		var chosen = picker.querySelector('[data-law-rel-chosen]');
		if (!chosen) {
			return;
		}

		var base = picker.getAttribute('data-law-rel-name');
		/* Same index scheme as law-admin.js's own picker rows: the count plus a
		   clock component, so a removed row's index is never handed out twice
		   within one session of editing. */
		var j = chosen.querySelectorAll('.law-rel-item').length + (Date.now() % 1000);

		var html = source.innerHTML.split('__name__').join(base).split('__j__').join(String(j));
		var holder = document.createElement('div');
		holder.innerHTML = html;

		var item = holder.querySelector('.law-rel-item');
		if (!item) {
			return;
		}
		item.querySelectorAll('textarea[data-law-rich]').forEach(function (field) {
			field.removeAttribute('id');
		});

		chosen.appendChild(item);

		if (window.lawRichText) {
			window.lawRichText.initAll(item);
		}

		var firstName = item.querySelector('input[type="text"]');
		if (firstName) {
			firstName.focus();
		}
	});
})();
