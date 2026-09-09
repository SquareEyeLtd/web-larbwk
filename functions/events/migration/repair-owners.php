<?php
/**
 * One-off repair: event ownership and publish dates overwritten by a form save.
 *
 * Until 9 September 2026 law_events_form_save() passed a partial array to
 * wp_insert_post() on the UPDATE path. wp_insert_post() fills every omitted key
 * from its own defaults before it works out that it is an update, so every save
 * through the front-end form rewrote post_author to whoever pressed save and
 * reset post_date to "now". A committee member editing an event from
 * /account/dashboard/ therefore became its host, and law_user_can_manage_event()
 * locked the real host out of their own event; host-facing notifications
 * (which resolve the host from post_author) went to the committee member too.
 *
 * submission-form.php now uses wp_update_post() on that path. This file repairs
 * the events already damaged, reading the true owner and creation time out of
 * the append-only activity log rather than guessing.
 *
 * Dry run by default; nothing is written until "Apply" is pressed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This event's log entries, oldest first.
 *
 * Deliberately not law_event_log_entries(): that asks for approved comments
 * only, and wp_trash_post_comments() rewrites every comment on a trashed post
 * to the `post-trashed` status — so a trashed event's whole history would be
 * invisible here, and its ownership would silently go unrepaired until someone
 * restored it. Note the status is 'any', not 'all': in WP_Comment_Query 'all'
 * still means approved-or-held, and only 'any' drops the clause entirely.
 *
 * @param int $event_id law_event post ID.
 * @return WP_Comment[]
 */
function law_events_repair_owner_log( $event_id ) {
	return get_comments(
		array(
			'post_id' => (int) $event_id,
			'type'    => LAW_EVENT_LOG_TYPE,
			'status'  => 'any',
			'orderby' => 'comment_date_gmt',
			'order'   => 'ASC',
		)
	);
}

/**
 * The true origin of an event, from its activity log.
 *
 * The `submit` transition is the authoritative marker: it is written by
 * law_event_set_status() with the acting user, and only the host (or a
 * committee member acting on a draft) can fire it. Falls back to the earliest
 * logged entry by a real user, which for an event still in draft is its
 * creator.
 *
 * @param int $event_id law_event post ID.
 * @return array{user_id:int,date:string,basis:string}|null
 */
function law_events_repair_owner_origin( $event_id ) {
	$entries = law_events_repair_owner_log( $event_id );
	$first   = null;

	foreach ( $entries as $entry ) {
		$user_id = (int) $entry->user_id;
		if ( $user_id < 1 ) {
			continue; // System/webhook lines say nothing about ownership.
		}
		if ( null === $first ) {
			$first = array( 'user_id' => $user_id, 'date' => $entry->comment_date, 'basis' => 'first log entry' );
		}
		$context = law_event_log_context( $entry->comment_ID );
		if ( 'submit' === ( $context['action'] ?? '' ) ) {
			return array( 'user_id' => $user_id, 'date' => $entry->comment_date, 'basis' => 'submit transition' );
		}
	}

	return $first;
}

/**
 * Did somebody OTHER than the origin owner save this event through the
 * front-end form? That is the only way the author could have been rewritten by
 * the bug, so it is the corroboration the repair insists on before it moves an
 * author — a deliberate reassignment through the wp-admin author dropdown
 * leaves no such line and must be left alone.
 *
 * @param int $event_id law_event post ID.
 * @param int $author   The current post_author to look for.
 * @return string Log date of the offending save, '' when there is none.
 */
function law_events_repair_owner_form_save_by( $event_id, $author ) {
	$author = (int) $author;
	if ( $author < 1 ) {
		return '';
	}
	foreach ( law_events_repair_owner_log( $event_id ) as $entry ) {
		if ( (int) $entry->user_id !== $author ) {
			continue;
		}
		$action = law_event_log_context( $entry->comment_ID )['action'] ?? '';
		if ( in_array( $action, array( 'committee_edit', 'host_edit' ), true ) ) {
			return $entry->comment_date;
		}
	}
	return '';
}

/**
 * Every event the repair would touch, plus every one it deliberately skips.
 *
 * @return array{fix:array<int,array>,skip:array<int,array>}
 */
