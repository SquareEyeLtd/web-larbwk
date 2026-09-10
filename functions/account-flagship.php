<?php
/**
 * The flagship conference's front-end control and application form
 * (FLAGSHIP_PAYMENTS.md §4.1, §4.2).
 *
 * The counterpart of functions/account-bookings.php, which does the same job
 * for hosted events. It is a separate file because almost nothing is shared:
 * a hosted event is booked instantly and free, the flagship is applied for,
 * reviewed and charged, so the states, the copy and the form are different
 * all the way down. What IS shared is reused: the modal skeleton, the fetch
 * layer, the notice markup and the form styles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Price row in the event details box, or '' for any event that does not
 * charge for a place.
 *
 * The advertised figure, "£550.00 + VAT" — the net price plus a note that VAT
 * is added, NOT the total. Denis, 10 September 2026: the box states the price
 * the way LAW quotes it, and the arithmetic (net, VAT, total) belongs in the
 * application form, next to the consent to be charged it. Doing the sum twice
 * in two places is also two places for it to disagree.
 */
function law_flagship_details_price( array $event ) {
	$event_id = (int) ( $event['id'] ?? 0 );
	if ( ! $event_id || ! function_exists( 'law_flagship_is' ) || ! law_flagship_is( $event_id ) ) {
		return '';
	}
	$price = law_flagship_price_pence( 0, $event_id );
	if ( $price < 1 ) {
		return '';
	}

	return sprintf(
		/* translators: %s: the price excluding VAT. */
		__( '%s + VAT', 'law' ),
		law_events_format_pence( $price )
	);
}

/**
 * The Places row: how many are left, or that there are none.
 *
 * Empty when no number has been set, because "0 places left" and "nobody has
 * said how many there are" are different things and only one of them is worth
 * a line in the box.
 */
function law_flagship_details_places( array $event ) {
	$event_id = (int) ( $event['id'] ?? 0 );
	if ( ! $event_id || ! function_exists( 'law_flagship_is' ) || ! law_flagship_is( $event_id ) ) {
		return '';
	}
	$places = law_flagship_places();
	if ( $places['available'] < 1 ) {
		return '';
	}
	if ( $places['full'] ) {
		return __( 'Fully booked', 'law' );
	}

	return sprintf(
		/* translators: %s: number of places. */
		_n( '%s place left', '%s places left', $places['remaining'], 'law' ),
		number_format_i18n( $places['remaining'] )
	);
}

/**
 * The control in the flagship page's details box.
 *
 * Reached through law_booking_render_action(), which hands the flagship
 * straight here, so the details box has one call site and the routing
 * decision lives in one place.
 *
 * @param array $event   The hydrated event array.
 * @param bool  $preview The committee's preview: show what an attendee will
 *                       see, with nothing actionable and no personal state.
 */
function law_flagship_render_action( array $event, $preview = false ) {
	$event_id = (int) ( $event['id'] ?? 0 );
	if ( ! $event_id ) {
		return;
	}

	if ( ! $preview ) {
		law_flagship_notice_render();
	}

	$price  = law_flagship_price_pence( 0, $event_id );
	$start  = (string) law_event_meta( $event_id, '_law_start' );
	$passed = '' !== $start && strtotime( $start ) <= current_time( 'timestamp' );

	// The viewer's OWN state comes first, and in particular BEFORE "this
	// event has taken place": a delegate who attended still needs the link to
	// their booking and their VAT receipt afterwards, and testing the date
	// first took both away the moment the conference started.
	$user_id     = $preview ? 0 : get_current_user_id();
	$application = $user_id ? law_flagship_application_for_user( $user_id, $event_id ) : null;

	if ( $application ) {
		law_flagship_render_own_state( $application, $passed );
		return;
	}

	// State: nothing to apply for yet. A price of 0 is the committee's way of
	// saying "not on sale", so it reads the same as no date at all rather
	// than offering a free place.
	if ( $price < 1 ) {
		law_flagship_state( __( 'Applications open soon', 'law' ), __( 'Applications for this conference have not opened yet. Please check back.', 'law' ) );
		return;
	}

	// State: it has happened, and this viewer has no place on it.
	if ( $passed ) {
		law_flagship_state( __( 'This event has taken place', 'law' ) );
		return;
	}

	// The price and the count are now facts in the box above (the Price and
	// Places rows), so the control repeats neither: just the button, and one
	// line in the single case that would otherwise surprise somebody — a
	// conference that is full and still taking applications.
	$places = law_flagship_places();
	if ( $places['full'] ) {
		printf(
			'<p class="law-booking-substate">%s</p>',
			esc_html__( 'You can still apply, and we will be in touch if a place opens up.', 'law' )
		);
	}

	law_flagship_render_opener( $event, $preview );
}

