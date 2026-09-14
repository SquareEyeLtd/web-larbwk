# Retiring the self-service roles and adding the Account hub

Implementation handoff, written 14 September 2026 for whichever agent executes it. It is
self-contained: everything below was verified against the working tree at commit `aaa12e3`
on branch `events-4.2` and the local database. Line numbers are correct as of that commit;
re-check them before editing, since other agents work in this tree concurrently.

Read this whole document before starting. The order of work in section 9 matters, and the
risks in section 11 describe things that break silently.

---

## 1. Why

The client has asked for the role checkboxes to come off the registration form. Any signed-in
person may now submit an event or book a place, so the three self-service roles `event_host`,
`sponsor` and `attendee` no longer carry meaning. Every ordinary user becomes a plain WordPress
`subscriber`. Existing accounts are converted by a new step on the LAW > Migration screen
(`wp-admin/admin.php?page=law-migration`), so the deploy-then-migrate cutover carries it and a
git push alone never leaves an environment half-converted.

With everyone landing in the same place after sign-in, `/account/` becomes an **Account hub**
in the style of WooCommerce's "My account": a grid of linked boxes with icons for My profile, My
bookings, My events, Submit an event, the committee tools where the viewer is committee, and
Sign out.

### How much depends on the roles

Less than it looks. The three roles hold only the `read` capability (confirmed from
`wp_user_roles`), so nothing capability-based depends on them: `law_user_is_committee()`,
`law_user_can_manage_event()`, the wp-admin screens, the workflow engine, the bookings engine's
ownership checks are all untouched. Every dependency is a string comparison against role slugs,
and only two of those gate anything:

- `law_events_user_can_submit()` in `functions/events/submission-form.php:14-23`
- `law_account_user_is_host_like()` in `functions/account-bookings.php:305-314`

Everything else is form fields, welcome-email selection, HubSpot tag derivation,
account-creation defaults, Members plugin page rows, tests and documentation.

### Local data (read-only queries, 14 September 2026)

318 users. 302 hold at least one of the three roles. Exact combinations:

| roles held | users |
|---|---|
| attendee | 137 |
| event_host | 95 |
| event_host + attendee | 28 |
| sponsor + event_host | 15 |
| sponsor | 15 |
| sponsor + event_host + attendee | 10 |
| events_committee | 8 |
| editor + events_committee | 2 |
| editor | 2 |
| administrator | 2 |
| subscriber + administrator | 2 |
| administrator + event_host | 1 (user 1, Trevor) |
| administrator + attendee | 1 (user 270, local admin) |

The last two rows are why the migration step must add `subscriber` and then remove only the
three named roles. A `set_role( 'subscriber' )` sweep would strip administrator from both.

Members plugin restriction rows (`_members_access_role` post meta) locally:

| page | path | roles |
|---|---|---|
| 290 Account | `/account/` | administrator, editor, sponsor, subscriber, event_host, events_committee, attendee |
| 292 My events | `/account/events/` | same seven |
| 67244 My bookings | `/account/bookings/` | same seven |
| 294 Submit an event | `/account/events/submit/` | administrator, editor, sponsor, subscriber, event_host, events_committee |
| 439 Profile | `/account/profile/` | none (unrestricted) |
| 372 Event submitted | `/account/events/submit/done/` | none (unrestricted) |
| 414 and its five children | `/account/dashboard/…` | administrator, editor, events_committee |

`subscriber` is already present locally where it matters. Production may differ, and these rows
are database state, so both provisioning routes must ensure them (section 5).

Where the roles are defined: `event_host` is registered by
`wp-content/mu-plugins/law-secondary-host-users.php:28-39` on every `init`. `sponsor`,
`attendee` and `events_committee` exist only as rows in `wp_user_roles`, created through the
Members plugin. Nothing in the theme registers any of them.

---

## 2. Decisions already made by Denis (14 September 2026)

Do not reopen these.

1. **Committee keep their shortcut.** Ordinary users land on the hub after sign-in; committee
   members (anyone with `edit_others_law_events`) still go straight to `/account/dashboard/`.
2. **My events shows only when the viewer owns or co-owns at least one event**, committee
   included (they have Manage events). Submit an event always shows for a signed-in user. The
   header dropdown and the hub agree because both read one list.
3. **The host/sponsor signal survives as optional user meta, not a role.** The role checkbox
   group becomes an optional tick group with two boxes, "I plan to host an event" and "I
   represent a LAW sponsor", stored in user meta `law_intent`. It drives the welcome-email
   choice and the HubSpot contact-type tags only. It has no access effect anywhere. Two ticks
   rather than one because the HubSpot tags `<year> Event Host` and `<year> Sponsor` are
   distinct. The migration step seeds this meta from the roles each user holds today, so no
   information is lost.
4. **Minimal cleanup.** The role definitions stay in `wp_user_roles`: the mu-plugin re-registers
   `event_host` on every `init`, mu-plugins are off limits, and keeping the definitions makes
   rollback a code revert plus re-adding roles from the seeded meta. The legacy Gravity Forms
   hooks in `functions/users.php` and `functions/hubspot.php`, and the GF User Registration feed
   on form 1 (User registration) that hardcodes `event_host`, are left for the post-cutover
   cleanup ticket. They only fire on the retired forms.
5. **Item order on both surfaces:** My profile, My bookings, My events (only if owned), Submit
   an event, then the committee tools, then Sign out. This reorders the header dropdown too;
   committee items currently come first there.

---

## 3. Conventions the executing agent must follow

- **Never run a git command that writes** (`stash`, `checkout <file>`, `reset`, `clean`,
  commit, push) unless Denis asks for that specific command. Other agents work in this tree at
  the same time.
- **Do not edit anything under `wp-content/mu-plugins/`.** All changes stay in the theme.
- **Both provisioning routes.** Anything that depends on database state (page templates, page
  content, Members rows) must be applied by both migration step 10 (`law_migration_run_pages()`)
  and the `?setup-account-pages` admin trigger (`functions/setup-account-pages.php`), through
  one shared helper, so a git push plus one URL makes any environment work.
- **`EVENTS_FUNC.md` is a living document.** Update every section this work touches in the same
  piece of work (section 10 lists them) and add a change-history entry.
- **Gravity Forms naming.** Wherever a form or field ID appears in code comments, docs or
  reports, pair it with its name: form 1 (User registration), form 3 (User profile), form 2
  (Event > submit an event).
- **House style in all user-facing copy and docs:** UK English, sentence case for headings and
  labels, no em-dashes. Never mention the activity log in UI help text.
