<?php
/**
 * The Flagship screen (FLAGSHIP_UI.md §4.3): one wp-admin form, a submenu of
 * Events, holding everything about the flagship conference — its title,
 * description, date, location, banner image, whether it shows on the programme,
 * and its session-by-session agenda with each session's speakers.
 *
 * It writes to ONE law_event post (functions/events/flagship.php) and its child
 * law_session posts, so the flagship reuses the whole read side: the single
 * event view, the speaker cards and biography dialogs, the speakers archive,
 * the .ics feed and, later, the bookings engine. Nothing about the workflow,
 * the fee or the invoice runs from here; see law_flagship_save().
 *
 * This file is the wp-admin SCREEN only: the menu entry, the request
 * controller and the page's own field layout. Everything else lives where both
 * editing surfaces can reach it, because the committee's front-end dashboard
 * (functions/events/flagship-dashboard.php) has to write exactly the same data:
 *
 * - reading, validating and saving a submission: functions/events/flagship.php
 * - the sessions repeater and its speaker rows: functions/events/flagship-form.php
 *
 * Two details of this screen are load-bearing:
 *
 * - The sessions repeater is NOT law_field_repeater(). That helper clones
 *   `input[data-law-name]` only, so a select, a textarea or a nested picker in
 *   its template row would never be renamed, and its row counter reuses an
 *   index after a middle row is removed. It follows the front-end pattern
 *   instead (assets/js/event-form.js): a monotonic counter on the wrapper,
 *   `data-name` on template fields, and the template row marked
 *   `data-law-row-template`, which is the attribute law-rich-text.js checks
 *   before it attaches an editor.
 * - The save redirects on success (post/redirect/get), unlike the settings
 *   screen, which echoes inline: this one inserts posts, so a refresh must not
 *   be able to re-post the form.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The screen's own capability. The committee holds the law_event cap set. */
const LAW_FLAGSHIP_CAP = 'edit_law_events';

add_action( 'admin_menu', 'law_flagship_register_menu', 20 );

/**
 * A submenu of Events. WordPress builds a post type's own submenu (All, Add
 * new, the taxonomies, the show_in_menu CPTs) before admin_menu fires, so this
 * lands last whatever priority it uses.
 */
function law_flagship_register_menu() {
	add_submenu_page(
		'edit.php?post_type=' . LAW_EVENT_CPT,
		'Flagship event',
		'Flagship',
		LAW_FLAGSHIP_CAP,
		'law-flagship',
		'law_flagship_screen'
	);
}

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'law_event_page_law-flagship' !== $hook ) {
			return;
		}
		// fields.php already enqueues wp.media, law-admin.js and law-admin.css on
		// this hook (it matches "law-"), and the editor because the screen's
		// post_type resolves to law_event. Both are asked for explicitly here so
		// the screen does not depend on either inference; law_rich_text_enqueue()
		// is idempotent.
		law_rich_text_enqueue();
		wp_enqueue_script(
			'law-flagship-admin',
			get_theme_file_uri( 'assets/js/law-flagship-admin.js' ),
			array( 'law-events-admin', 'law-rich-text' ),
			'1.0',
			true
		);
	}
);

/* The screen ________________________________________________________________ */

