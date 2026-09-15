<?php
/**
 * The committee's "set the ticket type" dialog, for the Flagship bookings
 * dashboard (templates/account-dashboard-flagship-bookings.php).
 *
 * ONE dialog for the whole table. Every other confirm dialog on this screen is
 * per row, because Approve and Decline say different things about different
 * people and money; this one says the same thing about everybody, and the row
 * it is about is a hidden field. That matters here: Approve and Decline only
 * render on rows a decision can still be made on, but a ticket type can be set
 * on any row, so a dialog per row would mean one on EVERY row of a list that
 * can run to hundreds.
 *
 * assets/js/flagship-ticket-type.js fills in the booking, the delegate's name
 * and the current value from the pencil that was pressed, then opens it. There
 * is no fetch and so no skeleton: the dialog is already on the page and opens
 * in the same frame as the press.
 *
 * Rendered OUTSIDE #law-cal-events, like parts/events/flagship-add-attendee.php
 * and for the same reason: a filter change replaces that container wholesale
 * and would destroy a dialog living inside it.
 *
 * With JavaScript off this renders nothing anyone can reach (the dialog is
 * position:fixed and hidden, and every pencil ships `hidden`). That path is
 * covered instead by the <noscript> select in each cell, which posts this same
 * action — see law_flagship_ticket_type_cell().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! law_user_is_committee() ) {
	return;
}

$law_tt_id = law_flagship_ticket_type_modal_id();
?>
<?php
// The heading's wording lives HERE, not in the script: data-law-ticket-title
// is a printf template the script fills with the delegate's name. It cannot go
// through the dialog's `copy`, which is passed through wp_kses_post() and so
// would have any data-* hook stripped out of it.
?>
<form class="law-booking-form law-ticket-type__form" method="post"
	data-law-ticket-title="<?php echo esc_attr( __( 'Ticket type for %s', 'law' ) ); ?>"
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="law_flagship_ticket_type">
	<?php
	// Written by the script from the pencil's own data-law-ticket-id. It is
	// the only thing that changes between one use of this dialog and the
	// next, and the handler re-checks that it names a flagship booking.
	?>
	<input type="hidden" name="booking_id" value="" data-law-ticket-booking>
	<?php wp_nonce_field( 'law_flagship_ticket_type' ); ?>
	<?php law_events_honeypot_field(); ?>

	<?php
	get_template_part(
		'parts/layout/modal',
		null,
		array(
			'id'      => $law_tt_id,
			'title'   => __( 'Ticket type', 'law' ),
			'copy'    => array(
				__( 'This is for the committee\'s own records. The delegate is never shown it and it changes nothing about their place or their price.', 'law' ),
			),
			'field'   => array(
				'name'        => 'law_ticket_type',
				'type'        => 'select',
				'label'       => __( 'Ticket type', 'law' ),
				// The heading says it already, and this is the only control in
				// the dialog; the label stays as the select's accessible name.
				'label_hidden' => true,
				'options'     => law_booking_ticket_types(),
				'placeholder' => __( 'Not set', 'law' ),
			),
			'confirm' => array(
				'label' => __( 'Apply', 'law' ),
				'class' => 'button orange',
				'busy'  => __( 'Applying…', 'law' ),
			),
			'close'   => __( 'Cancel', 'law' ),
		)
	);
	?>
</form>
