<?php
/**
 * The flagship conference's button on the programme card
 * (`parts/events/flagship-card.php`), the state it is resolved from
 * (`law_flagship_action_state()`) and the label map both that card and the
 * conference page's own control read (`law_flagship_action_link()`).
 *
 * The flagship is applied for, not booked, so it has its own states and its own
 * dialog. What is pinned here is that each application status offers the right
 * button, that the two surfaces cannot word the same state differently, and
 * that the apply dialog's fragment endpoint refuses what the apply handler
 * would refuse.
 */
class FlagshipCardActionTest extends LAW_Test_Case {

	private int $flagship = 0;
	/** This class's own filters, so only these are removed again. */
	private array $filters = array();

	protected function tearDown(): void {
		// Targeted, not remove_all_filters(): these classes share a process, and
		// stripping every callback on a hook takes out the ones another suite
		// installed for its own run.
		foreach ( $this->filters as $hook => $callback ) {
			remove_filter( $hook, $callback );
		}
		$this->filters = array();
		law_flagship_event_id( true );
		parent::tearDown();
	}

	private function add_filter_once( string $hook, callable $callback ): void {
		$this->filters[ $hook ] = $callback;
		add_filter( $hook, $callback );
	}

	/** A published flagship on sale, pinned as "the" flagship. */
	private function make_flagship( array $meta = array(), string $status = 'publish' ): int {
		$event_id = $this->make_event(
			array_merge(
				array(
					'_law_is_flagship'               => 1,
					'_law_flagship_date'             => '2026-12-02',
					'_law_start'                     => gmdate( 'Y-m-d H:i', strtotime( '+60 days' ) ),
					'_law_end'                       => gmdate( 'Y-m-d H:i', strtotime( '+60 days +8 hours' ) ),
					'_law_flagship_price_pence'      => 55000,
					'_law_flagship_price_late_pence' => 60000,
					'_law_flagship_price_switch'     => '2026-10-17 00:00',
					'_law_tickets_available'         => 50,
				),
				$meta
			),
			$status
		);
		$this->flagship = $event_id;
		$this->add_filter_once( 'law_flagship_event_id', fn() => $this->flagship );
		$this->add_filter_once( 'pre_option_law_events_source', fn() => 'cpt' );
		law_flagship_event_id( true );

		return $event_id;
	}

	/** An application in a given status, without going near Stripe. */
	private function make_application( int $event_id, int $user_id, string $status, string $payment = '' ): int {
		$booking_id = wp_insert_post(
			array(
				'post_type'   => LAW_BOOKING_CPT,
				'post_parent' => $event_id,
				'post_status' => $status,
				'post_author' => $user_id,
				'post_title'  => 'Application',
			)
		);
		$this->posts[] = $booking_id;
		if ( '' !== $payment ) {
			law_event_update_meta( $booking_id, '_law_payment_status', $payment );
		}
		return (int) $booking_id;
	}

	private function card( int $event_id ) {
		return law_flagship_card_action( array( 'id' => $event_id, 'title' => get_the_title( $event_id ) ) );
	}

	/* The states ___________________________________________________________ */

	public function test_a_visitor_with_no_application_is_offered_apply(): void {
		$event = $this->make_flagship();
		$state = law_flagship_action_state( $event, array( 'user_id' => 0 ) );
		$this->assertSame( 'apply', $state['state'] );
		$this->assertSame( 'Apply', law_flagship_action_link( $state )['label'] );
	}

	public function test_an_unpriced_conference_is_not_open(): void {
		$event = $this->make_flagship( array( '_law_flagship_price_pence' => 0, '_law_flagship_price_late_pence' => 0 ) );
		$state = law_flagship_action_state( $event, array( 'user_id' => 0 ) );
		$this->assertSame( 'not-open', $state['state'] );
		$this->assertNull( law_flagship_action_link( $state ), 'Nothing to press until it is on sale.' );
	}