function law_flagship_screen() {
	if ( ! current_user_can( LAW_FLAGSHIP_CAP ) ) {
		wp_die( 'Sorry, you are not allowed to access this page.' );
	}

	// Self-provisioning, so the screen works on a fresh deploy whether or not
	// anyone has run the migration or ?setup-account-pages (FLAGSHIP_UI.md §4.9).
	if ( ! law_flagship_event_id() ) {
		law_flagship_ensure_post();
	}
	$event_id = law_flagship_event_id();

	$errors = null;
	$values = null;

	if ( isset( $_POST['law_flagship_nonce'] ) ) {
		check_admin_referer( 'law_flagship_save', 'law_flagship_nonce' );
		$values = law_flagship_input_from_post();
		$result = law_flagship_save( $values, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$errors = $result;
		} else {
			wp_safe_redirect( add_query_arg( 'law_saved', '1', law_flagship_admin_url() ) );
			exit;
		}
	}

	if ( null === $values ) {
		$values = law_flagship_form_values( $event_id );
	}

	echo '<div class="wrap"><h1>Flagship event</h1>';

	if ( ! empty( $_GET['law_saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Flagship event saved.</p></div>';
	}
	if ( $errors && $errors->has_errors() ) {
		echo '<div class="notice notice-error"><p>The flagship event was not saved:</p><ul style="list-style:disc;margin-left:1.5em">';
		foreach ( $errors->get_error_messages() as $message ) {
			echo '<li>' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	law_flagship_render_form( $values, $event_id );

	echo '</div>';
}

/* Rendering _________________________________________________________________ */

function law_flagship_render_form( array $values, $event_id ) {
	$url  = $event_id ? law_flagship_public_url( $event_id ) : '';
	$live = $event_id && 'publish' === get_post_status( $event_id );
	$week = function_exists( 'law_calendar_week_days' ) ? law_calendar_week_days() : array();

	echo '<form method="post" class="law-flagship-form">';
	wp_nonce_field( 'law_flagship_save', 'law_flagship_nonce' );

	if ( $url ) {
		if ( $live ) {
			printf(
				'<p class="description">The flagship page is <a href="%1$s" target="_blank" rel="noopener">%1$s</a>, and it shows on the programme under its date.</p>',
				esc_url( $url )
			);
		} else {
			// Not "the page is X", because it is not public yet. The address is
			// still worth stating (it does not change when the box is ticked), and
			// the link previews it for whoever can edit it.
			printf(
				'<p class="description">Not on the programme yet. Once "Show on the programme" is ticked below it will be published at <a href="%1$s" target="_blank" rel="noopener">%1$s</a>, and appear on the programme under its date. You can <a href="%1$s" target="_blank" rel="noopener">preview it</a> now.</p>',
				esc_url( $url )
			);
		}
	}

	// The sentinel rides with the first fields: PHP's max_input_vars silently
	// truncates a very large POST from the END, so anything that must survive
	// comes before the repeater.
	echo '<input type="hidden" name="law_flagship[sessions_present]" value="1">';

	// First, because it is the decision the whole screen hangs off: everything
	// below is either on the programme or it is not (Denis, 9 September 2026).
	// The committee dashboard leads with the same control, in the same place.
	law_field_checkbox( 'law_flagship[show]', 'Show on the programme', $values['show'] );
	echo '<p class="description">Until this is ticked the flagship is a draft: its page is not public and no block appears on the programme.</p>';

	law_field_text( 'law_flagship[title]', 'Title', $values['title'], array( 'class' => 'large-text' ) );

	echo '<p class="law-field"><strong>Description</strong><br>';
	law_rich_text_field(
		array(
			'name'  => 'law_flagship[description]',
			'id'    => 'law-flagship-description',
			'value' => $values['description'],
			'rows'  => 10,
			'label' => 'Flagship event description',
		)
	);
	echo '</p>';

	law_field_text( 'law_flagship[date]', 'Date', $values['date'], array( 'type' => 'date' ) );
	echo '<p class="description">2 December by default. It must fall inside the programme week set in LAW → Events settings';
	if ( $week && ! isset( $week[ $values['date'] ] ) ) {
		echo ' <strong>— the date below is outside that week, so the programme will show the flagship block above the days rather than under one</strong>';
	}
	echo '.</p>';

	law_field_text( 'law_flagship[venue]', 'Location', $values['venue'], array( 'class' => 'large-text' ) );
	echo '<p class="description">Shown in the details box, and mapped on the flagship page when it is a real address.</p>';

	echo '<div class="law-flagship-hero">';
	law_field_media( 'law_flagship[hero_image_id]', 'Banner and preview image', $values['hero_image_id'] );
	echo '</div>';
	echo '<p class="description">Used for the block on the programme and the banner on the flagship page. Leave it empty to use the site\'s default banner photograph. JPG, PNG or WebP. A wide image at least 1600 pixels across works best.</p>';

	// Bookings and pricing (FLAGSHIP_PAYMENTS.md §2.2). Above the agenda,
	// because the committee sets these once and edits the agenda repeatedly.
	echo '<h2>Bookings and pricing</h2>';
	law_field_number( 'law_flagship[places]', 'Places available', $values['places'], array( 'min' => 0 ) );
	echo '<p class="description">Applications are never refused when this runs out: the page tells the delegate the conference is full and that they will be queued, and you can still approve them. Zero means no number has been set yet.</p>';

	law_field_text( 'law_flagship[price]', 'Price before the switch (£, excluding VAT)', $values['price'], array( 'class' => 'small-text' ) );
	law_field_text( 'law_flagship[price_late]', 'Price after the switch (£, excluding VAT)', $values['price_late'], array( 'class' => 'small-text' ) );
	law_field_text( 'law_flagship[price_switch]', 'Price switches at', $values['price_switch'], array( 'class' => 'regular-text' ) );
	echo '<p class="description">Date and time as <code>YYYY-MM-DD HH:MM</code>, in UK time. VAT is added on top of both prices at the standard rate. A delegate is charged the price that applied when they saved their card, even if they are approved after the switch.</p>';

	$preview = law_flagship_price_preview_line( $values['price'], $values['price_late'], $values['price_switch'] );
	if ( '' !== $preview ) {
		printf( '<p class="description"><strong>%s</strong></p>', esc_html( $preview ) );
	}

	law_flagship_render_sessions( $values['sessions'] );

	submit_button( 'Save flagship event' );
	echo '</form>';

	law_flagship_render_new_speaker_template();
}
