/**
 * Committee dashboard export buttons (parts of templates/account-dashboard.php).
 *
 * CSV and Excel are plain links to admin-post.php?action=law_committee_export;
 * this script only refreshes their hrefs with the live filter values just
 * before activation, so exports follow filters changed over AJAX without a
 * reload (the server-rendered hrefs already carry the page-load filters, which
 * is the no-JS fallback). The PDF button is JS-only: it fetches format=json
 * from the same endpoint and builds the document client-side with pdfmake,
 * like the legacy GravityView DataTables PDF button did.
 */
(function () {
	'use strict';

	var box = document.querySelector('[data-law-export]');
	if (!box) {
		return;
	}
	// The dashboard has a live filter bar whose values ride along; the
	// bookings list (and any future export surface) has none, and the baked
	// hrefs are already complete.
	var form = document.getElementById('law-cal-filter-form');

	var exportUrl = box.getAttribute('data-export-url');

	// Mirrors filterParams() in calendar-filters.js: each page brings its own
	// filter set, read generically at the moment of use.
	function filterParams() {
		var params = new URLSearchParams();
		if (form) {
			form.querySelectorAll('input[name], select[name]').forEach(function (field) {
				var value = field.value.trim();
				if (value !== '') {
					params.set(field.name, value);
				}
			});
		}
		return params;
	}

	function exportHref(format) {
		var params = filterParams();
		params.set('format', format);
		return exportUrl + '&' + params.toString();
	}

	box.querySelectorAll('a[data-format]').forEach(function (link) {
		function refresh() {
			link.href = exportHref(link.getAttribute('data-format'));
		}
		// mousedown + keydown cover left, middle and keyboard activation
		// before the navigation starts.
		link.addEventListener('mousedown', refresh);
		link.addEventListener('keydown', refresh);
	});

	var pdf = box.querySelector('[data-format="pdf"]');
	if (!pdf || !window.fetch || !window.pdfMake) {
		return;
	}
	pdf.hidden = false;

	pdf.addEventListener('click', function () {
		var label = pdf.textContent;
		pdf.disabled = true;
		pdf.textContent = 'Preparing…';

		fetch(exportHref('json'), { credentials: 'same-origin' })
			.then(function (response) {
				return response.json();
			})
			.then(function (json) {
				if (!json.success) {
					throw new Error((json.data && json.data.message) || 'Export failed');
				}
				var d = json.data;
				var body = [d.columns.map(function (heading) {
					return { text: String(heading), style: 'head' };
				})].concat(d.rows.map(function (row) {
					return row.map(function (cell) {
						return String(cell === null || cell === undefined ? '' : cell);
					});
				}));

				window.pdfMake.createPdf({
					// The dashboard's 17 columns need A3 landscape to stay
					// legible (it still prints on A4 with "fit to page"); the
					// narrower bookings export sits comfortably on A4.
					pageSize: d.columns.length > 10 ? 'A3' : 'A4',
					pageOrientation: 'landscape',
					pageMargins: [20, 24, 20, 28],
					defaultStyle: { fontSize: 7 },
					styles: { head: { bold: true, fillColor: '#eeeeee' } },
					footer: function (page, pages) {
						return { text: page + ' / ' + pages, alignment: 'right', margin: [0, 8, 20, 0], fontSize: 7 };
					},
					content: [
						{ text: d.title, fontSize: 12, bold: true, margin: [0, 0, 0, 8] },
						{
							table: {
								headerRows: 1,
								dontBreakRows: true,
								widths: d.columns.map(function (heading) {
									return heading === 'Title' || heading === 'Sector' ? '*' : 'auto';
								}),
								body: body
							},
							layout: 'lightHorizontalLines'
						}
					]
				}).download(d.filename);
			})
			.catch(function (error) {
				window.alert((error && error.message) || 'Sorry, the PDF export failed. Please reload the page and try again.');
			})
			.finally(function () {
				pdf.disabled = false;
				pdf.textContent = label;
			});
	});
})();
