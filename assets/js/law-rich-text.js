/**
 * The WYSIWYG editor behind the events module's descriptive fields — the event
 * description, each speaker biography and each session description.
 *
 * WordPress core's TinyMCE, attached from JavaScript (wp.editor.initialize)
 * rather than printed by wp_editor(), so that a repeater row cloned in the
 * browser can get an editor of its own. Every textarea carrying data-law-rich
 * is a candidate; one inside a repeater's hidden template row is left alone
 * until event-form.js clones it and calls init() on the copy.
 *
 * Public API (window.lawRichText):
 *   init(textarea)   Attach an editor. Safe to call twice on the same field.
 *   remove(textarea) Detach, writing the content back to the textarea first.
 *   initAll(root)    Attach to every eligible field under root.
 *   save()           Flush every editor into its textarea.
 *
 * Two things the wiring has to get right, both of them easy to miss:
 *
 * 1. wp_enqueue_editor() prints TinyMCE at priority 45 of the footer scripts,
 *    which is AFTER this file. Nothing may touch window.tinymce before
 *    DOMContentLoaded, and even then we poll briefly in case the browser is
 *    still executing the tinymce bundle.
 *
 * 2. TinyMCE hides the textarea it takes over, so the content only reaches the
 *    textarea when something calls triggerSave(). A capture-phase submit
 *    listener does that for every form on the page, which covers the plain
 *    POST forms and the fetch/FormData ones (the AJAX modal pattern) alike.
 */
