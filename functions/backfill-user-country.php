<?php
/**
 * One-off backfill: country of residence from form 1 entries to user meta.
 *
 * User Registration feed 1 never mapped form 1 field 10, so the country was
 * captured on every registration entry but never written to the user. Two
 * knock-on effects then wrote a wrong value for some users: form 3's
 * {user:country} prefill resolved empty and its select fell through to its
 * first choice, and the ACF `country` field has allow_null off, so any save
 * of the wp-admin user screen stamped the same first choice.
 *
 * This script copies field 10 from each user's most recent registration entry
 * into their `country` user meta. It writes through ACF's update_field() when
 * ACF is available, so the `_country` reference key is set and the value shows
 * correctly on the user's ACF admin screen.
 *
 * TEMPORARY. Remove this file and its require line from functions.php once the
 * backfill has run.
 *
 * Usage, as an administrator:
 *
 *   /wp-admin/?law_backfill_country=1     dry run, writes nothing, prints a
 *                                         report and a link to run for real
 *
 * The report's links carry a nonce. Add &clear=1 to also delete suspect values
 * for users who have no registration entry to source a correct value from.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_BACKFILL_COUNTRY_FORM     = 1;
const LAW_BACKFILL_COUNTRY_FIELD    = '10';
const LAW_BACKFILL_COUNTRY_META     = 'country';
const LAW_BACKFILL_COUNTRY_NONCE    = 'law_backfill_country';
const LAW_BACKFILL_COUNTRY_DONE_OPT = 'law_backfill_country_done';

/**
 * Values that are known to have been written by mistake rather than chosen.
 * These are overwritten when a registration entry gives a different answer.
 * Anything else stored on a user is treated as deliberate and left alone.
 */
function law_backfill_country_suspect_values() {
	return apply_filters( 'law_backfill_country_suspect_values', array( 'Afghanistan' ) );
}

