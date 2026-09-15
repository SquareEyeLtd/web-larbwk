/**
 * The Ticket type pencil on the committee's Flagship bookings dashboard.
 *
 * ONE dialog serves the whole table (parts/events/flagship-ticket-type.php).
 * All this does is load it with the row that was pressed — the booking, the
 * delegate's name and the type currently set — and open it. Everything after
 * that is already built: booking-form.js posts the form over fetch, shows the
 * "Applying…" busy state, and swaps the cell from the `cell` key the handler
 * answers with.
 *
 * Delegated at the document, never bound at load: the table arrives over
 * &law_partial=1 whenever a filter changes, and a handler bound to markup that
 * later goes away is a control that quietly stops working. The same is true
 * after an edit, when the cell replaces itself.
 *
 * Depends on law-modal.js for window.lawModal.
 */
(function () {
	'use strict';

	var MODAL_ID = 'law-flagship-ticket-type';

	function dialog() { return document.getElementById(MODAL_ID); }

	/* Capture phase, so this runs BEFORE law-modal.js's own delegated opener
	   (bound on the document in the bubble phase) and the dialog is already
	   loaded with this row by the time it is shown. */
	document.addEventListener('click', function (event) {
		var opener = event.target.closest ? event.target.closest('[data-law-ticket-open]') : null;
		if (!opener || !window.lawModal) { return; }

		var modal = dialog();
		if (!modal) { return; }
		/* law-modal.js opens it on the same click through data-law-modal-open.
		   This listener only fills it in first, so nothing is prevented here:
		   preventing the default would stop the dialog opening at all. */

		var form = modal.closest('form');
		if (!form) { return; }

		var name = opener.getAttribute('data-law-ticket-name') || '';

		form.querySelectorAll('[data-law-ticket-booking]').forEach(function (input) {
			input.value = opener.getAttribute('data-law-ticket-id') || '';
		});

		/* The select ships disabled and law-modal.js enables it on open, which
		   happens after this handler; setting .value on a disabled control is
		   fine and survives being enabled. */
		var field = modal.querySelector('[data-law-modal-field]');
		if (field) { field.value = opener.getAttribute('data-law-ticket-value') || ''; }

		/* The wording is PHP's, from the form's own printf template, so no
		   sentence is assembled in here. */
		var title = modal.querySelector('.law-modal__title');
		var template = form.getAttribute('data-law-ticket-title');
		if (title && template && name) { title.textContent = template.replace('%s', name); }

		/* A refusal left over from the last row this dialog was used for would
		   otherwise read as a refusal of this one. */
		modal.querySelectorAll('.law-modal__error, .law-form-error').forEach(function (node) {
			node.remove();
		});
	}, true);
})();
