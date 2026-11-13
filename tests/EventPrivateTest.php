<?php
/**
 * Private events (Denis, 13 November 2026; functions/events/private-events.php).
 *
 * A hosted event or a reception ticked private is off every public list, and
 * its own page opens only with ?c=<its code>; anyone else is sent to the
 * programme. The code rides on every get_permalink() of the event, so the
 * links the booking flow already builds keep working, which is also why no
 * public list may show one.
 */

require_once __DIR__ . '/class-law-test-case.php';

class EventPrivateTest extends LAW_Test_Case {

	private $source_before;

	protected function setUp(): void {
		parent::setUp();
		$this->source_before = get_option( 'law_events_source', null );
		update_option( 'law_events_source', 'cpt' );
		law_calendar_events( true );
	}

	protected function tearDown(): void {
		if ( null === $this->source_before ) {
			delete_option( 'law_events_source' );
		} else {
			update_option( 'law_events_source', $this->source_before );
		}
		$_POST = array();
		law_calendar_events( true );
		law_speakers_confirmed_maps( true );
		law_private_event_ids( true );
		parent::tearDown();
	}

	/** Confirmed, slotted, venued and with places: on the programme and bookable. */
	private function live_event( array $meta = array(), $status = 'publish', $author = 0 ): int {
		return $this->make_event(
			array_merge(
				array(
					'_law_tickets_available' => 25,
					'_law_venue'             => 'A room with a view',
					'_law_start'             => gmdate( 'Y-m-d H:i', strtotime( '+30 days' ) ),
					'_law_end'               => gmdate( 'Y-m-d H:i', strtotime( '+30 days +2 hours' ) ),
				),
				$meta
			),
			$status,
			$author
		);
	}

	private function make_reception( $status = 'law-draft' ): int {
		return $this->live_event( array( '_law_is_reception' => 1 ), $status );
	}

	private function programme_ids(): array {
		law_calendar_events( true );
		return array_map( 'intval', wp_list_pluck( law_calendar_events(), 'id' ) );
	}

	private function log_messages( int $event_id ): array {
		return wp_list_pluck( law_event_log_entries( $event_id ), 'comment_content' );
	}

	/** The Committee controls panel's own write, as law_committee_action_handler() performs it. */
	private function committee_saves_flags( int $event_id, array $post ): void {
		$_POST  = array_merge( array( 'law_flags_present' => '1' ), $post );
		$before = array( '_law_private' => (int) law_event_meta( $event_id, '_law_private' ) );
		law_event_set_private( $event_id, ! empty( $_POST['law_private'] ) );
		law_event_log_flag_change( $event_id, $before, get_current_user_id() );
		$_POST = array();
	}

	private function render_notice( int $event_id, string $class = '' ): string {
		ob_start();
		get_template_part( 'parts/events/private-notice', null, array( 'event_id' => $event_id, 'class' => $class ) );
		return (string) ob_get_clean();
	}

	/* The flag and its code __________________________________________________ */

	public function test_an_event_is_public_until_it_is_made_private(): void {
		$event = $this->live_event();
		$this->assertFalse( law_event_is_private( $event ) );
		$this->assertSame( '', law_event_private_code( $event ), 'No code until the first tick.' );

		law_event_set_private( $event, true );
		$this->assertTrue( law_event_is_private( $event ) );
		$this->assertMatchesRegularExpression( '/^[a-z2-9]{10}$/', law_event_private_code( $event ) );

		$this->assertFalse( law_event_is_private( 0 ) );
		$this->assertFalse( law_event_is_private( PHP_INT_MAX ) );
	}

	/** Links already sent out must survive the box being unticked and ticked again. */
	public function test_the_code_is_kept_across_an_untick_and_a_re_tick(): void {
		$event = $this->live_event();
		law_event_set_private( $event, true );
		$code = law_event_private_code( $event );

		law_event_set_private( $event, false );
		$this->assertFalse( law_event_is_private( $event ) );
		$this->assertSame( $code, law_event_private_code( $event ) );

		law_event_set_private( $event, true );
		$this->assertSame( $code, law_event_private_code( $event ) );
	}

