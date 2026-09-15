<?php
/**
 * The committee's edit form for ONE reception, for
 * templates/account-dashboard-receptions.php (?law_reception=<id|new>).
 *
 * The SUBMIT-AN-EVENT form's shape (Denis, 14 September 2026): a plain
 * .law-event-form on the filled navy hero, not the --light variant the
 * committee's white dashboards use. A reception IS an event, so editing one
 * should look like editing one; the template supplies the hero, this supplies
 * the fields. The .law-row-grid vocabulary is flagship-manage.php's, because
 * that is the other screen where LAW edits its own event, and two form idioms
 * for one job would drift.
 *
 * Invitation only DISABLES the price and places controls rather than hiding
 * them (house rule: preview and read-only surfaces disable, they never hide),
 * and the stored values are kept: a reception that was on sale and is now
 * invitation-only must not silently lose its price.
 *
 * Args: event_id (0 for a new reception).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_rm_id     = absint( $args['event_id'] ?? 0 );
$law_rm_state  = law_receptions_dashboard_state();
$law_rm_errors = (array) $law_rm_state['errors'];
$law_rm_values = law_reception_form_values( $law_rm_id );

// What a refused save had typed, so nothing is thrown away on the round trip.
// Only the keys it actually carried, so a partial POST cannot blank the rest.
foreach ( (array) $law_rm_state['input'] as $law_rm_key => $law_rm_typed ) {
	if ( array_key_exists( $law_rm_key, $law_rm_values ) ) {
		$law_rm_values[ $law_rm_key ] = $law_rm_typed;
	}
}

$law_rm_new    = ! $law_rm_id;
// The address it WILL have, not get_permalink()'s -- which on a draft hands
// back ?post_type=law_event&p=995, neither the address it will get nor
// something anybody wants to read (law_events_public_url(), source.php).
$law_rm_public = $law_rm_id ? (string) law_events_public_url( $law_rm_id ) : '';
$law_rm_taken  = $law_rm_id ? law_event_attendee_total( $law_rm_id ) : 0;
?>

<?php if ( $law_rm_errors ) : ?>
	<div class="law-form-notice is-error" role="alert">
		<?php foreach ( $law_rm_errors as $law_rm_error ) : ?>
			<p><?php echo esc_html( $law_rm_error ); ?></p>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php if ( ! $law_rm_new && ! $law_rm_values['show'] ) : ?>
	<div class="law-form-notice" role="status">
		<p><?php esc_html_e( 'This reception is not on the programme yet. Tick "Show on the programme" below and save to publish it at:', 'law' ); ?></p>
		<?php // Its own line: a URL inline in a sentence wraps mid-address. ?>
		<p><code><?php echo esc_html( $law_rm_public ); ?></code></p>
	</div>
<?php endif; ?>

<form class="law-event-form law-booking-form law-reception-form" method="post"
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="law_reception_manage">
	<input type="hidden" name="law_reception[event_id]" value="<?php echo esc_attr( (string) $law_rm_id ); ?>">
	<?php
	// The checkbox sentinel rides with the first fields: PHP's
	// max_input_vars truncates a long POST from the END, and without it
	// a truncated save would read as "the committee unticked
	// everything" and take the reception off the programme.
	?>
	<input type="hidden" name="law_reception[present]" value="1">
	<?php wp_nonce_field( 'law_reception_manage' ); ?>
	<?php law_events_honeypot_field(); ?>

	<fieldset>
		<legend><?php esc_html_e( 'The reception', 'law' ); ?></legend>

		<?php
		// First, because it is the decision the whole screen hangs off:
		// everything below is either on the programme or it is not.
		?>
		<p class="law-form-field">
			<label>
				<input type="checkbox" name="law_reception[show]" value="1" <?php checked( ! empty( $law_rm_values['show'] ) ); ?>>
				<?php esc_html_e( 'Show on the programme', 'law' ); ?>
			</label>
			<span class="law-form-hint"><?php esc_html_e( 'Until this is ticked the reception is a draft: its page is not public and it does not appear in the programme or the calendar.', 'law' ); ?></span>
		</p>

		<p class="law-form-field">
			<label for="law-rm-title"><?php esc_html_e( 'Title *', 'law' ); ?></label>
			<input type="text" id="law-rm-title" name="law_reception[title]"
				value="<?php echo esc_attr( (string) $law_rm_values['title'] ); ?>" required>
		</p>

		<div class="law-form-field">
			<label for="law-rm-description"><?php esc_html_e( 'Description', 'law' ); ?></label>
			<?php
			law_rich_text_field(
				array(
					'name'  => 'law_reception[description]',
					'id'    => 'law-rm-description',
					'value' => (string) $law_rm_values['description'],
					'rows'  => 8,
					'label' => __( 'Reception description', 'law' ),
				)
			);
			?>
		</div>

		<?php
		// Three across, one row: a date and the two times it brackets are one
		// decision, and stacking them two-up put "Ends" on a line of its own
		// under a hint belonging to "Date".
		?>
		<div class="law-row-grid law-row-grid--three">
			<p class="law-form-field">
				<label for="law-rm-date"><?php esc_html_e( 'Date', 'law' ); ?></label>
				<input type="date" id="law-rm-date" name="law_reception[date]"
					value="<?php echo esc_attr( (string) $law_rm_values['date'] ); ?>">
				<span class="law-form-hint"><?php esc_html_e( 'It must fall inside the programme week for the reception to sit under a day.', 'law' ); ?></span>
			</p>

			<p class="law-form-field">
				<label for="law-rm-start"><?php esc_html_e( 'Starts', 'law' ); ?></label>
				<input type="time" id="law-rm-start" name="law_reception[start]"
					value="<?php echo esc_attr( (string) $law_rm_values['start'] ); ?>">
			</p>

			<p class="law-form-field">
				<label for="law-rm-end"><?php esc_html_e( 'Ends', 'law' ); ?></label>
				<input type="time" id="law-rm-end" name="law_reception[end]"
					value="<?php echo esc_attr( (string) $law_rm_values['end'] ); ?>">
			</p>
		</div>

		<p class="law-form-field">
			<label for="law-rm-venue"><?php esc_html_e( 'Venue (name and address)', 'law' ); ?></label>
			<input type="text" id="law-rm-venue" name="law_reception[venue]"
				value="<?php echo esc_attr( (string) $law_rm_values['venue'] ); ?>">
			<span class="law-form-hint"><?php esc_html_e( 'A real address is mapped on the event page. A placeholder such as "tbc" shows the text without a map.', 'law' ); ?></span>
		</p>
	</fieldset>

	<fieldset>
		<legend><?php esc_html_e( 'Places and price', 'law' ); ?></legend>

		<?php
		// Invitation only disables the two controls below rather than
		// hiding them, so the committee can see what the reception
		// would charge if it ever took bookings. Disabled inputs post
		// nothing, and the saver leaves a key it was not sent alone, so
		// the stored values survive untouched.
		$law_rm_locked = ! empty( $law_rm_values['invitation'] );
		?>
		<p class="law-form-field">
			<label>
				<input type="checkbox" name="law_reception[invitation]" value="1" <?php checked( $law_rm_locked ); ?>>
				<?php esc_html_e( 'Invitation only', 'law' ); ?>
			</label>
			<span class="law-form-hint"><?php esc_html_e( 'LAW invites people to this reception itself. The page and the calendar still show it, with a note where the booking button would be.', 'law' ); ?></span>
		</p>

		<div class="law-row-grid">
			<p class="law-form-field">
				<label for="law-rm-places"><?php esc_html_e( 'Places available', 'law' ); ?></label>
				<input type="number" id="law-rm-places" name="law_reception[places]" min="0" step="1"
					value="<?php echo esc_attr( (string) (int) $law_rm_values['places'] ); ?>"
					<?php disabled( $law_rm_locked ); ?>>
				<span class="law-form-hint">
					<?php esc_html_e( 'Zero means places have not been released yet. Once they run out the page offers the waitlist.', 'law' ); ?>
					<?php if ( $law_rm_taken > 0 ) : ?>
						<strong><?php echo esc_html( sprintf( __( '%d places are taken, so this cannot be set lower.', 'law' ), $law_rm_taken ) ); ?></strong>
					<?php endif; ?>
				</span>
			</p>

			<p class="law-form-field">
				<label for="law-rm-price"><?php esc_html_e( 'Price excluding VAT (£)', 'law' ); ?></label>
				<input type="text" inputmode="decimal" id="law-rm-price" name="law_reception[price]"
					value="<?php echo esc_attr( (string) $law_rm_values['price'] ); ?>"
					<?php disabled( $law_rm_locked ); ?>>
				<span class="law-form-hint"><?php esc_html_e( 'Zero means the reception is not on sale. VAT is added on top at the standard rate.', 'law' ); ?></span>
			</p>
		</div>

		<?php
		// One sentence saying what a delegate will actually be charged,
		// because "45.00 excluding VAT" is not a figure anybody checks
		// against a card statement.
		$law_rm_pence   = law_events_pounds_to_pence( (string) $law_rm_values['price'] );
		$law_rm_preview = ( null !== $law_rm_pence && $law_rm_pence > 0 )
			? sprintf( __( 'Attendees pay %s.', 'law' ), law_events_price_label( $law_rm_pence ) )
			: '';
		?>
		<?php if ( '' !== $law_rm_preview && ! $law_rm_locked ) : ?>
			<p class="law-form-field"><strong><?php echo esc_html( $law_rm_preview ); ?></strong></p>
		<?php endif; ?>

		<?php if ( $law_rm_locked ) : ?>
			<p class="law-form-hint"><?php esc_html_e( 'Invitation-only receptions take no bookings, so the places and price above are not in use. They are kept in case the reception goes on sale later.', 'law' ); ?></p>
		<?php endif; ?>

		<p class="law-form-field">
			<label>
				<input type="checkbox" name="law_reception[included]" value="1" <?php checked( ! empty( $law_rm_values['included'] ) ); ?>>
				<?php esc_html_e( 'Included with the flagship place', 'law' ); ?>
			</label>
			<span class="law-form-hint"><?php esc_html_e( 'A delegate with a confirmed flagship place can add this reception to their bookings at no cost, and is offered it when they register. They still get a place when the reception is full; the committee sizes the room.', 'law' ); ?></span>
		</p>
	</fieldset>

	<p class="law-form-buttons">
<a class="button second" href="<?php echo esc_url( law_receptions_dashboard_url() ); ?>"><?php esc_html_e( 'Cancel', 'law' ); ?></a>
<button type="submit" class="button orange" data-law-modal-busy="<?php esc_attr_e( 'Saving…', 'law' ); ?>">
	<?php echo esc_html( $law_rm_new ? __( 'Create the reception', 'law' ) : __( 'Save the reception', 'law' ) ); ?>
</button>
	</p>
</form>
