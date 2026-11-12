<?php
/**
 * One-off helper: merge two speaker records into one (Denis, 26 October 2026).
 *
 * Duplicates get in through doors the dedupe cannot see. The same host typed
 * Sylvia Tonova twice into form 8 (Event > speaker) on 9 September 2026, once
 * as sylvia.tonova@ and once as sylvia.tonva@; two emails rightly made two
 * records, and correcting the typo later on the wp-admin speaker screen left
 * two records sharing one address. A typo can sit in the name just as well, so
 * no automatic match is precise enough: an administrator picks the pair.
 *
 * The MAIN speaker is kept exactly as it is: title, name parts, email, website,
 * biography and featured image are never touched. The SECOND speaker's rows,
 * on every event and session in any status, are re-pointed at the main speaker
 * with their per-event details (role, organisation, job title, photo, bio)
 * intact, because those are what each host typed for that event (Denis). A
 * post that already names the main speaker loses the second speaker's row
 * instead: one event must never carry the same speaker_id twice, since neither
 * the host form nor the event page dedupes.
 *
 * The legacy /speakers/<entry id>/ addresses, the law_year terms and the old
 * /speakers/<slug>/ address (through core's _wp_old_slug redirect) follow the
 * main speaker. Then the second record goes to the trash, stamped with whom it
 * was merged into. Its trash hook (speakers.php) finds no rows left, so it
 * detaches and logs nothing, and a later restore brings back an empty record,
 * not the moved rows.
 *
 * Admin-only, on LAW → Migration: a dry-run preview, then Merge.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** On a merged-away speaker: the ID of the speaker it was merged into. */
const LAW_SPEAKER_MERGED_INTO_META = '_law_speaker_merged_into';

/**
 * What merging $merged_id into $main_id would do, without writing anything.
 * The preview prints it and the apply walks it, so the two cannot disagree.
 *
 * @param int $main_id   The speaker that is kept.
 * @param int $merged_id The speaker merged into it.
 * @return array{main:int,merged:int,posts:array[]}|WP_Error One entry per event
 *         or session naming the second speaker: post_id, event_id, title,
 *         status, action ('move', or 'drop' when the main speaker is already there).
 */
function law_events_merge_speakers_plan( $main_id, $merged_id ) {
	$main_id   = absint( $main_id );
	$merged_id = absint( $merged_id );

	if ( ! $main_id || ! $merged_id ) {
		return new WP_Error( 'law_merge_missing', 'Choose both speakers.' );
	}
	if ( $main_id === $merged_id ) {
		return new WP_Error( 'law_merge_same', 'Choose two different speakers.' );
	}
	foreach ( array( $main_id, $merged_id ) as $speaker_id ) {
		$speaker = get_post( $speaker_id );
		if ( ! $speaker || LAW_SPEAKER_CPT !== $speaker->post_type || 'publish' !== $speaker->post_status ) {
			return new WP_Error( 'law_merge_not_speaker', sprintf( '#%d is not a published speaker record.', $speaker_id ) );
		}
	}

	$posts = array();
	foreach ( law_speaker_holder_ids( $merged_id ) as $post_id ) {
		$event_id = LAW_SESSION_CPT === get_post_type( $post_id ) ? (int) wp_get_post_parent_id( $post_id ) : $post_id;
		$names    = array_map( fn( $row ) => (int) ( $row['speaker_id'] ?? 0 ), law_event_meta( $post_id, '_law_speakers' ) );
		$event    = get_post( $event_id );
		$posts[]  = array(
			'post_id'  => $post_id,
			'event_id' => $event_id,
			'title'    => $event_id === $post_id
				? (string) get_post_field( 'post_title', $post_id )
				: sprintf( 'Session "%s", on %s', get_post_field( 'post_title', $post_id ), $event ? $event->post_title : 'no event' ),
			'status'   => $event ? law_event_status_label( $event ) : '',
			'action'   => in_array( $main_id, $names, true ) ? 'drop' : 'move',
		);
	}

	return array( 'main' => $main_id, 'merged' => $merged_id, 'posts' => $posts );
}

/**
 * Merge $merged_id into $main_id: rows, legacy addresses, year terms, the old
 * profile address, then the second record to the trash.
 *
 * @param int $main_id   The speaker that is kept.
 * @param int $merged_id The speaker merged into it.
 * @param int $actor     User the activity-log lines are written as.
 * @return array{events:int,dropped:int,trashed:bool,lines:string[]}|WP_Error
 */