- **Design rules:** emphasis and call-to-action panels are filled brand navy (`#292459`) with
  white text, not pale tints; in such a panel the text forms one block on the left and the
  button sits on the right, vertically centred; do not de-emphasise items by inference (every
  tile the same weight); first-party JS lives in `assets/js/`, not `assets/js/vendor/`.
- **Browser testing is opt-in** and goes to the `test-specialist` agent (Playwright), never
  `browser-tester`. Ask Denis at the end; do not launch it unprompted. Add `.playwright/` and
  `.playwright-cli/` to `.gitignore` before any browser run.
- **Tests run against the real local database:** `php -d memory_limit=512M vendor/bin/phpunit`
  from the theme root (`--filter Name` for one class). `tests/bootstrap.php` wraps each test in a
  transaction that is rolled back; DDL (such as creating the migration log table) commits it, so
  never drive the full migration step from a test on a database that lacks the table.

---

## 4. Part A: the role retirement (backend)

### A1. `functions/events/registration.php`

**Replace** `law_registration_roles()` (:21-27) with:

```php
/** The optional "tell us about yourself" ticks: key => label. Meta only, no access effect. */
function law_registration_intents() {
	return array(
		'host'    => 'I plan to host an event',
		'sponsor' => 'I represent a LAW sponsor',
	);
}

/** The three retired self-service roles. For the migration step and the pre-step guard window only. */
function law_registration_legacy_roles() {
	return array( 'event_host', 'sponsor', 'attendee' );
}

/** @return string[] The stored intents, whitelisted. */
function law_registration_read_intent( $user_id ) {
	return array_values( array_intersect(
		(array) get_user_meta( (int) $user_id, 'law_intent', true ),
		array_keys( law_registration_intents() )
	) );
}

/** @return string[] What was stored, whitelisted. */
function law_registration_write_intent( $user_id, array $intents ) {
	$intents = array_values( array_intersect(
		array_map( 'sanitize_key', $intents ),
		array_keys( law_registration_intents() )
	) );
	update_user_meta( (int) $user_id, 'law_intent', $intents );
	return $intents;
}
```

Storage: meta key `law_intent`, a plain PHP array (serialised by core). An **empty array** means
"asked, nothing ticked". **No row** means "never asked": engine-created co-owner and colleague
accounts, and every user before the migration step runs. The step uses that distinction
(`metadata_exists()`) so it never overwrites a user who has edited their profile between runs.

**Change:**

- `law_registration_welcome_slug( array $intents )` (:39-43):
  `return $intents ? 'user_welcome_registered_host' : 'user_welcome_registered';`
  Either tick means hosting-side copy. No floor logic.
- `law_registration_hubspot_tags( array $intents )` (:119-130): `'sponsor'` → `"$year Sponsor"`,
  `'host'` → `"$year Event Host"`. Output strings are unchanged, so existing
  `law_hubspot_contact_type` values stay comparable.
- `law_registration_write_profile_meta()` (:133-158): line 145 becomes
  `$intents = law_registration_write_intent( $user_id, (array) ( $input['law_intent'] ?? array() ) );`.
  **Delete line 152** (`update_field( 'law_role', … )`): stop writing the ACF user field
  `law_role` (ACF field 434 "Role", group 285 "User fields"; its choices are the role slugs, so
  writing `host` would be an off-list value). Leave existing stored values alone. Keep the
  accessibility and dietary writes. Return `$intents`.
- **Delete** `law_registration_sync_roles()` (:160-178).
- `law_registration_apply_attendee_profile()` guard at :262. **This is the silent trap.** Today
  it reads `array_diff( (array) $user->roles, array_keys( law_registration_roles() ) )` and means
  "only fill blanks on an account that holds nothing but self-service roles". With the role map
  emptied, `array_diff( $roles, array() )` is non-empty for every user, so the function would
  return early for every existing account, on-behalf profile fills (country, accessibility,
  dietary for people booked in by phone) would stop, and nothing would log it. Rewrite as:
  ```php
  if ( ! $user || array_diff( (array) $user->roles, array_merge( array( 'subscriber' ), law_registration_legacy_roles() ) ) ) {
      return $written;
  }
  ```
  Including the legacy roles covers the window between deploy and the step. Update the docblock
  (:244-251).
- `law_registration_handler()`: delete the locked-role handling (:307-314, :388-390, :398-404);
  delete the "Please choose at least one role" validation (:369-371); `'role' => 'subscriber'` at
  :414; delete the `add_role()` loop (:425-427); rename `$stored_roles` → `$stored_intents`
  (:428-429, :447). The `{user_roles}` placeholder at :436 **keeps its name** (production may
  hold Emails-screen overrides carrying it, and an unknown placeholder is replaced with `''` at
  notifications.php:621 rather than shown, so a rename would silently blank a line); its value
  becomes the ticked labels joined with ", " or "None ticked".
- `law_profile_store_error_state()` (:498): `'roles'` → `'law_intent'` in the re-array list.
  Without this a fully cleared tick group reverts to the stored value on a validation error.
- `law_profile_handler()` (:623-625): drop the `law_registration_sync_roles()` call; HubSpot
  tags from the intents returned by `law_registration_write_profile_meta()`.
- `law_profile_values()` (:660): `'law_intent' => law_registration_read_intent( $user_id )`
  replaces the `'roles'` entry.
- File docblock (:8-13) and the comment at :444-446.

### A2. Form parts

- `parts/events/profile-fields.php:69-83`: replace the `roles[]` block and its
  `if ( empty( $args['locked_role'] ) )` wrapper with an optional group: label "Tell us about
  yourself (optional)", `<input type="checkbox" name="law_intent[]" value="…">` for each entry of
  `law_registration_intents()`, checked from `$law_pf_value( 'law_intent', array() )`. No
  required marker in either mode. Docblock (:6-9) loses `locked_role`.
- `templates/register.php`: delete the `?role=` parsing (:18-25), the hidden `roles[]` /
  `locked_role` inputs (:56-59) and the `'locked_role'` arg (:64). Keep `redirect_to`.
- `parts/events/booking-modal.php:74` and `parts/events/flagship-apply-modal.php:71`: drop
  `'role' => 'attendee'` from the `add_query_arg()` building the register link.
- `templates/account-profile.php:7`: comment.

### A3. The two gates

- `law_events_user_can_submit( $user_id = 0 )` (`functions/events/submission-form.php:14-23`):
  keep the signature and its three callers (:1041 the POST handler, `header-nav.php:332`,
  `templates/account-event-form.php:56`). Body becomes `return $user && $user->exists();`.
  Docblock: "Any signed-in account may submit (Denis, 14 September 2026). Kept as the single seam
  so a future narrowing has one place to go."
