<?php
/**
 * The My bookings / My events split (10 September 2026).
 *
 * A person's own bookings used to sit above the host listing on
 * /account/events/, with the header naming that one page "My events" or
 * "My bookings" depending on who was looking. They are two pages now, with two
 * audiences: My events is the host side, /account/bookings/ is everyone's own
 * bookings, because hosts, sponsors and committee members book places at other
 * firms' events like anyone else.
 *
 * The URLs are what these tests really guard. Nothing asserted on them before,
 * and they are baked into confirmation emails, so a wrong one is only
 * discovered by an attendee who cannot reach their booking.
 */

class MyBookingsPageTest extends LAW_Test_Case {

	private const PATH     = 'account/bookings';
	private const TEMPLATE = 'templates/account-bookings.php';

	private $source_before;

	protected function setUp(): void {
		parent::setUp();
		$this->source_before = get_option( 'law_events_source', null );
		update_option( 'law_events_source', 'cpt' );
	}

	protected function tearDown(): void {
		if ( null === $this->source_before ) {
			delete_option( 'law_events_source' );
		} else {
			update_option( 'law_events_source', $this->source_before );
		}
		parent::tearDown();
	}

	/**
	 * The page is database state, so a git deploy alone has to produce it.
	 * Both provisioning routes must name it or one environment gets the header
	 * link with a 404 behind it.
	 */
	public function test_the_page_is_provisioned_by_both_routes(): void {
		$map = law_migration_page_map();
		$this->assertArrayHasKey( self::PATH, $map, 'Migration step 10 must create the page.' );
		$this->assertSame( self::TEMPLATE, $map[ self::PATH ]['template'] );
		$this->assertSame( 'My bookings', $map[ self::PATH ]['title'] );

		$setup = file_get_contents( get_theme_file_path( 'functions/setup-account-pages.php' ) );
		$this->assertStringContainsString( "\$setup['" . self::PATH . "']", $setup, 'The ?setup-account-pages trigger must assign the template too.' );
		$this->assertStringContainsString( 'law_setup_my_bookings_access', $setup, 'And copy the role rows from the parent page.' );

		$runner = file_get_contents( get_theme_file_path( 'functions/events/migration/runner.php' ) );
		$this->assertStringContainsString( 'law_setup_my_bookings_access', $runner, 'Migration step 10 must copy them as well.' );

		$this->assertFileExists( get_theme_file_path( self::TEMPLATE ) );
	}

	/**
	 * The restriction comes from /account/, not from the events dashboard: this
	 * page is for every signed-in role, not the committee.
	 *
	 * A page created by either route carries no _members_access_role rows at
	 * all, which the Members plugin reads as public.
	 */
	public function test_the_access_helper_copies_the_account_page_rows(): void {
		$parent = get_page_by_path( 'account' );
		if ( ! $parent instanceof WP_Post || ! get_post_meta( $parent->ID, '_members_access_role' ) ) {
			$this->markTestSkipped( 'This environment puts no Members restriction on /account/.' );
		}
		$page = get_page_by_path( self::PATH );
		$this->assertInstanceOf( WP_Post::class, $page, 'Run /wp-admin/?setup-account-pages on this environment.' );

		$this->assertSame( 'ok', law_setup_my_bookings_access(), 'Idempotent once the rows are there.' );
		$this->assertSame(
			get_post_meta( $parent->ID, '_members_access_role' ),
			get_post_meta( $page->ID, '_members_access_role' ),
			'My bookings takes /account/ role rows, so every signed-in role can open it.'
		);
	}

	/** The page hangs off /account/, beside My events rather than under it. */
	public function test_the_page_sits_under_account(): void {
		$page = get_page_by_path( self::PATH );
		$this->assertInstanceOf( WP_Post::class, $page );

		$parent = get_page_by_path( 'account' );
		$this->assertSame( (int) $parent->ID, (int) $page->post_parent );
		$this->assertSame( 'bookings', $page->post_name, 'Not bookings-2: the committee page of the same slug lives under a different parent.' );
		$this->assertSame( self::TEMPLATE, get_post_meta( $page->ID, '_wp_page_template', true ) );
	}

	/**
	 * The split itself. The manage view moved; the per-event attendee list did
	 * not, because that one is about a host's own event.
	 */
	public function test_the_two_booking_urls_point_at_different_pages(): void {
		$this->assertStringContainsString( '/account/bookings/', law_booking_manage_url( 123 ) );
		$this->assertStringContainsString( 'law_booking=123', law_booking_manage_url( 123 ) );

		$this->assertStringContainsString( '/account/events/', law_booking_list_url( 456 ) );
		$this->assertStringContainsString( 'law_event_bookings=456', law_booking_list_url( 456 ) );
	}

	/**
	 * {bookings_link} is what eleven attendee emails carry. It has to be the
	 * new page, and {dashboard_link} has to stay the old one -- they resolved
	 * to the same URL until the split, so nothing would have noticed a swap.
	 */
	public function test_the_email_placeholders_point_at_the_right_pages(): void {
		$event_id     = $this->make_event( array(), 'publish' );
		$placeholders = law_events_email_placeholders( $event_id );

		$this->assertStringContainsString( '/account/bookings/', $placeholders['{bookings_link}'] );
		$this->assertStringContainsString( '/account/events/', $placeholders['{dashboard_link}'] );
		$this->assertStringNotContainsString( '/account/bookings/', $placeholders['{dashboard_link}'] );
	}

	/** The template renders the manage view and the listing, and nothing else. */
	public function test_the_template_carries_the_two_views(): void {
		$template = file_get_contents( get_theme_file_path( self::TEMPLATE ) );

		$this->assertStringContainsString( 'Template Name: My bookings', $template );
		$this->assertStringContainsString( 'parts/events/booking-manage', $template );
		$this->assertStringContainsString( 'law_account_bookings', $template );
		$this->assertStringContainsString( 'nocache_headers', $template, 'Per-user content must not be cached by a proxy.' );
		$this->assertStringNotContainsString( 'booking-list', $template, 'The per-event attendee list stays on My events.' );
	}

	/** And My events keeps the host half, and only the host half. */
	public function test_my_events_no_longer_renders_bookings(): void {
		$template = file_get_contents( get_theme_file_path( 'templates/account-events.php' ) );

		$this->assertStringContainsString( 'parts/events/booking-list', $template, 'The per-event attendee list stays.' );
		$this->assertStringContainsString( 'parts/events/thread', $template, 'So does the committee thread.' );
		$this->assertStringNotContainsString( 'law_account_bookings(', $template );
		// The partial, not the class: .law-booking-manage still wraps the
		// per-event attendee list, which is staying put.
		$this->assertStringNotContainsString( 'parts/events/booking-manage', $template );
		$this->assertStringNotContainsString( "\$_GET['law_booking']", $template, 'The manage view is reached on the bookings page now.' );
	}
}
