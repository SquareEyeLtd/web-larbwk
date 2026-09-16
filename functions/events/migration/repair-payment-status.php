<?php
/**
 * Repair the payment status of events that owe nothing but say "Unpaid".
 *
 * The events migrated from form 2 (Event > submit an event) that hold a
 * snapshotted host fee of £0 and a payment status of `unpaid` (22 when this
 * was written, 36 on the current local copy). Every one of them is Confirmed,
 * and their source entries had field 84 (Calculated fee (pence)) empty or 0
 * with field 95 (Event status) reading Confirmed, so nothing was ever owed on
 * them.
 *
 * `law_migration_derive_payment()` (runner.php) calls that combination `free`
 * and always has, so where the `unpaid` came from was a puzzle until
 * 16 September 2026, when the audit of the migrated data found it: the meta
 * schema declared `_law_payment_status` twice, once for the event and once for
 * the booking, and law_events_all_meta_schemas() merged them so the BOOKING
 * vocabulary won for both. Writing an event's `free` through
 * law_event_update_meta() sanitised it to `pending_setup` (no such event
 * state), and the per-post-type callback registered by law_events_register_meta()
 * then read THAT as unknown and stored `unpaid`. `unpaid` survived the same
 * round trip by accident, which is why only the free events were damaged.
 * law_events_meta_type() now resolves the schema from the post's own post
 * type, so a migration run today produces `free` correctly and this panel is a
 * one-off repair for the rows written before the fix rather than a chore to
 * repeat after every run.
 *
 * Rather than a silent UPDATE, this is a panel: the events are listed, each
 * row can be unticked, and every change lands in the event's activity log
 * through law_event_set_payment_status().
 *
 * Nothing in the booking path reads an event's payment status -- booking is
 * gated on publication, which is what paying does -- so this is a
 * committee-facing correctness fix. The Payment column on the Events dashboard
 * and in wp-admin reads "Unpaid" against an event that owes nothing, which is
 * exactly the sort of thing that wastes an afternoon during a reconciliation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every event whose £0 fee disagrees with its Unpaid payment status.
 *
 * Deliberately narrow: a fee of 0, a status of exactly `unpaid`, and an event
 * that has actually been approved. An event with a real fee outstanding is none
 * of this panel's business, neither is one already reading paid, free or
 * refunded, and neither is one still under review, whose fee has not been
 * snapshotted yet.
 *
 * @return array<int,array{event_id:int,title:string,status:string,entry_id:int}>
 */
function law_events_repair_payment_scan() {
	$events = get_posts(
		array(
			'post_type'        => LAW_EVENT_CPT,
			// Every registered status, not 'any', which drops the trash: a
			// trashed event's payment status should be right too if it is ever
			// restored.
			'post_status'      => array_keys( get_post_stati() ),
			'numberposts'      => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		)
	);

	$rows = array();
	foreach ( $events as $event ) {
		if ( 'unpaid' !== (string) law_event_meta( $event->ID, '_law_payment_status' ) ) {
			continue;
		}
		if ( (int) law_event_meta( $event->ID, '_law_fee_pence' ) > 0 ) {
			continue;
		}
		// Approved or Confirmed only. A £0 on an event still under review is
		// not a decision that nothing is owed: the fee is snapshotted AT
		// approval (law_event_snapshot_fee()), and the approve transition
		// already sets Free itself when that snapshot comes out at zero. Six
		// Proposed sponsor events qualified on fee and status alone, and
		// calling them Free would be answering a question the committee has
		// not reached yet.
		if ( ! law_event_has_been_approved( $event->ID ) ) {
			continue;
		}
		$rows[] = array(
			'event_id' => (int) $event->ID,
			'title'    => $event->post_title,
			'status'   => law_event_status_label( $event ),
			'entry_id' => (int) law_event_meta( $event->ID, '_law_gf_entry_id' ),
		);
	}
	return $rows;
}

/**
 * Set the chosen events to Free, with a log line on each.
 *
 * @param int[] $ids Event IDs ticked on the panel.
 * @return array{repaired:int,skipped:string[],lines:string[]}
 */
