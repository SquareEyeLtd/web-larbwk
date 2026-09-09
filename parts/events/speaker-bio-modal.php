<?php
/**
 * One speaker's full biography in a dialog, opened by the "Read full bio"
 * button on a speaker card (parts/events/speaker-card.php).
 *
 * Like parts/events/booking-modal.php, this renders the shared .law-modal
 * skeleton itself rather than going through parts/layout/modal.php, whose args
 * are confirm-dialog shaped: that part wraps each copy string in a <p>, which
 * would nest paragraphs inside paragraphs once the biography goes through
 * wpautop(), and it has no slot for the photo the dialog leads with.
 * assets/js/law-modal.js supplies open/close, Escape and the focus trap off
 * the shared classes, and assets/css/law-modal.css the styling.
 *
 * Hidden without JavaScript: the card's <details> block is the no-JS path.
 *
 * Use with get_template_part( 'parts/events/speaker-bio-modal', null, $args ):
 *   id      (string) The dialog's element id. Required.
 *   speaker (array)  A law_speaker_card() row. Required.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_bm_id      = isset( $args['id'] ) ? trim( (string) $args['id'] ) : '';
$law_bm_speaker = isset( $args['speaker'] ) && is_array( $args['speaker'] ) ? $args['speaker'] : array();
$law_bm_name    = trim( (string) ( $law_bm_speaker['name'] ?? '' ) );
if ( '' === $law_bm_id || '' === $law_bm_name ) {
	return;
}

law_modal_enqueue();

$law_bm_tag   = law_speaker_role_display( (string) ( $law_bm_speaker['role'] ?? '' ) );
$law_bm_title = implode( ', ', array_filter( array( (string) ( $law_bm_speaker['job_title'] ?? '' ), (string) ( $law_bm_speaker['organisation'] ?? '' ) ) ) );
$law_bm_bio   = trim( (string) ( $law_bm_speaker['bio'] ?? '' ) );
?>
<div class="law-modal law-modal--speaker" id="<?php echo esc_attr( $law_bm_id ); ?>" hidden>
	<div class="law-modal__overlay" data-law-modal-close></div>
	<?php // tabindex="-1" so law-modal.js can move focus into a dialog with no field. ?>
	<div class="law-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $law_bm_id ); ?>-title" tabindex="-1">
		<button type="button" class="law-modal__close" data-law-modal-close aria-label="<?php esc_attr_e( 'Close', 'law' ); ?>">&times;</button>
		<div class="law-modal__media">
			<span class="law-modal__photo">
				<?php if ( ! empty( $law_bm_speaker['photo'] ) ) : ?>
					<img src="<?php echo esc_url( (string) $law_bm_speaker['photo'] ); ?>" alt="" loading="lazy">
				<?php else : ?>
					<span class="law-modal__initials" aria-hidden="true"><?php echo esc_html( law_calendar_name_initials( $law_bm_name ) ); ?></span>
				<?php endif; ?>
			</span>
			<span class="law-modal__heading">
				<?php // The role sits inside the heading so the dialog's accessible name carries it, as the card's does. ?>
				<h2 class="law-modal__title" id="<?php echo esc_attr( $law_bm_id ); ?>-title"><?php echo esc_html( $law_bm_name ); ?><?php if ( '' !== $law_bm_tag ) : ?> <span class="law-modal__tag">(<?php echo esc_html( $law_bm_tag ); ?>)</span><?php endif; ?></h2>
				<?php if ( '' !== $law_bm_title ) : ?>
					<p class="law-modal__subtitle"><?php echo esc_html( $law_bm_title ); ?></p>
				<?php endif; ?>
			</span>
		</div>
		<?php
		// The biography is the dialog's only scrolling region (law-modal.css), so
		// the close button stays on screen however long the bio runs. A scrollable
		// region needs to be reachable by keyboard, hence tabindex="0" and a role
		// and label to announce it with.
		?>
		<div class="law-modal__bio" tabindex="0" role="group" aria-label="<?php esc_attr_e( 'Biography', 'law' ); ?>">
			<?php echo law_rich_text_render( $law_bm_bio ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses'd against the rich-text allowlist. ?>
		</div>
		<?php if ( ! empty( $law_bm_speaker['url'] ) ) : ?>
			<?php
			// A new tab, so following the link does not throw away the event
			// listing the reader opened this dialog from. The screen-reader hint
			// matches templates/speaker.php's outbound link.
			?>
			<p class="law-modal__copy">
				<a href="<?php echo esc_url( (string) $law_bm_speaker['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View speaker profile', 'law' ); ?><span class="show-for-sr"> <?php esc_html_e( '(opens in a new tab)', 'law' ); ?></span></a>
			</p>
		<?php endif; ?>
		<p class="law-modal__actions">
			<button type="button" class="button second" data-law-modal-close><?php esc_html_e( 'Close', 'law' ); ?></button>
		</p>
	</div>
</div>