/**
 * The Apply button plus its two dialogs.
 *
 * The opener is a real link to the inline no-JS form, which this function
 * renders in its place when the link is followed; booking-form.js upgrades
 * the link to open the modal instead. The hosted-event control's precedent
 * (law_booking_render_opener()), including why the dialogs are deferred.
 *
 * @param bool $preview Committee preview: render the button inert, and defer
 *                      no dialogs, so a page whose event may not even be
 *                      published has nothing actionable on it.
 */
function law_flagship_render_opener( array $event, $preview = false ) {
	$event_id = (int) $event['id'];

	// A disabled <button> is inert by every route — pointer, keyboard,
	// assistive tech and form submission — where an <a> with only
	// aria-disabled would still follow its href on Enter.
	if ( $preview ) {
		printf(
			'<button type="button" class="button orange" disabled aria-disabled="true">%s</button>',
			esc_html__( 'Apply', 'law' )
		);
		return;
	}

	// Both dialogs are position:fixed, and this control renders inside the
	// hero's event details box, whose .grid-container is a stacking context
	// that would clamp them under the fixed header. So they go to wp_footer,
	// at body level, where no stacking context can reach them.
	law_flagship_footer_modal( $event, 'success' );

	if ( ! empty( $_GET['law_flagship_apply'] ) ) {
		get_template_part( 'parts/events/flagship-apply-modal', null, array( 'event' => $event, 'context' => 'inline' ) );
		return;
	}

	law_flagship_footer_modal( $event, 'apply' );

	printf(
		'<a class="button orange" href="%s" data-law-modal-open="law-flagship-modal">%s</a>',
		esc_url( add_query_arg( 'law_flagship_apply', '1', get_permalink( $event_id ) ) ),
		esc_html__( 'Apply', 'law' )
	);
}

/** What the delegate sees once they have an application of their own. */
function law_flagship_render_own_state( WP_Post $application, $passed = false ) {
	$booking_id = (int) $application->ID;
	$manage     = law_booking_manage_url( $booking_id );
	$price      = law_booking_price( $booking_id );

	if ( 'publish' === $application->post_status ) {
		law_flagship_state(
			$passed ? __( 'You attended this event', 'law' ) : __( "You're attending", 'law' ),
			law_event_meta( $booking_id, '_law_is_complimentary' )
				? __( 'Your place is confirmed, with our compliments.', 'law' )
				: sprintf(
					/* translators: %s: the amount paid. */
					__( 'Your place is confirmed and %s has been paid.', 'law' ),
					law_events_format_pence( $price['gross'] )
				)
		);
		printf(
			'<a class="button orange" href="%s">%s</a>',
			esc_url( $manage ),
			esc_html( $passed ? __( 'View my booking and receipt', 'law' ) : __( 'View my booking', 'law' ) )
		);
		return;
	}

	// Past the event, an undecided or unpaid application is history: there is
	// nothing useful left to do with it, and offering "sort out my payment"
	// for a conference that has happened would be worse than saying nothing.
	if ( $passed ) {
		law_flagship_state( __( 'This event has taken place', 'law' ) );
		return;
	}

	if ( 'law-payment-failed' === $application->post_status ) {
		$sca = 'action_required' === (string) law_event_meta( $booking_id, '_law_payment_status' );
		law_flagship_state(
			__( 'Your payment needs attention', 'law' ),
			$sca
				? __( 'Your application has been approved, but your bank needs you to confirm the payment.', 'law' )
				: __( 'Your application has been approved, but we could not take the payment.', 'law' )
		);
		printf( '<a class="button orange" href="%s">%s</a>', esc_url( $manage ), esc_html__( 'Sort out my payment', 'law' ) );
		return;
	}

	// law-applied.
	$waiting = 'pending_setup' === (string) law_event_meta( $booking_id, '_law_payment_status' );
	law_flagship_state(
		$waiting ? __( 'Your application needs your payment details', 'law' ) : __( 'Your application is being reviewed', 'law' ),
		$waiting
			? __( 'We cannot put your application to the committee until your payment details are saved. Nothing is charged unless you are approved.', 'law' )
			: __( 'The committee will decide shortly, and we will email you either way. Nothing has been charged.', 'law' )
	);
	printf(
		'<a class="button orange" href="%s">%s</a>',
		esc_url( $manage ),
		esc_html( $waiting ? __( 'Add my payment details', 'law' ) : __( 'View my application', 'law' ) )
	);
}

/** The control's heading and supporting line, in the shared markup. */
function law_flagship_state( $heading, $sub = '' ) {
	printf( '<p class="law-booking-state">%s</p>', esc_html( $heading ) );
	if ( '' !== $sub ) {
		printf( '<p class="law-booking-substate">%s</p>', esc_html( $sub ) );
	}
}

/**
 * The notice map for every flagship surface, so the event page, My bookings
 * and the committee list cannot word the same outcome three ways.
 *
 * @return array{0:string,1:string}|null [ 'ok'|'error', message ].
 */
