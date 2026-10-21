<?php
/**
 * The committee's Delete for one speaker: a one-button form behind the shared
 * confirm dialog (parts/layout/modal.php), on each Manage Speakers row and
 * under the edit view's buttons. It posts to law_speakers_dashboard_delete_handler().
 *
 * The dialog lists every event the speaker is on, because deleting the record
 * takes them off each one, and with them the role, organisation, job title,
 * photo and biography stored on that event's row (law_speaker_detach()). The
 * committee should read where before agreeing, not find out from a host.
 *
 * A .law-booking-form, so assets/js/booking-form.js posts it over fetch and the
 * dialog reports the outcome before the page reloads. Without JS the opener is
 * a plain submit, as every other confirm-dialog action in the module is.
 *
 * Args:
 * - speaker_id  int    The law_speaker post ID.
 * - name        string The display name.
 * - appearances array  law_speakers_dashboard_appearances() for the speaker.
 * - opener      string 'button' (the list's row action, the default) or
 *                      'text' (the edit view's quieter link under Save).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_sdel_id          = (int) ( $args['speaker_id'] ?? 0 );
$law_sdel_name        = (string) ( $args['name'] ?? '' );
$law_sdel_appearances = (array) ( $args['appearances'] ?? array() );
$law_sdel_opener      = 'text' === ( $args['opener'] ?? 'button' ) ? 'text' : 'button';
if ( ! $law_sdel_id ) {
	return;
}

$law_sdel_modal = 'law-modal-speaker-delete-' . $law_sdel_id;
$law_sdel_count = count( $law_sdel_appearances );

// One line per event: title, date and status. The status goes on every line,
// Confirmed included, because here it is the warning: a Confirmed event is the
// one whose public page changes.
$law_sdel_list = array();
$law_sdel_live = 0;
foreach ( $law_sdel_appearances as $law_sdel_appearance ) {
	$law_sdel_line = $law_sdel_appearance['event_title'];
	if ( '' !== $law_sdel_appearance['event_start'] ) {
		$law_sdel_line .= ', ' . date_i18n( 'D j M Y', strtotime( $law_sdel_appearance['event_start'] ) );
	}
	$law_sdel_list[] = $law_sdel_line . ' (' . law_event_status_label( $law_sdel_appearance['event_status'] ) . ')';
	if ( law_event_is_publicly_listed( (int) $law_sdel_appearance['event_id'] ) ) {
		$law_sdel_live++;
	}
}

// The lead is the paragraph booking-form.js replaces with the outcome, so
// everything that stops being true once the delete has happened lives in it;
// the restore note goes under the list, where it stays true afterwards.
if ( $law_sdel_count ) {
	$law_sdel_lead = sprintf(
		/* translators: 1: speaker's name, 2: number of events. */
		_n(
			'%1$s is on %2$s event. Deleting the record takes them off it, along with the role, organisation, job title, photo and biography entered for them there.',
			'%1$s is on %2$s events. Deleting the record takes them off every one, along with the role, organisation, job title, photo and biography entered for them on each.',
			$law_sdel_count,
			'law'
		),
		$law_sdel_name,
		number_format_i18n( $law_sdel_count )
	);
	if ( $law_sdel_live ) {
		$law_sdel_lead .= ' ' . __( 'Events already on the website change straight away.', 'law' );
	}
	$law_sdel_after = _n(
		'If this was a mistake, an administrator can restore the record from the wp-admin trash, which puts them back on this event too.',
		'If this was a mistake, an administrator can restore the record from the wp-admin trash, which puts them back on these events too.',
		$law_sdel_count,
		'law'
	);
} else {
	/* translators: %s: speaker's name. */
	$law_sdel_lead  = sprintf( __( '%s is not on any event, so deleting the record changes nothing else.', 'law' ), $law_sdel_name );
	$law_sdel_after = __( 'If this was a mistake, an administrator can restore the record from the wp-admin trash.', 'law' );
}
?>
<form class="law-booking-form law-speaker-delete law-speaker-delete--<?php echo esc_attr( $law_sdel_opener ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="law_speaker_delete">
	<input type="hidden" name="law_speaker_id" value="<?php echo esc_attr( (string) $law_sdel_id ); ?>">
	<?php wp_nonce_field( 'law_speaker_delete' ); ?>
	<?php law_events_honeypot_field(); ?>

	<?php if ( 'text' === $law_sdel_opener ) : ?>
		<?php
		// Under the button row and as plain underlined text, the rule the email
		// editors' Reset set (Denis, 22 September 2026): the rarest action on
		// the screen, and the only one that throws work away, should not
		// compete with Save for attention.
		?>
		<p class="law-form-buttons__aside">
			<button type="submit" class="law-text-button" data-law-modal-open="<?php echo esc_attr( $law_sdel_modal ); ?>">
				<?php esc_html_e( 'Delete this speaker', 'law' ); ?>
			</button>
		</p>
	<?php else : ?>
		<button type="submit" class="button alert hollow" data-law-modal-open="<?php echo esc_attr( $law_sdel_modal ); ?>">
			<?php esc_html_e( 'Delete', 'law' ); ?><span class="show-for-sr"> <?php echo esc_html( $law_sdel_name ); ?></span>
		</button>
	<?php endif; ?>

	<?php
	get_template_part(
		'parts/layout/modal',
		null,
		array(
			'id'      => $law_sdel_modal,
			/* translators: %s: speaker's name. */
			'title'   => sprintf( __( 'Delete %s?', 'law' ), $law_sdel_name ),
			'copy'    => $law_sdel_lead,
			'list'    => $law_sdel_list,
			'after'   => $law_sdel_after,
			'confirm' => array(
				'label' => __( 'Delete speaker', 'law' ),
				'class' => 'button alert',
				'busy'  => __( 'Deleting…', 'law' ),
			),
			// The default close label is "Cancel", which next to a delete can
			// read as the destructive one.
			'close'   => __( 'Keep the speaker', 'law' ),
		)
	);
	?>
</form>