function law_events_repair_owner_scan() {
	$events = get_posts(
		array(
			'post_type' => LAW_EVENT_CPT,
			// Every registered status, not 'any': 'any' drops the trash, and a
			// trashed event still has an owner that must be right if it is ever
			// restored.
			'post_status'      => array_keys( get_post_stati() ),
			'numberposts'      => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		)
	);

	$fix  = array();
	$skip = array();

	foreach ( $events as $event ) {
		$origin = law_events_repair_owner_origin( $event->ID );
		if ( ! $origin ) {
			continue; // No log evidence at all: migrated or hand-made. Silent, not a problem.
		}

		$author_wrong = (int) $event->post_author !== $origin['user_id'];
		// A second of slack: the log line is written just after the post save.
		$date_wrong = strtotime( $event->post_date ) > strtotime( $origin['date'] ) + 60;

		if ( ! $author_wrong && ! $date_wrong ) {
			continue;
		}

		$row = array(
			'event_id'        => (int) $event->ID,
			'title'           => $event->post_title,
			'status'          => $event->post_status,
			'author_now'      => (int) $event->post_author,
			'author_proposed' => $author_wrong ? $origin['user_id'] : (int) $event->post_author,
			'date_now'        => $event->post_date,
			'date_proposed'   => $date_wrong ? $origin['date'] : $event->post_date,
			'basis'           => $origin['basis'],
			'fix_author'      => $author_wrong,
			'fix_date'        => $date_wrong,
		);

		if ( $author_wrong ) {
			if ( ! get_user_by( 'id', $origin['user_id'] ) ) {
				$row['reason'] = sprintf( 'Original owner (user %d) no longer has an account.', $origin['user_id'] );
				$skip[]        = $row;
				continue;
			}
			$offending = law_events_repair_owner_form_save_by( $event->ID, $event->post_author );
			if ( '' === $offending ) {
				$row['reason'] = 'Current owner never saved this event through the front-end form, so the author was changed some other way. Left alone.';
				$skip[]        = $row;
				continue;
			}
			$row['offending_save'] = $offending;
		}

		$fix[] = $row;
	}

	return array( 'fix' => $fix, 'skip' => $skip );
}

/**
 * Apply the repair.
 *
 * @param int[] $event_ids Events to repair; empty means every event the scan finds.
 * @return array{repaired:int,failed:array<int,string>,lines:string[]}
 */
function law_events_repair_owner_apply( array $event_ids = array() ) {
	$scan     = law_events_repair_owner_scan();
	$wanted   = array_map( 'intval', $event_ids );
	$repaired = 0;
	$failed   = array();
	$lines    = array();

	foreach ( $scan['fix'] as $row ) {
		if ( $wanted && ! in_array( $row['event_id'], $wanted, true ) ) {
			continue;
		}

		$postarr = array( 'ID' => $row['event_id'] );
		if ( $row['fix_author'] ) {
			$postarr['post_author'] = $row['author_proposed'];
		}
		if ( $row['fix_date'] ) {
			$postarr['post_date']     = $row['date_proposed'];
			$postarr['post_date_gmt'] = get_gmt_from_date( $row['date_proposed'] );
			// Tells wp_update_post() the date is deliberate, so its
			// "drafts get today's date" branch cannot re-stamp it.
			$postarr['edit_date'] = true;
		}

		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			$failed[ $row['event_id'] ] = $result->get_error_message();
			continue;
		}

		$notes = array();
		if ( $row['fix_author'] ) {
			$was = get_user_by( 'id', $row['author_now'] );
			$now = get_user_by( 'id', $row['author_proposed'] );
			$notes[] = sprintf(
				'owner restored to %s (%s) from %s (%s)',
				$now ? $now->display_name : 'user ' . $row['author_proposed'],
				$now ? $now->user_email : 'unknown',
				$was ? $was->display_name : 'user ' . $row['author_now'],
				$was ? $was->user_email : 'unknown'
			);
		}
		if ( $row['fix_date'] ) {
			$notes[] = sprintf( 'submission date restored to %s from %s', $row['date_proposed'], $row['date_now'] );
		}

		$message = sprintf(
			'Data repair: %s. The author and date had been overwritten by a front-end form save (fixed 9 September 2026); the original values come from this event\'s %s.',
			implode( ' and ', $notes ),
			$row['basis']
		);

		law_event_log(
			$row['event_id'],
			$message,
			array(
				'action'          => 'owner_repair',
				'source'          => 'repair',
				'author_old'      => $row['author_now'],
				'author_new'      => $row['author_proposed'],
				'post_date_old'   => $row['date_now'],
				'post_date_new'   => $row['date_proposed'],
				'basis'           => $row['basis'],
			)
		);

		++$repaired;
		$lines[] = sprintf( '#%d %s — %s', $row['event_id'], $row['title'], implode( '; ', $notes ) );
	}

	return array( 'repaired' => $repaired, 'failed' => $failed, 'lines' => $lines );
}

/**
 * The LAW → Migration card: the scan table and the Apply button.
 * Called from law_migration_admin_page().
 */
