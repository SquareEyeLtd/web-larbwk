<?php
/**
 * The rich-text layer behind the descriptive fields (functions/events/rich-text.php):
 * the event description, each speaker biography and each session description
 * are edited in a WYSIWYG editor, so they store HTML rather than plain text.
 *
 * What the tests are pinning down is the boundary: the allowlist is narrower
 * than wp_kses_post(), an emptied editor's "<p>&nbsp;</p>" must read as empty
 * everywhere, and the places that cannot take markup (excerpts, emails, the
 * .ics feed, the exports) must get text with its line breaks intact.
 */
class RichTextTest extends LAW_Test_Case {

	public function test_the_allowlist_keeps_formatting_and_drops_everything_else(): void {
		$this->assertSame(
			'<p>A <strong>bold</strong> and <em>italic</em> line.</p>',
			law_rich_text_sanitize( '<p>A <strong>bold</strong> and <em>italic</em> line.</p>' )
		);
		$this->assertSame(
			'<ul><li>One</li><li>Two</li></ul>',
			law_rich_text_sanitize( '<ul><li>One</li><li>Two</li></ul>' )
		);
		$this->assertSame(
			'<h3>Programme</h3><h4>Morning</h4><blockquote>Quoted.</blockquote>',
			law_rich_text_sanitize( '<h3>Programme</h3><h4>Morning</h4><blockquote>Quoted.</blockquote>' )
		);
		$this->assertSame(
			'<a href="https://example.org" target="_blank" rel="noopener">Book</a>',
			law_rich_text_sanitize( '<a href="https://example.org" target="_blank" rel="noopener">Book</a>' )
		);
	}

	public function test_media_layout_and_scripts_are_stripped(): void {
		// wp_kses_post() would keep all three of these. The events allowlist is
		// deliberately narrower: a host formats a description, they do not lay
		// out a page with it.
		$this->assertSame( 'Caption', law_rich_text_sanitize( '<img src="x.jpg" alt=""><figure>Caption</figure>' ) );
		$this->assertSame( 'AB', law_rich_text_sanitize( '<table><tr><td>A</td><td>B</td></tr></table>' ) );
		$this->assertSame( 'Red', law_rich_text_sanitize( '<span style="color:red">Red</span>' ) );
		$this->assertSame( 'Safe', law_rich_text_sanitize( '<script>alert(1)</script>Safe' ) );
		// wp_kses strips the disallowed protocol and leaves the remainder as a
		// relative URL, so the link is inert rather than removed. That is core's
		// own behaviour, pinned here so a future change to it is noticed.
		$this->assertSame( '<a href="alert(1)">Click</a>', law_rich_text_sanitize( '<a href="javascript:alert(1)">Click</a>' ) );
		$this->assertStringNotContainsString( 'javascript:', law_rich_text_sanitize( '<a href="javascript:alert(1)">Click</a>' ) );
	}

	public function test_an_emptied_editor_stores_an_empty_string(): void {
		// What TinyMCE actually posts when the author clears the field. Storing
		// it verbatim would make an empty description pass the "did they fill
		// this in?" checks and print a blank paragraph on the event page.
		foreach ( array( '', '   ', '<p></p>', '<p>&nbsp;</p>', '<p><br></p>', "<p>&nbsp;</p>\n<p>&nbsp;</p>" ) as $posted ) {
			$this->assertSame( '', law_rich_text_sanitize( $posted ), sprintf( '"%s" is an empty field.', $posted ) );
			$this->assertTrue( law_rich_text_is_empty( $posted ) );
		}
		$this->assertFalse( law_rich_text_is_empty( '<p>Something.</p>' ) );
	}

	public function test_plain_text_keeps_the_breaks_the_markup_carried(): void {
		// The bug this guards: wp_strip_all_tags() alone runs a list together
		// into one word, and the excerpt, the .ics feed and the notification
		// emails all read a description this way.
		$this->assertSame( "One\nTwo", law_rich_text_plain( '<ul><li>One</li><li>Two</li></ul>' ) );
		$this->assertSame( "First para\nSecond para", law_rich_text_plain( '<p>First para</p><p>Second para</p>' ) );
		$this->assertSame( "Line one\nLine two", law_rich_text_plain( 'Line one<br>Line two' ) );
		$this->assertSame( 'Bold and italic', law_rich_text_plain( '<strong>Bold</strong> and <em>italic</em>' ) );
		$this->assertSame( 'A B', law_rich_text_plain( 'A&nbsp;B' ), 'A non-breaking space reads as a space, not as a word.' );
		$this->assertSame( 'Plain text, unchanged.', law_rich_text_plain( 'Plain text, unchanged.' ) );
	}

	public function test_render_paragraphs_the_stored_text_and_re_applies_the_allowlist(): void {
		// wpautop is left on in the editor, so a plain paragraph is stored with
		// no <p> around it, exactly as the classic editor has always stored it.
		$this->assertSame( "<p>One</p>\n<p>Two</p>\n", law_rich_text_render( "One\n\nTwo" ) );
		$this->assertSame( '', law_rich_text_render( '' ) );
		$this->assertStringNotContainsString(
			'<script',
			law_rich_text_render( '<script>alert(1)</script>Left over from before the allowlist' ),
			'Output is filtered as well as input, so a value written straight to the database by the migrator is still safe to print.'
		);
	}

	public function test_a_speaker_row_stores_the_biographys_markup(): void {
		$event         = $this->make_event();
		$speaker       = $this->make_speaker( 'Ada Speaker' );
		law_event_update_meta(
			$event,
			'_law_speakers',
			array(
				array(
					'speaker_id' => $speaker,
					'bio'        => '<p>Ada is a <strong>partner</strong>.</p><ul><li>Arbitration</li></ul>',
				),
			)
		);
		$rows = law_event_meta( $event, '_law_speakers' );
		$this->assertSame( '<p>Ada is a <strong>partner</strong>.</p><ul><li>Arbitration</li></ul>', $rows[0]['bio'] );

		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $speaker, 'bio' => '<p>Ada <script>alert(1)</script>.</p>' ) ) );
		$rows = law_event_meta( $event, '_law_speakers' );
		$this->assertSame( '<p>Ada .</p>', $rows[0]['bio'], 'The meta schema runs the same allowlist as the form.' );
	}

	public function test_a_biography_summary_keeps_markup_in_full_and_drops_it_from_the_excerpt(): void {
		$bio     = '<p>Ada is a <strong>partner</strong> at Chambers.</p><ul><li>Arbitration</li><li>Mediation</li></ul>';
		$summary = law_speaker_bio_summary( $bio, 4 );
		$this->assertSame( $bio, $summary['full'], 'The dialog renders the markup, so the full value keeps it.' );
		$this->assertSame( 'Ada is a partner…', $summary['excerpt'], 'The card shows one line of plain text.' );
		$this->assertTrue( $summary['trimmed'] );

		$empty = law_speaker_bio_summary( '<p>&nbsp;</p>' );
		$this->assertSame( '', $empty['full'], 'An empty editor leaves the card with no biography at all, not with an empty paragraph.' );
		$this->assertSame( '', $empty['excerpt'] );
		$this->assertFalse( $empty['trimmed'] );
	}

	/* Fixtures ______________________________________________________________ */

	private function make_speaker( string $name ): int {
		$id = wp_insert_post(
			array( 'post_type' => LAW_SPEAKER_CPT, 'post_status' => 'publish', 'post_title' => $name )
		);
		$this->posts[] = (int) $id;
		return (int) $id;
	}
}
