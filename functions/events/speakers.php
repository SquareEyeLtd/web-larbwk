<?php
/**
 * Speaker records (law_speaker): dedupe at save time, lookups, and the
 * relationship helpers events and sessions use (EVENTS_4.1_REBUILD.md §3.2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event cap for an all-status scan of speaker rows (the Manage Speakers
 * dashboard). House style is never -1 on a screen path; the confirmed-only
 * pass keeps its own 500, which is the size of a programme year.
 */
const LAW_SPEAKERS_EVENT_SCAN_CAP = 1000;

/**
 * The role a person has at ONE event (Trevor, 3 September 2026): Speaker,
 * Host or Moderator. Per appearance like the organisation and job title,
 * since the same person hosts one event and moderates another. Stored on the
 * _law_speakers row as the key; '' means "not set", which reads as Speaker
 * and, on a session row, inherits the parent event row's role.
 *
 * @return array<string,string> key => label, in the order the selects offer.
 */
function law_speaker_roles() {
	return array(
		'speaker'   => 'Speaker',
		'host'      => 'Host',
		'moderator' => 'Moderator',
	);
}

/**
 * Normalise a key OR a label (any case, padded) to a role key. '' for
 * anything unknown, so a stray value can never reach the meta. The label
 * match is what lets the migration map form 8 field 9 (Role), whose stored
 * value is the label itself.
 *
 * @param mixed $value Posted or stored value.
 * @return string Role key, or ''.
 */
function law_speaker_role_key( $value ) {
	$value = mb_strtolower( trim( (string) ( is_scalar( $value ) ? $value : '' ) ) );
	if ( '' === $value ) {
		return '';
	}
	foreach ( law_speaker_roles() as $key => $label ) {
		if ( $value === $key || $value === mb_strtolower( $label ) ) {
			return $key;
		}
	}
	return '';
}

/** The label for a role key or label; '' when unknown or empty. */
function law_speaker_role_label( $key ) {
	$key = law_speaker_role_key( $key );
	return '' === $key ? '' : law_speaker_roles()[ $key ];
}

/**
 * What a listing prints after the name. '' (never set: every row migrated or
 * saved before roles existed) reads as Speaker, the default, and prints as
 * such (Denis, 8 September 2026: the role shows on every card). This is the
 * one line to change should the default ever be suppressed.
 *
 * @param string $role Row value (key, label or '').
 * @return string Label to print.
 */
function law_speaker_role_display( $role ) {
	$key = law_speaker_role_key( $role );
	return law_speaker_role_label( '' === $key ? 'speaker' : $key );
}

/**
 * The heading a speaker profile prints over the events a person held this
 * role at. The profile groups its event list by role rather than listing
 * everything under "Speaking at:" (Denis, 11 September 2026), so each role
 * needs a phrase that reads naturally: you speak *at* an event and moderate
 * *at* one, but you host one outright.
 *
 * @return array<string,string> Role key => heading.
 */
function law_speaker_role_headings() {
	return array(
		'speaker'   => 'Speaking at:',
		'host'      => 'Hosting:',
		'moderator' => 'Moderating at:',
	);
}

/**
 * The heading for a role key or label. Anything unknown or empty reads as
 * Speaker, exactly as law_speaker_role_display() does: a row saved before
 * roles existed is still somebody speaking at the event.
 *
 * @param string $role Row value (key, label or '').
 * @return string Heading to print.
 */
function law_speaker_role_heading( $role ) {
	$key = law_speaker_role_key( $role );
	return law_speaker_role_headings()[ '' === $key ? 'speaker' : $key ];
}

/**
 * Find an existing speaker: by email AND name together, then (only when no
 * email was given) by normalised full name.
 *
 * An email on its own no longer identifies a person (Denis, 19 November 2026).
 * Clerks' team inboxes (teamd@oeclaw.co.uk), a firm's events address, or a host
 * typing their own address for several speakers all give one address to
 * several people, and matching on the address alone merged them into one
 * shared, publicly displayed record. Every save of that event then flipped the
 * record's name between the rows: the prod log shows "Lord Richards of
 * Camberwell" and "Dame Elizabeth Gloster" swapping about 20 times on
 * 13 October. So an address match counts only when the name is the same person
 * too (law_speaker_same_person()); the same address under a different name is
 * somebody new who shares an inbox, and gets their own record.
 *
 * A caller that passes an empty $name is looking an address up, not matching a
 * person (the tests, and lookups that only have the address), and gets the
 * first record holding it.
 *
 * The name fallback runs ONLY when no usable email was given. An email is an
 * identity claim, so a row carrying one that matches nothing is a new person,
 * even if somebody with the same name is already on file: two different
 * solicitors called "John Smith" at two different firms must not be collapsed
 * into one shared, publicly-displayed profile that then carries the wrong
 * email. The fallback still exists for the sources that have no email at all,
 * the legacy List field 48 rows the migration reads, which pass '' here.
 * A duplicate record is a nuisance an administrator can merge on LAW →
 * Migration (migration/merge-speakers.php); a wrong merge silently rewrites a
 * real person's profile.
 *
 * The event forms switch the name fallback off ($by_name = false, Denis,
 * 19 November 2026): a host's draft skips validation, an address like
 * "john@firm" passes the browser but not is_email(), and the committee's
 * external-event form validates no speaker rows, and in each case a name match
 * attached the row to whoever already had that name. The next save then wrote
 * the host's email and website onto that other person's shared record. From a
 * form, a row without a usable email is a new speaker.
 *
 * Among the records holding the address, one with the SAME NAME
 * (law_speaker_same_name()) is preferred to a merely similar one, so after a
 * host corrected "Noami" to "Naomi" (which gives the event a new record), the
 * next "Naomi" links to the new record and not to the misspelt one.
 *
 * @param string $email   Email (may be empty).
 * @param string $name    Full display name ('' = look the address up only).
 * @param int    $exclude A record that must not be returned: the one a
 *                        replacement is leaving, so a corrected spelling does
 *                        not link straight back to it.
 * @param bool   $by_name Whether a row with no usable email may match on the
 *                        name alone (the legacy sources do; the forms do not).
 * @return int Post ID, 0 when no match.
 */
