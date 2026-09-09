# Flagship event: build specification

**Built 9 September 2026.** This document is the specification the build
followed; `EVENTS_FUNC.md` is the living record of the code as it now stands,
and is the one to keep current from here. Three things changed during the build
at Denis's request, and are corrected in place below: the agenda renders as a
**vertical timeline** rather than the accordion (§5.3, §5.10a), the **venue
section sits below the agenda** on this page (§5.3), and the session times read
**12-hour** on both the page and the programme block. The build also found two
bugs in existing code, recorded in §9.

Handoff document for the agent building the flagship conference feature in the
LAW events module. It is self-contained: read it together with `EVENTS_FUNC.md`
(the living code report, which you must update as part of this work) and
`EVENTS_4.2_SPECS.md` §3.6 and §5 (the enhanced agenda and the flagship
application flow, which this feature lays the groundwork for but does not
build).

Line numbers below were checked against the working tree on 9 September 2026
and are pointers, not contracts. Verify each before editing.

House rules that apply to everything here: UK English, sentence case for
headings and labels, no em dashes in copy. First-party JS lives in
`assets/js/`, never `assets/js/vendor/`. Do not touch `wp-content/mu-plugins/`.
Add `.playwright/` and `.playwright-cli/` to `.gitignore` before any browser
test. Browser QA goes to the `test-specialist` agent, opt-in, never the
`browser-tester` agent.

---

## 1. What is being built, and why this shape

The flagship conference (2 December) needs three things on the site:

1. **An editing screen** for the committee: description, location, hero image,
   and a session-by-session agenda where each session has a title, start and
   end time, description and speakers (existing speakers picked by search, or a
   new speaker entered inline).
2. **A public page** at `/events/flagship/` that reads like a single event page:
   hero with title and a details box (date, time, location only), back link,
   description, the agenda as a vertical timeline with speaker cards, then the venue and its
   map, then a back button.
3. **A highlighted block on `/programme/`** under Wednesday 2 December, always
   present, with the image on the left and, on the right, the title, a list of
   session titles with times, and an "Event details" button.

### Decisions already taken with Denis (9 September 2026). Do not reopen them.

- **The flagship is one `law_event` post**, flagged with meta `_law_is_flagship`,
  `post_name` `flagship`, so WordPress gives it the permalink
  `/events/flagship/` (the `law_event` rewrite slug is `events`,
  `functions/events/post-types.php:52`). Its sessions are child `law_session`
  posts, exactly like any other event with an agenda. It is NOT a WordPress
  page and NOT a settings option. Reasons, for the record: a page at
  `/events/flagship` would be swallowed by the CPT rewrite rule (there is no
  `/events` page; the programme lives at `/programme/`, page 622, template
  `templates/calendar.php`); option-stored sessions would be invisible to the
  speaker directory, the speaker cards, `.ics` and the bookings engine, and
  the 4.2 §5 flagship application flow needs a `law_event` post to book
  against.
- **The editing UI is a single wp-admin screen**, "Flagship", a submenu of the
  Events menu. It is the form Denis asked for; only its storage changed.
- **The programme block is always visible under 2 December**, regardless of
  keyword, sector or type filters. That day, and its tab in the day nav, are
  never empty while a published flagship exists.
- **Excluded from the committee dashboard and from hosts' My events.** It stays
  in the wp-admin Events list.
- **Deploy must work instantly.** A git push to another environment must not
  need a manual step: the migration's step 10 and the
  `/wp-admin/?setup-account-pages` trigger both create the flagship post when
  it is missing, and the Flagship screen creates it on first open.
- **Date, time, places, registration.** The date is fixed (2 December of the
  programme year by default, editable). Start and end are computed from the
  sessions. No places count and no booking control on the flagship for now;
  the approval-gated application flow (4.2 §5) is out of scope here.
- **Hero image.** An optional custom image, set on the Flagship screen, is used
  for both the programme block and the hero banner on the flagship page. When
  none is set, both fall back to the theme's default hero photograph, so the
  two always match.

---

## 2. Existing code you will reuse (read these first)

| What | Where | Notes |
|---|---|---|
| Single-event view (hero, details box, description, venue map, sessions accordion, speakers, bio dialogs, back links) | `parts/calendar-body.php` (278 lines) | Caller variables documented in its docblock, lines 5 to 29. `get_header()` is called inside it. Reuse it; do not copy it. |
| Details box in the hero | `parts/calendar-event-details.php` | Args `rows`, `places`, `event`, `preview`. Icon map at lines 52 to 60 keyed by row key. |
| Hero partial | `parts/layout/hero-title.php` | Args `title`, `is_event`, `image`, `classes`, `solid`, `content`, `text`, `after_title`. Default image at line 38: `law_asset( 'assets/images/patrons-and-committee-bg.jpg' )`. |
| Back link | `parts/layout/back-link.php` | Args `url`, `label`. |
| Programme list | `parts/calendar-events.php`, `parts/calendar-filters.php`, `parts/loop/event.php` | Grouping via `law_calendar_events_by_date()` (`functions/calendar.php:968`), week from `law_calendar_week_days()` (`:13`, reads `week_start`/`week_end` settings, falls back to 2026-11-30 to 2026-12-04). The `&law_partial=1` AJAX path (`law_calendar_maybe_render_partial()`, `:274`) re-renders `parts/calendar-events.php` alone. |
| Hydrated event array | `law_events_map_post()` (`functions/events/source.php:50`), `law_events_cpt_hydrate()` (`:297`), `law_calendar_event_by_id()` (`functions/calendar.php:825`) | Keys: `id, title, status, host, venue, tickets, tickets_sold, tickets_remaining, type, sectors, speakers, sessions, excerpt, description, url, is_evening, date, start, end, time_label, unscheduled, is_sponsored, sort`. No image key today. |
| Session rows | `law_event_session_rows( $event_id )` (`functions/events/speakers.php:875`), `law_event_session_ids()` (`:684`) | Row: `id, title, start, end, time_label ("HH:MM–HH:MM"), description, speakers[]`. Ordered by start time, then `menu_order`, then ID. |
| Speaker cards and dialogs | `law_speaker_card()` (`speakers.php:746`), `parts/events/speaker-card.php`, `parts/events/speaker-bio-modal.php`, `law_speaker_dialogs()` (`:861`) | Dialogs must print outside the `<details>` accordion (calendar-body lines 243 to 245 already do this). |
| Speaker upsert | `law_speaker_upsert( array $data, array $log = array(), array $options = array() )` (`speakers.php:232`) | `$data`: `first_name, last_name, email, website, bio, photo_id`. `$log`: `event_id, actor`. Dedupes by email, then normalised name. Returns post ID or 0. |
| Speaker roles | `law_speaker_roles()`, `law_speaker_role_key()` (`speakers.php:27, :44`) | `speaker / host / moderator`; `''` reads as Speaker. |
| Meta read/write | `law_event_meta()`, `law_event_update_meta()` (`functions/events/meta.php:326`) | The single write path. Empty value deletes. Unknown keys refused. `speaker_rows` sanitiser at `:231` keeps `speaker_id, role, organisation, job_title, photo_id, bio, sort`. `time` type at `:186`. |
| Admin field renderers | `functions/events/admin/fields.php` | `law_field_text/number/textarea/select/checkbox/datetime/media/repeater/relationship()`, `law_field_relationship_row()` (`:169`), `law_field_relationship_photo()` (`:230`). AJAX speaker search `wp_ajax_law_events_search_posts` (`:242`, nonce `law_events_admin`, cap `edit_law_events`). Asset enqueue at `:274` to `:304` (versions are a hand-bumped `'1.4'`). |
| Rich text | `functions/events/rich-text.php` | `law_rich_text_field( $args )` (`:180`; args `name, id, value, rows, template, required, label, class`), `law_rich_text_sanitize()`, `law_rich_text_enqueue()` (idempotent). JS API `window.lawRichText = { init, remove, initAll }` (`assets/js/law-rich-text.js:237`). It refuses to initialise a textarea inside `[data-law-row-template]` (`:42`). |
| Admin JS | `assets/js/law-admin.js` | Legacy repeater (lines 9 to 36, inputs only, index bug), relationship picker (39 to 179, bound once at DOM ready, `addItem()` at 75 builds the speaker row markup), media picker (249 to 272). |
| Front-end repeater pattern to copy | `assets/js/event-form.js:13-56, 130-142` | Monotonic `data-law-counter`, renames every `[data-name]`, strips `id` from `textarea[data-law-rich]`, `lawRichText.initAll(row)` after insert, `lawRichText.remove` before delete. |
| Session save to mirror | `law_events_form_save_sessions()` (`functions/events/submission-form.php:685-793`) | Upsert by posted `id` only when already a child; `menu_order` from position; hard-delete removed children. |
| Committee list query | `law_committee_events()` (`functions/events/committee.php:19`), `law_committee_status_counts()` (`:76`), `law_committee_requested_event()` (`:91`) | |
| My events | `law_account_events()` (`functions/account-events.php:37`, CPT loop at 52 to 57) | |
| Setup trigger | `functions/setup-account-pages.php` (`law_setup_account_pages()` at 27, `admin_init` hook at 273) | Guards module calls with `function_exists()` because `functions.php` requires it before the module. |
| Migration step 10 | `functions/events/migration/runner.php` (`law_migration_steps()` at 30, `law_migration_run_pages( $dry )` at 1755, `law_migration_log()`) | |
| Activity log | `law_event_log( $post_id, $message, array $context, array $args )` (`functions/events/log.php`) | Style precedent: `law_event_log_flag_change()` in `workflow.php:479`. |
| Google map helpers | `law_calendar_venue_is_mappable()`, `law_calendar_maps_url()`, `law_calendar_maps_embed_url()` (`functions/calendar.php:1504-1541`) | Keyless embed, free-text venue. |
| Tests | `phpunit.xml`, `tests/bootstrap.php`, `tests/class-law-test-case.php`, `tests/RichTextTest.php` (style) | `vendor/bin/phpunit` from the theme root; integration tests against the local WordPress inside a rolled-back transaction. |

