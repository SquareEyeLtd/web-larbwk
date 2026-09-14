<?php
/**
 * Reassign event references to the Gravity Forms entry IDs.
 *
 * The legacy site minted references with GP Unique ID: field 70 (Unique ID) on
 * form 2 (Event > submit an event), format LAW26-00121. Migration carried that
 * value across verbatim, so the events migrated before 14 September 2026 still
 * hold it. On that date the client settled on the Gravity Forms ENTRY ID
 * instead, because that is the number the committee sees in the entries list
 * and quotes to each other (the event titled "Coming Soon to an Arbitration
 * Near You…" is entry 190 there, and was LAW26-00121 here).
 *
 * So from now on:
 *
 * - a MIGRATED event's reference is its Gravity Forms entry ID, which is
 *   already stored on the post as `_law_gf_entry_id` by
 *   law_migration_populate_event(); and
 * - a NEW event's reference is its own law_event post ID
 *   (law_events_event_reference(), written on the first save).
 *
 * This panel brings the events migrated under the old rule into line. It is
 * the entry link that decides, never a title match: each proposed ID is looked
 * up in Gravity Forms and its own Event title shown beside the post's, so the
 * pairing can be checked by eye before anything is written. Dry by default:
 * the scan writes nothing until "Apply" is pressed, it is safe to re-run, and
 * every change lands in the event's activity log.
 *
 * It also re-stamps Stripe. Every customer and invoice this module (and the
 * retired Make scenario before it) raised carries a `law_reference` metadata
 * key, written at creation and never updated since. Nothing in the code reads
 * it — a webhook resolves its event through `law_event_id`, falling back to
 * `gf_entry_id` (law_stripe_resolve_event_id()) — so the payment path is not
 * at risk either way. But whoever looks a payment up in the Stripe dashboard
 * reads that key, and an invoice saying LAW26-00121 against a site saying 190
 * is exactly the sort of drift that wastes an afternoon during a reconciliation.
 * So the apply patches the metadata on the event's stored customer and invoice
 * as well. Stripe allows that on a finalised or paid invoice: only monetary
 * values and `collection_method` become uneditable at finalisation, and a
 * metadata write merges, so keys Make left behind survive.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The reference an event should hold under the 14 September 2026 rule.
 *
 * @param int $event_id law_event post ID.
 * @return array{reference:string,basis:string,entry_id:int}
 */
function law_events_reference_expected( $event_id ) {
	$event_id = (int) $event_id;
	$entry_id = (int) law_event_meta( $event_id, '_law_gf_entry_id' );

	if ( $entry_id > 0 ) {
		return array(
			'reference' => (string) $entry_id,
			'basis'     => 'gf_entry',
			'entry_id'  => $entry_id,
		);
	}

	return array(
		'reference' => law_events_event_reference( $event_id ),
		'basis'     => 'post_id',
		'entry_id'  => 0,
	);
}

/**
 * The Gravity Forms entry behind a migrated event, for verification.
 *
 * @param int $entry_id Legacy entry ID on form 2 (Event > submit an event).
 * @return array{found:bool,title:string,status:string,reference:string}
 */
function law_events_reference_entry_check( $entry_id ) {
	$entry_id = (int) $entry_id;
	$missing  = array( 'found' => false, 'title' => '', 'status' => '', 'reference' => '' );

	if ( $entry_id < 1 || ! class_exists( 'GFAPI' ) ) {
		return $missing;
	}

	$entry = GFAPI::get_entry( $entry_id );
	if ( ! is_array( $entry ) || is_wp_error( $entry ) ) {
		return $missing;
	}

	return array(
		'found'     => true,
		// Field 17 (Event title) and field 70 (Unique ID) on form 2
		// (Event > submit an event).
		'title'     => (string) rgar( $entry, '17' ),
		'status'    => (string) ( $entry['status'] ?? '' ),
		'reference' => (string) rgar( $entry, '70' ),
	);
}

/**
 * Every event, sorted into what the reassignment would change, what it must
 * not touch, and what is already right.
 *
 * Trashed events are included, exactly as repair-owners.php reasons about
 * ownership: a trashed event that is later restored should come back with the
 * reference everybody else is quoting.
 *
 * A reference is meant to identify one event, so the scan refuses to create a
 * duplicate: if two events would end up holding the same number (or one would
 * take a number another event already holds), both land in `conflicts` and
 * neither is offered for change. That cannot happen with today's data — the
 * entry IDs in use run to 1,171 and new post IDs are past 260,000 — but the
 * check costs nothing and a hand-made event could sit anywhere.
 *
 * @return array{fix:array[],conflicts:array[],ok:int,total:int}
 */
