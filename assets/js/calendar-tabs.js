/**
 * The programme's day tabs (parts/calendar-daynav.php): one day at a time.
 *
 * The day links are server-rendered as jump links to #day-YYYY-MM-DD. Here
 * they become a WAI-ARIA tablist with automatic activation (Left/Right/Home/End
 * move between days with something on), the day sections become its panels and
 * only the active one shows. The active day is kept in the URL hash, the form
 * the links already used, so a deep link to a day still lands on it; the first
 * day shown is the hash's, else today's during the week, else the first day
 * with something on. The flagship strip at the top of the results hides while
 * the flagship's own day is showing, because the block itself is on screen.
 *
 * Works with assets/js/calendar-filters.js, which still owns the filters, the
 * AJAX fetch, the mobile filter modal and the greying-out of empty days: after
 * its law:partial-rendered event the new panels are enhanced, the counts on the
 * tabs refreshed from each section's data-count, and the same day kept unless
 * it emptied. Its own day-link scroll handler stands down when the nav carries
 * data-law-daynav, which is how the old layout (programme-old/) keeps scrolling.
 *
 * Nothing here computes the header offset. A scroll to the top of the panel
 * goes through scrollIntoView, so html's scroll-padding-top (app.css, measured
 * by app.js) handles the header and the results' scroll-margin-top the bar.
 */
