/**
 * Speakers archive search (templates/speakers.php).
 *
 * Filters the speaker cards as the visitor types, matching name, role and
 * organisation, and wraps the matching text in <mark> elements. Matching is
 * case- and accent-insensitive; every word of the query must match somewhere
 * on the card. No dependencies.
 */
(function () {
	'use strict';

	var DEBOUNCE_MS = 120;

	function stripAccents(text) {
		return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
	}

	// Lower-cased, accent-stripped copy of `text`, plus a map from every folded
	// character back to its index in the original string, so matches can be
	// highlighted in the original text.
	function fold(text) {
		var folded = '';
		var map = [];
		var i, j, stripped;
		for (i = 0; i < text.length; i++) {
			stripped = stripAccents(text.charAt(i)).toLowerCase();
			for (j = 0; j < stripped.length; j++) {
				folded += stripped.charAt(j);
				map.push(i);
			}
		}
		map.push(text.length); // Sentinel so a match may end at the last character.
		return { text: folded, map: map };
	}

	// Merged [start, end) ranges of every token occurrence, as indices into the
	// field's original text.
	function matchRanges(field, tokens) {
		var found = [];
		tokens.forEach(function (token) {
			var at = field.folded.text.indexOf(token);
			while (at !== -1) {
				found.push([field.folded.map[at], field.folded.map[at + token.length]]);
				at = field.folded.text.indexOf(token, at + 1);
			}
		});
		found.sort(function (a, b) {
			return a[0] - b[0];
		});

		var merged = [];
		found.forEach(function (range) {
			var last = merged[merged.length - 1];
			if (last && range[0] <= last[1]) {
				last[1] = Math.max(last[1], range[1]);
			} else {
				merged.push(range.slice());
			}
		});
		return merged;
	}

	function paint(field, tokens) {
		var ranges = tokens.length ? matchRanges(field, tokens) : [];

		if (!ranges.length) {
			// Only rebuild when a previous highlight needs removing.
			if (field.el.firstElementChild) {
				field.el.textContent = field.text;
			}
			return;
		}

		var frag = document.createDocumentFragment();
		var cursor = 0;
		ranges.forEach(function (range) {
			if (range[0] > cursor) {
				frag.appendChild(document.createTextNode(field.text.slice(cursor, range[0])));
			}
			var mark = document.createElement('mark');
			mark.className = 'law-speakers__hit';
			mark.textContent = field.text.slice(range[0], range[1]);
			frag.appendChild(mark);
			cursor = range[1];
		});
		if (cursor < field.text.length) {
			frag.appendChild(document.createTextNode(field.text.slice(cursor)));
		}

		field.el.textContent = '';
		field.el.appendChild(frag);
	}

	function init(root) {
		var input = root.querySelector('[data-speaker-search-input]');
		var count = root.querySelector('[data-speaker-search-count]');
		var empty = root.querySelector('[data-speaker-search-empty]');
		if (!input) {
			return;
		}

		var cards = Array.prototype.map.call(root.querySelectorAll('[data-speaker-card]'), function (el) {
			var fields = Array.prototype.map.call(el.querySelectorAll('[data-speaker-field]'), function (fieldEl) {
				var text = fieldEl.textContent;
				return { el: fieldEl, text: text, folded: fold(text) };
			});
			return {
				el: el,
				fields: fields,
				haystack: fields
					.map(function (field) {
						return field.folded.text;
					})
					.join(' ')
			};
		});

		function apply() {
			var tokens = stripAccents(input.value).toLowerCase().split(/\s+/).filter(Boolean);
			var shown = 0;

			cards.forEach(function (card) {
				var matches = tokens.every(function (token) {
					return card.haystack.indexOf(token) !== -1;
				});
				card.el.hidden = !matches;
				if (matches) {
					shown++;
				}
				card.fields.forEach(function (field) {
					paint(field, matches ? tokens : []);
				});
			});

			if (count) {
				count.textContent = tokens.length ? 'Showing ' + shown + ' of ' + cards.length + ' speakers' : '';
			}
			if (empty) {
				empty.hidden = shown > 0;
			}
		}

		var timer = null;
		input.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(apply, DEBOUNCE_MS);
		});
		input.addEventListener('search', apply); // The input's native clear button.
		input.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && input.value) {
				input.value = '';
				apply();
			}
		});
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-speaker-search]'), init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
