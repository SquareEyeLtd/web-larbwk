/**
 * The bookings front end (EVENTS_BOOKINGS.md §7): progressive enhancement over
 * three no-JS-complete pieces.
 *
 * 1. Every booking opener — Register and Join waitlist on an event card and on
 *    the event page itself, and the flagship's Apply on both — is a real link
 *    to the inline form (?law_book=1, ?law_waitlist=1, ?law_flagship_apply=1)
 *    carrying data-law-book. With JS, section 2b opens the placeholder dialog
 *    on the press and fetches that one event's real dialog into it
 *    (?law_dialog=1). No page ships a booking dialog of its own any more, so a
 *    programme listing the whole week carries one button per card and no
 *    dialogs at all, and every surface behaves the same way.
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
 * 4. The host's waitlist arrows are the one action that does not reload: the
 *    reorder handler answers with the queue's new order and the table is
 *    reordered where it stands, with a shared status line above it. A payload
 *    that reports a promotion (or a queue this page no longer matches) falls
 *    back to the redirect, because that changes the other tables too.
 *
 * Everything here is delegated at the document rather than bound at load: the
 * committee's tables arrive over &law_partial=1, the programme's cards are
 * replaced whenever a filter changes, and a card's dialog is injected on click.
 * A handler bound to markup that later goes away is a control that quietly
 * stops working.
 *
 * Depends on law-modal.js for window.lawModal (open, close and redirect) and
 * the shared modal classes.
 */
