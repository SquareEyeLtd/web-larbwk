<?php
/**
 * Sweep non-breaking spaces out of rich text stored before they were normalised.
 *
 * A host who pastes a description from Word, Google Docs or a PDF brings the
 * source's spaces with them, and those applications emit U+00A0 where a reader
 * sees a space. A browser will not break a line at one, so a sentence whose
 * spaces are all non-breaking is a single unbreakable word: it runs out of the
 * reading column instead of wrapping. The event "Hot Topics in Energy and
 * Mining Arbitration" arrived that way -- one paragraph, twenty-one of them --
 * and printed off the right-hand edge of its page (Denis, 17 September 2026).
 *
 * law_rich_text_sanitize() now strips them on every write, so nothing new can
 * land in this state, and .law-cal-detail__body carries an overflow-wrap
 * backstop so even an unswept value stays inside its column. This panel is the
 * one-off for what was already stored, not a chore to repeat.
 *
 * It reaches all four places rich text is kept: the description on a law_event,
 * the description on a law_session, the fallback biography on a law_speaker,
 * and the per-appearance biographies in an event's `_law_speakers` rows.
 *
 * Rather than a silent UPDATE, this is a panel in the same shape as the other
 * repairs: every affected value is listed with the offending text shown, each
 * row can be unticked, and a change to an event or one of its sessions lands in
 * that event's activity log. Rows are pre-ticked only where the damage is
 * visible -- two or more non-breaking spaces in a row, which is three words
 * joined and enough to widen a column. A lone stray one is listed unticked,
 * because a single soft join harms nothing and might even have been meant.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post types whose post_content is rich text, and what to call them on screen.
 *
 * @return array<string,string>
 */
function law_events_nbsp_post_types() {
	return array(
		LAW_EVENT_CPT   => 'Event description',
		LAW_SESSION_CPT => 'Session description',
		LAW_SPEAKER_CPT => 'Speaker biography (fallback)',
	);
}

/**
 * How many non-breaking spaces a value holds, and the longest run of them.
 *
 * The run is what decides whether the text actually breaks the page: one
 * non-breaking space joins two words, two joins three, and by the time a whole
 * sentence is joined there is nowhere legal for the browser to wrap.
 *
 * @param string $value Stored rich text.
 * @return array{total:int,run:int,sample:string}
 */
function law_events_nbsp_measure( $value ) {
	$value = (string) $value;
	$fixed = law_rich_text_normalise_spaces( $value );
	if ( $fixed === $value ) {
		return array( 'total' => 0, 'run' => 0, 'sample' => '' );
	}

	// Measured on the plain text, so an entity inside markup is counted the
	// same as the raw bytes and the sample reads as prose rather than as HTML.
	$plain = law_rich_text_strip_code_blocks( strip_shortcodes( $value ) );
	$plain = wp_strip_all_tags( (string) preg_replace( '#<(?:br|/p|/li|/h[1-6]|/blockquote|/div)\s*/?>#i', "\n", $plain ) );
	$plain = html_entity_decode( $plain, ENT_QUOTES, get_bloginfo( 'charset' ) );

	$total  = substr_count( $plain, "\xc2\xa0" );
	$run    = 0;
	$sample = '';
	// PREG_OFFSET_CAPTURE so the sample can be widened to the words either side
	// of the run. The run on its own is often unreadable -- where the paste left
	// a line of them as an indent, the match is a single letter and a gap.
	if ( preg_match_all( '/(?:\S+\xc2\xa0)+\S+/', $plain, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[0] as $match ) {
			$count = substr_count( $match[0], "\xc2\xa0" );
			if ( $count > $run ) {
				$run    = $count;
				$start  = max( 0, (int) $match[1] - 40 );
				$sample = substr( $plain, $start, strlen( $match[0] ) + ( (int) $match[1] - $start ) + 40 );
			}
		}
	}

	$sample = trim( (string) preg_replace( '/\s+/', ' ', str_replace( "\xc2\xa0", ' ', $sample ) ) );
	// The offsets above are byte offsets, so the window can open or close in the
	// middle of a multi-byte character. Re-encoding drops the partial bytes
	// rather than printing a replacement glyph in the table.
	$sample = (string) mb_convert_encoding( $sample, 'UTF-8', 'UTF-8' );

	return array(
		'total'  => $total,
		'run'    => $run,
		'sample' => $sample,
	);
}

