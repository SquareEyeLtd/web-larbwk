<?php
/**
 * Speaker photo upload validation: the rules the help text under every upload
 * control now promises (functions/events/submission-form.php).
 *
 * law_events_validate_photos() had no test at all, which is how the pixel
 * bounds came to be enforced without ever being mentioned to a host.
 */

final class SpeakerPhotoTest extends LAW_Test_Case {

	/** Temp files created by a test, removed in tearDown. */
	private array $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = array();
		parent::tearDown();
	}

	/** A real image on disk, via GD, at the given size. */
	private function image( $width, $height, $ext = 'jpg' ) {
		$path  = tempnam( sys_get_temp_dir(), 'law-photo-' ) . '.' . $ext;
		$image = imagecreatetruecolor( $width, $height );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 200, 200, 200 ) );
		if ( 'png' === $ext ) {
			imagepng( $image, $path );
		} elseif ( 'webp' === $ext ) {
			imagewebp( $image, $path );
		} else {
			imagejpeg( $image, $path );
		}
		$this->files[] = $path;
		return $path;
	}

	/** A file of arbitrary bytes with the given extension. */
	private function bytes( $contents, $ext ) {
		$path = tempnam( sys_get_temp_dir(), 'law-photo-' ) . '.' . $ext;
		file_put_contents( $path, $contents );
		$this->files[] = $path;
		return $path;
	}

	/** The $_FILES shape a speaker_photo[i] input produces. */
	private function batch( array $rows ) {
		$batch = array( 'name' => array(), 'tmp_name' => array(), 'size' => array(), 'error' => array(), 'type' => array() );
		foreach ( $rows as $i => $row ) {
			$batch['name'][ $i ]     = $row['name'];
			$batch['tmp_name'][ $i ] = $row['tmp_name'] ?? '';
			$batch['size'][ $i ]     = $row['size'] ?? ( $row['tmp_name'] ?? '' ? filesize( $row['tmp_name'] ) : 0 );
			$batch['error'][ $i ]    = $row['error'] ?? UPLOAD_ERR_OK;
			$batch['type'][ $i ]     = $row['type'] ?? '';
		}
		return array( 'speaker_photo' => $batch );
	}

	public function test_valid_jpeg_passes() {
		$path   = $this->image( 800, 800 );
		$errors = law_events_validate_photos( $this->batch( array( 0 => array( 'name' => 'headshot.jpg', 'tmp_name' => $path ) ) ) );
		$this->assertSame( array(), $errors );
	}

	public function test_png_and_webp_pass() {
		$errors = law_events_validate_photos(
			$this->batch(
				array(
					0 => array( 'name' => 'a.png', 'tmp_name' => $this->image( 400, 400, 'png' ) ),
					1 => array( 'name' => 'b.webp', 'tmp_name' => $this->image( 400, 400, 'webp' ) ),
				)
			)
		);
		$this->assertSame( array(), $errors );
	}

	public function test_empty_row_is_skipped() {
		$errors = law_events_validate_photos( $this->batch( array( 0 => array( 'name' => '' ) ) ) );
		$this->assertSame( array(), $errors );
	}

	public function test_a_file_over_the_cap_is_rejected() {
		$path   = $this->bytes( str_repeat( 'x', 16 ), 'jpg' );
		$errors = law_events_validate_photos(
			$this->batch( array( 0 => array( 'name' => 'huge.jpg', 'tmp_name' => $path, 'size' => LAW_PHOTO_MAX_BYTES + 1 ) ) )
		);
		$this->assertArrayHasKey( 'speaker_photo_0', $errors );
		$this->assertStringContainsString( '5 MB maximum', $errors['speaker_photo_0'] );
	}

	/**
	 * PHP rejected it first, because the server's own upload_max_filesize is
	 * tighter than ours. Before this branch existed the size check passed (the
	 * size arrives as 0), the MIME sniff then failed on an empty tmp path, and
	 * the host was told their photo "must be a JPG, PNG or WebP image" for what
	 * was really a server limit.
	 */
	public function test_php_level_size_failure_reports_the_size_not_the_type() {
		$errors = law_events_validate_photos(
			$this->batch( array( 0 => array( 'name' => 'huge.jpg', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE ) ) )
		);
		$this->assertArrayHasKey( 'speaker_photo_0', $errors );
		$this->assertStringContainsString( '5 MB maximum', $errors['speaker_photo_0'] );
		$this->assertStringNotContainsString( 'must be a JPG', $errors['speaker_photo_0'] );
	}

	/** The extension is not trusted: the content is sniffed. */
	public function test_text_renamed_to_png_is_rejected() {
		$path   = $this->bytes( 'this is not an image at all', 'png' );
		$errors = law_events_validate_photos( $this->batch( array( 0 => array( 'name' => 'fake.png', 'tmp_name' => $path ) ) ) );
		$this->assertArrayHasKey( 'speaker_photo_0', $errors );
		$this->assertStringContainsString( 'must be a JPG, PNG or WebP image', $errors['speaker_photo_0'] );
	}

	public function test_gif_is_rejected() {
		$path  = tempnam( sys_get_temp_dir(), 'law-photo-' ) . '.gif';
		$image = imagecreatetruecolor( 200, 200 );
		imagegif( $image, $path );
		$this->files[] = $path;

		$errors = law_events_validate_photos( $this->batch( array( 0 => array( 'name' => 'animated.gif', 'tmp_name' => $path ) ) ) );
		$this->assertArrayHasKey( 'speaker_photo_0', $errors );
		$this->assertStringContainsString( 'must be a JPG, PNG or WebP image', $errors['speaker_photo_0'] );
	}

	public function test_an_image_below_the_minimum_is_rejected() {
		$path   = $this->image( 20, 20 );
		$errors = law_events_validate_photos( $this->batch( array( 0 => array( 'name' => 'tiny.jpg', 'tmp_name' => $path ) ) ) );
		$this->assertArrayHasKey( 'speaker_photo_0', $errors );
		$this->assertStringContainsString( '50×50', $errors['speaker_photo_0'] );
	}

	/**
	 * Over the maximum on one side only: 7000 wide by 10 tall, so the bound is
	 * exercised without allocating a 7000×7000 pixel buffer.
	 */
	public function test_an_image_over_the_maximum_is_rejected() {
		$path   = $this->image( LAW_PHOTO_MAX_PX + 1000, 10 );
		$errors = law_events_validate_photos( $this->batch( array( 0 => array( 'name' => 'wide.jpg', 'tmp_name' => $path ) ) ) );
		$this->assertArrayHasKey( 'speaker_photo_0', $errors );
		$this->assertStringContainsString( '6000×6000', $errors['speaker_photo_0'] );
	}

	/** Errors are keyed by row, so a bad row does not condemn a good one. */
	public function test_errors_are_keyed_by_row() {
		$errors = law_events_validate_photos(
			$this->batch(
				array(
					0 => array( 'name' => 'good.jpg', 'tmp_name' => $this->image( 400, 400 ) ),
					1 => array( 'name' => 'tiny.jpg', 'tmp_name' => $this->image( 20, 20 ) ),
				)
			)
		);
		$this->assertArrayNotHasKey( 'speaker_photo_0', $errors );
		$this->assertArrayHasKey( 'speaker_photo_1', $errors );
	}

	/** A payload that is not the parallel-array shape writes nothing. */
	public function test_a_malformed_payload_is_ignored() {
		$this->assertSame( array(), law_events_validate_photos( array() ) );
		$this->assertSame( array(), law_events_validate_photos( array( 'speaker_photo' => array( 'name' => 'not-an-array.jpg' ) ) ) );
	}

	/* The help text and the accept attribute must agree with the rules above,
	   since they are the promise the host is shown. ______________________ */

	public function test_the_hint_states_the_enforced_limits() {
		$hint = law_events_photo_hint();
		$this->assertStringContainsString( '5 MB', $hint );
		$this->assertStringContainsString( '50×50', $hint );
		$this->assertStringContainsString( '6000×6000', $hint );
		$this->assertStringContainsString( 'JPG, PNG or WebP', $hint );
	}

	public function test_the_accept_attribute_covers_every_allowed_mime() {
		$accept = law_events_photo_accept();
		foreach ( law_events_photo_mimes() as $mime ) {
			$this->assertStringContainsString( $mime, $accept );
		}
		foreach ( array( '.jpg', '.jpeg', '.png', '.webp' ) as $extension ) {
			$this->assertStringContainsString( $extension, $accept );
		}
	}

	/** The sideloader's own allowlist must not drift from the validator's. */
	public function test_the_sideload_override_matches_the_validator() {
		$this->assertSame(
			law_events_photo_mimes(),
			array_values( law_events_photo_upload_mimes() )
		);
	}
}