(function (window, document) {
	'use strict';

	var settings = window.lawRichTextSettings || {};
	var sequence = 0;

	function ready() {
		return !!(window.tinymce && window.wp && window.wp.editor && window.wp.editor.initialize);
	}

	/* A textarea inside a template row has no editor: it is a blueprint, and
	   cloning an initialised editor would copy TinyMCE's own DOM with it. */
	function eligible(textarea) {
		return textarea
			&& textarea.hasAttribute('data-law-rich')
			&& !textarea.closest('[data-law-row-template]');
	}

	/* TinyMCE addresses editors by element id, and wp.editor.initialize() puts
	   that id straight into a jQuery selector, so it has to be plain. Repeater
	   fields are named speakers[3][bio]; their ids are not. */
	function ensureId(textarea) {
		if (!textarea.id || /[^A-Za-z0-9_-]/.test(textarea.id)) {
			textarea.id = 'law-rich-js-' + (++sequence);
		}
		return textarea.id;
	}

	function config() {
		return {
			tinymce: {
				toolbar1: settings.toolbar || 'bold,italic,bullist,numlist,link',
				toolbar2: '',
				toolbar3: '',
				toolbar4: '',
				block_formats: settings.blockFormats || 'Paragraph=p',
				plugins: settings.plugins || 'lists,link,paste,wordpress',
				valid_elements: settings.validElements || undefined,
				content_css: settings.contentCss || [],
				menubar: false,
				statusbar: false,
				branding: false,
				elementpath: false,
				/* ON, and load-bearing: it is what makes the editor read the
				   plain-text descriptions already in the database as paragraphs
				   (wp.editor.autop on load) and write them back in the same shape
				   (wp.editor.removep on save), instead of collapsing a host's line
				   breaks the first time they open an old event. */
				wpautop: true,
				browser_spellcheck: true,
				relative_urls: false,
				remove_script_host: false,
				convert_urls: false,
				height: 220,
				setup: function (editor) {
					// The inline "please fill this in" message clears as soon as
					// the author starts typing, matching the native behaviour the
					// required attribute used to give this field.
					editor.on('keyup change', function () {
						clearError(document.getElementById(editor.id));
					});
				}
			},
			quicktags: false,
			mediaButtons: false
		};
	}

	function init(textarea) {
		if (!ready() || !eligible(textarea)) {
			return;
		}
		var id = ensureId(textarea);
		if (window.tinymce.get(id)) {
			return; // Already ours.
		}
		window.wp.editor.initialize(id, config());
	}

	function remove(textarea) {
		if (!textarea || !textarea.id || !ready()) {
			return;
		}
		var editor = window.tinymce.get(textarea.id);
		if (!editor) {
			return;
		}
		editor.save(); // Content back into the textarea before the editor goes.
		window.wp.editor.remove(textarea.id);
	}

	function initAll(root) {
		var scope = root && root.querySelectorAll ? root : document;
		Array.prototype.forEach.call(scope.querySelectorAll('textarea[data-law-rich]'), init);
	}

	function save() {
		if (window.tinymce && window.tinymce.triggerSave) {
			window.tinymce.triggerSave();
		}
	}

	/* Empty-field messages ____________________________________________________

	   The required attribute cannot be used on a field TinyMCE has hidden: the
	   browser blocks the submit and logs "an invalid form control is not
	   focusable", with nothing on screen to tell the author why. So the check
	   is done here, in the same .law-form-error markup the server-side errors
	   use, and the server validates the field regardless. */

	function wrapper(textarea) {
		return textarea.closest('.law-form-field') || textarea.closest('.law-rich-text') || textarea.parentNode;
	}

	function clearError(textarea) {
		if (!textarea) {
			return;
		}
		var field = wrapper(textarea);
		var message = field && field.querySelector('[data-law-rich-error]');
		if (message) {
			message.remove();
		}
		if (field && field.classList) {
			field.classList.remove('is-invalid');
		}
	}

	function showError(textarea, text) {
		clearError(textarea);
		var field = wrapper(textarea);
		if (!field) {
			return;
		}
		var message = document.createElement('span');
		message.className = 'law-form-error';
		message.setAttribute('role', 'alert');
		message.setAttribute('data-law-rich-error', '');
		message.textContent = text;
		field.appendChild(message);
		if (field.classList) {
			field.classList.add('is-invalid');
		}
	}

	function focusField(textarea) {
		var editor = window.tinymce && window.tinymce.get(textarea.id);
		if (editor && !editor.isHidden()) {
			editor.focus();
		} else {
			textarea.focus();
		}
		var field = wrapper(textarea);
		if (field && field.scrollIntoView) {
			field.scrollIntoView({ block: 'center', behavior: 'smooth' });
		}
	}

	function validate(form) {
		var invalid = null;
		Array.prototype.forEach.call(
			form.querySelectorAll('textarea[data-law-rich][data-law-rich-required]'),
			function (textarea) {
				// &nbsp; is what an emptied editor leaves behind, and it is not
				// whitespace to trim(); the server's own check agrees with this one.
				var value = textarea.value.replace(/<[^>]*>/g, ' ').replace(/&nbsp;| /g, ' ');
				if (value.trim() !== '') {
					clearError(textarea);
					return;
				}
				showError(textarea, textarea.getAttribute('data-law-rich-required'));
				if (!invalid) {
					invalid = textarea;
				}
			}
		);
		return invalid;
	}

	/* Flush before anything reads the form, whether that is the browser posting
	   it or our own fetch layer building a FormData from it. Capture phase, so
	   this runs before the handlers that call preventDefault(). */
	document.addEventListener(
		'submit',
		function (event) {
			var form = event.target;
			if (!form || !form.querySelector || !form.querySelector('textarea[data-law-rich]')) {
				return;
			}
			save();
			// A draft save is allowed to be incomplete, exactly as it is on the
			// server (law_events_form_save() skips the required checks for it).
			// formnovalidate is how "Save draft" already opts out of the browser's
			// own checks, so honouring it keeps the two in step.
			var submitter = event.submitter;
			if (submitter && (submitter.formNoValidate || submitter.value === 'draft')) {
				return;
			}
			var invalid = validate(form);
			if (invalid) {
				event.preventDefault();
				event.stopPropagation();
				focusField(invalid);
			}
		},
		true
	);

	window.lawRichText = {
		init: init,
		remove: remove,
		initAll: initAll,
		save: save
	};

	/* TinyMCE is printed after this file, so wait for the document and then
	   give the bundle a few frames to finish executing before giving up. */
	function start(attempt) {
		if (ready()) {
			initAll(document);
			return;
		}
		if ((attempt || 0) > 60) {
			return; // No rich editor for this visitor: the textareas still work.
		}
		window.setTimeout(function () {
			start((attempt || 0) + 1);
		}, 50);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			start(0);
		});
	} else {
		start(0);
	}
})(window, document);
