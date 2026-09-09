<?php
/**
 * One-off backfill: switch the session agenda ON for every event that already
 * has sessions.
 *
 * Before 9 September 2026 the Session agenda section was on every event form
 * unconditionally, and the 4.2 §3.6 "enhanced agenda is opt-in per event"
 * switch was merely whether any sessions had been typed into it. It is now an
 * explicit committee switch, _law_session_agenda, and the form section is
 * gated on it (law_event_has_session_agenda()).
 *
 * That predicate also returns true while an event has law_session children,
 * so no existing agenda is stranded by the change. But the dashboard filter
 * matches the meta key alone, so without this backfill an event with sessions
 * would have an open Session agenda section and still be missing from the
 * "With an agenda" filter — the switch and the filter would mean different
 * things. This sets the key to match reality, once.
 *
 * Idempotent: re-running it finds nothing to do. Dry run by default; nothing
 * is written until "Apply" is pressed.
 *
 * Keep the "or has sessions" limb in law_event_has_session_agenda() even after
 * this has run: law_session posts are independently creatable in wp-admin, so
 * sessions can still appear on a switched-off event later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events with sessions whose _law_session_agenda switch is not yet on.
 *
 * Every post status is considered, trash included: a trashed event that is
 * later restored should come back with its switch right, exactly as
 * repair-owners.php reasons about ownership.
 *
 * @return array[] One row per event: event_id, title, status, sessions.
 */
function law_events_backfill_agenda_scan() {
	global $wpdb;

	$parents = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_parent > 0",
			LAW_SESSION_CPT
		)
	);

	$rows = array();
	foreach ( array_map( 'intval', (array) $parents ) as $event_id ) {
		$event = get_post( $event_id );
		if ( ! $event || LAW_EVENT_CPT !== $event->post_type ) {
			continue; // An orphan or a re-parented session: not ours to touch.
		}
		if ( law_event_meta( $event_id, '_law_session_agenda' ) ) {
			continue; // Already on.
		}
		$rows[] = array(
			'event_id' => $event_id,
			'title'    => $event->post_title,
			'status'   => law_event_status_label( $event ),
			'sessions' => count( law_event_session_ids( $event_id ) ),
		);
	}

	usort(
		$rows,
		static function ( $a, $b ) {
			return $a['event_id'] <=> $b['event_id'];
		}
	);
	return $rows;
}

/**
 * Switch the agenda on for the named events (or every scanned one).
 *
 * Logged on each event like any other committee change, so the switch never
 * appears to have turned itself on.
 *
 * @param int[] $event_ids Subset to apply to; empty means all scanned.
 * @return array{applied:int,lines:string[]}
 */
function law_events_backfill_agenda_apply( array $event_ids = array() ) {
	$rows    = law_events_backfill_agenda_scan();
	$wanted  = array_map( 'absint', $event_ids );
	$actor   = get_current_user_id();
	$applied = 0;
	$lines   = array();

	foreach ( $rows as $row ) {
		if ( $wanted && ! in_array( $row['event_id'], $wanted, true ) ) {
			continue;
		}
		$before = array(
			'_law_is_law_event'   => (int) law_event_meta( $row['event_id'], '_law_is_law_event' ),
			'_law_session_agenda' => (int) law_event_meta( $row['event_id'], '_law_session_agenda' ),
		);
		law_event_update_meta( $row['event_id'], '_law_session_agenda', 1 );
		law_event_log_flag_change( $row['event_id'], $before, $actor );
		$applied++;
		$lines[] = sprintf( '#%d %s (%d sessions)', $row['event_id'], $row['title'], $row['sessions'] );
	}

	return array( 'applied' => $applied, 'lines' => $lines );
}

/** The panel on the migration screen (functions/events/migration/page.php). */
function law_events_backfill_agenda_panel() {
	$notice = '';
	if ( isset( $_POST['law_backfill_agenda_nonce'] ) ) {
		check_admin_referer( 'law_backfill_agenda', 'law_backfill_agenda_nonce' );
		$result = law_events_backfill_agenda_apply( array_map( 'absint', (array) ( $_POST['law_backfill_agenda_ids'] ?? array() ) ) );
		$notice = sprintf(
			'<div class="notice notice-success"><p><strong>%d event(s) switched on.</strong></p>%s</div>',
			$result['applied'],
			$result['lines'] ? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $result['lines'] ) ) . '</li></ul>' : ''
		);
	}

	$rows = law_events_backfill_agenda_scan();
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html().
	?>
	<h2>Backfill: session agenda switch</h2>
	<p class="description" style="max-width:900px">
		The Session agenda section on the event form is now gated on an explicit committee switch
		(<code>_law_session_agenda</code>), rather than simply appearing on every form. Events that already have
		sessions keep the section either way, but the dashboard's "With an agenda" filter reads the switch, so
		without this backfill they would have an agenda and still not show up in that filter. This sets the switch
		to match the sessions each event actually has. Safe to re-run: it only ever finds events whose switch is
		still off.
	</p>

	<?php if ( ! $rows ) : ?>
		<p><strong>Nothing to backfill.</strong> Every event with sessions already has the session agenda switched on.</p>
	<?php else : ?>
		<form method="post">
			<?php wp_nonce_field( 'law_backfill_agenda', 'law_backfill_agenda_nonce' ); ?>
			<table class="widefat striped" style="max-width:900px">
				<thead>
				<tr>
					<th style="width:2em"><input type="checkbox" checked disabled></th>
					<th>Event</th><th>Status</th><th>Sessions</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><input type="checkbox" name="law_backfill_agenda_ids[]" value="<?php echo esc_attr( (string) $row['event_id'] ); ?>" checked></td>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['event_id'] ) ); ?>">#<?php echo esc_html( (string) $row['event_id'] ); ?> <?php echo esc_html( $row['title'] ); ?></a></td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
						<td><?php echo esc_html( (string) $row['sessions'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="submit" class="button button-primary">Switch the session agenda on for the ticked events</button></p>
		</form>
	<?php endif; ?>
	<?php
}
