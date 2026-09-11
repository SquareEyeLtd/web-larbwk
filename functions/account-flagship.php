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
 * The scarcity tone of the Places row, or '' when there is no row to colour.
 *
 * A sibling rather than a second return value from law_flagship_details_places(),
 * which every caller and a test read as a plain string.
 *
 * It exists so the flagship does not say "6 places left" in quiet navy on the
 * one page where the number matters most, while every hosted event shouts:
 * the flagship states its count as a FACT in the box, not in the panel below,
 * so the panel's wash alone would leave the number unmarked.
 *
 * @return string open|low|full, or '' for any event with no Places row.
 */
function law_flagship_details_places_tone( array $event ) {
	$event_id = (int) ( $event['id'] ?? 0 );
	if ( ! $event_id || ! function_exists( 'law_flagship_is' ) || ! law_flagship_is( $event_id ) ) {
		return '';
	}
	$places = law_flagship_places();
	if ( $places['available'] < 1 ) {
		return '';
	}

	return law_flagship_places_tone();
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
		// Outside the panel, as on the hosted-event control: .law-form-notice
		// carries its own light-surface colours and would be unreadable on a
		// filled one.
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

	// The same filled panel the hosted events use (law_booking_panel()), so the
	// two pages cannot drift into two panel styles. Resolved here, from values
	// already read, rather than in a tone function of its own, which would
	// repeat the application lookup.
	if ( $application ) {
		$tone = 'mine';
	} elseif ( $price < 1 ) {
		$tone = 'open';
	} elseif ( $passed ) {
		$tone = 'closed';
	} else {
		$tone = law_flagship_places_tone();
	}

	// No status pill: every flagship state already opens with a
	// .law-booking-state heading, and the one that does not (the plain Apply
	// button) states its count in the Places row of the box above.
	ob_start();
	law_flagship_render_action_body( $event, $preview, $application, $price, $passed );
	law_booking_panel( $tone, trim( (string) ob_get_clean() ) );
}

/**
 * The scarcity tone of the conference itself: open | low | full.
 *
 * Shares law_event_capacity_warning_at() with the hosted events, so "nearly
 * full" means the same thing on both pages and on the host's warning email.
 */
function law_flagship_places_tone() {
	$places = law_flagship_places();
	if ( $places['available'] < 1 ) {
		return 'open';
	}
	if ( $places['full'] ) {
		return 'full';
	}
	return $places['remaining'] <= law_event_capacity_warning_at( $places['available'] ) ? 'low' : 'open';
}

/**
 * Where one viewer stands on the flagship conference: the single decision the
 * event page's control and the programme card both read.
 *
 * The hosted events' law_booking_state() for the flagship, and here for the
 * same reason: the page has room for a heading and an explanatory line, a card
 * has room for a button, and the two must not come to different conclusions
 * about the same application. Named law_flagship_action_state() because
 * law_flagship_state() is already the control's heading renderer.
 *
 * @param array $args {
 *     @type bool $preview Committee preview: report the conference's own state,
 *                         never the viewer's application.
 *     @type int  $user_id The viewer. Defaults to the current user, or 0 under
 *                         preview.
 * }
 * @return array|null NULL when this is not the flagship at all. {
 *     @type string       $state       attending|attended|payment-failed|needs-card
 *                                     |in-review|past|not-open|apply
 *     @type int          $event_id
 *     @type WP_Post|null $application The viewer's own, if any
 *     @type string       $manage_url  Where their application is managed, '' without one
 *     @type bool         $passed      The conference has started or finished
 *     @type int          $price       Net price in pence; under 1 means not on sale
 *     @type bool         $full        Applications still taken, but no places left
 * }
 */
function law_flagship_action_state( $event_id, array $args = array() ) {
	$event_id = (int) $event_id;
	if ( ! $event_id || ! law_flagship_is( $event_id ) ) {
		return null;
	}

	$preview = ! empty( $args['preview'] );
	$user_id = array_key_exists( 'user_id', $args ) ? (int) $args['user_id'] : ( $preview ? 0 : get_current_user_id() );

	$price       = law_flagship_price_pence( 0, $event_id );
	$start       = (string) law_event_meta( $event_id, '_law_start' );
	$passed      = '' !== $start && strtotime( $start ) <= current_time( 'timestamp' );
	$application = $user_id ? law_flagship_application_for_user( $user_id, $event_id ) : null;

	$state = array(
		'state'       => 'apply',
		'event_id'    => $event_id,
		'application' => $application,
		'manage_url'  => $application ? law_booking_manage_url( (int) $application->ID ) : '',
		'passed'      => $passed,
		'price'       => $price,
		'full'        => false,
	);

	// The viewer's OWN state comes first, and in particular BEFORE "this event
	// has taken place": a delegate who attended still needs the link to their
	// booking and their VAT receipt afterwards.
	if ( $application ) {
		$booking_id = (int) $application->ID;
		if ( 'publish' === $application->post_status ) {
			$state['state'] = $passed ? 'attended' : 'attending';
			return $state;
		}
		// Past the event, an undecided or unpaid application is history: there
		// is nothing useful left to do with it.
		if ( $passed ) {
			$state['state'] = 'past';
			return $state;
		}
		if ( 'law-payment-failed' === $application->post_status ) {
			$state['state'] = 'payment-failed';
			return $state;
		}
		$state['state'] = 'pending_setup' === (string) law_event_meta( $booking_id, '_law_payment_status' )
			? 'needs-card'
			: 'in-review';
		return $state;
	}

	// A price of 0 is the committee's way of saying "not on sale", so it reads
	// the same as no date at all rather than offering a free place.
	if ( $price < 1 ) {
		$state['state'] = 'not-open';
		return $state;
	}
	if ( $passed ) {
		$state['state'] = 'past';
		return $state;
	}

	// Deliberately not a state of its own: a full conference still takes
	// applications and queues them (Denis, 10 September 2026). The page adds a
	// line saying so; the button is the same button.
	$places         = law_flagship_places();
	$state['full']  = ! empty( $places['full'] );
	return $state;
}

