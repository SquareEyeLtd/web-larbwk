<?php
/**
 * law_session children of an event: the saver upserts them rather than
 * wiping and rebuilding, so a session keeps its post ID and its meta across
 * a host or committee edit (9 September 2026). The wipe-and-rebuild it
 * replaced churned session IDs and destroyed _law_gf_entry_id, which is the
 * migrator's "already migrated" check for step 4 (sessions).
 */
class SessionsTest extends LAW_Test_Case {

	/** A session row as the repeater posts it. */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array( 'id' => '', 'title' => 'Session', 'start' => '09:00', 'end' => '10:00', 'description' => 'What happens.', 'speakers' => array() ),
			$overrides
		);
	}

	/** Track the event's sessions for tearDown and return them, in order. */
	private function sessions( int $event_id ): array {
		$ids         = law_event_session_ids( $event_id );
		$this->posts = array_merge( $this->posts, array_diff( $ids, $this->posts ) );
		return $ids;
	}

	/** A complete, valid non-draft form input (mirrors SubmissionFormLockTest). */
	private function valid_input( array $overrides = array() ): array {
		return array_merge(
			array(
				'law_form_action'     => 'update',
				'event_title'         => 'Sessions test event',
				'description'         => 'Sessions test description.',
				'event_type'          => 'Social event',
				'host_organisations'  => 'Sessions Org LLP',
				'preferred_slots'     => array( 'Any slot' ),
				'sectors'             => array(),
				'venue_needed'        => 'Yes, please share our details with venue hosts',
				'fee_tier'            => 'uk',
				'invoice_name'        => 'Sessions Contact',
				'invoice_email'       => 'sessions-invoice@example.test',
				'invoice_line1'       => '1 Sessions Street',
				'invoice_city'        => 'London',
				'invoice_postal_code' => 'EC1A 1AA',
				'invoice_country'     => 'United Kingdom',
			),
			$overrides
		);
	}

	public function test_edit_updates_in_place_and_only_removed_rows_are_deleted(): void {
		$event = $this->make_event();

		law_events_form_save_sessions(
			$event,
			array(
				$this->row( array( 'title' => 'Opening', 'start' => '09:00' ) ),
				$this->row( array( 'title' => 'Panel', 'start' => '10:00' ) ),
				$this->row( array( 'title' => 'Close', 'start' => '11:00' ) ),
			)
		);
		$first = $this->sessions( $event );
		$this->assertCount( 3, $first );
		$this->assertSame( array( 'Opening', 'Panel', 'Close' ), array_map( 'get_the_title', $first ) );

		// Rename the middle one, drop the last, add a new one.
		law_events_form_save_sessions(
			$event,
			array(
				$this->row( array( 'id' => $first[0], 'title' => 'Opening', 'start' => '09:00' ) ),
				$this->row( array( 'id' => $first[1], 'title' => 'Panel discussion', 'start' => '10:00' ) ),
				$this->row( array( 'title' => 'Drinks', 'start' => '12:00' ) ),
			)
		);
		$second = $this->sessions( $event );

		$this->assertCount( 3, $second );
		$this->assertSame( $first[0], $second[0], 'An untouched session keeps its post ID.' );
		$this->assertSame( $first[1], $second[1], 'An edited session is updated in place.' );
		$this->assertSame( 'Panel discussion', get_the_title( $second[1] ), 'The edit landed.' );
		$this->assertNotContains( $first[2], $second, 'The removed row is gone.' );
		$this->assertNull( get_post( $first[2] ), 'And its post is deleted, not orphaned.' );
		$this->assertNotContains( $second[2], $first, 'The added row is a new post.' );
	}

	public function test_a_session_keeps_its_migration_link_across_an_edit(): void {
		$event = $this->make_event();
		law_events_form_save_sessions( $event, array( $this->row( array( 'title' => 'Migrated session' ) ) ) );
		$ids = $this->sessions( $event );
		law_event_update_meta( $ids[0], '_law_gf_entry_id', 4321 );

		law_events_form_save_sessions( $event, array( $this->row( array( 'id' => $ids[0], 'title' => 'Migrated session, retitled' ) ) ) );

		$after = $this->sessions( $event );
		$this->assertSame( $ids[0], $after[0] );
		$this->assertSame(
			4321,
			(int) law_event_meta( $after[0], '_law_gf_entry_id' ),
			"_law_gf_entry_id survives a host edit, so migration step 4's already-migrated check still holds."
		);
	}

	public function test_a_posted_id_this_event_does_not_own_inserts_instead_of_hijacking(): void {
		$mine    = $this->make_event();
		$theirs  = $this->make_event();
		law_events_form_save_sessions( $theirs, array( $this->row( array( 'title' => 'Their session' ) ) ) );
		$victim = $this->sessions( $theirs )[0];

		law_events_form_save_sessions( $mine, array( $this->row( array( 'id' => $victim, 'title' => 'Stolen' ) ) ) );

		$mine_ids = $this->sessions( $mine );
		$this->assertCount( 1, $mine_ids );
		$this->assertNotSame( $victim, $mine_ids[0], 'A forged ID inserts a new session.' );
		$this->assertSame( 'Their session', get_the_title( $victim ), "The other event's session is untouched." );
		$this->assertSame( $theirs, (int) get_post_field( 'post_parent', $victim ), 'And it is not re-parented.' );

		// The same ID twice in one submission cannot collapse two rows onto one post.
		$dup = $this->make_event();
		law_events_form_save_sessions( $dup, array( $this->row( array( 'title' => 'One' ) ) ) );
		$only = $this->sessions( $dup )[0];
		law_events_form_save_sessions(
			$dup,
			array(
				$this->row( array( 'id' => $only, 'title' => 'One' ) ),
				$this->row( array( 'id' => $only, 'title' => 'Two' ) ),
			)
		);
		$after = $this->sessions( $dup );
		$this->assertCount( 2, $after );
		$this->assertSame( array( 'One', 'Two' ), array_map( 'get_the_title', $after ) );
	}

	public function test_the_edit_form_is_prefilled_with_each_session_id(): void {
		$event = $this->make_event();
		law_events_form_save_sessions( $event, array( $this->row( array( 'title' => 'Prefilled' ) ) ) );
		$ids = $this->sessions( $event );

		$values = law_events_form_values( get_post( $event ), array( 'errors' => array(), 'input' => array() ) );

		$this->assertSame( $ids[0], $values['sessions'][0]['id'], 'The hidden field can round-trip the post ID.' );
		$this->assertSame( 'Prefilled', $values['sessions'][0]['title'] );
	}

	public function test_a_failed_update_keeps_the_session_rather_than_deleting_it(): void {
		$event = $this->make_event();
		law_events_form_save_sessions(
			$event,
			array(
				$this->row( array( 'title' => 'Untouched', 'start' => '09:00' ) ),
				$this->row( array( 'title' => 'Survives', 'start' => '10:00' ) ),
			)
		);
		$ids    = $this->sessions( $event );
		$target = $ids[1];

		// Force wp_update_post() to return a WP_Error for this one session. The
		// reconcile sweep must still see the row as claimed: the host asked to
		// edit the session, not to remove it.
		$fail = function ( $maybe_empty, $postarr ) use ( $target ) {
			return (int) ( $postarr['ID'] ?? 0 ) === $target ? true : $maybe_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $fail, 10, 2 );
		law_events_form_save_sessions(
			$event,
			array(
				$this->row( array( 'id' => $ids[0], 'title' => 'Untouched', 'start' => '09:00' ) ),
				$this->row( array( 'id' => $target, 'title' => 'Edit that cannot be written', 'start' => '10:00' ) ),
			)
		);
		remove_filter( 'wp_insert_post_empty_content', $fail, 10 );

		$this->assertSame( $ids, $this->sessions( $event ), 'Both sessions survive, with their IDs.' );
		$this->assertNotNull( get_post( $target ) );
		$this->assertSame( 'Survives', get_the_title( $target ), 'The failed edit is lost; the session is not.' );

		$log = implode( "\n", array_map( static fn( $entry ) => $entry->comment_content, law_event_log_entries( $event ) ) );
		$this->assertStringContainsString( 'Could not save the changes to session', $log, 'The failure is logged, not swallowed.' );
	}

	public function test_menu_order_lets_a_host_reorder_sessions_that_start_at_the_same_time(): void {
		$event = $this->make_event();
		law_events_form_save_sessions(
			$event,
			array(
				$this->row( array( 'title' => 'Track A', 'start' => '09:00' ) ),
				$this->row( array( 'title' => 'Track B', 'start' => '09:00' ) ),
			)
		);
		$ids = $this->sessions( $event );
		$this->assertSame( array( 'Track A', 'Track B' ), array_map( 'get_the_title', $ids ) );

		// Same two posts, swapped in the repeater. Post IDs cannot express this
		// any more, so menu_order has to.
		law_events_form_save_sessions(
			$event,
			array(
				$this->row( array( 'id' => $ids[1], 'title' => 'Track B', 'start' => '09:00' ) ),
				$this->row( array( 'id' => $ids[0], 'title' => 'Track A', 'start' => '09:00' ) ),
			)
		);
		$after = $this->sessions( $event );

		$this->assertSame( array( $ids[1], $ids[0] ), $after, 'The host row order wins the same-start-time tie.' );
		$this->assertSame( array( 'Track B', 'Track A' ), array_map( 'get_the_title', $after ) );
		$this->assertSame(
			array( 'Track B', 'Track A' ),
			wp_list_pluck( law_event_session_rows( $event ), 'title' ),
			'And the listing shape follows.'
		);
	}

	public function test_clearing_a_sessions_fields_deletes_it_without_a_validation_error(): void {
		$host  = $this->make_user( 'event_host' );
		$event = $this->make_event( array(), 'law-proposed', $host );
		law_events_form_save_sessions( $event, array( $this->row( array( 'title' => 'To be cleared' ) ) ) );
		$ids = $this->sessions( $event );

		// The host empties the row's fields to remove it. The hidden ID is still
		// posted, and must not make the row count as started.
		$result = law_events_form_save(
			$this->valid_input(
				array(
					'sessions' => array(
						array( 'id' => (string) $ids[0], 'title' => '', 'start' => '', 'end' => '', 'description' => '' ),
					),
				)
			),
			array(),
			get_post( $event ),
			$host
		);

		$this->assertIsInt( $result, 'An emptied row is a removal, not an incomplete session.' );
		$this->assertSame( array(), law_event_session_ids( $event ), 'And the session is deleted.' );
		$this->assertNull( get_post( $ids[0] ) );
	}
}
