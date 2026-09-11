<?php
/**
 * Template Name: My bookings
 *
 * The personal bookings page, /account/bookings/ (split off My events on
 * 10 September 2026). Every signed-in role gets it: hosts, sponsors and
 * committee members book places at other firms' events like anyone else, so
 * this is not an attendee-only surface. My events (templates/account-events.php)
 * keeps the HOST side -- the events someone runs, their message threads and the
 * per-event attendee list.
 *
 * Two views: the listing, and the ?law_booking=<id> manage view for one
 * booking's party. CPT-mode only -- law_account_bookings() returns nothing on
 * the legacy Gravity Forms source. Restrict the page with Members as the other
 * account pages are (law_setup_my_bookings_access() copies /account/'s rows).
 */

// Per-user content: never let a proxy or the browser hand one person's
// bookings to the next, the way the committee dashboards guard theirs.
nocache_headers();

get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container">
		<?php // .law-cal: the event cards and their badges are scoped under it (calendar.css). ?>
		<div class="law-cal law-account-events">

			<?php
			$law_booking_id = absint( $_GET['law_booking'] ?? 0 );
			$law_is_cpt     = 'cpt' === law_events_source();
			?>
			<?php if ( $law_booking_id && $law_is_cpt ) : ?>

				<?php
				get_template_part(
					'parts/layout/back-link',
					null,
					array(
						'url'   => get_permalink(),
						'label' => __( 'Back to my bookings', 'law' ),
					)
				);
				?>
				<div class="law-booking-manage">
					<?php get_template_part( 'parts/events/booking-manage', null, array( 'booking_id' => $law_booking_id ) ); ?>
				</div>

			<?php else : ?>

				<div class="grid-x grid-padding-x">
					<div class="large-12 cell">

						<?php
						// Every notice this page can show is a booking or waitlist
						// one, from the single shared map in account-bookings.php.
						// The event-flow notices belong to My events.
						if ( function_exists( 'law_booking_notice_render' ) ) {
							law_booking_notice_render();
						}
						// The flagship's own outcomes (applied, card saved,
						// withdrawn) have their own map, because none of them
						// happens to a hosted booking.
						if ( function_exists( 'law_flagship_notice_render' ) ) {
							law_flagship_notice_render();
						}

						$law_bookings = $law_is_cpt && function_exists( 'law_account_bookings' ) ? law_account_bookings() : array();
						?>

						<?php if ( ! $law_bookings ) : ?>
							<p class="law-cal__empty">
								<?php
								// The Programme page by path, not law_calendar_url():
								// off the calendar templates that helper falls back to
								// the CURRENT page's permalink, which here would link
								// this page to itself.
								$law_programme     = get_page_by_path( 'programme' );
								$law_programme_url = $law_programme ? get_permalink( $law_programme ) : home_url( '/programme/' );
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
										// 'action', not 'full': the Manage action below
										// already links to this booking, so the card wants
										// only the states that offer something new -- a
										// place for someone who booked colleagues but not
										// themselves.
										'booking'    => 'action',
										'badge'      => law_booking_card_badge( $law_bk_item['status'] ),
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

					</div>
				</div>

			<?php endif; ?>

		</div>
	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
