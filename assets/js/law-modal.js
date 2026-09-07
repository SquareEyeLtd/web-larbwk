/**
 * Reusable confirmation modal (parts/layout/modal.php, assets/css/law-modal.css).
 *
 * Progressive enhancement, like the calendar filter bar: without JS the modals
 * stay hidden, any inline fallback field the page provides stays visible, and
 * the plain submit buttons do the job on their own. With JS, every
 * [data-law-modal-fallback] block is hidden and its controls disabled, each
 * [data-law-modal-open] button becomes an opener for the modal it names, and
 * only the field in the open modal is enabled, so a normal browser posts one
 * value per field name.
 *
 * That last part is a UX guarantee, not a security one: these dialogs sit on
 * committee-only endpoints and carry text the user may write freely, so a
 * hand-made POST with two values only picks which of their own drafts wins.
 *
 * The modals render inside their caller's form, so everything else on that
 * form still posts with the action.
 *
 * Two hooks for scripts that submit a modal's action over fetch (see
 * assets/js/committee-actions.js): window.lawModal.open(id) / .close() open
 * and close a dialog programmatically (open switches dialogs, closing any
 * current one first), and a modal carrying the law-modal--busy class is
 * mid-request, so Escape and the close controls are ignored until the
 * caller removes it.
 */
(function () {
	'use strict';

	var openers = document.querySelectorAll('[data-law-modal-open]');
	if (!openers.length) { return; }

	var open = null;    // The modal currently on screen.
	var opener = null;  // The button that opened it, for focus return.

	/* The no-JS fallback blocks: hidden, and every control inside them disabled
	   so their (empty) values cannot post alongside the modal's. */
	document.querySelectorAll('[data-law-modal-fallback]').forEach(function (block) {
		block.hidden = true;
		block.querySelectorAll('input, textarea, select').forEach(function (field) {
			field.disabled = true;
		});
	});

	/* Any field the modal carries, whatever its name. A plain confirmation
	   (Approve, Mark paid) has none, and every step below copes with that. */
	function fields(modal) { return modal.querySelectorAll('[data-law-modal-field]'); }

	function focusables(modal) {
		return modal.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled])');
	}

	function openModal(modal, button) {
		if (open) { closeModal(); }
		open = modal;
		opener = button || null;
		modal.hidden = false;
		document.body.classList.add('law-modal-open');
		if (opener) { opener.setAttribute('aria-expanded', 'true'); }
		var field = fields(modal)[0];
		if (field) {
			field.disabled = false;
			field.focus();
		} else {
			/* Nothing to type in, so focus the dialog itself (hence its
			   tabindex="-1"): the Tab trap and screen readers both need the
			   focus inside the dialog rather than back on the page. */
			var dialog = modal.querySelector('.law-modal__dialog');
			if (dialog) { dialog.focus(); }
		}
	}

	function closeModal() {
		if (!open) { return; }
		var modal = open;
		var button = opener;
		open = null;
		opener = null;
		fields(modal).forEach(function (field) { field.disabled = true; });
		modal.querySelectorAll('.law-form-error, .law-modal__error').forEach(function (error) {
			error.remove();
		});
		modal.hidden = true;
		document.body.classList.remove('law-modal-open');
		if (button) {
			button.setAttribute('aria-expanded', 'false');
			button.focus();
		}
	}

	openers.forEach(function (button) {
		var modal = document.getElementById(button.getAttribute('data-law-modal-open'));
		if (!modal) { return; }
		/* The button submitted the form on the no-JS path; from here it only
		   opens the modal, and the modal's own submit button carries the
		   action value. */
		button.type = 'button';
		button.removeAttribute('name');
		button.removeAttribute('value');
		button.setAttribute('aria-haspopup', 'dialog');
		button.setAttribute('aria-controls', modal.id);
		button.setAttribute('aria-expanded', 'false');
		button.addEventListener('click', function () { openModal(modal, button); });
	});

	document.addEventListener('click', function (event) {
		var target = event.target;
		if (target && target.closest && target.closest('[data-law-modal-close]')) {
			event.preventDefault();
			if (open && open.classList.contains('law-modal--busy')) { return; }
			closeModal();
		}
	});

	document.addEventListener('keydown', function (event) {
		if (!open) { return; }
		if (event.key === 'Escape') {
			event.preventDefault();
			if (open.classList.contains('law-modal--busy')) { return; }
			closeModal();
			return;
		}
		if (event.key !== 'Tab') { return; }
		/* Trap: Tab off either end of the dialog wraps to the other. */
		var items = focusables(open);
		if (!items.length) { return; }
		var first = items[0];
		var last = items[items.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	});

	/* A courtesy check only: the server refuses an empty required note either
	   way. The message comes from the field's own data-law-modal-error and goes
	   in the modal rather than a browser bubble, which is why the field carries
	   aria-required instead of a native required attribute. A field without
	   aria-required is optional, and a modal without a field skips this. */
	document.querySelectorAll('.law-modal [type="submit"]').forEach(function (button) {
		button.addEventListener('click', function (event) {
			var modal = button.closest('.law-modal');
			var input = modal ? fields(modal)[0] : null;
			if (!input || input.getAttribute('aria-required') !== 'true' || input.value.trim()) { return; }
			event.preventDefault();
			var field = input.closest('.law-form-field');
			if (field && !field.querySelector('.law-form-error')) {
				var error = document.createElement('span');
				error.className = 'law-form-error';
				error.setAttribute('role', 'alert');
				error.textContent = input.getAttribute('data-law-modal-error') || 'Please complete this field.';
				field.appendChild(error);
			}
			input.focus();
		});
	});

	/* The programmatic surface, for scripts that answer a modal's action over
	   fetch and then need to swap to a result dialog. */
	window.lawModal = {
		open: function (id) {
			var modal = document.getElementById(id);
			if (modal) { openModal(modal, null); }
		},
		close: closeModal
	};
})();
