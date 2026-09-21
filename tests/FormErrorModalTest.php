<?php
/**
 * The failed-save dialog over the event form (law_events_form_error_modal(),
 * functions/events/submission-form.php, 21 September 2026).
 *
 * What is pinned here is the contract the two callers and law-modal.js depend
 * on: nothing at all when the save succeeded, one line per failed field
 * carrying the message that field itself prints, a jump selector that really
 * matches the control on the form, and the auto-open flag that makes the
 * dialog appear without anyone clicking anything.
 */

require_once __DIR__ . '/class-law-test-case.php';

class FormErrorModalTest extends LAW_Test_Case {

	/** The dialog's markup for one error set. */
	private function render( array $errors, string ...$title ): string {
		ob_start();
		law_events_form_error_modal( $errors, ...$title );
		return (string) ob_get_clean();
	}

	public function test_no_errors_renders_nothing(): void {
		$this->assertSame( '', $this->render( array() ) );
	}

	public function test_one_line_per_field_with_its_own_message(): void {
		$html = $this->render( array(
			'event_title' => array( 'Please give the event a title.' ),
			'venue'       => array( 'Please give the venue name and/or address.' ),
		) );

		$this->assertStringContainsString( 'id="law-modal-form-errors"', $html );
		// Opened by law-modal.js the moment the page loads: the whole point is
		// that nobody has to notice the notice.
		$this->assertStringContainsString( 'data-law-modal-autoopen', $html );
		$this->assertSame( 2, substr_count( $html, '<li>' ), 'One line per failed field' );
		$this->assertStringContainsString( 'Please give the event a title.', $html );
		$this->assertStringContainsString( 'Please give the venue name and/or address.', $html );
		$this->assertStringContainsString( '2 fields need your attention:', $html );
		// Informational only: it must never carry a second submit button for
		// the form it sits next to.
		$this->assertStringNotContainsString( 'type="submit"', $html );
	}

	public function test_single_error_counts_in_the_singular(): void {
		$html = $this->render( array( 'terms' => array( 'Please accept the terms and conditions.' ) ) );
		$this->assertStringContainsString( 'One field needs your attention:', $html );
		$this->assertStringNotContainsString( '1 fields', $html );
	}

	public function test_title_is_the_callers(): void {
		$html = $this->render( array( 'event_title' => array( 'Please give the event a title.' ) ), 'Your event was not saved' );
		$this->assertStringContainsString( 'Your event was not saved', $html );
		// The default, for the committee edit and a host editing a live event.
		$html = $this->render( array( 'event_title' => array( 'Please give the event a title.' ) ) );
		$this->assertStringContainsString( 'Your changes were not saved', $html );
	}

	public function test_edit_lock_refusal_is_the_sentence_and_no_jump_lines(): void {
		$html = $this->render( array( 'locked' => array( 'This event is currently being edited by Someone Else.' ) ) );
		$this->assertStringContainsString( 'This event is currently being edited by Someone Else.', $html );
		// Nothing below is highlighted on that path, so there is nothing to
		// jump to and no count to give.
		$this->assertStringNotContainsString( 'law-modal__list', $html );
		$this->assertStringNotContainsString( 'need your attention', $html );
	}

	public function test_jump_selectors_match_the_real_controls(): void {
		$this->assertSame( '[name="event_title"], [name="event_title[]"]', law_events_form_error_selector( 'event_title' ) );
		// The checkbox groups post as arrays; the second half of the selector
		// is what actually matches preferred slots and sectors.
		$this->assertSame( '[name="preferred_slots"], [name="preferred_slots[]"]', law_events_form_error_selector( 'preferred_slots' ) );
		// Speaker photos are keyed by row index, and the input is that row's
		// file field (parts/events/event-form-speakers.php).
		$this->assertSame( '[name="speaker_photo[3]"]', law_events_form_error_selector( 'speaker_photo_3' ) );
		// Nothing to point at rather than a selector that could match anything.
		$this->assertSame( '', law_events_form_error_selector( 'not a key' ) );
	}

	public function test_a_key_with_no_control_stays_plain_text(): void {
		$html = $this->render( array( 'not a key' => array( 'Something went wrong.' ) ) );
		$this->assertStringContainsString( '<li>Something went wrong.</li>', $html );
		$this->assertStringNotContainsString( 'data-law-modal-goto', $html );
	}

	/**
	 * Every selector the validator can produce must match a control the form
	 * actually renders, or the dialog offers a line that goes nowhere. The
	 * fields part is rendered for a real event and searched for each one.
	 */
	public function test_every_validation_key_has_a_control_on_the_form(): void {
		$host  = $this->make_user();
		$event = get_post( $this->make_event( array(), 'law-submitted', $host ) );
		wp_set_current_user( $host );

		ob_start();
		get_template_part( 'parts/events/event-form-fields', null, array(
			'post'    => $event,
			'values'  => law_events_form_values( $event, array( 'errors' => array(), 'input' => array() ) ),
			'errors'  => array(),
			'locked'  => array(),
			'context' => 'host',
		) );
		$form = (string) ob_get_clean();
		$this->assertNotSame( '', $form, 'The fields part rendered nothing' );

		// The codes law_events_form_save() can add, read from the source of
		// truth rather than listed twice.
		$source = file_get_contents( get_theme_file_path( 'functions/events/submission-form.php' ) );
		preg_match_all( "/\\\$errors->add\(\s*'([a-z0-9_]+)'/", (string) $source, $matches );
		$keys = array_unique( $matches[1] );
		$this->assertNotEmpty( $keys, 'No validation codes found to check' );

		// The terms checkbox is not in the shared fields part: it lives in the
		// Finish section of templates/account-event-form.php, and only for a
		// draft or a brand-new event, which is also the only time its error can
		// be raised. Checked against that template's source instead.
		$this->assertStringContainsString( 'name="terms"', (string) file_get_contents( get_theme_file_path( 'templates/account-event-form.php' ) ) );
		$keys = array_diff( $keys, array( 'terms' ) );

		foreach ( $keys as $key ) {
			$selector = law_events_form_error_selector( $key );
			$this->assertNotSame( '', $selector, "No selector for {$key}" );
			// The selector is one or both of these two names; the form must
			// carry at least one of them.
			$found = false !== strpos( $form, 'name="' . $key . '"' )
				|| false !== strpos( $form, 'name="' . $key . '[]"' );
			$this->assertTrue( $found, "The form has no control named {$key}" );
		}
	}
}