function law_events_reference_scan() {
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

	$rows  = array();
	$holds = array(); // reference => event IDs that would hold it afterwards.

	foreach ( $events as $event ) {
		$expected = law_events_reference_expected( $event->ID );
		$row      = array(
			'event_id'      => (int) $event->ID,
			'title'         => $event->post_title,
			'status'        => law_event_status_label( $event ),
			'reference_now' => (string) law_event_meta( $event->ID, '_law_reference' ),
			'reference_new' => $expected['reference'],
			'basis'         => $expected['basis'],
			'entry_id'      => $expected['entry_id'],
			'entry'         => law_events_reference_entry_check( $expected['entry_id'] ),
		);

		$holds[ $row['reference_new'] ][] = $row['event_id'];
		$rows[]                           = $row;
	}

	$fix       = array();
	$conflicts = array();
	$ok        = 0;

	foreach ( $rows as $row ) {
		$clash = $holds[ $row['reference_new'] ] ?? array();
		if ( count( $clash ) > 1 ) {
			$row['reason'] = sprintf(
				'Reference %s would be shared with event(s) %s. Left alone: a reference identifies one event.',
				$row['reference_new'],
				implode( ', ', array_map( static fn( $id ) => '#' . $id, array_diff( $clash, array( $row['event_id'] ) ) ) )
			);
			$conflicts[] = $row;
			continue;
		}

		if ( $row['reference_now'] === $row['reference_new'] ) {
			$ok++;
			continue;
		}

		// A migrated event whose entry has vanished from Gravity Forms: the
		// stored ID is still the right answer (it is what the redirect map and
		// the Stripe metadata use), but say so rather than pretend it was
		// verified.
		$fix[] = $row;
	}

	return array(
		'fix'       => $fix,
		'conflicts' => $conflicts,
		'ok'        => $ok,
		'total'     => count( $rows ),
	);
}

/**
 * The Stripe objects whose metadata still quotes an event's old reference.
 *
 * @param int $event_id law_event post ID.
 * @return array{customer:string,invoice:string}
 */
function law_events_reference_stripe_objects( $event_id ) {
	return array(
		'customer' => (string) law_event_meta( $event_id, '_law_stripe_customer_id' ),
		'invoice'  => (string) law_event_meta( $event_id, '_law_stripe_invoice_id' ),
	);
}

/**
 * Every event that has a Stripe customer or invoice, whatever its reference.
 *
 * The re-stamp has to stay reachable after the references are already right:
 * the reassignment was applied on the local site on 14 September 2026 before
 * the Stripe patch existed, which left the invoices there quoting references
 * the site no longer uses and no row in the table above to fix them from. The
 * patch is idempotent, so re-stamping an object that is already correct costs
 * one API call and changes nothing.
 *
 * @return array[] event_id, title, reference, customer, invoice.
 */
function law_events_reference_stripe_rows() {
	$events = get_posts(
		array(
			'post_type'        => LAW_EVENT_CPT,
			'post_status'      => array_keys( get_post_stati() ),
			'numberposts'      => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
			'meta_query'       => array(
				'relation' => 'OR',
				array( 'key' => '_law_stripe_customer_id', 'value' => '', 'compare' => '!=' ),
				array( 'key' => '_law_stripe_invoice_id', 'value' => '', 'compare' => '!=' ),
			),
		)
	);

	$rows = array();
	foreach ( $events as $event ) {
		$objects = law_events_reference_stripe_objects( $event->ID );
		if ( ! array_filter( $objects ) ) {
			continue;
		}
		$rows[] = array(
			'event_id'  => (int) $event->ID,
			'title'     => $event->post_title,
			'status'    => law_event_status_label( $event ),
			'reference' => (string) law_event_meta( $event->ID, '_law_reference' ),
			'customer'  => $objects['customer'],
			'invoice'   => $objects['invoice'],
		);
	}
	return $rows;
}

/**
 * Re-stamp the Stripe metadata on the named events (or every one that has an
 * object), independently of any reference change.
 *
 * @param int[] $event_ids Subset; empty means every event with a Stripe object.
 * @return array{patched:int,lines:string[],failed:string[]}
 */
