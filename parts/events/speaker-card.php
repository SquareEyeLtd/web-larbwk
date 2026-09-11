<?php
/**
 * One speaker card on the single event view (parts/calendar-body.php), used
 * both by the event's own Speakers list and by each session panel so the two
 * cannot drift.
 *
 * The biography is the appearance's own (law_speaker_card() reads the event or
 * session row, falling back to the parent event's row and then to the speaker
 * post's editor content), shown as a short excerpt with a "Read full bio"
 * control when there is more to read. The role at this event (Speaker / Host /
 * Moderator, per appearance too) prints on its own line under the name, outside
 * the profile link so the link text stays the name alone.
 *
 * That control is progressive enhancement, on the modal component's own terms
 * (assets/js/law-modal.js): the button ships hidden and is revealed only when
 * the script finds its dialog, while the <details> block below it carries the
 * full text for anyone without JavaScript and is hidden by the same script.
 * The full biography therefore sits in the page either way, which keeps it
 * indexable now that the speaker profile no longer carries it.
 *
 * Use with get_template_part( 'parts/events/speaker-card', null, $args ):
 *   speaker (array) A law_speaker_card() row. Required.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_sc = isset( $args['speaker'] ) && is_array( $args['speaker'] ) ? $args['speaker'] : null;
if ( ! $law_sc || '' === trim( (string) ( $law_sc['name'] ?? '' ) ) ) {
	return;
}

$law_sc_name  = (string) $law_sc['name'];
$law_sc_tag   = law_speaker_role_display( (string) ( $law_sc['role'] ?? '' ) );
$law_sc_title = implode( ', ', array_filter( array( (string) ( $law_sc['job_title'] ?? '' ), (string) ( $law_sc['organisation'] ?? '' ) ) ) );
$law_sc_bio   = law_speaker_bio_summary( (string) ( $law_sc['bio'] ?? '' ) );

// The dialog only earns its place when the excerpt actually left something out;
// otherwise "Read full bio" would open the paragraph already on screen.
$law_sc_dialog = $law_sc_bio['trimmed'] ? law_speaker_dialog_register( $law_sc ) : '';
?>
<li class="law-cal-speakers__card">
	<div class="law-cal-speakers__head">
		<span class="law-cal-speakers__photo">
			<?php if ( ! empty( $law_sc['photo'] ) ) : ?>
				<?php // alt="", not the name: the link below states it, and a screen reader would otherwise read it twice. ?>
				<img src="<?php echo esc_url( (string) $law_sc['photo'] ); ?>" alt="" loading="lazy">
			<?php else : ?>
				<span class="law-cal-speakers__initials" aria-hidden="true"><?php echo esc_html( law_calendar_name_initials( $law_sc_name ) ); ?></span>
			<?php endif; ?>
		</span>
		<span class="law-cal-speakers__body">
			<span class="law-cal-speakers__name">
				<?php if ( ! empty( $law_sc['url'] ) ) : ?>
					<a href="<?php echo esc_url( (string) $law_sc['url'] ); ?>"><?php echo esc_html( $law_sc_name ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $law_sc_name ); ?>
				<?php endif; ?>
			</span>
			<?php if ( '' !== $law_sc_tag ) : ?>
				<span class="law-cal-speakers__tag"><?php echo esc_html( $law_sc_tag ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $law_sc_title ) : ?>
				<span class="law-cal-speakers__role"><?php echo esc_html( $law_sc_title ); ?></span>
			<?php endif; ?>
		</span>
	</div>
	<?php if ( '' !== $law_sc_bio['excerpt'] ) : ?>
		<p class="law-cal-speakers__bio"><?php echo esc_html( $law_sc_bio['excerpt'] ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $law_sc_dialog ) : ?>
		<button type="button" class="law-cal-speakers__biolink" data-law-modal-open="<?php echo esc_attr( $law_sc_dialog ); ?>" data-law-modal-enhanced hidden>
			<?php esc_html_e( 'Read full bio', 'law' ); ?>
		</button>
		<details class="law-cal-speakers__fallback" data-law-modal-fallback>
			<summary><?php esc_html_e( 'Read full bio', 'law' ); ?></summary>
			<div class="law-cal-speakers__fullbio"><?php echo law_rich_text_render( $law_sc_bio['full'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses'd against the rich-text allowlist. ?></div>
		</details>
	<?php endif; ?>
</li>
