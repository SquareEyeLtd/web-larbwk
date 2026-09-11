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

get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
	<?php get_template_part( 'parts/layout/hero-title', null, array( 'classes' => 'register-page', 'is_event' => true ) ); ?>
<?php endwhile; endif; wp_reset_postdata(); ?>

<section class="page-section">
	<div class="grid-container">
		<div class="law-speaker">

			<?php
			get_template_part(
				'parts/layout/back-link',
				null,
				array(
					'url'   => law_speakers_page_id() ? get_permalink( law_speakers_page_id() ) : home_url( '/speakers/' ),
					'label' => __( 'Back to speakers', 'law' ),
				)
			);
			?>

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
						<?php
						// Name, then the headline job title and organisation, exactly as
						// the speakers archive card shows them (Denis, 11 September 2026).
						// Both come from the merged profile (the first appearance that
						// supplied one), so a speaker who changed jobs between events
						// still gets the per-event values on each card below.
						?>
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

						<?php
						// No biography here either: like the organisation and job
						// title, it is per event and is shown on the single event
						// page's speaker cards ("Read full bio").
						?>
					</div>

					<?php if ( $law_events ) : ?>
						<?php
						// A profile only renders while a confirmed event references the
						// speaker, so this list is never empty.
						?>
						<div class="law-speaker__events law-cal">
							<h3 class="law-speaker__events-heading"><?php esc_html_e( 'Speaking at:', 'law' ); ?></h3>
							<?php foreach ( $law_events as $law_event ) : ?>
								<?php
								// What this speaker was at THIS event (role, organisation and
								// position as submitted for it), rendered under "Hosted by".
								$law_appearance = function_exists( 'law_speaker_appearance_for_event' )
									? law_speaker_appearance_for_event( $law_speaker['id'], law_events_resolve_event_post_id( $law_event['id'] ) )
									: null;
								get_template_part(
									'parts/loop/event',
									null,
									array(
										'event'     => $law_event,
										'url'       => law_speaker_event_link( $law_event['id'] ),
										'show_date' => true,
										// The profile column is narrow and each card also carries the
										// appearance lines, so the buttons go under the text rather
										// than squeezing the title into half the width.
										'stacked'   => true,
										'speaker'   => $law_appearance ? array(
											'name'         => $law_speaker['name'],
											'role'         => $law_appearance['role'],
											'organisation' => $law_appearance['organisation'],
											'job_title'    => $law_appearance['job_title'],
										) : array(),
									)
								);
								?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

			</div>
		</div>
	</div>
</section>

<?php get_footer(); ?>
