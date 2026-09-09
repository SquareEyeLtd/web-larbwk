/**
 * Committee dashboard workflow actions over fetch (templates/account-dashboard.php).
 *
 * Progressive enhancement on top of law-modal.js: without JS (or without
 * fetch) the modal confirm buttons are plain submits and the classic
 * POST-redirect-notice flow does the job on its own. With JS, a confirm
 * button inside a modal posts the controls form in the background, shows an
 * in-flight label ("Approving…"), then swaps to the success dialog and
 * reloads the page a few seconds later so the dashboard reflects the new
 * status. "Save changes" sits on the same form but outside any modal, so it
 * keeps the classic submit.
 *
 * The server side is the law_ajax=1 branch of law_committee_action_handler()
 * (functions/events/committee.php), which mirrors the thread reply handler.
 */
(function () {
	'use strict';

	var form = document.querySelector('.law-dashboard__controls');
	var success = document.getElementById('law-modal-success');
	if (!form || !success || !window.fetch || !window.lawModal) { return; }

	/* event.submitter fallback for older Safari: click fires before submit,
	   and law-modal.js's required-note check preventDefaults an invalid click
	   before any submit event, so a stale record can never fire a submit.
	   Every submit button updates the record — a "Save changes" click clears
	   it to null, so a modal confirm abandoned earlier can never claim it. */
	var lastClicked = null;
	form.querySelectorAll('[type="submit"]').forEach(function (button) {
		button.addEventListener('click', function () {
			lastClicked = button.closest('.law-modal') ? button : null;
		});
	});

	function busyState(modal, button, on) {
		modal.classList.toggle('law-modal--busy', on);
		var dialog = modal.querySelector('.law-modal__dialog');
		if (dialog) {
			if (on) { dialog.setAttribute('aria-busy', 'true'); } else { dialog.removeAttribute('aria-busy'); }
		}
		button.disabled = on;
		modal.querySelectorAll('.law-modal__actions [data-law-modal-close], .law-modal__close').forEach(function (control) {
			control.disabled = on;
		});
	}

	function showError(modal, message) {
		var actions = modal.querySelector('.law-modal__actions');
		var error = modal.querySelector('.law-modal__error');
		if (!error) {
			error = document.createElement('p');
			error.className = 'law-modal__error';
			error.setAttribute('role', 'alert');
			if (actions) { actions.parentNode.insertBefore(error, actions); }
		}
		error.textContent = message;
	}

	form.addEventListener('submit', function (event) {
		var submitter = event.submitter || lastClicked;
		/* Only a modal's confirm button goes over fetch; "Save changes" and
		   anything else on the form keeps the classic POST. */
		if (!submitter || !submitter.closest('.law-modal')) { return; }
		var modal = submitter.closest('.law-modal');
		event.preventDefault();
		if (submitter.disabled) { return; }

		var data = new FormData(form);
		/* FormData(form) omits the submitter's name/value, and that pair is the
		   only carrier of law_action — without it the server would run the
		   plain "Save changes" path and the action would silently not happen. */
		data.append(submitter.name, submitter.value);
		data.append('law_ajax', '1');

		var label = submitter.textContent;
		submitter.textContent = submitter.getAttribute('data-law-modal-busy') || 'Working…';
		busyState(modal, submitter, true);
		var oldError = modal.querySelector('.law-modal__error');
		if (oldError) { oldError.remove(); }

		function fail(message) {
			submitter.textContent = label;
			busyState(modal, submitter, false);
			showError(modal, message);
		}

		/* getAttribute, not form.action: the hidden name="action" input every
		   admin-post form carries shadows the property and returns the element. */
		fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (response) { return response.json(); })
			.then(function (response) {
				var payload = response.data || {};
				if (!response.success) {
					fail(payload.message || 'Sorry, that did not work. Please try again.');
					return;
				}
				var title = success.querySelector('.law-modal__title');
				var copy = success.querySelector('.law-modal__copy');
				if (title && payload.title) { title.textContent = payload.title; }
				if (copy && payload.message) { copy.textContent = payload.message; }
				/* Opening the success dialog closes the action modal (openModal
				   always closes the current one first), so drop the busy state
				   before handing over. */
				submitter.textContent = label;
				busyState(modal, submitter, false);
				window.lawModal.open('law-modal-success');
				/* lawModal.redirect, not location.replace: the handler often
				   answers with the page we are already on, and a fragment-only
				   navigation would not reload it. */
				window.setTimeout(function () {
					window.lawModal.redirect(payload.redirect);
				}, 3000);
			})
			.catch(function () {
				/* A non-JSON response can be a proxy or PHP timeout after a slow
				   approve that actually went through server-side, so send the
				   committee member to look before they fire it again. */
				fail('Sorry, that did not work. Please reload the page to check the event before trying again.');
			});
	});
})();