Two gaps in the existing admin tooling that shape the design (verified):

1. `law_field_repeater()` clones `input[data-law-name]` only, so selects,
   textareas and nested pickers inside a template row are never renamed, and
   its `nextIndex()` counts rows, so remove-then-add reuses an index. Do not
   build the sessions repeater on it. Copy `event-form.js`'s pattern instead.
2. The relationship picker JS binds once at DOM ready with no delegation, so a
   picker inside a freshly added session row gets no search, photo or remove
   handlers. Refactor it to be re-callable (§4.5).

---

## 3. Data model

### 3.1 Meta schema (`functions/events/meta.php`)

Add to `law_event_meta_schema()`, after `_law_session_agenda` (line 62), with a
comment block in the file's style:

| Key | Type | Purpose |
|---|---|---|
| `_law_is_flagship` | `flag` | Marks the one flagship post |
| `_law_flagship_date` | `date` (new type) | The fixed date; default `{year}-12-02` from `law_events_setting( 'year' )` |
| `_law_hero_image_id` | `int` | Custom hero and programme-block image (attachment ID) |

Add a `date` case to `law_events_sanitize_value()` next to `time` (line 186):
accept `YYYY-MM-DD` validated with `checkdate()`, else return `''`. While
there, zero-pad the `time` type (`9:30` becomes `09:30`) so string comparison
of times is safe; `law_event_session_ids()` already sorts with `strcmp` on
these strings.

### 3.2 Derived values, recomputed on every save

- `_law_start` = `{date} {earliest session start}`; `_law_end` = `{date}
  {latest session end}` (falling back to the latest start when no session has
  an end, so the event never ends before it starts). Both `''` when no session
  has a start. Format `Y-m-d H:i`.
- `_law_slot_label` is always empty. Never call `law_event_apply_slot_label()`
  for the flagship.
- Event-level `_law_speakers` = deduped union of the sessions' speaker rows, in
  session order, first occurrence of a `speaker_id` wins, `sort` renumbered.
  Because of this, `law_speakers_event_maps()` and
  `law_speaker_appearance_for_event()` (speakers.php) index flagship speakers
  with no read-side change, and flagship speakers appear on the speakers
  archive and their profiles' "Speaking at" once the flagship is published.
- No fee, no invoice, no workflow transition, no `_law_approved_at`, no
  payment meta. Write the post directly with `wp_update_post()` and
  `law_event_update_meta()`. Verified: nothing in the module reacts to a
  `law_event` status change except the nonce-gated admin save handlers and a
  bookings-only `transition_post_status` hook.

### 3.3 Security properties to preserve

- The host form cannot set `_law_is_flagship`: `law_events_form_save()`
  writes a fixed map of named keys (submission-form.php:377 to 415). No code
  change; pin it with a test.
- The wp-admin Event screen's `save_post_law_event` handler exits without its
  own nonce (`law_event_admin_nonce`), so the Flagship screen's POST does not
  trigger it.
- The bookings engine must refuse the flagship explicitly (§4.2), so hiding the
  booking control is not the only defence.

---

## 4. Backend and wp-admin

### 4.1 New file `functions/events/flagship.php`

Require it from `functions/events/_load.php` after `speakers-dashboard.php`
and before `source.php`. Note `law_events_source()` is defined at the bottom
of `_load.php`, so nothing may call it at include time.

```php
const LAW_FLAGSHIP_SLUG = 'flagship';

/** The flagship post ID, 0 if none. Memoised; $reset clears the memo. */
function law_flagship_event_id( $reset = false ): int
// get_posts: post_type LAW_EVENT_CPT, post_status law_event_all_status_keys(),
// meta_key _law_is_flagship, meta_value '1', fields ids, posts_per_page 1,
// orderby ID ASC. Return through apply_filters( 'law_flagship_event_id', $id )
// so tests can pin a fixture.

function law_flagship_is( $post_id ): bool        // (int) $post_id > 0 && === law_flagship_event_id()
function law_flagship_default_date(): string       // law_events_setting( 'year', 2026 ) . '-12-02'
function law_flagship_date( $event_id = 0 ): string
// _law_flagship_date, else substr( _law_start, 0, 10 ), else the default. Y-m-d.
function law_flagship_admin_url(): string          // admin_url( 'edit.php?post_type=law_event&page=law-flagship' )

/** @return array{id:int, created:bool, message:string} */
function law_flagship_ensure_post( $dry = false ): array
```

`law_flagship_ensure_post()` pseudocode:

```
$id = law_flagship_event_id( true );
if ( $id ) return [ $id, false, "Flagship event exists (post {$id}, /events/flagship/)." ];
$clash = get_page_by_path( 'flagship', OBJECT, LAW_EVENT_CPT );  // another law_event holds the slug
if ( $dry ) return [ 0, false, $clash
    ? "Would create the flagship event; post {$clash->ID} already holds the slug, so it would land on /events/flagship-2/."
    : 'Would create the flagship event "Flagship conference" (law-draft, /events/flagship/).' ];
$id = wp_insert_post( wp_slash( [
    'post_type' => LAW_EVENT_CPT, 'post_status' => 'law-draft',
    'post_title' => 'Flagship conference', 'post_name' => LAW_FLAGSHIP_SLUG,
    'post_author' => get_current_user_id(), 'post_content' => '',
] ), true );
if ( is_wp_error( $id ) ) return [ 0, false, 'ERROR ' . $id->get_error_message() ];
law_event_update_meta( $id, '_law_is_flagship', 1 );
law_event_update_meta( $id, '_law_flagship_date', law_flagship_default_date() );
wp_set_object_terms( $id, (string) law_events_setting( 'year', 2026 ), 'law_year', false );
law_flagship_event_id( true );
law_event_log( $id, 'Flagship event created.', [ 'action' => 'flagship_created', 'source' => 'setup' ] );
return [ $id, true, "Created the flagship event (post {$id}, " . get_permalink( $id ) . ")." ];
```

Append a warning to the message if the saved `post_name` is not `flagship`.

```php
/** [start, end] in 'Y-m-d H:i'; both '' when no session has a start. */
function law_flagship_compute_range( $event_id, $date ): array

/** Deduped union of the sessions' speaker rows, first occurrence wins, sort renumbered. */
function law_flagship_union_speakers( $event_id ): array

/** The single derived-data writer: _law_start/_law_end, _law_speakers, clears _law_slot_label, law_speakers_flush_maps(). */
function law_flagship_recompute( $event_id ): void

/** Hero/preview image URL for an event, '' when unset or the attachment is gone. */
function law_event_hero_image_url( $post_id, $size = 'large' ): string
// wp_get_attachment_image_url( (int) law_event_meta( $post_id, '_law_hero_image_id' ), $size ) ?: ''
```

Hooks in the same file, so edits made outside the Flagship screen keep the
derived fields honest:

```php
add_action( 'save_post_' . LAW_SESSION_CPT, function ( $post_id, $post ) { if ( $post->post_parent && law_flagship_is( $post->post_parent ) ) law_flagship_recompute( $post->post_parent ); }, 20, 2 );
add_action( 'deleted_post', /* same test on the deleted post's parent */, 10, 2 );
add_action( 'trashed_post', /* same */ );
```

