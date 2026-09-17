<?php
/**
 * Backfill the Stripe invoice ID on events invoiced by the retired Make
 * scenario.
 *
 * Form 2 (Event > submit an event) only ever recorded the HOSTED URL of the
 * invoice Make raised: step 19 (Log invoice URL) wrote `hosted_invoice_url`
 * into field 83 (Stripe invoice URL), and nothing anywhere stored the
 * invoice's own `in_...` ID. Migration therefore fills `_law_stripe_invoice_url`
 * and leaves `_law_stripe_invoice_id` empty on every migrated event (33
 * Approved and 21 Confirmed ones on the production copy scanned on
 * 17 September 2026).
 *
 * That empty ID is not a cosmetic gap. Three things key off it:
 *
 * 1. The double-billing guard in law_stripe_invoice_steps() resumes an
 *    existing invoice instead of raising a second one, and it looks the
 *    existing invoice up by ID. On a migrated event it sees nothing, so the
 *    "Create invoice" button on the wp-admin event screen would bill a host
 *    who already has an open invoice. (service.php now refuses that outright
 *    while the URL is present and the ID is not, and the button says why, but
 *    the refusal is a stopgap: the event still cannot be re-invoiced at all
 *    until this panel has run.)
 * 2. law_stripe_void_invoice() cancels nothing, so cancelling a migrated
 *    Approved event leaves its invoice open and payable while the host is
 *    emailed to say no payment is due.
 * 3. The Stripe re-stamp in repair-references.php only visits events that
 *    have a stored customer or invoice ID, so migrated invoices keep quoting
 *    the pre-14-September references in the Stripe dashboard.
 *
 * The payment path itself is NOT affected and never was: an invoice raised by
 * Make carries `metadata[gf_entry_id]`, and law_stripe_resolve_event_id()
 * resolves an incoming `invoice.paid` through that. A host paying a legacy
 * invoice after cutover is confirmed and published correctly with or without
 * this repair.
 *
 * How the lookup works. Two routes, both ending in the same match test, so a
 * write only ever happens on evidence tying the invoice to this event:
 *
 * - Stripe's invoice search, `metadata["gf_entry_id"]:"<entry>"`, which is the
 *   key Make stamped on every invoice it created; and
 * - failing that (search is not available on every account, and it is
 *   eventually consistent), the customer route: find the customer by field 73
 *   (Invoice contact email), list their invoices and look through them.
 *
 * A candidate is accepted only when its `hosted_invoice_url` is exactly the
 * URL the event already holds, or its `metadata[gf_entry_id]` is exactly this
 * event's source entry. Anything ambiguous (several matches, or an invoice
 * another event has already claimed) is reported and left alone rather than
 * guessed at. Dry by default: the scan and the lookup write nothing, the
 * proposals are shown for eyeballing, and the apply re-runs the lookup rather
 * than trusting what the page was rendered with.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Events looked up per press of the button (see law_events_invoice_id_panel()). */
const LAW_INVOICE_ID_LOOKUP_BATCH = 10;

/**
 * Every event holding a legacy invoice URL with no invoice ID beside it.
 *
 * Any status: a Confirmed (paid) event needs the ID as much as an Approved one
 * does, because it is what a refund, a reconciliation or the reference
 * re-stamp reads. Trashed events are included for the same reason
 * repair-owners.php includes them -- a restored event should come back whole.
 *
 * @return array<int,array<string,mixed>>
 */