function law_events_merge_speakers_apply( $main_id, $merged_id, $actor = 0 ) {
	$plan = law_events_merge_speakers_plan( $main_id, $merged_id );
	if ( is_wp_error( $plan ) ) {
		return $plan;
	}
	$main_id     = $plan['main'];
	$merged_id   = $plan['merged'];
	$main_name   = law_speaker_raw_name( $main_id );
	$merged_name = law_speaker_raw_name( $merged_id );

	// Rows. The first of the second speaker's rows on a post becomes the main
	// speaker's unless the post names the main speaker already; every further
	// one is dropped, so a post ends with the person once.
	$changed = array();
	$events  = array(); // event ID => whether a duplicate row was dropped there.
	$lines   = array();
	foreach ( $plan['posts'] as $item ) {
		$post_id  = $item['post_id'];
		$rows     = law_event_meta( $post_id, '_law_speakers' );
		$has_main = 'drop' === $item['action'];
		$keep     = array();
		$dropped  = false;
		foreach ( $rows as $row ) {
			if ( (int) ( $row['speaker_id'] ?? 0 ) !== $merged_id ) {
				$keep[] = $row;
			} elseif ( $has_main ) {
				$dropped = true;
			} else {
				$row['speaker_id'] = $main_id;
				$keep[]            = $row;
				$has_main          = true;
			}
		}
		law_event_update_meta( $post_id, '_law_speakers', $keep );
		$changed[] = $post_id;
		if ( $item['event_id'] ) {
			$events[ $item['event_id'] ] = ! empty( $events[ $item['event_id'] ] ) || $dropped;
		}
		$lines[] = sprintf( '%s (%s): %s', $item['title'], $item['status'], $dropped ? 'duplicate row removed' : 'moved' );
	}
	law_speaker_after_rows_changed( $changed );

	// One line per event, a session folded into its event, as the delete does.
	foreach ( $events as $event_id => $dropped ) {
		$message = sprintf( 'Speaker "%s" (#%d) merged into "%s" (#%d).', $merged_name, $merged_id, $main_name, $main_id );
		if ( $dropped ) {
			$message .= sprintf( ' That left "%s" on this event twice, so the extra row was removed.', $main_name );
		}
		law_event_log(
			$event_id,
			$message,
			array(
				'action'  => 'speaker_merged',
				'source'  => 'speaker_merge',
				'speaker' => $main_id,
				'merged'  => $merged_id,
			),
			array( 'user_id' => (int) $actor )
		);
	}

	// Legacy /speakers/<entry id>/ addresses resolve through the migration map
	// first and the entry-ID meta second (law_events_resolve_speaker_id()), and
	// the map would still answer with the trashed record, so both move.
	$entry_ids = array_merge( array( (int) law_event_meta( $merged_id, '_law_gf_entry_id' ) ), law_event_meta( $merged_id, '_law_gf_entry_ids' ) );
	foreach ( (array) ( law_migration_map()['speakers'] ?? array() ) as $entry_id => $speaker_id ) {
		if ( (int) $speaker_id === $merged_id ) {
			law_migration_map_set( 'speakers', $entry_id, $main_id );
			$entry_ids[] = (int) $entry_id;
		}
	}
	$entry_ids = array_filter( $entry_ids );
	if ( $entry_ids ) {
		law_event_update_meta( $main_id, '_law_gf_entry_ids', array_unique( array_merge( law_event_meta( $main_id, '_law_gf_entry_ids' ), $entry_ids ) ) );
	}

	// Year membership, not identity: the main speaker now speaks in those years too.
	$years = wp_get_object_terms( $merged_id, 'law_year', array( 'fields' => 'ids' ) );
	if ( $years && ! is_wp_error( $years ) ) {
		wp_set_object_terms( $main_id, array_map( 'intval', $years ), 'law_year', true );
	}

	// The old profile address. Core's wp_old_slug_redirect() 301s a 404'd
	// /speakers/<slug>/ to the post holding that slug as _wp_old_slug.
	$main_slug = (string) get_post_field( 'post_name', $main_id );
	$old_slugs = get_post_meta( $main_id, '_wp_old_slug' );
	foreach ( array_merge( array( (string) get_post_field( 'post_name', $merged_id ) ), get_post_meta( $merged_id, '_wp_old_slug' ) ) as $slug ) {
		if ( '' !== $slug && $slug !== $main_slug && ! in_array( $slug, $old_slugs, true ) ) {
			add_post_meta( $main_id, '_wp_old_slug', $slug );
			$old_slugs[] = $slug;
		}
	}

	// Speakers carry no log of their own, so the record says where it went.
	update_post_meta( $merged_id, LAW_SPEAKER_MERGED_INTO_META, $main_id );
	$trashed = (bool) wp_trash_post( $merged_id );

	return array(
		'events'  => count( $events ),
		'dropped' => count( array_filter( $events ) ),
		'trashed' => $trashed,
		'lines'   => $lines,
	);
}

