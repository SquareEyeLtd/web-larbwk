<?php
/**
 * Template Name: My events
 *
 * The host events dashboard: a theme-owned listing of the current user's
 * events as cards (functions/account-events.php). On the custom CPT path this
 * page also hosts the comment thread (?law_thread=) and the per-event attendee
 * list (?law_event_bookings=), and links to the custom edit form; the legacy
 * GravityView entry/edit branch remains only for the pre-cutover source.
 * Restrict the page with Members as before.
 *
 * The HOST side only since 10 September 2026. A person's own bookings live on
 * /account/bookings/ (templates/account-bookings.php); account-events.php
 * redirects ?law_booking= and anyone with no events of their own over to it.
 */

get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container">
		<div class="law-cal law-account-events">

			<?php
			$law_thread_id        = absint( $_GET['law_thread'] ?? 0 );
			$law_bookings_list_id = absint( $_GET['law_event_bookings'] ?? 0 );
			?>
			<?php if ( $law_bookings_list_id && 'cpt' === law_events_source() ) : ?>

				<?php
				// The list serves two audiences: a committee member arrives from
				// the dashboard and goes back there ("all events"); a host came
				// from, and returns to, their own My events list.
				$law_bl_committee = function_exists( 'law_user_is_committee' ) && law_user_is_committee();
				get_template_part(
					'parts/layout/back-link',
					null,
					array(
						'url'   => $law_bl_committee ? home_url( '/account/dashboard/' ) : get_permalink(),
						'label' => $law_bl_committee ? __( 'Back to all events', 'law' ) : __( 'Back to my events', 'law' ),
					)
				);
				?>
				<?php // .law-dashboard: the table/badge light-section colour resets are scoped to it. ?>
				<div class="law-dashboard law-booking-manage">
					<?php get_template_part( 'parts/events/booking-list', null, array( 'event_id' => $law_bookings_list_id ) ); ?>
				</div>

			<?php elseif ( $law_thread_id && 'cpt' === law_events_source() ) : ?>

				<?php
				get_template_part(
					'parts/layout/back-link',
					null,
					array(
						'url'   => get_permalink(),
						'label' => __( 'Back to my events', 'law' ),
					)
				);
				get_template_part( 'parts/events/thread', null, array( 'event_id' => $law_thread_id, 'context' => 'host' ) );
				?>

			<?php elseif ( law_account_events_in_entry_context() ) : ?>

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

						<?php
						// The event notices are this page's own; the booking and
						// waitlist notices the per-event attendee list redirects back
						// with come from the one shared map in account-bookings.php,
						// so the wording cannot drift again.
						$law_notice = sanitize_key( $_GET['law_notice'] ?? '' );
						$law_notice_text = array(
							'event-updated'   => __( 'Your changes have been saved.', 'law' ),
							'event-submitted' => __( 'Your event has been submitted to the committee.', 'law' ),
							'event-withdrawn' => __( 'Your event has been withdrawn.', 'law' ),
							'event-not-editable' => __( 'This event can no longer be edited.', 'law' ),
							'withdraw-failed' => __( 'Sorry, this event could not be withdrawn. Please reload the page and try again.', 'law' ),
						);
						if ( isset( $law_notice_text[ $law_notice ] ) ) {
							echo '<div class="law-form-notice" role="status">' . esc_html( $law_notice_text[ $law_notice ] ) . '</div>';
						} elseif ( function_exists( 'law_booking_notice_render' ) ) {
							law_booking_notice_render();
						}

						// The host section is now the whole page: a person's own bookings
						// moved to /account/bookings/ on 10 September 2026, and a
						// signed-in user with no events of their own is redirected there
						// by account-events.php, so everyone who reaches this listing
						// either runs events or is host-like.
						$law_is_committee = function_exists( 'law_user_is_committee' ) && law_user_is_committee();
						// With no events to list, the submit call to action lives in the empty-state sentence instead.
						$law_show_submit_button = $law_submit_url && $law_items;
						if ( $law_show_submit_button || $law_is_committee ) :
						?>
							<div class="law-account-events__toolbar">
								<?php if ( $law_show_submit_button ) : ?>
									<a class="button orange" href="<?php echo esc_url( $law_submit_url ); ?>"><?php esc_html_e( 'Submit an event', 'law' ); ?></a>
								<?php endif; ?>
								<?php if ( $law_is_committee ) : ?>
									<a class="button" href="<?php echo esc_url( home_url( '/account/dashboard/' ) ); ?>"><?php esc_html_e( 'Review queue', 'law' ); ?></a>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<?php if ( ! $law_items ) : ?>

							<p class="law-cal__empty">
								<?php
								if ( $law_submit_url ) {
									printf(
										/* translators: %s: link to the submit an event form. */
										esc_html__( 'You have not submitted any events yet. %s', 'law' ),
										'<a href="' . esc_url( $law_submit_url ) . '">' . esc_html__( 'Submit an event', 'law' ) . '</a>'
									);
								} else {
									esc_html_e( 'You have not submitted any events yet.', 'law' );
								}
								?>
							</p>

						<?php else : ?>

							<?php
							// One success dialog for the withdraw cards' AJAX flow
							// (event-form.js overwrites its copy from the response).
							$law_any_withdraw = false;
							if ( 'cpt' === law_events_source() ) {
								foreach ( $law_items as $law_item ) {
									if ( in_array( $law_item['event']['status'], array( 'Draft', 'Proposed', 'Sent back' ), true ) ) {
										$law_any_withdraw = true;
										break;
									}
								}
							}
							?>
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

							<?php
							if ( $law_any_withdraw ) {
								get_template_part(
									'parts/layout/modal',
									null,
									array(
										'id'      => 'law-modal-withdraw-success',
										'title'   => __( 'Event withdrawn', 'law' ),
										'copy'    => __( 'Reloading the page…', 'law' ),
										'confirm' => false,
										'close'   => __( 'Close', 'law' ),
									)
								);
							}
							?>

						<?php endif; ?>

					</div>
				</div>

			<?php endif; ?>

		</div>
	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
