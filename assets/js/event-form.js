/**
 * Front-end behaviours for the event form and dashboards: repeatable rows,
 * the sponsor/invoice toggle and smooth section navigation.
 */
(function () {
	'use strict';

	/* Repeaters: clone the hidden template row, renaming data-name → name.
	   Indexes come from a monotonic per-group counter (never the row count:
	   removing a middle row and re-adding would otherwise collide indexes
	   and silently drop a row's values). */
	document.querySelectorAll('.law-row-add').forEach(function (button) {
		button.addEventListener('click', function () {
			var group = button.getAttribute('data-law-add');
			var wrap = document.querySelector('[data-law-rows-group="' + group + '"]');
			if (!wrap) { return; }
			var template = wrap.querySelector('[data-law-row-template]');
			var row = template.cloneNode(true);
			row.removeAttribute('data-law-row-template');
			row.hidden = false;
			var index = parseInt(wrap.getAttribute('data-law-counter') || '', 10);
			if (isNaN(index)) {
				index = 0;
				wrap.querySelectorAll('.law-row:not([data-law-row-template]) [name]').forEach(function (field) {
					var match = field.name.match(/\[(\d+)\]/);
					if (match) { index = Math.max(index, parseInt(match[1], 10) + 1); }
				});
			}
			wrap.setAttribute('data-law-counter', String(index + 1));
			row.querySelectorAll('[data-name]').forEach(function (field) {
				field.name = field.getAttribute('data-name').replace('__i__', String(index));
				field.removeAttribute('data-name');
				if (field.tagName === 'TEXTAREA') { field.value = ''; } else if (field.type !== 'file') { field.value = ''; }
			});
			wrap.insertBefore(row, template);
			var first = row.querySelector('input, textarea');
			if (first) { first.focus(); }
		});
	});

	document.addEventListener('click', function (event) {
		if (event.target.classList && event.target.classList.contains('law-row-remove')) {
			event.target.closest('.law-row').remove();
		}
	});

	/* Sponsor tier hides the invoice fields. */
	var invoice = document.querySelector('[data-law-invoice]');
	if (invoice) {
		document.querySelectorAll('input[name="fee_tier"]').forEach(function (radio) {
			radio.addEventListener('change', function () {
				invoice.hidden = radio.value === 'sponsor' && radio.checked;
			});
		});
	}

	/* Section nav: smooth scroll + highlight. */
	document.querySelectorAll('.law-form-nav a').forEach(function (link) {
		link.addEventListener('click', function (event) {
			var target = document.querySelector(link.getAttribute('href'));
			if (target) {
				event.preventDefault();
				target.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});
	});
})();