function law_events_reference_stripe_restamp( array $event_ids = array() ) {
	$wanted  = array_map( 'absint', $event_ids );
	$patched = 0;
	$lines   = array();
	$failed  = array();

	foreach ( law_events_reference_stripe_rows() as $row ) {
		if ( $wanted && ! in_array( $row['event_id'], $wanted, true ) ) {
			continue;
		}
		$sync     = law_events_reference_sync_stripe( $row['event_id'] );
		$patched += count( $sync['patched'] );
		foreach ( $sync['failed'] as $kind => $message ) {
			$failed[] = sprintf( '#%d %s %s', $row['event_id'], $kind, $message );
		}
		if ( $sync['patched'] ) {
			$lines[] = sprintf(
				'#%d %s — %s stamped with reference %s',
				$row['event_id'],
				$row['title'],
				implode( ' and ', $sync['patched'] ),
				$row['reference'] ?: '(none)'
			);
		}
	}

	return array( 'patched' => $patched, 'lines' => $lines, 'failed' => $failed );
}

/**
 * Re-stamp this event's Stripe customer and invoice with its current metadata,
 * so the dashboard's `law_reference` agrees with the site's.
 *
 * A metadata-only patch, which Stripe permits on a finalised or paid invoice,
 * and a merge, so the retired Make scenario's own keys are left alone. Sending
 * the whole block re-asserts `law_event_id` and `gf_entry_id` too, which is
 * how an invoice raised by Make picks up the identifiers our webhook prefers.
 *
 * Never fatal to the reassignment: the reference is the local record and must
 * be written whatever Stripe says. A failure (no key configured locally, a
 * deleted object, a network error) is logged on the event and reported in the
 * panel notice.
 *
 * @param int $event_id law_event post ID.
 * @return array{patched:string[],failed:array<string,string>}
 */
function law_events_reference_sync_stripe( $event_id ) {
	$objects  = law_events_reference_stripe_objects( $event_id );
	$metadata = law_stripe_event_metadata( $event_id );
	$patched  = array();
	$failed   = array();

	$paths = array(
		'customer' => '/v1/customers/',
		'invoice'  => '/v1/invoices/',
	);

	foreach ( $paths as $kind => $prefix ) {
		$id = $objects[ $kind ];
		if ( '' === $id ) {
			continue;
		}

		$result = law_stripe_request( 'POST', $prefix . rawurlencode( $id ), array( 'metadata' => $metadata ) );
		if ( is_wp_error( $result ) ) {
			$failed[ $kind ] = $id . ': ' . $result->get_error_message();
			law_event_log(
				$event_id,
				sprintf(
					'Stripe %s %s could NOT be re-stamped with the new reference (%s): %s. Its metadata still quotes the old reference; patch it by hand in the Stripe dashboard or re-run the reassignment panel.',
					$kind,
					$id,
					$metadata['law_reference'] ?? '',
					$result->get_error_message()
				),
				array(
					'action'      => 'reference_stripe_sync',
					'source'      => 'repair',
					'object'      => $kind,
					'object_id'   => $id,
					'stripe_error' => $result->get_error_message(),
				)
			);
			continue;
		}

		$patched[] = $kind;
		law_event_log(
			$event_id,
			sprintf(
				'Stripe %s %s re-stamped with the new reference: law_reference %s, law_event_id %s, gf_entry_id %s. A metadata-only patch; nothing about the money changed.',
				$kind,
				$id,
				$metadata['law_reference'] ?? '(none)',
				$metadata['law_event_id'] ?? '(none)',
				$metadata['gf_entry_id'] ?? '(none)'
			),
			array(
				'action'    => 'reference_stripe_sync',
				'source'    => 'repair',
				'object'    => $kind,
				'object_id' => $id,
				'metadata'  => $metadata,
			)
		);
	}

	return array( 'patched' => $patched, 'failed' => $failed );
}

/**
 * Write the new references.
 *
 * @param int[] $event_ids    Subset to apply to; empty means every event the scan offers.
 * @param bool  $sync_stripe  Also re-stamp each event's Stripe customer and invoice metadata.
 * @return array{applied:int,lines:string[],stripe:int,stripe_failed:string[]}
 */
