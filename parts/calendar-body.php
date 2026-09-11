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
 *                                 drift. Defaults to law_calendar_url() and
 *                                 "Back to programme" for both.
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
		// Type before Hosted by so the desktop grid's first row reads what /
		// when / where and the second who / which sectors, with Sector spanning
		// the rest of that row. Sector is the one fact with no ceiling on its
		// length, so it is last wherever the grid wraps.
		array( 'key' => 'type', 'label' => 'Type', 'value' => $event['type'] ),
		array( 'key' => 'host', 'label' => 'Hosted by', 'value' => implode( ', ', $host_list ) ),
		// Pills, each linking to the programme filtered by that sector. The
		// filter already exists (law_sector, law_calendar_filter_params()), and
		// under the CPT source its dropdown is populated from the same term
		// names, so a pill's value selects an option exactly. 'value' is still
		// the joined string: it is what the partial tests for emptiness, and
		// what it falls back to.
		array(
			'key'   => 'sector',
			'label' => 'Sector',
			'value' => implode( ', ', $event['sectors'] ),
			'items' => array_map(
				fn( $law_cal_sector ) => array(
					'label' => $law_cal_sector,
					'url'   => law_calendar_url( array( 'law_sector' => $law_cal_sector ), false ),
				),
				(array) $event['sectors']
			),
		),
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
			// The flagship states its count here rather than in the panel below,
			// so the scarcity colour has to come with it or the one page where the
			// number matters most is the one page that says it quietly.
			'tone'  => function_exists( 'law_flagship_details_places_tone' ) ? law_flagship_details_places_tone( $event ) : '',
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

				<?php
				// The speakers column renders only for an event without sessions. When
				// there are sessions, each speaker already appears in the session panel
				// they speak in, and a sidebar copy would print every card (and its bio
				// dialog) twice on the page.
				$law_cal_speakers_aside = empty( $event['sessions'] ) && ! empty( $event['speakers'] );
				?>
				<article class="law-cal-detail">
					<div class="grid-x grid-padding-x">
						<?php
						// large-8 only when the speakers sidebar is beside it. Without the
						// sidebar (an event with sessions) the column takes the whole row
						// rather than leaving a third of it empty, which is what gives each
						// session room to put its speakers beside its description. The prose
						// keeps its own 65ch measure either way (calendar.css), so the wider
						// column does not make anything harder to read.
						?>
						<div class="<?php echo $law_cal_speakers_aside ? 'large-8' : 'large-12'; ?> cell law-cal-detail__main">
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
											<?php
											// Two columns when the session has both something to read
											// and somebody speaking (Denis, 11 September 2026): the
											// description on the left, that session's speakers on the
											// right. A modifier rather than a blanket rule, so a
											// session with only one of the two still fills the panel
											// instead of sitting in half of it.
											$law_cal_session_split = $session['description'] && ! empty( $session['speakers'] );
											?>
											<div class="law-cal-session__panel<?php echo $law_cal_session_split ? ' law-cal-session__panel--split' : ''; ?>">
												<?php if ( $session['description'] ) : ?>
													<div class="law-cal-session__body">
														<?php echo wp_kses_post( wpautop( $session['description'] ) ); ?>
													</div>
												<?php endif; ?>
												<?php if ( ! empty( $session['speakers'] ) ) : ?>
													<ul class="law-cal-speakers law-cal-speakers--cards law-cal-session__speakers">
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
							<?php
							// Venue last, after the sessions and speakers: the running
							// order and the people are what the reader came for, and the
							// address is a detail they need once (Denis, 9 September
							// 2026). The details box in the hero links down to this
							// section, so the address is still one click from the top.
							?>
							<?php get_template_part( 'parts/events/event-venue', null, array( 'venue' => $event['venue'] ) ); ?>
						</div>
						<?php if ( $law_cal_speakers_aside ) : ?>
							<div class="large-4 cell law-cal-detail__sidebar">
								<?php
								// The speakers sit beside the running order, not under it (Denis,
								// 11 September 2026): the description, sessions and venue are the
								// reader's path through the event, and a column of faces alongside
								// them answers "who is this?" without pushing the venue further
								// down the page. One card per row in here (calendar.css): the
								// two-per-row list is sized for the full-width column.
								?>
								<aside class="law-cal-detail__aside" aria-labelledby="law-cal-speakers-heading">
									<h2 id="law-cal-speakers-heading" class="law-cal-acc__heading">Speakers</h2>
									<ul class="law-cal-speakers law-cal-speakers--cards">
										<?php foreach ( $event['speakers'] as $speaker ) : ?>
											<?php get_template_part( 'parts/events/speaker-card', null, array( 'speaker' => $speaker ) ); ?>
										<?php endforeach; ?>
									</ul>
								</aside>
							</div>
						<?php endif; ?>
					</div>
					<div class="law-cal-detail__actions law-cal-detail__foot law-booking-actions">
						<?php
						// The booking control itself -- the state wording plus the
						// button -- lives in the hero's details box next to the
						// places count, so the decision and the means to act on it
						// are in one place (parts/calendar-event-details.php).
						//
						// What repeats here is the BUTTON only, because a reader who
						// has worked all the way down the sessions, the speakers and
						// the venue should not have to scroll back up to act
						// (Denis, 11 September 2026). One call, which routes a
						// flagship to its own application button; it presses exactly
						// as the one at the top does, dialog and all.
						//
						// The row sits OUTSIDE the two columns, under both of them
						// (Denis, 11 September 2026), so the way off the page is the
						// full width of the article rather than the foot of the left
						// column. __foot carries the grid gutter the cells above get
						// from .grid-padding-x, so the buttons line up with them.
						?>
						<a class="button" href="<?php echo esc_url( $law_cal_back_url ? $law_cal_back_url : law_calendar_url() ); ?>"><?php echo esc_html( $law_cal_back_label ? $law_cal_back_label : __( 'Back to programme', 'law' ) ); ?></a>
						<?php
						if ( function_exists( 'law_booking_render_action_buttons' ) ) {
							law_booking_render_action_buttons( $event, $law_cal_preview );
						}
						?>
					</div>
					<?php
					// The "Read full bio" dialogs every card above registered, printed
					// here and nowhere else, outside both columns. They must land outside
					// the sessions accordion, because a closed <details> renders nothing
					// and a dialog inside one could never be opened; and outside the
					// speakers sidebar, so it makes no difference which column registered
					// a card.
					foreach ( law_speaker_dialogs() as $law_cal_dialog ) {
						get_template_part( 'parts/events/speaker-bio-modal', null, $law_cal_dialog );
					}
					?>
				</article>

			<?php else : ?>

				<?php get_template_part( 'parts/calendar-filters' ); ?>

				<?php
				// The day tabs sit between the filters and the results, as a direct
				// child of .law-cal: the bar is sticky, and a sticky element only
				// sticks within its parent's box, so it has to share a parent with
				// the results it scrolls over rather than live inside the controls.
				?>
				<?php get_template_part( 'parts/calendar-daynav' ); ?>

				<?php
				// Announcements go through this status element (calendar-tabs.js
				// writes "Tuesday, 1 December 2026: 32 events" on a tab switch and
				// after a filter fetch) rather than an aria-live region around the
				// results, which would read a whole day's cards aloud every time a
				// tab showed or hid one.
				?>
				<p class="show-for-sr" id="law-cal-status" role="status"></p>

				<div class="law-cal-events" id="law-cal-events">
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
