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
 *   $law_cal_sessions_heading (string) Heading over the session timeline.
 *                                 Defaults to "Sessions"; the flagship page
 *                                 calls its running order an "Agenda". Every
 *                                 event renders its sessions open on the same
 *                                 vertical timeline
 *                                 (parts/events/session-timeline.php).
 *   $law_cal_sessions_panel (bool) Render that timeline reversed inside a
 *                                 filled navy panel. The flagship page sets it:
 *                                 its agenda is the substance of the page, and
 *                                 the panel marks it out as the one day-long
 *                                 paid event. The same flag also moves the
 *                                 timeline OUT of the reading column to the
 *                                 full width of the article below both
 *                                 columns, a panel that wide having no business
 *                                 in two thirds of the row.
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
$law_cal_sessions_heading = ( isset( $law_cal_sessions_heading ) && '' !== trim( (string) $law_cal_sessions_heading ) ) ? (string) $law_cal_sessions_heading : __( 'Sessions', 'law' );
$law_cal_sessions_panel   = ! empty( $law_cal_sessions_panel );

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
		// Any PRICED event: the flagship conference and the paid receptions.
		// The helper returns '' for anything free, so the row drops out of
		// every other event's box on its own (an empty value is skipped)
		// rather than needing a caller to know about it. There is no Places
		// row beside it: the count is printed in the availability panel below,
		// opposite its button (Denis, 11 September 2026).
		//
		// The box states the NET, "£45.00 + VAT", the way LAW quotes it; the
		// arithmetic belongs in the dialog, next to the consent to pay it, and
		// doing the sum twice is two places for it to disagree.
		array(
			'key'   => 'price',
			'label' => 'Price',
			'value' => function_exists( 'law_flagship_details_price' ) ? law_flagship_details_price( $event ) : '',
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
				// What goes in the sidebar beside the reading column (Denis, 15
				// September 2026): reference -- who is on, where it is -- rather than
				// part of the thread the reader follows down the page, which is the
				// description and then the running order.
				//
				// The Speakers list renders only for an event WITHOUT sessions. When
				// there are sessions each speaker already appears in the session panel
				// they speak in, and a sidebar copy would print every card (and its
				// bio dialog) twice on the page. Before this the list sat under the
				// running order, where it pushed everything else down the page.
				$law_cal_speakers_aside = empty( $event['sessions'] ) && ! empty( $event['speakers'] );

				// The venue is in the SIDEBAR on the flagship and in the reading
				// column on every other event (Denis, 15 September 2026). The two
				// pages read differently: the flagship's reading column holds the
				// description and nothing else, since its agenda is a panel below
				// both columns, so an address under it would sit in a half-empty
				// column with the sidebar beside it; a hosted event's column carries
				// the description and then the running order, and the address reads
				// as the last fact of that run, which is where it has lived since 9
				// September 2026.
				//
				// Keyed off $law_cal_sessions_panel so ONE flag carries the whole
				// flagship layout -- the panel, its placement below the columns, and
				// the venue beside the description -- and no part of it can disagree
				// with another.
				$law_cal_venue_in_main = ! $law_cal_sessions_panel;

				$law_cal_has_venue  = '' !== trim( (string) $event['venue'] );
				$law_cal_venue_side = $law_cal_has_venue && ! $law_cal_venue_in_main;
				$law_cal_has_aside  = $law_cal_speakers_aside || $law_cal_venue_side;

				// The flagship's agenda is a filled panel the width of the article, so
				// it renders BELOW the two columns rather than inside the left one
				// (Denis, 15 September 2026): the flagship page reads description
				// (with the venue beside it), then the day. Every other event keeps
				// its sessions in the reading column, under the description they
				// belong to. $law_cal_sessions_panel is the flagship's own flag
				// (templates/flagship-event.php), so the two cannot disagree.
				$law_cal_sessions_full = $law_cal_sessions_panel;

				// One closure per movable section, called from either of its two
				// positions, so no placement can drift into rendering a different
				// agenda or a different address from the other.
				$law_cal_render_sessions = static function () use ( $event, $law_cal_sessions_heading, $law_cal_sessions_panel ) {
					get_template_part(
						'parts/events/session-timeline',
						null,
						array(
							'sessions' => $event['sessions'],
							'heading'  => $law_cal_sessions_heading,
							'panel'    => $law_cal_sessions_panel,
						)
					);
				};

				$law_cal_render_venue = static function () use ( $event ) {
					get_template_part( 'parts/events/event-venue', null, array( 'venue' => $event['venue'] ) );
				};
				?>
				<article class="law-cal-detail">
					<div class="grid-x grid-padding-x">
						<?php
						// large-8 only when the sidebar is beside it. With nothing to put
						// in the sidebar -- no speakers to list and no venue stated -- the
						// column takes the whole row rather than leaving a third of it
						// empty. The prose keeps its own 65ch measure either way
						// (calendar.css), so the wider column does not make anything
						// harder to read.
						?>
						<div class="<?php echo $law_cal_has_aside ? 'large-8' : 'large-12'; ?> cell law-cal-detail__main">
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
							<?php if ( ! empty( $event['sessions'] ) && ! $law_cal_sessions_full ) : ?>
								<?php
								// Every event's running order renders as an open timeline
								// (Denis, 11 September 2026). The accordion this used to be
								// collapsed the one thing a reader scans a programme for, and
								// having two layouts for the same four sessions was a
								// difference with no reason behind it. Only the heading
								// differs: the flagship calls its day an Agenda, and renders
								// it below this row instead (see $law_cal_sessions_full).
								$law_cal_render_sessions();
								?>
							<?php endif; ?>
							<?php
							// The address at the foot of the reading column, below the
							// sessions: the running order is what the reader came for and
							// the venue is a detail they need once (Denis, 9 September
							// 2026). The facts box in the hero links down to it, so it is
							// still one click from the top. The flagship renders it in the
							// sidebar instead.
							?>
							<?php if ( $law_cal_venue_in_main ) : ?>
								<?php $law_cal_render_venue(); ?>
							<?php endif; ?>
						</div>
						<?php if ( $law_cal_has_aside ) : ?>
							<div class="large-4 cell law-cal-detail__sidebar">
								<?php
								// The reference column: things to look up rather than things
								// to read through (Denis, 15 September 2026). On a hosted
								// event that is the Speakers list, which answers "who is
								// this?" where the reader is already looking instead of
								// pushing the running order down the page. On the flagship
								// it is the venue, beside a description that would otherwise
								// have half a row to itself.
								//
								// A plain wrapper, not an <aside> labelled by the speakers
								// heading: it can hold either section or both, and each
								// carries its own heading and its own aria-labelledby.
								?>
								<div class="law-cal-detail__aside">
									<?php if ( $law_cal_speakers_aside ) : ?>
										<?php
										// One card per row in here (calendar.css): the
										// two-per-row list is sized for a full-width column.
										?>
										<section class="law-cal-detail__aside-section" aria-labelledby="law-cal-speakers-heading">
											<h2 id="law-cal-speakers-heading" class="law-cal-acc__heading">Speakers</h2>
											<ul class="law-cal-speakers law-cal-speakers--cards">
												<?php foreach ( $event['speakers'] as $speaker ) : ?>
													<?php get_template_part( 'parts/events/speaker-card', null, array( 'speaker' => $speaker ) ); ?>
												<?php endforeach; ?>
											</ul>
										</section>
									<?php endif; ?>
									<?php if ( $law_cal_venue_side ) : ?>
										<?php $law_cal_render_venue(); ?>
									<?php endif; ?>
								</div>
							</div>
						<?php endif; ?>
					</div>
					<?php if ( ! empty( $event['sessions'] ) && $law_cal_sessions_full ) : ?>
						<?php
						// The flagship's agenda, at the full width of the article and
						// below both columns. Same call as the one in the reading
						// column above, so the two placements cannot render different
						// agendas.
						$law_cal_render_sessions();
						?>
					<?php endif; ?>
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
					// here and nowhere else, outside both columns: outside the session
					// timeline, and outside the speakers sidebar, so it makes no
					// difference which column registered a card. (This mattered most
					// under the old sessions accordion, where a closed <details> renders
					// nothing and a dialog inside one could never be opened.)
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