/**
 * The button one flagship state offers: its label and where it goes. NULL when
 * the state offers no action at all.
 *
 * One map, because the event page and the programme card both put this button
 * on screen and it would be worse than useless if they named the same thing two
 * ways -- "Sort out my payment" on one page and something else on the other,
 * for the same failed charge.
 *
 * @param array $state law_flagship_action_state().
 */
function law_flagship_action_link( array $state ) {
	switch ( $state['state'] ) {
		case 'attending':
			return array( 'label' => __( 'View my booking', 'law' ), 'url' => $state['manage_url'] );
		case 'attended':
			return array( 'label' => __( 'View my booking and receipt', 'law' ), 'url' => $state['manage_url'] );
		case 'payment-failed':
			return array( 'label' => __( 'Sort out my payment', 'law' ), 'url' => $state['manage_url'] );
		case 'needs-card':
			return array( 'label' => __( 'Add my payment details', 'law' ), 'url' => $state['manage_url'] );
		case 'in-review':
			return array( 'label' => __( 'View my application', 'law' ), 'url' => $state['manage_url'] );
		case 'apply':
			return array(
				'label' => __( 'Apply', 'law' ),
				'url'   => add_query_arg( 'law_flagship_apply', '1', get_permalink( (int) $state['event_id'] ) ),
			);
	}
	// 'not-open' and 'past': the page explains, and there is nothing to press.
	return null;
}

/**
 * The flagship's BUTTON, with none of the control's explanatory paragraphs, for
 * the repeat at the foot of the conference page. The hosted events'
 * law_booking_render_action_buttons() routes here; see it for why the foot of
 * the page gets buttons and not the whole control.
 *
 * @param bool $preview Committee preview: inert, as at the top.
 */
function law_flagship_render_action_buttons( array $event, $preview = false ) {
	$state = law_flagship_action_state( (int) ( $event['id'] ?? 0 ), array( 'preview' => (bool) $preview ) );
	if ( ! $state ) {
		return;
	}

	// Apply goes through the opener, so the foot of the page gets the same
	// fetch-on-press link (and the same inert button under preview) as the top.
	//
	// Except on the no-JS path, where the opener IS the form: ?law_flagship_apply=1
	// renders it in the page, and repeating it here would put two copies of the
	// same form, with the same field names and a duplicated id, on one page.
	if ( 'apply' === $state['state'] ) {
		if ( empty( $_GET['law_flagship_apply'] ) ) {
			law_flagship_render_opener( $event, $preview );
		}
		return;
	}

	// Every other state is a plain link into the account area. A preview has no
	// viewer and so never reaches one.
	$link = law_flagship_action_link( $state );
	if ( $link ) {
		printf( '<a class="button orange" href="%s">%s</a>', esc_url( $link['url'] ), esc_html( $link['label'] ) );
	}
}

/**
 * The flagship's button on the programme card (parts/events/flagship-card.php),
 * as one entry in the same shape parts/loop/event.php's actions take.
 *
 * The flagship is not an ordinary card and has its own block, but it needs the
 * same treatment: a visitor browsing the programme should be able to apply, or
 * reach their application, without opening the conference page first. Like the
 * hosted events, the card carries NO dialog -- booking-form.js fetches the
 * apply dialog from {permalink}?law_flagship_apply=1&law_dialog=1 on the press.
 *
 * @return array|null
 */
function law_flagship_card_action( array $event ) {
	$event_id = (int) ( $event['id'] ?? 0 );

	// The committee programme lists the flagship before it is published, and an
	// Apply button on a conference nobody can apply to would be a broken
	// promise. Gated here rather than in the resolver, because the conference
	// page's own control deliberately renders for the committee preview.
	if ( ! $event_id || 'publish' !== get_post_status( $event_id ) ) {
		return null;
	}

	$state = law_flagship_action_state( $event_id );
	if ( ! $state ) {
		return null;
	}
	$link = law_flagship_action_link( $state );
	if ( ! $link ) {
		return null;
	}

	$action = array(
		'label' => $link['label'],
		'url'   => $link['url'],
		'class' => 'orange law-event-card__button--book',
	);
	if ( 'apply' === $state['state'] ) {
		// Only the Apply button has a dialog behind it; the rest are plain links
		// into the account area. Signed-out visitors get it too: being told an
		// account is needed is exactly what the dialog is for.
		$action['sr_label'] = sprintf(
			/* translators: %s: event title. */
			__( 'Apply for %s', 'law' ),
			(string) ( $event['title'] ?? '' )
		);
		$action['dialog'] = (int) $state['event_id'];
	}
	return $action;
}

