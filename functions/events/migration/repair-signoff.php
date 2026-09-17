<?php
/**
 * One-off repair: sign-offs written into individual email bodies.
 *
 * Since 17 September 2026 the sign-off is stored once
 * (law_events_email_signoff()) and appended by the renderer, so every
 * notification ends the same way whether its wording is the shipped default, a
 * site-wide override or a per-event one. The wording MIGRATED from the old
 * Gravity Forms notifications predates that: six of the stored overrides end
 * with their own "Best, / London Arbitration Week", and those now send two
 * sign-offs one after the other.
 *
 * This strips the sign-off out of the stored bodies so the shared one is the
 * only one left. It is deliberately not done at render time: guessing at send
 * time whether a body "already has" a sign-off would have to guess right on
 * every future edit as well, and a committee member who genuinely wants a
 * different closing line on one email would find it silently eaten.
 *
 * Only stored wording is touched — the option written by the Emails screens and
 * the per-event override meta. The code defaults carry no sign-off and never
 * did, so there is nothing in them to remove.
 *
 * Dry run by default; nothing is written until "Apply" is pressed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Closing lines, as a body that has one ends.
 *
 * Matched against ONE short trailing line at a time, anchored at both ends, so
 * a paragraph that happens to contain the word "regards" is never a candidate:
 * only a line that is nothing but a valediction can be.
 */
function law_events_signoff_valediction_pattern() {
	return '/^(best|best regards|kind regards|kindest regards|regards|warm regards|warmest regards|warm wishes|best wishes|many thanks|thanks|thank you|yours sincerely|yours faithfully|yours truly|sincerely|cheers)[,.!]?$/i';
}

/** Who the closing lines sign off as. */
function law_events_signoff_signature_pattern() {
	return '/^(london arbitration week|the london arbitration week team|the law team|law|the events committee|the organising committee|the committee)[,.!]?$/i';
}

/**
 * The body with its own trailing sign-off removed.
 *
 * Walks backwards over the trailing lines (or trailing <p> blocks, for a body
 * that carries markup), collecting short lines that are nothing but a
 * valediction or a signature. The collected block is only removed if a
 * VALEDICTION was among them: without that guard a body whose last sentence
 * happens to be the organisation's name would lose it, and "London Arbitration
 * Week" on its own is a plausible last line of a real paragraph.
 *
 * At most four lines are ever considered, so the worst case is a sign-off left
 * in place, never a message truncated.
 *
 * @param string $body Stored body, plain text or allowlisted HTML.
 * @return array{body:string,removed:string} The new body and what came off.
 */
