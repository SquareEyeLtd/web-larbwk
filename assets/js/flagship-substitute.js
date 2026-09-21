/**
 * The Substitute action on the committee's Flagship bookings dashboard
 * (the client's ask, 21 September 2026).
 *
 * ONE dialog serves the whole table (parts/events/flagship-substitute.php),
 * because the form carries fifteen fields and a copy per row on a list that
 * runs to hundreds would be most of the page. All this does is load the dialog
 * with the row that was pressed and let law-modal.js open it. Everything after
 * that is already built: booking-form.js posts the form over fetch, shows the
 * "Transferring…" busy state and marks any field the server refuses.
 *
 * Delegated at the document, never bound at load: the table arrives over
 * &law_partial=1 whenever a filter changes, and a handler bound to markup that
 * later goes away is a control that quietly stops working.
 *
 * Two things this has to do that the ticket-type dialog does not.
 *
 * It RESETS the form before writing anything, because one dialog serving every
 * row means a half-typed substitution for one delegate must not follow the
 * committee to another. The reset comes first and the booking id is written
 * after it — the other order clears the id that was just set.
 *
 * And it leaves the new delegate's fields EMPTY. Pre-filling them from the
 * current holder would make the quickest path through the dialog "press
 * Substitute, press Transfer" and substitute a seat to the person already in
 * it. The one thing carried over is the press pass, which is a property of the
 * seat rather than of the person.
 *
 * Depends on law-modal.js for window.lawModal.
 */
(function () {
	'use strict';

	var MODAL_ID = 'law-flagship-substitute';

	function dialog() { return document.getElementById(MODAL_ID); }

	/* Capture phase, so this runs BEFORE law-modal.js's own delegated opener
	   (bound on the document in the bubble phase) and the dialog is already
	   loaded with this row by the time it is shown. */
	document.addEventListener('click', function (event) {
		var opener = event.target.closest ? event.target.closest('[data-law-sub-open]') : null;
		if (!opener || !window.lawModal) { return; }

		var modal = dialog();
		if (!modal) { return; }

		var form = modal.querySelector('form');
		if (!form) { return; }

		/* Nothing is prevented here: law-modal.js opens the dialog on this same
		   click through data-law-modal-open, and preventing the default would
		   stop it opening at all. */

		/* Clear the last row out first, ids included, then write this one. */
		form.reset();

		form.querySelectorAll('[data-law-sub-booking]').forEach(function (input) {
			input.value = opener.getAttribute('data-law-sub-id') || '';
		});

		/* A property of the seat, so it travels with it. Everything else about
		   the new delegate is theirs to give. */
		var press = form.querySelector('[data-law-field="press"]');
		if (press) { press.checked = opener.getAttribute('data-law-sub-press') === '1'; }

		/* The wording is PHP's, from the form's own printf template, so no
		   sentence is assembled in here. */
		var current = form.querySelector('[data-law-sub-current]');
		var template = form.getAttribute('data-law-sub-current-template');
		if (current && template) {
			current.textContent = template
				.replace('%1$s', opener.getAttribute('data-law-sub-number') || '')
				.replace('%2$s', opener.getAttribute('data-law-sub-name') || '')
				.replace('%3$s', opener.getAttribute('data-law-sub-email') || '');
		}

		/* A refusal left over from the last row this dialog was used for would
		   otherwise read as a refusal of this one. */
		modal.querySelectorAll('.law-modal__error, .law-form-error').forEach(function (node) {
			node.remove();
		});
		modal.querySelectorAll('[aria-invalid]').forEach(function (node) {
			node.removeAttribute('aria-invalid');
		});
	}, true);
})();