/**
 * The panel's contents. Split out so law_flagship_render_action() can buffer
 * it whole; the branches below still return early as they always did.
 *
 * @param WP_Post|null $application The viewer's own application, if any.
 * @param int          $price       Net price in pence; under 1 means not on sale.
 * @param bool         $passed      The conference has started or finished.
 */
function law_flagship_render_action_body( array $event, $preview, $application, $price, $passed ) {
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

	// The no-JS path: ?law_flagship_apply=1 renders the form in the page. It
	// still submits over fetch, so it needs the success dialog, and that one is
	// position:fixed while this control renders inside the hero's event details
	// box, whose .grid-container is a stacking context that would clamp it under
	// the fixed header. So it goes to wp_footer, at body level.
	if ( ! empty( $_GET['law_flagship_apply'] ) ) {
		law_flagship_footer_modal( $event, 'success' );
		get_template_part( 'parts/events/flagship-apply-modal', null, array( 'event' => $event, 'context' => 'inline' ) );
		return;
	}

	// The button path carries NO dialog: booking-form.js opens the placeholder
	// on the press and fetches this conference's dialogs into it, the same flow
	// the programme card and the hosted events use
	// (law_booking_maybe_render_dialog()). The stacking-context problem goes
	// away with the markup, since the fetched dialogs land at body level.
	printf(
		'<a class="button orange" href="%s" data-law-book="%s">%s</a>',
		esc_url( add_query_arg( 'law_flagship_apply', '1', get_permalink( $event_id ) ) ),
		esc_attr( (string) $event_id ),
		esc_html__( 'Apply', 'law' )
	);
}

/** What the delegate sees once they have an application of their own. */
function law_flagship_render_own_state( WP_Post $application, $passed = false ) {
	$booking_id = (int) $application->ID;
	$price      = law_booking_price( $booking_id );

	// Which state this is, and the label its button carries, come from the
	// shared pair (law_flagship_action_state() / law_flagship_action_link()):
	// the programme card puts the same button on screen, and a failed charge
	// must not be "Sort out my payment" on one surface and something else on
	// the other. The headings and the explanatory lines stay here, because they
	// are this surface's -- a card has no room for them.
	$state = law_flagship_action_state(
		(int) $application->post_parent,
		array( 'user_id' => (int) $application->post_author )
	);
	if ( ! $state ) {
		return;
	}
	$link   = law_flagship_action_link( $state );
	$button = function () use ( $link ) {
		if ( $link ) {
			printf( '<a class="button orange" href="%s">%s</a>', esc_url( $link['url'] ), esc_html( $link['label'] ) );
		}
	};

	if ( 'attending' === $state['state'] || 'attended' === $state['state'] ) {
		law_flagship_state(
			'attended' === $state['state'] ? __( 'You attended this event', 'law' ) : __( "You're attending", 'law' ),
			law_event_meta( $booking_id, '_law_is_complimentary' )
				? __( 'Your place is confirmed, with our compliments.', 'law' )
				: sprintf(
					/* translators: %s: the amount paid. */
					__( 'Your place is confirmed and %s has been paid.', 'law' ),
					law_events_format_pence( $price['gross'] )
				)
		);
		$button();
		return;
	}

	// Past the event, an undecided or unpaid application is history: there is
	// nothing useful left to do with it, and offering "sort out my payment"
	// for a conference that has happened would be worse than saying nothing.
	if ( 'past' === $state['state'] ) {
		law_flagship_state( __( 'This event has taken place', 'law' ) );
		return;
	}

	if ( 'payment-failed' === $state['state'] ) {
		$sca = 'action_required' === (string) law_event_meta( $booking_id, '_law_payment_status' );
		law_flagship_state(
			__( 'Your payment needs attention', 'law' ),
			$sca
				? __( 'Your application has been approved, but your bank needs you to confirm the payment.', 'law' )
				: __( 'Your application has been approved, but we could not take the payment.', 'law' )
		);
		$button();
		return;
	}

	// law-applied.
	$waiting = 'needs-card' === $state['state'];
	law_flagship_state(
		$waiting ? __( 'Your application needs your payment details', 'law' ) : __( 'Your application is being reviewed', 'law' ),
		$waiting
			? __( 'We cannot put your application to the committee until your payment details are saved. Nothing is charged unless you are approved.', 'law' )
			: __( 'The committee will decide shortly, and we will email you either way. Nothing has been charged.', 'law' )
	);
	$button();
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
 *
 * Only 'success' is deferred in practice now, and only on the no-JS
 * ?law_flagship_apply=1 path: the Apply button's dialogs are fetched on the
 * press and injected at body level by booking-form.js instead.
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
