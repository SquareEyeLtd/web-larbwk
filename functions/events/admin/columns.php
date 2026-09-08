<?php
/**
 * Admin list-table columns and filters for the module CPTs, replacing the
 * old wp-admin GF entries screens (status, tier and year filtering).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* law_event columns _________________________________________________________ */

add_filter( 'manage_' . LAW_EVENT_CPT . '_posts_columns', function ( $columns ) {
	$new = array(
		'cb'              => $columns['cb'] ?? '',
		'title'           => 'Event',
		'law_status'      => 'Status',
		'law_slot'        => 'Slot',
		'law_host'        => 'Host',
		'law_fee'         => 'Fee',
		'law_payment'     => 'Payment',
		'law_reference'   => 'Reference',
		'date'            => $columns['date'] ?? 'Date',
	);
	return $new;
} );

add_action( 'manage_' . LAW_EVENT_CPT . '_posts_custom_column', function ( $column, $post_id ) {
	switch ( $column ) {
		case 'law_status':
			echo esc_html( law_event_status_label( get_post( $post_id ) ) );
			break;
		case 'law_slot':
			echo esc_html( (string) law_event_meta( $post_id, '_law_slot_label' ) ?: '—' );
			break;
		case 'law_host':
			$author = get_user_by( 'id', (int) get_post_field( 'post_author', $post_id ) );
			echo esc_html( $author ? $author->display_name : '—' );
			$org = (string) law_event_meta( $post_id, '_law_host_organisations' );
			if ( $org ) {
				echo '<br><span class="description">' . esc_html( $org ) . '</span>';
			}
			break;
		case 'law_fee':
			$tier = (string) law_event_meta( $post_id, '_law_fee_tier' );
			echo esc_html( $tier ? ucfirst( $tier ) : '—' );
			if ( law_event_meta( $post_id, '_law_fee_override' ) ) {
				echo ' <em>(override)</em>';
			}
			$fee = (int) law_event_meta( $post_id, '_law_fee_pence' );
			if ( $fee ) {
				echo '<br>' . esc_html( law_events_format_pence( $fee ) );
			}
			break;
		case 'law_payment':
			echo esc_html( ucfirst( (string) law_event_meta( $post_id, '_law_payment_status' ) ) ?: '—' );
			$error = law_event_meta( $post_id, '_law_stripe_error' );
			if ( is_array( $error ) && ! empty( $error['message'] ) ) {
				echo ' <span style="color:#b32d2e">⚠ Stripe error</span>';
			}
			break;
		case 'law_reference':
			echo '<code>' . esc_html( (string) law_event_meta( $post_id, '_law_reference' ) ) . '</code>';
			break;
	}
}, 10, 2 );

/** Fee tier filter dropdown on the events list. */
add_action( 'restrict_manage_posts', function ( $post_type ) {
	if ( LAW_EVENT_CPT !== $post_type ) {
		return;
	}
	$current = sanitize_key( $_GET['law_fee_tier'] ?? '' );
	echo '<select name="law_fee_tier"><option value="">All fee tiers</option>';
	foreach ( (array) law_events_setting( 'fee_tiers', array() ) as $tier => $config ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $tier ),
			selected( $current, $tier, false ),
			esc_html( $config['label'] )
		);
	}
	echo '</select>';

	wp_dropdown_categories(
		array(
			'taxonomy'        => 'law_year',
			'name'            => 'law_year',
			'value_field'     => 'slug',
			'selected'        => sanitize_key( $_GET['law_year'] ?? '' ),
			'show_option_all' => 'All programme years',
			'hide_empty'      => false,
		)
	);
} );

add_action( 'pre_get_posts', function ( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() || LAW_EVENT_CPT !== $query->get( 'post_type' ) ) {
		return;
	}
	$tier = sanitize_key( $_GET['law_fee_tier'] ?? '' );
	if ( $tier ) {
		$query->set( 'meta_key', '_law_fee_tier' );
		$query->set( 'meta_value', $tier );
	}
	$year = sanitize_key( $_GET['law_year'] ?? '' );
	if ( $year && '0' !== $year ) {
		$query->set( 'tax_query', array( array( 'taxonomy' => 'law_year', 'field' => 'slug', 'terms' => $year ) ) );
	}
} );

/* law_speaker columns _______________________________________________________ */

add_filter( 'manage_' . LAW_SPEAKER_CPT . '_posts_columns', function ( $columns ) {
	unset( $columns['date'] );
	$columns['law_email']  = 'Email';
	$columns['law_org']    = 'Organisation (first event)';
	$columns['law_events'] = 'Confirmed events';
	return $columns;
} );

add_action( 'manage_' . LAW_SPEAKER_CPT . '_posts_custom_column', function ( $column, $post_id ) {
	switch ( $column ) {
		case 'law_email':
			echo esc_html( (string) law_event_meta( $post_id, '_law_speaker_email' ) ?: '—' );
			break;
		case 'law_org':
			// Per-event data: the archive rule is the first confirmed appearance.
			echo esc_html( law_speaker_first_appearance( (int) $post_id )['organisation'] ?: '—' );
			break;
		case 'law_events':
			$map = law_speakers_confirmed_event_map();
			echo esc_html( (string) count( $map[ (int) $post_id ] ?? array() ) );
			break;
	}
}, 10, 2 );

/* law_session columns _______________________________________________________ */

add_filter( 'manage_' . LAW_SESSION_CPT . '_posts_columns', function ( $columns ) {
	unset( $columns['date'] );
	$columns['law_event'] = 'Event';
	$columns['law_time']  = 'Time';
	return $columns;
} );

add_action( 'manage_' . LAW_SESSION_CPT . '_posts_custom_column', function ( $column, $post_id ) {
	if ( 'law_event' === $column ) {
		$parent = (int) get_post_field( 'post_parent', $post_id );
		if ( $parent ) {
			printf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $parent ) ), esc_html( get_the_title( $parent ) ) );
		} else {
			echo '—';
		}
	}
	if ( 'law_time' === $column ) {
		echo esc_html( trim( law_event_meta( $post_id, '_law_start_time' ) . '–' . law_event_meta( $post_id, '_law_end_time' ), '–' ) ?: '—' );
	}
}, 10, 2 );