function law_speaker_find_existing( $email, $name, $exclude = 0, $by_name = true ) {
	$exclude = (int) $exclude;
	$email = mb_strtolower( trim( (string) $email ) );
	if ( is_email( $email ) ) {
		$by_email = get_posts(
			array(
				'post_type'      => LAW_SPEAKER_CPT,
				'post_status'    => 'any',
				'meta_key'       => '_law_speaker_email',
				'meta_value'     => $email,
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$by_email = array_values( array_diff( array_map( 'intval', $by_email ), array( $exclude ) ) );
		if ( '' === trim( (string) $name ) ) {
			return $by_email ? $by_email[0] : 0;
		}
		foreach ( array( 'law_speaker_same_name', 'law_speaker_same_person' ) as $test ) {
			foreach ( $by_email as $candidate ) {
				if ( $test( law_speaker_raw_name( $candidate ), $name ) ) {
					return $candidate;
				}
			}
		}
		return 0; // A real email with no same-named holder: a new person, not a name match.
	}

	$normalised = law_speaker_normalise_name( $name );
	if ( ! $by_name || '' === $normalised ) {
		return 0;
	}
	$candidates = get_posts(
		array(
			'post_type'      => LAW_SPEAKER_CPT,
			'post_status'    => 'any',
			'title'          => trim( (string) $name ),
			'fields'         => 'ids',
			'posts_per_page' => 5,
		)
	);
	foreach ( $candidates as $candidate ) {
		if ( (int) $candidate !== $exclude && law_speaker_normalise_name( law_speaker_raw_name( $candidate ) ) === $normalised ) {
			return (int) $candidate;
		}
	}
	return 0;
}

/**
 * Case- and whitespace-insensitive name key, mirroring the old dedupe. HTML
 * entities are decoded first, so a name that reached one side of a comparison
 * through get_the_title() (wptexturize turns an apostrophe into &#8217;) still
 * matches the same name read raw from the database. Without that, a session's
 * link to "Crystal O'Donnell" silently failed to match and was dropped on save.
 */
function law_speaker_normalise_name( $name ) {
	$name = html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
	// Decoding &#8217; gives a CURLY apostrophe, so the two spellings still would
	// not match without folding the smart punctuation back to its plain form.
	$name = strtr(
		$name,
		array( "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => '-' )
	);
	return preg_replace( '/\s+/', ' ', mb_strtolower( trim( $name ) ) );
}

/**
 * Words in a name that say nothing about WHO the person is: titles,
 * honorifics, post-nominals, name particles and the "TBC" a host types while a
 * speaker is unconfirmed. law_speaker_name_tokens() drops them, together with
 * LAW_SPEAKER_NAME_SUFFIXES, so "Toby Landau" and "Toby Landau KC", or "Prof.
 * Dr. Maxi Scherer" and "Maxi Scherer", compare as the same person.
 */
const LAW_SPEAKER_NAME_NOISE = array( 'mr', 'mrs', 'ms', 'miss', 'mx', 'dr', 'prof', 'professor', 'sir', 'dame', 'lord', 'lady', 'the', 'rt', 'hon', 'honourable', 'justice', 'judge', 'hhj', 'his', 'her', 'honour', 'of', 'and', 'but', 'tba', 'tbd', 'dbe', 'pc', 'kcmg', 'llm', 'fciarb', 'jr', 'sr', 'ii', 'iii', 'de', 'da', 'di', 'van', 'von', 'der', 'le', 'la', 'du' );

/**
 * The words that identify a person, for law_speaker_same_person(): lower case,
 * accents folded, a dotted abbreviation closed up ("S.C." → "sc", so it is
 * recognised as a post-nominal), anything in brackets dropped ("(Scot)",
 * "(Johan)"), every run of punctuation a word break (so a hyphen, a
 * non-breaking hyphen and a bullet all split the same way), and
 * LAW_SPEAKER_NAME_NOISE / the honorific suffixes dropped. Initials are KEPT
 * (19 November 2026): "A. Smith" and "B. Smith" are different people.
 *
 * @param string $name Full name.
 * @return string[] Unique tokens, in order.
 */
function law_speaker_name_tokens( $name ) {
	$name  = remove_accents( law_speaker_normalise_name( $name ) );
	$name  = (string) preg_replace_callback( '/(?<![\p{L}\p{N}])(?:\p{L}\.){2,}/u', fn( $m ) => str_replace( '.', '', $m[0] ), $name );
	$name  = preg_replace( '/\([^)]*\)/u', ' ', $name );
	$name  = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name );
	$noise = array_merge( LAW_SPEAKER_NAME_NOISE, array_map( 'strtolower', LAW_SPEAKER_NAME_SUFFIXES ) );
	$out   = array();
	foreach ( explode( ' ', trim( $name ) ) as $token ) {
		if ( '' === $token || in_array( $token, $noise, true ) ) {
			continue;
		}
		$out[] = $token;
	}
	return array_values( array_unique( $out ) );
}

/**
 * Whether a name is a stand-in rather than a person: "TBC", "Other speakers
 * to be confirmed shortly", "Law firm counsel (TBC)". Such a record is matched
 * by name across events (it has no email), so it is often shared, and typing
 * the real speaker over it must never rename the shared stand-in: record 745 on
 * prod was "Other speakers to be confirmed shortly" on two events until one
 * host made it Kevin Nash and the other made it Simon Dack.
 *
 * @param string $name Full name.
 */
function law_speaker_is_placeholder_name( $name ) {
	if ( ! law_speaker_name_tokens( $name ) ) {
		return true;
	}
	return (bool) preg_match( '/\b(speakers?|to be (confirmed|announced)|counsel|panell?ists?|moderators?|representatives?)\b/u', law_speaker_normalise_name( $name ) );
}

/**
 * A name with capitals, accents, spaces and punctuation taken out: "NiaLL
 * McCulloch KC", "Niall Mc-Culloch KC" and "niall mcculloch kc" all give
 * "niallmccullochkc".
 */
function law_speaker_name_key( $name ) {
	return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', remove_accents( law_speaker_normalise_name( $name ) ) );
}

/**
 * Whether an edited name is still the SAME NAME (Denis, 19 November 2026):
 * identical once capitals, accents, spaces and punctuation are ignored.
 *
 * This is what the event form uses to tell a CORRECTION from a REPLACEMENT.
 * Only a change of that kind ("NAOMI SMITH" → "Naomi Smith", "Noradele" →
 * "Noradèle") is written to the shared record, and so to every event the
 * speaker appears at. ANY other change, a fixed typo or an added "KC"
 * included, gives this event a new speaker and leaves the shared record alone.
 * Denis chose no guessing over a similarity test: a host can then never change
 * the name another event shows. The cost, measured on prod: of the 26
 * corrections hosts made between 18 September and 19 November 2026, 13 were of
 * this cosmetic kind and still correct everywhere; of the other 13, four were
 * on speakers shared with other events, which now leave two profiles of the
 * same person for the committee to merge (or the committee corrects the
 * spelling on Manage Speakers, which writes it everywhere).
 *
 * @param string $a Full name.
 * @param string $b Full name.
 */
function law_speaker_same_name( $a, $b ) {
	return law_speaker_name_key( $a ) === law_speaker_name_key( $b );
}

/**
 * Whether two names are the same human written more or less fully. This is the
 * FORGIVING test, used only where a wrong answer cannot rewrite anybody:
 * linking a newly added row to an existing record (law_speaker_find_existing(),
 * which only fills gaps on a match), spotting the same person on two rows, the
 * address clash checks, and keeping this event's photo when a host fixed a
 * name. Deciding whether an EDIT may change a shared record is
 * law_speaker_same_name()'s job, which is strict.
 *
 * Same person: every identifying word of the shorter name appears, EXACTLY, in
 * the longer one, once capitals, accents, punctuation, titles and post-nominals
 * are set aside. So an added or dropped middle name or "KC" still matches
 * ("Toby Landau" / "Toby Landau KC"), and nothing else does. There is no typo
 * allowance (Denis, 19 November 2026): it read Maria and Mario Rossi, or
 * Christian and Christina, as one person, which with a shared clerks' inbox
 * linked one to the other's record. A name with fewer than two identifying words
 * ("Mr Justice Smith", "Lord Gloster", a lone surname) must be the same name
 * outright, because one word is too thin to call two people the same and the
 * titles that were set aside may be all that tells them apart. Placeholders are
 * only ever the same as themselves.
 *
 * Of the 80 distinct renames hosts made on the prod event form between
 * 18 September and 19 November 2026, all 54 replacements read as different
 * people; tests/SpeakerIdentityTest.php keeps them as fixtures.
 *
 * When it is wrong it errs towards "different": that costs a duplicate record,
 * which the committee can merge.
 *
 * @param string $a Full name.
 * @param string $b Full name.
 */
function law_speaker_same_person( $a, $b ) {
	if ( law_speaker_is_placeholder_name( $a ) || law_speaker_is_placeholder_name( $b ) ) {
		return law_speaker_normalise_name( $a ) === law_speaker_normalise_name( $b );
	}
	$short = law_speaker_name_tokens( $a );
	$long  = law_speaker_name_tokens( $b );
	if ( count( $short ) > count( $long ) ) {
		list( $short, $long ) = array( $long, $short );
	}
	if ( count( $short ) < 2 ) {
		return law_speaker_same_name( $a, $b );
	}
	return ! array_diff( $short, $long );
}

/**
 * A speaker post's stored display name, UNFILTERED.
 *
 * get_the_title() runs the_title, and wptexturize rewrites an apostrophe as
 * &#8217;. That is right for rendering and wrong everywhere else: a form field
 * prefilled from it shows the raw entity to the host, and saving writes that
 * entity back into post_title, compounding on every save. The rule in this
 * module is therefore: get_the_title() to DISPLAY a name, this to MATCH one or
 * to put one into a form field.
 *
 * @param int $post_id Speaker post ID.
 */
function law_speaker_raw_name( $post_id ) {
	return trim( (string) get_post_field( 'post_title', (int) $post_id, 'raw' ) );
}

/**
 * Honorific suffixes that belong to the LAST name, so splitting a stored full
 * name gives "Ali" / "Malek KC" and never "Ali Malek" / "KC". The same list
 * the legacy list-field migrator uses (functions/migrate-speakers.php).
 */
const LAW_SPEAKER_NAME_SUFFIXES = array( 'KC', 'QC', 'SC', 'JP', 'OBE', 'CBE', 'MBE', 'KBE', 'CB', 'CMG', 'CVO', 'GCVO', 'PHD', 'ESQ', 'TBC' );

/**
 * Split a full name into first and last. This is the FALLBACK only: since
 * 9 September 2026 (Denis) the forms collect the two separately and store them
 * on the speaker post, and this reads a record saved before that, or a legacy
 * source that only ever had one name field. The last token is the last name,
 * with any honorific suffixes kept on it; a one-word name is all first name,
 * which is what the old law_speaker_post_profile() split did too.
 *
 * @param string $full Full name.
 * @return array{first:string,last:string}
 */
function law_speaker_split_name( $full ) {
	$full = trim( preg_replace( '/\s+/', ' ', (string) $full ) );
	if ( '' === $full ) {
		return array( 'first' => '', 'last' => '' );
	}
	$parts    = explode( ' ', $full );
	$suffixes = array();
	while ( count( $parts ) > 1 ) {
		$token = rtrim( (string) end( $parts ), '.,' );
		if ( in_array( mb_strtoupper( $token ), LAW_SPEAKER_NAME_SUFFIXES, true ) ) {
			array_unshift( $suffixes, array_pop( $parts ) );
			continue;
		}
		break;
	}
	if ( count( $parts ) < 2 ) {
		// One word, suffixes or not: it is the first name, nothing to split.
		return array( 'first' => $full, 'last' => '' );
	}
	$last = array_pop( $parts );
	return array(
		'first' => implode( ' ', $parts ),
		'last'  => trim( $last . ' ' . implode( ' ', $suffixes ) ),
	);
}

/** The display name two parts make: "First Last", collapsed and trimmed. */
function law_speaker_full_name( $first, $last ) {
	return trim( preg_replace( '/\s+/', ' ', trim( (string) $first ) . ' ' . trim( (string) $last ) ) );
}

/**
 * A speaker post's name in two parts: the stored meta when it has it, else the
 * post title split (every record saved before the fields were separate).
 *
 * @param int $post_id Speaker post ID.
 * @return array{first:string,last:string}
 */
function law_speaker_name_parts( $post_id ) {
	$first = trim( (string) law_event_meta( $post_id, '_law_speaker_first_name' ) );
	$last  = trim( (string) law_event_meta( $post_id, '_law_speaker_last_name' ) );
	if ( '' !== $first || '' !== $last ) {
		return array( 'first' => $first, 'last' => $last );
	}
	// The RAW title, not get_the_title(): these two values are prefilled into the
	// First/Last name inputs on three different editing screens, and each of
	// them rebuilds post_title from what comes back.
	return law_speaker_split_name( law_speaker_raw_name( $post_id ) );
}

/**
 * The two parts a submitted row carries. A row that only has the old single
 * 'name' key (a legacy source, or a saved payload from before the split) is
 * split, so no caller has to know which shape it holds.
 *
 * @param array $row first_name / last_name, or name.
 * @return array{first:string,last:string}
 */
function law_speaker_row_name_parts( array $row ) {
	$first = trim( (string) ( $row['first_name'] ?? '' ) );
	$last  = trim( (string) ( $row['last_name'] ?? '' ) );
	if ( '' !== $first || '' !== $last ) {
		return array( 'first' => $first, 'last' => $last );
	}
	return law_speaker_split_name( (string) ( $row['name'] ?? '' ) );
}

/**
 * Create or update a speaker from submitted row data. New data fills gaps;
 * it never blanks an existing value (matching the old backfill rule).
 *
 * Only identity fields live on the post: first name, last name, email,
 * website. Organisation, job title, photo and biography are per appearance and
 * belong on the event's _law_speakers row (law_events_form_save_speakers());
 * the featured image and the biography set here are fallbacks only, used when
 * the appearance has none of its own.
 *
 * @param array $data    first_name, last_name (a single 'name' is split when
 *                       the parts are absent), email, website, bio, photo_id
 *                       (attachment ID). organisation/job_title are accepted
 *                       and ignored.
 * @param array $log     event_id + actor, for the activity-log line.
 * @param array $options speaker_id: edit THIS record rather than matching one
 *                       (the caller must have already checked the row belongs
 *                       to it). overwrite_identity: write the identity fields
 *                       outright instead of only filling gaps, see below. A
 *                       name that is not the SAME NAME as the record's
 *                       (law_speaker_same_name()) is a replacement, never an
 *                       edit: the record is left alone and the data is matched
 *                       or created as if new, never back onto that record.
 *                       shown: the name/email/website the form displayed for
 *                       this row ('name', 'email', 'website'); a field posted
 *                       back unchanged is NOT written and an unchanged name is
 *                       never a replacement, so a form opened before somebody
 *                       else corrected the record cannot undo the correction.
 *                       by_name: false for the event forms, so a row without a
 *                       usable email is never matched on its name alone (see
 *                       law_speaker_find_existing()).
 * @return int Post ID, 0 on failure.
 */
function law_speaker_upsert( array $data, array $log = array(), array $options = array() ) {
	// The post title stays the display name every listing prints and every
	// lookup matches on; the two parts are stored beside it (Denis,
	// 9 September 2026), and a caller that still passes one 'name' is split.
	$parts = law_speaker_row_name_parts( $data );
	$name  = law_speaker_full_name( $parts['first'], $parts['last'] );
	if ( '' === $name ) {
		return 0;
	}

	// An explicit record beats matching on email/name: it is the only way a
	// caller can edit the very fields the match is made on. Callers pass one
	// only for a speaker the event already holds (law_events_form_save_speakers()).
	//
	// But only while it is still the same name (Denis, 19 November 2026).
	// Hosts replace a speaker by typing the new person over the old row far more
	// often than they correct one: 54 of the 80 distinct renames made on the prod
	// event form were replacements, and each renamed a record shared with every
	// other event that person appears at (Domitille Baizeau became Noradèle
	// Radjai on two events, Campbell Herbert became Kathleen Donnelly KC). So any
	// change beyond capitals, accents, spaces and punctuation is a REPLACEMENT,
	// a fixed typo included: the old record keeps its name, email, URL and photo
	// for its other events, and this data goes through match-or-create like a
	// newly added row, which links to the person's own record when they already
	// have one. The old record is excluded from that match, so a corrected
	// spelling cannot link straight back to the misspelt record it is leaving.
	//
	// What the host did not touch is not a change at all (19 November 2026).
	// A form opened before the committee corrected "Jon" to "John" on Manage
	// Speakers still posts "Jon"; read as an edit, that split the record and
	// undid the correction for this event. So a name posted back exactly as the
	// form showed it keeps the row on its record and is not written, and the
	// same goes for the email and the website below. A forged "shown" can only
	// keep a row on the record it already holds and stop fields being written;
	// the email and website it might then let through are ones a host may
	// change for the same person anyway (Denis, 19 November 2026: they apply on
	// every event).
	$shown          = (array) ( $options['shown'] ?? array() );
	$name_untouched = isset( $shown['name'] ) && law_speaker_full_name( (string) $shown['name'], '' ) === $name;
	$post_id        = 0;
	$replaced       = 0;
	if ( ! empty( $options['speaker_id'] ) ) {
		$target = get_post( (int) $options['speaker_id'] );
		if ( $target && LAW_SPEAKER_CPT === $target->post_type ) {
			if ( ! empty( $options['overwrite_identity'] ) && ! $name_untouched && ! law_speaker_same_name( law_speaker_raw_name( $target->ID ), $name ) ) {
				$replaced = (int) $target->ID;
			} else {
				$post_id = (int) $target->ID;
			}
		}
	}
	// A replacement does not inherit the website the form showed for the person
	// it replaced: a host who types a new name and email over a row and leaves
	// the website box alone is not giving the new person that page. Without this
	// it was gap-filled onto the new person's profile (the Chris Bogart /
	// Geoff Nicholas pattern the repair panel cleans up). The email is left as
	// typed: one address can serve several people.
	if ( $replaced && isset( $shown['website'] ) && trim( (string) $shown['website'] ) === trim( (string) ( $data['website'] ?? '' ) ) ) {
		$data['website'] = '';
	}
	if ( ! $post_id ) {
		$post_id = law_speaker_find_existing( (string) ( $data['email'] ?? '' ), $name, $replaced, ! isset( $options['by_name'] ) || $options['by_name'] );
	}

	// The host has given this speaker an address that another record of the
	// SAME person already holds: the person already has a profile, so the row
	// moves onto it. Without this the address was dropped by the clash check
	// below, silently, on every save: a draft saved with no email had created
	// an empty record, the full submit's address belonged to the person's real
	// profile, and the form then showed the email blank and demanded it again.
	$joined = 0;
	if ( $post_id && ! $replaced && ! empty( $options['overwrite_identity'] ) ) {
		$given = mb_strtolower( trim( (string) ( $data['email'] ?? '' ) ) );
		$was   = isset( $shown['email'] ) ? mb_strtolower( trim( (string) $shown['email'] ) ) : null;
		if ( is_email( $given ) && $given !== $was && $given !== mb_strtolower( (string) law_event_meta( $post_id, '_law_speaker_email' ) ) ) {
			$other = law_speaker_find_existing( $given, $name, $post_id, false );
			if ( $other ) {
				$joined  = $post_id;
				$post_id = $other;
			}
		}
	}
	$is_new    = ! $post_id;
	$overwrite = ! $is_new && ! $replaced && ! $joined && ! empty( $options['overwrite_identity'] );
	$filled    = array(); // Fields this submission backfilled on a PRE-EXISTING record.
	$changed   = array(); // Identity fields this submission REPLACED (overwrite mode).

	if ( ! $post_id ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => LAW_SPEAKER_CPT,
				'post_status'  => 'publish', // Visibility is derived from events, not from status.
				'post_title'   => $name,
				'post_content' => law_rich_text_sanitize( $data['bio'] ?? '' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}
	} else {
		// Backfill an empty biography only.
		$existing = get_post( $post_id );
		if ( $existing && '' === trim( $existing->post_content ) && '' !== trim( (string) ( $data['bio'] ?? '' ) ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_content' => law_rich_text_sanitize( $data['bio'] ) ) );
			$filled[] = 'biography';
		}
	}

	$meta_map = array(
		'email'   => array( 'key' => '_law_speaker_email', 'label' => 'email address' ),
		'website' => array( 'key' => '_law_website', 'label' => 'website' ),
	);
	foreach ( $meta_map as $field => $spec ) {
		$key    = $spec['key'];
		$value  = trim( (string) ( $data[ $field ] ?? '' ) );
		$value  = 'email' === $field ? mb_strtolower( $value ) : $value;
		$stored = (string) law_event_meta( $post_id, $key );

		if ( $overwrite ) {
			if ( $value === $stored ) {
				continue;
			}
			// Posted back exactly as the form showed it: the host did not change
			// it, so a value somebody else has since stored stands.
			if ( isset( $shown[ $field ] ) ) {
				$was = trim( (string) $shown[ $field ] );
				if ( ( 'email' === $field ? mb_strtolower( $was ) : $was ) === $value ) {
					continue;
				}
			}
			// A blank email means "not given", never "delete the one on file"
			// (15 September 2026). law_event_update_meta() turns '' into a
			// delete_post_meta(), so without this the record loses its dedupe
			// key — and the record is shared with every other event this person
			// appears at, so every future match for them silently falls back to
			// name matching. The host event form cannot post a blank (the field
			// is required there), but the committee's external-event form
			// validates no speaker rows at all (law_external_event_validate()),
			// and a row rebuilt by hand or by a later caller can. Clearing an
			// address outright is a Manage Speakers action, where a committee
			// member means it: law_speakers_dashboard_write_identity() writes
			// identity directly rather than through this function, exactly so
			// that it can. The website has no such rule — it is nobody's
			// identity, and an emptied box there is an ordinary correction.
			if ( 'email' === $field && '' === $value ) {
				continue;
			}
			// The email must never be moved onto an address another record of
			// the SAME person already owns: that would leave two records that
			// match the same row. Same guard the Manage Speakers screen applies,
			// except that here the rest of the row still saves — the host gets
			// their correction, and the clashing email is simply left alone. An
			// address held by a differently named record is a shared inbox and is
			// fine (19 November 2026). $value is non-empty by the check above.
			if ( 'email' === $field ) {
				$clash = law_speaker_find_existing( $value, $name );
				if ( $clash && (int) $clash !== (int) $post_id ) {
					continue;
				}
			}
			law_event_update_meta( $post_id, $key, $value );
			$changed[] = sprintf( '%s "%s" → "%s"', $spec['label'], $stored, $value );
			continue;
		}

		if ( '' !== $value && '' === $stored ) {
			law_event_update_meta( $post_id, $key, $value );
			if ( ! $is_new ) {
				$filled[] = $field;
			}
		}
	}

	if ( $overwrite && ! $name_untouched ) {
		// The row belongs to an event the saver is editing, so the name is
		// written outright rather than gap-filled: the host typed it into a
		// required field, and silently discarding it is worse than a logged
		// change on a shared record (Denis, 9 September 2026). post_name is left
		// alone, so an existing /speakers/<slug>/ link keeps resolving after a
		// correction — the same rule the Manage Speakers screen follows.
		$existing_name = law_speaker_raw_name( $post_id );
		if ( $existing_name !== $name ) {
			wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_title' => $name ) ) );
			$changed[] = sprintf( 'name "%s" → "%s"', $existing_name, $name );
		}
		foreach ( array( 'first' => '_law_speaker_first_name', 'last' => '_law_speaker_last_name' ) as $part => $key ) {
			law_event_update_meta( $post_id, $key, $parts[ $part ] );
		}
	} elseif ( ! $overwrite ) {
		// The name parts, gap-filled like everything else here: a record created
		// before the split (or by an import that only had one name field) gets
		// them on the next submission, and a submission never renames somebody
		// else's shared record. Not logged as a backfill: the displayed name does
		// not change, only its stored shape.
		foreach ( array( 'first' => '_law_speaker_first_name', 'last' => '_law_speaker_last_name' ) as $part => $key ) {
			if ( '' !== $parts[ $part ] && '' === (string) law_event_meta( $post_id, $key ) ) {
				law_event_update_meta( $post_id, $key, $parts[ $part ] );
			}
		}
	}

	if ( ! empty( $data['photo_id'] ) && ! has_post_thumbnail( $post_id ) ) {
		set_post_thumbnail( $post_id, (int) $data['photo_id'] );
		if ( ! $is_new ) {
			$filled[] = 'photo';
		}
	}

	if ( $joined && ! empty( $log['event_id'] ) && function_exists( 'law_event_log' ) ) {
		law_event_log(
			(int) $log['event_id'],
			sprintf( 'Speaker "%s" moved to their existing profile (#%d), which holds the address given (%s).', $name, (int) $post_id, mb_strtolower( trim( (string) ( $data['email'] ?? '' ) ) ) ),
			array( 'action' => 'speaker_linked', 'speaker' => (int) $post_id, 'left' => $joined, 'source' => 'submission' ),
			array( 'user_id' => (int) ( $log['actor'] ?? 0 ) )
		);
	}

	// A replacement leaves the old record exactly as it was, so the log says
	// what happened on THIS event: which person went and which came.
	if ( $replaced && ! empty( $log['event_id'] ) && function_exists( 'law_event_log' ) ) {
		law_event_log(
			(int) $log['event_id'],
			sprintf(
				'Speaker "%s" replaced by "%s" on this event. Their profile is unchanged; "%s" has %s.',
				law_speaker_raw_name( $replaced ),
				$name,
				$name,
				$is_new ? 'a new profile' : 'their own existing profile'
			),
			array( 'action' => 'speaker_replaced', 'speaker' => (int) $post_id, 'replaced' => $replaced, 'source' => 'submission' ),
			array( 'user_id' => (int) ( $log['actor'] ?? 0 ) )
		);
	}

	// A rename or a contact-detail change on a shared, publicly-displayed
	// profile shows on every event that speaker appears at, so it is always
	// recorded — the committee can see who changed what, and put it back.
	if ( $changed && ! empty( $log['event_id'] ) && function_exists( 'law_event_log' ) ) {
		law_event_log(
			(int) $log['event_id'],
			sprintf( 'Speaker profile updated from this submission: %s.', implode( ', ', $changed ) ),
			array( 'action' => 'speaker_updated', 'speaker' => (int) $post_id, 'changes' => $changed, 'source' => 'submission' ),
			array( 'user_id' => (int) ( $log['actor'] ?? 0 ) )
		);
	}

	// A shared, publicly-displayed speaker profile was altered by a host other
	// than its originator; record it on the event so the committee can spot a
	// bad backfill against a real speaker's record during review.
	if ( ! $is_new && $filled && ! empty( $log['event_id'] ) && function_exists( 'law_event_log' ) ) {
		law_event_log(
			(int) $log['event_id'],
			sprintf( 'Existing speaker profile "%s" backfilled from this submission: %s.', $name, implode( ', ', $filled ) ),
			array( 'action' => 'speaker_backfilled', 'speaker' => (int) $post_id, 'fields' => $filled, 'source' => 'submission' ),
			array( 'user_id' => (int) ( $log['actor'] ?? 0 ) )
		);
	}

	return (int) $post_id;
}

/**
 * Public speaker profile array in the shape functions/speakers.php renders
 * (id, first/last, name, organisation, job_title, url, photo, bio, email,
 * event_ids, appearances).
 *
 * organisation, job_title, photo and bio are those of the speaker's FIRST
 * confirmed appearance (earliest event), the archive rule Denis settled;
 * the per-event values are in 'appearances' for the profile's event cards.
 *
 * @param int   $post_id   Speaker post ID.
 * @param int[] $event_ids Confirmed events referencing this speaker.
 */
function law_speaker_post_profile( $post_id, array $event_ids = array() ) {
	$post = get_post( $post_id );
	if ( ! $post || LAW_SPEAKER_CPT !== $post->post_type ) {
		return null;
	}

	$name  = trim( $post->post_title );
	$parts = law_speaker_name_parts( $post->ID );
	$first = $parts['first'];
	$last  = $parts['last'];
	$seen  = law_speaker_first_appearance( $post->ID );

	return array(
		'id'           => (int) $post->ID,
		'first_name'   => $first,
		'last_name'    => $last,
		'name'         => $name,
		'organisation' => $seen['organisation'],
		'job_title'    => $seen['job_title'],
		'url'          => (string) law_event_meta( $post->ID, '_law_website' ),
		'photo'        => law_speaker_photo_url( $post->ID, $seen['photo_id'], 'medium' ),
		// The appearance's own, never the record's post_content: see law_speaker_card().
		'bio'          => $seen['bio'],
		'email'        => (string) law_event_meta( $post->ID, '_law_speaker_email' ),
		'entry_ids'    => array( (int) $post->ID ),
		'event_ids'    => array_map( 'intval', $event_ids ),
		'appearances'  => law_speaker_appearances( $post->ID ),
	);
}

/**
 * Confirmed (publish) event IDs that reference a set of speakers, keyed by
 * speaker ID. One query pass over the published events' speaker rows; the
 * same pass records each speaker's appearance row per event for
 * law_speaker_appearances().
 *
 * @return array<int,int[]> speaker_id => event post IDs.
 */
function law_speakers_confirmed_event_map() {
	return law_speakers_confirmed_maps()['events'];
}

/**
 * The single pass over a set of events' speaker rows, memoised per status set.
 * Returns two maps: 'events' (speaker_id => event IDs) and 'appearances'
 * (speaker_id => [event_id => the row for that event]).
 *
 * Extracted from law_speakers_confirmed_maps() so the committee's Manage
 * Speakers dashboard can walk EVERY status with the same code: a speaker post
 * exists from the first draft save onwards, and the committee edits appearances
 * long before an event is confirmed. The public archive still asks for
 * 'publish' only, through the wrapper below.
 *
 * @param string[] $statuses post_status values to walk.
 * @param int      $limit    Event cap (house style: never -1 on a screen path).
 * @param bool     $reset    Rebuild (tests create events mid-request).
 * @param bool     $listed_only Leave out private events (the public archive).
 * @return array{events:array<int,int[]>,appearances:array<int,array<int,array>>}
 */
function law_speakers_event_maps( array $statuses, $limit = 500, $reset = false, $listed_only = false ) {
	$cache = &law_speakers_event_maps_cache();
	$key   = implode( ',', $statuses ) . '|' . (int) $limit . ( $listed_only ? '|listed' : '' );
	if ( $reset ) {
		// Every key, not just this one: whatever invalidated one status set
		// (a test creating an event, a save mid-request) invalidated the rest.
		$cache = array();
	} elseif ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	$maps = array( 'events' => array(), 'appearances' => array() );

	$events = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => $statuses,
			'fields'         => 'ids',
			'posts_per_page' => (int) $limit,
		)
	);

	foreach ( $events as $event_id ) {
		$event_id = (int) $event_id;
		// The public archive and profiles leave out a private event (Denis,
		// 13 November 2026): a profile listing one would link to it, code and
		// all, and a speaker known only from one would be a public trace of it.
		if ( $listed_only && function_exists( 'law_event_is_private' ) && law_event_is_private( $event_id ) ) {
			continue;
		}
		foreach ( law_event_meta( $event_id, '_law_speakers' ) as $row ) {
			$speaker_id = (int) ( $row['speaker_id'] ?? 0 );
			if ( $speaker_id ) {
				$maps['events'][ $speaker_id ][]                 = $event_id;
				$maps['appearances'][ $speaker_id ][ $event_id ] = $row;
			}
		}
		// Session-level speakers appear in the archive too: they speak at the event.
		foreach ( law_event_session_ids( $event_id ) as $session_id ) {
			foreach ( law_event_meta( $session_id, '_law_speakers' ) as $row ) {
				$speaker_id = (int) ( $row['speaker_id'] ?? 0 );
				if ( ! $speaker_id ) {
					continue;
				}
				if ( empty( $maps['events'][ $speaker_id ] ) || ! in_array( $event_id, $maps['events'][ $speaker_id ], true ) ) {
					$maps['events'][ $speaker_id ][] = $event_id;
				}
				// The event-level row wins; a session row only stands in when
				// the speaker is not on the event itself.
				if ( ! isset( $maps['appearances'][ $speaker_id ][ $event_id ] ) ) {
					$maps['appearances'][ $speaker_id ][ $event_id ] = $row;
				}
			}
		}
	}

	$cache[ $key ] = $maps;

	return $maps;
}

/** The memo behind law_speakers_event_maps(), by reference so it can be flushed. */
function &law_speakers_event_maps_cache() {
	static $cache = array();
	return $cache;
}

/** Drop every memoised speaker map (a save, or a test creating events mid-request). */
function law_speakers_flush_maps() {
	$cache = &law_speakers_event_maps_cache();
	$cache = array();
}

/**
 * The published-events pass the public archive and profile read: a thin wrapper
 * over law_speakers_event_maps(), kept because every existing caller (and
 * tests/SpeakerRolesTest.php) names it and passes $reset.
 *
 * @param bool $reset Rebuild (tests create events mid-request).
 * @return array{events:array<int,int[]>,appearances:array<int,array<int,array>>}
 */
function law_speakers_confirmed_maps( $reset = false ) {
	return law_speakers_event_maps( array( 'publish' ), 500, $reset, true );
}

/**
 * A speaker's appearances, first submitted first: one row per event with the
 * role, organisation, job title, photo and biography they had at THAT event.
 *
 * Confirmed events only by default, which is what the public archive and the
 * profile mean by an appearance. The committee's Manage Speakers dashboard
 * passes every status instead, because a speaker record exists from the first
 * draft save and an appearance is most worth correcting before the event is
 * confirmed.
 *
 * @param int           $speaker_id Speaker post ID.
 * @param string[]|null $statuses   Event statuses to read; null = publish only.
 * @return array<int,array{event_id:int,role:string,organisation:string,job_title:string,photo_id:int,bio:string}>
 */
function law_speaker_appearances( $speaker_id, ?array $statuses = null ) {
	$maps = null === $statuses
		? law_speakers_confirmed_maps()
		: law_speakers_event_maps( $statuses, LAW_SPEAKERS_EVENT_SCAN_CAP );
	$rows = $maps['appearances'][ (int) $speaker_id ] ?? array();

	$appearances = array();
	foreach ( $rows as $event_id => $row ) {
		$appearances[] = array(
			'event_id'     => (int) $event_id,
			'role'         => law_speaker_role_key( $row['role'] ?? '' ),
			'organisation' => trim( (string) ( $row['organisation'] ?? '' ) ),
			'job_title'    => trim( (string) ( $row['job_title'] ?? '' ) ),
			'photo_id'     => (int) ( $row['photo_id'] ?? 0 ),
			'bio'          => trim( (string) ( $row['bio'] ?? '' ) ),
			'sort'         => law_speaker_event_sort_key( (int) $event_id ),
		);
	}
	usort( $appearances, fn( $a, $b ) => strcmp( $a['sort'], $b['sort'] ) );
	foreach ( $appearances as &$appearance ) {
		unset( $appearance['sort'] );
	}
	unset( $appearance );
	return $appearances;
}

/** Chronological key for an event: start datetime, then post date, then ID (unscheduled events last). */
function law_speaker_event_sort_key( $event_id ) {
	// Submission order (creation date, which migrated events keep from the
	// legacy entry), then ID. "First appearance" means the first time the
	// speaker was submitted to LAW, not the earliest event date: two events on
	// the same day would otherwise let a brand-new submission outrank a
	// years-old record (Denis, 8 September 2026).
	return (string) get_post_field( 'post_date_gmt', $event_id ) . ' ' . str_pad( (string) $event_id, 10, '0', STR_PAD_LEFT );
}

/**
 * The photo for a speaker on an event page (speakers section, sessions, the
 * host dashboard): the photo set specifically for THIS event, else the first
 * photo ever provided for the speaker (their first-submitted confirmed
 * appearance carrying one), else nothing, so the template shows the initials
 * placeholder (Denis, 8 September 2026). The archive and profile use the first
 * photo directly (law_speaker_first_appearance()). law_speaker_photo_url()
 * still consults the featured image last, for a photo the committee set on
 * the speaker itself in wp-admin.
 *
 * @param int $speaker_id   Speaker post ID.
 * @param int $row_photo_id The rendered row's own photo_id, 0 for none.
 */
function law_speaker_display_photo_id( $speaker_id, $row_photo_id = 0 ) {
	return (int) $row_photo_id ?: (int) law_speaker_first_appearance( $speaker_id )['photo_id'];
}

/**
 * The speaker's FIRST appearance on record: organisation, job title, photo and
 * biography from the earliest confirmed event, each field falling through to
 * the next appearance when the earlier row leaves it empty (so a row without a
 * photo does not blank the card). Used by the archive card and the profile.
 *
 * @param int           $speaker_id Speaker post ID.
 * @param string[]|null $statuses   Event statuses to read; null = publish only.
 * @return array{organisation:string,job_title:string,photo_id:int,bio:string,event_id:int}
 */
function law_speaker_first_appearance( $speaker_id, ?array $statuses = null ) {
	$first  = array( 'organisation' => '', 'job_title' => '', 'photo_id' => 0, 'bio' => '', 'event_id' => 0 );
	// No role: it is what the person was at ONE event, never a headline fact.
	$fields = array( 'organisation', 'job_title', 'photo_id', 'bio' );
	foreach ( law_speaker_appearances( $speaker_id, $statuses ) as $appearance ) {
		if ( ! $first['event_id'] ) {
			$first['event_id'] = $appearance['event_id'];
		}
		foreach ( $fields as $field ) {
			if ( empty( $first[ $field ] ) && ! empty( $appearance[ $field ] ) ) {
				$first[ $field ] = $appearance[ $field ];
			}
		}
		if ( $first['organisation'] && $first['job_title'] && $first['photo_id'] && '' !== $first['bio'] ) {
			break;
		}
	}
	return $first;
}

/**
 * The speaker's MOST RECENT appearance: the same four per-appearance fields
 * plus the role, taken from the latest event on record and falling through to
 * the appearance before it whenever the later row leaves a field empty (so an
 * event where nobody re-entered the organisation still prefills the one the
 * speaker last gave).
 *
 * The mirror of law_speaker_first_appearance(), which is the ARCHIVE rule (a
 * speaker's card shows what they were the first time LAW hosted them). This is
 * the EDITING rule: prefilling a new appearance with the stalest values on
 * record would be wrong, so the picker starts from the latest (Denis,
 * 11 September 2026). The role is included here and deliberately not there: on
 * a card it is a fact about one event, but on a form it is the value the
 * committee is most likely to want again.
 *
 * @param int           $speaker_id Speaker post ID.
 * @param string[]|null $statuses   Event statuses to read; null = publish only.
 * @return array{role:string,organisation:string,job_title:string,photo_id:int,bio:string,event_id:int}
 */
function law_speaker_latest_appearance( $speaker_id, ?array $statuses = null ) {
	$latest = array( 'role' => '', 'organisation' => '', 'job_title' => '', 'photo_id' => 0, 'bio' => '', 'event_id' => 0 );
	$fields = array( 'role', 'organisation', 'job_title', 'photo_id', 'bio' );
	// law_speaker_appearances() is first-submitted first, so the newest row is
	// the last one; reversed, the first non-empty value seen for a field is the
	// most recent one.
	foreach ( array_reverse( law_speaker_appearances( $speaker_id, $statuses ) ) as $appearance ) {
		if ( ! $latest['event_id'] ) {
			$latest['event_id'] = $appearance['event_id'];
		}
		foreach ( $fields as $field ) {
			if ( empty( $latest[ $field ] ) && ! empty( $appearance[ $field ] ) ) {
				$latest[ $field ] = $appearance[ $field ];
			}
		}
		if ( $latest['role'] && $latest['organisation'] && $latest['job_title'] && $latest['photo_id'] && '' !== $latest['bio'] ) {
			break;
		}
	}
	return $latest;
}

/**
 * What a speaker picker should put into a freshly chosen row: everything the
 * module knows about this person, so the committee edits prefilled values
 * instead of retyping them (Denis, 11 September 2026).
 *
 * Every status is read, not just confirmed events: a speaker record exists
 * from the first draft save, and the appearance worth copying forward is often
 * on an event that has not been confirmed yet.
 *
 * The speaker post's featured image is the photo's last resort, exactly as it
 * is when an event renders a row that left the photo blank
 * (law_speaker_photo_url(), the single event view). The biography has no such
 * resort: the post's own content stopped standing in for a blank bio on
 * 26 October 2026 (law_speaker_card()). There is no
 * organisation or job title on the speaker post to fall back to: those have
 * been per appearance since 4.1, and the old _law_organisation /
 * _law_job_title meta rows are inert (law_speaker_meta_schema()).
 *
 * @param int $speaker_id Speaker post ID.
 * @return array{role:string,organisation:string,job_title:string,photo_id:int,photo:string,bio:string}
 */
function law_speaker_row_prefill( $speaker_id ) {
	$speaker_id = (int) $speaker_id;
	$latest     = law_speaker_latest_appearance( $speaker_id, law_event_all_status_keys() );

	$photo_id = (int) $latest['photo_id'] ?: (int) get_post_thumbnail_id( $speaker_id );
	$bio      = $latest['bio'];

	return array(
		'role'         => (string) $latest['role'],
		'organisation' => (string) $latest['organisation'],
		'job_title'    => (string) $latest['job_title'],
		'photo_id'     => $photo_id,
		'photo'        => $photo_id ? (string) wp_get_attachment_image_url( $photo_id, 'thumbnail' ) : '',
		'bio'          => $bio,
	);
}

/**
 * The exact appearance row for one event (event row first, then its
 * sessions), regardless of the event's status. Used by the profile's
 * "Speaking at" cards and the admin "Appears at" box.
 *
 * @return array{role:string,organisation:string,job_title:string,photo_id:int,bio:string}|null Null when the speaker is not on the event.
 */
function law_speaker_appearance_for_event( $speaker_id, $event_id ) {
	$speaker_id = (int) $speaker_id;
	$event_id   = (int) $event_id;
	$sources    = array( law_event_meta( $event_id, '_law_speakers' ) );
	foreach ( law_event_session_ids( $event_id ) as $session_id ) {
		$sources[] = law_event_meta( $session_id, '_law_speakers' );
	}
	foreach ( $sources as $rows ) {
		foreach ( $rows as $row ) {
			if ( (int) ( $row['speaker_id'] ?? 0 ) === $speaker_id ) {
				return array(
					'role'         => law_speaker_role_key( $row['role'] ?? '' ),
					'organisation' => trim( (string) ( $row['organisation'] ?? '' ) ),
					'job_title'    => trim( (string) ( $row['job_title'] ?? '' ) ),
					'photo_id'     => (int) ( $row['photo_id'] ?? 0 ),
					'bio'          => trim( (string) ( $row['bio'] ?? '' ) ),
				);
			}
		}
	}
	return null;
}

/**
 * A speaker profile's events split by the role the person held at each one,
 * so the profile can head each list "Speaking at:", "Hosting:" or
 * "Moderating at:" instead of filing everything under "Speaking at:"
 * (Denis, 11 September 2026).
 *
 * Groups come back in law_speaker_roles() order and empty ones are dropped,
 * so a person who only ever spoke still sees exactly one list. An event whose
 * appearance row carries no role — every row migrated or saved before roles
 * existed — falls under Speaker, the module-wide default that
 * law_speaker_role_display() and law_speaker_role_heading() also apply.
 *
 * The events keep the programme order they arrive in, so each group reads
 * chronologically.
 *
 * @param int                    $speaker_id Speaker post ID.
 * @param array<int, array>      $events     Mapped calendar events, in order (law_speaker_events()).
 * @return array<string, array<int, array>> Role key => that role's events.
 */
function law_speaker_events_by_role( $speaker_id, $events ) {
	$groups = array_fill_keys( array_keys( law_speaker_roles() ), array() );
	foreach ( $events as $event ) {
		$appearance = law_speaker_appearance_for_event(
			(int) $speaker_id,
			law_events_resolve_event_post_id( $event['id'] )
		);
		$role = law_speaker_role_key( $appearance['role'] ?? '' );
		if ( '' === $role || ! isset( $groups[ $role ] ) ) {
			$role = 'speaker';
		}
		$groups[ $role ][] = $event;
	}
	return array_filter( $groups );
}

/**
 * Photo URL for an appearance: the row's attachment, else the speaker post's
 * featured image (the fallback set once by law_speaker_upsert()).
 *
 * @param int    $speaker_id Speaker post ID.
 * @param int    $photo_id   Appearance attachment ID (0 for none).
 * @param string $size       Image size.
 */
function law_speaker_photo_url( $speaker_id, $photo_id, $size = 'thumbnail' ) {
	if ( $photo_id ) {
		$url = wp_get_attachment_image_url( (int) $photo_id, $size );
		if ( $url ) {
			return (string) $url;
		}
	}
	return (string) get_the_post_thumbnail_url( (int) $speaker_id, $size );
}

/**
 * Session post IDs for an event: start time first, then the host's row order
 * from the sessions repeater (menu_order), then post ID.
 *
 * The menu_order tie-break is what orders parallel tracks that start at the
 * same time. Post ID cannot do it: sessions are upserted now, so an edited
 * session keeps the ID it was first created with. Sessions saved before
 * menu_order was written all hold 0 and fall through to the ID order they
 * used to have.
 *
 * @return int[]
 */
function law_event_session_ids( $event_id ) {
	// No meta_key in the query: an INNER JOIN would silently drop sessions
	// without a start time. Sorted in PHP instead, empty times last.
	$ids = get_posts(
		array(
			'post_type'      => LAW_SESSION_CPT,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'post_parent'    => (int) $event_id,
			'fields'         => 'ids',
			'posts_per_page' => 50,
			'orderby'        => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
		)
	);
	usort(
		$ids,
		function ( $a, $b ) {
			$time_a = (string) law_event_meta( $a, '_law_start_time' );
			$time_b = (string) law_event_meta( $b, '_law_start_time' );
			return strcmp( $time_a ?: '99:99', $time_b ?: '99:99' )
				?: ( (int) get_post_field( 'menu_order', $a ) <=> (int) get_post_field( 'menu_order', $b ) )
				?: $a <=> $b;
		}
	);
	return $ids;
}

/**
 * Speaker cards in the order the front end shows them: by ROLE first -- hosts,
 * then moderators, then speakers and anybody whose row names no role at all --
 * and alphabetically by surname, then first name, inside each of those groups
 * (Denis, 14 September 2026; the alphabetical part 11 September 2026).
 *
 * The role leads because it is the reader's way into a list of faces: the
 * person hosting the session and the person chairing it are who they are
 * looking for, and the rest are the panel. Alphabetical alone buried them
 * wherever their surname happened to fall. The stored order of _law_speakers
 * is only the order whoever filled the form typed the rows in, which tells a
 * reader nothing and puts the same person in a different place on every event,
 * so it is not used at all beyond breaking an exact tie.
 *
 * This is the one ordering rule for speaker cards, so the event's own Speakers
 * list, every session's speakers on the timeline and the legacy calendar
 * listings cannot drift apart.
 *
 * The surname comes from the speaker post when the card names one, since the
 * two name parts have been stored separately since 9 September 2026 and are
 * more reliable than splitting a display name ("Ali Malek KC", "van der Berg").
 * Otherwise the card's display name is split, which is all a legacy calendar
 * card carries.
 *
 * @param array[] $cards law_speaker_card() rows.
 * @return array[]
 */
function law_speakers_sort_cards( array $cards ) {
	$rows = array();
	foreach ( array_values( $cards ) as $index => $card ) {
		// Both keys are computed once per card rather than inside the
		// comparator, which would re-read the post meta on every comparison.
		$rows[] = array(
			'role'  => law_speaker_role_rank( (array) $card ),
			'key'   => law_speaker_sort_key( (array) $card ),
			'index' => $index,
			'card'  => $card,
		);
	}
	usort(
		$rows,
		// The index breaks ties explicitly, so two identical names keep the
		// order they were entered in whatever the sort implementation does.
		fn( $a, $b ) => ( $a['role'] <=> $b['role'] )
			?: ( strcmp( $a['key'], $b['key'] ) ?: ( $a['index'] <=> $b['index'] ) )
	);
	return wp_list_pluck( $rows, 'card' );
}

/**
 * Where one card's role puts it in that order: 0 host, 1 moderator, 2 anything
 * else. A row with no role reads as "Speaker" everywhere it is printed
 * (law_speaker_role_display()), so it sorts with the speakers rather than
 * forming a fourth group of its own.
 *
 * @param array $card A law_speaker_card() row.
 * @return int
 */
function law_speaker_role_rank( array $card ) {
	switch ( law_speaker_role_key( $card['role'] ?? '' ) ) {
		case 'host':
			return 0;
		case 'moderator':
			return 1;
		default:
			return 2;
	}
}

/**
 * The comparable "surname, first name" key for one speaker card.
 *
 * A one-word name ("Cher") has no surname, so it files under the name itself
 * rather than sorting above everybody else on an empty key.
 *
 * @param array $card A law_speaker_card() row (or a legacy calendar card).
 */
function law_speaker_sort_key( array $card ) {
	$id = (int) ( $card['id'] ?? 0 );
	// The post-type check matters: a legacy calendar card's id is a Gravity
	// Forms entry ID, not a post ID, so the meta is only read when the card
	// really does point at a speaker post.
	$parts = $id && LAW_SPEAKER_CPT === get_post_type( $id )
		? law_speaker_name_parts( $id )
		: law_speaker_split_name( (string) ( $card['name'] ?? '' ) );

	$first = law_speaker_sort_token( $parts['first'] );
	$last  = law_speaker_sort_token( $parts['last'] );
	return ( '' !== $last ? $last : $first ) . ' ' . $first;
}

/**
 * Fold one name to its comparable form: accents removed, lower case, and
 * punctuation dropped, so "O'Donnell" files under "odonnell" and "Odegaard"
 * files under "odegaard" rather than sorting after Z. A name that is left with
 * nothing (a script remove_accents() cannot transliterate) keeps its own
 * lower-cased text instead of collapsing to an empty key.
 *
 * @param string $name First or last name.
 */
function law_speaker_sort_token( $name ) {
	$name    = mb_strtolower( trim( (string) $name ) );
	$folded  = (string) preg_replace( '/[^a-z0-9]+/', '', remove_accents( $name ) );
	return '' !== $folded ? $folded : $name;
}

/**
 * Card-shaped speaker rows for an event listing (the shape
 * parts/calendar-body.php renders): id, name, role, organisation, job_title,
 * url (profile), photo, photo_id, bio. Values are the event's own appearance
 * rows.
 *
 * @param int $event_id law_event post ID.
 */
function law_event_speaker_cards( $event_id ) {
	$cards = array();
	foreach ( law_event_meta( $event_id, '_law_speakers' ) as $row ) {
		$card = law_speaker_card( (int) ( $row['speaker_id'] ?? 0 ), $row );
		if ( $card ) {
			$cards[] = $card;
		}
	}
	return law_speakers_sort_cards( $cards );
}

/**
 * One card row for a speaker at one event. Role, organisation, job title,
 * photo and biography come from the appearance row; any field the row leaves empty falls
 * through to $fallback_rows (the parent event's rows, for a session row the
 * committee added in wp-admin without details), then the photo falls back to
 * the speaker post's featured image. The biography stops there: a blank one
 * prints nothing.
 *
 * 'medium', not 'thumbnail': the single event view renders these at 5.5rem, so
 * a 150px hard-cropped thumbnail is soft on a retina screen. Note medium is
 * not cropped square, which is why the CSS uses object-fit.
 * The photo is this event's own, then the first one ever provided for the
 * speaker, then the featured image (else the template's initials placeholder).
 *
 * @param int   $speaker_id    Speaker post ID.
 * @param array $row           The _law_speakers row being rendered.
 * @param array $fallback_rows Parent event's _law_speakers rows (sessions only).
 */
function law_speaker_card( $speaker_id, array $row = array(), array $fallback_rows = array() ) {
	$post = get_post( (int) $speaker_id );
	if ( ! $post || LAW_SPEAKER_CPT !== $post->post_type ) {
		return null;
	}

	$role         = law_speaker_role_key( $row['role'] ?? '' );
	$organisation = trim( (string) ( $row['organisation'] ?? '' ) );
	$job_title    = trim( (string) ( $row['job_title'] ?? '' ) );
	$photo_id     = (int) ( $row['photo_id'] ?? 0 );
	$bio          = trim( (string) ( $row['bio'] ?? '' ) );
	if ( '' === $role || '' === $organisation || '' === $job_title || ! $photo_id || '' === $bio ) {
		foreach ( $fallback_rows as $fallback ) {
			if ( (int) ( $fallback['speaker_id'] ?? 0 ) !== (int) $post->ID ) {
				continue;
			}
			// A session row's blank role inherits the event's, like the other fields.
			$role         = '' !== $role ? $role : law_speaker_role_key( $fallback['role'] ?? '' );
			$organisation = '' !== $organisation ? $organisation : trim( (string) ( $fallback['organisation'] ?? '' ) );
			$job_title    = '' !== $job_title ? $job_title : trim( (string) ( $fallback['job_title'] ?? '' ) );
			$photo_id     = $photo_id ?: (int) ( $fallback['photo_id'] ?? 0 );
			$bio          = '' !== $bio ? $bio : trim( (string) ( $fallback['bio'] ?? '' ) );
			break;
		}
	}

	// Organisation, job title and biography are this event's; the photo is this
	// event's own, else the first ever provided (law_speaker_display_photo_id()).
	$photo_id = law_speaker_display_photo_id( $post->ID, $photo_id );

	return array(
		'id'           => (int) $post->ID,
		'name'         => $post->post_title,
		'role'         => $role, // Key, '' = Speaker; templates print law_speaker_role_display().
		'organisation' => $organisation,
		'job_title'    => $job_title,
		'url'          => function_exists( 'law_speaker_url' ) ? law_speaker_url( $post->ID ) : '',
		'photo'        => law_speaker_photo_url( $post->ID, $photo_id, 'medium' ),
		'photo_id'     => $photo_id,
		// This event's own, or none (Denis, 26 October 2026). The record's
		// post_content used to stand in, and that is set once from the first bio
		// any host typed, where nobody on Manage Speakers can see or clear it: a
		// "download CV from website" kept printing after the committee had emptied
		// every bio it could reach.
		'bio'          => $bio,
	);
}

/**
 * The card's short biography: the first $words words, plain text. Used by the
 * single event view's speaker cards and by law_speaker_seo_description().
 *
 * The '…' is passed explicitly because wp_trim_words() otherwise appends the
 * HTML entity ' &hellip;', which an esc_html() caller prints literally; and
 * shortcodes are stripped because an appearance biography is stored raw and
 * never passes through the_content.
 *
 * @param string $bio   Raw biography.
 * @param int    $words Word limit.
 */
function law_speaker_bio_excerpt( $bio, $words = 24 ) {
	// law_rich_text_plain(), not wp_strip_all_tags(): a biography is rich text
	// now, and stripping tags alone would run "<li>One</li><li>Two</li>"
	// together as "OneTwo". It strips the shortcodes too.
	return wp_trim_words( law_rich_text_plain( $bio ), (int) $words, '…' );
}

/**
 * The three things a speaker card needs from a biography in one call: the
 * plain full text, the excerpt, and whether the excerpt actually left anything
 * out. The card only offers "Read full bio" when it did, so the dialog never
 * opens on the paragraph already under it.
 *
 * The word split mirrors wp_trim_words()'s own, so the count and the excerpt
 * cannot disagree about where the limit falls.
 *
 * Fourteen words, not law_speaker_bio_excerpt()'s own 24: a card sits in a
 * third-width column beside a session's description, where 24 words ran to
 * four or five lines and the card competed with the session it belongs to
 * (Denis, 11 September 2026). Fourteen is about two lines at that width, and
 * anyone who wants the rest is one "Read full bio" away. The excerpt helper
 * keeps 24 because law_speaker_seo_description() uses it for a meta
 * description, where a longer summary is the point.
 *
 * 'full' keeps the author's markup, because the dialog and the no-JS
 * <details> block both render it through law_rich_text_render(); the excerpt
 * and the word count work off the plain text, since a card shows one line and
 * a half-open <strong> in it would leak into the rest of the page.
 *
 * @param string $bio   Raw biography.
 * @param int    $words Word limit.
 * @return array{full:string,excerpt:string,trimmed:bool}
 */
function law_speaker_bio_summary( $bio, $words = 14 ) {
	$full  = trim( (string) $bio );
	$plain = law_rich_text_plain( $bio );
	$parts = '' === $plain ? array() : (array) preg_split( '/[\n\r\t ]+/', $plain, -1, PREG_SPLIT_NO_EMPTY );
	return array(
		'full'    => '' === $plain ? '' : $full,
		'excerpt' => '' === $plain ? '' : law_speaker_bio_excerpt( $bio, $words ),
		'trimmed' => count( $parts ) > (int) $words,
	);
}

/**
 * The bio dialogs the single event view has to print. A speaker card that has
 * more biography than its excerpt shows registers here and gets an id back for
 * its "Read full bio" button; parts/calendar-body.php prints the registered
 * dialogs once, after the speaker and session sections.
 *
 * A registry rather than an index passed down the template: the cards render
 * in two different places (the event's own Speakers list and each session
 * panel) and the dialogs must all end up outside the session <details>, since
 * a closed <details> renders nothing and its dialog could never open. One
 * dialog per CARD, not per speaker: each appearance row carries its own
 * biography, so two session rows for the same person can legitimately differ.
 *
 * @param array $speaker A law_speaker_card() row.
 * @return string The dialog's element id.
 */
function law_speaker_dialog_register( array $speaker ) {
	$dialogs   = &law_speaker_dialogs_store();
	$id        = 'law-speaker-bio-' . ( count( $dialogs ) + 1 );
	$dialogs[] = array( 'id' => $id, 'speaker' => $speaker );
	return $id;
}

/** The registered dialogs, in registration order. */
function law_speaker_dialogs() {
	return law_speaker_dialogs_store();
}

/** The registry's backing store, by reference so the register can append. */
function &law_speaker_dialogs_store() {
	static $dialogs = array();
	return $dialogs;
}

/**
 * Sessions for an event in the listing shape (title, start, end, time_label,
 * description, speakers[]).
 */
function law_event_session_rows( $event_id ) {
	$sessions   = array();
	$event_rows = law_event_meta( $event_id, '_law_speakers' ); // Appearance fallback for session rows.
	foreach ( law_event_session_ids( $event_id ) as $session_id ) {
		$post  = get_post( $session_id );
		$start = (string) law_event_meta( $session_id, '_law_start_time' );
		$end   = (string) law_event_meta( $session_id, '_law_end_time' );

		$speakers = array();
		foreach ( law_event_meta( $session_id, '_law_speakers' ) as $row ) {
			$card = law_speaker_card( (int) ( $row['speaker_id'] ?? 0 ), $row, $event_rows );
			if ( $card ) {
				$speakers[] = $card;
			}
		}

		$time_label = $start && $end ? $start . '–' . $end : $start;
		$title      = $post ? trim( $post->post_title ) : '';
		$desc       = $post ? trim( $post->post_content ) : '';
		if ( '' === $title && '' === $time_label && '' === $desc && empty( $speakers ) ) {
			continue;
		}

		$sessions[] = array(
			// The post ID: the edit form round-trips it so a save updates the
			// session in place instead of deleting and re-creating it.
			'id'          => (int) $session_id,
			'title'       => $title,
			'start'       => $start,
			'end'         => $end,
			'time_label'  => $time_label,
			'description' => $desc,
			'speakers'    => law_speakers_sort_cards( $speakers ),
		);
	}
	return $sessions;
}

/* Removing a speaker record __________________________________________________
 *
 * A speaker is referenced by ID from the _law_speakers rows of every event and
 * session they appear at, and those rows carry the per-event details (role,
 * organisation, job title, photo, biography). Trashing the record used to leave
 * every row behind: the event page kept printing the person (law_speaker_card()
 * reads a trashed post like any other) with a profile link that went nowhere,
 * and once the trash was emptied a flagship session naming them could no
 * longer be saved ("speaker #N no longer exists").
 *
 * So taking a record out takes it off everything that names it, whichever
 * screen did it (Manage Speakers' Delete, or wp-admin's own Trash link): the
 * work hangs off WordPress's trash and delete hooks rather than off a button.
 * Trash keeps what it removed on the record, and a restore from the wp-admin
 * trash puts it back, so a mistaken delete costs nobody the biographies.
 */

/** The meta key that holds, on a trashed speaker, the rows its trash removed. */
const LAW_SPEAKER_DETACHED_META = '_law_speaker_detached';

/**
 * Every event or session, in ANY status, whose _law_speakers rows name this
 * speaker. Trashed events too: one restored later must not come back naming a
 * record that has gone.
 *
 * Uncapped, unlike the screen passes: this decides what a delete writes, and a
 * capped scan would leave rows behind on whatever fell past the cap.
 *
 * @param int $speaker_id Speaker post ID.
 * @return int[]
 */
function law_speaker_holder_ids( $speaker_id ) {
	$speaker_id = (int) $speaker_id;
	$candidates = array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => array( LAW_EVENT_CPT, LAW_SESSION_CPT ),
				'post_status'    => array_keys( get_post_stati() ),
				'meta_key'       => '_law_speakers', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- an EXISTS join, not a LIKE over the serialised rows.
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		)
	);
	update_meta_cache( 'post', $candidates );

	$holders = array();
	foreach ( $candidates as $post_id ) {
		foreach ( law_event_meta( $post_id, '_law_speakers' ) as $row ) {
			if ( (int) ( $row['speaker_id'] ?? 0 ) === $speaker_id ) {
				$holders[] = $post_id;
				break;
			}
		}
	}
	return $holders;
}

