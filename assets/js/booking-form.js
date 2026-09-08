/**
 * The bookings front end (EVENTS_BOOKINGS.md §7): progressive enhancement over
 * three no-JS-complete pieces.
 *
 * 1. The Register opener is a real link to the inline form (?law_book=1);
 *    with JS it opens the booking modal instead (law-modal.js does the
 *    opening off data-law-modal-open — this script only stops the navigation).
 * 2. The attendee repeater (parts/events/attendee-repeater.php): add/remove
 *    rows up to the container's data-law-max, with focus handed to the new
 *    row's first field and back to Add a colleague on removal. Deliberately
 *    its own hooks (data-law-booking-*): event-form.js drives the other
 *    repeaters and both scripts share the account pages.
 * 3. Every .law-booking-form posts over fetch with law_ajax=1 (the handlers
 *    answer JSON). Errors render in place — row/field-keyed refusals mark the
 *    offending input; anything else prints above the actions. Success opens
 *    the form's data-law-booking-success dialog (locked: its two links are
 *    the only exits) or, when there is no dialog to show, follows the
 *    payload's redirect.
 *
 * Depends on law-modal.js for window.lawModal and the shared modal classes.
 */
(function () {
	'use strict';

	/* 1. The modal opener is an anchor so the no-JS path navigates to the
	   inline form; with JS, law-modal.js opens the dialog and the navigation
	   must not happen. Scoped to this feature's own two openers (Register and
	   Join waitlist) so a future anchor opener elsewhere is not silently
	   deadened. */
	document.querySelectorAll('a[data-law-modal-open="law-booking-modal"], a[data-law-modal-open="law-waitlist-modal"]').forEach(function (link) {
		link.addEventListener('click', function (event) {
			event.preventDefault();
		});
	});

	/* 2. The attendee repeater. */
	var rowCounter = 100; // Clear of any server-rendered row indexes.

	function visibleRows(container) {
		return container.querySelectorAll('.law-row:not([data-law-booking-row-template])');
	}

	function toggleAdd(container) {
		var form = container.closest('form');
		var add = form ? form.querySelector('[data-law-booking-add]') : null;
		if (add) {
			add.hidden = visibleRows(container).length >= parseInt(container.getAttribute('data-law-max') || '0', 10);
		}
	}

	document.querySelectorAll('form.law-booking-form').forEach(function (form) {
		var container = form.querySelector('[data-law-booking-rows]');
		if (!container) { return; }
		toggleAdd(container);

		form.addEventListener('click', function (event) {
			var add = event.target.closest('[data-law-booking-add]');
			if (add) {
				var template = container.querySelector('[data-law-booking-row-template]');
				var max = parseInt(container.getAttribute('data-law-max') || '0', 10);
				if (!template || visibleRows(container).length >= max) { return; }
				var row = template.cloneNode(true);
				row.hidden = false;
				row.removeAttribute('data-law-booking-row-template');
				rowCounter += 1;
				row.querySelectorAll('[data-name]').forEach(function (input) {
					input.name = input.getAttribute('data-name').replace('__i__', String(rowCounter));
					input.removeAttribute('data-name');
				});
				container.insertBefore(row, template);
				toggleAdd(container);
				var first = row.querySelector('input');
				if (first) { first.focus(); }
				return;
			}
			var remove = event.target.closest('[data-law-booking-remove]');
			if (remove) {
				var owned = remove.closest('.law-row');
				if (owned && !owned.hasAttribute('data-law-booking-row-template')) {
					owned.remove();
					toggleAdd(container);
					var addButton = form.querySelector('[data-law-booking-add]');
					if (addButton) { addButton.focus(); }
				}
			}
		});
	});

	/* 3. Fetch submission. */

	/* Which element a message belongs in. A confirm dialog is rendered INSIDE
	   the form it confirms (parts/layout/modal.php), so form.closest('.law-modal')
	   is null on exactly the forms that have one, and an error placed in the
	   form lands behind the open dialog where nobody sees it. The submitter is
	   what tells us which of the two we are in. */
	function scopeFor(form, submitter) {
		var modal = submitter && submitter.closest ? submitter.closest('.law-modal') : null;
		return modal || form.closest('.law-modal') || form;
	}

	function clearErrors(form, scope) {
		( scope || form ).querySelectorAll('.law-modal__error, [data-law-booking-error]').forEach(function (node) {
			node.remove();
		});
		form.querySelectorAll('label.is-invalid').forEach(function (label) {
			label.classList.remove('is-invalid');
		});
	}

	function showError(form, scope, message) {
		scope = scope || form;
		var in_modal = scope.classList && scope.classList.contains('law-modal');
		var error = document.createElement('p');
		error.setAttribute('role', 'alert');
		if (in_modal) {
			error.className = 'law-modal__error';
			var actions = scope.querySelector('.law-modal__actions');
			if (actions) { actions.parentNode.insertBefore(error, actions); } else { scope.querySelector('.law-modal__dialog').appendChild(error); }
		} else {
			error.className = 'law-form-notice is-error';
			error.setAttribute('data-law-booking-error', '');
			form.insertBefore(error, form.firstChild);
		}
		error.textContent = message;
	}

	function markField(form, rowIndex, field) {
		var rows = form.querySelectorAll('[data-law-booking-rows] .law-row:not([data-law-booking-row-template])');
		var row = rows[rowIndex];
		var input = row ? row.querySelector('[name$="[' + field + ']"]') : null;
		if (!input) { return; }
		var label = input.closest('label');
		if (label) { label.classList.add('is-invalid'); }
		input.focus();
	}

	function busyState(form, button, on, busyLabel) {
		var modal = button && button.closest ? button.closest('.law-modal') : form.closest('.law-modal');
		if (button) {
			if (on) {
				button.setAttribute('data-law-booking-label', button.textContent);
				button.textContent = busyLabel || 'Working…';
			} else {
				button.textContent = button.getAttribute('data-law-booking-label') || button.textContent;
			}
			button.disabled = on;
		}
		if (modal) {
			modal.classList.toggle('law-modal--busy', on);
			modal.querySelectorAll('[data-law-modal-close], .law-modal__close').forEach(function (control) {
				control.disabled = on;
			});
		}
	}

	/* event.submitter's fallback for older Safari: click fires before submit,
	   and law-modal.js can preventDefault an invalid click before any submit
	   event, so a stale record can never fire one. Mirrors committee-actions.js. */
	var lastClicked = null;
	document.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('[type="submit"]') : null;
		lastClicked = button && button.closest('form.law-booking-form') ? button : null;
	}, true);

	document.querySelectorAll('form.law-booking-form').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			if (!window.fetch) { return; }
			event.preventDefault();
			/* Not "the form's first submit": that is the opener sitting behind
			   the confirm dialog. */
			var button = event.submitter || lastClicked;
			if (!button || button.form !== form) { button = form.querySelector('[type="submit"]'); }
			if (button && button.disabled) { return; }
			var scope = scopeFor(form, button);

			var data = new FormData(form);
			data.append('law_ajax', '1');
			clearErrors(form, scope);
			busyState(form, button, true, button ? button.getAttribute('data-law-modal-busy') : '');

			// getAttribute, not form.action: the hidden name="action" input every
			// admin-post form carries shadows the property.
			fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (response) { return response.json(); })
				.then(function (response) {
					var payload = response.data || {};
					if (!response.success) {
						busyState(form, button, false);
						showError(form, scope, payload.message || 'Sorry, that change could not be made.');
						if (typeof payload.row === 'number' && payload.field) {
							markField(form, payload.row, payload.field);
						}
						return;
					}
					var dialogId = form.getAttribute('data-law-booking-success');
					var dialog = dialogId ? document.getElementById(dialogId) : null;
					if (dialog && window.lawModal) {
						busyState(form, button, false);
						window.lawModal.open(dialogId);
						/* Lock it: the dialog's own links are the exits. Closing in
						   place would leave the stale pre-booking page behind it. */
						dialog.classList.add('law-modal--busy');
						return;
					}
					/* Confirmed in a dialog: say what happened before reloading.
					   The server's message is sometimes the whole point ("the
					   event is now over-booked by 2 places"), and a page-top
					   notice is scrolled past when the redirect carries an
					   anchor. */
					if (scope !== form && scope.classList && scope.classList.contains('law-modal')) {
						var copy = scope.querySelector('.law-modal__copy');
						if (copy && payload.message) { copy.textContent = payload.message; }
						var title = scope.querySelector('.law-modal__title');
						if (title && payload.title) { title.textContent = payload.title; }
						scope.classList.add('law-modal--busy');
						window.setTimeout(function () {
							window.location.replace(payload.redirect || window.location.href);
						}, 2500);
						return;
					}
					/* replace, not assign: Back should not return to the stale form. */
					window.location.replace(payload.redirect || window.location.href);
				})
				.catch(function () {
					busyState(form, button, false);
					showError(form, scope, 'Sorry, that change could not be made. Please reload the page and try again.');
				});
		});
	});
})();