- `law_account_user_is_host_like()` (`functions/account-bookings.php:305-314`): **keep the
  name**; body becomes `return is_user_logged_in();`. Rewrite the docblock: it is now the
  `[user-content role="host"]` audience's "signed in" seam. Part B removes its other callers
  (header-nav.php:321, account-events.php:75, templates/account.php:43), leaving
  `functions/shortcodes.php:122` as the only one. Do not make it a wrapper over
  `law_events_user_can_submit()`: this file loads with the shared functions, before the events
  module.
- `templates/account-event-form.php:56-57`: delete the branch whose copy is "Event submission is
  for registered event hosts. You can add the Event host role from your profile." The signed-out
  branch at :55 reads "Please sign in to submit an event." with "sign in" linked
  (`law_auth_login_url()` with `redirect_to`).

### A4. Role grants in the engines

- `functions/events/co-owners.php:191`: `law_events_create_host_user()` default becomes
  `'role' => 'subscriber'`. Keep the function name (three callers, documented). Fix the docblock
  (:174-188).
- `functions/events/bookings.php`: :876-881 drop the `'role' => 'attendee'` element so the default
  applies. **Delete** `law_booking_grant_attendee_role()` (:905-921) and its callers at :1205-1207
  (the whole `foreach $people` loop that only grants the role) and :1595. Docblock :851-852 loses
  "granting the attendee role if missing".
- `functions/events/flagship-bookings.php:339` and :1387: delete the calls; comment :337-338
  loses "the attendee role".
- `functions/events/migration/runner.php:405` and :415: step 1 log strings become "Would create
  account for …" / "Created account %d for …" (drop `event_host`).
- `functions/events/notifications.php`: `user_welcome_registered` (:419) name → "Email to new
  user > welcome after registration", trigger → "user registration (no hosting or sponsor
  tick)"; `user_welcome_registered_host` (:427) trigger → "user registration (ticked host or
  sponsor)". Leave the admin-notice bodies at :190 and :198 and runner.php:1644 (`Roles:
  {user_roles}`) alone; the value now reads the ticked labels or "None ticked".

### A5. Members plugin `subscriber` rows (both provisioning routes)

In `functions/setup-account-pages.php`, replace `law_setup_account_events_attendee_access()`
(:179-205) with:

```php
/**
 * Every account page a plain subscriber needs must admit the subscriber role
 * in its Members restriction. Pages with no restriction rows are left alone
 * (the plugin reads none as public). Legacy role rows are not removed: the
 * roles stay defined and the rows keep rollback cheap.
 *
 * @return string 'ok', or e.g. 'updated: /account/events/, /account/bookings/; missing: /account/events/submit/'.
 */
function law_setup_account_page_roles() {
	$paths = array( 'account', 'account/events', 'account/bookings', 'account/events/submit' );
	// For each: get_page_by_path(); missing → record; no rows → record as open;
	// rows without 'subscriber' → add_post_meta( $id, '_members_access_role', 'subscriber' ), record as updated.
	// Compose the return string from the three lists; 'ok' when all are empty.
}
```

Wiring (order matters: `law_setup_my_bookings_access()` copies `/account/`'s rows onto a
row-less `/account/bookings/`, so the subscriber pass must run after it):

- Trigger, `law_setup_account_pages()` (:128-135): replace the attendee line, moved to after the
  `law_setup_my_bookings_access()` line: `'ACCESS   account page roles: ' . law_setup_account_page_roles()`.
- Step 10, `law_migration_run_pages()` (`runner.php:1826-1832`): delete the attendee block; add
  after the my-bookings block (:1836-1838), inside the same `! $dry` guard:
  `law_migration_log( 'pages', 'created', 'account pages', 'Account page roles: ' . law_setup_account_page_roles() . '.' );`

### A6. Migration step 11: `retire_roles`

All in `functions/events/migration/runner.php` unless stated.

- `law_migration_steps()` (:16-32): append as the LAST entry
  `'retire_roles' => array( 'label' => 'Step 11: retire the self-service roles (event_host, sponsor and attendee become subscriber; hosting/sponsor intent seeded)', 'gated' => true )`.
  Last on purpose: "Run all" executes gated steps in array order (page.php:196), so step 10 has
  written the subscriber Members rows before anyone loses `event_host`. `page.php` renders the
  step table from `law_migration_steps()` (page.php:100-130), so the new step appears on the
  screen and in Run all with no UI change. `gated => true` also means a real run requires a
  fresh snapshot and a passing preflight, which is right for a change to 300 accounts.
- Dispatcher `law_migration_run_step()` switch (:1928-1958): `case 'retire_roles': return law_migration_run_retire_roles( $dry );`.
- Pure per-user helper, testable without the log table:

```php
/**
 * @return array{status:string, removed:string[], kept:string[], intents:string[], seeded:bool, added_subscriber:bool}
 *   status: 'skipped' (holds none of the three) | 'dry-run' | 'created'.
 */
