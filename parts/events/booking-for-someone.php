<?php
/**
 * "Who is this place for? Myself / Someone else" on the flagship
 * registration and the reception checkout and waitlist, free receptions
 * included (BOOK_ON_BEHALF.md §3.2). Sits directly after the event summary, before the
 * price, the code, the consent and the terms, because the answer changes what
 * the rest of the form is about.
 *
 * Two sibling fieldsets, never one inside the other, so no field is announced
 * under two legends. "Someone else" reveals the attendee's details through
 * law-toggles.js; while hidden they are disabled (data-law-toggle-disable), so
 * they neither post nor hold up the submit, and anything typed survives a
 * change of mind. Without JavaScript both show, and the server ignores the
 * details when Myself is chosen.
 *
 * The details are the shared attendee part (attendee-details-fields.php),
 * posting law_attendees[0][…] and the flat profile names, which is what
 * law_booking_paid_choice_from_request() reads.
 *
 * Args:
 *   context     'modal' | 'inline'.
 *   id_prefix   Unique per copy on the page.
 *   holds_place The viewer already has a place here: Myself is shown disabled
 *               with the reason, and Someone else is chosen for them.
 *   others_full Why the viewer can book for nobody else here (a free
 *               reception's cap, law_reception_on_behalf_free_cap()), or ''.
 *               Someone else is shown disabled with it, before anything is
 *               typed, and the details stay closed.
 *   state       A refused no-JS submission to put back: for, values, profile,
 *               field.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_bfs_inline  = 'inline' === ( $args['context'] ?? 'modal' );
$law_bfs_prefix  = (string) ( $args['id_prefix'] ?? 'law-for' );
$law_bfs_holds   = ! empty( $args['holds_place'] );
$law_bfs_full    = (string) ( $args['others_full'] ?? '' );
$law_bfs_state   = (array) ( $args['state'] ?? array() );
$law_bfs_other   = '' === $law_bfs_full && ( $law_bfs_holds || 'other' === ( $law_bfs_state['for'] ?? '' ) );
$law_bfs_details = $law_bfs_prefix . '-details';
// Hidden up front only where a script is certain to be running (a fetched
// dialog); the inline form may be read without one, so it starts open and the
// script closes it. Details nobody can send are closed (and disabled) for all.
$law_bfs_hide    = '' !== $law_bfs_full || ( ! $law_bfs_other && ! $law_bfs_inline );
?>

<fieldset class="law-booking-fieldset law-booking-for">
	<legend><?php esc_html_e( 'Who is this place for?', 'law' ); ?></legend>
	<?php
	// Above the choices and tied to the one that can be made: a keyboard user
	// tabbing in lands on Someone else (Myself is disabled and skipped), so
	// the reason is announced with it (WCAG 1.3.1). When neither can be made,
	// each disabled choice carries its own reason.
	$law_bfs_hint      = $law_bfs_prefix . '-holds';
	$law_bfs_full_hint = $law_bfs_prefix . '-full';
	?>
	<?php if ( $law_bfs_holds ) : ?>
		<p class="law-form-hint" id="<?php echo esc_attr( $law_bfs_hint ); ?>">
			<?php
			echo esc_html(
				'' === $law_bfs_full
					? __( 'You already have a place, so this one is for someone else.', 'law' )
					: __( 'You already have a place here.', 'law' )
			);
			?>
		</p>
	<?php endif; ?>
	<?php if ( '' !== $law_bfs_full ) : ?>
		<p class="law-form-hint" id="<?php echo esc_attr( $law_bfs_full_hint ); ?>"><?php echo esc_html( $law_bfs_full ); ?></p>
	<?php endif; ?>
	<div class="law-choices">
		<label>
			<input type="radio" name="law_for" value="self" <?php checked( ! $law_bfs_other && ! $law_bfs_holds ); ?> <?php disabled( $law_bfs_holds ); ?><?php echo $law_bfs_holds && '' !== $law_bfs_full ? ' aria-describedby="' . esc_attr( $law_bfs_hint ) . '"' : ''; ?>>
			<?php esc_html_e( 'Myself', 'law' ); ?>
		</label>
		<label>
			<input type="radio" name="law_for" value="other" data-law-toggles="<?php echo esc_attr( $law_bfs_details ); ?>" <?php checked( $law_bfs_other ); ?> <?php disabled( '' !== $law_bfs_full ); ?><?php echo '' !== $law_bfs_full ? ' aria-describedby="' . esc_attr( $law_bfs_full_hint ) . '"' : ( $law_bfs_holds ? ' aria-describedby="' . esc_attr( $law_bfs_hint ) . '"' : '' ); ?>>
			<?php esc_html_e( 'Someone else', 'law' ); ?>
		</label>
	</div>
</fieldset>

<fieldset class="law-booking-fieldset law-booking-for__details" id="<?php echo esc_attr( $law_bfs_details ); ?>" data-law-toggle-disable<?php echo $law_bfs_hide ? ' hidden' : ''; ?><?php disabled( '' !== $law_bfs_full ); ?>>
	<legend><?php esc_html_e( 'Their details', 'law' ); ?></legend>
	<p class="law-form-hint"><?php esc_html_e( 'We will email them an invitation to see their place.', 'law' ); ?></p>
	<?php if ( $law_bfs_inline ) : ?>
		<noscript><p class="law-form-hint"><?php esc_html_e( 'Only needed if you chose Someone else.', 'law' ); ?></p></noscript>
	<?php endif; ?>
	<?php
	get_template_part(
		'parts/events/attendee-details-fields',
		null,
		array(
			'id_prefix'        => $law_bfs_prefix,
			'values'           => (array) ( $law_bfs_state['values'] ?? array() ),
			'invalid'          => (string) ( $law_bfs_state['field'] ?? '' ),
			'profile'          => (array) ( $law_bfs_state['profile'] ?? array() ),
			'country_required' => true,
			'show_other'       => $law_bfs_inline,
		)
	);
	?>
</fieldset>