function law_events_invoice_id_scan() {
	$events = get_posts(
		array(
			'post_type'        => LAW_EVENT_CPT,
			'post_status'      => array_keys( get_post_stati() ),
			'numberposts'      => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array( 'key' => '_law_stripe_invoice_url', 'value' => '', 'compare' => '!=' ),
			),
		)
	);

	$rows = array();
	foreach ( $events as $event ) {
		if ( '' !== trim( (string) law_event_meta( $event->ID, '_law_stripe_invoice_id' ) ) ) {
			continue;
		}
		$url = trim( (string) law_event_meta( $event->ID, '_law_stripe_invoice_url' ) );
		if ( '' === $url ) {
			continue;
		}
		$rows[] = array(
			'event_id' => (int) $event->ID,
			'title'    => $event->post_title,
			'status'   => law_event_status_label( $event ),
			'payment'  => (string) law_event_meta( $event->ID, '_law_payment_status' ),
			'entry_id' => (int) law_event_meta( $event->ID, '_law_gf_entry_id' ),
			'email'    => (string) law_event_meta( $event->ID, '_law_invoice_email' ),
			'fee'      => (int) law_event_meta( $event->ID, '_law_fee_pence' ),
			'url'      => $url,
		);
	}
	return $rows;
}

/**
 * Whether a Stripe invoice is provably this event's.
 *
 * The URL is the stronger signal of the two -- it is the exact string the
 * legacy workflow logged against this entry -- so it is reported separately
 * and wins when both routes disagree.
 *
 * @param array  $invoice  Stripe invoice object.
 * @param int    $entry_id Source form 2 (Event > submit an event) entry ID.
 * @param string $url      Hosted invoice URL held on the event.
 * @return string '' | 'url' | 'metadata'
 */
function law_events_invoice_id_match( array $invoice, $entry_id, $url ) {
	$hosted = (string) ( $invoice['hosted_invoice_url'] ?? '' );
	if ( '' !== $url && '' !== $hosted && $hosted === $url ) {
		return 'url';
	}
	$stamped = (int) ( $invoice['metadata']['gf_entry_id'] ?? 0 );
	if ( $entry_id > 0 && $stamped === $entry_id ) {
		return 'metadata';
	}
	return '';
}

/**
 * Ask Stripe which invoice this event's URL belongs to.
 *
 * Read-only: every call here is a GET. Returns what it found and why, so the
 * panel can show the reasoning and the apply can refuse anything unclear.
 *
 * @param int $event_id law_event post ID.
 * @return array{invoice_id:string,customer_id:string,status:string,total:int,
 *               matched_on:string,error:string,notes:string[],ambiguous:array[]}
 */
