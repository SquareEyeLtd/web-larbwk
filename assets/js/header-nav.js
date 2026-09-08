/**
 * Header disclosure behaviour: the account dropdown in the top bar
 * (parts/layout/top-nav.php) and the mobile burger's open state.
 *
 * The dropdown is a native <details>, so opening, closing, the expanded
 * state and keyboard operation are the browser's job and still work with
 * this file absent. All that is added here is what <details> does not do on
 * its own:
 *
 *   - Escape closes and returns focus to the trigger.
 *   - A pointerdown outside closes. Not blur, which misbehaves on touch and
 *     with VoiceOver.
 *   - Focus leaving the menu closes it, so tabbing past the last link does
 *     not strand an open panel behind you.
 *
 * Deliberately NOT a focus trap. This is a disclosure, not a dialog; law-modal.js
 * is the place that traps focus.
 *
 * The account panel and the burger's full-screen sheet would otherwise
 * overlap on mobile, so both broadcast law:menu-open and close themselves
 * when someone else opens. The burger's own animation stays in app.js.
 */
(function () {
	'use strict';

	var MENU_OPEN = 'law:menu-open';

	function close(details) {
		if (details.open) {
			details.open = false;
		}
	}

	function setup(details) {
		var summary = details.querySelector('summary');

		details.addEventListener('toggle', function () {
			if (details.open) {
				document.dispatchEvent(new CustomEvent(MENU_OPEN, { detail: details }));
			}
		});

		document.addEventListener(MENU_OPEN, function (event) {
			if (event.detail !== details) {
				close(details);
			}
		});

		details.addEventListener('keydown', function (event) {
			if (event.key !== 'Escape' && event.key !== 'Esc') {
				return;
			}
			if (!details.open) {
				return;
			}
			close(details);
			if (summary) {
				summary.focus();
			}
		});

		// relatedTarget is where focus is going. Null (a click on chrome, or
		// the window losing focus) is not a reason to close.
		details.addEventListener('focusout', function (event) {
			if (!details.open) {
				return;
			}
			if (event.relatedTarget && !details.contains(event.relatedTarget)) {
				close(details);
			}
		});

		document.addEventListener('pointerdown', function (event) {
			if (details.open && !details.contains(event.target)) {
				close(details);
			}
		});
	}

	var panels = document.querySelectorAll('[data-law-topnav]');
	Array.prototype.forEach.call(panels, setup);

	// The burger announces itself too, so opening it closes any open account
	// menu. app.js dispatches this from its own click handler.
	document.addEventListener(MENU_OPEN, function (event) {
		if (event.detail === 'burger') {
			Array.prototype.forEach.call(panels, close);
		}
	});
}());