/**
 * Take one speaker off every event and session that names them.
 *
 * Every other row stays, in its order; the array goes back through
 * law_event_update_meta(), the single write path, which deletes the meta
 * outright when the last row goes. A flagship's own rows are the union of its
 * sessions', so the flagship is recomputed rather than edited by hand.
 *
 * @param int $speaker_id Speaker post ID.
 * @return array<int,array[]> post ID => the rows removed from it.
 */
function law_speaker_detach( $speaker_id ) {
	$speaker_id = (int) $speaker_id;
	$removed    = array();

	foreach ( law_speaker_holder_ids( $speaker_id ) as $post_id ) {
		$keep = array();
		foreach ( law_event_meta( $post_id, '_law_speakers' ) as $row ) {
			if ( (int) ( $row['speaker_id'] ?? 0 ) === $speaker_id ) {
				$removed[ $post_id ][] = $row;
			} else {
				$keep[] = $row;
			}
		}
		law_event_update_meta( $post_id, '_law_speakers', $keep );
	}

	law_speaker_after_rows_changed( array_keys( $removed ) );

	return $removed;
}

/**
 * Put back what law_speaker_detach() took, on whatever still exists.
 *
 * A post that already names this person again is skipped: while the record sat
 * in the trash, a host re-adding them by email would have created a NEW record
 * (law_speaker_find_existing() never matches a trashed one), and restoring the
 * old row beside it would print the same person twice.
 *
 * @param int   $speaker_id Speaker post ID.
 * @param array $removed    law_speaker_detach()'s return value.
 * @return array<int,array[]> post ID => the rows put back on it.
 */