function law_events_invoice_id_lookup( $event_id ) {
	$event_id = (int) $event_id;
	$entry_id = (int) law_event_meta( $event_id, '_law_gf_entry_id' );
	$url      = trim( (string) law_event_meta( $event_id, '_law_stripe_invoice_url' ) );
	$email    = mb_strtolower( trim( (string) law_event_meta( $event_id, '_law_invoice_email' ) ) );

	$out = array(
		'invoice_id'  => '',
		'customer_id' => '',
		'status'      => '',
		'total'       => 0,
		'matched_on'  => '',
		'error'       => '',
		'notes'       => array(),
		'ambiguous'   => array(),
	);

	if ( '' === law_stripe_secret_key() ) {
		$out['error'] = 'Stripe is not configured on this site (LAW_STRIPE_SECRET_KEY is not set), so no lookup is possible here.';
		return $out;
	}

	$candidates = array();

	// Route 1: the metadata Make stamped on every invoice it raised.
	if ( $entry_id > 0 ) {
		$search = law_stripe_request(
			'GET',
			'/v1/invoices/search',
			array(
				'query' => sprintf( 'metadata["gf_entry_id"]:"%d"', $entry_id ),
				'limit' => 20,
			)
		);
		if ( is_wp_error( $search ) ) {
			// Not fatal: an account without search, or a transient failure,
			// simply falls through to the customer route.
			$out['notes'][] = 'Invoice search unavailable (' . $search->get_error_message() . '); fell back to the customer\'s invoice list.';
		} else {
			foreach ( (array) ( $search['data'] ?? array() ) as $invoice ) {
				if ( ! empty( $invoice['id'] ) ) {
					$candidates[ (string) $invoice['id'] ] = (array) $invoice;
				}
			}
		}
	}

	// Route 2: the customer behind field 73 (Invoice contact email), and every
	// invoice on them. Run whenever route 1 produced no usable match, not only
	// when it produced nothing, so a search hit that fails the match test still
	// gets a second opinion.
	$matched_yet = array_filter(
		$candidates,
		fn( $invoice ) => '' !== law_events_invoice_id_match( $invoice, $entry_id, $url )
	);
	if ( ! $matched_yet && '' !== $email ) {
		$customers = law_stripe_request( 'GET', '/v1/customers', array( 'email' => $email, 'limit' => 10 ) );
		if ( is_wp_error( $customers ) ) {
			$out['notes'][] = 'Customer lookup failed: ' . $customers->get_error_message();
		} else {
			foreach ( (array) ( $customers['data'] ?? array() ) as $customer ) {
				$customer_id = (string) ( $customer['id'] ?? '' );
				if ( '' === $customer_id ) {
					continue;
				}
				$invoices = law_stripe_request( 'GET', '/v1/invoices', array( 'customer' => $customer_id, 'limit' => 100 ) );
				if ( is_wp_error( $invoices ) ) {
					$out['notes'][] = sprintf( 'Invoice list for customer %s failed: %s', $customer_id, $invoices->get_error_message() );
					continue;
				}
				foreach ( (array) ( $invoices['data'] ?? array() ) as $invoice ) {
					if ( ! empty( $invoice['id'] ) ) {
						$candidates[ (string) $invoice['id'] ] = (array) $invoice;
					}
				}
				// Unpaged, deliberately: a host with more than 100 invoices is
				// not a case this repair should guess its way through. Say so
				// rather than quietly searching only the newest hundred.
				if ( ! empty( $invoices['has_more'] ) ) {
					$out['notes'][] = sprintf( 'Customer %s has more than 100 invoices; only the most recent 100 were read.', $customer_id );
				}
			}
		}
	}

	if ( ! $candidates ) {
		$out['error'] = 'No invoice found in Stripe for this event. Open the stored URL and read the invoice ID off the dashboard, or set it by hand.';
		return $out;
	}

	$by_url  = array();
	$by_meta = array();
	foreach ( $candidates as $id => $invoice ) {
		$match = law_events_invoice_id_match( $invoice, $entry_id, $url );
		if ( 'url' === $match ) {
			$by_url[ $id ] = $invoice;
		} elseif ( 'metadata' === $match ) {
			$by_meta[ $id ] = $invoice;
		}
	}

	$matched    = $by_url ?: $by_meta;
	$matched_on = $by_url ? 'hosted invoice URL' : 'gf_entry_id metadata';

	if ( ! $matched ) {
		$out['error'] = sprintf(
			'%d invoice(s) found for this contact, none of them matching this event\'s stored URL or entry ID.',
			count( $candidates )
		);
		return $out;
	}

	if ( count( $matched ) > 1 ) {
		foreach ( $matched as $id => $invoice ) {
			$out['ambiguous'][] = sprintf(
				'%s (%s, %s)',
				$id,
				(string) ( $invoice['status'] ?? '?' ),
				law_events_format_pence( (int) ( $invoice['total'] ?? 0 ) )
			);
		}
		$out['error'] = 'More than one invoice matches, so nothing is written: pick the right one in the Stripe dashboard and set it by hand.';
		return $out;
	}

	$invoice           = reset( $matched );
	$out['invoice_id'] = (string) $invoice['id'];
	$out['customer_id'] = is_array( $invoice['customer'] ?? null )
		? (string) ( $invoice['customer']['id'] ?? '' )
		: (string) ( $invoice['customer'] ?? '' );
	$out['status']     = (string) ( $invoice['status'] ?? '' );
	$out['total']      = (int) ( $invoice['total'] ?? 0 );
	$out['matched_on'] = $matched_on;

	// An invoice belongs to exactly one event. If another post already holds
	// this ID, something is wrong with the pairing and a write would corrupt
	// both records.
	$claimed = law_events_invoice_id_claimed_by( $out['invoice_id'], $event_id );
	if ( $claimed ) {
		$out['error'] = sprintf(
			'Invoice %s is already recorded against event #%d (%s), so it is not written here.',
			$out['invoice_id'],
			$claimed,
			get_the_title( $claimed )
		);
		$out['invoice_id'] = '';
	}

	return $out;
}