### 4.2 Bookings guard

In `law_booking_create()` (`functions/events/bookings.php`), refuse an event
for which `law_flagship_is()` is true, returning the engine's usual
`WP_Error` shape with a plain message ("The flagship conference is not booked
through this form."). Log nothing; it is a guard against a crafted request.

### 4.3 New file `functions/events/admin/flagship-screen.php`

Require it from `_load.php` after `admin/session-screen.php`.

**Registration and assets**

```php
add_action( 'admin_menu', 'law_flagship_register_menu', 20 );
function law_flagship_register_menu() {
    add_submenu_page( 'edit.php?post_type=' . LAW_EVENT_CPT, 'Flagship event', 'Flagship',
        'edit_law_events', 'law-flagship', 'law_flagship_screen' );
}
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( 'law_event_page_law-flagship' !== $hook ) return;
    law_rich_text_enqueue();   // idempotent; do not rely on fields.php's post_type inference
    wp_enqueue_script( 'law-flagship-admin', get_theme_file_uri( 'assets/js/law-flagship-admin.js' ),
        array( 'law-events-admin', 'law-rich-text' ), '1.0', true );
} );
```

Capability is `edit_law_events`, not `manage_options`: the committee role
holds the whole `law_event` capability set (capabilities.php:32 to 56) and the
speaker search AJAX already checks `edit_law_events`. WordPress builds a CPT's
submenu (All, Add new, taxonomies, the `show_in_menu` CPTs) before `admin_menu`
fires, so any priority appends Flagship after Programme years. The
`fields.php:274-304` enqueue already loads wp.media, `law-admin.js` and
`law-admin.css` on this hook because it contains `law-`.

**Controller**

```php
function law_flagship_screen(): void
```

```
if ( ! current_user_can( 'edit_law_events' ) ) wp_die( 'Sorry, you are not allowed to access this page.' );
if ( ! law_flagship_event_id() ) law_flagship_ensure_post();          // self-provision on first open
$event_id = law_flagship_event_id();
$errors = null; $values = null;
if ( isset( $_POST['law_flagship_nonce'] ) ) {
    check_admin_referer( 'law_flagship_save', 'law_flagship_nonce' );
    $values = law_flagship_input_from_post();
    $result = law_flagship_save( $values, get_current_user_id() );
    if ( is_wp_error( $result ) ) { $errors = $result; }              // re-render the POSTED values
    else { wp_safe_redirect( add_query_arg( 'law_saved', '1', law_flagship_admin_url() ) ); exit; }  // PRG
}
if ( null === $values ) $values = law_flagship_form_values( $event_id );
if ( ! empty( $_GET['law_saved'] ) ) print a notice-success "Flagship event saved."
if ( $errors ) print one notice-error per message
law_flagship_render_form( $values, $event_id );
```

Redirect after POST (unlike `law_events_settings_page()`, which echoes inline)
because this save inserts posts and a refresh must not re-post.

**Values array** (one shape shared by the reader, the renderer and the saver):

```
[
  'title' => string, 'description' => string (HTML), 'date' => 'Y-m-d', 'venue' => string,
  'hero_image_id' => int, 'show' => bool, 'sessions_present' => bool,
  'sessions' => [ [ 'id' => int, 'title', 'start', 'end', 'description',
      'speakers' => [ [ 'speaker_id' => int, 'is_new' => bool, 'first_name', 'last_name', 'email', 'website',
                        'role', 'organisation', 'job_title', 'photo_id' => int, 'bio' ], ... ] ], ... ]
]
```

`law_flagship_form_values( $event_id )` reads `post_title`, `post_content`,
`law_flagship_date()`, `_law_venue`, `_law_hero_image_id`,
`'publish' === post_status`, and sessions from `law_event_session_ids()`
reading title, content, `_law_start_time`, `_law_end_time`, `_law_speakers`
directly (not `law_event_session_rows()`, which returns display cards).

**Screen layout** (top to bottom; put the title and the `sessions_present`
sentinel BEFORE the repeater so a truncated POST cannot read as "no
sessions"):

1. Title (`law_field_text( 'law_flagship[title]', 'Title', … )`), required.
2. Hidden `law_flagship[sessions_present]` = 1.
3. Description (`law_rich_text_field()`), the same editor as the screenshot
   Denis supplied (Paragraph select, bold, italic, lists, quote, link).
4. Date (`law_field_text( …, [ 'type' => 'date' ] )`), default
   `law_flagship_default_date()`, hint "2 December by default".
5. Location (`law_field_text( 'law_flagship[venue]', 'Location', … )`), hint
   "Shown in the details box and on the map."
6. Hero / preview image (`law_field_media( 'law_flagship[hero_image_id]', …
   )`), hint "Used on the programme block and as the banner on the flagship
   page. Leave empty to use the default banner photograph."
7. "Show on the programme" (`law_field_checkbox( 'law_flagship[show]', … )`).
8. Sessions repeater (below).
9. `wp_nonce_field( 'law_flagship_save', 'law_flagship_nonce' )`,
   `submit_button( 'Save flagship event' )`.

**POST field scheme** (template placeholders: `__i__` session index, `__j__`
speaker index, `__name__` picker base name inside the new-speaker template):

```
law_flagship[title] [description] [date] [venue] [hero_image_id] [show] [sessions_present]
law_flagship[sessions][i][id] [title] [start] [end] [description]
law_flagship[sessions][i][speakers][j][speaker_id] [role] [organisation] [job_title] [photo_id] [bio]
law_flagship[sessions][i][speakers][j][is_new] [first_name] [last_name] [email] [website]   (new-speaker rows only)
```

**Sessions repeater markup**

- Wrapper: `<div class="law-flagship-sessions" data-law-flagship-sessions data-law-counter="N">`.
- One session: `<div class="law-row law-flagship-session" data-law-row>` with a
  `.law-flagship-session__head` holding Title (required), Start time
  (`type=time`, required), End time (`type=time`), then Description
  (`law_rich_text_field()`), then the speaker picker, then a
  `button.button-link-delete.law-row-remove` (×) top right.
- The template row is the last one, with `data-law-row-template hidden`. That
  exact attribute matters: `law-rich-text.js` refuses to initialise editors
  inside it. Do NOT use `law_field_repeater()`'s `data-law-template`.
- Template fields carry `data-name` (with `__i__`), live rows carry `name`.
  `law_rich_text_field( [ 'template' => $is_template ] )` already switches to
  `data-name`.
- Add button: `<button type="button" class="button law-row-add">Add session</button>`.
- Section heading and hint, mirroring the host form's Session agenda copy:
  "Sessions" / "Break the day into sessions. Each session lists its own
  speakers; search for an existing speaker, or add a new one."

**Speaker picker per session**

Reuse `law_field_relationship( $name, 'Speakers', $rows, LAW_SPEAKER_CPT, false, $args )`
with the new `$args` (§4.4): `render_row` dispatches `is_new` rows to
`law_flagship_render_new_speaker_row()` and everything else to
`law_field_relationship_row()`; `after_list` is
`<button type="button" class="button-link law-rel-add-new">Add new speaker</button>`.
The picker's `data-law-rel-name` on the template row is
`law_flagship[sessions][__i__][speakers]`, rewritten by the JS on clone.

**New-speaker row** (`law_flagship_render_new_speaker_row( $name, $j, array $row )`,
where `$j` may be an int or the string `__j__`; build names as strings, do not
route through `law_field_relationship_photo()`, which `(int)`-casts the index):

```
<li class="law-rel-item is-new">
  <span class="law-rel-title">New speaker</span>
  <input type="hidden" name="{name}[{j}][is_new]" value="1">
  <span class="law-rel-new-fields">
    first_name (text, required*)  last_name (text, required*)  email (type=email)  website (type=url)
  </span>
  role select (law_speaker_roles(), blank "Select role" first)
  organisation (text, placeholder "Organisation at this event")
  job_title (text, placeholder "Job title at this event")
  photo span (same markup as law_field_relationship_photo(): hidden photo_id, thumb, Choose photo, Remove photo)
  rich-text bio (same markup as law_rich_text_field(): span.law-rich-text > textarea.law-rich-text__area.law-rel-bio[data-law-rich])
  <button type="button" class="button-link-delete law-rel-remove" aria-label="Remove">×</button>
</li>
```

Hint under the identity fields: "Add the email if you have it: it links the
row to an existing profile with that address instead of creating a duplicate."

One page-level `<template id="law-flagship-new-speaker">` at the end of the
form, rendered by the same function with `__name__` and `__j__`; content
inside `<template>` is inert, so TinyMCE never touches it.

**Reader**

```php
function law_flagship_input_from_post(): array
function law_flagship_sessions_from_post( array $raw ): array
function law_flagship_speaker_rows_from_post( array $raw ): array
```

- `$raw = wp_unslash( (array) ( $_POST['law_flagship'] ?? array() ) )` once,
  recursively. Never unslash again per value.
- `title`, `venue`, `organisation`, `job_title`: `sanitize_text_field`.
  `description`, `bio`: `law_rich_text_sanitize()`. `date`:
  `law_events_sanitize_value( $v, 'date' )`. `start`, `end`:
  `law_events_sanitize_value( $v, 'time' )` (bad values become `''` and
  validation then reports them). `hero_image_id`, `id`, `speaker_id`,
  `photo_id`: `absint`. `show`, `sessions_present`, `is_new`: `! empty()`.
  `role`: `law_speaker_role_key()`. `email`: `sanitize_email`. `website`:
  `esc_url_raw`.
- `sessions`: `array_values()` of array rows; drop a row with every field blank
  and no speakers. `speakers`: `array_values()`; drop a row with neither
  `speaker_id` nor `is_new`.
- Do NOT pass speaker rows through the `speaker_rows` sanitiser at read time
  (it would drop `is_new` rows, which have no `speaker_id`). That sanitiser
  runs inside `law_event_update_meta()` at write time, after new speakers
  have been resolved to IDs. `law_events_rows_from_post()`
  (event-screen.php:511) is flat and unsuitable here.

**Validation**

```php
function law_flagship_validate( array $input ): WP_Error   // no errors = valid
```

Plain-language messages, sessions numbered from 1:

- Title empty: "Give the flagship event a title."
- Date empty or invalid: "Enter the date as YYYY-MM-DD."
- Per session: title empty: "Session N needs a title."; start empty: "Session
  N needs a start time (HH:MM)."; end set and before start: "Session N ends
  before it starts."
- Per new-speaker row: first or last name blank: "Session N, new speaker:
  first and last name are both required."; email present but `! is_email()`:
  "Session N, new speaker: that email address is not valid."
- Per existing-speaker row whose `speaker_id` is not a `law_speaker` post:
  "Session N: speaker #ID no longer exists." (a forged or stale ID is refused,
  not silently dropped).

Validation runs before any write. A failed save changes nothing and re-renders
the posted values.

**Saver**

```php
/** @return int|WP_Error The flagship post ID. */
function law_flagship_save( array $input, $actor )
```

```
$errors = law_flagship_validate( $input ); if ( $errors->has_errors() ) return $errors;
$event_id = law_flagship_event_id() ?: law_flagship_ensure_post()['id'];
if ( ! $event_id ) return new WP_Error( 'law_flagship_missing', 'The flagship event could not be created.' );
$before = law_flagship_snapshot( $event_id );
$updated = wp_update_post( wp_slash( [
    'ID' => $event_id, 'post_title' => $input['title'], 'post_content' => $input['description'],
    'post_status' => $input['show'] ? 'publish' : 'law-draft', 'post_name' => LAW_FLAGSHIP_SLUG,
] ), true );
if ( is_wp_error( $updated ) ) return $updated;
law_event_update_meta( $event_id, '_law_is_flagship', 1 );          // re-asserted
law_event_update_meta( $event_id, '_law_flagship_date', $input['date'] );
law_event_update_meta( $event_id, '_law_venue', $input['venue'] );
law_event_update_meta( $event_id, '_law_hero_image_id', $input['hero_image_id'] );
if ( $input['sessions_present'] ) law_flagship_save_sessions( $event_id, $input['sessions'], $actor );
law_flagship_recompute( $event_id );
law_flagship_event_id( true );
law_flagship_log_save( $event_id, $before, law_flagship_snapshot( $event_id ), $actor );
return $event_id;
```

```php
/** @return int[] Kept session IDs. */
function law_flagship_save_sessions( $event_id, array $sessions, $actor ): array
```

Mirror of `law_events_form_save_sessions()` (submission-form.php:683 to 792):

```
$owned = law_event_session_ids( $event_id ); $kept = []; $position = 0;
foreach ( $sessions as $row ) {
    $session_id = ( $row['id'] && in_array( $row['id'], $owned, true ) && ! in_array( $row['id'], $kept, true ) ) ? $row['id'] : 0;
    if ( $session_id ) {
        $kept[] = $session_id;   // before the write, as the host form does
        $r = wp_update_post( wp_slash( [ 'ID' => $session_id, 'post_title' => $row['title'], 'post_content' => $row['description'], 'menu_order' => $position ] ), true );
        if ( is_wp_error( $r ) ) { law_event_log( $event_id, …, [ 'action' => 'session_save_failed' ] ); $position++; continue; }
    } else {
        $session_id = wp_insert_post( wp_slash( [ 'post_type' => LAW_SESSION_CPT, 'post_status' => 'publish', 'post_parent' => $event_id,
            'post_title' => $row['title'], 'post_content' => $row['description'], 'menu_order' => $position ] ), true );
        if ( is_wp_error( $session_id ) ) { log; $position++; continue; }
        $kept[] = $session_id;
    }
    $position++;
    law_event_update_meta( $session_id, '_law_start_time', $row['start'] );
    law_event_update_meta( $session_id, '_law_end_time', $row['end'] );
    law_event_update_meta( $session_id, '_law_speakers', law_flagship_resolve_speaker_rows( $row['speakers'], $event_id, $actor ) );
}
foreach ( array_diff( $owned, $kept ) as $orphan ) wp_delete_post( $orphan, true );
return $kept;
```

```php
function law_flagship_resolve_speaker_rows( array $rows, $event_id, $actor ): array
```

```
$out = [];
foreach ( $rows as $row ) {
    if ( $row['is_new'] ) {
        $id = law_speaker_upsert(
            [ 'first_name' => …, 'last_name' => …, 'email' => …, 'website' => …, 'bio' => $row['bio'], 'photo_id' => $row['photo_id'] ],
            [ 'event_id' => $event_id, 'actor' => $actor ]
        );                       // gap-fill mode: photo becomes the profile's featured image only if it has none
        if ( ! $id ) continue;   // 0 only for an empty name, which validation refused
    } else { $id = $row['speaker_id']; }
    $out[] = [ 'speaker_id' => $id, 'role' => $row['role'], 'organisation' => …, 'job_title' => …, 'photo_id' => …, 'bio' => …, 'sort' => count( $out ) ];
}
return $out;   // law_event_update_meta()'s speaker_rows sanitiser finishes the job
```

After a successful save a new-speaker row re-renders as an existing speaker
(with its ID), so `is_new` never survives a round trip. After a failed save it
re-renders as a new-speaker row with the typed values.

**Activity log**

```php
function law_flagship_snapshot( $event_id ): array   // title, status, date, venue, hero id, session titles, union speaker IDs
function law_flagship_log_save( $event_id, array $before, array $after, $actor ): void
```

One `law_event_log()` line per save that changed something, in plain words:
"Flagship event saved: shown on the programme; date 2026-12-02 → 2026-12-03;
2 sessions added (Opening keynote, Panel); speaker added Jane Doe." Context
`[ 'action' => 'flagship_saved', 'source' => 'wp-admin', 'changes' => [...] ]`,
args `[ 'user_id' => $actor ]`. The status words ("Shown on the programme." /
"Hidden from the programme.") always appear when the status changed. No line
when nothing changed.

### 4.4 `functions/events/admin/fields.php`

- Extend `law_field_relationship( $name, $label, array $rows, $post_type,
  $simple = false, array $args = array() )`: `$args['render_row']`, a
  `callable( $name, $i, array $row )` replacing `law_field_relationship_row()`
  for every row; `$args['after_list']`, escaped HTML printed after `</ol>`.
  Move the existing `if ( ! $id ) continue;` into the default (no
  `render_row`) branch, so a new-speaker row survives a failed-validation
  re-render. The two existing callers (event-screen.php:256,
  session-screen.php:34) are unchanged.
- Bump the `'1.4'` versions of `law-events-admin` style and script (lines 291
  to 292) to `'1.5'`.

### 4.5 `assets/js/law-admin.js` refactor

Wrap the relationship picker bindings (lines 39 to 179) in `initRel(picker)`
and the media picker (249 to 272) in `initMedia(field)`:

```js
function initRel(picker) {
    if (picker.hasAttribute('data-law-rel-ready') || picker.closest('[data-law-row-template]')) { return; }
    picker.setAttribute('data-law-rel-ready', '1');
    /* existing body unchanged */
}
function initMedia(field) { /* same guard with data-law-media-ready */ }
function initAll(root) {
    (root || document).querySelectorAll('[data-law-rel]').forEach(initRel);
    (root || document).querySelectorAll('[data-law-media]').forEach(initMedia);
}
initAll(document);
window.lawAdminFields = { initRel: initRel, initMedia: initMedia, initAll: initAll };
```

The template-row skip matters: a picker bound inside the hidden template would
clone with its `ready` marker and never get handlers. Keep `rowIndex()` as is.
Leave the legacy `[data-law-repeater]` block (lines 9 to 36) alone; the event
screen's people and contacts boxes still use it.

### 4.6 New file `assets/js/law-flagship-admin.js`

Modelled on `event-form.js` lines 13 to 56 and 130 to 142.

```
var wrap = document.querySelector('[data-law-flagship-sessions]'); if (!wrap) return;
var template = wrap.querySelector('[data-law-row-template]');

nextIndex(): parseInt(wrap.dataset.lawCounter) or, when absent, 1 + the max N found in
    existing rows' name="law_flagship[sessions][N]..." (a re-render after a failed save is
    non-contiguous); then store index + 1 back on the wrapper.

addSession():
    row = template.cloneNode(true); remove data-law-row-template and hidden
    row.querySelectorAll('[data-name]')          -> name = data-name.replace('__i__', i); reset value / checked / selectedIndex (as event-form.js)
    row.querySelectorAll('[data-law-rel-name]')  -> attribute .replace('__i__', i)
    row.querySelectorAll('[data-law-rel-ready],[data-law-media-ready]') -> remove the markers (defensive)
    row.querySelectorAll('textarea[data-law-rich]') -> removeAttribute('id')
    wrap.insertBefore(row, template)
    window.lawRichText && lawRichText.initAll(row)
    window.lawAdminFields && lawAdminFields.initAll(row)
    focus the title input

Delegated click on wrap:
    .law-row-remove  -> row.querySelectorAll('textarea[data-law-rich]').forEach(lawRichText.remove); row.remove()
    .law-rel-add-new -> picker = target.closest('[data-law-rel]'); base = picker.getAttribute('data-law-rel-name');
                        j = chosen.children.length + Date.now() % 1000;
                        html = document.getElementById('law-flagship-new-speaker').innerHTML.split('__name__').join(base).split('__j__').join(j);
                        li = build from html; strip id from textarea[data-law-rich]; chosen.appendChild(li);
                        lawRichText.initAll(li); focus first name
    (.law-rel-remove and the photo buttons are handled by the picker's own listener from initRel; the new row shares the classes)
```

No reorder controls: `law_event_session_ids()` orders by start time and only
uses `menu_order` (written from DOM order) to break ties.

### 4.7 `assets/css/law-admin.css`

Append a small block; keep it to these selectors:

```css
.law-flagship-form { max-width: 960px; }
.law-flagship-session { position: relative; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; padding: 12px 16px 4px; margin: 0 0 12px; }
.law-flagship-session__head { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.law-flagship-session__head .law-field { flex: 1 1 240px; margin: 0 0 12px; }
.law-flagship-session__head .law-field--time { flex: 0 0 120px; }
.law-flagship-session .law-row-remove { position: absolute; top: 8px; right: 8px; }
.law-rel-item.is-new { border-left: 3px solid #dba617; }
.law-rel-new-fields { flex: 1 0 100%; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
.law-flagship-hero .law-media-preview img { max-width: 240px; height: auto; }
```

### 4.8 Exclusions and guards elsewhere

- `functions/events/committee.php`:
  - `law_committee_events()` (line 19): before `get_posts`, `$flagship =
    law_flagship_event_id(); if ( $flagship ) $query['post__not_in'] = array(
    $flagship );`. The export's `$overrides` only touches `posts_per_page`, so
    the exclusion survives the `array_merge`. Do not use a `NOT EXISTS`
    meta_query here: a caller-supplied `meta_query` replaces the filters (see
    the comment at lines 68 to 71).
  - `law_committee_status_counts()` (line 76) uses `wp_count_posts()`; subtract
    one from the flagship's current status so the status chips stay right.
  - `law_committee_requested_event()` (line 91): if the requested event is the
    flagship, refuse with a notice "The flagship conference is edited on the
    Flagship screen." linking to `law_flagship_admin_url()`.
- `functions/account-events.php`, `law_account_events()` CPT loop (lines 52 to
  57): `if ( function_exists( 'law_flagship_is' ) && law_flagship_is( $post_id
  ) ) continue;`. Leave `law_events_owned_event_ids()` (co-owners.php) alone;
  it is an ownership helper, not a listing.
- `functions/events/admin/event-screen.php`, `law_event_admin_save()` (line
  290): when `law_flagship_is( $post_id )`, skip the `_law_slot_label`,
  `_law_start`, `_law_end` keys in the `$plain` map (around lines 358 to 368)
  and the `law_event_apply_slot_label()` call, and call
  `law_flagship_recompute( $post_id )` at the end instead. Add an
  `admin_notices` line on the flagship's `post.php` screen: "This is the
  flagship event. Edit it on the Flagship screen." with the link.
- Do NOT exclude the flagship from `law_events_cpt_mapped_events()`
  (source.php) or from the wp-admin Events list (`admin/columns.php`).

### 4.9 Provisioning: deploy works instantly

- `functions/setup-account-pages.php`, inside `law_setup_account_pages()`
  after the ACCESS report lines (around line 118):

  ```php
  if ( function_exists( 'law_flagship_ensure_post' ) ) {
      $flagship = law_flagship_ensure_post();
      $report[] = ( $flagship['created'] ? 'CREATED  ' : 'OK       ' ) . '/events/flagship/ ' . $flagship['message'];
  }
  ```

  Not gated on `law_events_source()`: a dormant flagship post is harmless
  before cutover and lets the committee fill the screen in advance. Update the
  file's docblock (lines 2 to 16) to mention the flagship post.
- `functions/events/migration/runner.php`: relabel step 10 (line 30) to
  `'Step 10: account page templates and the flagship event'` and the section
  comment near line 1724; at the end of `law_migration_run_pages( $dry )`
  (before the `return` near line 1825):

  ```php
  if ( function_exists( 'law_flagship_ensure_post' ) ) {
      $flagship = law_flagship_ensure_post( $dry );
      law_migration_log( 'pages', $dry ? 'dry-run' : ( $flagship['created'] ? 'created' : 'skipped' ), '/events/flagship/', $flagship['message'] );
      if ( $flagship['created'] ) $created++;
  }
  ```

  Extend the step's summary string ("%d templates assigned, %d pages created,
  flagship event %s."). Re-runs hit the `skipped` branch.
- The Flagship screen self-provisions on first open (§4.3). Rewrites need
  nothing: the permalink is the CPT's own.

---

## 5. Front end

### 5.1 Routing and mapping: `functions/events/source.php`

- `template_include` filter (lines 453 to 461): before the `event-single.php`
  return, add

  ```php
  if ( function_exists( 'law_flagship_is' ) && law_flagship_is( get_queried_object_id() ) ) {
      $flagship = get_theme_file_path( 'templates/flagship-event.php' );
      if ( file_exists( $flagship ) ) return $flagship;
  }
  ```

- `law_events_map_post()` (lines 75 to 126):
  - `$is_flagship = function_exists( 'law_flagship_is' ) && law_flagship_is( $post->ID );`
  - `date`: `$start ? substr( $start, 0, 10 ) : ( $is_flagship ? law_flagship_date( $post->ID ) : '' )`.
  - The no-start time label becomes `$is_flagship ? 'Times to be announced' : 'Slot not confirmed'`.
  - Add `'is_flagship' => $is_flagship` to the returned array.
  - Effect: a published flagship with no sessions is no longer dropped by the
    "public calendar hides unscheduled events" rule (lines 87 to 89), its
    `sort` uses its date, and `unscheduled` is false. `law_calendar_map_entry()`
    (GF mode) needs nothing; consumers read `! empty( $event['is_flagship'] )`.

### 5.2 New `templates/flagship-event.php` (no `Template Name:` header)

```php
<?php
/**
 * The flagship conference's single page. Swapped in by the template_include
 * filter in functions/events/source.php for the one law_event post flagged
 * _law_is_flagship; never offered in the page template dropdown.
 */
$_GET['event']        = (string) get_the_ID();  // the SEO title filters resolve the event via law_calendar_requested_event_id()
$law_cal_show_status  = false;
$law_cal_details_rows = array( 'date', 'time', 'venue' );
$law_cal_no_booking   = true;
require get_theme_file_path( 'parts/calendar-body.php' );
```

Do not pass `$law_cal_back`: `law_calendar_url()` (calendar.php:326 to 331)
already handles `is_singular( LAW_EVENT_CPT )`, and a label override would
apply to both the top link ("Back to programme") and the foot button ("Back to
events calendar"), which must differ.

### 5.3 `parts/calendar-body.php` (reused, two new caller variables)

- Docblock (lines 5 to 29): add
  - `$law_cal_details_rows (string[]|null)`: allow-list of details-box row keys
    (`date`, `time`, `venue`, `host`, `type`, `sector`); null or unset = all.
  - `$law_cal_no_booking (bool)`: the details box renders neither the booking
    control nor the places fallback. The flagship is approval-gated with its
    own flow (4.2 §5), so nothing here may offer to book it.
- After line 38: normalise both (`isset && is_array ? : null`, `! empty`).
- Line 94: add `'booking' => ! $law_cal_no_booking` to the details part args.
- Line 98 (time row): `'value' => $event['date'] ? law_calendar_event_time_label( $event ) : ''`.
  `law_calendar_event_time_label()` returns `time_label` when `start` is
  empty, so the flagship shows "Times to be announced"; an ordinary event is
  unchanged (a CPT event with a date always has a start).
- Lines 96 to 103: build the rows into an array first, then if
  `null !== $law_cal_details_rows` keep only rows whose `key` is in it.
- After `$hero_args` (lines 107 to 111):

  ```php
  if ( 'cpt' === law_events_source() && function_exists( 'law_event_hero_image_url' ) ) {
      $law_hero_image = law_event_hero_image_url( $event['id'], 'large' );
      if ( '' !== $law_hero_image ) $hero_args['image'] = $law_hero_image;
  }
  ```

  Omitting `image` keeps hero-title.php's default. The `cpt` guard matters: in
  GF mode `$event['id']` is an entry ID.
- **As built, two more caller variables were added** for changes Denis asked
  for while it was going in: `$law_cal_sessions_style` ('accordion' by default,
  'timeline' for the flagship) and `$law_cal_venue_last` (the venue below the
  sessions rather than above). The venue markup moved out to
  `parts/events/event-venue.php` so the two orders share one piece of markup.
  The flagship's order is therefore description → agenda timeline → venue →
  back button.
- Nothing else changes: description (162), speakers-only-when-no-sessions,
  dialogs, foot button all render as for any single event.

### 5.4 `parts/calendar-event-details.php`

- Docblock: `booking (bool) Default true. False renders neither the booking control nor the places fallback row.`
- After line 40: `$law_ed_booking = ! array_key_exists( 'booking', $args ) || ! empty( $args['booking'] );`
- Line 81: `if ( $law_ed_booking && $law_ed_event && function_exists( 'law_booking_render_action' ) )`
- Line 100: `if ( $law_ed_booking && '' === $law_ed_cta && '' !== $law_ed_places_value )`

An arg rather than passing an empty `event` keeps the venue row's link to
`#law-cal-venue-heading` (lines 94 to 97, 131 to 138), which reads
`event.venue`.

### 5.5 Hero default in one place

- `functions/helpers.php`: `law_hero_default_image_url()` returning
  `law_asset( 'assets/images/patrons-and-committee-bg.jpg' )`.
- `parts/layout/hero-title.php:38` uses it. `parts/events/flagship-card.php`
  falls back to it. Check the bundled JPEG's pixel width; if the hero would be
  softer with `large` (1024px) than it is today, use `full`.

### 5.6 Programme list: `functions/calendar.php`

- `law_calendar_flagship_event( $reset = false )`: memoised per
  `law_calendar_context()`. In CPT mode returns
  `law_calendar_event_by_id( law_flagship_event_id() )`, else `null`. The
  resolver applies the public status filter off the committee template (line
  840), so an unpublished flagship is `null` on the public programme and
  present (with its status badge) on the committee calendar. Hydration
  (sessions, speakers, `the_content`) happens once per request.
- `law_calendar_events()` (lines 415 to 419): inside the CPT loop,
  `if ( ! empty( $mapped['is_flagship'] ) ) continue;`. Everything downstream
  (`law_calendar_events_by_date()`, the unscheduled bucket, per-day counts)
  derives from it, so the flagship never appears as an ordinary card, in a
  slot bar or under "No confirmed slot".
- `law_calendar_day_is_empty( $date )`: false if
  `law_calendar_events_by_date()[ $date ]` is non-empty, else false if
  `law_calendar_flagship_event()` exists and its `date` equals `$date`, else
  true. Used by BOTH `parts/calendar-events.php:38` and
  `parts/calendar-filters.php:26`, which today compute emptiness separately.
- Add `$reset = false` parameters to `law_calendar_events()`,
  `law_calendar_filters()` and `law_calendar_event_by_id()` (or one
  `law_calendar_reset_caches()` that clears all three) so the render tests can
  run in one process. Precedent: `law_speakers_event_maps( …, $reset )`.
- Filter facets are taxonomy term lists (`law_events_cpt_field_choices()`), not
  event-derived, so nothing changes there. There is no speaker filter on the
  programme.

### 5.7 `parts/calendar-events.php`

- After line 20: `$law_flagship = law_calendar_flagship_event(); $law_flagship_date = $law_flagship ? (string) $law_flagship['date'] : '';`
- Leave `$law_has_events` (23 to 29) as is: with a keyword that matches
  nothing the page prints "No events match this search." and then the pinned
  block under its day. That is honest.
- Line 38: `if ( law_calendar_day_is_empty( $law_date ) ) continue;`
- After line 43 (under the navy `.law-cal-day-bar`, before the first orange
  slot bar): `if ( $law_flagship && $law_date === $law_flagship_date ) get_template_part( 'parts/events/flagship-card', null, array( 'event' => $law_flagship, 'show_status' => $law_show_status ) );`
- Safety net before the `foreach` at line 36: if `$law_flagship` exists but
  `$law_flagship_date` is not a key of `$law_days` (the week was moved in LAW
  > Events settings), render the block once above the day loop so it cannot
  vanish silently.
- The `&law_partial=1` AJAX path renders this same part, so filtering keeps
  the block; `updateDayNav()` in `assets/js/calendar-filters.js` looks for
  `#day-<date>` in the returned markup, so the 2 Dec tab un-greys correctly.

### 5.8 `parts/calendar-filters.php`

Line 26: `$law_day_empty = law_calendar_day_is_empty( $law_date );`

### 5.9 Committee calendar

Nothing extra: `templates/calendar-committee.php` requires the same body,
`show_status` reaches the card, and the resolver returns the flagship in any
non-draft status there. The card prints `law_calendar_status_badge( $event )`
and `law_calendar_edit_link( $event )` when `show_status` is set, as
`parts/loop/event.php:89-97` does.

### 5.10 New `parts/events/flagship-card.php`

Args: `event` (hydrated array, required), `show_status` (bool). Markup:

```
<article class="law-flagship-card" aria-labelledby="law-flagship-card-title">
  <div class="law-flagship-card__media">
    <img src="{law_event_hero_image_url( id, 'medium_large' ) or law_hero_default_image_url()}"
         alt="{attachment alt, or ''}" loading="lazy">
  </div>
  <div class="law-flagship-card__body">
    {law_calendar_status_badge( $event ) when show_status}
    <span class="law-flagship-card__badge">Flagship event</span>
    <h3 id="law-flagship-card-title" class="law-flagship-card__title">
      <a href="{event.url}">{title}</a> {law_calendar_edit_link( $event ) when show_status}
    </h3>
    <p class="law-flagship-card__meta">{law_calendar_event_time_label( $event )} · {venue}</p>
    <ul class="law-flagship-card__sessions">              (only when sessions exist)
      <li>
        <span class="law-flagship-card__session-time">{session.time_label}</span>
        <span class="law-flagship-card__session-title">{session.title, or "Session" when blank}</span>
      </li>
    </ul>
    <p class="law-flagship-card__meta">Programme to be announced</p>   (when there are no sessions)
    <div class="law-event-card__actions law-flagship-card__actions">
      <a class="button law-event-card__button" href="{event.url}">Event details</a>
    </div>
  </div>
</article>
```

`h3` sits correctly under the day's `h2` and alongside the slot bars' `h3`.
Session `time_label` comes from `law_event_session_rows()` ("HH:MM–HH:MM"),
matching the accordion on the single page. Reusing `.law-event-card__actions`
gives the existing mobile full-width button rule (calendar.css:434 to 438).
One label only: do not also print `law_event_law_badge()`.

### 5.10a `parts/events/session-timeline.php` (as built)

The accordion suits an event with two or three sessions a reader chooses to
open; a day-long conference agenda is the content, and collapsing eight items
behind summaries hides the whole programme. So the flagship renders every
session open on a vertical timeline. The pattern, from the established practice
for conference agendas:

- An **ordered list** (`<ol>` of `<li>`), because the agenda is a sequence, and
  real `<time datetime="09:30">9:30am</time>` elements: machine-readable, with
  the 12-hour label as the text, matching the event's own Time fact and the
  programme block.
- **One left-aligned rail**, never cards alternating either side of a centre
  line. The alternating variant breaks down on a phone and costs the reader the
  single vertical line their eye follows down a schedule.
- The line and markers are **CSS pseudo-elements**, because they are
  decoration; the time beside them is the information.
- A **break** (registration, coffee, lunch) gets a smaller hollow marker and
  lighter type, so the shape of the day reads at a glance. The part infers it
  from a session having neither a description nor a speaker, rather than from a
  field nobody would maintain.
- Mobile-first: one column with the rail at the far left and the time above the
  title; from 48em the time moves into its own right-aligned column with the
  rail between the two. The geometry is three custom properties
  (`--law-tl-time`, `--law-tl-gap`, `--law-tl-dot`) so the marker and the line
  cannot drift from the column width. The time column is 9.75rem, wide enough
  to keep a 12-hour range on one line.

### 5.11 `assets/css/calendar.css`

Add after the event-card block (around line 451). Brand colours: navy
`#292459`, orange `#ef7d05`, grey text `#666`.

```css
.law-flagship-card { display: flex; flex-direction: column; margin: 0 0 1.5rem; background: #292459; color: #fff; border: 2px solid #292459; }
.law-flagship-card__media img { display: block; width: 100%; height: 100%; object-fit: cover; aspect-ratio: 16 / 9; }
.law-flagship-card__body { flex: 1 1 auto; min-width: 0; padding: 1.5rem 1.75rem; }
.law-flagship-card__badge { display: inline-block; margin: 0 0 .6rem; padding: .2rem .6rem; border: 1px solid #ef7d05; border-radius: 999px; color: #ef7d05; font-size: .75rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
.law-flagship-card__title { margin: 0 0 .5rem; font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 1.5rem; color: #fff; }
.law-flagship-card__title a { color: #fff; }
.law-flagship-card__title a:hover, .law-flagship-card__title a:focus { color: #ef7d05; }
.law-flagship-card__meta { margin: 0 0 .5rem; color: #fff; opacity: .9; font-size: .9rem; }
.law-flagship-card__sessions { list-style: none; margin: 1rem 0; padding: 0; }
.law-flagship-card__sessions li { display: flex; gap: .75rem; padding: .35rem 0; border-top: 1px solid rgba(255,255,255,.2); }
.law-flagship-card__session-time { flex: 0 0 7.5rem; color: #ef7d05; font-weight: 600; }
.law-flagship-card .button { background: #fff; color: #292459; border-color: #fff; }
.law-flagship-card .button:hover, .law-flagship-card .button:focus { background: #ef7d05; color: #292459; border-color: #ef7d05; }
.law-flagship-card .law-cal-card__badge { position: static; display: inline-block; margin: 0 .5rem .6rem 0; }
@media (min-width: 48em) {
  .law-flagship-card { flex-direction: row; }
  .law-flagship-card__media { flex: 0 0 40%; }
  .law-flagship-card__media img { aspect-ratio: auto; }
}
```

Match the badge's type metrics to the existing `.law-cal-card__badge`
(calendar.css:572 to 581) rather than the placeholder values above.
Contrast (calculated, verify with a tool): white on navy about 14:1, orange on
navy about 5.1:1, navy on orange about 5.1:1, all AA. White on orange is about
2.8:1: never use it here.

---

## 6. `EVENTS_FUNC.md` updates (same piece of work)

- §1 file counts and the §2 load-order line: `flagship.php` after
  `speakers-dashboard`, `admin/flagship-screen.php` after `session-screen`.
- `meta.php` section: the three keys, the `date` type, the zero-padded `time`.
- New `### flagship.php: the flagship conference (9 September 2026)` section
  after `speakers-dashboard.php`: the one-post model and why (rewrite
  collision, reuse, bookings anchor), the derived start/end and union rule, the
  `law_flagship_event_id` filter seam, the recompute hooks, the bookings guard,
  and what is deliberately absent (slot label, fee, invoice, workflow, booking
  control, `_law_approved_at`).
- `committee.php` and §3 `account-events.php`: the exclusions, the status-count
  adjustment, the `?event=` refusal.
- `source.php`: the two `template_include` branches; `is_flagship`, the date
  fallback and "Times to be announced" in `law_events_map_post()`.
- §3 `calendar.php`: `law_calendar_flagship_event()`,
  `law_calendar_day_is_empty()`, the exclusion in `law_calendar_events()`, the
  `$reset` parameters.
- Admin UI: `flagship-screen.php` (screen, POST scheme, reader, validation,
  PRG, log line), `fields.php`'s `$args`, `window.lawAdminFields`, the Event
  screen guard and notice.
- Migration: step 10's new label and flagship line; the setup trigger.
- §4 templates, parts and assets: `templates/flagship-event.php`,
  `parts/events/flagship-card.php`, calendar-body's six caller variables and
  the automatic hero image, `calendar-event-details`'s `booking` arg,
  `law_hero_default_image_url()`, `assets/js/law-flagship-admin.js`, the
  `law-admin.css` and `calendar.css` blocks.
- §6 open items: the approval-gated application flow (4.2 §5) is not built; the
  flagship's `_law_approved_at` and payment meta are never set, so anything
  rendering them shows blanks for it.
- Change history: a new entry dated 9 September 2026 at the top.

---

## 7. Tests

Run `vendor/bin/phpunit` (the whole suite, since the `time` sanitiser changed),
then `vendor/bin/phpunit --filter Flagship`. Style: `tests/RichTextTest.php`,
base class `LAW_Test_Case`, explicit `: void`, `assertSame` with the expected
value first, a comment on each assertion naming the bug it guards.

Fixture rules: the local database will hold a real, committed flagship post
once this ships, and `law_flagship_event_id()` returns the lowest flagged ID.
Each test creates its own fixture (`make_event( array( '_law_is_flagship' =>
1 ), 'law-draft' )`), pins it with `add_filter( 'law_flagship_event_id', fn()
=> $fixture )` plus `law_flagship_event_id( true )`, and removes the filter in
`tearDown()`. Where a helper branches on `law_events_source()`, force `cpt`
with `add_filter( 'pre_option_law_events_source', fn() => 'cpt' )`
(`isolate_option()` is array-only). Call the cache resets in `setUp()`.

### `tests/FlagshipTest.php`

1. `test_ensure_post_is_idempotent`: without the filter, the first call
   creates (assert `created`, `post_name === 'flagship'`, status `law-draft`,
   `_law_is_flagship` 1, `law_year` term set) or, if the DB already holds one,
   reports the "exists" path; the second call returns the same ID with
   `created === false`; exactly one flagged post; `dry => true` creates
   nothing.
2. `test_start_and_end_are_computed_from_the_sessions`: save with date
   `2026-12-02` and sessions 09:30 to 10:30 and 14:00 to 16:00; assert
   `_law_start === '2026-12-02 09:30'`, `_law_end === '2026-12-02 16:00'`,
   `_law_slot_label === ''`; save again with `sessions_present` and no
   sessions; assert both blank and the children deleted.
3. `test_event_speakers_are_the_union_of_session_speakers`: two sessions
   sharing speaker A plus B; event `_law_speakers` has two rows, A once, `sort`
   0 and 1; publish and assert `law_speakers_confirmed_maps( true )['events'][A]`
   contains the fixture and `law_speaker_appearance_for_event( A, $fixture )`
   is non-null.
4. `test_a_new_speaker_row_is_upserted_and_deduped`: an `is_new` row with
   first, last and email creates a `law_speaker` with that email and the
   session row carries its ID; saving again with the same email in a new
   `is_new` row yields the same ID and still one post.
5. `test_a_posted_session_id_must_belong_to_the_flagship`: another event with
   a session; post that session's ID in the flagship's rows; a new child is
   created under the flagship and the other session's parent is unchanged.
6. `test_edit_keeps_ids_and_deletes_only_removed_rows`: mirror the first case
   of `tests/SessionsTest.php`.
7. `test_the_flagship_is_hidden_from_the_committee_list_and_my_events`:
   publish the fixture; `$_GET = array()`; `law_committee_events()` IDs exclude
   it (precedent EventFlagsTest:287); a host user owning the fixture and a
   normal event sees only the normal one in `law_account_events()` (call it
   once per process; it is memoised).
8. `test_the_host_form_cannot_set_the_flagship_flag`: `law_events_form_save()`
   with `_law_is_flagship` and `law_flagship` keys in the input (copy
   `valid_input()` from EventFlagsTest); the new event has no
   `_law_is_flagship` meta and `law_flagship_event_id( true )` is still the
   fixture.
9. `test_validation_refuses_and_writes_nothing`: missing title, a session with
   no start, a new speaker with no last name; `WP_Error` with three messages;
   the fixture's title and session count unchanged.

### `tests/FlagshipRenderTest.php`

1. `test_template_include_routes_the_flagship_to_its_own_template`: swap
   `$GLOBALS['wp_query']` for `new WP_Query( array( 'p' => $id, 'post_type' =>
   LAW_EVENT_CPT ) )` (pattern in HeaderNavTest.php:168 to 192); assert
   `apply_filters( 'template_include', 'index.php' )` ends with
   `templates/flagship-event.php`; repeat with an ordinary published event and
   assert `templates/event-single.php`.
2. `test_the_list_pins_the_flagship_under_its_date_and_not_as_a_card`: no
   element of `law_calendar_events_by_date()[ law_flagship_date() ]` has the
   flagship ID; `law_calendar_flagship_event()['id']` equals it;
   `law_calendar_day_is_empty( law_flagship_date() )` is false with no other
   events; render `parts/calendar-events.php` with output buffering and assert
   one `law-flagship-card` inside `id="day-<date>"` and zero
   `law-event-card__title` links to the flagship permalink.
3. `test_a_filtered_list_still_carries_the_block`: `$_GET['law_kw'] =
   'no-such-term'`, reset the filters, render, assert the block is present and
   `law-cal__empty` is present; unset in `finally`.
4. `test_hero_image_prefers_the_custom_attachment`: insert an attachment
   (`post_mime_type image/jpeg`, `_wp_attached_file`) and set
   `_law_hero_image_id`; `law_event_hero_image_url()` contains the filename
   and the rendered card `src` matches; clear the meta and assert both the
   helper's fallback path and the card use `law_hero_default_image_url()`.
5. `test_a_flagship_without_sessions_keeps_its_date_and_falls_back_on_the_time_label`:
   `law_events_map_post( $id )['date'] === law_flagship_date()`, `unscheduled`
   false, `law_calendar_event_time_label()` returns "Times to be announced".
6. `test_the_details_box_hides_booking_and_places_when_asked`: render
   `parts/calendar-event-details.php` with `booking => false` and a places
   value; assert no `law-event-details__footer` and no
   `law-event-details__item--places`.
7. `test_bookings_refuse_the_flagship`: `law_booking_create()` against a
   published flagship returns a `WP_Error`.

---

## 8. Manual acceptance checklist

1. Fresh database: open Events → Flagship. The post is created on first open;
   the form shows "Flagship conference", date 2 December of the programme
   year, empty description.
2. Add a description with a bulleted list, a location ("IDRC, 70 Fleet
   Street"), a hero image, two sessions (09:30 to 10:30, 14:00 to 16:00), an
   existing speaker via search in session 1, a new speaker (name only) in
   session 2, tick "Show on the programme", save. Notice "Flagship event
   saved."; reload shows everything; the new speaker now appears as an
   existing one; Events → Speakers has the new record.
3. `/events/flagship/`: hero uses the chosen image; details box shows Date
   (Wednesday 2 December 2026), Time (9:30am - 4:00pm), Location; no places,
   no booking control; "Back to programme" link; description with the list
   intact; the Sessions timeline with speaker cards and working "Read full
   bio" dialogs, then Venue with map and "Open in Google Maps"; "Back to
   events calendar" button. Remove the hero image and reload: default banner.
4. `/programme/`: the block sits under Wednesday 2 Dec above any slot bars,
   image left, badge, title, two session lines with times, "Event details"
   button; the 2 Dec tab is active. Type a keyword matching nothing: "No
   events match this search." and the block still renders. Stacked layout on
   a narrow viewport.
5. `/committee/programme/`: block with the status badge and edit link.
   `/account/dashboard/`: the flagship is absent; `?event=<flagship id>` is
   refused with the link to the Flagship screen. `/account/events/` for the
   flagship's author: absent.
6. Untick "Show on the programme": `/events/flagship/` 404s for a visitor (the
   Members gate and status rules apply); the block disappears from
   `/programme/` and 2 Dec greys out if it has nothing else.
7. `/wp-admin/?setup-account-pages`: an `OK /events/flagship/ …` line (or
   `CREATED` on an environment without the post). LAW → Migration, step 10
   dry-run: the flagship line reads "exists" or "would create".
8. Speaker archive `/speakers/`: the flagship's speakers are listed once it is
   published, and their profiles show it under "Speaking at".
9. Check `ini_get( 'max_input_vars' )` against a large programme (about 12
   fields per speaker row plus 6 per session; a 1000 limit is reached around
   40 speaker rows). Show a warning notice on the screen when the field count
   nears the limit.

Closing gates per project practice: three conformance passes against this
document, a `security-specialist` review (new admin POST handler, AJAX reuse,
upsert path, bookings guard), then offer the `test-specialist` browser pass
with screenshots of the Flagship screen, `/events/flagship/` and `/programme/`
including a rendering and colour-contrast check.

---

## 8a. Bugs this build found in existing code

Both were fixed as part of it; both are recorded in `EVENTS_FUNC.md`.

1. **The status guard silently reverted "Show on the programme".** The
   `wp_insert_post_data` guard in `functions/events/workflow.php` reverts any
   status change to an existing `law_event` that does not come from the workflow
   engine, which is what stops the classic editor's Publish button confirming an
   unapproved event. The flagship has no workflow, so its tick box did nothing
   at all. Its saver now raises a `law_flagship_saving` global around its own
   `wp_update_post`, and the guard honours that flag **only** for the flagship
   and **only** for `publish` / `law-draft`. A separate flag from the engine's,
   so nothing pretends a transition ran.
2. **The `time` sanitiser did not zero-pad.** `9:30` was stored as typed, and
   `'10:30' < '9:30'` lexicographically, so a session validated as ending before
   it started and the derived start could pick the wrong session. `time` now
   zero-pads and range-checks the hour and minute, and the screen's validation
   compares the times as the schema will store them.

A third trap, found while wiring the page: `law_events_map_post( $id, array() )`
means "any status **except** `law-draft`" — only `array( '*' )` includes drafts.
The flagship starts as a draft, so the template passing `array()` rendered the
whole programme list at `/events/flagship/` for whoever was editing it.

## 9. Risks and known trade-offs

- **`max_input_vars`** can silently truncate a very large programme's POST.
  The sentinel and title fields come first so a truncated post never reads as
  "no sessions", and the screen warns when the count is near the limit.
- **Name-only new speakers** dedupe by normalised name onto an existing
  profile with the same name. Mitigated by the field hint and by the upsert's
  backfill log line on the event.
- **Slug clash**: if another `law_event` already uses `flagship`, the post
  lands on `/events/flagship-2/`; `ensure_post` reports it, and re-saving the
  flagship re-asserts `post_name` once the other slug is changed.
- **Static caches** (`law_calendar_event_by_id()`, `law_calendar_events()`,
  `law_calendar_filters()`, `law_flagship_event_id()`) are fine in production
  but need the `$reset` seams for the tests.
- **Admin Menu Editor Pro** can hide the new submenu. If it does, register a
  hidden `options.php` twin the way `law_events_register_law_subpage()`
  (settings.php:306) does.
- **Other write paths** to the same post: the wp-admin Event and Session
  screens can still edit it. §4.1's hooks and §4.8's guard keep the derived
  fields honest; the committee dashboard's `?event=` route refuses it.
- **Hydration cost**: `law_calendar_flagship_event()` runs `the_content` and
  builds speaker cards on every programme render and AJAX partial. One event;
  acceptable. Do not add more hydration to the card.
- **The keyless Google embed** (`law_calendar_maps_embed_url()`) is an
  undocumented endpoint. Unchanged here, but the flagship makes the venue map
  more visible.
- **`law_events_cpt_author_counts()`** (source.php:264) counts published
  events per author for the multi-event sponsor rule; the flagship counts for
  its admin author. Harmless unless an administrator also hosts events.
- **Session time labels** use an en dash and 24-hour times ("09:30–10:30")
  while the event-level label is 12-hour ("9:30am - 4:00pm"). That
  inconsistency predates this work; leave it.
