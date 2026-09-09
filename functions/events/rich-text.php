<?php
/**
 * Rich text (WYSIWYG) for the events module's descriptive fields: the event
 * description, each speaker's biography and each session's description.
 *
 * The editor is WordPress core's own TinyMCE, initialised from JavaScript with
 * wp_enqueue_editor() + wp.editor.initialize() (assets/js/law-rich-text.js)
 * rather than wp_editor(). Two reasons: the repeater rows are cloned in the
 * browser, so an editor has to be attachable to a textarea that did not exist
 * when the page rendered; and a textarea that no JavaScript ever reaches stays
 * a working plain textarea, which is the no-JS fallback the rest of the module
 * already assumes.
 *
 * wpautop is left ON (assets/js/law-rich-text.js), which is what makes the
 * change safe for the content already in the database: the editor runs
 * wp.editor.autop() over the stored text on load and wp.editor.removep() over
 * it on save, so a legacy plain-text description keeps its line breaks and a
 * newly formatted one is stored the way the classic editor has always stored
 * it — blank lines between paragraphs, tags only where the author added
 * formatting. Every render path already ran wpautop(), so nothing downstream
 * has to change to keep working.
 *
 * Storage is sanitised through law_rich_text_sanitize(), NOT wp_kses_post():
 * hosts should be able to emphasise and structure a description, not embed
 * media, tables or layout that would break the event page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The tags a descriptive field may contain, in wp_kses() shape.
 *
 * Deliberately narrower than wp_kses_post(): bold, italic, the two list
 * types, links, two heading levels and a blockquote. <p> and <br> are here
 * because removep() leaves them behind inside blockquotes and lists even
 * though it strips the ones wrapping plain paragraphs.
 *
 * @return array<string,array<string,bool>>
 */
function law_rich_text_allowed_html() {
	return array(
		'p'          => array(),
		'br'         => array(),
		'strong'     => array(),
		'b'          => array(),
		'em'         => array(),
		'i'          => array(),
		'ul'         => array(),
		'ol'         => array(),
		'li'         => array(),
		'h3'         => array(),
		'h4'         => array(),
		'blockquote' => array(),
		'a'          => array(
			'href'   => true,
			'title'  => true,
			'target' => true,
			'rel'    => true,
		),
	);
}

/**
 * Sanitise one submitted rich-text value for storage. The single write path:
 * the front-end forms, the wp-admin screens and the speakers dashboard all go
 * through this, the way law_event_update_meta() is the single meta write path.
 *
 * @param mixed $value Raw submitted value.
 * @return string Stored HTML, or '' when the author left the field empty.
 */
function law_rich_text_sanitize( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	// Script and style BLOCKS, not just their tags: wp_kses() removes the tag
	// and leaves the code behind as visible text, so "<script>alert(1)</script>"
	// would print as "alert(1)" in the middle of a description.
	$value = law_rich_text_strip_code_blocks( (string) $value );
	$value = wp_kses( $value, law_rich_text_allowed_html() );
	// An emptied editor posts markup, not an empty string: "<p>&nbsp;</p>" or a
	// lone <br>. Storing that would make an empty description pass the "did the
	// host fill this in?" checks and print an empty paragraph on the event page.
	if ( law_rich_text_is_empty( $value ) ) {
		return '';
	}
	return trim( $value );
}

/**
 * Drop <script> and <style> blocks, contents and all.
 *
 * wp_kses() only removes the tags, which turns a pasted script into a line of
 * visible code rather than into nothing. Non-greedy and case-insensitive, and
 * it runs before the allowlist so the allowlist never sees the payload.
 *
 * @param string $value Raw value.
 */
function law_rich_text_strip_code_blocks( $value ) {
	return (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', (string) $value );
}

/**
 * Whether a rich-text value holds no actual words. Entity-aware, because the
 * editor's idea of empty is "<p>&nbsp;</p>".
 *
 * @param mixed $value Stored or submitted value.
 */
function law_rich_text_is_empty( $value ) {
	return '' === law_rich_text_plain( $value );
}

/**
 * A rich-text value as plain text, for the places that must not carry markup:
 * calendar excerpts, the keyword index, notification emails and the .ics feed.
 *
 * wp_strip_all_tags() alone is wrong for this now — it would run "<li>One</li>
 * <li>Two</li>" together as "OneTwo" — so block boundaries become line breaks
 * first. Shortcodes go too: an appearance biography is stored raw and never
 * passes through the_content, so a stray shortcode would print as source.
 *
 * @param mixed $value Stored value.
 * @return string Plain text with paragraph and list breaks preserved.
 */
function law_rich_text_plain( $value ) {
	if ( ! is_scalar( $value ) ) {
		return '';
	}
	$text = law_rich_text_strip_code_blocks( strip_shortcodes( (string) $value ) );
	$text = preg_replace( '#<(?:br|/p|/li|/h[1-6]|/blockquote|/div)\s*/?>#i', "\n", $text );
	$text = wp_strip_all_tags( (string) $text );
	$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );
	// A non-breaking space is whitespace to a reader but not to trim().
	$text = str_replace( "\xc2\xa0", ' ', $text );
	$text = preg_replace( '/[ \t]+/', ' ', $text );
	$text = preg_replace( '/\n{3,}/', "\n\n", $text );
	return trim( (string) $text );
}

/**
 * A rich-text value ready to print. wpautop() turns the stored blank lines
 * back into paragraphs, wp_kses() re-applies the allowlist at output as well
 * as at input so a value stored before this field became rich text (or written
 * straight to the database by the migrator) still cannot inject markup.
 *
 * @param mixed $value Stored value.
 * @return string Safe HTML.
 */