/**
 * The select options: every published speaker, by name, labelled with what
 * tells two near-identical records apart (email, record ID, event count).
 *
 * @return array{options:array<int,string>,truncated:bool}
 */
function law_events_merge_speakers_choices() {
	$speakers = get_posts(
		array(
			'post_type'      => LAW_SPEAKER_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => LAW_SPEAKERS_DASHBOARD_SCREEN_CAP,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	$events  = law_speakers_event_maps( law_speakers_dashboard_statuses(), LAW_SPEAKERS_DASHBOARD_SCREEN_CAP )['events'];
	$options = array();
	foreach ( $speakers as $speaker ) {
		$count  = count( array_unique( $events[ $speaker->ID ] ?? array() ) );
		$email  = (string) law_event_meta( $speaker->ID, '_law_speaker_email' );
		$options[ (int) $speaker->ID ] = sprintf(
			'%s (%s), #%d, %s',
			law_speaker_raw_name( $speaker->ID ),
			'' !== $email ? $email : 'no email',
			$speaker->ID,
			sprintf( _n( '%d event', '%d events', $count, 'law' ), $count )
		);
	}
	return array( 'options' => $options, 'truncated' => count( $speakers ) >= LAW_SPEAKERS_DASHBOARD_SCREEN_CAP );
}

/** The panel on the migration screen (functions/events/migration/page.php). */
function law_events_merge_speakers_panel() {
	$main   = absint( $_POST['law_merge_main'] ?? 0 );
	$merged = absint( $_POST['law_merge_merged'] ?? 0 );
	$plan   = null;
	$notice = '';

	if ( isset( $_POST['law_merge_speakers_nonce'] ) ) {
		check_admin_referer( 'law_merge_speakers', 'law_merge_speakers_nonce' );
		if ( 'merge' === sanitize_key( $_POST['law_merge_step'] ?? '' ) ) {
			$main_name   = law_speaker_raw_name( $main );
			$merged_name = law_speaker_raw_name( $merged );
			$result      = law_events_merge_speakers_apply( $main, $merged, get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				$notice = '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			} else {
				$notice = sprintf(
					'<div class="notice notice-success"><p><strong>%s</strong></p>%s</div>',
					esc_html(
						sprintf(
							'"%s" (#%d) merged into "%s" (#%d)%s.',
							$merged_name,
							$merged,
							$main_name,
							$main,
							$result['trashed'] ? ' and moved to the trash' : ', but could not be moved to the trash'
						)
					),
					$result['lines'] ? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $result['lines'] ) ) . '</li></ul>' : ''
				);
				$main   = 0;
				$merged = 0;
			}
		} else {
			$plan = law_events_merge_speakers_plan( $main, $merged );
			if ( is_wp_error( $plan ) ) {
				$notice = '<div class="notice notice-error"><p>' . esc_html( $plan->get_error_message() ) . '</p></div>';
				$plan   = null;
			}
		}
	}

	$choices = law_events_merge_speakers_choices();
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html().
	?>
	<h2 id="law-merge-speakers">Merge two speakers</h2>
	<p class="description" style="max-width:900px">
		Moves every event of the second speaker onto the main speaker, then moves the second speaker to the trash.
		The main speaker's name, email, website, biography and photo are kept; each event keeps its own job title, photo and bio.
	</p>

	<form method="post" action="#law-merge-speakers">
		<?php wp_nonce_field( 'law_merge_speakers', 'law_merge_speakers_nonce' ); ?>
		<input type="hidden" name="law_merge_step" value="preview">
		<table class="form-table" role="presentation" style="max-width:900px">
			<?php
			foreach (
				array(
					'law_merge_main'   => array( 'Main speaker (kept)', $main ),
					'law_merge_merged' => array( 'Speaker to merge into it', $merged ),
				) as $law_name => list( $law_label, $law_selected )
			) :
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $law_name ); ?>"><?php echo esc_html( $law_label ); ?></label></th>
					<td>
						<select id="<?php echo esc_attr( $law_name ); ?>" name="<?php echo esc_attr( $law_name ); ?>" style="max-width:100%">
							<option value="">Choose a speaker</option>
							<?php foreach ( $choices['options'] as $law_id => $law_option ) : ?>
								<option value="<?php echo esc_attr( (string) $law_id ); ?>" <?php selected( $law_selected, $law_id ); ?>><?php echo esc_html( $law_option ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php if ( $choices['truncated'] ) : ?>
			<p class="description">Only the first <?php echo esc_html( number_format_i18n( LAW_SPEAKERS_DASHBOARD_SCREEN_CAP ) ); ?> speakers are listed.</p>
		<?php endif; ?>
		<p><button type="submit" class="button">Preview merge</button></p>
	</form>

	<?php
	if ( ! $plan ) {
		return;
	}
	$law_profiles = array( 'Main speaker (kept)' => $plan['main'], 'Merged into it' => $plan['merged'] );
	?>
	<table class="widefat striped" style="max-width:900px">
		<thead>
		<tr>
			<th></th>
			<?php foreach ( $law_profiles as $law_heading => $law_id ) : ?>
				<th><?php echo esc_html( $law_heading ); ?></th>
			<?php endforeach; ?>
		</tr>
		</thead>
		<tbody>
		<tr>
			<th scope="row">Record</th>
			<?php foreach ( $law_profiles as $law_id ) : ?>
				<td><a href="<?php echo esc_url( (string) get_edit_post_link( $law_id ) ); ?>">#<?php echo esc_html( (string) $law_id ); ?></a></td>
			<?php endforeach; ?>
		</tr>
		<tr>
			<th scope="row">Name</th>
			<?php foreach ( $law_profiles as $law_id ) : ?>
				<td><?php echo esc_html( law_speaker_raw_name( $law_id ) ); ?></td>
			<?php endforeach; ?>
		</tr>
		<tr>
			<th scope="row">Email</th>
			<?php foreach ( $law_profiles as $law_id ) : ?>
				<td><?php echo esc_html( (string) law_event_meta( $law_id, '_law_speaker_email' ) ?: 'Not set' ); ?></td>
			<?php endforeach; ?>
		</tr>
		<tr>
			<th scope="row">Website</th>
			<?php foreach ( $law_profiles as $law_id ) : ?>
				<td><?php echo esc_html( (string) law_event_meta( $law_id, '_law_website' ) ?: 'Not set' ); ?></td>
			<?php endforeach; ?>
		</tr>
		</tbody>
	</table>

	<?php if ( $plan['posts'] ) : ?>
		<h3>What happens to the second speaker's events</h3>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th>Event</th><th>Status</th><th>What happens</th></tr></thead>
			<tbody>
			<?php foreach ( $plan['posts'] as $law_item ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( (string) get_edit_post_link( $law_item['post_id'] ) ); ?>"><?php echo esc_html( $law_item['title'] ); ?></a></td>
					<td><?php echo esc_html( $law_item['status'] ); ?></td>
					<td><?php echo esc_html( 'drop' === $law_item['action'] ? 'Already has the main speaker, so this duplicate row is removed' : 'Moves to the main speaker, with this event\'s own details' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p>The second speaker is on no event, so merging only moves their record to the trash.</p>
	<?php endif; ?>

	<form method="post" action="#law-merge-speakers">
		<?php wp_nonce_field( 'law_merge_speakers', 'law_merge_speakers_nonce' ); ?>
		<input type="hidden" name="law_merge_step" value="merge">
		<input type="hidden" name="law_merge_main" value="<?php echo esc_attr( (string) $plan['main'] ); ?>">
		<input type="hidden" name="law_merge_merged" value="<?php echo esc_attr( (string) $plan['merged'] ); ?>">
		<p class="description" style="max-width:900px">
			The second speaker's old profile address will redirect to the main speaker.
			Restoring the second speaker from the trash brings back an empty record, not these events.
		</p>
		<p><button type="submit" class="button button-primary"><?php echo esc_html( sprintf( 'Merge #%d into #%d', $plan['merged'], $plan['main'] ) ); ?></button></p>
	</form>
	<?php
}