	public function test_a_conference_that_has_happened_offers_nothing_to_a_visitor(): void {
		$event = $this->make_flagship( array( '_law_start' => gmdate( 'Y-m-d H:i', strtotime( '-2 days' ) ) ) );
		$state = law_flagship_action_state( $event, array( 'user_id' => 0 ) );
		$this->assertSame( 'past', $state['state'] );
		$this->assertNull( law_flagship_action_link( $state ) );
	}

	public function test_an_application_awaiting_payment_details_asks_for_them(): void {
		$event = $this->make_flagship();
		$user  = $this->make_user( 'attendee' );
		$this->make_application( $event, $user, 'law-applied', 'pending_setup' );

		$state = law_flagship_action_state( $event, array( 'user_id' => $user ) );
		$this->assertSame( 'needs-card', $state['state'] );
		$this->assertSame( 'Add my payment details', law_flagship_action_link( $state )['label'] );
	}

	public function test_an_application_with_a_card_saved_is_in_review(): void {
		$event = $this->make_flagship();
		$user  = $this->make_user( 'attendee' );
		$this->make_application( $event, $user, 'law-applied', 'ready' );

		$state = law_flagship_action_state( $event, array( 'user_id' => $user ) );
		$this->assertSame( 'in-review', $state['state'] );
		$this->assertSame( 'View my application', law_flagship_action_link( $state )['label'] );
	}

	public function test_a_failed_charge_offers_to_sort_the_payment_out(): void {
		$event = $this->make_flagship();
		$user  = $this->make_user( 'attendee' );
		$this->make_application( $event, $user, 'law-payment-failed', 'action_required' );

		$state = law_flagship_action_state( $event, array( 'user_id' => $user ) );
		$this->assertSame( 'payment-failed', $state['state'] );
		$this->assertSame( 'Sort out my payment', law_flagship_action_link( $state )['label'] );
	}

	public function test_a_confirmed_place_links_to_the_booking(): void {
		$event = $this->make_flagship();
		$user  = $this->make_user( 'attendee' );
		$this->make_application( $event, $user, 'publish' );

		$state = law_flagship_action_state( $event, array( 'user_id' => $user ) );
		$this->assertSame( 'attending', $state['state'] );
		$this->assertSame( 'View my booking', law_flagship_action_link( $state )['label'] );
	}

	/**
	 * The viewer's own state is resolved BEFORE "this has taken place": a
	 * delegate who attended still needs their booking and their VAT receipt.
	 */
	public function test_a_delegate_who_attended_still_reaches_their_receipt(): void {
		$event = $this->make_flagship( array( '_law_start' => gmdate( 'Y-m-d H:i', strtotime( '-2 days' ) ) ) );
		$user  = $this->make_user( 'attendee' );
		$this->make_application( $event, $user, 'publish' );

		$state = law_flagship_action_state( $event, array( 'user_id' => $user ) );
		$this->assertSame( 'attended', $state['state'] );
		$this->assertSame( 'View my booking and receipt', law_flagship_action_link( $state )['label'] );
	}

	/**
	 * An undecided application on a conference that has been is history: there
	 * is nothing useful left to press, and "sort out my payment" for an event
	 * that has happened would be worse than silence.
	 */
	public function test_an_undecided_application_on_a_past_conference_offers_nothing(): void {
		$event = $this->make_flagship( array( '_law_start' => gmdate( 'Y-m-d H:i', strtotime( '-2 days' ) ) ) );
		$user  = $this->make_user( 'attendee' );
		$this->make_application( $event, $user, 'law-applied', 'ready' );

		$state = law_flagship_action_state( $event, array( 'user_id' => $user ) );
		$this->assertSame( 'past', $state['state'] );
		$this->assertNull( law_flagship_action_link( $state ) );
	}