function law_rich_text_render( $value ) {
	if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
		return '';
	}
	return wp_kses( wpautop( (string) $value ), law_rich_text_allowed_html() );
}

/**
 * Print the textarea an editor attaches to.
 *
 * The wrapper is what the toolbar and the invalid-field highlight hang off,
 * and it is also what tells law-rich-text.js which textareas are its own; a
 * textarea inside a repeater's hidden template row is skipped until the row is
 * cloned (see the clone step in assets/js/event-form.js).
 *
 * @param array $args {
 *     @type string $name     Field name. Required.
 *     @type string $id       Element id. Generated when omitted.
 *     @type string $value    Stored HTML.
 *     @type int    $rows     Fallback textarea height, used without JavaScript.
 *     @type bool   $template Repeater template row: post nothing until cloned,
 *                            so the name goes in data-name (event-form.js).
 *     @type string $required Message to show when the field is left empty, or
 *                            '' for a field the server alone validates. NEVER
 *                            the required attribute: TinyMCE hides the
 *                            textarea, and a browser refuses to submit a form
 *                            holding an invalid control it cannot focus.
 *     @type string $label    Accessible name when there is no <label for>.
 * }
 */
function law_rich_text_field( array $args = array() ) {
	static $sequence = 0;

	$args = wp_parse_args(
		$args,
		array(
			'name'     => '',
			'id'       => '',
			'value'    => '',
			'rows'     => 6,
			'template' => false,
			'required' => '',
			'label'    => '',
			'class'    => '',
		)
	);

	if ( '' === $args['id'] ) {
		$args['id'] = 'law-rich-' . ++$sequence;
	}

	$attributes = array(
		sprintf( '%s="%s"', $args['template'] ? 'data-name' : 'name', esc_attr( (string) $args['name'] ) ),
		sprintf( 'id="%s"', esc_attr( (string) $args['id'] ) ),
		sprintf( 'rows="%d"', max( 2, (int) $args['rows'] ) ),
		sprintf( 'class="%s"', esc_attr( trim( 'law-rich-text__area ' . (string) $args['class'] ) ) ),
		'data-law-rich',
	);
	if ( '' !== (string) $args['required'] ) {
		$attributes[] = sprintf( 'data-law-rich-required="%s"', esc_attr( (string) $args['required'] ) );
	}
	if ( '' !== (string) $args['label'] ) {
		$attributes[] = sprintf( 'aria-label="%s"', esc_attr( (string) $args['label'] ) );
	}

	printf(
		'<span class="law-rich-text"><textarea %s>%s</textarea></span>',
		implode( ' ', $attributes ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each attribute is escaped above.
		esc_textarea( (string) $args['value'] )
	);
}

/**
 * Load the editor. Idempotent, so every screen that renders a rich field can
 * ask for it without knowing whether another one already did.
 *
 * wp_enqueue_editor() is what prints TinyMCE and wp.editor.getDefaultSettings()
 * in the footer, at a later priority than the enqueued scripts — which is why
 * law-rich-text.js waits for DOMContentLoaded before it initialises anything.
 */
function law_rich_text_enqueue() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	wp_enqueue_editor();

	$path = get_theme_file_path( 'assets/css/rich-text.css' );
	wp_enqueue_style( 'law-rich-text', get_theme_file_uri( 'assets/css/rich-text.css' ), array( 'editor-buttons' ), file_exists( $path ) ? filemtime( $path ) : false );

	$script = get_theme_file_path( 'assets/js/law-rich-text.js' );
	wp_enqueue_script( 'law-rich-text', get_theme_file_uri( 'assets/js/law-rich-text.js' ), array( 'editor', 'jquery' ), file_exists( $script ) ? filemtime( $script ) : false, true );
	wp_localize_script( 'law-rich-text', 'lawRichTextSettings', law_rich_text_settings() );
}

/**
 * The TinyMCE configuration handed to the browser.
 *
 * toolbar1 and block_formats are the whole of the editorial policy: bold,
 * italic, the two lists, a blockquote, links, the two heading levels and
 * "Clear formatting" for pasted-in mess. valid_elements repeats
 * law_rich_text_allowed_html() client-side so that pasting a table shows the
 * host it was dropped, instead of it silently disappearing on save.
 *
 * @return array
 */
function law_rich_text_settings() {
	$content_css = get_theme_file_uri( 'assets/css/rich-text-content.css' );
	$path        = get_theme_file_path( 'assets/css/rich-text-content.css' );
	if ( file_exists( $path ) ) {
		$content_css = add_query_arg( 'ver', filemtime( $path ), $content_css );
	}

	return array(
		'contentCss'   => array( 'https://use.typekit.net/vum0moo.css', $content_css ),
		'toolbar'      => 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,removeformat,undo,redo',
		'blockFormats' => sprintf(
			'%s=p;%s=h3;%s=h4',
			__( 'Paragraph', 'law' ),
			__( 'Heading', 'law' ),
			__( 'Subheading', 'law' )
		),
		'validElements' => 'p,br,strong/b,em/i,ul,ol,li,h3,h4,blockquote,a[href|title|target|rel]',
		// A subset of core's own default list. 'wplink' (not TinyMCE's 'link')
		// is what the toolbar's Link button runs: it overrides that button with
		// WordPress's link dialog, whose markup and script wp_enqueue_editor()
		// already prints. 'wpautoresize' is left out on purpose — a growing
		// editor inside a repeater row makes the form jump as a host types.
		'plugins'       => 'lists,paste,wordpress,wplink,wptextpattern',
	);
}
