<?php
/**
 * Shared Full list / Day markup for public and committee calendars.
 *
 * Set these before including; all are optional:
 *   $law_cal_show_status (bool)   Committee mode: status pills, and the
 *                                 "all submissions" note on the list view.
 *   $law_cal_hero_title  (string) Hero title for the list view.
 *   $law_cal_event       (array)  A pre-resolved, already-hydrated event array
 *                                 to render, instead of resolving $_GET['event']
 *                                 through law_calendar_event_by_id(). The
 *                                 committee preview (?preview-event=<id> on
 *                                 templates/account-dashboard.php) passes one,
 *                                 because that resolver applies the public
 *                                 status filter anywhere but the committee
 *                                 calendar template, so an unconfirmed event
 *                                 would come back null.
 *   $law_cal_back        (array)  array( 'url' => …, 'label' => … ) overriding
 *                                 BOTH ways back off the single event view --
 *                                 the chevron link at the top and the button at
 *                                 the foot of the article -- so the two cannot
 *                                 drift. Defaults to law_calendar_url() with
 *                                 "Back to programme" / "Back to events
 *                                 calendar".
 *   $law_cal_preview     (bool)   Committee preview: the booking control
 *                                 renders for an event of any status with its
 *                                 button inert, so the preview shows the row
 *                                 an attendee will see instead of omitting it.
 *   $law_cal_details_rows (array) Allow-list of details-box row keys, from
 *                                 'date', 'time', 'venue', 'host', 'type',
 *                                 'sector', 'price' and 'places'; unset means
 *                                 every row. The flagship page
 *                                 (templates/flagship-event.php) passes
 *                                 date/time/venue/price/places: it has no host
 *                                 organisation, type or sector to state, and
 *                                 it is the one event that charges.
 *   $law_cal_no_booking  (bool)   Render no booking control and no places
 *                                 fallback. The flagship is approval-gated
 *                                 through its own application flow
 *                                 (EVENTS_4.2_SPECS.md §5), so this page must
 *                                 not offer to book it.
 *   $law_cal_sessions_style (string) 'accordion' (the default) or 'timeline'.
 *                                 An accordion suits an event with two or
 *                                 three sessions a reader might open; a
 *                                 day-long conference agenda is the content
 *                                 itself, so the flagship renders every
 *                                 session open on a vertical timeline
 *                                 (parts/events/session-timeline.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_cal_show_status = ! empty( $law_cal_show_status );
$law_cal_event       = isset( $law_cal_event ) && is_array( $law_cal_event ) ? $law_cal_event : null;
$law_cal_back        = isset( $law_cal_back ) && is_array( $law_cal_back ) ? $law_cal_back : array();
$law_cal_preview     = ! empty( $law_cal_preview );
$law_cal_details_rows = isset( $law_cal_details_rows ) && is_array( $law_cal_details_rows ) ? $law_cal_details_rows : null;
$law_cal_no_booking   = ! empty( $law_cal_no_booking );
$law_cal_sessions_style = ( isset( $law_cal_sessions_style ) && 'timeline' === $law_cal_sessions_style ) ? 'timeline' : 'accordion';

$page_id = get_queried_object_id();
$calendar_blocked = function_exists( 'members_can_current_user_view_post' )
	&& $page_id
	&& ! members_can_current_user_view_post( $page_id );

if ( $law_cal_event && ! $calendar_blocked ) {
	$event    = $law_cal_event;
	$event_id = (int) ( $event['id'] ?? 0 );
} else {
	$event_id = $calendar_blocked ? 0 : law_calendar_requested_event_id();
	$event    = $event_id ? law_calendar_event_by_id( $event_id ) : null;
}

$law_cal_back_url   = (string) ( $law_cal_back['url'] ?? '' );
$law_cal_back_label = (string) ( $law_cal_back['label'] ?? '' );

get_header();

// Only the list view needs the week; the single event view never reads $days,
// so the query is skipped there.
$days = ( $calendar_blocked || $event ) ? array() : law_calendar_week_days();

if ( $event ) {
	$host_list = $event['host'] ? array_filter( array_map( 'trim', preg_split( '/;/', $event['host'] ) ) ) : array();

	// Bookings (CPT mode): the hero shows places REMAINING, not the raw ticket
	// number — "places", never "tickets", the events are free (EVENTS_BOOKINGS.md).
	if ( 'cpt' === law_events_source() && function_exists( 'law_event_tickets_remaining' ) ) {
		$law_remaining   = law_event_tickets_remaining( $event['id'] );
		$law_places_meta = array(
			'label' => 'Places remaining',
			'value' => null === $law_remaining ? '' : number_format_i18n( $law_remaining ),
		);
	} else {
		$law_places_meta = array( 'label' => 'Available tickets', 'value' => $event['tickets'] ? number_format_i18n( $event['tickets'] ) : '' );
	}

	// The details box that sits in the hero below the title. Built here and
	// passed to the hero partial as pre-escaped markup, so parts/layout/
	// hero-title.php stays generic page chrome (it is shared with 404.php,
	// privacy, patrons and eight others) rather than carrying an events-module
	// box, an icon set and a booking control.
	//
	// Places remaining is deliberately kept as its own footer fact rather than
	// left to the booking control: law_booking_render_action() only states
	// availability in two of its five states ("N places left" when bookable,
	// "fully booked" when sold out), so an already-booked attendee wondering
	// whether to bring colleagues would otherwise lose the number entirely.
	$law_cal_rows = array(
		array( 'key' => 'date', 'label' => 'Date', 'value' => $event['date'] ? law_calendar_day_heading( $event['date'] ) : 'Slot not confirmed' ),
		// Keyed off the date, not the start time: an event with a date but no
		// times still has something to say (the flagship's "Times to be
		// announced" until its agenda is written), and time_label is what
		// law_calendar_event_time_label() falls back to.
		array( 'key' => 'time', 'label' => 'Time', 'value' => $event['date'] ? law_calendar_event_time_label( $event ) : '' ),
		array( 'key' => 'venue', 'label' => 'Location', 'value' => $event['venue'] ),
		array( 'key' => 'host', 'label' => 'Hosted by', 'value' => implode( ', ', $host_list ) ),
		array( 'key' => 'type', 'label' => 'Type', 'value' => $event['type'] ),
		array( 'key' => 'sector', 'label' => 'Sector', 'value' => implode( ', ', $event['sectors'] ) ),
		// Only the flagship charges for a place today, and both helpers
		// return '' for anything else, so these two rows drop out of every
		// other event's box on their own (an empty value is skipped) rather
		// than needing a caller to know about them.
		array(
			'key'   => 'price',
			'label' => 'Price',
			'value' => function_exists( 'law_flagship_details_price' ) ? law_flagship_details_price( $event ) : '',
		),
		array(
			'key'   => 'places',
			'label' => 'Places',
			'value' => function_exists( 'law_flagship_details_places' ) ? law_flagship_details_places( $event ) : '',
		),
	);
	if ( null !== $law_cal_details_rows ) {
		$law_cal_rows = array_values(
			array_filter(
				$law_cal_rows,
				fn( $law_cal_row ) => in_array( $law_cal_row['key'], $law_cal_details_rows, true )
			)
		);
	}

	ob_start();
	get_template_part(
		'parts/calendar-event-details',
		null,
		array(
			'event'   => $event,
			'preview' => $law_cal_preview,
			'places'  => $law_places_meta,
			'booking' => ! $law_cal_no_booking,
			'rows'    => $law_cal_rows,
		)
	);

	$hero_args = array(
		'title'       => $event['title'],
		'classes'     => 'law-event-hero',
		'after_title' => (string) ob_get_clean(),
	);

	// A banner photograph chosen for this event wins over the theme's default
	// one. Only the flagship carries _law_hero_image_id today, and the guard is
	// on the source because in legacy mode $event['id'] is a Gravity Forms
	// entry ID, not a post ID. Leaving the key unset keeps hero-title.php's own
	// default, which is the same picture the programme block falls back to.
	if ( 'cpt' === law_events_source() && function_exists( 'law_event_hero_image_url' ) ) {
		$law_cal_hero_image = law_event_hero_image_url( $event['id'], 'large' );
		if ( '' !== $law_cal_hero_image ) {
			$hero_args['image'] = $law_cal_hero_image;
		}
	}
} else {
	$hero_args = array();
	if ( ! empty( $law_cal_hero_title ) ) {
		$hero_args['title'] = (string) $law_cal_hero_title;
	}
}
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title', null, $hero_args ); ?>

<section class="page-section">
	<div class="grid-container">
		<?php if ( $calendar_blocked ) : ?>
			<div class="law-cal">
				<?php echo wp_kses_post( members_get_post_error_message( get_the_ID() ) ); ?>
			</div>
		<?php else : ?>
		<div class="law-cal<?php echo $law_cal_show_status ? ' law-cal--committee' : ''; ?>">

			<?php if ( $law_cal_show_status && ! $event ) : ?>
				<p class="law-cal__note">All submissions. The public programme shows approved events only.</p>
			<?php endif; ?>

			<?php if ( $event ) : ?>

				<?php
				get_template_part(
					'parts/layout/back-link',
					null,
					array(
						'url'   => $law_cal_back_url ? $law_cal_back_url : law_calendar_url(),
						'label' => $law_cal_back_label ? $law_cal_back_label : __( 'Back to programme', 'law' ),
					)
				);
				?>

				<article class="law-cal-detail">
					<div class="grid-x grid-padding-x">
						<div class="large-8 cell">
							<?php if ( $law_cal_show_status || law_calendar_entry_admin_url( $event['id'] ) ) : ?>
								<p class="law-cal-detail__admin">
									<?php if ( $law_cal_show_status ) : ?>
										<?php law_calendar_status_badge( $event ); ?>
									<?php endif; ?>
									<?php law_calendar_edit_link( $event ); ?>
								</p>
							<?php endif; ?>
							<h2 class="screen-reader-text"><?php esc_html_e( 'About this event', 'law' ); ?></h2>
							<div class="law-cal-detail__body">
								<?php echo wp_kses_post( wpautop( $event['description'] ) ); ?>
							</div>
							<?php if ( ! empty( $event['sessions'] ) && 'timeline' === $law_cal_sessions_style ) : ?>
								<?php
								get_template_part(
									'parts/events/session-timeline',
									null,
									array( 'sessions' => $event['sessions'] )
								);
								?>
							<?php elseif ( ! empty( $event['sessions'] ) ) : ?>
								<section class="law-cal-sessions" aria-labelledby="law-cal-sessions-heading">
									<h2 id="law-cal-sessions-heading" class="law-cal-acc__heading">Sessions</h2>
									<?php foreach ( $event['sessions'] as $session ) : ?>
										<?php
										$session_label = trim( $session['title'] );
										if ( '' === $session_label ) {
											$session_label = $session['time_label'] ? $session['time_label'] : __( 'Session', 'law' );
										}
										?>
										<?php
										// Deliberately no name="" attribute: a shared name would
										// make these an exclusive accordion, so opening one
										// session would slam the previous one shut. Readers
										// compare sessions side by side, so each one opens and
										// closes on its own.
										?>
										<details class="law-cal-session">
											<summary class="law-cal-session__summary">
												<span class="law-cal-session__heading">
													<span class="law-cal-session__title"><?php echo esc_html( $session_label ); ?></span>
													<?php if ( $session['time_label'] && $session['time_label'] !== $session_label ) : ?>
														<span class="law-cal-session__time"><?php echo esc_html( $session['time_label'] ); ?></span>
													<?php endif; ?>
												</span>
											</summary>
											<div class="law-cal-session__panel">
												<?php if ( $session['description'] ) : ?>
													<div class="law-cal-session__body">
														<?php echo wp_kses_post( wpautop( $session['description'] ) ); ?>
													</div>
												<?php endif; ?>
												<?php if ( ! empty( $session['speakers'] ) ) : ?>
													<ul class="law-cal-speakers law-cal-speakers--cards">
														<?php foreach ( $session['speakers'] as $session_speaker ) : ?>
															<?php get_template_part( 'parts/events/speaker-card', null, array( 'speaker' => $session_speaker ) ); ?>
														<?php endforeach; ?>
													</ul>
												<?php endif; ?>
											</div>
										</details>
									<?php endforeach; ?>
								</section>
							<?php endif; ?>
							<?php if ( empty( $event['sessions'] ) && ! empty( $event['speakers'] ) ) : ?>
								<section class="law-cal-acc">
									<h2 class="law-cal-acc__heading">Speakers</h2>
									<ul class="law-cal-speakers law-cal-speakers--cards">
										<?php foreach ( $event['speakers'] as $speaker ) : ?>
											<?php get_template_part( 'parts/events/speaker-card', null, array( 'speaker' => $speaker ) ); ?>
										<?php endforeach; ?>
									</ul>
								</section>
							<?php endif; ?>
							<?php
							// Venue last, after the sessions and speakers: the running
							// order and the people are what the reader came for, and the
							// address is a detail they need once (Denis, 9 September
							// 2026). The details box in the hero links down to this
							// section, so the address is still one click from the top.
							?>
							<?php get_template_part( 'parts/events/event-venue', null, array( 'venue' => $event['venue'] ) ); ?>
							<?php
							// The "Read full bio" dialogs the cards above registered, printed
							// here and nowhere else: they must land outside the sessions
							// accordion, because a closed <details> renders nothing and a
							// dialog inside one could never be opened.
							foreach ( law_speaker_dialogs() as $law_cal_dialog ) {
								get_template_part( 'parts/events/speaker-bio-modal', null, $law_cal_dialog );
							}
							?>
							<div class="law-cal-detail__actions law-booking-actions">
								<?php
								// The booking control (Register / waitlist /
								// open soon / closed / you're booked) used to sit here. It
								// now renders in the hero's details box next to the places
								// count, so the decision and the means to act on it are in
								// one place. See parts/calendar-event-details.php.
								?>
								<a class="button" href="<?php echo esc_url( $law_cal_back_url ? $law_cal_back_url : law_calendar_url() ); ?>"><?php echo esc_html( $law_cal_back_label ? $law_cal_back_label : __( 'Back to events calendar', 'law' ) ); ?></a>
							</div>
						</div>
					</div>
				</article>

			<?php else : ?>

				<?php get_template_part( 'parts/calendar-filters' ); ?>

				<div class="law-cal-events" id="law-cal-events" aria-live="polite">
					<?php get_template_part( 'parts/calendar-events', null, array( 'show_status' => $law_cal_show_status ) ); ?>
				</div>

			<?php endif; ?>

		</div>
		<?php endif; ?>
	</div>
</section>
<?php endwhile; endif; ?>

<?php
get_footer();
