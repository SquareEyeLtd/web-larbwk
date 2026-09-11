/**
 * Programme calendar filters, shared with the committee dashboard.
 *
 * Desktop: keyword (debounced) and the selects re-fetch the events over
 * AJAX as soon as they change. Mobile: the filters live in a modal opened by
 * the Filters button; changes apply when Apply is pressed. The fetch hits the
 * page's own URL with &law_partial=1, which returns only the events markup
 * (parts/calendar-events.php, or parts/events/dashboard-list.php on the
 * dashboard), so Members access rules still apply. The filter fields are read
 * from the form generically, so each page brings its own set; the results
 * container picks its skeleton with data-law-skeleton="table" for list tables.
 */
(function () {
	'use strict';

	var controls = document.querySelector('[data-law-cal-controls]');
	var results = document.getElementById('law-cal-events');
	var form = document.getElementById('law-cal-filter-form');
	if (!controls || !results || !form) {
		return;
	}

	var pageUrl = controls.getAttribute('data-page-url') || window.location.pathname;
	var toggle = controls.querySelector('.law-cal-filterbar__toggle');
	var panel = controls.querySelector('.law-cal-filterbar__panel');
	var closeBtn = controls.querySelector('.law-cal-filterbar__close');
	var clearLink = controls.querySelector('.law-cal-filter-form__clear');
	var keyword = form.querySelector('input[name="law_kw"]');
	var desktop = window.matchMedia('(min-width: 64em)');

	var abortController = null;
	var keywordTimer = null;
	var KEYWORD_DELAY = 400;

	controls.classList.add('is-enhanced');
	if (toggle) {
		toggle.hidden = false;
	}

	function filterFields() {
		return form.querySelectorAll('input[name], select[name]');
	}

	function filterParams() {
		var params = new URLSearchParams();
		filterFields().forEach(function (field) {
			var value = field.value.trim();
			if (value !== '') {
				params.set(field.name, value);
			}
		});
		return params;
	}

	function skeletonHtml() {
		var section = '';
		if (results.getAttribute('data-law-skeleton') === 'table') {
			section += '<div class="law-cal-skeleton__bar law-cal-skeleton__bar--table-head"></div>';
			for (var r = 0; r < 6; r++) {
				section += '<div class="law-cal-skeleton__bar law-cal-skeleton__bar--table-row"></div>';
			}
		} else {
			for (var s = 0; s < 2; s++) {
				section += '<div class="law-cal-skeleton__bar law-cal-skeleton__bar--day"></div>';
				section += '<div class="law-cal-skeleton__bar law-cal-skeleton__bar--slot"></div>';
				for (var c = 0; c < 2; c++) {
					section +=
						'<div class="law-cal-skeleton__card">' +
						'<div class="law-cal-skeleton__line law-cal-skeleton__line--title"></div>' +
						'<div class="law-cal-skeleton__line law-cal-skeleton__line--meta"></div>' +
						'</div>';
				}
			}
		}
		return '<div class="law-cal-skeleton" aria-hidden="true">' + section + '</div>';
	}

	/* The day links are parts/calendar-daynav.php, a sibling of the controls
	   (sticky, so it has to share a parent with the results), hence the
	   document-wide lookup. Pages with no day links -- the dashboards, which
	   share this script -- find none and skip. */
	function dayLinks() {
		return document.querySelectorAll('.law-cal-daynav__link');
	}

	function updateDayNav() {
		dayLinks().forEach(function (link) {
			var day = link.getAttribute('data-day');
			var hasEvents = !!results.querySelector('#day-' + (window.CSS && CSS.escape ? CSS.escape(day) : day));
			link.classList.toggle('is-empty', !hasEvents);
			if (hasEvents) {
				link.removeAttribute('aria-disabled');
				link.removeAttribute('tabindex');
			} else {
				link.setAttribute('aria-disabled', 'true');
				link.setAttribute('tabindex', '-1');
			}
		});
	}

	function syncUrl(params) {
		var query = params.toString();
		window.history.replaceState(null, '', pageUrl + (query ? '?' + query : ''));
	}

	function fetchEvents() {
		var params = filterParams();
		syncUrl(params);

		if (abortController) {
			abortController.abort();
		}
		abortController = new AbortController();

		results.classList.add('is-loading');
		results.innerHTML = skeletonHtml();

		params.set('law_partial', '1');

		fetch(pageUrl + '?' + params.toString(), {
			signal: abortController.signal,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'fetch' }
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return response.text();
			})
			.then(function (html) {
				results.classList.remove('is-loading');
				results.innerHTML = html;
				updateDayNav();
				/* Anything that enhanced the OLD markup has to know it has
				   gone. Scripts on these pages are delegated, so behaviour
				   survives on its own, but state that was computed from the
				   DOM (which bulk buttons should be live, which ARIA an
				   opener carries) has to be recomputed against the new rows.
				   One event, so a page can add a listener rather than this
				   file learning about every feature that uses it. */
				document.dispatchEvent(
					new CustomEvent( 'law:partial-rendered', { detail: { container: results } } )
				);
				if ( window.lawModal && window.lawModal.initAll ) { window.lawModal.initAll( results ); }
			})
			.catch(function (error) {
				if (error.name === 'AbortError') {
					return;
				}
				// Fall back to a normal page load with the same filters.
				window.location.assign(pageUrl + (filterParams().toString() ? '?' + filterParams().toString() : ''));
			});
	}

	// --- Modal (mobile) ---

	function openModal() {
		controls.classList.add('is-modal-open');
		document.documentElement.classList.add('law-cal-modal-open');
		if (toggle) {
			toggle.setAttribute('aria-expanded', 'true');
		}
		if (keyword) {
			keyword.focus();
		}
	}

	function closeModal() {
		controls.classList.remove('is-modal-open');
		document.documentElement.classList.remove('law-cal-modal-open');
		if (toggle) {
			toggle.setAttribute('aria-expanded', 'false');
		}
	}

	if (toggle) {
		toggle.addEventListener('click', function () {
			if (controls.classList.contains('is-modal-open')) {
				closeModal();
			} else {
				openModal();
			}
		});
	}
	if (closeBtn) {
		closeBtn.addEventListener('click', closeModal);
	}
	if (panel) {
		// Click on the dimmed backdrop (the panel itself, not its children).
		panel.addEventListener('click', function (event) {
			if (event.target === panel) {
				closeModal();
			}
		});
	}
	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && controls.classList.contains('is-modal-open')) {
			closeModal();
			if (toggle) {
				toggle.focus();
			}
		}
	});

	// --- Applying filters ---

	form.addEventListener('submit', function (event) {
		event.preventDefault();
		closeModal();
		fetchEvents();
	});

	form.querySelectorAll('select').forEach(function (select) {
		select.addEventListener('change', function () {
			if (desktop.matches) {
				fetchEvents();
			}
		});
	});

	if (keyword) {
		keyword.addEventListener('input', function () {
			if (!desktop.matches) {
				return;
			}
			window.clearTimeout(keywordTimer);
			keywordTimer = window.setTimeout(fetchEvents, KEYWORD_DELAY);
		});
		keyword.addEventListener('search', function () {
			// Clearing via the input's native × button.
			if (desktop.matches) {
				fetchEvents();
			}
		});
	}

	if (clearLink) {
		clearLink.addEventListener('click', function (event) {
			event.preventDefault();
			form.reset();
			// reset() restores the server-rendered values, so blank explicitly.
			// data-law-keep marks a field that is not a filter and must survive
			// (the old programme layout's `variant` flag, programme-old/).
			filterFields().forEach(function (field) {
				if (field.hasAttribute('data-law-keep')) {
					return;
				}
				field.value = '';
			});
			closeModal();
			fetchEvents();
		});
	}

	// Day links as jump links: smooth-scroll to the day section, ignore days
	// with no events. Only where the nav is NOT a tablist (the old layout at
	// ?variant=old): on the programme the day links are tabs, calendar-tabs.js
	// owns the click and switches the day instead of scrolling.
	dayLinks().forEach(function (link) {
		link.addEventListener('click', function (event) {
			var nav = link.closest('.law-cal-daynav');
			if (nav && nav.hasAttribute('data-law-daynav')) {
				return;
			}
			if (link.classList.contains('is-empty')) {
				event.preventDefault();
				return;
			}
			var day = link.getAttribute('data-day');
			var target = results.querySelector('#day-' + (window.CSS && CSS.escape ? CSS.escape(day) : day));
			if (!target) {
				return;
			}
			event.preventDefault();
			/* No behavior option and no reduced-motion check: both come from
			   html's scroll-behavior in app.css, as does the header offset via
			   scroll-padding-top. preventDefault stays because this replaces
			   the history entry rather than pushing one. */
			target.scrollIntoView({ block: 'start' });
			window.history.replaceState(null, '', link.getAttribute('href'));
		});
	});
})();