function law_events_reference_apply( array $event_ids = array(), $sync_stripe = true ) {
	$scan          = law_events_reference_scan();
	$wanted        = array_map( 'absint', $event_ids );
	$applied       = 0;
	$lines         = array();
	$stripe        = 0;
	$stripe_failed = array();

	foreach ( $scan['fix'] as $row ) {
		if ( $wanted && ! in_array( $row['event_id'], $wanted, true ) ) {
			continue;
		}

		law_event_update_meta( $row['event_id'], '_law_reference', $row['reference_new'] );

		law_event_log(
			$row['event_id'],
			sprintf(
				'Reference changed from %s to %s (%s). The references are now the Gravity Forms entry IDs for migrated events and the event ID for new ones (client decision, 14 September 2026).',
				$row['reference_now'] ?: '(none)',
				$row['reference_new'],
				'gf_entry' === $row['basis']
					? 'the Gravity Forms entry this event was migrated from, on form 2 (Event > submit an event)'
					: 'this event was not migrated, so it takes its own event ID'
			),
			array(
				'action'        => 'reference_repair',
				'source'        => 'repair',
				'reference_old' => $row['reference_now'],
				'reference_new' => $row['reference_new'],
				'basis'         => $row['basis'],
				'gf_entry_id'   => $row['entry_id'],
			)
		);

		$applied++;
		$note = '';
		if ( $sync_stripe && array_filter( law_events_reference_stripe_objects( $row['event_id'] ) ) ) {
			$sync   = law_events_reference_sync_stripe( $row['event_id'] );
			$stripe += count( $sync['patched'] );
			foreach ( $sync['failed'] as $kind => $message ) {
				$stripe_failed[] = sprintf( '#%d %s %s', $row['event_id'], $kind, $message );
			}
			$note = $sync['patched'] ? sprintf( ' (Stripe %s re-stamped)', implode( ' and ', $sync['patched'] ) ) : ' (Stripe patch FAILED, see the log)';
		}

		$lines[] = sprintf(
			'#%d %s — %s → %s%s',
			$row['event_id'],
			$row['title'],
			$row['reference_now'] ?: '(none)',
			$row['reference_new'],
			$note
		);
	}

	return array(
		'applied'       => $applied,
		'lines'         => $lines,
		'stripe'        => $stripe,
		'stripe_failed' => $stripe_failed,
	);
}

