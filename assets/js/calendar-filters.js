/**
 * Programme calendar filters.
 *
 * Desktop: keyword (debounced) and the two selects re-fetch the events over
 * AJAX as soon as they change. Mobile: the filters live in a modal opened by
 * the Filters button; changes apply when Apply is pressed. The fetch hits the
 * page's own URL with &law_partial=1, which returns only the events markup
 * (parts/calendar-events.php), so Members access rules still apply.
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

	function filterParams() {
		var params = new URLSearchParams();
		['law_kw', 'law_sector', 'law_type'].forEach(function (name) {
			var field = form.elements[name];
			var value = field ? field.value.trim() : '';
			if (value !== '') {
				params.set(name, value);
			}
		});
		return params;
	}

	function skeletonHtml() {
		var section = '';
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
		return '<div class="law-cal-skeleton" aria-hidden="true">' + section + '</div>';
	}

	function updateDayNav() {
		controls.querySelectorAll('.law-cal-daynav__link').forEach(function (link) {
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
			['law_kw', 'law_sector', 'law_type'].forEach(function (name) {
				if (form.elements[name]) {
					form.elements[name].value = '';
				}
			});
			closeModal();
			fetchEvents();
		});
	}

	// Day links: smooth-scroll to the day section, ignore days with no events.
	controls.querySelectorAll('.law-cal-daynav__link').forEach(function (link) {
		link.addEventListener('click', function (event) {
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
			var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
			window.history.replaceState(null, '', link.getAttribute('href'));
		});
	});
})();
