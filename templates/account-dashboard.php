<?php
/**
 * Template Name: Events dashboard (committee)
 *
 * The committee review dashboard replacing the Gravity Flow inbox and
 * GravityView 419 (EVENTS_4.1_REBUILD.md §3.6): a filterable list of every
 * event, and a detail view with the facts, thread, controls and the
 * Approve / Send back / Reject actions. Restrict the page with Members.
 */

get_header();

$law_detail = function_exists( 'law_committee_requested_event' ) ? law_committee_requested_event() : null;
$law_can    = law_user_is_committee();
// ?event=<id>&law_edit=1 swaps the detail view for the committee edit form
// (parts/events/committee-event-form.php) on this same page, so the Members
// gate and the committee check above cover it with no extra routing.
$law_edit_mode = $law_detail && ! empty( $_GET['law_edit'] );
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container law-dashboard">

	<?php if ( ! $law_can ) : ?>
		<p><?php esc_html_e( 'This dashboard is for the LAW committee.', 'law' ); ?></p>

	<?php elseif ( $law_edit_mode ) : ?>
		<?php get_template_part( 'parts/events/committee-event-form', null, array( 'post' => $law_detail ) ); ?>

	<?php elseif ( $law_detail ) : ?>
		<?php
		$law_id     = $law_detail->ID;
		$law_status = law_event_status_label( $law_detail );
		$law_error  = law_committee_take_error();
		$law_notice = sanitize_key( $_GET['law_notice'] ?? '' );
		$law_stripe_error = law_event_meta( $law_id, '_law_stripe_error' );
		$law_fee    = (int) law_event_meta( $law_id, '_law_fee_pence' );

		get_template_part( 'parts/layout/back-link', null, array(
			'url'   => get_permalink(),
			'label' => __( 'Back to all events', 'law' ),
		) );
		?>

		<?php if ( $law_error ) : ?>
			<div class="law-form-notice is-error" role="alert"><?php echo esc_html( $law_error ); ?></div>
		<?php elseif ( str_starts_with( $law_notice, 'action-' ) || 'saved' === $law_notice ) : ?>
			<div class="law-form-notice" role="status"><?php echo esc_html( 'saved' === $law_notice ? 'Changes saved.' : 'Done: ' . str_replace( array( 'action-', '_' ), array( '', ' ' ), $law_notice ) . '.' ); ?></div>
		<?php elseif ( 'invoice-sent' === $law_notice ) : ?>
			<div class="law-form-notice" role="status">Invoice created and sent.</div>
		<?php elseif ( 'invoice-failed' === $law_notice ) : ?>
			<div class="law-form-notice is-error" role="alert">Invoice creation failed again; see the Stripe error below.</div>
		<?php endif; ?>

		<div class="grid-x grid-padding-x">
			<div class="large-8 cell">
				<h1 class="law-dashboard__title"><?php echo esc_html( $law_detail->post_title ); ?>
					<span class="law-cal-card__badge law-cal-card__badge--<?php echo esc_attr( law_calendar_status_slug( $law_status ) ); ?>"><?php echo esc_html( $law_status ); ?></span></h1>

				<dl class="law-dashboard__facts">
					<dt>Reference</dt><dd><code><?php echo esc_html( (string) law_event_meta( $law_id, '_law_reference' ) ); ?></code></dd>
					<dt>Host</dt><dd><?php
						$law_author = get_user_by( 'id', (int) $law_detail->post_author );
						echo esc_html( $law_author ? $law_author->display_name . ' (' . $law_author->user_email . ')' : '—' );
					?></dd>
					<dt>Host organisation(s)</dt><dd><?php echo esc_html( (string) law_event_meta( $law_id, '_law_host_organisations' ) ?: '—' ); ?></dd>
					<dt>Event type</dt><dd><?php echo esc_html( law_events_post_term_name( $law_id, 'law_event_type' ) ?: '—' ); ?></dd>
					<dt>Sector</dt><dd><?php echo esc_html( law_event_sector_summary( $law_id ) ?: '—' ); ?></dd>
					<dt>Slot</dt><dd><?php echo esc_html( (string) law_event_meta( $law_id, '_law_slot_label' ) ?: 'Not confirmed' ); ?></dd>
					<dt>Preferred slots</dt><dd><?php echo esc_html( implode( '; ', law_event_meta( $law_id, '_law_preferred_slots' ) ) ?: '—' ); ?></dd>
					<dt>Venue needed?</dt><dd><?php echo esc_html( (string) law_event_meta( $law_id, '_law_venue_needed' ) ?: '—' ); ?></dd>
					<dt>Venue</dt><dd><?php echo esc_html( (string) law_event_meta( $law_id, '_law_venue' ) ?: '—' ); ?></dd>
					<dt>Venue capacity</dt><dd><?php echo esc_html( (string) law_event_meta( $law_id, '_law_venue_capacity' ) ?: '—' ); ?></dd>
					<dt>Tickets available</dt><dd><?php echo esc_html( (string) law_event_meta( $law_id, '_law_tickets_available' ) ?: '—' ); ?></dd>
					<dt>Terms accepted</dt><dd><?php
						$law_terms = law_event_meta( $law_id, '_law_terms_consent' );
						echo esc_html( ! empty( $law_terms['accepted'] ) ? ( ! empty( $law_terms['at'] ) ? mysql2date( 'j M Y, H:i', $law_terms['at'] ) : 'Yes' ) : '—' );
					?></dd>
					<dt>Fee tier</dt><dd><?php echo esc_html( law_event_tier_label( (string) law_event_meta( $law_id, '_law_fee_tier' ) ) ); ?></dd>
					<dt>Fee snapshot</dt><dd><?php echo esc_html( law_events_format_pence( $law_fee ) . ( law_event_meta( $law_id, '_law_vat' ) ? ' + VAT' : '' ) ); ?></dd>
					<dt>Payment</dt><dd><?php echo esc_html( ucfirst( (string) law_event_meta( $law_id, '_law_payment_status' ) ) ?: '—' ); ?>
						<?php $law_inv = (string) law_event_meta( $law_id, '_law_stripe_invoice_url' ); ?>
						<?php if ( $law_inv ) : ?> · <a href="<?php echo esc_url( $law_inv ); ?>" target="_blank" rel="noopener">Stripe invoice ↗</a><?php endif; ?></dd>
				</dl>

				<?php if ( is_array( $law_stripe_error ) && ! empty( $law_stripe_error['message'] ) ) : ?>
					<div class="law-form-notice is-error">
						<strong>Stripe error:</strong> <?php echo esc_html( $law_stripe_error['message'] ); ?>
						(<?php echo esc_html( $law_stripe_error['at'] ?? '' ); ?>)
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=law_event_retry_invoice&event_id=' . $law_id ), 'law_event_retry_invoice' ) ); ?>">Retry invoice</a>
					</div>
				<?php endif; ?>

				<div class="law-dashboard__description">
					<h2>Description</h2>
					<?php echo wp_kses_post( apply_filters( 'the_content', $law_detail->post_content ) ); ?>
				</div>

				<div class="law-dashboard__section">
					<h2>Speakers</h2>
					<?php $law_speaker_rows = law_event_meta( $law_id, '_law_speakers' ); ?>
					<?php if ( $law_speaker_rows ) : ?>
						<ul class="law-dashboard__people">
							<?php foreach ( $law_speaker_rows as $law_row ) :
								// Organisation, job title and photo are this event's own (the appearance row).
								$law_card = law_speaker_card( (int) ( $law_row['speaker_id'] ?? 0 ), (array) $law_row );
								if ( ! $law_card ) {
									continue;
								}
								$law_speaker = get_post( $law_card['id'] );
								$law_sp_web  = (string) law_event_meta( $law_speaker->ID, '_law_website' );
								$law_sp_bio  = trim( (string) $law_speaker->post_content );
								?>
								<li>
									<?php if ( $law_card['photo'] ) : ?>
										<img class="law-dashboard__person-photo" src="<?php echo esc_url( $law_card['photo'] ); ?>" alt="" width="150" height="150" loading="lazy">
									<?php endif; ?>
									<div>
										<strong><?php echo esc_html( $law_card['name'] ); ?></strong>
										<?php echo esc_html( implode( ' · ', array_filter( array( $law_card['job_title'], $law_card['organisation'] ) ) ) ); ?>
										<br><a href="mailto:<?php echo esc_attr( (string) law_event_meta( $law_speaker->ID, '_law_speaker_email' ) ); ?>"><?php echo esc_html( (string) law_event_meta( $law_speaker->ID, '_law_speaker_email' ) ); ?></a>
										<?php if ( $law_sp_web ) : ?> · <a href="<?php echo esc_url( $law_sp_web ); ?>" target="_blank" rel="noopener">Profile ↗</a><?php endif; ?>
										<?php if ( $law_sp_bio ) : ?><p class="law-dashboard__person-bio"><?php echo esc_html( $law_sp_bio ); ?></p><?php endif; ?>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p>No speakers added.</p>
					<?php endif; ?>
				</div>

				<?php $law_sessions = law_event_session_rows( $law_id ); ?>
				<?php if ( $law_sessions ) : ?>
					<div class="law-dashboard__section">
						<h2>Session agenda</h2>
						<ul class="law-dashboard__sessions">
							<?php foreach ( $law_sessions as $law_session ) : ?>
								<li>
									<strong><?php echo esc_html( $law_session['title'] ); ?></strong>
									<?php if ( $law_session['time_label'] ) : ?> — <?php echo esc_html( $law_session['time_label'] ); ?><?php endif; ?>
									<?php if ( $law_session['description'] ) : ?><p><?php echo esc_html( $law_session['description'] ); ?></p><?php endif; ?>
									<?php if ( $law_session['speakers'] ) : ?>
										<p class="law-dashboard__session-speakers">Speakers: <?php echo esc_html( implode( ', ', wp_list_pluck( $law_session['speakers'], 'name' ) ) ); ?></p>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<div class="law-dashboard__section">
					<h2>Owners &amp; contacts</h2>
					<?php
					$law_people_groups = array(
						'Additional event owners' => law_event_meta( $law_id, '_law_co_owner_rows' ),
						'Event contacts'          => law_event_meta( $law_id, '_law_contacts' ),
					);
					foreach ( $law_people_groups as $law_group_label => $law_group_rows ) :
						?>
						<h3><?php echo esc_html( $law_group_label ); ?></h3>
						<?php if ( $law_group_rows ) : ?>
							<ul class="law-dashboard__people law-dashboard__people--plain">
								<?php foreach ( $law_group_rows as $law_row ) : ?>
									<li><strong><?php echo esc_html( (string) ( $law_row['name'] ?? '' ) ); ?></strong>
										<?php if ( ! empty( $law_row['organisation'] ) ) : ?> · <?php echo esc_html( (string) $law_row['organisation'] ); ?><?php endif; ?>
										<?php if ( ! empty( $law_row['email'] ) ) : ?> · <a href="mailto:<?php echo esc_attr( (string) $law_row['email'] ); ?>"><?php echo esc_html( (string) $law_row['email'] ); ?></a><?php endif; ?></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p>None.</p>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>

				<?php
				$law_inv_address = law_event_meta( $law_id, '_law_invoice_address' );
				$law_inv_name    = (string) law_event_meta( $law_id, '_law_invoice_name' );
				$law_inv_email   = (string) law_event_meta( $law_id, '_law_invoice_email' );
				$law_inv_vat     = (string) law_event_meta( $law_id, '_law_vat_number' );
				?>
				<?php if ( $law_inv_name || $law_inv_email || array_filter( (array) $law_inv_address ) ) : ?>
					<div class="law-dashboard__section">
						<h2>Invoice details</h2>
						<dl class="law-dashboard__facts">
							<dt>Contact</dt><dd><?php echo esc_html( $law_inv_name ?: ( $law_inv_email ? '' : '—' ) ); ?>
								<?php if ( $law_inv_email ) : ?>(<a href="mailto:<?php echo esc_attr( $law_inv_email ); ?>"><?php echo esc_html( $law_inv_email ); ?></a>)<?php endif; ?></dd>
							<dt>Address</dt><dd><?php
								echo esc_html( implode( ', ', array_filter( array_map( 'strval', array(
									$law_inv_address['line1'] ?? '',
									$law_inv_address['line2'] ?? '',
									$law_inv_address['city'] ?? '',
									$law_inv_address['state'] ?? '',
									$law_inv_address['postal_code'] ?? '',
									$law_inv_address['country'] ?? '',
								) ) ) ) ?: '—' );
							?></dd>
							<dt>VAT number</dt><dd><?php echo esc_html( $law_inv_vat ?: '—' ); ?></dd>
						</dl>
					</div>
				<?php endif; ?>

				<?php if ( 'law-draft' !== $law_detail->post_status ) : // Drafts are edited by their host. ?>
					<p class="law-dashboard__edit">
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'event' => $law_id, 'law_edit' => 1 ), get_permalink() ) ); ?>">Edit event details</a>
					</p>
				<?php endif; ?>

				<?php get_template_part( 'parts/events/thread', null, array( 'event_id' => $law_id, 'context' => 'committee' ) ); ?>

				<details class="law-dashboard__log">
					<summary>Activity log (<?php echo esc_html( (string) count( law_event_log_entries( $law_id ) ) ); ?>)</summary>
					<ul class="law-log">
						<?php foreach ( law_event_log_entries( $law_id ) as $law_entry ) : ?>
							<li><span class="law-log__date"><?php echo esc_html( mysql2date( 'j M Y, H:i', $law_entry->comment_date ) ); ?></span>
								<strong><?php echo esc_html( $law_entry->comment_author ?: 'System' ); ?></strong>
								<?php echo esc_html( wp_strip_all_tags( $law_entry->comment_content ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			</div>

			<div class="large-4 cell">
				<form class="law-dashboard__controls" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="law_committee_action">
					<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $law_id ); ?>">
					<?php wp_nonce_field( 'law_committee_action' ); ?>

					<h2>Committee controls</h2>

					<p class="law-form-field"><label for="law-dash-slot">Confirmed slot</label>
						<select id="law-dash-slot" name="law_slot_label">
							<option value="">Slot not confirmed</option>
							<?php foreach ( law_events_slots( true ) as $law_slot ) : ?>
								<option value="<?php echo esc_attr( $law_slot['label'] ); ?>" <?php selected( (string) law_event_meta( $law_id, '_law_slot_label' ), $law_slot['label'] ); ?>>
									<?php echo esc_html( $law_slot['label'] . ( $law_slot['retired'] ? ' (retired)' : '' ) ); ?></option>
							<?php endforeach; ?>
						</select></p>

					<p class="law-form-field"><label for="law-dash-assignee">Assignee</label>
						<select id="law-dash-assignee" name="law_assignee">
							<option value="">(none)</option>
							<?php foreach ( law_events_committee_users() as $law_user ) : ?>
								<option value="<?php echo esc_attr( (string) $law_user->ID ); ?>" <?php selected( (int) law_event_meta( $law_id, '_law_assignee' ), $law_user->ID ); ?>><?php echo esc_html( $law_user->display_name ); ?></option>
							<?php endforeach; ?>
						</select></p>

					<input type="hidden" name="law_terms_present" value="1">
					<div class="law-form-field">
						<span class="law-form-label">Event category</span>
						<div class="law-choices">
							<?php
							$law_current_cats = law_events_post_term_names( $law_id, 'law_event_category' );
							$law_cat_terms    = get_terms( array( 'taxonomy' => 'law_event_category', 'hide_empty' => false ) );
							foreach ( is_wp_error( $law_cat_terms ) ? array() : $law_cat_terms as $law_term ) :
								?>
								<label><input type="checkbox" name="law_event_category[]" value="<?php echo esc_attr( $law_term->name ); ?>" <?php checked( in_array( $law_term->name, $law_current_cats, true ) ); ?>>
									<?php echo esc_html( $law_term->name ); ?></label>
							<?php endforeach; ?>
						</div>
					</div>

					<p class="law-form-field"><label for="law-dash-orgs">Linked organisations (sponsor highlighting)</label>
						<select id="law-dash-orgs" name="law_organisation_ids[]" multiple size="5">
							<?php
							$law_linked_orgs = array_map( 'intval', law_event_meta( $law_id, '_law_organisation_ids' ) );
							foreach ( get_posts( array( 'post_type' => 'organisation', 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $law_org ) :
								?>
								<option value="<?php echo esc_attr( (string) $law_org->ID ); ?>" <?php selected( in_array( $law_org->ID, $law_linked_orgs, true ) ); ?>><?php echo esc_html( $law_org->post_title ); ?></option>
							<?php endforeach; ?>
						</select></p>

					<?php $law_fee_override = (bool) law_event_meta( $law_id, '_law_fee_override' ); ?>
					<div class="law-form-field">
						<div class="law-choices">
							<label><input type="checkbox" id="law-dash-override" name="law_fee_override" value="1" <?php checked( $law_fee_override ); ?>> Override fee</label>
						</div>
					</div>

					<?php
					// Legacy parity: field 81 (Discounted fee) on form 2 (Event > submit an
					// event) was shown only when field 87 (Override fee) was ticked. The
					// hidden attribute is rendered here, not applied by JS on load, so there
					// is no flash and no-JS committee members with the box already ticked
					// still see the amount. The input is never disabled: the handler keys off
					// isset( $_POST['law_fee_override_amount'] ) to decide whether to touch
					// the override at all.
					?>
					<p class="law-form-field" id="law-dash-amount-field" data-law-toggle-for="law-dash-override"<?php echo $law_fee_override ? '' : ' hidden'; ?>><label for="law-dash-amount">Override amount (£)<br><small>Enter new fee in pounds, without symbol. Leave blank for no change; enter 0 for a free event.</small></label>
						<input type="number" id="law-dash-amount" name="law_fee_override_amount" step="0.01" min="0" value="<?php echo esc_attr( (string) law_event_meta( $law_id, '_law_fee_override_amount' ) ); ?>"></p>

					<?php
					// Only the actions legal for the event's current status get buttons
					// and modals: the same from-lists the workflow engine enforces, so
					// nobody is offered a Send back that would only bounce with an error.
					$law_actions = law_event_available_ui_actions( $law_detail );

					// Delete (trash) is not a workflow action: it is only offered on a
					// Cancelled or Rejected event (the handler enforces the same), so a
					// live event with an open invoice must be cancelled first.
					$law_can_delete = in_array( $law_detail->post_status, array( 'law-cancelled', 'law-rejected' ), true );

					// The no-JS path for Send back, Reject and Cancel: one inline comment
					// box and the plain submit buttons below. With JS, law-modal.js hides
					// and disables this field (that is what data-law-modal-fallback marks
					// it as) and turns the action buttons into openers for the modals at
					// the foot of the form, so a normal browser has one law_note textarea
					// enabled at a time.
					if ( array_intersect( array( 'send_back', 'reject', 'cancel' ), $law_actions ) ) :
						?>
					<p class="law-form-field" id="law-dash-note-field" data-law-modal-fallback><label for="law-dash-note">Comment / reason<br><small>(required for Send back, Reject and Cancel; the host sees it)</small></label>
						<textarea id="law-dash-note" name="law_note" rows="3"></textarea></p>
					<?php endif; ?>

					<p class="law-form-field"><label for="law-dash-private-note">Private note<br><small>(committee only, saved to the activity log)</small></label>
						<textarea id="law-dash-private-note" name="law_private_note" rows="2"></textarea></p>

					<p class="law-dashboard__buttons">
						<button type="submit" name="law_action" value="" class="button">Save changes</button>
						<?php if ( in_array( 'approve', $law_actions, true ) ) : ?>
							<button type="submit" name="law_action" value="approve" class="button orange" data-law-modal-open="law-modal-approve">Approve</button>
						<?php endif; ?>
						<?php if ( in_array( 'send_back', $law_actions, true ) ) : ?>
							<button type="submit" name="law_action" value="send_back" class="button" data-law-modal-open="law-modal-send-back">Send back</button>
						<?php endif; ?>
						<?php if ( in_array( 'reject', $law_actions, true ) ) : ?>
							<button type="submit" name="law_action" value="reject" class="button alert" data-law-modal-open="law-modal-reject">Reject</button>
						<?php endif; ?>
						<?php if ( in_array( 'mark_paid', $law_actions, true ) ) : ?>
							<button type="submit" name="law_action" value="mark_paid" class="button" data-law-modal-open="law-modal-mark-paid">Mark paid &amp; confirm</button>
						<?php endif; ?>
						<?php if ( in_array( 'cancel', $law_actions, true ) ) : ?>
							<button type="submit" name="law_action" value="cancel" class="button alert" data-law-modal-open="law-modal-cancel">Cancel event</button>
						<?php endif; ?>
						<?php if ( $law_can_delete ) : ?>
							<button type="submit" name="law_action" value="delete" class="button alert" data-law-modal-open="law-modal-delete">Delete event</button>
						<?php endif; ?>
					</p>

					<p><a href="<?php echo esc_url( get_edit_post_link( $law_id ) ); ?>">Full editing in wp-admin →</a></p>

					<?php
					// The confirmation dialogs. They live inside the controls form, so the
					// slot, assignee, categories, linked organisations, fee override and
					// private note all still post with the action, and each carries the
					// submit button that actually fires it. Their note fields ship disabled
					// and are enabled by JS only while their own modal is open.
					//
					// The fee is the live calculated figure, override included, which is
					// exactly what approval freezes onto the event, so the number quoted
					// below is the number that gets invoiced.
					$law_approve_fee = law_event_calculate_fee_pence( $law_id );

					if ( in_array( 'approve', $law_actions, true ) ) {
						get_template_part(
							'parts/layout/modal',
							null,
							array(
								'id'      => 'law-modal-approve',
								'title'   => 'Approve this event',
								'copy'    => $law_approve_fee > 0
									? array(
										'The fee is fixed at ' . esc_html( law_events_format_pence( $law_approve_fee ) ) . ' plus VAT, and a Stripe invoice is raised and emailed to the invoice contact.',
										'Any additional event owners get an account on this event, and the committee is emailed to say it has been approved.',
										'The event stays Approved and is not published on the programme until the invoice is paid. Everything else you have changed on this form is saved at the same time.',
									)
									: array(
										'This event is free, so the fee is fixed at ' . esc_html( law_events_format_pence( 0 ) ) . ' and no invoice is raised.',
										'Any additional event owners get an account on this event, and the committee is emailed to say it has been approved.',
										'The event is then confirmed and published on the programme straight away. Everything else you have changed on this form is saved at the same time.',
									),
								'confirm' => array( 'label' => 'Approve', 'name' => 'law_action', 'value' => 'approve', 'class' => 'button orange', 'busy' => 'Approving…' ),
								'close'   => 'Close',
							)
						);
					}

					if ( in_array( 'send_back', $law_actions, true ) ) {
						get_template_part(
							'parts/layout/modal',
							null,
							array(
								'id'      => 'law-modal-send-back',
								'title'   => 'Send this event back to the host',
								'copy'    => "The host is emailed your message and it is posted to the event's message thread, so they can reply and resubmit. Everything else you have changed on this form is saved at the same time.",
								'field'   => array(
									'name'     => 'law_note',
									'label'    => 'What needs changing? (required)',
									'rows'     => 4,
									'required' => true,
									'error'    => 'Please tell the host why, so they know what to do next.',
								),
								'confirm' => array( 'label' => 'Send back', 'name' => 'law_action', 'value' => 'send_back', 'class' => 'button', 'busy' => 'Sending back…' ),
							)
						);
					}

					if ( in_array( 'reject', $law_actions, true ) ) {
						get_template_part(
							'parts/layout/modal',
							null,
							array(
								'id'      => 'law-modal-reject',
								'title'   => 'Reject this event',
								'copy'    => "The host is emailed your reason and the event cannot be resubmitted, so please be clear about why it was not accepted. The reason is also posted to the event's message thread.",
								'field'   => array(
									'name'     => 'law_note',
									'label'    => 'Reason for rejection (required)',
									'help'     => 'This will be inserted in the email sent to the submitter, explaining the rejection.',
									'rows'     => 4,
									'required' => true,
									'error'    => 'Please tell the host why, so they know what to do next.',
								),
								'confirm' => array( 'label' => 'Reject event', 'name' => 'law_action', 'value' => 'reject', 'class' => 'button alert', 'busy' => 'Rejecting…' ),
							)
						);
					}

					if ( in_array( 'mark_paid', $law_actions, true ) ) {
						get_template_part(
							'parts/layout/modal',
							null,
							array(
								'id'      => 'law-modal-mark-paid',
								'title'   => 'Mark as paid and confirm',
								'copy'    => array(
									'This records the fee as paid by hand, outside Stripe, so please only use it once the money has actually arrived (a bank transfer, say). It does not check with Stripe first.',
									'The event is then published on the programme, the host is emailed to confirm, and the committee gets a payment received notice.',
									'Everything else you have changed on this form is saved at the same time.',
								),
								'confirm' => array( 'label' => 'Mark paid and confirm', 'name' => 'law_action', 'value' => 'mark_paid', 'class' => 'button', 'busy' => 'Confirming…' ),
								'close'   => 'Close',
							)
						);
					}

					if ( in_array( 'cancel', $law_actions, true ) ) {
						get_template_part(
							'parts/layout/modal',
							null,
							array(
								'id'      => 'law-modal-cancel',
								'title'   => 'Cancel this event',
								'copy'    => array(
									'The host is emailed your reason and it is posted to the event\'s message thread. The event cannot be resubmitted.',
									'publish' === $law_detail->post_status
										? 'The event comes off the published programme.'
										: 'The event will not be published.',
									'unpaid' === (string) law_event_meta( $law_id, '_law_payment_status' ) && $law_fee > 0
										? 'The outstanding Stripe invoice is cancelled (voided), so no payment is due.'
										: 'A fee that has already been paid is never refunded automatically: the committee is alerted to review the payment in Stripe instead.',
									'Everything else you have changed on this form is saved at the same time.',
								),
								'field'   => array(
									'name'     => 'law_note',
									'label'    => 'Reason for cancellation (required)',
									'help'     => 'This is emailed to the host and posted to the event thread.',
									'rows'     => 4,
									'required' => true,
									'error'    => 'Please tell the host why the event is being cancelled.',
								),
								'confirm' => array( 'label' => 'Cancel event', 'name' => 'law_action', 'value' => 'cancel', 'class' => 'button alert', 'busy' => 'Cancelling…' ),
								// The default close label is "Cancel", which on this dialog
								// would read as the destructive action.
								'close'   => 'Keep the event',
							)
						);
					}

					if ( $law_can_delete ) {
						get_template_part(
							'parts/layout/modal',
							null,
							array(
								'id'      => 'law-modal-delete',
								'title'   => 'Delete this event',
								'copy'    => array(
									'The event is moved to the trash: it disappears from this dashboard and from the host\'s events list, and no one is emailed.',
									'It can be restored from the wp-admin Events list (Trash) if this was a mistake; permanent deletion also happens there.',
								),
								'confirm' => array( 'label' => 'Delete event', 'name' => 'law_action', 'value' => 'delete', 'class' => 'button alert', 'busy' => 'Deleting…' ),
								'close'   => 'Keep the event',
							)
						);
					}

					// The success dialog committee-actions.js opens once an AJAX
					// action has gone through; the script overwrites the title
					// and copy from the server's response, so these are only
					// fallbacks. No confirm button: it is informational, and the
					// page reloads itself a few seconds later. Delete is offered on
					// statuses with no workflow actions (Cancelled/Rejected), so it
					// needs the dialog too.
					if ( ! empty( $law_actions ) || $law_can_delete ) {
						get_template_part(
							'parts/layout/modal',
							null,
							array(
								'id'      => 'law-modal-success',
								'title'   => 'Done',
								'copy'    => 'Reloading the page…',
								'confirm' => false,
								'close'   => 'Close',
							)
						);
					}
					?>
				</form>
			</div>
		</div>

	<?php else : ?>
		<?php if ( 'event-deleted' === sanitize_key( $_GET['law_notice'] ?? '' ) ) : ?>
			<div class="law-form-notice" role="status">Event moved to trash. It can be restored from the wp-admin Events list.</div>
		<?php endif; ?>
		<?php
		// The filter bar reuses the programme page's markup, CSS and JS
		// (parts/calendar-filters.php, assets/js/calendar-filters.js): keyword
		// and status filter over AJAX, mobile filters modal, GET fallback.
		$law_counts   = law_committee_status_counts();
		$law_current  = sanitize_key( $_GET['law_status'] ?? '' );
		$law_kw       = sanitize_text_field( wp_unslash( $_GET['law_kw'] ?? '' ) );
		$law_page_url = get_permalink();
		?>
		<div class="law-cal-controls" data-law-cal-controls data-page-url="<?php echo esc_url( $law_page_url ); ?>">
			<div class="law-cal-filterbar">
				<button type="button" class="button law-cal-filterbar__toggle" aria-expanded="false" aria-controls="law-cal-filter-panel" hidden>
					<span class="law-cal-filterbar__burger" aria-hidden="true"><span></span><span></span><span></span></span>
					<?php esc_html_e( 'Filters', 'law' ); ?>
				</button>

				<div class="law-cal-filterbar__panel" id="law-cal-filter-panel">
					<div class="law-cal-filterbar__head">
						<p class="law-cal-filterbar__title"><?php esc_html_e( 'Filters', 'law' ); ?></p>
						<button type="button" class="law-cal-filterbar__close" aria-label="<?php esc_attr_e( 'Close filters', 'law' ); ?>">&times;</button>
					</div>

					<form class="law-cal-filter-form" id="law-cal-filter-form" method="get" action="<?php echo esc_url( $law_page_url ); ?>">
						<p class="law-cal-filter-form__field law-cal-filter-form__field--keyword">
							<label class="show-for-sr" for="law-dash-kw"><?php esc_html_e( 'Keyword', 'law' ); ?></label>
							<input
								type="search"
								id="law-dash-kw"
								name="law_kw"
								value="<?php echo esc_attr( $law_kw ); ?>"
								placeholder="<?php esc_attr_e( 'Enter a keyword', 'law' ); ?>"
								autocomplete="off"
							>
						</p>

						<p class="law-cal-filter-form__field">
							<label class="show-for-sr" for="law-dash-status"><?php esc_html_e( 'Status', 'law' ); ?></label>
							<select id="law-dash-status" name="law_status">
								<option value=""><?php esc_html_e( 'All statuses', 'law' ); ?></option>
								<?php foreach ( law_event_statuses() as $law_status_key => $law_config ) :
									if ( 'law-draft' === $law_status_key ) { continue; } ?>
									<option value="<?php echo esc_attr( $law_status_key ); ?>" <?php selected( $law_current, $law_status_key ); ?>>
										<?php echo esc_html( $law_config['label'] . ' (' . ( $law_counts[ $law_status_key ] ?? 0 ) . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>

						<div class="law-cal-filter-form__actions">
							<button type="submit" class="button law-cal-filter-form__apply"><?php esc_html_e( 'Apply', 'law' ); ?></button>
							<a class="button second law-cal-filter-form__clear" href="<?php echo esc_url( $law_page_url ); ?>"><?php esc_html_e( 'Clear all', 'law' ); ?></a>
						</div>
					</form>
				</div>
			</div>

			<?php
			// Export buttons (functions/events/export.php). The hrefs bake in
			// the server-rendered filters as the no-JS fallback; JS refreshes
			// them with the live values (assets/js/export-buttons.js). PDF is
			// built client-side by pdfmake, so it only renders with JS.
			$law_export_base = wp_nonce_url( admin_url( 'admin-post.php?action=law_committee_export' ), 'law_committee_export' );
			$law_export_args = array_filter( array( 'law_kw' => $law_kw, 'law_status' => $law_current ) );
			?>
			<div class="law-cal-export" data-law-export data-export-url="<?php echo esc_url( $law_export_base ); ?>">
				<span class="law-cal-export__label"><?php esc_html_e( 'Export:', 'law' ); ?></span>
				<a class="button second" data-format="csv" href="<?php echo esc_url( add_query_arg( $law_export_args + array( 'format' => 'csv' ), $law_export_base ) ); ?>">CSV</a>
				<a class="button second" data-format="xlsx" href="<?php echo esc_url( add_query_arg( $law_export_args + array( 'format' => 'xlsx' ), $law_export_base ) ); ?>">Excel</a>
				<button type="button" class="button second" data-format="pdf" hidden>PDF</button>
			</div>
		</div>

		<div class="law-cal-events" id="law-cal-events" aria-live="polite" data-law-skeleton="table">
			<?php get_template_part( 'parts/events/dashboard-list' ); ?>
		</div>
	<?php endif; ?>

	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