function law_events_repair_payment_apply( array $ids ) {
	$allowed  = wp_list_pluck( law_events_repair_payment_scan(), 'event_id' );
	$repaired = 0;
	$skipped  = array();
	$lines    = array();

	foreach ( $ids as $id ) {
		$id = (int) $id;
		// Re-checked against the scan rather than trusted from the post: the
		// panel may have been open while somebody raised a fee or marked the
		// event paid, and this must never overwrite that.
		if ( ! in_array( $id, $allowed, true ) ) {
			$skipped[] = sprintf( '#%d no longer qualifies (its fee or payment status has changed).', $id );
			continue;
		}

		law_event_set_payment_status( $id, 'free', 'repair', get_current_user_id() );
		law_event_log(
			$id,
			'Data repair: this event owes no host fee, so its payment status is Free rather than Unpaid. The £0 came from form 2 (Event > submit an event) field 84 (Calculated fee (pence)) at migration; nothing is owed and nothing was ever invoiced.',
			array( 'action' => 'payment_repair', 'source' => 'repair', 'old' => 'unpaid', 'new' => 'free' )
		);

		++$repaired;
		$lines[] = sprintf( '#%d %s — Unpaid → Free', $id, get_the_title( $id ) );
	}

	return array( 'repaired' => $repaired, 'skipped' => $skipped, 'lines' => $lines );
}

/**
 * The LAW → Migration card: the scan table and the Apply button.
 * Called from law_migration_admin_page().
 */
function law_events_repair_payment_panel() {
	$notice = '';
	if ( isset( $_POST['law_repair_payment_nonce'] ) ) {
		check_admin_referer( 'law_repair_payment', 'law_repair_payment_nonce' );
		$ids    = array_map( 'absint', (array) ( $_POST['law_repair_payment_ids'] ?? array() ) );
		$result = law_events_repair_payment_apply( $ids );
		$notice = sprintf(
			'<div class="notice notice-%s"><p><strong>%d event(s) set to Free.</strong>%s</p>%s</div>',
			$result['skipped'] ? 'warning' : 'success',
			$result['repaired'],
			$result['skipped'] ? ' ' . esc_html( implode( ' ', $result['skipped'] ) ) : '',
			$result['lines'] ? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $result['lines'] ) ) . '</li></ul>' : ''
		);
	}

	$rows = law_events_repair_payment_scan();
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html().
	?>
	<h2>Repair: events that owe nothing but read "Unpaid"</h2>
	<p class="description" style="max-width:900px">
		These events carry a host fee of £0 and a payment status of Unpaid, so the Payment column says they owe money
		when nothing was ever invoiced. They came across from form 2 (Event &gt; submit an event) with field 84
		(Calculated fee (pence)) empty and field 95 (Event status) already Confirmed. Migration derives that
		combination as Free now, so nothing new will land in this state. Setting them to Free changes no behaviour
		&mdash; booking is gated on publication, not on this field &mdash; and each change is written to the event's
		activity log. Events with a real fee outstanding are never listed here.
	</p>

	<?php if ( ! $rows ) : ?>
		<p><strong>Nothing to repair.</strong> No event holds a £0 fee against an Unpaid payment status.</p>
	<?php else : ?>
		<form method="post">
			<?php wp_nonce_field( 'law_repair_payment', 'law_repair_payment_nonce' ); ?>
			<table class="widefat striped" style="max-width:900px">
				<thead>
				<tr>
					<th style="width:2em"><input type="checkbox" checked disabled></th>
					<th>Event</th><th>Status</th><th>Host fee</th><th>Payment now</th><th>Payment after</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><input type="checkbox" name="law_repair_payment_ids[]" value="<?php echo esc_attr( (string) $row['event_id'] ); ?>" checked></td>
						<td>
							<a href="<?php echo esc_url( (string) get_edit_post_link( $row['event_id'] ) ); ?>">#<?php echo esc_html( (string) $row['event_id'] ); ?> <?php echo esc_html( $row['title'] ); ?></a>
							<?php if ( $row['entry_id'] ) : ?>
								<br><span class="description">form 2 (Event &gt; submit an event) entry <?php echo esc_html( (string) $row['entry_id'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
						<td><?php echo esc_html( law_events_format_pence( 0 ) ); ?></td>
						<td>Unpaid</td>
						<td><strong>Free</strong></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="submit" class="button button-primary">Set <?php echo esc_html( (string) count( $rows ) ); ?> event(s) to Free</button></p>
		</form>
	<?php endif; ?>
	<?php
}