function law_migration_retire_user_roles( WP_User $user, $dry ) {
	$legacy  = law_registration_legacy_roles();
	$roles   = (array) $user->roles;
	$removed = array_values( array_intersect( $legacy, $roles ) );
	if ( ! $removed ) {
		return array( 'status' => 'skipped', 'removed' => array(), 'kept' => $roles, 'intents' => array(), 'seeded' => false, 'added_subscriber' => false );
	}
	$kept    = array_values( array_diff( $roles, $legacy ) ); // administrator, editor, events_committee, subscriber
	$intents = array_values( array_filter( array(
		in_array( 'event_host', $removed, true ) ? 'host' : '',
		in_array( 'sponsor', $removed, true ) ? 'sponsor' : '',
	) ) );
	$seed    = ! metadata_exists( 'user', $user->ID, 'law_intent' );
	$add_sub = ! in_array( 'subscriber', $roles, true );
	if ( $dry ) {
		return array( 'status' => 'dry-run', 'removed' => $removed, 'kept' => $kept, 'intents' => $intents, 'seeded' => $seed, 'added_subscriber' => $add_sub );
	}
	if ( $seed ) {
		law_registration_write_intent( $user->ID, $intents ); // empty array for attendee-only: "asked, nothing ticked"
	}
	if ( $add_sub ) {
		$user->add_role( 'subscriber' ); // BEFORE removing anything: never role-less, never set_role()
	}
	foreach ( $removed as $role ) {
		$user->remove_role( $role );
	}
	return array( 'status' => 'created', 'removed' => $removed, 'kept' => $kept, 'intents' => $intents, 'seeded' => $seed, 'added_subscriber' => $add_sub );
}
```

- Step function:

```php
function law_migration_run_retire_roles( $dry ) {
	$started = time();
	$done = 0; $host = 0; $sponsor = 0; $already = 0;
	$ids = get_users( array( 'role__in' => law_registration_legacy_roles(), 'fields' => 'ID', 'number' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
	if ( ! $ids ) {
		law_migration_log( 'retire_roles', 'skipped', 'users', 'No account holds event_host, sponsor or attendee.' );
		return array( 'done' => true, 'summary' => 'Nothing to do: no account holds a retired role.' );
	}
	foreach ( $ids as $id ) {
		// The history step's time-box (runner.php:1337-1342): the page JS loops on done => false.
		if ( ! $dry && $done > 0 && time() - $started >= 20 ) {
			return array( 'done' => false, 'summary' => $done . ' accounts moved this batch; more remain.' );
		}
		$user   = new WP_User( (int) $id );
		$result = law_migration_retire_user_roles( $user, $dry );
		$ref    = sprintf( 'user %d (%s)', $user->ID, $user->user_email );
		// e.g. "Roles removed: event_host, attendee. Subscriber added. Kept: administrator. Intent seeded: host."
		//  or  "Would remove: sponsor. Subscriber already held. Intent left as already set."
		law_migration_log( 'retire_roles', $result['status'], $ref, $message );
		$done++; // and count host / sponsor / already-set
	}
	return array( 'done' => true, 'summary' => sprintf( '%d accounts moved to subscriber (%d host intent, %d sponsor intent, %d intent already set).', $done, $host, $sponsor, $already ) );
}
```

Idempotent by construction: a processed user no longer matches `role__in`, so a re-run finds
nothing and logs one `skipped` line; each batch re-queries, so it only sees what remains. One
log line per user is deliberate (about 300 rows next to the 2,296 already in the table): each
line records exactly which roles were removed, which is the rollback data. Dry run walks every
user with no writes and no time-box. The step leaves ACF `law_role`, `law_hubspot_contact_type`
and every non-legacy role alone.

- `functions/events/migration/page.php:108-110`: the `in_array( $step, array( 'counters', 'notifications' ) )`
  warning becomes a small `$warnings` map keyed by step, with a new entry for `retire_roles`:
  "Removes event_host, sponsor and attendee from every account that holds them (subscriber added
  first, hosting/sponsor intent seeded from the roles). Run step 10 first so the subscriber
  Members rows exist. Each log line records the roles removed."
- Optional, one line in `law_migration_verification()` (:1965+): "Accounts still holding a retired
  role: N" from the same `get_users` count.

Preflight note: the gate's hard check is `class_exists( 'GFAPI' )` (runner.php:228). Gravity
Forms stays installed for form 7 (Contact) and "Source flag still on GF" is warn-only (:230), so
the step can run after the cutover.

---

## 5. Part B: the Account hub (front end)

### B1. Ownership helper (`functions/account-events.php`)

Add below `law_account_events()` (:106):

```php
/**
 * Whether a user owns or co-owns at least one event, the flagship excluded
 * (as law_account_events() excludes it: its author is whoever ran setup).
 * Memoised per user for the request: the header renders twice per page and
 * the hub a third time.
 */
function law_account_user_has_events( $user_id = 0 ) {
	static $cache = array();
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( $user_id < 1 ) {
		return false;
	}
	if ( array_key_exists( $user_id, $cache ) ) {
		return $cache[ $user_id ];
	}
	if ( 'cpt' === law_events_source() ) {
		$ids = law_events_owned_event_ids( $user_id ); // co-owners.php:259, two cheap queries
		if ( function_exists( 'law_flagship_is' ) ) {
			$ids = array_filter( $ids, static fn( $id ) => ! law_flagship_is( $id ) );
		}
		$cache[ $user_id ] = (bool) $ids;
	} else {
		$cache[ $user_id ] = (bool) law_account_events(); // legacy source, current user only
	}
	return $cache[ $user_id ];
}

/** Test-facing: drop the memoised results of this and law_account_events(). */
function law_account_events_reset_cache() { … }
```

Use `law_events_owned_event_ids()` rather than `law_account_events()`: the latter also runs
`law_events_map_post( $id, array( '*' ) )` per event, which is the expensive part and pointless
for a yes/no. Key `law_account_events()`'s own `static $items` by user id as well (tests switch
users mid-request) and give the reset function a way to clear both (precedent:
`law_calendar_reset_caches()`, `functions/calendar.php:1145`).

### B2. `functions/header-nav.php`

- **Build order** (:264-357) becomes, in this sequence:
  1. `profile` "My profile"
  2. `my_bookings` "My bookings" (CPT source; the legacy `elseif` branch at :346 keys on
     `! law_account_user_has_events()` and stays, dead on this environment but harmless)
  3. `events` "My events", gated on `law_account_user_has_events()`
  4. `submit` "Submit an event", gated on `law_events_user_can_submit()`
  5. the six committee items in their existing order (`dashboard`, `speakers`, `flagship`,
     `bookings`, `flagship_bookings`, `discounts`), gated on `law_user_is_committee()`
  6. `signout` "Sign out", appended unfiltered as now (:378-383)

  Remove the `$host_like` variable. Keep every existing comment that explains an item; update
  the ones about order (:315-331) to record Denis's 14 September order.
- **Three new keys on every item**, copied through the build loop (:362-375) and ignored by
  `parts/layout/top-nav.php` (which reads only `label`, `url`, `current`, :87-93):

  | key | group | icon | description |
  |---|---|---|---|
  | profile | personal | `user` | Your details and password |
  | my_bookings | personal | `ticket` | Places you have booked |
  | events | personal | `date` (existing glyph) | Events you run or co-own |
  | submit | personal | `plus` | Propose an event for the week |
  | dashboard | committee | `clipboard` | Review and manage every submitted event |
  | speakers | committee | `microphone` | Speaker records and their appearances |
  | flagship | committee | `flag` | Edit the flagship conference |
  | bookings | committee | `places` (existing) | Bookings at hosted events |
  | flagship_bookings | committee | `price` (existing) | Applications and payments for the flagship |
  | discounts | committee | `type` (existing) | Prepare and manage discount codes |
  | signout | signout | `signout` | (empty) |

- **File header** (:14-22): principle 1 now names `law_user_is_committee()`,
  `law_account_user_has_events()` and `law_events_user_can_submit()`. Add: "Items also carry
  `icon`, `description` and `group`, read by the account hub (`parts/layout/account-tiles.php`)
  and ignored by the top bar. The hub is built from this function's items; never write a second
  list." Comment :152-153 → "the account hub, or the dashboard for committee".

### B3. `functions/auth.php`

- Add `law_auth_default_redirect()` returning `home_url( '/account/' )`. Literal path, like the
  surrounding code: auth.php loads before header-nav.php and the login page can render before
  pages are provisioned.
- `law_auth_redirect_to()` :53 → `return $redirect ? $redirect : law_auth_default_redirect();`
- `law_auth_committee_redirect()` :65 → `$default = law_auth_default_redirect();`. The filter
  only overrides when `$redirect_to` is empty or equals the default, so both sites must change
  together or the committee shortcut stops firing.
- Docblocks :46-49 and :56-60: the hub is for everyone; committee go straight to their queue.
- `:283` "Log out" → "Sign out". Also `templates/login.php:54`. One label everywhere, matching
  the dropdown.

### B4. Icons: `law_icon()` in `functions/helpers.php`

- `law_icon_paths()`: one table of SVG inner markup keyed by icon name. Move the eight strings
  now local to `parts/calendar-event-details.php:81-102` (`date`, `time`, `venue`, `host`,
  `type`, `sector`, `places`, `price`) into it and add seven new glyphs, all in a 24-unit box,
  stroke only, round caps and joins, matching the existing convention (:75-79):
  - `user`: `<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>`
  - `ticket`: `<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4V8Z"/><path d="M14 7v1M14 11.5v1M14 16v1"/>`
  - `plus`: `<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 8v8M8 12h8"/>`
  - `clipboard`: `<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2"/><path d="m9 13 2 2 4-4"/>`
  - `microphone`: `<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0"/><path d="M12 18v3M8 21h8"/>`
  - `flag`: `<path d="M5 21V4"/><path d="M5 4h11l-1.5 3.5L16 11H5"/>`
  - `signout`: `<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>`
- `law_icon( $key, $class = '', $size = 24, $stroke = 1.75 )`: returns the full
  `<svg class="…" width height viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">…</svg>`
  string, or `''` for an unknown key.
- `parts/calendar-event-details.php` switches to `law_icon_paths()` (or `law_icon( $key, 'law-event-details__icon', 18 )`)
  so the glyph set cannot drift. Run `FlagshipRenderTest` afterwards.
- Do not use the Font Awesome kit loaded in `header.php:12`; nothing in the theme uses it.

### B5. The hub: template, part, stylesheet

**`templates/account-hub.php`**, header comment `Template Name: Account hub`. A NEW template,
not a rewrite of `templates/account.php`: that template also serves page 372
(`/account/events/submit/done/`, "Event submitted"), whose editor content must keep rendering in
the hero.

```php
nocache_headers();                       // per-user page, as account-bookings.php:20
get_header();
// loop
get_template_part( 'parts/layout/hero-title' );   // photo banner like My bookings / My events
?>
<section class="page-section"><div class="grid-container law-account-hub">
<?php if ( ! is_user_logged_in() ) : ?>
	<p>Please <a href="<?php echo esc_url( law_auth_login_url( array( 'redirect_to' => rawurlencode( get_permalink() ) ) ) ); ?>">sign in</a> to see your account.</p>
<?php else :
	echo do_shortcode( '[action-message]' );      // /account/?action=registered callout
	?>
	<p class="law-account-hub__lead">Signed in as <strong><?php echo esc_html( law_header_nav_display_name() ); ?></strong>.</p>
	<?php get_template_part( 'parts/layout/account-tiles', null, array( 'items' => law_header_nav()['account']['items'] ) );
endif; ?>
</div></section>
<?php get_footer();
```

The signed-out branch is **required**, not defensive: the Members plugin's content permissions
filter `the_content()` only (settings: `content_permissions => 1`, in-place message, no
redirect), and this template never calls `the_content()`, so it must gate itself the way the
dashboards call `law_user_is_committee()` themselves.

Why the photo hero: the hub is a navigation page like My bookings and My events, which use
`parts/layout/hero-title`; the solid navy hero is reserved for pages whose hero carries a form
(`app.css:1345-1355`). Keeping it out of the `.auth-hero` body-flex rules (`auth.css:18-27`)
also avoids their side effects.

**`parts/layout/account-tiles.php`**: arg `items` (the array from `law_header_nav()`). Groups
render in fixed order `personal` (heading "Your account"), `committee` (heading "Committee
tools"), `signout` (no heading); a group with no items renders nothing; an item without `group`
falls into `personal`.

```html
<section class="law-account-hub__group" aria-labelledby="law-hub-personal">
	<h2 id="law-hub-personal" class="law-account-hub__heading">Your account</h2>
	<ul class="law-account-hub__tiles" role="list">
		<li>
			<a class="law-account-hub__tile law-account-hub__tile--my_bookings" href="…">
				<!-- law_icon( $item['icon'], 'law-account-hub__icon', 32 ) -->
				<span class="law-account-hub__label">My bookings</span>
				<span class="law-account-hub__desc">Places you have booked</span>
			</a>
		</li>
	</ul>
</section>
```

`role="list"` because the CSS removes bullets and Safari then drops list semantics. `esc_html`
labels and descriptions, `esc_url` hrefs.

**`assets/css/account-hub.css`**, enqueued in `functions/enqueue.php` after the auth block (:63):
`if ( is_page_template( 'templates/account-hub.php' ) ) { wp_enqueue_style( 'law-account-hub', law_asset( 'css/account-hub.css' ), array( 'law-wp' ), filemtime( … ) ); }`
(match the neighbouring calls' handle/dependency conventions). Palette: navy `#292459`, orange
`#ef7d05`, yellow `#ffcc02`.

- `.law-account-hub__tiles`: `display:grid; gap:1.25rem; list-style:none; margin:0 0 2.5rem; padding:0;`
  1 column; `repeat(2, minmax(0, 1fr))` at `min-width: 40em`; `repeat(3, minmax(0, 1fr))` at
  `min-width: 64em` (explicit columns, the calendar.css convention at :1973-1974).
- `.law-account-hub__tile`: `display:flex; flex-direction:column; gap:.5rem; height:100%; min-height:9rem; padding:1.5rem; background:#292459; color:#fff; border-radius:0; border-top:4px solid #ef7d05; text-decoration:none; transition: background-color .25s, border-color .25s;`
  Square and shadowless like every calendar.css panel; the orange rule is the `.law-booking-panel`
  seam (`calendar.css:2161-2179`). Every tile identical weight.
- `.law-account-hub__icon`: `width:32px; height:32px; color:#ef7d05;`
- `.law-account-hub__label`: the theme's heading face at 600, ~1.05rem, white.
  `.law-account-hub__desc`: ~.9rem, `rgba(255,255,255,.85)`.
- Hover and focus: `background:#ef7d05; border-top-color:#292459;` icon and text white (the
  theme's chips and `.button.orange` swap orange and navy on hover, `app.css:281-289`).
- `:focus-visible`: `outline:2px solid #292459; outline-offset:2px` (precedent `app.css:501`).
- `.law-account-hub .callout`: navy fill, white text, 4px left border by tone (`.success`
  `#2e7d32`, `.info` `#ef7d05`), mirroring `auth.css:312-352` for a white page; otherwise
  Foundation's pastel callout applies to the registration message.
- `.law-account-hub__heading`: navy, 1.25rem, `margin: 0 0 1rem`. `.law-account-hub__lead`: navy, 1.1rem.

No JavaScript.

### B6. Provisioning page 290 and its content (both routes)

- `functions/setup-account-pages.php:35` (`$setup['account']`) and `law_migration_page_map()`
  (`runner.php:1736`): `'account' => 'templates/account-hub.php'`. Both routes already assign a
  template where the page exists with a different one.
- Rename `law_setup_account_page_audience()` (:227-245) → `law_setup_account_page_content()` and
  update both callers (:128 report line, label `'CONTENT  /account/ body: '`; `runner.php:1823-1824`).
  Return `ok | updated | missing` as before. New rule, applied to `post_content` in order:
  1. Remove every `<!-- wp:shortcode -->…<!-- /wp:shortcode -->` block whose body contains
     `[user-content` (`/<!--\s*wp:shortcode\s*-->\s*\[user-content\b.*?\[\/user-content\]\s*<!--\s*\/wp:shortcode\s*-->/s`),
     then any stray `[user-content …]…[/user-content]` outside a block.
  2. Remove empty paragraph blocks (`/<!--\s*wp:paragraph\s*-->\s*<p>\s*<\/p>\s*<!--\s*\/wp:paragraph\s*-->/`).
  3. If `[action-message]` is absent, prepend `<!-- wp:paragraph -->\n<p>[action-message]</p>\n<!-- /wp:paragraph -->`.
  4. Collapse three or more newlines to two; `trim()`.
  5. Equal to `trim( $page->post_content )` → `ok`; else `wp_update_post()` → `updated`.

  Idempotent by construction. On this database the body becomes exactly the `[action-message]`
  paragraph. Anything else an editor has added is left alone (narrow, like the current helper).
  Current content of page 290 for reference: an `[action-message]` paragraph, a
  `[user-content role="attendee"]` shortcode block ("The booking platform for event attendees
  will be available soon."), a `[user-content role="host"]` shortcode block (Submit / view your
  events links), two empty paragraphs.
- `templates/account.php`: docblock now says it serves editor-content account pages such as the
  submission confirmation (page 372). Replace the host-like fallback (:40-53) with one generic
  sentence linking to `law_account_url( 'account' )` ("Welcome to London Arbitration Week. Go to
  your account."). Keep the buffered-content safety net.

### B7. `/account/events/`: redirect and empty state

- `functions/account-events.php:44-88`: keep the template guard, the unprovisioned-environment
  guard and the `?law_booking=` forwarding (:45-65) exactly as they are; every confirmation email
  already sent links there. **Delete** the second redirect (:67-87) and the `$host_like` line.
  Everyone can submit now, so the page has a job for everyone. Rewrite the docblock (:36-42):
  one redirect, and why the second went.
- `templates/account-events.php`: `$law_show_submit_button = $law_submit_url && $law_items;`
  (:120) stays (toolbar button only when there are cards). Replace the `<p class="law-cal__empty">`
  at :135-147 with a filled panel, text block left and button right, vertically centred:
  ```html
  <div class="law-account-events__empty">
  	<div class="law-account-events__empty-text">
  		<p class="law-account-events__empty-title">You have not submitted any events yet.</p>
  		<p>Anyone with an account can propose an event for London Arbitration Week. Drafts are saved here until you submit them.</p>
  	</div>
  	<a class="button orange" href="{submit url}">Submit an event</a>
  </div>
  ```
  CSS in `assets/css/calendar.css` beside `.law-account-events__toolbar` (:1184): flex row,
  `align-items:center`, `justify-content:space-between`, `gap:1.5rem`, navy fill, white text,
  `border-top: 4px solid #ef7d05`, padding `1.5rem 2rem`, stacking on a phone; a `.law-cal a`
  colour reset for the button (precedent `.law-cal .law-flagship-strip__link`, :665). The button
  should be larger than the theme's small base button (house rule for filled panels).
- Fix the comment at :113-117 ("everyone who reaches this listing either runs events or is
  host-like") which becomes false.

---

## 6. Part C: tests

- `tests/class-law-test-case.php:155`: `make_user( $role = 'subscriber' )`.
- Mass replace `make_user( 'attendee' )`, `make_user( 'event_host' )`, `make_user( 'sponsor' )`
  and `make_user( 'event_attendee' )` (FlagshipTest:535; that role never existed, so the fixture
  has been silently role-less) → `make_user()` across the ~25 files. Keep `events_committee` and
  explicit `subscriber`. Keep a legacy role only where a test is deliberately about it (below).
  `SubmissionFormLockTest:208` keeps its meaning (ownership, not role) with the default.
- `tests/RegistrationTest.php`: delete `test_role_sync_never_touches_privileged_roles` (:8-17) and
  `test_role_sync_floor_is_attendee` (:19-23). Rewrite the whitelist test (:25-49): posting
  `'law_intent' => array( 'administrator', 'event_host', 'host', 'FAKE' )` stores and returns
  `array( 'host' )` and writes no `law_role` row. HubSpot tags (:51-59): inputs `array()`,
  `array( 'sponsor' )`, `array( 'sponsor', 'host' )`. Welcome slug (:61-71): `array()` →
  `user_welcome_registered`; `array( 'host' )` and `array( 'sponsor' )` → `user_welcome_registered_host`.
- `tests/AccountAudienceTest.php`: `host_audience()` (:34-43) true for `subscriber` and for the
  legacy roles (a not-yet-migrated user is still signed in); guests still false. Committee
  exclusion tests (:52-61) use `make_user()`. The setup-helper test (:85-106) becomes
  `test_setup_helper_leaves_only_the_action_message`: after `law_setup_account_page_content()`,
  the body contains `[action-message]`, matches no `/\[user-content\b/`, and rendering with
  `$_GET['action'] = 'registered'` contains "Registration successful". Delete
  `test_a_subscriber_gets_no_audience_block…` (:117-132): the fallback it protected is gone.
  Keep idempotency (:135) against the renamed helper.
- `tests/BookingsTest.php` :117, :121-131: no role is granted by booking; linking an existing
  `events_committee` account leaves its roles unchanged. `tests/WorkflowTest.php:116`:
  `assertContains( 'subscriber', … )`.
- `tests/HeaderNavTest.php`: `role_expectations()` (:52-61) becomes `[role, owns_event, expected]`
  cases in the NEW order:
  - `subscriber`, no event → `profile, my_bookings, submit, signout`
  - `subscriber`, owns event → `profile, my_bookings, events, submit, signout`
  - `events_committee`, no event → `profile, my_bookings, submit, dashboard, speakers, flagship, bookings, flagship_bookings, discounts, signout`
  - `events_committee`, owns event → the same with `events` after `my_bookings`
  - `administrator` and `editor`, no event → as committee, no event

  `test_items_per_role` creates the user, then `make_event( array(), 'law-proposed', $user_id )`
  when `owns_event`, then `law_account_events_reset_cache()` before `law_header_nav()`. New:
  a subscriber co-owner (`law_event_set_co_owner_ids()`) sees My events; the flagship does not
  count as an owned event; every item carries `icon`, `description`, `group` (committee items
  `group === 'committee'`, signout `'signout'`) and `law_icon()` is non-empty for every icon key.
  Replace `test_attendee_reaches_bookings_and_not_my_events` (:138) and
  `test_host_like_user_sees_both_events_and_bookings` (:153) with the subscriber without / with
  variants. `:80` and `:95` fixtures → `make_user()`. The viewable-pages test (:183) iterates
  `administrator, editor, events_committee, subscriber`. Docblock :269-272 → "the account hub,
  or the dashboard for committee".
- New `tests/AuthRedirectTest.php` (save and restore `$_REQUEST['redirect_to']`): default with no
  `redirect_to` is `home_url( '/account/' )`; a valid same-host `redirect_to` wins; an off-site
  one is dropped; `law_auth_committee_redirect( '', '', $committee )` and
  `( home_url( '/account/' ), '', $committee )` both give `/account/dashboard/`, an explicit other
  URL is kept, a subscriber keeps `/account/`; `law_auth_redirect_to()` with no request arg
  equals `law_auth_default_redirect()`.
- New `tests/AccountHubTest.php`: `law_migration_page_map()['account']['template']` and the
  `$setup['account']` line in setup-account-pages.php both name `templates/account-hub.php`
  (pattern: `MyBookingsPageTest:44-58`); the template file contains `nocache_headers` and
  `is_user_logged_in`; rendering the part (pattern: `FlagshipRenderTest:83-87`, `ob_start` +
  `get_template_part( 'parts/layout/account-tiles', null, array( 'items' => … ) )`) for a
  subscriber without events contains the profile, my_bookings and submit URLs and "Sign out",
  not the events URL or "Committee tools"; for a committee user contains the heading and the six
  committee hrefs; every `<li>` contains exactly one `<svg`; an empty items array renders nothing.
- New `tests/RoleRetirementTest.php`: both gates true for a subscriber and false when signed
  out; the :262 guard fills blanks on a subscriber-only account and on a subscriber+attendee
  account, and writes nothing on `events_committee` or administrator+subscriber accounts;
  `law_migration_retire_user_roles()` on fixtures built with `make_user()` plus `add_role()`
  (event_host+attendee, sponsor, attendee, administrator+event_host, events_committee+attendee):
  dry run changes nothing and reports `dry-run`; real run leaves exactly `subscriber` plus any
  privileged role, `law_intent` equals the expected array (empty array for attendee-only, with
  `metadata_exists()` true), a second call returns `skipped`, and a pre-existing `law_intent`
  row is not overwritten. Test the per-user helper, not `law_migration_run_retire_roles()`.
  `law_setup_account_page_roles()`: skip if `/account/` is missing; every restricted page
  of the four carries `subscriber` afterwards; a second call returns `ok`.
- Run the full suite: `php -d memory_limit=512M vendor/bin/phpunit`. Also
  `--filter 'HeaderNav|AccountAudience|AuthRedirect|AccountHub|RoleRetirement|MyBookingsPage|FlagshipRender|Registration|Bookings|Workflow'`
  while iterating.

---

## 7. Part D: documentation (`EVENTS_FUNC.md`)

Update in the same piece of work:

- `capabilities.php` section (:496-523): the "Host-side roles get nothing" bullet becomes "there
  are no host-side roles; every self-service account is a subscriber; hosting/sponsor intent is
  `law_intent` user meta with no access effect".
- `co-owners.php` (:714-751): default role subscriber.
- `registration.php` (:2251-2286): intents, legacy-roles helper, the rewritten guard, no role sync.
- `submission-form.php` (:2088-2092), `shortcodes.php` (:3095-3108), `account-events.php`
  (:3033-3063): both gates are "signed in"; one redirect on My events; the ownership helper and
  its cache; the empty-state panel.
- `auth.php` (:3064-3079): default `/account/` via `law_auth_default_redirect()`; committee still
  to the dashboard; "Sign out".
- `header-nav.php` (:3080-3094): the new order, the ownership gate, the three hub keys, "the hub
  is built from this function's items, never a second list".
- §4 templates: `templates/account-hub.php`, `parts/layout/account-tiles.php`,
  `assets/css/account-hub.css`, `law_icon()` / `law_icon_paths()` in helpers.php;
  `templates/account.php` now serves the confirmation page only.
- Migration section (:2817-2925): step 11, the subscriber helper in step 10, the content helper
  rename; the step count in §1 (48 files) if a file is added.
- §6 open items: item 4 "Profile roles[] demotion" (:3513) resolved by removal; the "Sponsor
  access parity" block (:3539-3566) marked superseded; security finding 1 (:3526-3530, the
  self-asserted £0 sponsor fee tier) stays open and is now **wider**: any signed-in account can
  submit and pick the sponsor tier, and the sponsor tick is unchecked meta. Recommend the
  approval badge is scheduled.
- Change history: a `### The account hub and the end of the self-service roles (14 September 2026)`
  entry in the style of the 11 September entries, recording the five decisions in section 2,
  the Members `the_content()` finding, and the deploy order in section 11.

---

## 8. What a former host or attendee experiences afterwards

- Signs in → lands on `/account/` (the hub) unless a `redirect_to` was carried. Sees My profile,
  My bookings, My events (if they own or co-own one), Submit an event, Sign out. A committee
  member signs in → `/account/dashboard/` as before; opening `/account/` shows the same personal
  tiles plus "Committee tools".
- The header dropdown shows the same items in the same order.
- The registration form has no role boxes; an optional "Tell us about yourself" group with two
  ticks. The profile form shows the same group, prefilled from what the migration seeded.
- `/account/events/` no longer bounces a person with no events to My bookings; it shows the
  empty-state panel with a Submit button. `?law_booking=` links still forward to My bookings.
- The submit form opens for anyone signed in. The "You can add the Event host role" message is gone.
- Booking a place grants nothing; registering a colleague creates a subscriber.

---

## 9. Order of work

1. A1, A2: registration helpers, handler, profile handler, form parts.
2. A3: the two gates and the dead branch in the event form template. A4: engine grants,
   notification labels, runner log strings.
3. A5: subscriber provisioning helper wired into both routes. A6: step 11, dispatcher,
   page.php warning.
4. B1: ownership helper and cache reset. B2: header nav order, gate, three keys, docblocks.
   B3: auth default redirect at both sites, "Sign out" at both sites.
5. B4: icons. B5: template, part, stylesheet, enqueue gate. B6: page map and setup array,
   content helper rename and rewrite rule.
6. B7: My events redirect removal, empty-state panel and CSS.
7. Part C: tests (fixture default, mass replace, rewrites, new files). Full suite green.
8. Locally: open `/wp-admin/?setup-account-pages` and check page 290 has the hub template and
   its body is the `[action-message]` paragraph only; open the LAW > Migration screen, run step 11
   as a dry run and read the per-user lines, then run it for real (a fresh snapshot and preflight
   are required by the gate); verify with
   `SELECT meta_value, COUNT(*) FROM wp_usermeta WHERE meta_key = 'wp_capabilities' GROUP BY meta_value`
   that only subscriber / administrator / editor / events_committee combinations remain, and
   `SELECT COUNT(*) FROM wp_usermeta WHERE meta_key = 'law_intent'` is about 302. Re-run step 11
   and confirm "Nothing to do".
9. Part D: docs.
10. Closing gates for a build this size: conformance passes against this document, a security
    review (the `security-specialist` agent: registration handler, profile handler, the
    migration step, the Members rows), then offer Denis the browser pass in section 10.

---

## 10. Verification

- PHPUnit full suite green.
- Browser pass (test-specialist, only if Denis opts in), with screenshots and a colour-contrast
  check on the tiles (white on navy about 14:1, orange on navy about 5.1:1):
  1. Register a new account: no role boxes; the optional tick group is present; landing is
     `/account/?action=registered` showing the success callout and the tiles profile / bookings /
     submit / sign out, no My events.
  2. Submit an event from the hub → My events appears in the hub and in the dropdown.
  3. Sign in as committee → lands on `/account/dashboard/`; `/account/` shows "Committee tools".
  4. Sign in as a demoted former attendee → hub; `/account/events/` shows the empty-state panel
     rather than redirecting; `/account/events/?law_booking=<id>` forwards to `/account/bookings/`.
  5. Register an attendee on someone's behalf whose account already exists as a subscriber →
     blank country and dietary are filled (the :262 guard).
  6. Sign out from the hub tile → home page, signed out.
- Migration screen: step 11 listed with its warning; dry run logs one line per user naming the
  roles that would go; real run summary counts match the data table in section 1; re-run reports
  "Nothing to do".

---

## 11. Risks and things that break silently

1. **Production deploy order.** From the moment the code ships, new registrants are subscribers.
   If `/account/`, `/account/events/`, `/account/bookings/` or `/account/events/submit/` there lack
   a `subscriber` Members row, those users are locked out by the Members plugin, not by theme
   code. Run `?setup-account-pages` (or step 10) immediately after the deploy, and only then
   step 11. The step order enforces the second half; nothing enforces the first.
2. **The :262 profile-fill guard** (A1). Emptying the role map without the rewrite disables every
   on-behalf profile fill on existing accounts, with no error and no log line.
3. **A form must not clear what it does not ask about** (A1). The intent ticks came off the
   forms on 14 September 2026, so `law_registration_write_profile_meta()` writes `law_intent`
   only when the input actually carries the key. Without that, the first person to save their
   profile would silently wipe what migration step 11 had just seeded, and with it the only
   translation of the retired roles.
4. **Stored email overrides beat the registry defaults**, and this bit us. Both
   user-registration emails carry a "Roles: {user_roles}" line imported from Gravity Forms by
   migration step 9, so removing it from the code changed nothing until
   `law_setup_strip_user_roles_from_emails()` cleaned the stored bodies too. Both provisioning
   routes run it. Treat every email copy change the same way: check the
   `law_events_email_overrides` option before assuming the registry default is what production
   actually sends.
5. **Both auth redirect sites** (B3) must change together, or the committee shortcut stops firing.
6. **The hub template must gate itself** (B5). Members' content permissions only filter
   `the_content()`, which the hub never calls.
7. **`templates/account.php` must survive** (B5, B6). Page 372 uses it.
8. **Sponsor tier self-assertion widens.** Any account can now submit and choose the £0 sponsor
   tier; the sponsor tick is unchecked meta. Flag to Denis; not fixed here.
9. **HubSpot.** `law_hubspot_contact_type` is written only by these two forms and, as far as the
   theme shows, read by Make outside this codebase (the GF HubSpot feed 15 on form 1 is inactive;
   no webhook feeds exist). Tag strings are unchanged. Denis to confirm with the client that the
   intent ticks are an acceptable source for the Sponsor / Event Host tags.
10. **wp-admin cosmetics.** The Users screen role counts change and the ACF "Role" user field
    (field 434) shows stale ticks for old users and blanks for new ones until the cleanup ticket
    removes the field and the `law_role` rows. `body.role-event_host` selectors in
    `assets/css/gravity-flow.css` stop matching; they only style the retired Gravity Flow inbox.
11. **Tests and the log table.** Driving the full step from a test on a database without
    `wp_law_migration_log` runs DDL that commits the test transaction and leaks fixtures. Test the
    per-user helper.
12. **mu-plugins** (not to be edited). `law-secondary-host-users.php` keeps `event_host` defined
    (intended) and would create `event_host` users again if form 2 (Event > submit an event)
    were reactivated; the source flip keeps it inactive. `law-user-profile-update.php` maps form
    3 (User profile) field 12 to `law_role` and is dead after the cutover. Both are for the
    cleanup ticket, together with `functions/users.php`, `functions/hubspot.php` and GF User
    Registration feed 1 on form 1 (User registration), which hardcodes `event_host`.