	/**
	 * A full conference still takes applications and queues them (Denis,
	 * 10 September 2026), so "full" is a flag the page adds a line about, not
	 * a state that takes the button away.
	 */
	public function test_a_full_conference_still_offers_apply(): void {
		$event = $this->make_flagship( array( '_law_tickets_available' => 1 ) );
		law_event_update_meta( $event, '_law_tickets_sold', 1 );

		$state = law_flagship_action_state( $event, array( 'user_id' => 0 ) );
		$this->assertSame( 'apply', $state['state'] );
		$this->assertSame( 'Apply', law_flagship_action_link( $state )['label'] );
	}

	public function test_an_ordinary_event_is_not_resolved_here_at_all(): void {
		$this->make_flagship();
		$other = $this->make_event( array( '_law_tickets_available' => 5 ), 'publish' );
		$this->assertNull( law_flagship_action_state( $other ) );
	}

	/* The card button ______________________________________________________ */

	public function test_the_card_carries_apply_with_the_dialog_hook_for_a_signed_in_viewer(): void {
		$event = $this->make_flagship();
		wp_set_current_user( $this->make_user( 'attendee' ) );

		$action = $this->card( $event );
		$this->assertSame( 'Apply', $action['label'] );
		$this->assertStringContainsString( 'law_flagship_apply=1', $action['url'] );
		$this->assertStringContainsString( 'orange', $action['class'] );
		$this->assertSame( $event, $action['dialog'] );
		$this->assertStringContainsString( get_the_title( $event ), $action['sr_label'] );
	}

	/**
	 * A signed-out visitor gets the dialog too: being told an account is needed
	 * is exactly what it is for (Denis, 11 September 2026).
	 */
	public function test_the_card_hooks_up_the_dialog_for_a_signed_out_visitor(): void {
		$event = $this->make_flagship();
		wp_set_current_user( 0 );
		$this->assertSame( $event, $this->card( $event )['dialog'] );
	}

	/**
	 * Only Apply opens a dialog. The rest go into the account area, so they
	 * must not carry the fetch hook.
	 */
	public function test_the_account_states_are_plain_links_with_no_dialog(): void {
		$event = $this->make_flagship();
		$user  = $this->make_user( 'attendee' );
		$this->make_application( $event, $user, 'publish' );
		wp_set_current_user( $user );

		$action = $this->card( $event );
		$this->assertSame( 'View my booking', $action['label'] );
		$this->assertArrayNotHasKey( 'dialog', $action );
	}

	/**
	 * The committee programme lists the flagship before it is published. An
	 * Apply button there would promise something the apply handler refuses.
	 */
	public function test_the_card_shows_no_button_on_an_unpublished_conference(): void {
		// Created unpublished rather than demoted: the events module's status
		// guard reverts a status flip that did not come from the workflow.
		$event = $this->make_flagship( array(), 'law-draft' );
		wp_set_current_user( $this->make_user( 'attendee' ) );

		$this->assertSame( 'law-draft', get_post_status( $event ) );
		$this->assertNull( $this->card( $event ) );
		$this->assertWPError( law_flagship_guard_open( $event ), 'law_flagship_unpublished' );
	}

	public function test_the_card_shows_no_button_when_there_is_nothing_to_do(): void {
		$event = $this->make_flagship( array( '_law_flagship_price_pence' => 0, '_law_flagship_price_late_pence' => 0 ) );
		$this->assertNull( $this->card( $event ) );
	}

	/* The dialog fragment __________________________________________________ */

	/**
	 * law_booking_guard_open() refuses the flagship outright, so the fragment
	 * endpoint branches to the flagship's own gate before reaching it.
	 * Otherwise Apply on a card would fetch a 404 and navigate away.
	 */
	public function test_the_fragment_endpoint_branches_the_flagship_to_its_own_gate(): void {
		$event = $this->make_flagship();
		$this->assertWPError( law_booking_guard_open( $event ), 'law_booking_flagship' );
		$this->assertTrue( law_flagship_guard_open( $event ) );

		$source = file_get_contents( get_theme_file_path( 'functions/account-bookings.php' ) );
		$this->assertStringContainsString( 'law_booking_render_flagship_dialog( $event_id );', $source );
		$this->assertStringContainsString( 'law_flagship_guard_open( $event_id )', $source );
	}

