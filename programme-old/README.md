# The original programme layout, for reference

`/programme/?variant=old` (and `/committee/programme/?variant=old`) renders the
programme as it was before the day-tabs layout became the default on
11 September 2026: every day stacked under navy day bars, orange slot bars, full
cards, the flagship block under Wednesday somewhere in the middle of a
10,000px page. Kept only so the two can be compared side by side.

```
programme-old.php          the module: ?variant=old reader, template swap,
                           AJAX partial endpoint, stylesheet enqueue
template.php               the page template swapped in
parts/controls.php         the old day links + filters (parts/calendar-filters.php as it was,
                           plus a hidden `variant` field so filtering keeps the old layout)
parts/events.php           the old list (parts/calendar-events.php as it was, verbatim)
assets/programme-old.css   the old rules for what the new layout restyled, scoped to .law-cal--old
```

Plus one line in `functions.php`:

```php
require_once(get_theme_file_path('/programme-old/programme-old.php'));
```

It reuses, unchanged: the data layer in `functions/calendar.php`, the card
partial `parts/loop/event.php`, the flagship block
`parts/events/flagship-card.php`, the hero, and `assets/js/calendar-filters.js`.
No tests of its own: it exists to be looked at, then deleted.

## To remove

```sh
rm -r programme-old
```

and delete the `require_once` line from `functions.php`.