function law_events_repair_owner_panel() {
	$notice = '';
	if ( isset( $_POST['law_repair_owners_nonce'] ) ) {
		check_admin_referer( 'law_repair_owners', 'law_repair_owners_nonce' );
		$ids    = array_map( 'absint', (array) ( $_POST['law_repair_owners_ids'] ?? array() ) );
		$result = law_events_repair_owner_apply( $ids );
		$notice = sprintf(
			'<div class="notice notice-%s"><p><strong>%d event(s) repaired.</strong>%s</p>%s</div>',
			$result['failed'] ? 'warning' : 'success',
			$result['repaired'],
			$result['failed'] ? ' ' . count( $result['failed'] ) . ' failed: ' . esc_html( implode( '; ', $result['failed'] ) ) : '',
			$result['lines'] ? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $result['lines'] ) ) . '</li></ul>' : ''
		);
	}

	$scan = law_events_repair_owner_scan();
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html().
	?>
	<h2>Repair: event owners overwritten by a form save</h2>
	<p class="description" style="max-width:900px">
		Until 9 September 2026 every save through the front-end event form rewrote <code>post_author</code> to
		whoever pressed save and reset <code>post_date</code> to that moment, because the update went through
		<code>wp_insert_post()</code> with a partial array. A committee member editing an event from the dashboard
		became its host, which locked the real host out and sent their notifications to the wrong inbox.
		The true owner below is read from each event's activity log, and an author is only ever moved when the log
		also shows the current owner saving that event through the form. Trashed events are included, so their
		ownership is right if they are ever restored. Check the list before applying.
	</p>

	<?php if ( ! $scan['fix'] && ! $scan['skip'] ) : ?>
		<p><strong>Nothing to repair.</strong> No event's owner or submission date disagrees with its activity log.</p>
	<?php else : ?>
		<?php if ( $scan['fix'] ) : ?>
			<form method="post">
				<?php wp_nonce_field( 'law_repair_owners', 'law_repair_owners_nonce' ); ?>
				<table class="widefat striped" style="max-width:1100px">
					<thead>
					<tr>
						<th style="width:2em"><input type="checkbox" checked disabled></th>
						<th>Event</th><th>Owner now</th><th>Owner should be</th>
						<th>Submitted (stored)</th><th>Submitted (actual)</th><th>Evidence</th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $scan['fix'] as $row ) : ?>
						<?php
						$was = get_user_by( 'id', $row['author_now'] );
						$now = get_user_by( 'id', $row['author_proposed'] );
						?>
						<tr>
							<td><input type="checkbox" name="law_repair_owners_ids[]" value="<?php echo esc_attr( (string) $row['event_id'] ); ?>" checked></td>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $row['event_id'] ) ); ?>">#<?php echo esc_html( (string) $row['event_id'] ); ?> <?php echo esc_html( $row['title'] ); ?></a>
								<br><span class="description"><?php echo esc_html( $row['status'] ); ?></span>
							</td>
							<td><?php echo $row['fix_author'] ? esc_html( sprintf( '%s (%s)', $was ? $was->display_name : 'user ' . $row['author_now'], $was ? $was->user_email : '?' ) ) : '<em>unchanged</em>'; ?></td>
							<td><?php echo $row['fix_author'] ? '<strong>' . esc_html( sprintf( '%s (%s)', $now ? $now->display_name : 'user ' . $row['author_proposed'], $now ? $now->user_email : '?' ) ) . '</strong>' : '&mdash;'; ?></td>
							<td><?php echo esc_html( $row['date_now'] ); ?></td>
							<td><?php echo $row['fix_date'] ? '<strong>' . esc_html( $row['date_proposed'] ) . '</strong>' : '<em>unchanged</em>'; ?></td>
							<td class="description">
								<?php echo esc_html( $row['basis'] ); ?>
								<?php if ( ! empty( $row['offending_save'] ) ) : ?>
									<br>form save by current owner at <?php echo esc_html( $row['offending_save'] ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( 'Apply the repair to the ticked events', 'primary', 'submit', true, array( 'onclick' => "return confirm('Restore the owner and submission date on the ticked events? Each change is written to the event\\'s activity log.');" ) ); ?>
			</form>
		<?php endif; ?>

		<?php if ( $scan['skip'] ) : ?>
			<h3>Skipped (needs a human)</h3>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th>Event</th><th>Owner now</th><th>Log says</th><th>Why it was skipped</th></tr></thead>
				<tbody>
				<?php foreach ( $scan['skip'] as $row ) : ?>
					<?php
					$was = get_user_by( 'id', $row['author_now'] );
					$now = get_user_by( 'id', $row['author_proposed'] );
					?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $row['event_id'] ) ); ?>">#<?php echo esc_html( (string) $row['event_id'] ); ?> <?php echo esc_html( $row['title'] ); ?></a></td>
						<td><?php echo esc_html( $was ? sprintf( '%s (%s)', $was->display_name, $was->user_email ) : 'user ' . $row['author_now'] ); ?></td>
						<td><?php echo esc_html( $now ? sprintf( '%s (%s)', $now->display_name, $now->user_email ) : 'user ' . $row['author_proposed'] ); ?></td>
						<td><?php echo esc_html( $row['reason'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>
	<?php
}
