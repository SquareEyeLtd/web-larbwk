/**
 * Unread-message toasts (functions/events/unread.php): reveal toasts not
 * dismissed this session (staggered slide-in) and wire the dismiss buttons.
 * Dismissal is per session AND per latest message (the data-key carries the
 * newest comment ID), so a genuinely new message resurfaces the toast.
 */
(function () {
	'use strict';

	var wrap = document.querySelector('.law-toasts');
	if (!wrap) {
		return;
	}

	var store = null;
	try {
		store = window.sessionStorage;
	} catch (e) {
		// Storage blocked: toasts still show, dismissal just lasts one page view.
	}

	var shown = 0;
	wrap.querySelectorAll('.law-toast').forEach(function (toast) {
		var key = 'law-toast-dismissed:' + toast.getAttribute('data-key');

		if (store && store.getItem(key)) {
			toast.remove();
			return;
		}

		window.setTimeout(function () {
			toast.classList.add('is-visible');
		}, 150 + 120 * shown);
		shown++;

		var dismiss = toast.querySelector('.law-toast__dismiss');
		if (dismiss) {
			dismiss.addEventListener('click', function () {
				if (store) {
					try {
						store.setItem(key, '1');
					} catch (e) {}
				}
				toast.classList.remove('is-visible');
				window.setTimeout(function () {
					toast.remove();
				}, 250);
			});
		}
	});

	if (!wrap.querySelector('.law-toast')) {
		wrap.remove();
	}
})();