/** The panel on the migration screen (functions/events/migration/page.php). */
function law_events_reference_panel() {
	$notice = '';
	if ( isset( $_POST['law_reference_repair_nonce'] ) ) {
		check_admin_referer( 'law_reference_repair', 'law_reference_repair_nonce' );
		$result = law_events_reference_apply(
			array_map( 'absint', (array) ( $_POST['law_reference_repair_ids'] ?? array() ) ),
			! empty( $_POST['law_reference_repair_stripe'] )
		);
		$notice = sprintf(
			'<div class="notice notice-%s"><p><strong>%d reference(s) reassigned.</strong> %d Stripe object(s) re-stamped.%s</p>%s</div>',
			$result['stripe_failed'] ? 'warning' : 'success',
			$result['applied'],
			$result['stripe'],
			$result['stripe_failed']
				? ' <strong>' . count( $result['stripe_failed'] ) . ' Stripe patch(es) failed:</strong> ' . esc_html( implode( '; ', $result['stripe_failed'] ) )
				: '',
			$result['lines'] ? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $result['lines'] ) ) . '</li></ul>' : ''
		);
	}

	if ( isset( $_POST['law_reference_stripe_nonce'] ) ) {
		check_admin_referer( 'law_reference_stripe', 'law_reference_stripe_nonce' );
		$restamp = law_events_reference_stripe_restamp( array_map( 'absint', (array) ( $_POST['law_reference_stripe_ids'] ?? array() ) ) );
		$notice .= sprintf(
			'<div class="notice notice-%s"><p><strong>%d Stripe object(s) re-stamped.</strong>%s</p>%s</div>',
			$restamp['failed'] ? 'warning' : 'success',
			$restamp['patched'],
			$restamp['failed'] ? ' <strong>' . count( $restamp['failed'] ) . ' failed:</strong> ' . esc_html( implode( '; ', $restamp['failed'] ) ) : '',
			$restamp['lines'] ? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $restamp['lines'] ) ) . '</li></ul>' : ''
		);
	}

	$scan = law_events_reference_scan();
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html().
	?>
	<h2>Reassign event references to the Gravity Forms entry IDs</h2>
	<p class="description" style="max-width:900px">
		References used to come from field 70 (Unique ID) on form 2 (Event &gt; submit an event), in the
		<code>LAW26-00121</code> format GP Unique ID generates, and migration carried that value across verbatim.
		The reference is now the number the committee actually works from: a migrated event takes its
		<strong>Gravity Forms entry ID</strong> (entry 190 for "Coming Soon to an Arbitration Near You…"), and an
		event created here takes its own <strong>event ID</strong>. New events already do this; this panel brings
		the ones migrated under the old rule into line.
	</p>
	<p class="description" style="max-width:900px">
		Each proposed ID comes from the entry link migration stored on the post (<code>_law_gf_entry_id</code>),
		never from a title match, and the entry is looked up in Gravity Forms so you can check the two titles
		agree before applying. Nothing is written until you press the button, it is safe to re-run, and every
		change is written to the event's activity log.
		<?php echo esc_html( sprintf( '%d of %d events already hold the right reference.', $scan['ok'], $scan['total'] ) ); ?>
	</p>

	<?php if ( ! $scan['fix'] && ! $scan['conflicts'] ) : ?>
		<p><strong>Nothing to reassign.</strong> Every event's reference is already its Gravity Forms entry ID (migrated) or its own event ID (created here).</p>
	<?php else : ?>
		<?php if ( $scan['fix'] ) : ?>
			<form method="post">
				<?php wp_nonce_field( 'law_reference_repair', 'law_reference_repair_nonce' ); ?>
				<table class="widefat striped" style="max-width:1200px">
					<thead>
					<tr>
						<th style="width:2em"><input type="checkbox" checked disabled></th>
						<th>Event</th><th>Status</th>
						<th>Reference now</th><th>Reference should be</th>
						<th>Where it comes from</th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $scan['fix'] as $row ) : ?>
						<tr>
							<td><input type="checkbox" name="law_reference_repair_ids[]" value="<?php echo esc_attr( (string) $row['event_id'] ); ?>" checked></td>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['event_id'] ) ); ?>">#<?php echo esc_html( (string) $row['event_id'] ); ?> <?php echo esc_html( $row['title'] ); ?></a></td>
							<td><?php echo esc_html( $row['status'] ); ?></td>
							<td><code><?php echo esc_html( $row['reference_now'] ?: '—' ); ?></code></td>
							<td><strong><code><?php echo esc_html( $row['reference_new'] ); ?></code></strong></td>
							<td class="description">
								<?php if ( 'gf_entry' !== $row['basis'] ) : ?>
									Not migrated: the event's own ID.
								<?php elseif ( ! $row['entry']['found'] ) : ?>
									Gravity Forms entry <?php echo esc_html( (string) $row['entry_id'] ); ?> on form 2
									(Event &gt; submit an event) — <strong>the entry is no longer readable</strong>, so the
									title could not be checked. The stored entry link is still the right number.
								<?php else : ?>
									Gravity Forms entry <?php echo esc_html( (string) $row['entry_id'] ); ?> on form 2
									(Event &gt; submit an event)<?php echo 'active' === $row['entry']['status'] ? '' : esc_html( ' (' . $row['entry']['status'] . ')' ); ?>,
									field 70 (Unique ID) <code><?php echo esc_html( $row['entry']['reference'] ?: '—' ); ?></code><br>
									field 17 (Event title): "<?php echo esc_html( $row['entry']['title'] ); ?>"
									<?php if ( law_events_reference_titles_differ( $row['title'], $row['entry']['title'] ) ) : ?>
										<br><span style="color:#996800"><strong>Titles differ from the event above — check this one.</strong></span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				$law_with_stripe = 0;
				foreach ( $scan['fix'] as $law_row ) {
					if ( array_filter( law_events_reference_stripe_objects( $law_row['event_id'] ) ) ) {
						$law_with_stripe++;
					}
				}
				?>
				<p><label>
					<input type="checkbox" name="law_reference_repair_stripe" value="1" checked>
					Also re-stamp the Stripe metadata on these events' customers and invoices
					<?php if ( $law_with_stripe ) : ?>
						(<?php echo esc_html( sprintf( '%d of the %d events above have one', $law_with_stripe, count( $scan['fix'] ) ) ); ?>)
					<?php else : ?>
						(none of the events above have one, so this will do nothing)
					<?php endif; ?>
				</label><br>
				<span class="description" style="max-width:900px;display:block">
					Their <code>law_reference</code> metadata still quotes the old reference, and it is what somebody
					reconciling a payment reads in the Stripe dashboard. Nothing in the code resolves an event by it
					(webhooks use <code>law_event_id</code>, then <code>gf_entry_id</code>), so leaving it alone breaks
					nothing; it just drifts. The patch touches metadata only, never the money, and a failure is logged
					on the event without holding up the reference itself.
				</span></p>
				<?php submit_button( 'Reassign the references on the ticked events', 'primary', 'submit', true, array( 'onclick' => "return confirm('Reassign the references on the ticked events? Each change is written to the event\\'s activity log, and unless you unticked the box the matching Stripe customers and invoices are re-stamped in Stripe too.');" ) ); ?>
			</form>
		<?php endif; ?>

		<?php if ( $scan['conflicts'] ) : ?>
			<h3>Skipped (needs a human)</h3>
			<table class="widefat striped" style="max-width:1200px">
				<thead><tr><th>Event</th><th>Reference now</th><th>Would become</th><th>Why it was skipped</th></tr></thead>
				<tbody>
				<?php foreach ( $scan['conflicts'] as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $row['event_id'] ) ); ?>">#<?php echo esc_html( (string) $row['event_id'] ); ?> <?php echo esc_html( $row['title'] ); ?></a></td>
						<td><code><?php echo esc_html( $row['reference_now'] ?: '—' ); ?></code></td>
						<td><code><?php echo esc_html( $row['reference_new'] ); ?></code></td>
						<td class="description"><?php echo esc_html( $row['reason'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>

	<?php $law_stripe_rows = law_events_reference_stripe_rows(); ?>
	<h3>Stripe metadata</h3>
	<p class="description" style="max-width:900px">
		Every customer and invoice raised for an event carries its reference as a <code>law_reference</code>
		metadata key, stamped once at creation. Nothing in the code resolves an event by it (webhooks use
		<code>law_event_id</code>, then <code>gf_entry_id</code>), so a stale value breaks nothing, but it is what
		somebody reconciling a payment reads in the Stripe dashboard. Use this when a reference changed without the
		Stripe box ticked, or after editing one by hand. The patch sends metadata only, never anything about the
		money, Stripe allows it on a finalised or paid invoice, and it merges, so keys the retired Make scenario
		left behind survive. Re-stamping something already correct is harmless.
	</p>

	<?php if ( ! $law_stripe_rows ) : ?>
		<p><strong>No Stripe objects to re-stamp.</strong> No event has a stored Stripe customer or invoice.</p>
	<?php else : ?>
		<form method="post">
			<?php wp_nonce_field( 'law_reference_stripe', 'law_reference_stripe_nonce' ); ?>
			<table class="widefat striped" style="max-width:1100px">
				<thead>
				<tr>
					<th style="width:2em"><input type="checkbox" checked disabled></th>
					<th>Event</th><th>Reference to stamp</th><th>Customer</th><th>Invoice</th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $law_stripe_rows as $law_row ) : ?>
					<tr>
						<td><input type="checkbox" name="law_reference_stripe_ids[]" value="<?php echo esc_attr( (string) $law_row['event_id'] ); ?>" checked></td>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $law_row['event_id'] ) ); ?>">#<?php echo esc_html( (string) $law_row['event_id'] ); ?> <?php echo esc_html( $law_row['title'] ); ?></a></td>
						<td><strong><code><?php echo esc_html( $law_row['reference'] ?: '—' ); ?></code></strong></td>
						<td><code><?php echo esc_html( $law_row['customer'] ?: '—' ); ?></code></td>
						<td><code><?php echo esc_html( $law_row['invoice'] ?: '—' ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( 'Re-stamp the ticked events in Stripe', 'secondary', 'submit', true, array( 'onclick' => "return confirm('Patch the metadata on these Stripe customers and invoices? Metadata only: nothing about the money changes.');" ) ); ?>
		</form>
	<?php endif; ?>
	<?php
}

/**
 * Do the post title and the entry's own Event title disagree enough to be
 * worth a second look?
 *
 * Compared loosely on purpose: migration trims and decodes entities, and a
 * committee member may since have tidied the title here, so only a real
 * difference should raise a flag.
 *
 * @param string $post_title  law_event post title.
 * @param string $entry_title Field 17 (Event title) on the source entry.
 * @return bool
 */
function law_events_reference_titles_differ( $post_title, $entry_title ) {
	$normalise = static function ( $value ) {
		$value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
		$value = preg_replace( '/[^a-z0-9]+/u', ' ', mb_strtolower( $value ) );
		return trim( (string) $value );
	};

	$a = $normalise( $post_title );
	$b = $normalise( $entry_title );
	if ( '' === $a || '' === $b ) {
		return false;
	}
	return $a !== $b;
}