(function () {
	'use strict';

	/* 1. (Was: stop the navigation on a Register anchor that law-modal.js had
	   just opened a server-rendered dialog for. Nothing renders those anchors
	   any more — every booking opener on the site is now a data-law-book link
	   whose dialog is fetched, and section 2b below both cancels the navigation
	   and does the opening.)

	   2. The attendee repeater. Delegated for the same reason: a dialog fetched
	   for an event card arrives long after load, and a per-form binding made at
	   load would leave its "Add a colleague" dead. */
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

	/* Hide "Add a colleague" on a form that is already at its cap. Matters for
	   the inline form, which the server may repopulate with rows after a refused
	   submission; a freshly fetched dialog starts empty, but it is run over both
	   so the two paths cannot diverge. */
	function initRepeaters(root) {
		(root || document).querySelectorAll('form.law-booking-form [data-law-booking-rows]').forEach(toggleAdd);
	}

	document.addEventListener('click', function (event) {
		var form = event.target.closest ? event.target.closest('form.law-booking-form') : null;
		if (!form) { return; }
		var container = form.querySelector('[data-law-booking-rows]');
		if (!container) { return; }

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

	initRepeaters();

	/* 2b. The booking dialog for an event CARD, fetched on click.

	   Cards carry a Register button but no dialog: the programme renders the
	   whole week at once, and the dialog is per event — a fixed wrapper id, its
	   own places count, its own colleague cap — so fifty copies would collide on
	   that id and be thrown away on the next filter change anyway. Instead the
	   button is a real link to the inline form on the event page, and this
	   fetches that one event's dialog (?law_dialog=1, see
	   law_booking_maybe_render_dialog()) and drops it in at body level.

	   Everything here degrades to following the link: no fetch, a slow server, a
	   404 because the event stopped being bookable since the page loaded. The
	   event page then explains why, which is a better answer than a dead
	   button. */

	var LOADING_ID = 'law-booking-loading';
	/* How long the skeleton stays up once shown, so it cannot flash. One number,
	   here, because it is a feel setting rather than a mechanism. */
	var MIN_LOADING = 1000;

	var dialogHost = null;
	var dialogCache = {};   // event id -> dialog HTML
	var dialogPending = {}; // event id -> the fetch promise, shared by click and prefetch
	var clickToken = 0;     // The press currently being served; later presses win.
	var hoverTimer = null;
	var hoverButton = null;
	var prefetched = 0;
	var PREFETCH_MAX = 8;   // A mouse swept down 50 cards must not fetch 50 pages.

	/* Outside #law-cal-events on purpose: the filter bar replaces that container
	   wholesale, and a dialog living inside it would be destroyed mid-use. */
	function bookHost() {
		if (!dialogHost || !document.contains(dialogHost)) {
			dialogHost = document.createElement('div');
			dialogHost.setAttribute('data-law-book-host', '');
			document.body.appendChild(dialogHost);
		}
		return dialogHost;
	}

	function bookUrl(button) {
		var href = button.getAttribute('href') || '';
		return href + (href.indexOf('?') === -1 ? '?' : '&') + 'law_dialog=1';
	}

	/* One promise per event, shared by the press and by any prefetch already
	   running for it. A prefetch must never make the press do nothing: that is
	   exactly what "I had to click Register twice" was. */
	function dialogFor(button) {
		var id = button.getAttribute('data-law-book');
		if (dialogCache[id]) { return Promise.resolve(dialogCache[id]); }
		if (dialogPending[id]) { return dialogPending[id]; }

		var controller = window.AbortController ? new AbortController() : null;
		var timer = window.setTimeout(function () { if (controller) { controller.abort(); } }, 8000);
		var request = fetch(bookUrl(button), {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'fetch' },
			signal: controller ? controller.signal : undefined
		})
			.then(function (response) {
				if (!response.ok) { throw new Error('HTTP ' + response.status); }
				return response.text();
			})
			.then(
				function (html) {
					window.clearTimeout(timer);
					delete dialogPending[id];
					dialogCache[id] = html;
					return html;
				},
				function (error) {
					window.clearTimeout(timer);
					delete dialogPending[id];
					throw error;
				}
			);

		dialogPending[id] = request;
		return request;
	}

	/* The dialog's id comes from the markup, never from the button: the server
	   decides the mode, so a card rendered while places were free opens the
	   waitlist dialog if the event has since filled up. */
	function showDialog(html, button) {
		var host = bookHost();
		host.innerHTML = html; // Replaces the previous pair; only one is ever live.
		if (window.lawModal && window.lawModal.initAll) { window.lawModal.initAll(host); }
		initRepeaters(host);
		var dialog = host.querySelector('.law-modal[id]');
		if (!dialog) { return false; }
		window.lawModal.open(dialog.id, button);
		return true;
	}

	/* Which of the three things this button opens, read off its own no-JS href
	   rather than a second attribute: the URL already says it. */
	function bookKind(button) {
		var href = button.getAttribute('href') || '';
		if (href.indexOf('law_flagship_apply=') !== -1) { return 'apply'; }
		if (href.indexOf('law_waitlist=') !== -1) { return 'waitlist'; }
		return 'book';
	}

	/* Open the placeholder immediately, so the press is visibly answered while
	   the real dialog is on its way. Its heading comes from the partial's own
	   data attributes, so the copy stays in PHP and matches the heading of the
	   dialog about to replace it. */
	function showLoading(button) {
		var loading = document.getElementById(LOADING_ID);
		if (!loading) { return false; }
		var title = loading.querySelector('.law-modal__title');
		if (title) {
			var wanted = title.getAttribute('data-law-loading-' + bookKind(button));
			if (wanted) { title.textContent = wanted; }
		}
		window.lawModal.open(LOADING_ID, button);
		return true;
	}

	function loadingIsUp() {
		var loading = document.getElementById(LOADING_ID);
		return !!loading && !loading.hidden;
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('[data-law-book]') : null;
		if (!button || !window.fetch || !window.lawModal) { return; }
		event.preventDefault();

		var id = button.getAttribute('data-law-book');
		var token = ++clickToken;

		// Already in hand, from an earlier press or a prefetch: no wait, no
		// placeholder, straight to the form. Nothing has appeared, so there is
		// nothing that could flash.
		if (dialogCache[id]) {
			showDialog(dialogCache[id], button);
			return;
		}

		// No placeholder on this page (it only renders for signed-in viewers of
		// a card surface): fall back to marking the button itself busy.
		var placeheld = showLoading(button);
		if (!placeheld) { bookBusy(button, true); }
		var shownAt = Date.now();

		/* Once the skeleton is up it stays up for MIN_LOADING, even if the
		   response beat it. A local fetch can answer in 60ms, and a skeleton
		   that appears and vanishes inside one frame reads as a glitch rather
		   than as loading (Denis, 11 September 2026). This only ever DELAYS the
		   placeholder path; a cached dialog still opens instantly. */
		function settle(done) {
			var wait = placeheld ? Math.max(0, MIN_LOADING - (Date.now() - shownAt)) : 0;
			window.setTimeout(function () {
				if (token !== clickToken) { return; } // A later press won.
				if (!placeheld) { bookBusy(button, false); }
				// Closed while we waited: they changed their mind, so do not
				// reopen over the top of them.
				if (placeheld && !loadingIsUp()) { return; }
				done();
			}, wait);
		}

		dialogFor(button).then(
			function (html) {
				settle(function () {
					if (!showDialog(html, button)) {
						window.location.assign(button.getAttribute('href'));
					}
				});
			},
			function () {
				settle(function () {
					// The inline form on the event page is a working fallback,
					// and a 404 here means the event stopped being bookable
					// since the page loaded — which that page explains.
					window.location.assign(button.getAttribute('href'));
				});
			}
		);
	});

	function bookBusy(button, on) {
		if (on) {
			button.setAttribute('aria-busy', 'true');
		} else {
			button.removeAttribute('aria-busy');
		}
	}

	/* Prefetch on hover and on keyboard focus, so the dialog is usually already
	   in hand by the time the press lands and no placeholder is ever seen.
	   Capped, and skipped on a metered connection: each one is a page bootstrap.

	   It shares dialogFor()'s promise with the click handler, so a press that
	   arrives mid-prefetch waits for that same request rather than being
	   swallowed. */
	function prefetch(button) {
		var id = button && button.getAttribute('data-law-book');
		if (!id || dialogCache[id] || dialogPending[id] || prefetched >= PREFETCH_MAX) { return; }
		if (!window.fetch) { return; }
		if (navigator.connection && navigator.connection.saveData) { return; }
		prefetched += 1;
		dialogFor(button).catch(function () { /* The press will navigate instead. */ });
	}

	/* pointerover, not pointerenter: it bubbles, so one listener covers cards
	   that arrive later. The dwell timer is only reset when the pointer moves to
	   a DIFFERENT button, so moving the cursor about within one button does not
	   postpone its fetch for ever. */
	document.addEventListener('pointerover', function (event) {
		var button = event.target.closest ? event.target.closest('[data-law-book]') : null;
		if (button === hoverButton) { return; }
		hoverButton = button;
		window.clearTimeout(hoverTimer);
		if (!button) { return; }
		hoverTimer = window.setTimeout(function () { prefetch(button); }, 120);
	});

	document.addEventListener('focusin', function (event) {
		var button = event.target.closest ? event.target.closest('[data-law-book]') : null;
		if (button) { prefetch(button); }
	});

	/* The ARIA belongs to the enhanced control only. Without JS the button is a
	   link to a page, and aria-haspopup="dialog" would be a lie; aria-controls is
	   left off entirely, because the dialog it would name does not exist until
	   the button is pressed and a dangling reference is worse than none. */
	function initBookButtons(root) {
		(root || document).querySelectorAll('[data-law-book]').forEach(function (button) {
			button.setAttribute('aria-haspopup', 'dialog');
			button.setAttribute('aria-expanded', 'false');
		});
	}
	initBookButtons();

	/* A filter change replaces every card. The dialog host survives (it is a
	   sibling of the list), but the opener behind an open dialog has just been
	   thrown away, and the places counts in the cache may have moved. */
	document.addEventListener('law:partial-rendered', function (event) {
		var host = dialogHost;
		if (host) {
			var live = host.querySelector('.law-modal:not([hidden])');
			// Not a dialog mid-request or holding a confirmation: those are
			// locked, and closing one would lose the reader the outcome.
			if (live && !live.classList.contains('law-modal--busy')) {
				window.lawModal.close();
				host.innerHTML = '';
			} else if (!live) {
				host.innerHTML = '';
			}
		}
		if (loadingIsUp()) { window.lawModal.close(); }
		dialogCache = {};
		prefetched = 0;
		initBookButtons(event.detail && event.detail.container);
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
			/* The waitlist arrows are single-glyph buttons in a table cell:
			   swapping in a word stretches the button and shifts the whole row,
			   so they ask for a quiet busy state instead. Their accessible name
			   is an aria-label either way, so no text is lost. */
			if (button.hasAttribute('data-law-booking-busy-quiet')) {
				if (on) {
					button.setAttribute('aria-busy', 'true');
				} else {
					button.removeAttribute('aria-busy');
				}
			} else if (on) {
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

	/* The host's waitlist queue, reordered in place.

	   A move posts like any other .law-booking-form, but the handler answers
	   with the queue as it now stands (order: [{id, position}]), so the arrows
	   need no page reload. Anything that payload cannot describe — an entry
	   promoted by the move, somebody joining or leaving meanwhile — falls back
	   to the redirect, because those change the active table, the cancelled
	   table and the counts in their headings as well. */
	function waitlistBody() {
		return document.querySelector('[data-law-waitlist]');
	}

	/* One shared line above the queue, for both the "order updated" confirmation
	   and a refusal: these forms are three glyph buttons inside a table cell, so
	   a notice placed in the form itself would wreck the row. */
	function waitlistNote(message, isError) {
		var body = waitlistBody();
		if (!body || !message) { return; }
		var wrap = body.closest('.law-dashboard__table-wrap') || body;
		var note = document.querySelector('[data-law-waitlist-status]');
		if (!note) {
			note = document.createElement('p');
			note.setAttribute('data-law-waitlist-status', '');
			note.setAttribute('role', 'status');
			wrap.parentNode.insertBefore(note, wrap);
		}
		note.className = 'law-form-notice ' + (isError ? 'is-error' : 'is-success');
		note.textContent = message;
	}

	function applyWaitlistOrder(payload, button) {
		var body = waitlistBody();
		var order = payload.order;
		if (!body || !order || !order.length) { return false; }
		// An entry has left the queue for the active table: reload instead.
		if (payload.promoted && payload.promoted.length) { return false; }

		var rows = {};
		var onPage = body.querySelectorAll('[data-law-waitlist-row]');
		onPage.forEach(function (row) {
			rows[row.getAttribute('data-law-waitlist-row')] = row;
		});
		// A queue this page has never seen: somebody joined or left meanwhile.
		if (onPage.length !== order.length) { return false; }
		var known = order.every(function (entry) { return !!rows[String(entry.id)]; });
		if (!known) { return false; }

		/* Moving a <tr> that holds the focused button blurs it, so remember
		   which control the actor was on and hand focus back afterwards. */
		var focusRow = button && button.closest ? button.closest('[data-law-waitlist-row]') : null;
		var focusForm = button && button.closest ? button.closest('[data-law-waitlist-move]') : null;
		var focusId = focusRow ? focusRow.getAttribute('data-law-waitlist-row') : '';
		var focusDir = focusForm ? focusForm.getAttribute('data-law-waitlist-move') : '';

		order.forEach(function (entry, index) {
			var row = rows[String(entry.id)];
			// Append in order: the rows walk into place, and moving the nodes
			// rather than re-rendering keeps their reject and promote dialogs
			// wired to law-modal.js.
			body.appendChild(row);
			var cell = row.querySelector('.law-booking-table__position strong');
			if (cell) { cell.textContent = String(entry.position); }
			// The server's position, not the row index: the next click's
			// stale-position guard is only as good as this value.
			row.querySelectorAll('[name="expected_position"]').forEach(function (input) {
				input.value = String(entry.position);
			});
			var edges = { top: 0 === index, up: 0 === index, down: index === order.length - 1 };
			row.querySelectorAll('[data-law-waitlist-move]').forEach(function (form) {
				var move = form.querySelector('[type="submit"]');
				if (move) { move.disabled = !!edges[form.getAttribute('data-law-waitlist-move')]; }
			});
		});

		if (focusId && rows[focusId]) {
			var wanted = rows[focusId].querySelector('[data-law-waitlist-move="' + focusDir + '"] [type="submit"]:not([disabled])')
				|| rows[focusId].querySelector('[data-law-waitlist-move] [type="submit"]:not([disabled])');
			if (wanted) { wanted.focus(); }
		}
		return true;
	}

	/* Select-all in a table header: tick or clear every enabled box bound to
	   the same form. Delegated, because the committee's tables are swapped in
	   over &law_partial=1 and a handler bound at load would be lost with the
	   old markup. */
	document.addEventListener('change', function (event) {
		var master = event.target.closest ? event.target.closest('[data-law-check-all]') : null;
		if (!master) { return; }
		var table = master.closest('table');
		if (!table) { return; }
		var form = null;
		table.querySelectorAll('tbody input[type="checkbox"]').forEach(function (box) {
			if (box.disabled) { return; }
			box.checked = master.checked;
			form = box.form || form;
		});
		syncBulkButtons(form);
	});

	/* Bulk decide buttons are dead until something is ticked. Delegated and
	   re-evaluated on every change, so a table swapped in by a filter starts
	   in the right state. Deliberately driven from the DOM rather than from a
	   counter: select-all, an individual tick and a swapped-in table all end
	   up asking the same question. */
	function syncBulkButtons(form) {
		if (!form) { return; }
		var ticked = false;
		document.querySelectorAll('input[name="booking_id[]"][type="checkbox"]').forEach(function (box) {
			if (box.form === form && box.checked) { ticked = true; }
		});
		/* document, not form.querySelectorAll: the flagship list renders its
		   decide buttons ABOVE the table and joins them to the form with the
		   HTML `form` attribute, so they are not descendants of it. */
		document.querySelectorAll('[data-law-bulk-decide]').forEach(function (button) {
			if (button.form !== form) { return; }
			button.disabled = !ticked;
			button.setAttribute('aria-disabled', ticked ? 'false' : 'true');
		});
	}

	function syncAllBulkButtons() {
		document.querySelectorAll('[data-law-bulk-decide]').forEach(function (button) {
			if (button.form) { syncBulkButtons(button.form); }
		});
	}

	document.addEventListener('change', function (event) {
		var box = event.target;
		if (box && box.type === 'checkbox' && box.form) { syncBulkButtons(box.form); }
	});
	syncAllBulkButtons();
	/* The committee's tables arrive over &law_partial=1; re-evaluate when one
	   lands, since the new boxes are all unticked. */
	document.addEventListener('law:partial-rendered', syncAllBulkButtons);

	/* event.submitter's fallback for older Safari: click fires before submit,
	   and law-modal.js can preventDefault an invalid click before any submit
	   event, so a stale record can never fire one. Mirrors committee-actions.js. */
	var lastClicked = null;
	document.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('[type="submit"]') : null;
		/* button.form, not closest('form'): a submit may sit outside its form
		   and be joined to it by the HTML `form` attribute. */
		var owner = button ? button.form : null;
		lastClicked = owner && owner.classList.contains('law-booking-form') ? button : null;
	}, true);

	/* Delegated at the document, NOT bound per form at load. The committee's
	   tables are swapped in over &law_partial=1 when a filter changes, and a
	   handler bound to the old markup dies with it — leaving every action on
	   the new table falling back to a plain POST. That is not a graceful
	   degradation here: a form whose meaning depends on which button was
	   pressed would post the hidden default instead. */
	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form || !form.classList || !form.classList.contains('law-booking-form')) { return; }
		( function () {
			if (!window.fetch) { return; }
			event.preventDefault();
			/* Not "the form's first submit": that is the opener sitting behind
			   the confirm dialog. */
			var button = event.submitter || lastClicked;
			if (!button || button.form !== form) { button = form.querySelector('[type="submit"]'); }
			if (button && button.disabled) { return; }
			var scope = scopeFor(form, button);

			var data = new FormData(form);
			/* A native submit sends the SUBMITTER's own name and value;
			   FormData(form) does not. Without this, a form whose meaning
			   depends on which button was pressed (approve vs decline, say)
			   posts one thing with JS and another without it — and the
			   no-JS reading is the one a hidden default field supplies, so
			   the two silently disagree. Append it, as the browser would. */
			if (button && button.name) { data.append(button.name, button.value); }
			data.append('law_ajax', '1');
			clearErrors(form, scope);
			busyState(form, button, true, button ? button.getAttribute('data-law-modal-busy') : '');

			// getAttribute, not form.action: the hidden name="action" input every
			// admin-post form carries shadows the property.
			fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (response) { return response.json(); })
				.then(function (response) {
					var payload = response.data || {};
					/* Whatever the outcome, a cached dialog for this event is
					   now suspect: the places count and the colleague cap in it
					   were true before this submission. */
					dialogCache = {};
					if (!response.success) {
						busyState(form, button, false);
						if (form.hasAttribute('data-law-waitlist-move')) {
							waitlistNote(payload.message || 'Sorry, that move could not be made.', true);
							return;
						}
						showError(form, scope, payload.message || 'Sorry, that change could not be made.');
						if (typeof payload.row === 'number' && payload.field) {
							markField(form, payload.row, payload.field);
						}
						return;
					}
					/* A waitlist move: reorder the table where we stand, and only
					   reload when the payload says the queue changed in ways this
					   page cannot show. Busy state off first — the swap sets each
					   arrow's disabled state from its new edge position, and
					   clearing the busy state afterwards would undo it. */
					if (payload.order) {
						busyState(form, button, false);
						if (applyWaitlistOrder(payload, button)) {
							waitlistNote(payload.message, false);
							return;
						}
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
							window.lawModal.redirect(payload.redirect);
						}, 2500);
						return;
					}
					/* lawModal.redirect, not location.replace: these handlers
					   answer with the list the form was posted from, and a
					   fragment-only navigation would not reload it. */
					window.lawModal.redirect(payload.redirect);
				})
				.catch(function () {
					busyState(form, button, false);
					var message = 'Sorry, that change could not be made. Please reload the page and try again.';
					if (form.hasAttribute('data-law-waitlist-move')) {
						waitlistNote(message, true);
						return;
					}
					showError(form, scope, message);
				});
		}() );
	});
})();