function law_flagship_notice_text( $key ) {
	$map = array(
		'flagship-applied'     => array( 'ok', __( 'Your application has been received. We will email you as soon as the committee has decided.', 'law' ) ),
		'flagship-card'        => array( 'ok', __( 'Your payment details have been saved. Nothing is charged unless your application is approved.', 'law' ) ),
		'flagship-card-failed' => array( 'error', __( 'Your payment details were not saved. Please try again.', 'law' ) ),
		// Stripe sends the delegate back here when they abandon the hosted
		// page. Without a word they are left on a page that looks unchanged
		// and cannot tell whether anything was saved.
		'flagship-card-cancelled' => array( 'ok', __( 'No payment details were saved, so nothing has been charged. Your application is still here whenever you want to add them.', 'law' ) ),
		'flagship-price-changed'  => array( 'error', __( 'The price changed while you were filling in the form, so nothing was submitted. Please check the new price and apply again.', 'law' ) ),
		// Only shown when the retry actually went through. While it was shown
		// for every retry it contradicted the failure panel underneath it.
		'flagship-paid'        => array( 'ok', __( 'Thank you. The payment has gone through and your place is confirmed.', 'law' ) ),
		'flagship-retried'     => array( 'ok', __( 'Thank you. We have tried the payment again with your new payment method.', 'law' ) ),
		'flagship-withdrawn'   => array( 'ok', __( 'Your application has been withdrawn and the payment details we held have been removed.', 'law' ) ),
		'flagship-failed'      => array( 'error', __( 'Sorry, that could not be done. Please check the details and try again.', 'law' ) ),
		'flagship-denied'      => array( 'error', __( 'Sorry, you cannot do that.', 'law' ) ),
		'rate-limited'         => array( 'error', __( 'Too many actions in a short time. Please wait a moment and try again.', 'law' ) ),
	);

	return $map[ $key ] ?? null;
}

/** Print the notice for ?law_notice=, if there is one. */
function law_flagship_notice_render() {
	$notice = law_flagship_notice_text( sanitize_key( (string) ( $_GET['law_notice'] ?? '' ) ) );
	if ( ! $notice ) {
		return;
	}
	printf(
		'<p class="law-form-notice %s" role="alert">%s</p>',
		'ok' === $notice[0] ? 'is-success' : 'is-error',
		esc_html( $notice[1] )
	);
}

/**
 * Defer the application dialogs to wp_footer.
 *
 * Same reason as the booking modal's: the dialogs are position:fixed and the
 * hero's .grid-container is a stacking context, which would clamp them under
 * the fixed header if they rendered where the control does.
 */
function law_flagship_footer_modal( array $event, $which = 'apply' ) {
	add_action(
		'wp_footer',
		static function () use ( $event, $which ) {
			get_template_part(
				'success' === $which ? 'parts/events/flagship-success-modal' : 'parts/events/flagship-apply-modal',
				null,
				array( 'event' => $event, 'context' => 'modal' )
			);
		}
	);
}

/**
 * The values the application form starts with: whatever the delegate typed on
 * a refused attempt, else their profile.
 */
function law_flagship_apply_values( $user_id ) {
	$profile = law_profile_values( (int) $user_id );
	$state   = law_flagship_form_state();
	$typed   = (array) ( $state['input']['answers'] ?? array() );

	$values = array(
		'salutation'          => (string) get_user_meta( (int) $user_id, 'salutation', true ),
		'first_name'          => (string) ( $profile['first_name'] ?? '' ),
		'last_name'           => (string) ( $profile['last_name'] ?? '' ),
		'job_title'           => (string) ( $profile['job_title'] ?? '' ),
		'organisation'        => (string) ( $profile['organisation'] ?? '' ),
		'country'             => (string) ( $profile['country'] ?? '' ),
		'dietary_other'       => (string) ( $profile['dietary_other'] ?? '' ),
		'accessibility_other' => (string) ( $profile['accessibility_other'] ?? '' ),
	);

	foreach ( $values as $key => $value ) {
		if ( isset( $typed[ $key ] ) ) {
			$values[ $key ] = (string) $typed[ $key ];
		}
	}
	$values['errors'] = (array) ( $state['errors'] ?? array() );

	return $values;
}

/**
 * The flagship page's assets: the shared form styles, the modal component and
 * the fetch layer. Hooked rather than printed at partial time, so the
 * stylesheets reach the head.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( 'cpt' !== law_events_source() || ! function_exists( 'law_flagship_is' ) ) {
			return;
		}
		if ( ! is_singular( LAW_EVENT_CPT ) || ! law_flagship_is( get_queried_object_id() ) ) {
			return;
		}
		wp_enqueue_style( 'law-event-form', get_theme_file_uri( 'assets/css/event-form.css' ), array(), filemtime( get_theme_file_path( 'assets/css/event-form.css' ) ) );
		law_modal_enqueue();
		wp_enqueue_script( 'law-booking-form', get_theme_file_uri( 'assets/js/booking-form.js' ), array( 'law-modal' ), filemtime( get_theme_file_path( 'assets/js/booking-form.js' ) ), true );
	}
);