function law_speaker_reattach( $speaker_id, array $removed ) {
	$speaker_id = (int) $speaker_id;
	$restored   = array();

	foreach ( $removed as $post_id => $rows ) {
		$post_id = (int) $post_id;
		if ( ! is_array( $rows ) || ! in_array( get_post_type( $post_id ), array( LAW_EVENT_CPT, LAW_SESSION_CPT ), true ) ) {
			continue;
		}
		$current = law_event_meta( $post_id, '_law_speakers' );
		if ( law_speaker_rows_name_person( $current, $speaker_id ) ) {
			continue;
		}
		foreach ( $rows as $row ) {
			$current[] = array( 'speaker_id' => $speaker_id ) + (array) $row;
		}
		law_event_update_meta( $post_id, '_law_speakers', $current );
		$restored[ $post_id ] = $rows;
	}

	law_speaker_after_rows_changed( array_keys( $restored ) );

	return $restored;
}

/**
 * Whether a set of speaker rows already names this person: the same record, or
 * a different record with the same email and a same-person name, or the same
 * name (the keys law_speaker_find_existing() matches on). A shared address
 * under a different name is a different person (19 November 2026).
 *
 * @param array $rows       _law_speakers rows.
 * @param int   $speaker_id Speaker post ID.
 */
function law_speaker_rows_name_person( array $rows, $speaker_id ) {
	$speaker_id = (int) $speaker_id;
	$email      = mb_strtolower( trim( (string) law_event_meta( $speaker_id, '_law_speaker_email' ) ) );
	$raw_name   = law_speaker_raw_name( $speaker_id );
	$name       = law_speaker_normalise_name( $raw_name );

	foreach ( $rows as $row ) {
		$other = (int) ( $row['speaker_id'] ?? 0 );
		if ( $other === $speaker_id ) {
			return true;
		}
		if ( '' !== $email
			&& mb_strtolower( trim( (string) law_event_meta( $other, '_law_speaker_email' ) ) ) === $email
			&& law_speaker_same_person( law_speaker_raw_name( $other ), $raw_name ) ) {
			return true;
		}
		if ( '' !== $name && law_speaker_normalise_name( law_speaker_raw_name( $other ) ) === $name ) {
			return true;
		}
	}
	return false;
}