function law_events_signoff_strip( $body ) {
	$body      = (string) $body;
	$working   = $body;
	$removed   = array();
	$found_val = false;

	for ( $i = 0; $i < 4; $i++ ) {
		// Trailing emptiness first: an editor leaves "&nbsp;" paragraphs and
		// stray <br>s behind, and a sign-off sitting above one would otherwise
		// look like the middle of the body rather than the end of it.
		$trimmed = preg_replace( '#(?:\s|&nbsp;|<br\s*/?>|<p>\s*(?:&nbsp;)?\s*</p>)+$#i', '', $working );
		if ( null === $trimmed ) {
			break;
		}
		$working = $trimmed;

		// The last unit: a closing <p> block where the body carries markup,
		// otherwise everything after the last line break.
		//
		// The opening tag is found by offset rather than by a non-greedy match.
		// '<p[^>]*>(.*?)</p>$' looks right and is not: anchored at the end, the
		// engine starts from the FIRST <p> in the body and lets the lazy group
		// swallow every paragraph in between, so a three-paragraph message
		// reads as one long unit and nothing is ever recognised.
		$opens = array();
		preg_match_all( '#<p\b[^>]*>#i', $working, $opens, PREG_OFFSET_CAPTURE );
		$last_open = ! empty( $opens[0] ) ? (int) end( $opens[0] )[1] : -1;

		if ( $last_open >= 0 && preg_match( '#^<p\b[^>]*>(.*)</p>$#is', substr( $working, $last_open ), $match ) ) {
			$unit = substr( $working, $last_open );
			$text = $match[1];
		} elseif ( preg_match( '#(?:^|\n)([^\n]*)$#', $working, $match ) ) {
			$unit = $match[0];
			$text = $match[1];
		} else {
			break;
		}

		$plain = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
		// A curly apostrophe or a non-breaking space must not stop a match.
		$plain = trim( str_replace( array( "\xc2\xa0", '’' ), array( ' ', "'" ), $plain ) );

		if ( '' === $plain ) {
			break;
		}
		// Length is the cheap guard that keeps a real paragraph out of reach
		// before either pattern is even tried.
		if ( strlen( $plain ) > 60 ) {
			break;
		}

		$is_valediction = (bool) preg_match( law_events_signoff_valediction_pattern(), $plain );
		$is_signature   = (bool) preg_match( law_events_signoff_signature_pattern(), $plain );
		if ( ! $is_valediction && ! $is_signature ) {
			break;
		}

		$found_val = $found_val || $is_valediction;
		array_unshift( $removed, $plain );
		$working = substr( $working, 0, strlen( $working ) - strlen( $unit ) );

		// A valediction is the top of a sign-off; nothing above it belongs to
		// it, so stop rather than eating the last line of the message.
		if ( $is_valediction ) {
			break;
		}
	}

	if ( ! $found_val ) {
		return array( 'body' => $body, 'removed' => '' );
	}

	return array( 'body' => rtrim( $working ), 'removed' => implode( ' / ', $removed ) );
}

/**
 * Every stored body that ends with its own sign-off.
 *
 * @return array<int,array<string,mixed>> source, ref, name, removed, body.
 */
function law_events_signoff_scan() {
	$rows = array();

	$overrides = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );
	$registry  = law_events_email_registry();
	foreach ( (array) $overrides as $slug => $override ) {
		if ( ! is_array( $override ) ) {
			continue;
		}
		$strip = law_events_signoff_strip( (string) ( $override['body'] ?? '' ) );
		if ( '' === $strip['removed'] ) {
			continue;
		}
		$rows[] = array(
			'source'  => 'option',
			'ref'     => (string) $slug,
			'name'    => (string) ( $registry[ $slug ]['name'] ?? $slug ),
			'removed' => $strip['removed'],
			'body'    => $strip['body'],
		);
	}

	// The per-event booking confirmations (email-override.php). None carried a
	// sign-off when this was written, but a committee member writing one
	// tomorrow would, and the panel is the place that would show it.
	global $wpdb;
	$meta = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s )",
			'_law_email_override_body',
			'_law_email_override_body_free'
		)
	);
	foreach ( (array) $meta as $row ) {
		$strip = law_events_signoff_strip( (string) $row->meta_value );
		if ( '' === $strip['removed'] ) {
			continue;
		}
		$rows[] = array(
			'source'  => 'meta',
			'ref'     => (int) $row->post_id . '|' . (string) $row->meta_key,
			'name'    => sprintf( '#%d %s (%s)', (int) $row->post_id, get_the_title( (int) $row->post_id ), (string) $row->meta_key ),
			'removed' => $strip['removed'],
			'body'    => $strip['body'],
		);
	}

	return $rows;
}

/**
 * Strip the sign-off from the ticked bodies.
 *
 * Rescans rather than trusting anything posted back: the panel posts
 * identifiers, never the new wording, so a stale form cannot overwrite an edit
 * somebody made in between.
 *
 * @param array $refs The 'ref' values ticked on the panel.
 * @return array{repaired:int,lines:array<int,string>}
 */
