<?php
/**
 * Picking an existing speaker prefills the row: the latest appearance wins,
 * field by field, and the speaker post's own biography and featured image are
 * the last resort (Denis, 11 September 2026).
 */
class SpeakerPrefillTest extends LAW_Test_Case {

	/** Force an event's submission date, which is what orders appearances. */
	private function dated_event( $days_ago, $status = 'law-proposed' ) {
		$event = $this->make_event( array(), $status );
		$when  = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days_ago * DAY_IN_SECONDS ) );
		wp_update_post( array( 'ID' => $event, 'post_date' => $when, 'post_date_gmt' => $when ) );
		return $event;
	}

	public function test_latest_appearance_wins_field_by_field(): void {
		$speaker       = law_speaker_upsert( array( 'name' => 'Prefill Latest Testspeaker', 'email' => 'prefill-latest@example.test' ) );
		$this->posts[] = $speaker;

		$older = $this->dated_event( 30 );
		$newer = $this->dated_event( 1 );
		law_event_update_meta(
			$older,
			'_law_speakers',
			array( array( 'speaker_id' => $speaker, 'role' => 'host', 'organisation' => 'Old Firm', 'job_title' => 'Associate', 'photo_id' => 4321, 'bio' => 'The old biography.' ) )
		);
		// The newer row leaves the photo and the job title unset, so those two
		// fall through to the older appearance while the rest come from here.
		law_event_update_meta(
			$newer,
			'_law_speakers',
			array( array( 'speaker_id' => $speaker, 'role' => 'moderator', 'organisation' => 'New Firm', 'bio' => 'The new biography.' ) )
		);
		law_speakers_flush_maps();

		$latest = law_speaker_latest_appearance( $speaker, law_event_all_status_keys() );
		$this->assertSame( $newer, $latest['event_id'], 'The most recently submitted event is the one to copy from.' );
		$this->assertSame( 'moderator', $latest['role'], 'The role is part of the prefill, unlike the archive headline.' );
		$this->assertSame( 'New Firm', $latest['organisation'] );
		$this->assertSame( 'The new biography.', $latest['bio'] );
		$this->assertSame( 'Associate', $latest['job_title'], 'A field the newer row left empty falls through to the one before it.' );
		$this->assertSame( 4321, $latest['photo_id'] );

		// The mirror image: the archive still reads the FIRST appearance.
		$first = law_speaker_first_appearance( $speaker, law_event_all_status_keys() );
		$this->assertSame( 'Old Firm', $first['organisation'] );
	}

	public function test_prefill_never_offers_the_speaker_posts_own_biography(): void {
		$speaker       = law_speaker_upsert( array( 'name' => 'Prefill Fallback Testspeaker', 'email' => 'prefill-fallback@example.test' ) );
		$this->posts[] = $speaker;
		wp_update_post( array( 'ID' => $speaker, 'post_content' => 'The profile biography.' ) );

		$prefill = law_speaker_row_prefill( $speaker );
		$this->assertSame( '', $prefill['bio'], "The speaker post's own content is no fallback since 26 October 2026: it would carry a stale bio into a new row." );
		$this->assertSame( '', $prefill['organisation'], 'There is no organisation on the speaker post to fall back to: it is per appearance.' );
		$this->assertSame( '', $prefill['role'] );
		$this->assertSame( 0, $prefill['photo_id'] );
		$this->assertSame( '', $prefill['photo'] );

		// An appearance's biography is copied forward.
		$event = $this->dated_event( 2 );
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $speaker, 'organisation' => 'Appearance Firm', 'bio' => 'The appearance biography.' ) ) );
		law_speakers_flush_maps();

		$prefill = law_speaker_row_prefill( $speaker );
		$this->assertSame( 'The appearance biography.', $prefill['bio'] );
		$this->assertSame( 'Appearance Firm', $prefill['organisation'] );
	}

	public function test_prefill_reads_unconfirmed_events_too(): void {
		$speaker       = law_speaker_upsert( array( 'name' => 'Prefill Draft Testspeaker', 'email' => 'prefill-draft@example.test' ) );
		$this->posts[] = $speaker;
		$draft         = $this->dated_event( 3, 'law-proposed' );
		law_event_update_meta( $draft, '_law_speakers', array( array( 'speaker_id' => $speaker, 'organisation' => 'Unconfirmed Firm', 'job_title' => 'Partner' ) ) );
		law_speakers_flush_maps();

		$this->assertSame( 'Unconfirmed Firm', law_speaker_row_prefill( $speaker )['organisation'], 'The committee edits appearances long before an event is confirmed.' );
		$this->assertSame( '', law_speaker_latest_appearance( $speaker )['organisation'], 'The confirmed-only default is unchanged.' );
	}

	/**
	 * Domitille Baizeau's card kept printing "download CV from website" after
	 * every bio on Manage Speakers was emptied: it came from the speaker post's
	 * own content. A blank appearance bio now prints nothing, on the event and
	 * on the profile.
	 */
	public function test_a_blank_appearance_bio_prints_nothing_anywhere(): void {
		$speaker       = law_speaker_upsert( array( 'name' => 'Stale Bio Testspeaker', 'email' => 'stale-bio@example.test', 'bio' => 'download CV from website' ) );
		$this->posts[] = $speaker;
		$this->assertSame( 'download CV from website', trim( get_post_field( 'post_content', $speaker ) ), 'Fixture: the record carries the stale text.' );

		$event = $this->dated_event( 1, 'publish' );
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $speaker, 'organisation' => 'Firm', 'bio' => '' ) ) );
		law_speakers_confirmed_maps( true );

		$this->assertSame( '', law_event_speaker_cards( $event )[0]['bio'] );
		$this->assertSame( '', law_speaker_post_profile( $speaker, array( $event ) )['bio'] );
	}
}
