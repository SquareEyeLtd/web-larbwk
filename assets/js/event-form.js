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

	/* Conditional fields: a checkbox with data-law-toggles shows/hides the
	   element with that id (the "Other: please specify" inputs). */
	document.querySelectorAll('[data-law-toggles]').forEach(function (box) {
		var target = document.getElementById(box.getAttribute('data-law-toggles'));
		if (!target) { return; }
		var sync = function () {
			target.hidden = !box.checked;
			if (target.hidden) {
				var field = target.querySelector('input');
				if (field) { field.value = ''; }
			}
		};
		box.addEventListener('change', sync);
	});

	/* Password strength, WordPress-style (wp.passwordStrength / zxcvbn). */
	var passField = document.querySelector('[data-law-strength]');
	var confirmField = document.querySelector('[data-law-strength-confirm]');
	var strengthOutput = document.querySelector('[data-law-strength-output]');
	if (passField && strengthOutput && window.wp && wp.passwordStrength) {
		// WP core's own score mapping: 0/1 short, 2 bad, 3 good, 4 strong,
		// and 5 = the two fields DO NOT MATCH (returned by the meter when the
		// confirm field is non-empty and different). Texts come from core's
		// pwsL10n so the wording matches WordPress exactly.
		var l10n = window.pwsL10n || {};
		var labels = {
			0: [l10n.short || 'Very weak', 'is-short'],
			1: [l10n.short || 'Very weak', 'is-short'],
			2: [l10n.bad || 'Weak', 'is-bad'],
			3: [l10n.good || 'Medium', 'is-good'],
			4: [l10n.strong || 'Strong', 'is-strong'],
			5: [l10n.mismatch || 'Mismatch', 'is-mismatch']
		};
		var update = function () {
			var pass = passField.value;
			var confirm = confirmField ? confirmField.value : '';
			if (!pass && !confirm) { strengthOutput.hidden = true; return; }
			var disallowed = wp.passwordStrength.userInputDisallowedList
				? wp.passwordStrength.userInputDisallowedList()
				: [];
			var score = wp.passwordStrength.meter(pass, disallowed, confirm);
			var result = labels[score] || labels[0];
			strengthOutput.hidden = false;
			strengthOutput.textContent = result[0];
			strengthOutput.className = 'law-pass-strength ' + result[1];
		};
		passField.addEventListener('input', update);
		if (confirmField) { confirmField.addEventListener('input', update); }
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