	public function test_two_events_get_different_codes(): void {
		$one = $this->live_event();
		$two = $this->live_event();
		law_event_set_private( $one, true );
		law_event_set_private( $two, true );
		$this->assertNotSame( law_event_private_code( $one ), law_event_private_code( $two ) );
	}

	/* The link ______________________________________________________________ */

	/**
	 * Every existing get_permalink() caller -- the confirmation email, My
	 * bookings, the booking dialog, Stripe's return URL -- picks the code up.
	 */
	public function test_the_permalink_carries_the_code_only_while_private(): void {
		$event = $this->live_event();
		$this->assertStringNotContainsString( '?c=', get_permalink( $event ) );

		law_event_set_private( $event, true );
		$code = law_event_private_code( $event );
		$this->assertStringContainsString( 'c=' . $code, get_permalink( $event ) );
		$this->assertSame( get_permalink( $event ), law_event_private_url( $event ) );
		$this->assertStringContainsString( 'c=' . $code, law_events_event_url( $event ), 'The programme card link the committee follows.' );

		law_event_set_private( $event, false );
		$this->assertStringNotContainsString( 'c=', get_permalink( $event ) );
	}

	/** A draft's link is the address it WILL have, not ?post_type=law_event&p=N. */
	public function test_a_draft_reception_gets_its_future_address_with_the_code(): void {
		$reception = $this->make_reception( 'law-draft' );
		law_event_set_private( $reception, true );

		$url = law_event_private_url( $reception );
		$this->assertStringNotContainsString( 'post_type=', $url );
		$this->assertStringEndsWith( '/?c=' . law_event_private_code( $reception ), $url );
	}

	/* The page ______________________________________________________________ */

	public function test_the_page_opens_only_with_the_code_or_for_its_managers(): void {
		$host  = $this->make_user();
		$event = $this->live_event( array(), 'publish', $host );
		$this->assertTrue( law_event_private_page_allowed( $event, 0, '' ), 'A public event opens for anyone.' );

		law_event_set_private( $event, true );
		$code = law_event_private_code( $event );

		$this->assertFalse( law_event_private_page_allowed( $event, 0, '' ), 'No code: sent to the programme.' );
		$this->assertFalse( law_event_private_page_allowed( $event, 0, 'wrongcode1' ) );
		$this->assertFalse( law_event_private_page_allowed( $event, $this->make_user(), '' ), 'Signed in is not enough.' );
		$this->assertTrue( law_event_private_page_allowed( $event, 0, $code ) );
		$this->assertTrue( law_event_private_page_allowed( $event, 0, strtoupper( $code ) ), 'A code retyped in capitals still opens it.' );
		$this->assertTrue( law_event_private_page_allowed( $event, $host, '' ), 'The host keeps the page.' );
		$this->assertTrue( law_event_private_page_allowed( $event, $this->make_committee_user(), '' ), 'So does the committee.' );
	}

	/** Another event's code opens nothing. */
	public function test_a_code_belongs_to_one_event(): void {
		$one = $this->live_event();
		$two = $this->live_event();
		law_event_set_private( $one, true );
		law_event_set_private( $two, true );

		$this->assertFalse( law_event_private_page_allowed( $two, 0, law_event_private_code( $one ) ) );
	}

	public function test_the_visitor_without_a_code_is_sent_to_the_programme_page(): void {
		$page_id = law_events_programme_page_id();
		$this->assertSame( $page_id ? get_permalink( $page_id ) : home_url( '/programme/' ), law_events_programme_url() );
	}

	/* Off the public lists __________________________________________________ */

	public function test_a_private_event_drops_off_the_public_programme_only(): void {
		$event = $this->live_event();
		$this->assertContains( $event, $this->programme_ids() );

		law_event_set_private( $event, true );
		$this->assertNotContains( $event, $this->programme_ids() );

		$committee = array_map( 'intval', wp_list_pluck( law_events_cpt_mapped_events( array() ), 'id' ) );
		$this->assertContains( $event, $committee, 'The committee programme still lists it, with the code on its link.' );

		$this->assertIsArray( law_events_map_post( $event ), 'Its own page still maps, for the visitor holding the code.' );
	}