add_action( 'admin_init', 'law_backfill_country_run' );
function law_backfill_country_run() {

	if ( empty( $_GET['law_backfill_country'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to run this.', 'law' ) );
	}

	$write = ! empty( $_GET['write'] );
	$clear = ! empty( $_GET['clear'] );
	$force = ! empty( $_GET['force'] );

	// Any run that changes data is nonce-checked and guarded against a repeat.
	if ( $write ) {
		check_admin_referer( LAW_BACKFILL_COUNTRY_NONCE );

		$done = get_option( LAW_BACKFILL_COUNTRY_DONE_OPT );
		if ( $done && ! $force ) {
			wp_die( sprintf(
				/* translators: %s: date the backfill last ran */
				esc_html__( 'This backfill already ran on %s. Add &force=1 to run it again.', 'law' ),
				esc_html( $done )
			) );
		}
	}

	$source  = law_backfill_country_source_values();
	$users   = get_users( array(
		'fields'  => array( 'ID', 'user_email' ),
		'orderby' => 'ID',
	) );

	$rows    = array();
	$counts  = array(
		'written'  => 0,
		'skipped'  => 0,
		'kept'     => 0,
		'cleared'  => 0,
		'nosource' => 0,
	);

	foreach ( $users as $user ) {
		$user_id = (int) $user->ID;
		$current = (string) get_user_meta( $user_id, LAW_BACKFILL_COUNTRY_META, true );
		$entry   = isset( $source[ $user_id ] ) ? (string) $source[ $user_id ] : '';
		$suspect = in_array( $current, law_backfill_country_suspect_values(), true );

		if ( '' === $entry ) {
			// Nothing to copy from: admin accounts, imports, deleted entries.
			$counts['nosource']++;

			if ( '' !== $current && $suspect ) {
				if ( $clear ) {
					if ( $write ) {
						law_backfill_country_write( $user_id, '' );
					}
					$counts['cleared']++;
					$rows[] = array( $user_id, $user->user_email, $current, '(none)', $write ? 'cleared' : 'would clear' );
					continue;
				}
				$rows[] = array( $user_id, $user->user_email, $current, '(none)', 'suspect, no source - fix by hand or use &clear=1' );
			}
			continue;
		}

		if ( $current === $entry ) {
			$counts['skipped']++;
			continue;
		}

		if ( '' !== $current && ! $suspect ) {
			// A deliberate change made after registration. Leave it.
			$counts['kept']++;
			$rows[] = array( $user_id, $user->user_email, $current, $entry, 'kept, looks deliberate' );
			continue;
		}

		if ( $write ) {
			law_backfill_country_write( $user_id, $entry );
		}
		$counts['written']++;
		$rows[] = array(
			$user_id,
			$user->user_email,
			'' === $current ? '(empty)' : $current,
			$entry,
			$write ? 'written' : 'would write',
		);
	}

	if ( $write ) {
		update_option( LAW_BACKFILL_COUNTRY_DONE_OPT, current_time( 'mysql' ) );
	}

	law_backfill_country_report( $rows, $counts, $write, $clear );
}

/**
 * Most recent active form 1 entry per user, as user ID => country.
 *
 * Matched on the entry's created_by rather than its email field: every form 1
 * entry carries created_by, and it survives a later email change on the
 * profile form, which an email match would not.
 */
function law_backfill_country_source_values() {
	global $wpdb;

	$entry      = class_exists( 'GFFormsModel' ) ? GFFormsModel::get_entry_table_name() : $wpdb->prefix . 'gf_entry';
	$entry_meta = class_exists( 'GFFormsModel' ) ? GFFormsModel::get_entry_meta_table_name() : $wpdb->prefix . 'gf_entry_meta';

	$sql = $wpdb->prepare(
		"SELECT e.created_by AS user_id, m.meta_value AS country
		 FROM {$entry} e
		 INNER JOIN {$entry_meta} m
		         ON m.entry_id = e.id AND m.form_id = %d AND m.meta_key = %s
		 INNER JOIN (
		     SELECT created_by, MAX(id) AS id
		     FROM {$entry}
		     WHERE form_id = %d AND status = 'active' AND created_by > 0
		     GROUP BY created_by
		 ) latest ON latest.id = e.id",
		LAW_BACKFILL_COUNTRY_FORM,
		LAW_BACKFILL_COUNTRY_FIELD,
		LAW_BACKFILL_COUNTRY_FORM
	);

	$out = array();
	foreach ( (array) $wpdb->get_results( $sql ) as $row ) {
		$value = trim( (string) $row->country );
		if ( '' !== $value ) {
			$out[ (int) $row->user_id ] = $value;
		}
	}

	return $out;
}

/**
 * Write one country value. Uses ACF so the `_country` reference key is set,
 * matching how mu-plugins/law-user-profile-update.php stores its fields.
 */
function law_backfill_country_write( $user_id, $value ) {
	if ( '' === $value ) {
		// Clear the value and ACF's reference key, so the field reads as empty
		// rather than as a wrong answer.
		delete_user_meta( $user_id, LAW_BACKFILL_COUNTRY_META );
		delete_user_meta( $user_id, '_' . LAW_BACKFILL_COUNTRY_META );
		return;
	}

	if ( function_exists( 'update_field' ) ) {
		update_field( LAW_BACKFILL_COUNTRY_META, $value, 'user_' . $user_id );
		return;
	}

	update_user_meta( $user_id, LAW_BACKFILL_COUNTRY_META, $value );
}

/**
 * Print the report and stop. Nothing else on this request should run.
 */
function law_backfill_country_report( $rows, $counts, $write, $clear ) {

	$lines   = array();
	$lines[] = $write ? 'Country backfill: WRITE run' : 'Country backfill: dry run, nothing was changed';
	$lines[] = str_repeat( '=', 72 );
	$lines[] = sprintf( '%-9s %s', $write ? 'written' : 'to write', $counts['written'] );
	$lines[] = sprintf( '%-9s %s', 'cleared', $counts['cleared'] );
	$lines[] = sprintf( '%-9s %s', 'correct', $counts['skipped'] );
	$lines[] = sprintf( '%-9s %s', 'kept', $counts['kept'] );
	$lines[] = sprintf( '%-9s %s', 'nosource', $counts['nosource'] );
	$lines[] = '';

	if ( $rows ) {
		$lines[] = sprintf( '%-6s %-40s %-20s %-20s %s', 'ID', 'Email', 'Stored now', 'From entry', 'Action' );
		$lines[] = str_repeat( '-', 130 );
		foreach ( $rows as $row ) {
			$lines[] = vsprintf( '%-6s %-40s %-20s %-20s %s', $row );
		}
	} else {
		$lines[] = 'Nothing to do.';
	}

	$report = esc_html( implode( "\n", $lines ) );

	$next = '';
	if ( ! $write ) {
		$url = wp_nonce_url(
			admin_url( '/?law_backfill_country=1&write=1' . ( $clear ? '&clear=1' : '' ) ),
			LAW_BACKFILL_COUNTRY_NONCE
		);
		$next = '<p><a class="button button-primary" href="' . esc_url( $url ) . '">Run for real</a>';
		if ( ! $clear ) {
			$clear_url = add_query_arg( 'clear', '1', admin_url( '/?law_backfill_country=1' ) );
			$next .= ' &nbsp; <a href="' . esc_url( $clear_url ) . '">Preview with &amp;clear=1</a>';
		}
		$next .= '</p>';
	} else {
		$next = '<p>Done. Remove functions/backfill-user-country.php and its require line from functions.php.</p>';
	}

	wp_die(
		'<pre style="font:13px/1.5 ui-monospace,monospace;white-space:pre;overflow:auto">' . $report . '</pre>' . $next,
		'Country backfill',
		array( 'response' => 200 )
	);
}
