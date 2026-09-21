<?php
/**
 * Repair: events Stripe was paid for and this site never knew.
 *
 * THE DEFECT. Migration derived every event's payment status from form 2
 * (Event > submit an event) field 95 (Event status), because field 96 (Payment
 * status) was blank on every active entry and no legacy workflow step ever
 * wrote it (EVENTS.md, "Known defects", item 1). Confirmed meant paid,
 * anything else meant unpaid. That was the only inference available, and it is
 * wrong for any event whose host paid without field 95 ever being advanced.
 *
 * WHY IT WAS WRONG FOR SOME. Field 95 only reached "Confirmed" when Gravity
 * Flow step 23 (Set status to Confirmed) ran, and step 23 only ran when step 20
 * (Waiting for payment) was released by the Make scenario calling in on
 * `invoice.paid`. Where that call never arrived, the entry sat at step 20 and
 * the workflow never completed, however long ago the money cleared. On the
 * copy examined on 21 September 2026, all 33 migrated events now reading
 * Approved and unpaid are parked at step 20 with `workflow_final_status`
 * pending, while all 21 reading paid are complete. So the divide between
 * "paid" and "unpaid" in the migrated data records whether MAKE SUCCEEDED, not
 * whether anybody paid, and the only place the truth exists is Stripe.
 *
 * WHAT IT COSTS. Not a wrong label. Paying is what confirms and publishes an
 * event, and law_booking_guard_open() gates booking on publication, so a host
 * who has paid has an event sitting on the programme reading "Open soon" that
 * cannot take a single booking, while the committee's Payment column invites
 * somebody to chase them for the money.
 *
 * WHAT THIS PANEL DOES. For each event it asks Stripe what the invoice behind
 * it actually says, and where Stripe has been paid it records the payment and
 * carries the event through the same confirm transition a live payment would
 * have done -- publishing it, opening booking -- through
 * law_stripe_reconcile_settle(). A repair that corrected the Payment column and
 * left the event unpublished would have fixed the symptom nobody was hurt by.
 *
 * THE EMAILS ARE OFF BY DEFAULT, and that is the difference between this panel
 * and the daily sweep in stripe/reconcile.php. The sweep catches a delivery
 * missed hours ago and should email, because the host is owed that
 * confirmation. These payments are weeks old; "thank you for your payment"
 * arriving now reads as a mistake, and the committee does not need a
 * payment-received alert for money it banked in August. Every suppressed email
 * is still logged on its event, so whoever runs this has the list of hosts to
 * contact by hand.
 *
 * RUN THE INVOICE ID REPAIR FIRST. repair-stripe-invoice-ids.php gives these
 * events their `in_…` ID, which turns every later check into one cheap GET and
 * brings them inside the daily sweep. This panel works without it -- it falls
 * back to the same search-based matching -- but the events recorded as PAID
 * are not examined here at all, and they only come under the sweep's eye once
 * they hold an ID.
 *
 * Dry by default: the scan writes nothing, the check writes nothing, and the
 * apply re-reads Stripe rather than trusting the page it was rendered from.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Events read from Stripe per press of the check button. */
const LAW_RECONCILE_PANEL_BATCH = 10;

/**
 * When this event was last read from Stripe by the panel.
 *
 * Without it the panel cannot advance. A settled event leaves the scan because
 * its payment status changes, but a row that comes back `agreed` -- an open
 * invoice against an unpaid event, which is most of them and is the CORRECT
 * answer -- stays in the scan for ever, so pressing the button again re-read
 * the same first ten and the panel looked stuck. Ordering by this, oldest
 * first, walks the list instead.
 */
const LAW_RECONCILE_CHECKED_META = '_law_reconcile_checked_at';

/**
 * Every event that holds a Stripe invoice and does not believe it was paid.
 *
 * Narrow on purpose. An event already reading paid, refunded or free is not
 * this panel's business (the daily sweep reports those the other way round),
 * and an event still under review has no snapshotted fee and no invoice.
 * Cancelled events ARE included: money that arrived on one is the single most
 * important thing on this screen, and law_stripe_reconcile_check() refuses to
 * settle it automatically for exactly that reason.
 *
 * @return array<int,array<string,mixed>>
 */
