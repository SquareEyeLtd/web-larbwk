<?php
/**
 * Single speaker profile, /speakers/<form 8 entry ID>/.
 *
 * No "Template Name" on purpose: this is not a wp-admin page template. It is
 * routed over the Speakers page by law_speakers_single_template() in
 * functions/speakers.php, which also 404s unknown IDs before we get here.
 */

$law_speaker = law_speaker_current_profile();
$law_events  = $law_speaker ? law_speaker_events( $law_speaker ) : array();
$law_days    = law_calendar_week_days();

get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
	<?php get_template_part( 'parts/layout/hero-title', null, array( 'classes' => 'register-page', 'is_event' => true ) ); ?>
<?php endwhile; endif; wp_reset_postdata(); ?>

<section class="page-section">
	<div class="grid-container">
		<div class="law-speaker">

			<div class="grid-x grid-padding-x">
				<div class="large-12 cell">
					<a class="law-cal-detail__back" href="<?php echo esc_url( get_permalink( get_queried_object_id() ) ); ?>">Back to speakers</a>
				</div>
			</div>

			<div class="grid-x grid-padding-x grid-padding-y">

				<div class="large-4 cell">
					<div class="law-speakers__photo law-speaker__photo">
						<?php if ( $law_speaker['photo'] ) : ?>
							<img src="<?php echo esc_url( $law_speaker['photo'] ); ?>" alt="<?php echo esc_attr( $law_speaker['name'] ); ?>">
						<?php else : ?>
							<span class="law-speakers__initials" aria-hidden="true"><?php echo esc_html( law_speaker_initials( $law_speaker ) ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<div class="large-8 cell">
					<div class="law-speaker__details">
						<h2 class="law-speaker__name"><?php echo esc_html( $law_speaker['name'] ); ?></h2>

						<?php if ( $law_speaker['job_title'] ) : ?>
							<p class="law-speakers__role"><?php echo esc_html( $law_speaker['job_title'] ); ?></p>
						<?php endif; ?>

						<?php if ( $law_speaker['organisation'] ) : ?>
							<p class="law-speaker__company"><?php echo esc_html( $law_speaker['organisation'] ); ?></p>
						<?php endif; ?>

						<?php if ( $law_speaker['url'] ) : ?>
							<p>
								<a class="normal-link law-speaker__website" href="<?php echo esc_url( $law_speaker['url'] ); ?>" target="_blank" rel="noopener">
									View profile<svg class="law-speaker__ext-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 13.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5.5"/><path d="M14 3h7v7"/><path d="M10 14 21 3"/></svg><span class="show-for-sr">(opens in a new tab)</span>
								</a>
							</p>
						<?php endif; ?>

						<?php if ( $law_speaker['bio'] ) : ?>
							<div class="law-speaker__bio">
								<?php echo wp_kses_post( wpautop( $law_speaker['bio'] ) ); ?>
							</div>
						<?php endif; ?>
					</div>

					<?php if ( $law_events ) : ?>
						<div class="law-speaker__events law-cal">
							<h3 class="law-cal-acc__heading">Speaking at</h3>
							<?php foreach ( $law_events as $law_event ) : ?>
								<article class="<?php echo esc_attr( law_calendar_card_classes( $law_event ) ); ?>">
									<h4 class="law-cal-card__title">
										<a href="<?php echo esc_url( law_speaker_event_link( $law_event['id'] ) ); ?>"><?php echo esc_html( $law_event['title'] ); ?></a>
									</h4>
									<p class="law-cal-card__meta">
										<?php
										$law_date_label = $law_event['date'] ? ( $law_days[ $law_event['date'] ] ?? $law_event['date'] ) : 'Slot not confirmed';
										echo esc_html( $law_date_label . ' · ' . law_calendar_meta_line( $law_event ) );
										?>
									</p>
									<?php
									$law_hosted = law_calendar_hosted_by( $law_event );
									if ( $law_hosted ) :
										?>
										<p class="law-cal-card__host"><?php echo esc_html( $law_hosted ); ?></p>
									<?php endif; ?>
									<?php if ( $law_event['excerpt'] ) : ?>
										<p class="law-cal-card__desc"><?php echo esc_html( $law_event['excerpt'] ); ?></p>
									<?php endif; ?>
									<?php law_calendar_sponsored_label( $law_event ); ?>
								</article>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

			</div>
		</div>
	</div>
</section>

<?php get_footer(); ?>