	public function test_a_private_reception_drops_off_the_programme_too(): void {
		$reception = $this->make_reception( 'publish' );
		$this->assertContains( $reception, $this->programme_ids() );

		law_event_set_private( $reception, true );
		$this->assertNotContains( $reception, $this->programme_ids() );
	}

	/**
	 * A speaker heard only at a private event has no public profile, and nobody's
	 * profile lists the event: either would hand out its link, code and all.
	 */
	public function test_the_speakers_archive_leaves_a_private_event_out(): void {
		$speaker = wp_insert_post( array( 'post_type' => LAW_SPEAKER_CPT, 'post_status' => 'publish', 'post_title' => 'Private Speaker' ) );
		$this->posts[] = $speaker;
		$event = $this->live_event( array( '_law_speakers' => array( array( 'speaker_id' => $speaker, 'role' => 'speaker' ) ) ) );

		$this->assertContains( $event, law_speakers_confirmed_maps( true )['events'][ $speaker ] ?? array() );

		law_event_set_private( $event, true );
		$this->assertArrayNotHasKey( $speaker, law_speakers_confirmed_maps( true )['events'] );
		$this->assertNull( law_events_cpt_speaker_profile( $speaker ) );

		$all = law_speakers_event_maps( array( 'publish' ), 500, true );
		$this->assertContains( $event, $all['events'][ $speaker ] ?? array(), 'The committee\'s own walk of every event still sees it.' );
	}

	public function test_the_sitemap_and_post_type_listings_leave_it_out(): void {
		$event = $this->live_event();
		law_event_set_private( $event, true );
		$this->assertContains( $event, law_private_event_ids( true ) );

		$args = apply_filters( 'seopress_sitemaps_single_query', array(), LAW_EVENT_CPT );
		$this->assertContains( $event, (array) ( $args['post__not_in'] ?? array() ) );

		$pages = apply_filters( 'seopress_sitemaps_single_query', array(), 'page' );
		$this->assertNotContains( $event, (array) ( $pages['post__not_in'] ?? array() ), 'Other post types are left alone.' );
	}

	/**
	 * The oEmbed endpoint answers the BARE address with the permalink, code and
	 * all, so a private event gets no embed (found checking the live page).
	 */
	public function test_oembed_gives_nothing_away(): void {
		$event = $this->live_event();
		$this->assertNotFalse( get_oembed_response_data( $event, 600 ) );

		law_event_set_private( $event, true );
		$this->assertFalse( get_oembed_response_data( $event, 600 ) );
	}

	/* Booking is unaffected __________________________________________________ */

	public function test_a_private_event_still_takes_bookings(): void {
		$event = $this->live_event();
		law_event_set_private( $event, true );

		$this->assertTrue( law_event_is_publicly_listed( $event ), 'It still has a live page.' );
		$this->assertTrue( law_booking_guard_open( $event ), 'Whoever holds the link can register.' );
	}

	/* What the committee sees _______________________________________________ */

	public function test_the_badge_marks_a_private_event_and_nothing_else(): void {
		$event = $this->live_event();
		ob_start();
		law_event_private_badge( $event );
		$this->assertSame( '', ob_get_clean() );

		law_event_set_private( $event, true );
		ob_start();
		law_event_private_badge( $event );
		$badge = ob_get_clean();
		$this->assertStringContainsString( 'law-cal-card__badge--private', $badge );
		$this->assertStringContainsString( 'Private', $badge );
	}

	public function test_the_notice_gives_the_link_and_says_when_it_is_not_live_yet(): void {
		$event = $this->live_event();
		$this->assertSame( '', trim( $this->render_notice( $event ) ), 'Nothing on a public event.' );

		law_event_set_private( $event, true );
		$live = $this->render_notice( $event, 'is-warning' );
		$this->assertStringContainsString( 'This event is private', $live );
		$this->assertStringContainsString( esc_html( law_event_private_url( $event ) ), $live );
		$this->assertStringContainsString( 'is-warning', $live );

		$proposed = $this->live_event( array(), 'law-proposed' );
		law_event_set_private( $proposed, true );
		$this->assertStringContainsString( 'not available yet', $this->render_notice( $proposed ) );

		$draft = $this->make_reception( 'law-draft' );
		law_event_set_private( $draft, true );
		$this->assertStringContainsString( 'This reception is a draft and not available yet', $this->render_notice( $draft ) );

		$live_reception = $this->make_reception( 'publish' );
		law_event_set_private( $live_reception, true );
		$this->assertStringContainsString( 'This reception is private', $this->render_notice( $live_reception ) );
	}

