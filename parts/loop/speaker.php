<?php
/**
 * Speakers archive loop item (templates/speakers.php).
 *
 * Use with get_template_part( 'parts/loop/speaker', null, $args ):
 *   speaker (array) A merged law_speakers() profile. Required.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_speaker = isset( $args['speaker'] ) && is_array( $args['speaker'] ) ? $args['speaker'] : null;
if ( ! $law_speaker ) {
	return;
}

$law_speaker_url = law_speaker_url( $law_speaker['id'] );
?>
<div class="large-4 medium-6 cell" data-speaker-card>
	<div class="law-speakers__card">
		<a class="law-speakers__photo" href="<?php echo esc_url( $law_speaker_url ); ?>" aria-hidden="true" tabindex="-1">
			<?php if ( $law_speaker['photo'] ) : ?>
				<img src="<?php echo esc_url( $law_speaker['photo'] ); ?>" alt="" loading="lazy">
			<?php else : ?>
				<span class="law-speakers__initials"><?php echo esc_html( law_speaker_initials( $law_speaker ) ); ?></span>
			<?php endif; ?>
		</a>
		<div class="law-speakers__details">
			<h3><a href="<?php echo esc_url( $law_speaker_url ); ?>" data-speaker-field><?php echo esc_html( $law_speaker['name'] ); ?></a></h3>
			<?php if ( $law_speaker['job_title'] ) : ?>
				<p class="law-speakers__role" data-speaker-field><?php echo esc_html( $law_speaker['job_title'] ); ?></p>
			<?php endif; ?>
			<?php if ( $law_speaker['organisation'] ) : ?>
				<p class="law-speakers__org" data-speaker-field><?php echo esc_html( $law_speaker['organisation'] ); ?></p>
			<?php endif; ?>
			<a class="normal-link law-speakers__view" href="<?php echo esc_url( $law_speaker_url ); ?>">VIEW SPEAKER</a>
		</div>
	</div>
</div>