function law_events_signoff_apply( array $refs ) {
	$refs  = array_map( 'strval', $refs );
	$lines = array();
	$done  = 0;

	$overrides = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );
	$dirty     = false;

	foreach ( law_events_signoff_scan() as $row ) {
		if ( ! in_array( (string) $row['ref'], $refs, true ) ) {
			continue;
		}

		if ( 'option' === $row['source'] ) {
			if ( ! isset( $overrides[ $row['ref'] ] ) || ! is_array( $overrides[ $row['ref'] ] ) ) {
				continue;
			}
			$overrides[ $row['ref'] ]['body'] = $row['body'];
			$dirty                            = true;
		} else {
			list( $post_id, $meta_key ) = array_pad( explode( '|', (string) $row['ref'], 2 ), 2, '' );
			if ( ! $post_id || ! $meta_key ) {
				continue;
			}
			update_post_meta( (int) $post_id, $meta_key, $row['body'] );
			// Per-event wording belongs to exactly one event, so this one IS
			// logged — the same rule email-override.php saves under.
			law_event_log(
				(int) $post_id,
				'Repair: the sign-off written into this event\'s booking confirmation was removed, so the shared sign-off is the only one it sends.',
				array( 'action' => 'email_override', 'source' => 'migration', 'removed' => $row['removed'] )
			);
		}

		$lines[] = sprintf( '%s — removed "%s"', $row['name'], $row['removed'] );
		++$done;
	}

	if ( $dirty ) {
		update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, $overrides, false );
	}

	return array( 'repaired' => $done, 'lines' => $lines );
}

/**
 * The LAW → Migration card: the scan table and the Apply button.
 * Called from law_migration_admin_page().
 */
function law_events_signoff_panel() {
	$notice = '';
	if ( isset( $_POST['law_repair_signoff_nonce'] ) ) {
		check_admin_referer( 'law_repair_signoff', 'law_repair_signoff_nonce' );
		$refs   = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['law_repair_signoff_refs'] ?? array() ) );
		$result = law_events_signoff_apply( $refs );
		$notice = sprintf(
			'<div class="notice notice-success"><p><strong>%d email(s) repaired.</strong></p>%s</div>',
			$result['repaired'],
			$result['lines'] ? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $result['lines'] ) ) . '</li></ul>' : ''
		);
	}

	$rows = law_events_signoff_scan();
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html().
	?>
	<h2>Repair: sign-offs written into individual emails</h2>
	<p class="description" style="max-width:900px">
		The sign-off is now a single setting at the foot of the
		<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'law-events-emails' ), admin_url( 'admin.php' ) ) ); ?>">Emails</a>
		screen, added to the end of every notification as it sends. Wording carried over from the old Gravity Forms
		notifications has its own closing lines written into the message, so those emails now sign off twice.
		This removes the closing lines from the stored wording, leaving the shared one. Only a short trailing line
		that is nothing but a valediction or the organisation's name is ever touched, and only when a valediction is
		among them, so the message itself cannot be truncated. Check the list before applying.
	</p>

	<?php if ( ! $rows ) : ?>
		<p><strong>Nothing to repair.</strong> No stored email wording ends with its own sign-off.</p>
	<?php else : ?>
		<form method="post">
			<?php wp_nonce_field( 'law_repair_signoff', 'law_repair_signoff_nonce' ); ?>
			<table class="widefat striped" style="max-width:1100px">
				<thead>
				<tr>
					<th style="width:2em"><input type="checkbox" checked disabled></th>
					<th>Email</th><th>Will be removed</th><th>New last words</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><input type="checkbox" name="law_repair_signoff_refs[]" value="<?php echo esc_attr( (string) $row['ref'] ); ?>" checked></td>
						<td><strong><?php echo esc_html( $row['name'] ); ?></strong><br><span class="description"><?php echo esc_html( (string) $row['ref'] ); ?></span></td>
						<td><code><?php echo esc_html( $row['removed'] ); ?></code></td>
						<td class="description">…<?php echo esc_html( mb_substr( trim( wp_strip_all_tags( $row['body'] ) ), -90 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( 'Remove the sign-off from the ticked emails', 'primary', 'submit', true, array( 'onclick' => "return confirm('Remove the closing lines from the ticked emails? They will still sign off, using the shared sign-off on the Emails screen.');" ) ); ?>
		</form>
	<?php endif; ?>
	<?php
}
