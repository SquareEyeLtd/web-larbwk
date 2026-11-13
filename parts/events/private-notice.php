<?php
/**
 * The private link at the top of a private event's committee screen: the
 * Committee controls view of a hosted event and the receptions editor (Denis,
 * 13 November 2026). One part, so the two say the same thing.
 *
 * Live, it says the event is off the programme and gives the link. Not live
 * yet (a reception draft, a hosted event not yet approved, or a disabled one),
 * it says so first, then gives the link it will open from.
 *
 * Args: event_id, class (extra notice classes, e.g. is-warning on a white page).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_pn_id = absint( $args['event_id'] ?? 0 );
if ( ! $law_pn_id || ! law_event_is_private( $law_pn_id ) ) {
	return;
}
$law_pn_url = law_event_private_url( $law_pn_id );
if ( '' === $law_pn_url ) {
	return;
}

$law_pn_reception = function_exists( 'law_reception_is' ) && law_reception_is( $law_pn_id );
if ( law_event_is_publicly_listed( $law_pn_id ) ) {
	$law_pn_text = $law_pn_reception
		? __( 'This reception is private: it is off the programme, and its page opens only from this link:', 'law' )
		: __( 'This event is private: it is off the programme, and its page opens only from this link:', 'law' );
} else {
	$law_pn_text = $law_pn_reception
		? __( 'This reception is a draft and not available yet. It is private, so once published it opens only from this link:', 'law' )
		: __( 'This event is not available yet. It is private, so once it is live it opens only from this link:', 'law' );
}
?>
<div class="law-form-notice law-private-notice <?php echo esc_attr( (string) ( $args['class'] ?? '' ) ); ?>" role="status">
	<p><?php echo esc_html( $law_pn_text ); ?></p>
	<?php // Its own line: a URL inline in a sentence wraps mid-address. ?>
	<p><code><?php echo esc_html( $law_pn_url ); ?></code></p>
</div>