/**
 * The derived data that follows a change to some posts' speaker rows: a
 * flagship's union of its sessions, and the memoised appearance maps.
 *
 * @param int[] $post_ids Events and sessions whose rows changed.
 */
function law_speaker_after_rows_changed( array $post_ids ) {
	$flagships = array();
	foreach ( $post_ids as $post_id ) {
		$parent = LAW_SESSION_CPT === get_post_type( $post_id ) ? (int) wp_get_post_parent_id( $post_id ) : 0;
		if ( $parent && function_exists( 'law_flagship_is' ) && law_flagship_is( $parent ) ) {
			$flagships[ $parent ] = true;
		}
	}
	foreach ( array_keys( $flagships ) as $flagship_id ) {
		law_flagship_recompute( $flagship_id );
	}
	law_speakers_flush_maps();
}

/**
 * One activity-log line per EVENT a removal or a restore touched, so a host or
 * the committee wondering where a speaker went can read it on the event. A
 * session's rows are logged on its event; an event that lost the person from
 * its own row and from a session gets one line, not two.
 *
 * @param int    $speaker_id Speaker post ID.
 * @param array  $rows_by_post post ID => rows, from detach or reattach.
 * @param string $what       'removed' or 'restored'.
 */
function law_speaker_log_rows_change( $speaker_id, array $rows_by_post, $what ) {
	$events = array();
	foreach ( array_keys( $rows_by_post ) as $post_id ) {
		$event_id = LAW_SESSION_CPT === get_post_type( $post_id ) ? (int) wp_get_post_parent_id( $post_id ) : (int) $post_id;
		if ( $event_id ) {
			$events[ $event_id ] = true;
		}
	}

	$name    = law_speaker_raw_name( (int) $speaker_id );
	$message = 'restored' === $what
		? 'Speaker "%s" put back on this event: their speaker record was restored from the trash.'
		: 'Speaker "%s" taken off this event: their speaker record was deleted.';

	foreach ( array_keys( $events ) as $event_id ) {
		law_event_log(
			$event_id,
			sprintf( $message, $name ),
			array(
				'action'  => 'restored' === $what ? 'speaker_restored' : 'speaker_removed',
				'source'  => 'speaker_record',
				'speaker' => (int) $speaker_id,
			)
		);
	}
}