function law_events_reconcile_scan() {
	$events = get_posts(
		array(
			'post_type'        => LAW_EVENT_CPT,
			'post_status'      => array( 'law-approved', 'publish', 'law-cancelled' ),
			'numberposts'      => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		)
	);

	$rows = array();
	foreach ( $events as $event ) {
		$payment = (string) law_event_meta( $event->ID, '_law_payment_status' );
		if ( in_array( $payment, array( 'paid', 'refunded', 'free' ), true ) ) {
			continue;
		}
		$invoice_id  = trim( (string) law_event_meta( $event->ID, '_law_stripe_invoice_id' ) );
		$invoice_url = trim( (string) law_event_meta( $event->ID, '_law_stripe_invoice_url' ) );
		if ( '' === $invoice_id && '' === $invoice_url ) {
			continue;
		}
		$rows[] = array(
			'event_id'   => (int) $event->ID,
			'title'      => $event->post_title,
			'status'     => law_event_status_label( $event ),
			'payment'    => $payment,
			'entry_id'   => (int) law_event_meta( $event->ID, '_law_gf_entry_id' ),
			'fee'        => (int) law_event_meta( $event->ID, '_law_fee_pence' ),
			'url'        => $invoice_url,
			'has_id'     => '' !== $invoice_id,
			'checked_at' => (int) get_post_meta( $event->ID, LAW_RECONCILE_CHECKED_META, true ),
		);
	}

	// Never checked first, then oldest check first, so each press of the button
	// takes the next ten rather than the same ten. ID order breaks ties, so the
	// walk is stable within a batch.
	usort(
		$rows,
		fn( $a, $b ) => array( $a['checked_at'], $a['event_id'] ) <=> array( $b['checked_at'], $b['event_id'] )
	);
	return $rows;
}

/**
 * Settle the ticked events.
 *
 * Each one is re-checked against Stripe inside law_stripe_reconcile_settle(),
 * so a row that was paid when the page rendered and has since been voided is
 * refused rather than acted on.
 *
 * @param int[] $ids    Event IDs ticked on the panel.
 * @param bool  $notify Whether to send the confirmation emails.
 * @return array{settled:int,skipped:string[],lines:string[]}
 */
function law_events_reconcile_apply( array $ids, $notify = false ) {
	$allowed = wp_list_pluck( law_events_reconcile_scan(), 'event_id' );
	$settled = 0;
	$skipped = array();
	$lines   = array();
	$actor   = get_current_user_id();

	foreach ( $ids as $id ) {
		$id = (int) $id;
		if ( ! in_array( $id, $allowed, true ) ) {
			$skipped[] = sprintf( '#%d no longer qualifies (it now reads paid, or its invoice has gone).', $id );
			continue;
		}

		$result = law_stripe_reconcile_settle(
			$id,
			array( 'source' => 'reconcile_repair', 'actor' => $actor, 'notify' => (bool) $notify )
		);
		if ( is_wp_error( $result ) ) {
			$skipped[] = sprintf( '#%d %s', $id, $result->get_error_message() );
			continue;
		}

		++$settled;
		$lines[] = sprintf(
			'#%d %s — %s recorded against invoice %s; the event is now %s.',
			$id,
			get_the_title( $id ),
			law_events_format_pence( (int) $result['amount_paid'] ),
			$result['invoice_id'],
			$result['status_label']
		);
	}

	return array( 'settled' => $settled, 'skipped' => $skipped, 'lines' => $lines );
}

/**
 * The LAW → Migration card. Called from law_migration_admin_page().
 *
 * Three states, as the other Stripe panel has: the candidate list, the checked
 * proposals, and the result of an apply. Checking is its own button because
 * each event costs at least one Stripe call and, for a legacy event with no
 * invoice ID, up to three.
 */
