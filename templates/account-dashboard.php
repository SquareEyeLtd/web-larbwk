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
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container law-dashboard">

	<?php if ( ! $law_can ) : ?>
		<p><?php esc_html_e( 'This dashboard is for the LAW committee.', 'law' ); ?></p>

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
					<dt>Slot</dt><dd><?php echo esc_html( (string) law_event_meta( $law_id, '_law_slot_label' ) ?: 'Not confirmed' ); ?></dd>
					<dt>Preferred slots</dt><dd><?php echo esc_html( implode( '; ', law_event_meta( $law_id, '_law_preferred_slots' ) ) ?: '—' ); ?></dd>
					<dt>Venue</dt><dd><?php echo esc_html( (string) law_event_meta( $law_id, '_law_venue' ) ?: '—' ); ?></dd>
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
						<span class="law-form-label" style="font-weight:600">Event category</span>
						<?php
						$law_current_cats = law_events_post_term_names( $law_id, 'law_event_category' );
						$law_cat_terms    = get_terms( array( 'taxonomy' => 'law_event_category', 'hide_empty' => false ) );
						foreach ( is_wp_error( $law_cat_terms ) ? array() : $law_cat_terms as $law_term ) :
							?>
							<label style="display:block"><input type="checkbox" name="law_event_category[]" value="<?php echo esc_attr( $law_term->name ); ?>" <?php checked( in_array( $law_term->name, $law_current_cats, true ) ); ?>> <?php echo esc_html( $law_term->name ); ?></label>
						<?php endforeach; ?>
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

					<p class="law-form-field"><label><input type="checkbox" name="law_fee_override" value="1" <?php checked( (bool) law_event_meta( $law_id, '_law_fee_override' ) ); ?>> Override fee</label></p>
					<p class="law-form-field"><label for="law-dash-amount">Override amount (£)</label>
						<input type="number" id="law-dash-amount" name="law_fee_override_amount" step="0.01" min="0" value="<?php echo esc_attr( (string) law_event_meta( $law_id, '_law_fee_override_amount' ) ); ?>"></p>

					<p class="law-form-field"><label for="law-dash-note">Comment / reason<br><small>(required for Send back and Reject; the host sees it)</small></label>
						<textarea id="law-dash-note" name="law_note" rows="3"></textarea></p>

					<p class="law-form-field"><label for="law-dash-private-note">Private note<br><small>(committee only, saved to the activity log)</small></label>
						<textarea id="law-dash-private-note" name="law_private_note" rows="2"></textarea></p>

					<p class="law-dashboard__buttons">
						<button type="submit" name="law_action" value="" class="button">Save changes</button>
						<button type="submit" name="law_action" value="approve" class="button orange">Approve</button>
						<button type="submit" name="law_action" value="send_back" class="button">Send back</button>
						<button type="submit" name="law_action" value="reject" class="button alert">Reject</button>
						<?php if ( 'law-approved' === $law_detail->post_status ) : ?>
							<button type="submit" name="law_action" value="mark_paid" class="button">Mark paid &amp; confirm</button>
						<?php endif; ?>
					</p>

					<p><a href="<?php echo esc_url( get_edit_post_link( $law_id ) ); ?>">Full editing in wp-admin →</a></p>
				</form>
			</div>
		</div>

	<?php else : ?>
		<?php
		$law_counts  = law_committee_status_counts();
		$law_current = sanitize_key( $_GET['law_status'] ?? '' );
		$law_kw      = sanitize_text_field( wp_unslash( $_GET['law_kw'] ?? '' ) );
		$law_events  = law_committee_events();
		?>
		<div class="law-dashboard__filters">
			<nav class="law-dashboard__chips" aria-label="Filter by status">
				<a class="<?php echo '' === $law_current ? 'is-active' : ''; ?>" href="<?php echo esc_url( get_permalink() ); ?>">All</a>
				<?php foreach ( law_event_statuses() as $law_status_key => $law_config ) :
					if ( 'law-draft' === $law_status_key ) { continue; } ?>
					<a class="<?php echo $law_current === $law_status_key ? 'is-active' : ''; ?>"
						href="<?php echo esc_url( add_query_arg( 'law_status', $law_status_key, get_permalink() ) ); ?>">
						<?php echo esc_html( $law_config['label'] ); ?> (<?php echo esc_html( (string) ( $law_counts[ $law_status_key ] ?? 0 ) ); ?>)</a>
				<?php endforeach; ?>
			</nav>
			<form method="get" class="law-dashboard__search">
				<?php if ( $law_current ) : ?><input type="hidden" name="law_status" value="<?php echo esc_attr( $law_current ); ?>"><?php endif; ?>
				<label class="screen-reader-text" for="law-dash-kw">Search events</label>
				<input type="search" id="law-dash-kw" name="law_kw" value="<?php echo esc_attr( $law_kw ); ?>" placeholder="Search events…">
				<button type="submit" class="button">Search</button>
			</form>
		</div>

		<?php if ( ! $law_events ) : ?>
			<p class="law-cal__empty">No events match.</p>
		<?php else : ?>
			<table class="law-dashboard__table">
				<thead><tr><th>Event</th><th>Host</th><th>Slot</th><th>Status</th><th>Payment</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $law_events as $law_row ) :
					$law_row_status = law_event_status_label( $law_row );
					$law_row_author = get_user_by( 'id', (int) $law_row->post_author );
					?>
					<tr>
						<td><strong><a href="<?php echo esc_url( add_query_arg( 'event', $law_row->ID, get_permalink() ) ); ?>"><?php echo esc_html( $law_row->post_title ); ?></a></strong><br>
							<code><?php echo esc_html( (string) law_event_meta( $law_row->ID, '_law_reference' ) ); ?></code></td>
						<td><?php echo esc_html( $law_row_author ? $law_row_author->display_name : '—' ); ?></td>
						<td><?php echo esc_html( (string) law_event_meta( $law_row->ID, '_law_slot_label' ) ?: '—' ); ?></td>
						<td><span class="law-cal-card__badge law-cal-card__badge--<?php echo esc_attr( law_calendar_status_slug( $law_row_status ) ); ?>"><?php echo esc_html( $law_row_status ); ?></span></td>
						<td><?php echo esc_html( ucfirst( (string) law_event_meta( $law_row->ID, '_law_payment_status' ) ) ?: '—' ); ?></td>
						<td><a class="button" href="<?php echo esc_url( add_query_arg( 'event', $law_row->ID, get_permalink() ) ); ?>">Review</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>

	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
