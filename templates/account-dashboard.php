<?php
/**
 * Template Name: Events dashboard (committee)
 *
 * The committee review dashboard replacing the Gravity Flow inbox and
 * GravityView 419 (EVENTS_4.1_REBUILD.md §3.6): a filterable list of every
 * event, and a detail view with the facts, thread, controls and the
 * Approve / Send back / Reject actions. Restrict the page with Members.
 */

// Every branch of this page is committee-only: the review list, the detail
// view with its invoice figures and activity log, the edit form and the
// preview of an unpublished event. Access rests on a runtime capability check,
// so nothing in front of PHP may key a copy of the HTML on the URL alone.
// The page's own AJAX partial does this already
// (law_committee_maybe_render_partial(), functions/events/committee.php); the
// full render was missing it. Called before any output, so the headers still
// go out with the response.
nocache_headers();

// ?preview-event=<id> renders the attendee-facing single event view for the
// committee, so an event can be checked before it is confirmed. It reuses
// parts/calendar-body.php -- the same renderer the programme and the single
// event permalink use -- and only two things differ: the event is resolved
// here, because the partial's own resolver applies the public status filter
// and an unconfirmed event would come back null, and the back link returns to
// this dashboard's detail view rather than the programme. Rendered before
// get_header() because calendar-body.php brings its own header and footer,
// exactly as templates/calendar-committee.php does.
//
// A law-draft is deliberately NOT previewable: it is owner-only unsubmitted
// host data, the same reasoning behind law_committee_events() refusing an
// explicit law_status=law-draft (2026-09-07 finding). A draft id falls through
// to the dashboard, and the detail view offers no Preview button on a draft --
// exactly as it offers no Edit button.
$law_preview_id = absint( wp_unslash( $_GET['preview-event'] ?? 0 ) );
if ( $law_preview_id && law_user_is_committee() && 'cpt' === law_events_source() ) {
	// The statuses this route renders, named explicitly: every registered
	// law_event workflow status except law-draft. law_events_map_post()'s
	// array() means "every status but law-draft", which is wider than the
	// workflow -- it would also render a trashed or auto-draft event -- so the
	// post's own status is checked against the vocabulary first. The post-type
	// check inside law_events_map_post() still handles an id naming a page or
	// a booking.
	$law_preview_statuses = array_diff( array_keys( law_event_statuses() ), array( 'law-draft' ) );
	$law_preview          = in_array( get_post_status( $law_preview_id ), $law_preview_statuses, true )
		? law_events_map_post( $law_preview_id, array() )
		: null;
	if ( $law_preview ) {
		$law_cal_event       = law_events_cpt_hydrate( $law_preview );
		$law_cal_show_status = true;
		$law_cal_preview     = true;
		$law_cal_back        = array(
			'url'   => add_query_arg( 'event', $law_preview_id, get_permalink() ),
			'label' => __( 'Back to event', 'law' ),
		);
		require get_theme_file_path( 'parts/calendar-body.php' );
		return;
	}
}

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
		<?php elseif ( 'agenda-on' === $law_notice ) : ?>
			<?php // The agenda fields live on the edit form, not this screen, so link there. ?>
			<div class="law-form-notice" role="status">Session agenda turned on.
				<a href="<?php echo esc_url( add_query_arg( array( 'event' => $law_id, 'law_edit' => 1 ), get_permalink() ) . '#law-section-agenda' ); ?>">Add the sessions now</a>, or leave it for the host to fill in.</div>
		<?php elseif ( 'agenda-off' === $law_notice ) : ?>
			<div class="law-form-notice" role="status">Session agenda turned off. The section has been removed from this event's form.</div>
		<?php elseif ( 'agenda-off-kept' === $law_notice ) : ?>
			<?php
			// Unticking the box does not delete sessions, and the section stays
			// while any exist. Saying nothing here is what made the old category
			// checkboxes feel like a control with no effect.
			$law_kept_sessions = count( law_event_session_ids( $law_id ) );
			?>
			<div class="law-form-notice" role="status">Session agenda turned off. This event still has
				<?php echo esc_html( sprintf( _n( '%d session', '%d sessions', $law_kept_sessions, 'law' ), $law_kept_sessions ) ); ?>,
				so the section stays on its form until they are deleted.</div>
		<?php elseif ( 'invoice-sent' === $law_notice ) : ?>
			<div class="law-form-notice" role="status">Invoice created and sent.</div>
		<?php elseif ( 'invoice-failed' === $law_notice ) : ?>
			<div class="law-form-notice is-error" role="alert">Invoice creation failed again; see the Stripe error below.</div>
		<?php endif; ?>

		<div class="grid-x grid-padding-x">
			<div class="large-8 cell">
				<h1 class="law-dashboard__title"><?php echo esc_html( $law_detail->post_title ); ?>
					<span class="law-cal-card__badge law-cal-card__badge--<?php echo esc_attr( law_calendar_status_slug( $law_status ) ); ?>"><?php echo esc_html( $law_status ); ?></span></h1>

				<?php if ( 'law-draft' !== $law_detail->post_status ) : // A draft belongs to its host: neither edited nor previewed here. ?>
					<p class="law-dashboard__event-actions">
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'event' => $law_id, 'law_edit' => 1 ), get_permalink() ) ); ?>">Edit event details</a>
						<?php
						// The preview renders law_event posts, so there is nothing to
						// offer pre-cutover. Once the event is Confirmed there is a real
						// public page, so the button becomes View event and goes to the
						// permalink -- the preview is only for events the public cannot
						// reach yet. It opens in a new tab because the public page has no
						// way back to this dashboard, unlike the preview's back link.
						if ( 'cpt' === law_events_source() ) :
							$law_is_confirmed = 'publish' === $law_detail->post_status;
							?>
							<a class="button second"
								href="<?php echo esc_url( $law_is_confirmed ? get_permalink( $law_id ) : add_query_arg( 'preview-event', $law_id, get_permalink() ) ); ?>"
								<?php echo $law_is_confirmed ? ' target="_blank" rel="noopener"' : ''; ?>><?php
								echo esc_html( $law_is_confirmed ? __( 'View event', 'law' ) : __( 'Preview event', 'law' ) );
							?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<dl class="law-dashboard__facts">
					<dt>Reference</dt><dd><code><?php echo esc_html( (string) law_event_meta( $law_id, '_law_reference' ) ); ?></code></dd>
					<dt>Host</dt><dd><?php
						$law_author = get_user_by( 'id', (int) $law_detail->post_author );
						echo esc_html( $law_author ? $law_author->display_name . ' (' . $law_author->user_email . ')' : '—' );
					?></dd>
					<dt>Host organisation(s)</dt><dd><?php echo esc_html( (string) law_event_meta( $law_id, '_law_host_organisations' ) ?: '—' ); ?></dd>
					<dt>Linked organisations</dt><dd><?php echo esc_html( implode( ', ', law_event_organisation_names( $law_id ) ) ?: '—' ); ?></dd>
					<dt>Event type</dt><dd><?php echo esc_html( law_events_post_term_name( $law_id, 'law_event_type' ) ?: '—' ); ?></dd>
					<?php
					// The two committee switches, read-only, next to the other facts.
					// Same reasoning as the Linked organisations row: the only other way
					// to see the value is to find the control further down the page.
					$law_detail_sessions = count( law_event_session_ids( $law_id ) );
					?>
					<dt>Run by</dt><dd><?php echo esc_html( law_event_meta( $law_id, '_law_is_law_event' ) ? 'LAW' : 'An external host' ); ?></dd>
					<dt>Session agenda</dt><dd><?php
						if ( law_event_meta( $law_id, '_law_session_agenda' ) || $law_detail_sessions ) {
							echo esc_html(
								$law_detail_sessions
									? sprintf( _n( 'On, %d session', 'On, %d sessions', $law_detail_sessions, 'law' ), $law_detail_sessions )
									: 'On, none added yet'
							);
							// The switch is off but the section is still on the form.
							if ( ! law_event_meta( $law_id, '_law_session_agenda' ) ) {
								echo esc_html( ' (switch off, sessions still present)' );
							}
						} else {
							echo esc_html( 'Off' );
						}
					?></dd>
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
								// Role, organisation, job title and photo are this event's own (the appearance row).
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
										<?php $law_card_role = law_speaker_role_display( $law_card['role'] ); ?>
										<?php if ( '' !== $law_card_role ) : ?><span class="law-dashboard__person-role">(<?php echo esc_html( $law_card_role ); ?>)</span><?php endif; ?>
										<?php echo esc_html( implode( ' · ', array_filter( array( $law_card['job_title'], $law_card['organisation'] ) ) ) ); ?>
										<br><a href="mailto:<?php echo esc_attr( (string) law_event_meta( $law_speaker->ID, '_law_speaker_email' ) ); ?>"><?php echo esc_html( (string) law_event_meta( $law_speaker->ID, '_law_speaker_email' ) ); ?></a>
										<?php if ( $law_sp_web ) : ?> · <a href="<?php echo esc_url( $law_sp_web ); ?>" target="_blank" rel="noopener">Profile ↗</a><?php endif; ?>
										<?php // Rich text (functions/events/rich-text.php): the host wrote this in a WYSIWYG editor, so the preview has to show the formatting rather than the source. ?>
										<?php if ( $law_sp_bio ) : ?><div class="law-dashboard__person-bio"><?php echo law_rich_text_render( $law_sp_bio ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses'd against the rich-text allowlist. ?></div><?php endif; ?>
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
									<?php if ( $law_session['description'] ) : ?><div class="law-dashboard__session-body"><?php echo law_rich_text_render( $law_session['description'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses'd against the rich-text allowlist. ?></div><?php endif; ?>
									<?php if ( $law_session['speakers'] ) : ?>
										<?php
										// Each name with the role the person has in this session (a blank
										// session row inherits the event's), the way the public cards print it.
										$law_session_names = array_map(
											function ( $law_session_speaker ) {
												$law_session_role = law_speaker_role_display( (string) ( $law_session_speaker['role'] ?? '' ) );
												return $law_session_speaker['name'] . ( '' !== $law_session_role ? ' (' . $law_session_role . ')' : '' );
											},
											$law_session['speakers']
										);
										?>
										<p class="law-dashboard__session-speakers">Speakers: <?php echo esc_html( implode( ', ', $law_session_names ) ); ?></p>
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

					<?php
					// Normalised, so a slot stored with different dash
					// punctuation still shows as the selected option instead of
					// falling back to "not confirmed" and clearing the dates on
					// the next save.
					$law_slot_current = law_events_normalise_slot_label( law_event_meta( $law_id, '_law_slot_label' ) );
					?>
					<p class="law-form-field"><label for="law-dash-slot">Confirmed slot</label>
						<select id="law-dash-slot" name="law_slot_label">
							<option value="">Slot not confirmed</option>
							<?php foreach ( law_events_slots( true ) as $law_slot ) : ?>
								<option value="<?php echo esc_attr( $law_slot['label'] ); ?>" <?php selected( $law_slot_current, $law_slot['label'] ); ?>>
									<?php echo esc_html( $law_slot['label'] . ( $law_slot['retired'] ? ' (retired)' : '' ) ); ?></option>
							<?php endforeach; ?>
						</select></p>

					<?php
					// Venue capacity and Places available. Always on the panel,
					// not only for an event LAW placed: the committee owns the
					// band at every status, and a host who asked LAW to find
					// them a venue never sees these two on their own form
					// (law_events_venue_details_visible()), so this panel and
					// wp-admin are the only doors to them.
					//
					// data-law-capacity / data-law-tickets are the same hooks the
					// event form uses, so event-form.js keeps the number field's
					// max in step with the band here too. The panel and the edit
					// form never render together (?law_edit=1 swaps one for the
					// other), so the single-element lookup in that script is safe.
					$law_dash_capacity = (string) law_event_meta( $law_id, '_law_venue_capacity' );
					$law_dash_places   = (string) law_event_meta( $law_id, '_law_tickets_available' );
					$law_dash_bands    = law_events_venue_capacity_bands();
					$law_dash_max      = $law_dash_bands[ $law_dash_capacity ] ?? null;
					?>
					<input type="hidden" name="law_venue_present" value="1">

					<p class="law-form-field"><label for="law-dash-capacity">Venue capacity</label>
						<select id="law-dash-capacity" name="law_venue_capacity" data-law-capacity>
							<option value="">(not set)</option>
							<?php foreach ( $law_dash_bands as $law_dash_band => $law_dash_band_max ) : ?>
								<option value="<?php echo esc_attr( $law_dash_band ); ?>" data-law-max="<?php echo esc_attr( null === $law_dash_band_max ? '' : (string) $law_dash_band_max ); ?>" <?php selected( $law_dash_capacity, $law_dash_band ); ?>><?php echo esc_html( $law_dash_band ); ?></option>
							<?php endforeach; ?>
						</select></p>

					<p class="law-form-field"><label for="law-dash-places">Places available</label>
						<input type="number" id="law-dash-places" name="law_tickets_available" min="1"
							<?php echo null === $law_dash_max ? '' : 'max="' . esc_attr( (string) $law_dash_max ) . '"'; ?>
							data-law-tickets value="<?php echo esc_attr( '0' === $law_dash_places ? '' : $law_dash_places ); ?>">
						<small>Places cannot exceed the band, and "251+" and "TBC" set no ceiling at all. Leave this blank for an event with no limit. Raising it offers the new places to anyone on the waitlist.</small></p>

					<p class="law-form-field"><label for="law-dash-assignee">Assignee</label>
						<select id="law-dash-assignee" name="law_assignee">
							<option value="">(none)</option>
							<?php foreach ( law_events_committee_users() as $law_user ) : ?>
								<option value="<?php echo esc_attr( (string) $law_user->ID ); ?>" <?php selected( (int) law_event_meta( $law_id, '_law_assignee' ), $law_user->ID ); ?>><?php echo esc_html( $law_user->display_name ); ?></option>
							<?php endforeach; ?>
						</select></p>

					<?php
					// The two classification switches. Rendered as separate fields
					// rather than a titled group: they are unrelated to each other and
					// this panel is already headed "Committee controls".
					//
					// Help text is <small> inside the field, NOT class="law-form-hint":
					// that class is coloured #d7d7ea for the dark host form and only
					// repainted under .law-event-form--light, so it would render pale
					// lilac on this white panel.
					$law_is_law_event   = (bool) law_event_meta( $law_id, '_law_is_law_event' );
					$law_agenda_on      = (bool) law_event_meta( $law_id, '_law_session_agenda' );
					$law_session_count  = count( law_event_session_ids( $law_id ) );
					?>
					<input type="hidden" name="law_flags_present" value="1">

					<div class="law-form-field">
						<div class="law-choices">
							<label><input type="checkbox" name="law_is_law_event" value="1" <?php checked( $law_is_law_event ); ?>> Run by LAW, not an external host</label>
						</div>
						<small>Tick this for an event LAW organises itself. It adds a LAW tag to the events list and the exports, and it lets visitors pick &ldquo;LAW events&rdquo; from the Organiser filter on the public programme.</small>
					</div>

					<div class="law-form-field">
						<div class="law-choices">
							<label><input type="checkbox" name="law_session_agenda" value="1" <?php checked( $law_agenda_on ); ?>> This event has a session agenda</label>
						</div>
						<?php if ( ! $law_agenda_on && $law_session_count ) : ?>
							<?php
							// The switch is off but the section is still on the form, because
							// law_event_has_session_agenda() keeps it while sessions exist:
							// hiding it would strand the agenda, and the saver deletes rows
							// the form does not post. Say so, or this reads as a control with
							// no effect.
							?>
							<small>This event already has <?php echo esc_html( sprintf( _n( '%d session', '%d sessions', $law_session_count, 'law' ), $law_session_count ) ); ?>, so the Session agenda section stays on its form even with this box unticked.
								<a href="<?php echo esc_url( add_query_arg( array( 'event' => $law_id, 'law_edit' => 1 ), get_permalink() ) . '#law-section-agenda' ); ?>">Delete the sessions</a> first if you want to remove the agenda.</small>
						<?php else : ?>
							<small>Adds a Session agenda section to this event's form, so the running order can be broken into sessions with their own times and speakers.</small>
						<?php endif; ?>
					</div>

					<input type="hidden" name="law_orgs_present" value="1">
					<p class="law-form-field"><label for="law-dash-orgs">Linked organisations (sponsor highlighting)</label>
						<select id="law-dash-orgs" name="law_organisation_ids[]" multiple size="5">
							<?php
							$law_linked_orgs = array_map( 'intval', law_event_meta( $law_id, '_law_organisation_ids' ) );
							foreach ( get_posts( array( 'post_type' => 'organisation', 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $law_org ) :
								?>
								<option value="<?php echo esc_attr( (string) $law_org->ID ); ?>" <?php selected( in_array( $law_org->ID, $law_linked_orgs, true ) ); ?>><?php echo esc_html( $law_org->post_title ); ?></option>
							<?php endforeach; ?>
						</select></p>

					<?php
					$law_fee_override = (bool) law_event_meta( $law_id, '_law_fee_override' );
					$law_fee_locked   = law_event_fee_override_locked( $law_id );

					// Read-only from approval onwards: law_event_snapshot_fee() froze the
					// fee and the invoice was raised from that snapshot, and nothing
					// recalculates it, so an editable control here would take a change,
					// report success and leave the invoice untouched. A genuine
					// post-approval fee change is a wp-admin job (the link below), where
					// the snapshot is re-taken. The handler refuses the write too.
					if ( $law_fee_locked ) :
						?>
					<div class="law-form-field">
						<strong>Host fee override</strong><br>
						<?php
						if ( $law_fee_override ) {
							echo esc_html( 'Applied: £' . number_format( (float) law_event_meta( $law_id, '_law_fee_override_amount' ), 2 ) );
						} else {
							echo esc_html(
								sprintf(
									'Not applied, so the %s tier price stands.',
									law_event_tier_label( (string) law_event_meta( $law_id, '_law_fee_tier' ) )
								)
							);
						}
						?>
						<br><small>The fee was snapshotted when this event was approved and the invoice raised from it, so it cannot be changed here any more. To change it, use "Full editing in wp-admin" below, then void the open invoice in Stripe and raise a new one.</small>
					</div>
					<?php else : ?>
					<div class="law-form-field">
						<div class="law-choices">
							<label><input type="checkbox" id="law-dash-override" name="law_fee_override" value="1" <?php checked( $law_fee_override ); ?>> Override the host fee</label>
						</div>
					</div>

					<?php
					// Legacy parity: field 81 (Discounted fee) on form 2 (Event > submit an
					// event) was shown only when field 87 (Override fee) was ticked. The
					// hidden attribute is rendered here, not applied by JS on load, so there
					// is no flash and no-JS committee members with the box already ticked
					// still see the amount. The input is never disabled: the handler keys off
					// isset( $_POST['law_fee_override_amount'] ) to decide whether to touch
					// the override at all. A ticked box with an empty amount is refused
					// server-side rather than read as £0.00, so a blank can never waive a
					// fee by accident.
					?>
					<p class="law-form-field" id="law-dash-amount-field" data-law-toggle-for="law-dash-override"<?php echo $law_fee_override ? '' : ' hidden'; ?>><label for="law-dash-amount">New host fee (£)<br><small>Enter the agreed fee in pounds, without the symbol. Type 0 to waive the fee entirely.</small></label>
						<input type="number" id="law-dash-amount" name="law_fee_override_amount" step="0.01" min="0" value="<?php echo esc_attr( (string) law_event_meta( $law_id, '_law_fee_override_amount' ) ); ?>"></p>
					<?php endif; ?>

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
					// slot, assignee, classification switches, linked organisations,
					// fee override and private note all still post with the action, and
					// each carries the submit button that actually fires it.
					// Their note fields ship disabled and are enabled by JS only while
					// their own modal is open.
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
		$law_run_by   = sanitize_key( $_GET['law_run_by'] ?? '' );
		$law_agenda   = sanitize_key( $_GET['law_agenda'] ?? '' );
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

						<?php
						// Two selects, not one mixing the axes: "run by LAW" and "has an
						// agenda" are not mutually exclusive, and a single select would
						// make "which of our own events have an agenda?" unanswerable.
						//
						// Selects, never checkboxes: calendar-filters.js reads field.value
						// for every named input regardless of checked, so a checkbox here
						// would filter permanently once rendered.
						?>
						<p class="law-cal-filter-form__field">
							<label class="show-for-sr" for="law-dash-run-by"><?php esc_html_e( 'Run by', 'law' ); ?></label>
							<select id="law-dash-run-by" name="law_run_by">
								<option value=""><?php esc_html_e( 'Run by anyone', 'law' ); ?></option>
								<option value="law" <?php selected( $law_run_by, 'law' ); ?>><?php esc_html_e( 'Run by LAW', 'law' ); ?></option>
								<option value="host" <?php selected( $law_run_by, 'host' ); ?>><?php esc_html_e( 'Run by a host', 'law' ); ?></option>
							</select>
						</p>

						<p class="law-cal-filter-form__field">
							<label class="show-for-sr" for="law-dash-agenda"><?php esc_html_e( 'Session agenda', 'law' ); ?></label>
							<select id="law-dash-agenda" name="law_agenda">
								<option value=""><?php esc_html_e( 'Any agenda', 'law' ); ?></option>
								<option value="yes" <?php selected( $law_agenda, 'yes' ); ?>><?php esc_html_e( 'With an agenda', 'law' ); ?></option>
								<option value="no" <?php selected( $law_agenda, 'no' ); ?>><?php esc_html_e( 'Without an agenda', 'law' ); ?></option>
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
			$law_export_args = array_filter( array( 'law_kw' => $law_kw, 'law_status' => $law_current, 'law_run_by' => $law_run_by, 'law_agenda' => $law_agenda ) );
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
