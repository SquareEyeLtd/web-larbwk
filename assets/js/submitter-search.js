/**
 * The Event submitters screen's people picker
 * (parts/events/submitters-add.php, functions/events/submitters-dashboard.php).
 *
 * Upgrades one plain text input into an ARIA combobox: type two characters or
 * more, matching accounts arrive over AJAX, and choosing one fills the hidden
 * user ID the handler prefers. Without this script the same input still works —
 * it is an email field, and the handler resolves a typed address — which is why
 * the field is a real <input> in the markup rather than something this file
 * creates.
 *
 * Keyboard: Down/Up move through the matches, Enter picks the active one,
 * Escape closes the list (and, pressed again on a chosen person, clears the
 * choice). The list is never focusable itself, so focus stays in the input
 * throughout and a screen reader hears each option through aria-activedescendant.
 *
 * No dependencies beyond law-modal.js, which owns the dialog this sits in.
 */
(function () {
	'use strict';

	var DEBOUNCE_MS = 220;
	var MIN_CHARS = 2;

	function config() {
		return window.lawSubmitterSearch || {};
	}

	/**
	 * `text` with every case-insensitive occurrence of `term` wrapped in <mark>.
	 * The term is matched as ONE phrase rather than word by word, which is the
	 * site-wide rule (the programme's server-side highlighter does the same),
	 * and .law-hit is that highlighter's class so the two look identical.
	 *
	 * Builds nodes rather than HTML: the text is somebody's name and email
	 * straight out of the database, and this is the one place it reaches the
	 * page, so it must never travel through innerHTML.
	 */
	function highlight(text, term) {
		var frag = document.createDocumentFragment();
		var haystack = text.toLowerCase();
		var needle = term.toLowerCase();
		var cursor = 0;
		var at;

		if (!needle) {
			frag.appendChild(document.createTextNode(text));
			return frag;
		}

		at = haystack.indexOf(needle);
		while (at !== -1) {
			if (at > cursor) {
				frag.appendChild(document.createTextNode(text.slice(cursor, at)));
			}
			var mark = document.createElement('mark');
			mark.className = 'law-hit';
			mark.textContent = text.slice(at, at + needle.length);
			frag.appendChild(mark);
			cursor = at + needle.length;
			at = haystack.indexOf(needle, cursor);
		}
		if (cursor < text.length) {
			frag.appendChild(document.createTextNode(text.slice(cursor)));
		}
		return frag;
	}

	function init(root) {
		var input = root.querySelector('[data-law-submitter-input]');
		var list = root.querySelector('[data-law-submitter-results]');
		var status = root.querySelector('[data-law-submitter-status]');
		var hidden = root.querySelector('[data-law-submitter-id]');
		var chosen = root.querySelector('[data-law-submitter-chosen]');
		var chosenName = root.querySelector('[data-law-submitter-chosen-name]');
		var clear = root.querySelector('[data-law-submitter-clear]');
		if (!input || !list || !hidden) {
			return;
		}

		var options = [];   /* The pickable <li> elements, in view order. */
		var active = -1;
		var timer = null;
		var sequence = 0;   /* Answers can arrive out of order; only the newest wins. */

		/* Everything a screen reader is told about the list: how many matches
		   arrived, and who has been chosen. The list itself is visual; this is
		   the running commentary beside it. */
		function announce(message) {
			if (status) {
				status.textContent = message;
			}
		}

		function closeList() {
			list.hidden = true;
			list.textContent = '';
			options = [];
			active = -1;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
		}

		function setActive(index) {
			if (!options.length) {
				return;
			}
			if (active > -1 && options[active]) {
				options[active].classList.remove('is-active');
				options[active].setAttribute('aria-selected', 'false');
			}
			active = (index + options.length) % options.length;
			var option = options[active];
			option.classList.add('is-active');
			option.setAttribute('aria-selected', 'true');
			input.setAttribute('aria-activedescendant', option.id);
			/* scrollIntoView with block:'nearest' so arrowing down a long list
			   does not jump the whole dialog. */
			if (option.scrollIntoView) {
				option.scrollIntoView({ block: 'nearest' });
			}
		}

		function choose(person) {
			hidden.value = String(person.id);
			input.value = person.email;
			if (chosenName) {
				chosenName.textContent = person.name + ' (' + person.email + ')';
			}
			if (chosen) {
				chosen.hidden = false;
			}
			closeList();
			/* Focus stays in the input, which is what a combobox is supposed to
			   do: moving it to the button would strand somebody who picked the
			   wrong person, because getting back to the field means shift-tabbing
			   out of a dialog. The announcement is what says where they are and
			   what to do next. */
			announce(person.name + ' chosen. Press Add submitter to confirm.');
		}

		function unchoose(focusInput) {
			hidden.value = '';
			if (chosen) {
				chosen.hidden = true;
			}
			if (chosenName) {
				chosenName.textContent = '';
			}
			if (focusInput) {
				input.focus();
			}
		}

		/* One row. A person who cannot be picked is still drawn, at full
		   weight, with the reason where the action would be: a match that
		   silently vanished would be indistinguishable from having no account
		   at all, which is the question the committee is actually asking. */
		function renderOption(person, term, index) {
			var li = document.createElement('li');
			li.className = 'law-submitter-search__option';
			li.id = input.id + '-option-' + index;
			li.setAttribute('role', 'option');
			li.setAttribute('aria-selected', 'false');

			var name = document.createElement('span');
			name.className = 'law-submitter-search__name';
			name.appendChild(highlight(person.name, term));
			li.appendChild(name);

			var meta = document.createElement('span');
			meta.className = 'law-submitter-search__meta';
			meta.appendChild(highlight(person.email, term));
			if (person.organisation) {
				meta.appendChild(document.createTextNode(' · ' + person.organisation));
			}
			li.appendChild(meta);

			if (!person.can) {
				li.classList.add('is-unavailable');
				li.setAttribute('aria-disabled', 'true');
				var reason = document.createElement('span');
				reason.className = 'law-submitter-search__reason';
				reason.textContent = person.reason;
				li.appendChild(reason);
				return li;
			}

			li.addEventListener('mousedown', function (event) {
				/* mousedown, not click: the input's blur would close the list
				   before a click ever landed. */
				event.preventDefault();
				choose(person);
			});
			li.addEventListener('mouseenter', function () {
				setActive(options.indexOf(li));
			});
			return li;
		}

		function render(payload, term) {
			list.textContent = '';
			options = [];
			active = -1;

			var results = (payload && payload.results) || [];
			if (!results.length) {
				var empty = document.createElement('li');
				empty.className = 'law-submitter-search__empty';
				empty.textContent = 'Nobody found for “' + term + '”. They may not have an account yet.';
				list.appendChild(empty);
				list.hidden = false;
				input.setAttribute('aria-expanded', 'true');
				announce('No matches.');
				return;
			}

			results.forEach(function (person, index) {
				var li = renderOption(person, term, index);
				list.appendChild(li);
				if (person.can) {
					options.push(li);
				}
			});

			if (payload.more) {
				var more = document.createElement('li');
				more.className = 'law-submitter-search__empty';
				more.textContent = 'More matches than can be shown. Keep typing to narrow it down.';
				list.appendChild(more);
			}

			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			announce(results.length + (results.length === 1 ? ' match.' : ' matches.'));
			if (options.length) {
				setActive(0);
			}
		}

		function search(term) {
			var mine = ++sequence;
			var cfg = config();
			if (!cfg.url || !window.fetch) {
				return;
			}

			list.textContent = '';
			options = [];
			active = -1;
			/* A skeleton row rather than an empty box: the list opens the
			   instant a request starts, so the control never goes silent while
			   the network is working. */
			var loading = document.createElement('li');
			loading.className = 'law-submitter-search__loading';
			loading.setAttribute('aria-hidden', 'true');
			loading.appendChild(document.createElement('span'));
			loading.appendChild(document.createElement('span'));
			list.appendChild(loading);
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			announce('Searching…');

			var url = cfg.url + '?action=law_submitters_search&_wpnonce=' +
				encodeURIComponent(cfg.nonce || '') + '&q=' + encodeURIComponent(term);

			fetch(url, { credentials: 'same-origin' })
				.then(function (response) { return response.json(); })
				.then(function (response) {
					if (mine !== sequence) {
						return; /* A newer keystroke has already asked again. */
					}
					if (!response || !response.success) {
						throw new Error('search failed');
					}
					render(response.data, term);
				})
				.catch(function () {
					if (mine !== sequence) {
						return;
					}
					list.textContent = '';
					var failed = document.createElement('li');
					failed.className = 'law-submitter-search__empty';
					failed.textContent = 'The search could not be run. Please reload the page and try again.';
					list.appendChild(failed);
					list.hidden = false;
					announce('The search could not be run.');
				});
		}

		input.addEventListener('input', function () {
			/* Any edit invalidates a previous choice, so a stale ID can never
			   outlive what is on screen and be posted instead of it. */
			unchoose(false);
			var term = input.value.trim();
			clearTimeout(timer);
			if (term.length < MIN_CHARS) {
				closeList();
				announce('');
				return;
			}
			timer = setTimeout(function () { search(term); }, DEBOUNCE_MS);
		});

		input.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				if (!options.length) {
					return;
				}
				event.preventDefault();
				setActive(active + (event.key === 'ArrowDown' ? 1 : -1));
				return;
			}
			if (event.key === 'Enter') {
				if (!list.hidden && active > -1 && options[active]) {
					/* Enter picks the highlighted person rather than submitting
					   the form, which would post whatever half-typed text is in
					   the field. */
					event.preventDefault();
					options[active].dispatchEvent(new MouseEvent('mousedown'));
				}
				return;
			}
			if (event.key === 'Escape') {
				if (!list.hidden) {
					event.stopPropagation(); /* Close the list, not the dialog. */
					closeList();
					return;
				}
				if (hidden.value) {
					event.stopPropagation();
					unchoose(true);
				}
			}
		});

		input.addEventListener('blur', function () {
			/* A timeout, so a mousedown on an option still lands first. */
			window.setTimeout(closeList, 120);
		});

		if (clear) {
			clear.addEventListener('click', function () {
				input.value = '';
				unchoose(true);
				announce('Choice cleared.');
			});
		}

		/* The dialog is reopened, not rebuilt, so a previous attempt's state
		   would still be sitting in it: a name in the field, somebody chosen,
		   an old list of matches. law-modal.js dispatches no event of its own,
		   and what it does do is toggle `hidden` on the container, so that is
		   what this watches. Cheaper and more honest than polling, and it does
		   not require law-modal.js to grow an API for one caller. */
		var modal = root.closest('.law-modal');
		if (modal && window.MutationObserver) {
			new MutationObserver(function () {
				if (modal.hidden) {
					return;
				}
				input.value = '';
				unchoose(false);
				closeList();
				announce('');
			}).observe(modal, { attributes: true, attributeFilter: ['hidden'] });
		}
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-law-submitter-search]'), init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
