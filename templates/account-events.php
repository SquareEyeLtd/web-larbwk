<?php
/**
 * Template Name: My events
 *
 * The host events dashboard: a theme-owned listing of the current user's
 * events as cards (functions/account-events.php). On the custom CPT path this
 * page also hosts the comment thread (?law_thread=) and links to the custom
 * edit form; the legacy GravityView entry/edit branch remains only for the
 * pre-cutover source. Restrict the page with Members as before.
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
			$law_booking_id       = absint( $_GET['law_booking'] ?? 0 );
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

			<?php elseif ( $law_booking_id && 'cpt' === law_events_source() ) : ?>

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
				<div class="law-booking-manage">
					<?php get_template_part( 'parts/events/booking-manage', null, array( 'booking_id' => $law_booking_id ) ); ?>
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
						// The event notices are this page's own; every booking and
						// waitlist notice comes from the one shared map in
						// account-bookings.php, so the wording cannot drift again.
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

						// Your bookings (EVENTS_BOOKINGS.md §7.3): the attendee side of
						// this page, above the host list. The page serves two audiences
						// now, so the host section only renders for host-like users (or
						// anyone who actually has events); a pure attendee is never
						// invited to "Submit an event" under their bookings.
						$law_bookings     = function_exists( 'law_account_bookings' ) ? law_account_bookings() : array();
						$law_is_host_like = function_exists( 'law_account_user_is_host_like' ) && law_account_user_is_host_like();
						$law_show_host    = $law_is_host_like || $law_items;
						$law_show_bookings = 'cpt' === law_events_source() && ( $law_bookings || ! $law_show_host );
						?>

						<?php if ( $law_show_bookings ) : ?>
							<h2 id="law-account-bookings" class="law-account-events__heading"><?php esc_html_e( 'My bookings', 'law' ); ?></h2>
							<?php if ( ! $law_bookings ) : ?>
								<p class="law-cal__empty">
									<?php
									// The Programme page by path, not law_calendar_url():
									// off the calendar templates that helper falls back to
									// the CURRENT page's permalink, which here would link
									// this page to itself.
									$law_programme      = get_page_by_path( 'programme' );
									$law_programme_url  = $law_programme ? get_permalink( $law_programme ) : home_url( '/programme/' );
									printf(
										/* translators: %s: link to the events programme. */
										esc_html__( 'You have no bookings yet. %s', 'law' ),
										'<a href="' . esc_url( $law_programme_url ) . '">' . esc_html__( 'Browse the programme', 'law' ) . '</a>'
									);
									?>
								</p>
							<?php else : ?>
								<?php foreach ( $law_bookings as $law_bk_item ) : ?>
									<?php
									get_template_part(
										'parts/loop/event',
										null,
										array(
											'event'      => $law_bk_item['event'],
											'url'        => $law_bk_item['event']['url'],
											'show_date'  => true,
											'badge'      => $law_bk_item['waitlisted']
												? array( 'label' => __( 'Waitlisted', 'law' ), 'slug' => 'waitlisted' )
												: array(),
											'meta_lines' => array_filter( array(
												// One booking per attendee: their own place, and
												// separately the colleagues they brought here.
												$law_bk_item['own']
													? sprintf( __( 'Booking #%d', 'law' ), (int) law_event_meta( $law_bk_item['own']->ID, '_law_booking_number' ) )
													: '',
												$law_bk_item['own'] && '' !== $law_bk_item['invited_by']
													? sprintf( __( 'Invited by %s', 'law' ), $law_bk_item['invited_by'] )
													: '',
												$law_bk_item['colleagues']
													? sprintf(
														_n( '+ %d colleague booked by you', '+ %d colleagues booked by you', count( $law_bk_item['colleagues'] ), 'law' ),
														count( $law_bk_item['colleagues'] )
													)
													: '',
											) ),
											'actions'    => array(
												array(
													'label'    => __( 'View event', 'law' ),
													'url'      => $law_bk_item['event']['url'],
													'external' => true,
													'arrow'    => true,
												),
												array(
													'label' => __( 'Manage', 'law' ),
													'url'   => law_booking_manage_url( $law_bk_item['manage_id'] ),
												),
											),
										)
									);
									?>
								<?php endforeach; ?>
							<?php endif; ?>
						<?php endif; ?>

						<?php if ( $law_show_bookings && $law_show_host ) : ?>
							<h2 id="law-account-events" class="law-account-events__heading"><?php esc_html_e( 'My events', 'law' ); ?></h2>
						<?php endif; ?>

						<?php
						$law_is_committee = function_exists( 'law_user_is_committee' ) && law_user_is_committee();
						// With no events to list, the submit call to action lives in the empty-state sentence instead.
						$law_show_submit_button = $law_show_host && $law_submit_url && $law_items;
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

						<?php if ( $law_show_host && ! $law_items ) : ?>

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

						<?php elseif ( $law_show_host ) : ?>

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
