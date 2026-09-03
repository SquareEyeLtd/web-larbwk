<?php
/**
 * Template Name: My events
 *
 * The host events dashboard: a theme-owned listing of the current user's
 * form 2 entries as event cards (functions/account-events.php). GravityView
 * stays as the edit engine: in its entry/edit context the page content (the
 * [gravityview] shortcode) renders instead, so editing, locking and Entry
 * Revisions keep working. Restrict the page with Members as before.
 */

get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container">
		<div class="law-cal law-account-events">

			<?php if ( law_account_events_in_entry_context() ) : ?>

				<?php
				get_template_part(
					'parts/layout/back-link',
					null,
					array(
						'url'   => get_permalink(),
						'label' => __( 'Back to my events', 'law' ),
					)
				);
				?>
				<div class="grid-x grid-padding-x">
					<div class="large-10 cell">
						<?php the_content(); ?>
					</div>
				</div>

			<?php else : ?>

				<?php
				$law_items      = law_account_events();
				$law_submit_url = law_account_events_submit_url();
				?>

				<div class="grid-x grid-padding-x">
					<div class="large-12 cell">

						<?php if ( $law_submit_url ) : ?>
							<div class="law-account-events__toolbar">
								<a class="button orange" href="<?php echo esc_url( $law_submit_url ); ?>"><?php esc_html_e( 'Submit an event', 'law' ); ?></a>
							</div>
						<?php endif; ?>

						<?php if ( ! $law_items ) : ?>

							<p class="law-cal__empty"><?php esc_html_e( 'You have not submitted any events yet.', 'law' ); ?></p>

						<?php else : ?>

							<?php foreach ( $law_items as $law_item ) : ?>
								<?php
								$law_event = $law_item['event'];
								$law_entry = $law_item['entry'];
								$law_edit  = law_account_event_edit_url( $law_entry );

								get_template_part(
									'parts/loop/event',
									null,
									array(
										'event'       => $law_event,
										// Confirmed events link to the public listing; the rest to the edit form.
										'url'         => ( 'Confirmed' === $law_event['status'] && function_exists( 'law_speaker_event_link' ) )
											? law_speaker_event_link( $law_event['id'] )
											: ( $law_edit ? $law_edit : $law_event['url'] ),
										'show_status' => true,
										'show_date'   => true,
										'meta_lines'  => law_account_event_meta_lines( $law_entry ),
										'actions'     => law_account_event_actions( $law_event, $law_entry ),
									)
								);
								?>
							<?php endforeach; ?>

						<?php endif; ?>

					</div>
				</div>

			<?php endif; ?>

		</div>
	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