function law_events_reconcile_panel() {
	$notice    = '';
	$proposals = array();

	if ( isset( $_POST['law_reconcile_nonce'] ) ) {
		check_admin_referer( 'law_reconcile_payments', 'law_reconcile_nonce' );

		if ( isset( $_POST['law_reconcile_apply'] ) ) {
			$ids    = array_map( 'absint', (array) ( $_POST['law_reconcile_ids'] ?? array() ) );
			$notify = ! empty( $_POST['law_reconcile_notify'] );
			$result = law_events_reconcile_apply( $ids, $notify );
			$notice = sprintf(
				'<div class="notice notice-%s"><p><strong>%d event(s) settled and confirmed.</strong> %s</p>%s%s</div>',
				$result['skipped'] ? 'warning' : 'success',
				$result['settled'],
				$notify
					? 'The host and committee emails were sent.'
					: 'The confirmation emails were suppressed; each event&rsquo;s activity log records which ones, so the hosts can be contacted by hand.',
				$result['lines'] ? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $result['lines'] ) ) . '</li></ul>' : '',
				$result['skipped'] ? '<p><strong>Not settled:</strong></p><ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $result['skipped'] ) ) . '</li></ul>' : ''
			);
		} else {
			foreach ( array_slice( law_events_reconcile_scan(), 0, LAW_RECONCILE_PANEL_BATCH ) as $row ) {
				$proposals[] = $row + array( 'check' => law_stripe_reconcile_check( $row['event_id'] ) );
				// Stamped whatever the verdict was, including `agreed`: the
				// point is to move the queue on, not to record a result.
				update_post_meta( $row['event_id'], LAW_RECONCILE_CHECKED_META, time() );
			}
		}
	}

	$rows = law_events_reconcile_scan();
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html().
	?>
	<h2>Repair: events Stripe was paid for and this site never knew</h2>
	<p class="description" style="max-width:900px">
		Migration had to read payment off form 2 (Event &gt; submit an event) field 95 (Event status), because
		field 96 (Payment status) was blank on every entry. Field 95 only reached &ldquo;Confirmed&rdquo; when the
		retired Make scenario called Gravity Flow step 20 (Waiting for payment) back on <code>invoice.paid</code>,
		so where that call was lost the entry stayed Approved however long ago the host paid. The money is real and
		only Stripe knows about it. This panel asks Stripe what each invoice actually says, and where it has been
		paid it records the payment and confirms the event exactly as a live payment would &mdash; publishing it and
		opening its booking, which is the part that matters. Nothing is written until you press Settle.
	</p>
	<p class="description" style="max-width:900px">
		<strong>Run &ldquo;Repair: legacy Stripe invoices with no invoice ID&rdquo; first.</strong> It gives these
		events their <code>in_…</code> ID, which makes every check here a single cheap lookup and brings them inside
		the daily reconciliation on Events &rarr; Settings afterwards.
	</p>

	<?php if ( '' === law_stripe_secret_key() ) : ?>
		<p><strong>Stripe is not configured on this site</strong> (<code>LAW_STRIPE_SECRET_KEY</code> is not set), so nothing can be checked here.</p>
	<?php endif; ?>

	<?php if ( ! $rows ) : ?>
		<p><strong>Nothing to check.</strong> Every event holding a Stripe invoice already records a payment status of paid, refunded or free.</p>
		<?php
		return;
	endif;
	?>

	<?php if ( $proposals ) : ?>
		<form method="post">
			<?php wp_nonce_field( 'law_reconcile_payments', 'law_reconcile_nonce' ); ?>
			<table class="widefat striped" style="max-width:1100px">
				<thead>
				<tr>
					<th style="width:2em"></th>
					<th>Event</th><th>This site</th><th>Stripe</th><th>Verdict</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $proposals as $row ) : $check = $row['check']; ?>
					<tr>
						<td>
							<?php if ( $check['settleable'] ) : ?>
								<input type="checkbox" name="law_reconcile_ids[]" value="<?php echo esc_attr( (string) $row['event_id'] ); ?>" checked>
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( (string) get_edit_post_link( $row['event_id'] ) ); ?>">#<?php echo esc_html( (string) $row['event_id'] ); ?> <?php echo esc_html( $row['title'] ); ?></a>
							<?php if ( $row['entry_id'] ) : ?>
								<br><span class="description">form 2 (Event &gt; submit an event) entry <?php echo esc_html( (string) $row['entry_id'] ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $row['url'] ) : ?>
								<br><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener" class="description">stored invoice page &#8599;</a>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $check['status_label'] ); ?><br>
							<span class="description"><?php echo esc_html( $check['payment'] ? ucfirst( $check['payment'] ) : 'no payment status' ); ?>,
								fee <?php echo esc_html( law_events_format_pence( $check['fee_pence'] ) ); ?></span>
						</td>
						<td>
							<?php if ( '' !== $check['invoice_id'] ) : ?>
								<code><?php echo esc_html( $check['invoice_id'] ); ?></code><br>
								<span class="description"><?php echo esc_html( sprintf(
									'%s, %s paid of %s',
									$check['invoice_status'] ?: 'status unknown',
									law_events_format_pence( $check['amount_paid'] ),
									law_events_format_pence( $check['invoice_total'] )
								) ); ?></span>
								<br><span class="description">found by <?php echo esc_html( $check['found_by'] ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e">&mdash;</span>
							<?php endif; ?>
							<?php foreach ( $check['notes'] as $note ) : ?>
								<br><span class="description"><?php echo esc_html( $note ); ?></span>
							<?php endforeach; ?>
						</td>
						<td>
							<strong style="color:<?php echo esc_attr( $check['settleable'] ? '#00a32a' : ( 'agreed' === $check['verdict'] ? '#1d2327' : '#b32d2e' ) ); ?>">
								<?php echo esc_html( strtoupper( str_replace( '_', ' ', $check['verdict'] ) ) ); ?>
							</strong>
							<br><span class="description"><?php echo esc_html( $check['detail'] ); ?></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label><input type="checkbox" name="law_reconcile_notify" value="1">
					Send the host and committee their confirmation emails as well
					<span class="description">(off by default: these payments are historic, and &ldquo;thank you for your payment&rdquo; arriving weeks later reads as a mistake. Suppressed emails are still logged on each event.)</span></label>
			</p>
			<p>
				<button type="submit" name="law_reconcile_apply" value="1" class="button button-primary">
					Settle these payments and confirm the events
				</button>
			</p>
		</form>
	<?php else : ?>
		<table class="widefat striped" style="max-width:900px">
			<thead>
			<tr><th>Event</th><th>Status</th><th>Payment</th><th>Host fee</th><th>Invoice</th><th>Last checked</th></tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['event_id'] ) ); ?>">#<?php echo esc_html( (string) $row['event_id'] ); ?> <?php echo esc_html( $row['title'] ); ?></a></td>
					<td><?php echo esc_html( $row['status'] ); ?></td>
					<td><?php echo esc_html( $row['payment'] ? ucfirst( $row['payment'] ) : '—' ); ?></td>
					<td><?php echo esc_html( law_events_format_pence( $row['fee'] ) ); ?></td>
					<td><?php echo esc_html( $row['has_id'] ? 'invoice ID recorded' : 'web address only (legacy)' ); ?></td>
					<td><?php echo $row['checked_at']
						? esc_html( gmdate( 'j M H:i', $row['checked_at'] ) . ' UTC' )
						: '<span class="description">never</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post">
			<?php wp_nonce_field( 'law_reconcile_payments', 'law_reconcile_nonce' ); ?>
			<?php $law_unchecked = count( array_filter( $rows, fn( $r ) => ! $r['checked_at'] ) ); ?>
			<p><button type="submit" class="button" <?php disabled( '' === law_stripe_secret_key() ); ?>>
				Check the next <?php echo esc_html( (string) min( LAW_RECONCILE_PANEL_BATCH, count( $rows ) ) ); ?> against Stripe
			</button>
			<span class="description"><?php echo esc_html( sprintf(
				'%d event(s) in the list, %d never checked. Each press takes the least recently checked, so pressing it repeatedly walks the whole list. Events that agree with Stripe stay listed, because there is nothing to do about them.',
				count( $rows ),
				$law_unchecked
			) ); ?></span></p>
		</form>
	<?php endif; ?>
	<?php
}
