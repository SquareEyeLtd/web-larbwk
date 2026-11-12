/**
 * Show and hide parts of a form from a checkbox or radio, site-wide.
 *
 * One delegated implementation for what used to be bound element by element
 * in event-form.js (and inline on the profile page): bound once at page load,
 * it never reached a dialog fetched afterwards, so the "Other" boxes did not
 * open inside the booking dialogs (BOOK_ON_BEHALF.md §3.10).
 *
 *   data-law-toggles="<id>"     on a checkbox or radio: the element with that
 *                               id shows while it is checked.
 *   data-law-toggle-disable     on that target: while hidden, every control in
 *                               it is DISABLED (so it neither posts nor blocks
 *                               the browser's own required check) and keeps
 *                               what was typed, for when it is shown again.
 *                               Without it, hiding clears the first input, as
 *                               it always has, so an answer that no longer
 *                               applies is not saved.
 *   data-law-toggle-for="<id>"  the same from the other end: this element
 *                               shows while the checkbox with that id is
 *                               checked. Hide only, never cleared: the
 *                               committee's override amount must keep posting
 *                               while hidden.
 *
 * window.lawToggles.sync( root ) brings everything under root into step with
 * its controls; booking-form.js calls it on a dialog it has just injected.
 */
(function () {
	'use strict';

	function controls(target) {
		return target.querySelectorAll('input, select, textarea, button');
	}

	/* Does a block hold something the server put there on purpose: a typed
	   value, or a refusal marked against it? */
	function holdsSomething(target) {
		if (target.querySelector('.is-invalid, .law-form-error')) { return true; }
		return Array.prototype.some.call(
			target.querySelectorAll('input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]), textarea, select'),
			function (field) { return field.value !== ''; }
		);
	}

	/* Show or hide one target. `changed` is true when somebody has just moved
	   the control, false on a sync (page load, an injected dialog, a reset).
	   Clearing happens only on a change, never on a sync, and a sync never
	   hides a block that holds a value or an error: the server shows a
	   "please specify" box with a stored answer even when its tick is off,
	   because that answer is still saved, and hiding it would leave it
	   posting unseen. */
	function apply(box, changed) {
		var target = document.getElementById(box.getAttribute('data-law-toggles'));
		if (!target) { return; }
		var show = box.checked;
		if (target.hasAttribute('data-law-toggle-disable')) {
			target.hidden = !show;
			Array.prototype.forEach.call(controls(target), function (control) {
				control.disabled = !show;
			});
			return;
		}
		if (!show && !changed && holdsSomething(target)) { return; }
		target.hidden = !show;
		if (!show && changed) {
			var field = target.querySelector('input');
			if (field) { field.value = ''; }
		}
	}

	/* A radio fires 'change' only when it becomes checked, never when a
	   sibling takes the selection, so every toggling radio in the group is
	   re-applied. Looked for in the same form only: two dialogs on one page
	   can share a group name. */
	function group(radio) {
		if (!radio.name) { return [radio]; }
		var scope = radio.form || document;
		return Array.prototype.filter.call(
			scope.querySelectorAll('input[type="radio"][data-law-toggles]'),
			function (other) { return other.name === radio.name; }
		);
	}

	function applyFor(target) {
		var box = document.getElementById(target.getAttribute('data-law-toggle-for'));
		if (box) { target.hidden = !box.checked; }
	}

	function sync(root) {
		root = root || document;
		Array.prototype.forEach.call(root.querySelectorAll('[data-law-toggles]'), function (box) {
			apply(box, false);
		});
		Array.prototype.forEach.call(root.querySelectorAll('[data-law-toggle-for]'), applyFor);
	}

	document.addEventListener('change', function (event) {
		var control = event.target;
		if (!(control instanceof HTMLInputElement)) { return; }
		if (control.type === 'radio') {
			group(control).forEach(function (box) { apply(box, true); });
		} else if (control.hasAttribute('data-law-toggles')) {
			apply(control, true);
		}
		if (control.id) {
			Array.prototype.forEach.call(
				document.querySelectorAll('[data-law-toggle-for="' + control.id + '"]'),
				applyFor
			);
		}
	});

	/* A form reset puts the controls back but fires no change, which left an
	   "Other" box open over an unticked choice. The reset lands after the
	   event, hence the deferral. */
	document.addEventListener('reset', function (event) {
		var form = event.target;
		setTimeout(function () { sync(form); }, 0);
	}, true);

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { sync(document); });
	} else {
		sync(document);
	}

	window.lawToggles = { sync: sync };
})();
