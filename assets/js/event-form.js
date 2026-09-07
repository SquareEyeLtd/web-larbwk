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
				if (field.type === 'checkbox' || field.type === 'radio') {
					field.checked = false; // Its value is the choice itself; only the state resets.
				} else if (field.type !== 'file') {
					field.value = '';
				}
			});
			wrap.insertBefore(row, template);
			syncSessionSpeakers();
			var first = row.querySelector('input, textarea');
			if (first) { first.focus(); }
		});
	});

	/* Session speakers: each session picks from the event's own speaker rows,
	   the way form 9 field 6 (Speakers) was a multiselect populated from the
	   form 8 (Event > speaker) entries. Free text was the wrong shape for it:
	   a name that didn't match a speaker exactly was silently dropped on save.
	   The list is rebuilt from the Speakers section whenever it changes, with
	   ticked names preserved so editing a speaker doesn't lose the session. */
	function speakerNames() {
		var names = [];
		document.querySelectorAll('[data-law-rows-group="speakers"] .law-row:not([data-law-row-template]) input[name$="[name]"]').forEach(function (input) {
			var name = input.value.trim();
			if (name && names.indexOf(name) === -1) { names.push(name); }
		});
		return names;
	}

	function syncSessionSpeakers() {
		var names = speakerNames();
		document.querySelectorAll('[data-law-session-speakers]').forEach(function (wrap) {
			var list = wrap.querySelector('[data-law-session-speaker-list]');
			var empty = wrap.querySelector('[data-law-session-speakers-empty]');
			if (!list) { return; }
			// Whether this row is still the hidden template decides name vs data-name,
			// so a cloned row keeps posting and the template keeps not posting.
			var isTemplate = !!wrap.closest('[data-law-row-template]');
			var existing = list.querySelector('input[type="checkbox"]');
			var fieldName = existing ? (existing.getAttribute('name') || existing.getAttribute('data-name')) : null;
			if (!fieldName) {
				var row = wrap.closest('.law-row');
				var sibling = row ? row.querySelector('[name^="sessions["], [data-name^="sessions["]') : null;
				var source = sibling ? (sibling.getAttribute('name') || sibling.getAttribute('data-name')) : null;
				if (!source) { return; }
				fieldName = source.replace(/\[[a-z_]+\]$/, '[speakers][]');
			}
			var checked = {};
			list.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
				if (box.checked) { checked[box.value] = true; }
			});
			list.textContent = '';
			names.forEach(function (name) {
				var label = document.createElement('label');
				var box = document.createElement('input');
				box.type = 'checkbox';
				box.setAttribute(isTemplate ? 'data-name' : 'name', fieldName);
				box.value = name;
				box.checked = !!checked[name];
				label.appendChild(box);
				label.appendChild(document.createTextNode(' ' + name));
				list.appendChild(label);
			});
			if (empty) { empty.hidden = names.length > 0; }
		});
	}

	document.addEventListener('input', function (event) {
		if (event.target.name && /^speakers\[\d+\]\[name\]$/.test(event.target.name)) { syncSessionSpeakers(); }
	});
	syncSessionSpeakers();

	document.addEventListener('click', function (event) {
		if (event.target.classList && event.target.classList.contains('law-row-remove')) {
			setTimeout(syncSessionSpeakers, 0);
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

	/* Conditional fields: a checkbox or radio with data-law-toggles shows/hides
	   the element with that id (the sector "please specify" inputs, the venue
	   name field). */
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
		/* A radio fires 'change' only when it becomes checked, never when a
		   sibling takes the selection, so watch the whole group to hide again. */
		if (box.type === 'radio' && box.name) {
			Array.prototype.forEach.call(document.getElementsByName(box.name), function (sib) {
				if (sib !== box) { sib.addEventListener('change', sync); }
			});
		}
	});

	/* The same idea from the other end: an element carrying data-law-toggle-for
	   names the checkbox that reveals it (the committee dashboard's override
	   amount). The wrapper's initial hidden state is rendered server-side, so
	   this only has to keep it in step from here on. The value is deliberately
	   never cleared: the field must keep posting even while hidden, because the
	   committee handler keys off isset( $_POST['law_fee_override_amount'] ). */
	document.querySelectorAll('[data-law-toggle-for]').forEach(function (target) {
		var box = document.getElementById(target.getAttribute('data-law-toggle-for'));
		if (!box) { return; }
		var sync = function () { target.hidden = !box.checked; };
		box.addEventListener('change', sync);
		sync();
	});

	/* Photo upload: reveal a Clear button once a file is chosen, and reset the
	   input on click. Delegated so cloned speaker rows work too. */
	document.addEventListener('change', function (event) {
		if (event.target && event.target.type === 'file') {
			var btn = event.target.parentNode && event.target.parentNode.querySelector('.law-file-clear');
			if (btn) { btn.hidden = !event.target.value; }
		}
	});
	document.addEventListener('click', function (event) {
		if (event.target && event.target.classList && event.target.classList.contains('law-file-clear')) {
			var input = event.target.parentNode && event.target.parentNode.querySelector('input[type="file"]');
			if (input) { input.value = ''; }
			event.target.hidden = true;
		}
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

	/* Tickets available can never exceed the chosen venue capacity band: keep the
	   number field's max in step with the select, and trim a value that no longer
	   fits so the host sees the ceiling before they submit. The server checks the
	   same rule (a locked band is read from the saved event, not this select). */
	(function () {
		var capacity = document.querySelector('[data-law-capacity]');
		var tickets = document.querySelector('[data-law-tickets]');
		if (!capacity || !tickets) { return; }
		capacity.addEventListener('change', function () {
			var option = capacity.options[capacity.selectedIndex];
			var max = option ? option.getAttribute('data-law-max') : '';
			if (max) {
				tickets.max = max;
				if (tickets.value && parseInt(tickets.value, 10) > parseInt(max, 10)) {
					tickets.value = max;
				}
			} else {
				tickets.removeAttribute('max');
			}
		});
	})();

	/* Invalid fields: the red border comes off as soon as the host touches the
	   control, so a field they are already fixing stops being flagged. The
	   message itself stays until the form is submitted again, so they can still
	   read what was wrong while they type. Delegated, so it also covers fields
	   inside repeater rows added after load. */
	['input', 'change'].forEach(function (type) {
		document.addEventListener(type, function (event) {
			var field = event.target.closest ? event.target.closest('.law-form-field') : null;
			if (!field || field.classList.contains('is-touched')) { return; }
			if (field.classList.contains('is-invalid') || field.querySelector('.law-form-error')) {
				field.classList.add('is-touched');
			}
		}, true);
	});

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

	/* Thread reply without a reload: post via fetch (law_ajax=1 makes the
	   handler answer JSON), append the returned bubble to the thread and
	   confirm or report the error inline below the button. If fetch is
	   unavailable or the script fails, the form still posts the classic
	   redirect-with-notice way. */
	document.querySelectorAll('.law-thread-reply').forEach(function (form) {
		var button = form.querySelector('button[type="submit"]');
		var textarea = form.querySelector('textarea[name="comment"]');
		if (!button || !textarea || !window.fetch) { return; }

		var view = form.closest('.law-thread-view');
		var status = document.createElement('div');
		status.className = 'law-form-notice law-thread-reply__status';
		status.hidden = true;
		form.appendChild(status);

		function notice(text, isError) {
			status.textContent = text;
			status.classList.toggle('is-error', !!isError);
			status.setAttribute('role', isError ? 'alert' : 'status');
			status.hidden = false;
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (!textarea.value.trim()) {
				notice('Please enter your message.', true);
				return;
			}

			var data = new FormData(form);
			data.append('law_ajax', '1');
			var label = button.textContent;
			button.disabled = true;
			button.textContent = 'Sending…';
			status.hidden = true;

			// getAttribute, not form.action: the hidden name="action" input every
			// admin-post form carries shadows the property and returns the element.
			fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (response) { return response.json(); })
				.then(function (response) {
					var payload = response.data || {};
					if (!response.success) {
						notice(payload.message || 'Sorry, your message could not be sent. Please try again.', true);
						button.textContent = label;
						return;
					}
					if (payload.bubble && view) {
						var list = view.querySelector('.law-thread-list');
						if (!list) {
							// First message: swap the empty-state sentence for the list.
							list = document.createElement('ol');
							list.className = 'law-thread-list';
							var empty = view.querySelector('.law-thread-empty');
							if (empty) { empty.replaceWith(list); } else { form.before(list); }
						}
						list.insertAdjacentHTML('beforeend', payload.bubble);
					}
					textarea.value = '';
					notice(payload.message || 'Comment sent.', false);
					if (payload.status && view) {
						var strong = view.querySelector('.law-thread-view__header strong');
						if (strong) { strong.textContent = payload.status; }
					}
					button.textContent = payload.button_label || label;
					if (payload.button_label && payload.button_label !== label) {
						// A resubmit just happened: the explanatory note no longer applies.
						var note = form.querySelector('.law-thread-reply__note');
						if (note) { note.remove(); }
					}
				})
				.catch(function () {
					notice('Sorry, your message could not be sent. Please reload the page and try again.', true);
					button.textContent = label;
				})
				.then(function () {
					button.disabled = false;
				});
		});
	});

	/* Withdraw an event without a reload (templates/account-events.php): the
	   card's form posts via fetch when confirmed from its modal (law_ajax=1
	   makes law_event_handle_withdraw() answer JSON), mirroring the committee
	   dashboard's committee-actions.js. Errors print inside the open modal;
	   success swaps to the shared law-modal-withdraw-success dialog and reloads
	   the page a few seconds later. Without JS (or without law-modal.js) the
	   opener stays a plain submit and the classic redirect-with-notice flow
	   does the job on its own. */
	(function () {
		var success = document.getElementById('law-modal-withdraw-success');
		if (!success || !window.fetch || !window.lawModal) { return; }

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

		document.querySelectorAll('.law-event-card__action-form').forEach(function (form) {
			var modal = form.querySelector('.law-modal');
			if (!modal) { return; }

			form.addEventListener('submit', function (event) {
				/* Only the modal's confirm goes over fetch; the opener never
				   submits with JS on (law-modal.js preventDefaults it). */
				var submitter = event.submitter;
				if (!submitter || !submitter.closest('.law-modal')) { return; }
				event.preventDefault();
				if (submitter.disabled) { return; }

				var data = new FormData(form);
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

				// getAttribute, not form.action: the hidden name="action" input every
				// admin-post form carries shadows the property and returns the element.
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
						submitter.textContent = label;
						busyState(modal, submitter, false);
						window.lawModal.open('law-modal-withdraw-success');
						/* replace, not assign: Back should not return to the stale
						   pre-action page. */
						window.setTimeout(function () {
							window.location.replace(payload.redirect || window.location.href);
						}, 3000);
					})
					.catch(function () {
						fail('Sorry, that did not work. Please reload the page to check the event before trying again.');
					});
			});
		});
	})();
})();