/**
 * Another event already holding this Stripe invoice ID, or 0.
 *
 * @param string $invoice_id Stripe invoice ID.
 * @param int    $except     Event being repaired.
 * @return int
 */
function law_events_invoice_id_claimed_by( $invoice_id, $except = 0 ) {
	$invoice_id = trim( (string) $invoice_id );
	if ( '' === $invoice_id ) {
		return 0;
	}
	$found = get_posts(
		array(
			'post_type'        => LAW_EVENT_CPT,
			'post_status'      => array_keys( get_post_stati() ),
			'numberposts'      => 2,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_key'         => '_law_stripe_invoice_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'       => $invoice_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	foreach ( $found as $id ) {
		if ( (int) $id !== (int) $except ) {
			return (int) $id;
		}
	}
	return 0;
}

/**
 * Write the invoice (and customer) IDs for the chosen events.
 *
 * The lookup is re-run here rather than trusting the rendered page: the panel
 * may have been open while somebody raised a new invoice or voided the old one.
 *
 * @param int[] $ids Event IDs ticked on the panel.
 * @return array{repaired:int,skipped:string[],lines:string[]}
 */
function law_events_invoice_id_apply( array $ids ) {
	$allowed  = wp_list_pluck( law_events_invoice_id_scan(), 'event_id' );
	$repaired = 0;
	$skipped  = array();
	$lines    = array();

	foreach ( $ids as $id ) {
		$id = (int) $id;
		if ( ! in_array( $id, $allowed, true ) ) {
			$skipped[] = sprintf( '#%d no longer qualifies (it now holds an invoice ID, or its URL has gone).', $id );
			continue;
		}

		$look = law_events_invoice_id_lookup( $id );
		if ( '' === $look['invoice_id'] ) {
			$skipped[] = sprintf( '#%d %s', $id, $look['error'] ?: 'no invoice matched.' );
			continue;
		}

		law_event_update_meta( $id, '_law_stripe_invoice_id', $look['invoice_id'] );

		// The customer ID was never recorded either (the legacy workflow logged
		// only the URL), and the invoice names it, so take it while we are here
		// -- but never overwrite one the module has since written itself.
		$customer_note = '';
		if ( '' !== $look['customer_id'] && '' === trim( (string) law_event_meta( $id, '_law_stripe_customer_id' ) ) ) {
			law_event_update_meta( $id, '_law_stripe_customer_id', $look['customer_id'] );
			$customer_note = sprintf( ' Customer %s recorded with it.', $look['customer_id'] );
		}

		law_event_log(
			$id,
			sprintf(
				'Data repair: Stripe invoice %s (%s, %s) recorded against this event, matched on its %s.%s The invoice was raised by the retired Make scenario, which logged only the hosted URL into form 2 (Event > submit an event) field 83 (Stripe invoice URL), so the ID was missing. Nothing about the money changed; the ID is what lets the module resume this invoice instead of raising a second one, and void it if the event is cancelled.',
				$look['invoice_id'],
				$look['status'] ?: 'status unknown',
				law_events_format_pence( $look['total'] ),
				$look['matched_on'],
				$customer_note
			),
			array(
				'action'      => 'invoice_id_repair',
				'source'      => 'repair',
				'invoice_id'  => $look['invoice_id'],
				'customer_id' => $look['customer_id'],
				'matched_on'  => $look['matched_on'],
				'status'      => $look['status'],
			)
		);

		++$repaired;
		$lines[] = sprintf(
			'#%d %s — %s (%s, matched on %s)',
			$id,
			get_the_title( $id ),
			$look['invoice_id'],
			$look['status'] ?: '?',
			$look['matched_on']
		);
	}

	return array( 'repaired' => $repaired, 'skipped' => $skipped, 'lines' => $lines );
}

/**
 * The LAW → Migration card. Called from law_migration_admin_page().
 *
 * Three states: the candidate list, the looked-up proposals, and the result of
 * an apply. The lookup is its own button because it costs a handful of Stripe
 * calls per event, which has no business running every time somebody opens the
 * Migration screen.
 */
function law_events_invoice_id_panel() {
	$notice    = '';
	$proposals = array();

	if ( isset( $_POST['law_invoice_id_nonce'] ) ) {
		check_admin_referer( 'law_invoice_id_repair', 'law_invoice_id_nonce' );

		if ( isset( $_POST['law_invoice_id_apply'] ) ) {
			$ids    = array_map( 'absint', (array) ( $_POST['law_invoice_id_ids'] ?? array() ) );
			$result = law_events_invoice_id_apply( $ids );
			$notice = sprintf(
				'<div class="notice notice-%s"><p><strong>%d event(s) now hold their Stripe invoice ID.</strong></p>%s%s</div>',
				$result['skipped'] ? 'warning' : 'success',
				$result['repaired'],
				$result['lines'] ? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $result['lines'] ) ) . '</li></ul>' : '',
				$result['skipped'] ? '<p><strong>Not written:</strong></p><ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $result['skipped'] ) ) . '</li></ul>' : ''
			);
		} else {
			// A batch at a time, not all 54 at once: each event costs one to
			// three Stripe round trips, and a screen that spends two minutes in
			// wp_remote_request is a screen that dies on somebody's execution
			// limit halfway through. Repaired events drop out of the scan, so
			// pressing the button again simply takes the next batch.
			foreach ( array_slice( law_events_invoice_id_scan(), 0, LAW_INVOICE_ID_LOOKUP_BATCH ) as $row ) {
				$proposals[] = $row + array( 'look' => law_events_invoice_id_lookup( $row['event_id'] ) );
			}
		}
	}

	$rows = law_events_invoice_id_scan();
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html().
	?>
	<h2>Repair: legacy Stripe invoices with no invoice ID</h2>
	<p class="description" style="max-width:900px">
		These events were invoiced by the retired Make scenario, which logged only the invoice's web address into
		form 2 (Event &gt; submit an event) field 83 (Stripe invoice URL) and never its <code>in_…</code> ID. Payment
		still works without it &mdash; an incoming <code>invoice.paid</code> finds its event through the
		<code>gf_entry_id</code> stamped on the invoice &mdash; but the ID is what stops the module raising a
		<em>second</em> invoice for a host who already has one, and what lets it void the invoice if the event is
		cancelled. This panel asks Stripe which invoice each URL belongs to and records the answer. It writes only
		when the invoice proves it is this event's, by its address matching exactly or by carrying this event's
		entry ID, and it writes nothing at all until you press Apply.
	</p>

	<?php if ( '' === law_stripe_secret_key() ) : ?>
		<p><strong>Stripe is not configured on this site</strong> (<code>LAW_STRIPE_SECRET_KEY</code> is not set in wp-config), so the lookup cannot run here.</p>
	<?php endif; ?>

	<?php if ( ! $rows ) : ?>
		<p><strong>Nothing to repair.</strong> Every event holding a Stripe invoice URL also holds its invoice ID.</p>
		<?php
		return;
	endif;
	?>

	<?php if ( $proposals ) : ?>
		<form method="post">
			<?php wp_nonce_field( 'law_invoice_id_repair', 'law_invoice_id_nonce' ); ?>
			<table class="widefat striped" style="max-width:1100px">
				<thead>
				<tr>
					<th style="width:2em"><input type="checkbox" checked disabled></th>
					<th>Event</th><th>Status</th><th>Invoice found</th><th>Matched on</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $proposals as $row ) : $look = $row['look']; ?>
					<tr>
						<td>
							<?php if ( '' !== $look['invoice_id'] ) : ?>
								<input type="checkbox" name="law_invoice_id_ids[]" value="<?php echo esc_attr( (string) $row['event_id'] ); ?>" checked>
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( (string) get_edit_post_link( $row['event_id'] ) ); ?>">#<?php echo esc_html( (string) $row['event_id'] ); ?> <?php echo esc_html( $row['title'] ); ?></a>
							<?php if ( $row['entry_id'] ) : ?>
								<br><span class="description">form 2 (Event &gt; submit an event) entry <?php echo esc_html( (string) $row['entry_id'] ); ?></span>
							<?php endif; ?>
							<br><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener" class="description">stored invoice page ↗</a>
						</td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
						<td>
							<?php if ( '' !== $look['invoice_id'] ) : ?>
								<code><?php echo esc_html( $look['invoice_id'] ); ?></code>
								<br><span class="description"><?php echo esc_html( sprintf( '%s, %s', $look['status'] ?: 'status unknown', law_events_format_pence( $look['total'] ) ) ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e"><?php echo esc_html( $look['error'] ); ?></span>
								<?php if ( $look['ambiguous'] ) : ?>
									<br><span class="description"><?php echo esc_html( implode( ' · ', $look['ambiguous'] ) ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
							<?php foreach ( $look['notes'] as $note ) : ?>
								<br><span class="description"><?php echo esc_html( $note ); ?></span>
							<?php endforeach; ?>
						</td>
						<td><?php echo esc_html( $look['matched_on'] ?: '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<button type="submit" name="law_invoice_id_apply" value="1" class="button button-primary">
					Record the matched invoice IDs
				</button>
			</p>
		</form>
	<?php else : ?>
		<table class="widefat striped" style="max-width:900px">
			<thead>
			<tr><th>Event</th><th>Status</th><th>Payment</th><th>Host fee</th><th>Invoice contact</th></tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( (string) get_edit_post_link( $row['event_id'] ) ); ?>">#<?php echo esc_html( (string) $row['event_id'] ); ?> <?php echo esc_html( $row['title'] ); ?></a>
						<?php if ( $row['entry_id'] ) : ?>
							<br><span class="description">form 2 (Event &gt; submit an event) entry <?php echo esc_html( (string) $row['entry_id'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $row['status'] ); ?></td>
					<td><?php echo esc_html( ucfirst( $row['payment'] ?: '—' ) ); ?></td>
					<td><?php echo esc_html( law_events_format_pence( $row['fee'] ) ); ?></td>
					<td><?php echo esc_html( $row['email'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post">
			<?php wp_nonce_field( 'law_invoice_id_repair', 'law_invoice_id_nonce' ); ?>
			<p>
				<button type="submit" class="button" <?php disabled( '' === law_stripe_secret_key() ); ?>>
					Look <?php echo esc_html( (string) min( count( $rows ), LAW_INVOICE_ID_LOOKUP_BATCH ) ); ?> invoice(s) up in Stripe
				</button>
				<span class="description">
					Read-only: this asks Stripe which invoice each URL belongs to and shows the answer. Nothing is written until you confirm.
					<?php if ( count( $rows ) > LAW_INVOICE_ID_LOOKUP_BATCH ) : ?>
						<?php echo esc_html( sprintf( 'Done in batches of %d, so the screen never sits waiting on Stripe; repeat until the list is empty.', LAW_INVOICE_ID_LOOKUP_BATCH ) ); ?>
					<?php endif; ?>
				</span>
			</p>
		</form>
	<?php endif; ?>
	<?php
}