/**
 * Every stored rich-text value still holding a non-breaking space.
 *
 * Keyed by a token the form posts back ("post:123" or "bio:123:2"), so a row
 * ticked on screen resolves to exactly one value on apply and nothing has to be
 * matched by position.
 *
 * @return array<string,array{kind:string,label:string,post_id:int,event_id:int,index:int,title:string,total:int,run:int,sample:string}>
 */
function law_events_nbsp_scan() {
	$rows = array();

	foreach ( law_events_nbsp_post_types() as $post_type => $label ) {
		$posts = get_posts(
			array(
				'post_type'        => $post_type,
				// Every registered status, not 'any': a trashed event's text
				// should be right too if it is ever restored.
				'post_status'      => array_keys( get_post_stati() ),
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		foreach ( $posts as $post ) {
			$measure = law_events_nbsp_measure( $post->post_content );
			if ( ! $measure['total'] ) {
				continue;
			}
			// A session belongs to its event, so its repair is logged there.
			$event_id = LAW_EVENT_CPT === $post_type ? (int) $post->ID : (int) $post->post_parent;
			$rows[ 'post:' . $post->ID ] = array(
				'kind'     => 'post',
				'label'    => $label,
				'post_id'  => (int) $post->ID,
				'event_id' => $event_id,
				'index'    => 0,
				'title'    => $post->post_title,
				'total'    => $measure['total'],
				'run'      => $measure['run'],
				'sample'   => $measure['sample'],
			);
		}
	}

	// The per-appearance biographies, which live on the event rather than on
	// the speaker: the same person's bio differs from one event to the next.
	$events = get_posts(
		array(
			'post_type'        => LAW_EVENT_CPT,
			'post_status'      => array_keys( get_post_stati() ),
			'numberposts'      => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		)
	);
	foreach ( $events as $event ) {
		$speakers = (array) law_event_meta( $event->ID, '_law_speakers' );
		foreach ( $speakers as $index => $row ) {
			$measure = law_events_nbsp_measure( (string) ( $row['bio'] ?? '' ) );
			if ( ! $measure['total'] ) {
				continue;
			}
			$speaker_id = (int) ( $row['speaker_id'] ?? 0 );
			$rows[ 'bio:' . $event->ID . ':' . $index ] = array(
				'kind'     => 'bio',
				'label'    => 'Speaker biography (this event)',
				'post_id'  => (int) $event->ID,
				'event_id' => (int) $event->ID,
				'index'    => (int) $index,
				'title'    => sprintf(
					'%s — %s',
					$event->post_title,
					$speaker_id ? get_the_title( $speaker_id ) : 'speaker'
				),
				'total'    => $measure['total'],
				'run'      => $measure['run'],
				'sample'   => $measure['sample'],
			);
		}
	}

	return $rows;
}

/**
 * Rewrite the chosen values, with a log line on each event touched.
 *
 * @param string[] $tokens Row tokens ticked on the panel.
 * @return array{repaired:int,skipped:string[],lines:string[]}
 */
function law_events_nbsp_apply( array $tokens ) {
	// Re-scanned rather than trusted from the form: the panel may have been
	// open while a host re-saved a description, and this must never write back
	// over an edit made in the meantime.
	$allowed  = law_events_nbsp_scan();
	$repaired = 0;
	$skipped  = array();
	$lines    = array();

	foreach ( $tokens as $token ) {
		$token = sanitize_text_field( (string) $token );
		if ( ! isset( $allowed[ $token ] ) ) {
			$skipped[] = sprintf( '%s no longer qualifies (the text has changed since the scan).', $token );
			continue;
		}
		$row = $allowed[ $token ];

		if ( 'post' === $row['kind'] ) {
			$content = (string) get_post_field( 'post_content', $row['post_id'] );
			$result  = wp_update_post(
				array(
					'ID'           => $row['post_id'],
					'post_content' => law_rich_text_normalise_spaces( $content ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				$skipped[] = sprintf( '#%d could not be saved: %s', $row['post_id'], $result->get_error_message() );
				continue;
			}
		} else {
			$speakers = (array) law_event_meta( $row['post_id'], '_law_speakers' );
			if ( ! isset( $speakers[ $row['index'] ] ) ) {
				$skipped[] = sprintf( '#%d speaker row %d has gone.', $row['post_id'], $row['index'] );
				continue;
			}
			$speakers[ $row['index'] ]['bio'] = law_rich_text_normalise_spaces( (string) ( $speakers[ $row['index'] ]['bio'] ?? '' ) );
			law_event_update_meta( $row['post_id'], '_law_speakers', $speakers );
		}

		if ( $row['event_id'] ) {
			law_event_log(
				$row['event_id'],
				sprintf(
					'Data repair: %d non-breaking space(s) replaced with ordinary spaces in the %s. They came in with a paste from Word, Google Docs or a PDF, and a browser will not break a line at one, so the text ran outside its column instead of wrapping. Only the spaces changed; no words were touched.',
					$row['total'],
					strtolower( $row['label'] )
				),
				array( 'action' => 'nbsp_repair', 'source' => 'repair', 'count' => $row['total'], 'run' => $row['run'] )
			);
		}

		++$repaired;
		$lines[] = sprintf( '%s — %s (%d)', $row['label'], $row['title'], $row['total'] );
	}

	return array( 'repaired' => $repaired, 'skipped' => $skipped, 'lines' => $lines );
}

/**
 * The LAW → Migration card: the scan table and the Apply button.
 * Called from law_migration_admin_page().
 */
function law_events_nbsp_panel() {
	$notice = '';
	if ( isset( $_POST['law_repair_nbsp_nonce'] ) ) {
		check_admin_referer( 'law_repair_nbsp', 'law_repair_nbsp_nonce' );
		$tokens = array_map( 'strval', (array) ( $_POST['law_repair_nbsp_rows'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per token in law_events_nbsp_apply().
		$result = law_events_nbsp_apply( $tokens );
		$notice = sprintf(
			'<div class="notice notice-%s"><p><strong>%d value(s) cleaned.</strong>%s</p>%s</div>',
			$result['skipped'] ? 'warning' : 'success',
			$result['repaired'],
			$result['skipped'] ? ' ' . esc_html( implode( ' ', $result['skipped'] ) ) : '',
			$result['lines'] ? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $result['lines'] ) ) . '</li></ul>' : ''
		);
	}

	$rows = law_events_nbsp_scan();
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html().
	?>
	<h2>Repair: non-breaking spaces in pasted text</h2>
	<p class="description" style="max-width:900px">
		A paste from Word, Google Docs or a PDF carries the source's spaces, and those applications use a
		non-breaking space where a reader sees an ordinary one. A browser never breaks a line at one, so a sentence
		joined by them behaves as a single unbreakable word and runs out of its column instead of wrapping.
		Descriptions saved from now on have these stripped automatically, so this list only covers text stored before
		that; re-running it is harmless. Rows are ticked where two or more sit in a row, which is enough to widen a
		column. A single stray one is listed unticked, because on its own it breaks nothing. Only spaces change &mdash;
		no words, no markup &mdash; and each change is written to the event's activity log.
	</p>

	<?php if ( ! $rows ) : ?>
		<p><strong>Nothing to repair.</strong> No stored description or biography holds a non-breaking space.</p>
	<?php else : ?>
		<form method="post">
			<?php wp_nonce_field( 'law_repair_nbsp', 'law_repair_nbsp_nonce' ); ?>
			<table class="widefat striped" style="max-width:1100px">
				<thead>
				<tr>
					<th style="width:2em"><input type="checkbox" checked disabled></th>
					<th>Field</th><th>Where</th><th style="width:5em">Count</th><th style="width:7em">Longest run</th><th>Text affected</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $token => $row ) : ?>
					<tr>
						<td><input type="checkbox" name="law_repair_nbsp_rows[]" value="<?php echo esc_attr( $token ); ?>" <?php checked( $row['run'] >= 2 ); ?>></td>
						<td><?php echo esc_html( $row['label'] ); ?></td>
						<td>
							<a href="<?php echo esc_url( (string) get_edit_post_link( $row['post_id'] ) ); ?>">#<?php echo esc_html( (string) $row['post_id'] ); ?> <?php echo esc_html( $row['title'] ); ?></a>
						</td>
						<td><?php echo esc_html( (string) $row['total'] ); ?></td>
						<td<?php echo $row['run'] >= 2 ? ' style="color:#b32d2e"' : ''; ?>>
							<?php echo esc_html( $row['run'] ? sprintf( '%d words joined', $row['run'] + 1 ) : 'none' ); ?>
						</td>
						<td><span class="description"><?php echo esc_html( $row['sample'] ? mb_substr( $row['sample'], 0, 120 ) : '—' ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="submit" class="button button-primary">Clean the ticked value(s)</button></p>
		</form>
	<?php endif; ?>
	<?php
}