	public function test_the_flagship_gate_refuses_an_unpublished_or_finished_conference(): void {
		$past = $this->make_flagship( array( '_law_start' => gmdate( 'Y-m-d H:i', strtotime( '-1 hour' ) ) ) );
		$this->assertWPError( law_flagship_guard_open( $past ), 'law_flagship_started' );
	}

	/**
	 * The conference page's own Apply button carries no dialog either: one flow
	 * everywhere, so the placeholder and the skeleton behave the same wherever
	 * somebody presses Apply.
	 */
	public function test_the_conference_page_opener_is_a_fetch_link_with_no_dialog(): void {
		$event = $this->make_flagship();
		wp_set_current_user( $this->make_user( 'attendee' ) );

		ob_start();
		law_flagship_render_opener( law_events_map_post( get_post( $event ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-law-book="' . $event . '"', $html );
		$this->assertStringContainsString( 'law_flagship_apply=1', $html, 'Still a real link, for the no-JS path.' );
		$this->assertStringNotContainsString( 'data-law-modal-open', $html );
		$this->assertStringNotContainsString( 'law-flagship-modal', $html );
	}

	public function test_the_conference_preview_opener_stays_inert(): void {
		$event = $this->make_flagship();

		ob_start();
		law_flagship_render_opener( law_events_map_post( get_post( $event ) ), true );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( 'data-law-book', $html );
	}

	/* The repeat at the foot of the conference page ________________________ */

	public function test_the_foot_of_the_conference_page_repeats_apply(): void {
		$event = $this->make_flagship();
		wp_set_current_user( $this->make_user( 'attendee' ) );

		ob_start();
		law_flagship_render_action_buttons( law_events_map_post( get_post( $event ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-law-book="' . $event . '"', $html );
		$this->assertStringContainsString( 'Apply', $html );
		$this->assertStringNotContainsString( 'law-booking-state', $html );
	}

	public function test_the_foot_of_the_conference_page_links_an_existing_application(): void {
		$event = $this->make_flagship();
		$user  = $this->make_user( 'attendee' );
		$this->make_application( $event, $user, 'law-payment-failed', 'action_required' );
		wp_set_current_user( $user );

		ob_start();
		law_flagship_render_action_buttons( law_events_map_post( get_post( $event ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Sort out my payment', $html );
		$this->assertStringNotContainsString( 'data-law-book', $html );
	}

	public function test_the_foot_of_the_conference_page_does_not_repeat_the_inline_no_js_form(): void {
		$event = $this->make_flagship();
		wp_set_current_user( $this->make_user( 'attendee' ) );

		$_GET['law_flagship_apply'] = '1';
		ob_start();
		law_flagship_render_action_buttons( law_events_map_post( get_post( $event ) ) );
		$html = (string) ob_get_clean();
		unset( $_GET['law_flagship_apply'] );

		$this->assertSame( '', trim( $html ) );
	}

	/** One call site at the foot of the page, routing the flagship like the top. */
	public function test_the_hosted_button_renderer_routes_the_flagship_here(): void {
		$event = $this->make_flagship();
		wp_set_current_user( $this->make_user( 'attendee' ) );

		ob_start();
		law_booking_render_action_buttons( law_events_map_post( get_post( $event ) ) );
		$this->assertStringContainsString( 'Apply', (string) ob_get_clean() );
	}

	public function test_the_placeholder_dialog_has_a_heading_for_the_application_too(): void {
		ob_start();
		get_template_part( 'parts/events/booking-loading-modal' );
		$html = (string) ob_get_clean();
		// Matching parts/events/flagship-apply-modal.php's own heading, so
		// nothing jumps when the fetched dialog replaces the placeholder.
		$this->assertStringContainsString( 'data-law-loading-apply="Apply to attend"', $html );
	}
}
