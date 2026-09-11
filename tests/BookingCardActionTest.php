<?php
/**
 * The booking button on an event card (parts/loop/event.php), the state
 * resolver both booking surfaces read (law_booking_state()) and the dialog
 * fragment the card's button fetches (law_booking_maybe_render_dialog()).
 *
 * The card carries a button and no dialog, so what is pinned here is: which
 * state each situation resolves to, that the card and the single event page
 * cannot disagree about it, that the card never queries per event, and that the
 * fragment endpoint refuses everything the submit handler would refuse.
 */

require_once __DIR__ . '/class-law-test-case.php';

class BookingCardActionTest extends LAW_Test_Case {

	private $source_before;
	/** The flagship filter this class installs, so only it is removed again. */
	private $flagship_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->source_before = get_option( 'law_events_source', null );
		update_option( 'law_events_source', 'cpt' );
	}

	protected function tearDown(): void {
		// Only this class's own callback: remove_all_filters() here would strip
		// the ones the flagship suites install in their setUp, and these classes
		// share a process.
		if ( $this->flagship_filter ) {
			remove_filter( 'law_flagship_event_id', $this->flagship_filter );
			$this->flagship_filter = null;
			law_flagship_event_id( true );
		}
		if ( null === $this->source_before ) {
			delete_option( 'law_events_source' );
		} else {
			update_option( 'law_events_source', $this->source_before );
		}
		parent::tearDown();
	}

	private function bookable_event( array $meta = array(), $status = 'publish' ): int {
		return $this->make_event(
			array_merge(
				array(
					'_law_tickets_available' => 10,
					'_law_start'             => gmdate( 'Y-m-d H:i', strtotime( '+30 days' ) ),
					'_law_end'               => gmdate( 'Y-m-d H:i', strtotime( '+30 days +2 hours' ) ),
				),
				$meta
			),
			$status
		);
	}

	private function card( int $event_id, $scope = 'full' ) {
		return law_booking_card_action( array( 'id' => $event_id, 'title' => get_the_title( $event_id ) ), $scope );
	}

	/* The states ___________________________________________________________ */

	public function test_a_published_event_with_places_resolves_bookable(): void {
		$state = law_booking_state( $this->bookable_event(), array( 'user_id' => 0 ) );
		$this->assertSame( 'bookable', $state['state'] );
		$this->assertSame( 10, $state['remaining'] );
		$this->assertSame( 'book', $state['mode'] );
	}

	public function test_an_event_with_no_places_released_resolves_not_open(): void {
		$event = $this->bookable_event( array( '_law_tickets_available' => 0 ) );
		$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
	}

	public function test_a_started_event_resolves_closed(): void {
		$event = $this->bookable_event( array( '_law_start' => gmdate( 'Y-m-d H:i', strtotime( '-1 hour' ) ) ) );
		$this->assertSame( 'closed', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
	}

	public function test_a_sold_out_event_resolves_full_in_waitlist_mode(): void {
		$event = $this->bookable_event( array( '_law_tickets_available' => 1 ) );
		$this->make_booking( $event, $this->make_user( 'attendee' ) );
		$state = law_booking_state( $event, array( 'user_id' => 0 ) );
		$this->assertSame( 'full', $state['state'] );
		$this->assertSame( 'waitlist', $state['mode'] );
	}

	public function test_an_unpublished_event_resolves_to_nothing_at_all(): void {
		$this->assertNull( law_booking_state( $this->bookable_event( array(), 'law-proposed' ), array( 'user_id' => 0 ) ) );
	}

	public function test_a_preview_resolves_an_unpublished_event_anyway(): void {
		$event = $this->bookable_event( array(), 'law-proposed' );
		$state = law_booking_state( $event, array( 'preview' => true ) );
		$this->assertSame( 'bookable', $state['state'], 'A preview answers "what will an attendee see".' );
	}

	public function test_the_legacy_source_resolves_to_nothing(): void {
		$event = $this->bookable_event();
		update_option( 'law_events_source', 'gf' );
		$this->assertNull( law_booking_state( $event, array( 'user_id' => 0 ) ) );
	}

	public function test_a_booked_viewer_resolves_booked_with_a_manage_url(): void {
		$event = $this->bookable_event();
		$user  = $this->make_user( 'attendee' );
		$this->make_booking( $event, $user );
		$state = law_booking_state( $event, array( 'user_id' => $user ) );
		$this->assertSame( 'booked', $state['state'] );
		$this->assertNotNull( $state['booking'] );
		$this->assertNotSame( '', $state['manage_url'] );
	}

	public function test_a_waitlisted_viewer_resolves_waitlisted(): void {
		$event = $this->bookable_event( array( '_law_tickets_available' => 1 ) );
		$this->make_booking( $event, $this->make_user( 'attendee' ) );
		$waiter = $this->make_user( 'attendee' );
		$this->make_waitlist( $event, $waiter );
		$this->assertSame( 'waitlisted', law_booking_state( $event, array( 'user_id' => $waiter ) )['state'] );
	}

	/**
	 * The one state that is not exclusive: someone who brought colleagues but
	 * has no place of their own still gets the availability state underneath,
	 * so they can still book themselves. The event page prints both.
	 */
	public function test_colleagues_are_counted_alongside_the_availability_state(): void {
		$event  = $this->bookable_event();
		$booker = $this->make_user( 'attendee' );
		$this->make_booking(
			$event,
			$booker,
			array( array( 'name' => 'Jo Colleague', 'email' => 'jo-' . wp_generate_password( 6, false ) . '@example.test', 'organisation' => 'Firm', 'job_title' => 'Counsel' ) )
		);
		law_booking_cancel( (int) law_booking_user_booking_for_event( $booker, $event )->ID, $booker );

		$state = law_booking_state( $event, array( 'user_id' => $booker ) );
		$this->assertNull( $state['booking'] );
		$this->assertSame( 1, $state['colleagues'] );
		$this->assertSame( 'bookable', $state['state'], 'They have no place, so they can still take one.' );
		$this->assertNotSame( '', $state['manage_url'], 'And they keep the route to the colleague they brought.' );
	}

	/* The card button ______________________________________________________ */

	public function test_the_card_offers_register_on_a_bookable_event(): void {
		$action = $this->card( $this->bookable_event() );
		$this->assertSame( 'Register', $action['label'] );
		$this->assertStringContainsString( 'law_book=1', $action['url'] );
		$this->assertStringContainsString( 'orange', $action['class'] );
	}

	public function test_the_card_offers_the_waitlist_when_the_event_is_full(): void {
		$event = $this->bookable_event( array( '_law_tickets_available' => 1 ) );
		$this->make_booking( $event, $this->make_user( 'attendee' ) );
		$action = $this->card( $event );
		$this->assertSame( 'Join waitlist', $action['label'] );
		$this->assertStringContainsString( 'law_waitlist=1', $action['url'] );
	}

	public function test_the_card_offers_nothing_when_bookings_have_not_opened_or_have_closed(): void {
		$this->assertNull( $this->card( $this->bookable_event( array( '_law_tickets_available' => 0 ) ) ) );
		$this->assertNull( $this->card( $this->bookable_event( array( '_law_start' => gmdate( 'Y-m-d H:i', strtotime( '-1 hour' ) ) ) ) ) );
	}

	public function test_the_card_offers_nothing_on_an_unpublished_event(): void {
		$this->assertNull( $this->card( $this->bookable_event( array(), 'law-proposed' ) ) );
	}

	/**
	 * The flagship is applied for, not booked, and it has its own card. A
	 * Register button here would be a second, wrong route into it -- which
	 * law_booking_guard_open() would refuse anyway.
	 */
	public function test_the_card_offers_nothing_on_the_flagship(): void {
		$event = $this->bookable_event( array( '_law_is_flagship' => 1 ) );
		// Filtered, the way the other flagship suites do it: the live database
		// already has a real flagship with a lower ID, and law_flagship_event_id()
		// takes the lowest. tearDown removes it again.
		$this->flagship_filter = fn() => $event;
		add_filter( 'law_flagship_event_id', $this->flagship_filter );
		law_flagship_event_id( true );
		$this->assertTrue( law_flagship_is( $event ) );

		$this->assertSame( 'flagship', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
		$this->assertNull( $this->card( $event ) );
	}

	public function test_a_booked_viewer_gets_a_manage_link_in_full_scope_and_nothing_in_action_scope(): void {
		$event = $this->bookable_event();
		$user  = $this->make_user( 'attendee' );
		$this->make_booking( $event, $user );
		wp_set_current_user( $user );

		$this->assertSame( 'Manage booking', $this->card( $event, 'full' )['label'] );
		$this->assertNull(
			$this->card( $event, 'action' ),
			'My bookings and My events already link to the booking; a second link is noise.'
		);
	}

	/**
	 * The dialog is hooked up for a signed-out visitor too. Sending them to
	 * ?law_book=1 instead made the press stop behaving like a press: being told
	 * an account is needed is exactly what the dialog is for (Denis,
	 * 11 September 2026).
	 */
	public function test_the_dialog_hook_is_emitted_for_signed_out_visitors_too(): void {
		$event = $this->bookable_event();

		wp_set_current_user( 0 );
		$this->assertSame( $event, $this->card( $event )['dialog'] );

		wp_set_current_user( $this->make_user( 'attendee' ) );
		$this->assertSame( $event, $this->card( $event )['dialog'] );
	}

	/**
	 * And the dialog they get says so, with both ways out of it, each returning
	 * to the event they pressed.
	 */
	public function test_the_signed_out_dialog_explains_the_account_requirement(): void {
		$event = $this->bookable_event();
		wp_set_current_user( 0 );

		$html = $this->render_dialog( $event );
		$this->assertStringContainsString( 'You need an account to book places', $html );
		$this->assertStringContainsString( 'Sign in', $html );
		$this->assertStringContainsString( 'Create an account', $html );
		$this->assertStringContainsString( 'role=attendee', $html );
		$this->assertStringNotContainsString( 'law-booking-form', $html, 'No form until they have an account.' );
	}

	public function test_the_button_names_the_event_for_a_screen_reader(): void {
		$event  = $this->bookable_event();
		$action = $this->card( $event );
		$this->assertStringContainsString( get_the_title( $event ), $action['sr_label'] );
	}

	/* Queries ______________________________________________________________ */

	/**
	 * The programme renders the whole week at once. Resolving the viewer's own
	 * place must cost the same whether there are two cards or two hundred, or
	 * the page gains a query per event.
	 */
	public function test_resolving_many_cards_does_not_query_per_card(): void {
		$user = $this->make_user( 'attendee' );
		wp_set_current_user( $user );
		$events = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$events[] = $this->bookable_event();
		}
		$this->make_booking( $events[0], $user );

		// The state of play on a real programme render: the events and their
		// meta are already in cache, because law_events_cpt_mapped_events()
		// primes both in one query for the whole week. What is measured here is
		// only what resolving each card's control then costs on top.
		_prime_post_caches( $events, false, true );
		law_booking_user_bookings_by_event( $user );
		law_flagship_event_id(); // Memoised per request, so the first card would otherwise pay for it.

		$before = get_num_queries();
		foreach ( $events as $event_id ) {
			law_booking_state( $event_id, array( 'user_id' => $user ) );
		}
		$this->assertSame(
			0,
			get_num_queries() - $before,
			'The booking map is built once per request, so a card costs no query of its own.'
		);
	}

	public function test_the_batch_map_agrees_with_the_single_event_lookup(): void {
		$event  = $this->bookable_event();
		$other  = $this->bookable_event();
		$user   = $this->make_user( 'attendee' );
		$this->make_booking( $event, $user );

		$statuses = law_booking_holding_statuses();
		$this->assertSame(
			(int) law_booking_user_bookings_by_event( $user )[ $event ]['own']->ID,
			(int) law_booking_user_booking_for_event( $user, $event, $statuses )->ID
		);
		$this->assertNull( law_booking_user_booking_for_event( $user, $other, $statuses ) );
	}

	/* The single event page uses the same flow _____________________________ */

	/**
	 * The event page's own Register button carries no dialog either, since
	 * 11 September 2026: one flow everywhere, so the placeholder, the fetch and
	 * the skeleton behave identically wherever somebody presses Register, and
	 * the page stops shipping ~4KB of dialog nobody may open.
	 */
	public function test_the_event_page_opener_is_a_fetch_link_with_no_dialog(): void {
		$event = $this->bookable_event();
		wp_set_current_user( $this->make_user( 'attendee' ) );

		ob_start();
		law_booking_render_opener( law_events_map_post( get_post( $event ) ), 'book' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-law-book="' . $event . '"', $html );
		$this->assertStringContainsString( 'law_book=1', $html, 'Still a real link, for the no-JS path.' );
		$this->assertStringNotContainsString( 'data-law-modal-open', $html );
		$this->assertStringNotContainsString( 'law-booking-modal', $html );
	}

	/**
	 * Signed out, the event page DOES hook up the dialog, unlike a card: the
	 * page already carries the form styles for everyone, so a visitor gets the
	 * sign in / create an account panel in a dialog rather than a page reload.
	 */
	public function test_the_event_page_opener_hooks_up_the_dialog_for_a_signed_out_visitor(): void {
		$event = $this->bookable_event();
		wp_set_current_user( 0 );

		ob_start();
		law_booking_render_opener( law_events_map_post( get_post( $event ) ), 'book' );
		$this->assertStringContainsString( 'data-law-book="' . $event . '"', (string) ob_get_clean() );
	}

	/**
	 * The event page's panel reads words-left, button-right, exactly as the
	 * flagship's does (Denis, 11 September 2026): one layout for both, built in
	 * one place (law_booking_panel()). Flat, as direct flex items, a state's
	 * heading took a row of its own above the row holding its own explanatory
	 * line and the button.
	 */
	public function test_the_event_pages_panel_puts_the_words_left_and_the_button_right(): void {
		$event = $this->bookable_event();
		$user  = $this->make_user( 'attendee' );
		$this->make_booking( $event, $user );
		wp_set_current_user( $user );

		ob_start();
		law_booking_render_action( law_events_map_post( get_post( $event ) ) );
		$html = (string) ob_get_clean();

		$main = '';
		if ( preg_match( '~<div class="law-booking-panel__main">(.*?)</div>~s', $html, $m ) ) {
			$main = $m[1];
		}
		$this->assertStringContainsString( 'law-booking-state', $main, 'The heading is in the left slot.' );
		$this->assertStringContainsString( esc_html__( "You're booked on this event.", 'law' ), $main );
		$this->assertStringNotContainsString( 'class="button', $main, 'And the button is not: it has a slot of its own.' );
		$this->assertStringContainsString( '<div class="law-booking-panel__action">', $html );
		$this->assertLessThan(
			strpos( $html, 'law-booking-panel__action' ),
			strpos( $html, 'law-booking-panel__main' ),
			'The words must precede the button in the panel.'
		);
	}

	/**
	 * The availability pill belongs WITH the count, inside the left slot and
	 * directly above it (Denis, 11 September 2026): "Almost full" and "Only 3
	 * places left" are one statement, and a pill spanning the whole panel read
	 * as a banner over the button as well.
	 */
	public function test_the_availability_pill_sits_above_the_count_in_the_left_slot(): void {
		$event = $this->bookable_event();
		wp_set_current_user( $this->make_user( 'attendee' ) );

		ob_start();
		law_booking_render_action( law_events_map_post( get_post( $event ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString(
			'<div class="law-booking-panel__main"><span class="law-booking-panel__status">Booking open</span>'
			. '<p class="law-booking-panel__count">',
			$html
		);
		// Not a flex item of the panel any more, so it must lay out as a block.
		$this->assertStringContainsString(
			".law-event-details .law-booking-panel__status {\n  display: block;",
			(string) file_get_contents( get_theme_file_path( 'assets/css/calendar.css' ) )
		);
	}

	/**
	 * The one state with TWO buttons keeps them together in the right-hand
	 * slot. Somebody who booked colleagues but has no place themselves is
	 * offered "Manage bookings" and "Register"; before the slots those
	 * straddled the panel with the places count between them.
	 */
	public function test_the_colleagues_only_state_keeps_both_buttons_in_the_action_slot(): void {
		$event  = $this->bookable_event();
		$booker = $this->make_user( 'attendee' );
		// A colleague's place, and then not one of their own: the engine makes
		// the booker's row first, so it is cancelled to reach this state.
		$this->make_booking(
			$event,
			$booker,
			array( array( 'name' => 'Jo Colleague', 'email' => 'jo-' . wp_generate_password( 6, false ) . '@example.test', 'organisation' => 'Firm', 'job_title' => 'Counsel' ) )
		);
		law_booking_cancel( (int) law_booking_user_booking_for_event( $booker, $event )->ID, $booker );
		wp_set_current_user( $booker );

		ob_start();
		law_booking_render_action( law_events_map_post( get_post( $event ) ) );
		$html = (string) ob_get_clean();

		$action = '';
		if ( preg_match( '~<div class="law-booking-panel__action">(.*?)</div>\s*</div>~s', $html, $m ) ) {
			$action = $m[1];
		}
		$this->assertStringContainsString( 'Manage bookings', $action );
		$this->assertStringContainsString( 'Register', $action );
		// The count stays on the left, where every other word is.
		$this->assertStringNotContainsString( 'law-booking-panel__count', $action );

		// And the slot is a row of its own, so the pair does not run together.
		$this->assertStringContainsString(
			".law-event-details .law-booking-panel__action {\n  display: flex;",
			(string) file_get_contents( get_theme_file_path( 'assets/css/calendar.css' ) )
		);
	}

	/**
	 * The committee preview renders the button where an attendee will find it
	 * but must never be actuable, so it is a disabled <button> with nothing to
	 * fetch and nothing to open.
	 */
	public function test_the_committee_preview_opener_stays_inert(): void {
		$event = $this->bookable_event( array(), 'law-proposed' );

		ob_start();
		law_booking_render_opener( law_events_map_post( get_post( $event ), array( '*' ) ), 'book', true );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( 'data-law-book', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	/**
	 * The placeholder renders on every surface that emits data-law-book -- the
	 * single event view and the card views -- and for everybody. Miss one and
	 * the press there is silent for the length of the fetch.
	 */
	public function test_the_placeholder_renders_on_every_surface_with_a_fetched_dialog(): void {
		$source = file_get_contents( get_theme_file_path( 'functions/account-bookings.php' ) );
		$this->assertStringContainsString( '! law_booking_is_event_view() && ! law_booking_is_card_view()', $source );
	}

	/* The repeat at the foot of the event page _____________________________ */

	public function test_the_foot_of_the_page_repeats_the_button_without_the_wording(): void {
		$event = $this->bookable_event();
		wp_set_current_user( $this->make_user( 'attendee' ) );

		ob_start();
		law_booking_render_action_buttons( law_events_map_post( get_post( $event ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-law-book="' . $event . '"', $html );
		$this->assertStringContainsString( 'Register', $html );
		// The state has been explained in the hero; repeating it under the back
		// link would read as a second, competing control.
		$this->assertStringNotContainsString( 'law-booking-state', $html );
		$this->assertStringNotContainsString( 'law-booking-substate', $html );
	}

	public function test_the_foot_of_the_page_links_to_a_booking_already_held(): void {
		$event = $this->bookable_event();
		$user  = $this->make_user( 'attendee' );
		$this->make_booking( $event, $user );
		wp_set_current_user( $user );

		ob_start();
		law_booking_render_action_buttons( law_events_map_post( get_post( $event ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Manage booking', $html );
		$this->assertStringNotContainsString( 'data-law-book', $html );
	}

	public function test_the_foot_of_the_page_is_empty_when_there_is_nothing_to_press(): void {
		$closed = $this->bookable_event( array( '_law_start' => gmdate( 'Y-m-d H:i', strtotime( '-1 hour' ) ) ) );
		ob_start();
		law_booking_render_action_buttons( law_events_map_post( get_post( $closed ) ) );
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	/**
	 * On the no-JS path the opener IS the form. Repeating it at the foot would
	 * put two copies of the same form, with the same field names and a
	 * duplicated id, on one page.
	 */
	public function test_the_foot_of_the_page_does_not_repeat_the_inline_no_js_form(): void {
		$event = $this->bookable_event();
		wp_set_current_user( $this->make_user( 'attendee' ) );

		$_GET['law_book'] = '1';
		ob_start();
		law_booking_render_action_buttons( law_events_map_post( get_post( $event ) ) );
		$html = (string) ob_get_clean();
		unset( $_GET['law_book'] );

		$this->assertSame( '', trim( $html ) );
	}

	/* The dialog fragment __________________________________________________ */

	public function test_the_dialog_endpoint_serves_the_booking_form_for_a_bookable_event(): void {
		$event = $this->bookable_event();
		wp_set_current_user( $this->make_user( 'attendee' ) );
		$html = $this->render_dialog( $event );
		$this->assertStringContainsString( 'id="law-booking-modal"', $html );
		$this->assertStringContainsString( 'name="event_id" value="' . $event . '"', $html );
		$this->assertStringContainsString( 'id="law-booking-success"', $html );
	}

	/**
	 * A card rendered while places were free, clicked after the event filled
	 * up. The server decides the mode, so the reader gets the waitlist form
	 * rather than one the handler would refuse.
	 */
	public function test_the_dialog_endpoint_answers_a_stale_card_with_the_waitlist_form(): void {
		$event = $this->bookable_event( array( '_law_tickets_available' => 1 ) );
		$this->make_booking( $event, $this->make_user( 'attendee' ) );
		wp_set_current_user( $this->make_user( 'attendee' ) );
		$html = $this->render_dialog( $event );
		$this->assertStringContainsString( 'id="law-waitlist-modal"', $html );
		$this->assertStringContainsString( 'value="law_waitlist_join"', $html );
	}

	public function test_the_dialog_endpoint_refuses_what_the_submit_handler_would_refuse(): void {
		// One predicate, law_booking_guard_open(), so the two cannot diverge.
		$this->assertWPError( law_booking_guard_open( $this->bookable_event( array(), 'law-proposed' ) ), 'law_booking_not_bookable' );
		$this->assertWPError( law_booking_guard_open( $this->bookable_event( array( '_law_tickets_available' => 0 ) ) ), 'law_booking_not_open' );
		$this->assertWPError( law_booking_guard_open( $this->bookable_event( array( '_law_start' => gmdate( 'Y-m-d H:i', strtotime( '-1 hour' ) ) ) ) ), 'law_booking_closed' );

		$source = file_get_contents( get_theme_file_path( 'functions/account-bookings.php' ) );
		$this->assertStringContainsString( 'law_booking_guard_open( $event_id )', $source );
	}

	/**
	 * The fragment must not be reachable through ?law_partial=1, which on an
	 * event permalink already means "give me the programme list".
	 */
	public function test_the_dialog_endpoint_uses_its_own_query_var(): void {
		$source = file_get_contents( get_theme_file_path( 'functions/account-bookings.php' ) );
		$this->assertStringContainsString( "\$_GET['law_dialog']", $source );
		$this->assertStringNotContainsString( "add_action( 'template_redirect', 'law_booking_maybe_render_dialog', 9 )", $source );
	}

	/** Render the fragment the way the endpoint does, without the exit. */
	private function render_dialog( int $event_id ): string {
		$event = law_events_map_post( get_post( $event_id ) );
		$mode  = 0 === (int) law_event_tickets_remaining( $event_id ) ? 'waitlist' : 'book';
		ob_start();
		get_template_part( 'parts/events/booking-modal', null, array( 'event' => $event, 'context' => 'modal', 'mode' => $mode ) );
		get_template_part( 'parts/events/booking-success-modal', null, array( 'event' => $event, 'mode' => $mode, 'close_url' => home_url( '/programme/' ) ) );
		return (string) ob_get_clean();
	}

	/**
	 * Cards carry no dialog, so there is a round trip between the press and the
	 * form. The placeholder is what answers the press immediately; without it
	 * the button looked dead and people pressed it twice.
	 */
	public function test_the_loading_placeholder_renders_once_for_a_signed_in_card_view(): void {
		ob_start();
		get_template_part( 'parts/events/booking-loading-modal' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="law-booking-loading"', $html );
		$this->assertStringContainsString( 'law-booking-skeleton', $html );
		// Both headings server-side, so the script picks one rather than
		// carrying a second copy of translated copy.
		$this->assertStringContainsString( 'data-law-loading-book="Book your place"', $html );
		$this->assertStringContainsString( 'data-law-loading-waitlist="Join the waitlist"', $html );

		$source = file_get_contents( get_theme_file_path( 'functions/account-bookings.php' ) );
		$this->assertStringContainsString( "add_action( 'wp_footer', 'law_booking_render_loading_modal' )", $source );
	}

	public function test_the_success_dialog_closes_back_to_where_the_booking_was_made(): void {
		$event = $this->bookable_event();
		wp_set_current_user( $this->make_user( 'attendee' ) );
		$this->assertStringContainsString( esc_url( home_url( '/programme/' ) ), $this->render_dialog( $event ) );
	}
}
