<?php
/**
 * Who someone is, for every form where one person books or registers another:
 * full name, email, organisation and job title, then the country,
 * accessibility and dietary set from parts/events/attendee-profile-fields.php.
 *
 * ONE copy. The four identity fields were hand-written in five places, and
 * three of those were these whole blocks: "Register an attendee" on the
 * per-event bookings list, the flagship's "Add an attendee without payment"
 * and its substitution dialog. Booking a paid place for someone else
 * (BOOK_ON_BEHALF.md §3.2) would have been a fourth.
 *
 * Labels WRAP their inputs rather than using for/id: the same fields can be
 * printed twice on one page (a dialog and its no-JS fallback), and ids would
 * collide, silently breaking both labels rather than one. Every input carries
 * data-law-field, which is what booking-form.js marks a refusal against and
 * what the substitution dialog's script fills.
 *
 * Args:
 *   id_prefix        Required, unique per copy on the page (the profile
 *                    part's "Other" boxes are shown and hidden by id).
 *   name_pattern     sprintf pattern for the four POST names. Default
 *                    'law_attendees[0][%s]', the row
 *                    law_booking_clean_additional_rows() reads, wrapped as a
 *                    one-row repeater so booking-form.js markField() finds a
 *                    row-keyed refusal; '%s' for the flagship's two committee
 *                    dialogs, whose handlers read flat names.
 *   org_required     Organisation and job title required (default true).
 *   values           Prefill for the four fields (the no-JS refusal path).
 *   invalid          The identity field a refusal named, to mark it.
 *   email_hint       One line under the email field; '' for none.
 *   profile          Prefill for the profile part, law_profile_values() shape.
 *   country_required Passed to the profile part.
 *   show_other       Passed to the profile part (the <noscript> copy).
 *   note             Passed to the profile part.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_ad_prefix  = (string) ( $args['id_prefix'] ?? 'law-attendee' );
$law_ad_pattern = (string) ( $args['name_pattern'] ?? 'law_attendees[0][%s]' );
$law_ad_rows    = '%s' !== $law_ad_pattern;
$law_ad_org_req = ! array_key_exists( 'org_required', $args ) || ! empty( $args['org_required'] );
$law_ad_values  = (array) ( $args['values'] ?? array() );
$law_ad_invalid = (string) ( $args['invalid'] ?? '' );
$law_ad_hint    = (string) ( $args['email_hint'] ?? '' );

$law_ad_field = static function ( $key, $label, $type, $required, $hint = '' ) use ( $law_ad_pattern, $law_ad_values, $law_ad_invalid ) {
	?>
	<p class="law-form-field">
		<label<?php echo $law_ad_invalid === $key ? ' class="is-invalid"' : ''; ?>>
			<?php echo esc_html( $required ? $label . ' *' : $label ); ?>
			<input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( sprintf( $law_ad_pattern, $key ) ); ?>" data-law-field="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) ( $law_ad_values[ $key ] ?? '' ) ); ?>" autocomplete="off"<?php echo $required ? ' aria-required="true"' : ''; ?><?php echo $law_ad_invalid === $key ? ' aria-invalid="true"' : ''; ?>>
		</label>
		<?php if ( '' !== $hint ) : ?>
			<span class="law-form-hint"><?php echo esc_html( $hint ); ?></span>
		<?php endif; ?>
	</p>
	<?php
};
?>

<?php if ( $law_ad_rows ) : ?>
<div class="law-rows" data-law-booking-rows="law_attendees" data-law-max="1">
	<div class="law-row law-row--bare">
<?php endif; ?>

		<div class="law-row-grid">
			<?php
			$law_ad_field( 'name', __( 'Full name', 'law' ), 'text', true );
			$law_ad_field( 'email', __( 'Email', 'law' ), 'email', true, $law_ad_hint );
			?>
		</div>

		<div class="law-row-grid">
			<?php
			$law_ad_field( 'organisation', __( 'Organisation', 'law' ), 'text', $law_ad_org_req );
			$law_ad_field( 'job_title', __( 'Job title', 'law' ), 'text', $law_ad_org_req );
			?>
		</div>

<?php if ( $law_ad_rows ) : ?>
	</div>
</div>
<?php endif; ?>

<?php
get_template_part(
	'parts/events/attendee-profile-fields',
	null,
	array(
		'id_prefix'        => $law_ad_prefix,
		'values'           => (array) ( $args['profile'] ?? array() ),
		'show_other'       => ! empty( $args['show_other'] ),
		'country_required' => ! empty( $args['country_required'] ),
		'note'             => (string) ( $args['note'] ?? '' ),
	)
);