	/* Saving and logging ____________________________________________________ */

	public function test_the_committee_panel_sets_clears_and_logs_it(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->live_event();

		$this->committee_saves_flags( $event, array( 'law_private' => '1' ) );
		$this->assertTrue( law_event_is_private( $event ) );
		$this->assertStringContainsString( 'Made private', $this->log_messages( $event )[0] );

		$count = count( $this->log_messages( $event ) );
		$this->committee_saves_flags( $event, array( 'law_private' => '1' ) );
		$this->assertCount( $count, $this->log_messages( $event ), 'Re-saving the same value must not log.' );

		$this->committee_saves_flags( $event, array() );
		$this->assertFalse( law_event_is_private( $event ), 'Unticked posts nothing, and that means off.' );
		$this->assertStringContainsString( 'Made public again', $this->log_messages( $event )[0] );
	}

	public function test_the_reception_editor_saves_it_through_the_shared_saver(): void {
		wp_set_current_user( $this->make_committee_user() );
		$reception = $this->make_reception( 'law-draft' );

		$_POST = array( 'law_reception' => array( 'event_id' => $reception, 'present' => 1, 'private_present' => 1, 'private' => 1 ) );
		$input = law_reception_input_from_post();
		$this->assertTrue( $input['private'] );

		$this->assertSame( $reception, law_reception_save( $input, get_current_user_id(), array( 'partial' => true ) ) );
		$this->assertTrue( law_event_is_private( $reception ) );
		$this->assertTrue( law_reception_form_values( $reception )['private'] );
		$this->assertStringContainsString( 'made private', $this->log_messages( $reception )[0] );

		// A POST truncated before the Privacy fieldset carries no sentinel, and
		// must leave the stored answer alone rather than make it public.
		$_POST = array( 'law_reception' => array( 'event_id' => $reception, 'present' => 1 ) );
		$input = law_reception_input_from_post();
		$this->assertArrayNotHasKey( 'private', $input );
		law_reception_save( $input, get_current_user_id(), array( 'partial' => true ) );
		$this->assertTrue( law_event_is_private( $reception ) );
	}

	/**
	 * The simulated writes above stand in for the real handlers only while the
	 * screens carry the field, so the wiring is pinned here.
	 */
	public function test_every_screen_carries_the_switch(): void {
		$theme = dirname( __DIR__ );
		$panel = (string) file_get_contents( $theme . '/templates/account-dashboard.php' );
		$this->assertStringContainsString( 'name="law_private"', $panel, 'The Committee controls panel.' );
		$this->assertStringContainsString( "'parts/events/private-notice'", $panel, 'The link above the title.' );

		$this->assertStringContainsString(
			"law_event_set_private( \$event_id, ! empty( \$_POST['law_private'] ) )",
			(string) file_get_contents( $theme . '/functions/events/committee.php' )
		);
		$this->assertStringContainsString(
			"law_event_set_private( \$post_id, ! empty( \$_POST['law_private'] ) )",
			(string) file_get_contents( $theme . '/functions/events/admin/event-screen.php' )
		);

		$editor = (string) file_get_contents( $theme . '/parts/events/reception-manage.php' );
		$this->assertStringContainsString( 'name="law_reception[private]"', $editor );
		$this->assertStringContainsString( 'name="law_reception[private_present]"', $editor );

		$this->assertStringContainsString( 'law_event_private_badge(', (string) file_get_contents( $theme . '/parts/events/receptions-list.php' ) );
		$this->assertStringContainsString( 'law_event_private_badge(', (string) file_get_contents( $theme . '/parts/events/dashboard-list.php' ) );
	}
}