(function () {
	'use strict';

	var nav = document.querySelector('.law-cal-daynav[data-law-daynav]');
	var results = document.getElementById('law-cal-events');
	if (!nav || !results) {
		return;
	}

	var flagshipDay = nav.getAttribute('data-flagship-day') || '';
	var active = null;

	var status = document.getElementById('law-cal-status');
	if (!status) {
		status = document.createElement('p');
		status.className = 'show-for-sr';
		status.setAttribute('role', 'status');
		results.parentNode.insertBefore(status, results);
	}
	/* An aria-live region around the results would read a whole day aloud on
	   every switch; the status element above says the day and its count. */
	results.removeAttribute('aria-live');

	function escapeId(value) {
		return window.CSS && CSS.escape ? CSS.escape(value) : value;
	}

	function links() {
		return Array.prototype.slice.call(nav.querySelectorAll('.law-cal-daynav__link'));
	}

	/* The "No confirmed slot" section is not a day: never a tab, and shown
	   under every one. */
	function sections() {
		return Array.prototype.slice
			.call(results.querySelectorAll('.law-cal-day-section[id^="day-"]'))
			.filter(function (section) {
				return section.id !== 'day-unscheduled';
			});
	}

	function sectionFor(day) {
		return results.querySelector('#day-' + escapeId(day));
	}

	function dayOf(section) {
		return section.id.replace(/^day-/, '');
	}

	function availableDays() {
		return links()
			.filter(function (link) {
				return !link.classList.contains('is-empty');
			})
			.map(function (link) {
				return link.getAttribute('data-day');
			});
	}

	/* Mirrors law_calendar_day_count_text(): cards only, a flagship-only day
	   leaves the count blank (its pill says what is on), an empty day says so. */
	function countText(count, day) {
		if (count === 1) {
			return '1 event';
		}
		if (count > 1) {
			return count + ' events';
		}
		return day === flagshipDay ? '' : 'No events';
	}

	function countFor(day) {
		var section = sectionFor(day);
		return section ? parseInt(section.getAttribute('data-count') || '0', 10) : 0;
	}

	function refreshCounts() {
		links().forEach(function (link) {
			var count = link.querySelector('.law-cal-daynav__count');
			if (count) {
				var day = link.getAttribute('data-day');
				count.textContent = countText(countFor(day), day);
			}
		});
	}

	/* On a phone the row is wider than the screen and scrolls sideways; the
	   active day is brought into view within the row. Element.scrollBy on the
	   nav, never scrollIntoView on the link, which would also scroll the page.
	   No behavior option: the nav's own scroll-behavior (and its reduced-motion
	   override in calendar.css) decides. */
	function revealLink(day) {
		var link = day ? nav.querySelector('.law-cal-daynav__link[data-day="' + escapeId(day) + '"]') : null;
		if (!link || nav.scrollWidth <= nav.clientWidth + 1) {
			return;
		}
		var row = nav.getBoundingClientRect();
		var box = link.getBoundingClientRect();
		if (box.left < row.left) {
			nav.scrollBy({ left: box.left - row.left - 16 });
		} else if (box.right > row.right) {
			nav.scrollBy({ left: box.right - row.right + 16 });
		}
	}

	function enhance() {
		nav.setAttribute('role', 'tablist');
		links().forEach(function (link) {
			var day = link.getAttribute('data-day');
			link.setAttribute('role', 'tab');
			if (!link.id) {
				link.id = 'law-cal-tab-' + day;
			}
			link.setAttribute('aria-controls', 'day-' + day);
		});
		sections().forEach(function (section) {
			section.setAttribute('role', 'tabpanel');
			section.setAttribute('aria-labelledby', 'law-cal-tab-' + dayOf(section));
		});
	}

	/* The hash if it names a day with something on, else today (during the
	   week), else the first day with something on. */
	function pick(preferred) {
		var days = availableDays();
		if (preferred && days.indexOf(preferred) !== -1) {
			return preferred;
		}
		var today = nav.getAttribute('data-today');
		if (today && days.indexOf(today) !== -1) {
			return today;
		}
		return days.length ? days[0] : null;
	}

	function announce(prefix) {
		var section = active ? sectionFor(active) : null;
		if (!section) {
			status.textContent = prefix || '';
			return;
		}
		var label = section.getAttribute('aria-label') || active;
		var what = countText(countFor(active), active) || 'the flagship conference';
		status.textContent = (prefix ? prefix + ' ' : '') + label + ': ' + what;
	}

	function activate(day, options) {
		options = options || {};
		active = day;
		links().forEach(function (link) {
			var on = day !== null && link.getAttribute('data-day') === day;
			link.setAttribute('aria-selected', on ? 'true' : 'false');
			/* Roving tabindex: one tab in the sequence, the rest reached with
			   the arrow keys. Empty days stay out of it, as calendar-filters.js
			   already leaves them. */
			if (link.classList.contains('is-empty')) {
				link.setAttribute('tabindex', '-1');
			} else {
				link.setAttribute('tabindex', on ? '0' : '-1');
			}
			if (on && options.focus) {
				link.focus();
			}
		});
		sections().forEach(function (section) {
			section.hidden = dayOf(section) !== day;
		});
		var strip = results.querySelector('.law-flagship-strip');
		if (strip) {
			strip.hidden = day !== null && strip.getAttribute('data-day') === day;
		}
		if (day && options.hash !== false) {
			window.history.replaceState(null, '', '#day-' + day);
		}
		if (options.announce !== false) {
			announce(options.prefix);
		}
		revealLink(day);
	}

	/* After a switch the new day should start where the old one was being
	   read: right under the tab bar. Only when the bar is stuck (the top of
	   the results has scrolled up behind it); switching near the top of the
	   page must not jump. */
	function scrollToPanel() {
		if (results.getBoundingClientRect().top < nav.getBoundingClientRect().bottom) {
			results.scrollIntoView({ block: 'start' });
		}
	}

	function fromHash() {
		var match = /^#day-(.+)$/.exec(window.location.hash || '');
		return match ? decodeURIComponent(match[1]) : null;
	}

	nav.addEventListener('click', function (event) {
		var link = event.target.closest ? event.target.closest('.law-cal-daynav__link') : null;
		if (!link || !nav.contains(link)) {
			return;
		}
		event.preventDefault();
		if (link.classList.contains('is-empty')) {
			return;
		}
		activate(link.getAttribute('data-day'));
		scrollToPanel();
	});

	nav.addEventListener('keydown', function (event) {
		var steps = { ArrowRight: 1, ArrowLeft: -1, Home: 'first', End: 'last' };
		if (!Object.prototype.hasOwnProperty.call(steps, event.key)) {
			return;
		}
		var days = availableDays();
		if (!days.length) {
			return;
		}
		var index = days.indexOf(active);
		var next;
		if (steps[event.key] === 'first') {
			next = 0;
		} else if (steps[event.key] === 'last') {
			next = days.length - 1;
		} else {
			next = (index + steps[event.key] + days.length) % days.length;
		}
		event.preventDefault();
		activate(days[next], { focus: true });
		scrollToPanel();
	});

	/* In-page day links inside the results -- the flagship strip -- switch the
	   tab. The strip sits at the top of the results, so the panel is already in
	   view there and scrollToPanel() is a no-op. */
	results.addEventListener('click', function (event) {
		var link = event.target.closest ? event.target.closest('a[href^="#day-"]') : null;
		if (!link) {
			return;
		}
		var day = link.getAttribute('href').slice('#day-'.length);
		if (availableDays().indexOf(day) === -1) {
			return;
		}
		event.preventDefault();
		activate(day);
		scrollToPanel();
	});

	window.addEventListener('hashchange', function () {
		var day = fromHash();
		if (day && day !== active && availableDays().indexOf(day) !== -1) {
			activate(day, { hash: false });
		}
	});

	/* After a filter fetch the panels are new elements: re-enhance them,
	   refresh the counts, and stay on the same day unless it emptied. */
	document.addEventListener('law:partial-rendered', function () {
		enhance();
		refreshCounts();
		activate(pick(active), { hash: false, prefix: 'Programme updated.' });
	});

	enhance();
	activate(pick(fromHash()), { hash: false, announce: false });
})();