/**
 * Trash: take the speaker off everything and keep what was taken on the record.
 * wp_trash_post fires before the status changes, the same moment the bookings
 * sweep uses for an event.
 */
add_action(
	'wp_trash_post',
	function ( $post_id ) {
		if ( LAW_SPEAKER_CPT !== get_post_type( $post_id ) ) {
			return;
		}
		$removed = law_speaker_detach( $post_id );
		if ( ! $removed ) {
			return;
		}
		// Plain post meta, outside the schema: a map of rows keyed by post ID
		// is no type the schema describes, and nothing reads it but the restore
		// below. It is deleted with the post when the trash is emptied.
		update_post_meta( $post_id, LAW_SPEAKER_DETACHED_META, $removed );
		law_speaker_log_rows_change( $post_id, $removed, 'removed' );
	}
);

/** Restore: put the rows back. The status itself is workflow.php's untrash filter. */
add_action(
	'untrashed_post',
	function ( $post_id ) {
		if ( LAW_SPEAKER_CPT !== get_post_type( $post_id ) ) {
			return;
		}
		$removed = get_post_meta( $post_id, LAW_SPEAKER_DETACHED_META, true );
		delete_post_meta( $post_id, LAW_SPEAKER_DETACHED_META );
		if ( ! is_array( $removed ) || ! $removed ) {
			return;
		}
		$restored = law_speaker_reattach( $post_id, $removed );
		if ( $restored ) {
			law_speaker_log_rows_change( $post_id, $restored, 'restored' );
		}
	}
);

/**
 * Permanent deletion. A record deleted from the trash was detached when it went
 * in, so this finds nothing; one deleted outright (wp_delete_post( $id, true ),
 * or a site with the trash switched off) has its rows taken here instead, as
 * has one trashed before this hook existed.
 */
add_action(
	'before_delete_post',
	function ( $post_id ) {
		if ( LAW_SPEAKER_CPT !== get_post_type( $post_id ) ) {
			return;
		}
		$removed = law_speaker_detach( $post_id );
		if ( $removed ) {
			law_speaker_log_rows_change( $post_id, $removed, 'removed' );
		}
	}
);
