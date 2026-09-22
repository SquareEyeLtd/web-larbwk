<?php
/**
 * Diagnostic: confirmed slots in Gravity Forms against confirmed slots in the
 * events module.
 *
 * URL trigger: /wp-admin/?test-events-confirmed-slots (administrators only).
 *
 * The confirmed slot is the one migrated value that nothing downstream can
 * recompute. Everything the programme shows about WHEN an event happens comes
 * from it: law_migration_populate_event() writes form 2 (Event > submit an
 * event) field 68 (Confirmed slot) into `_law_slot_label`, and parses the same
 * string into `_law_start` and `_law_end`. So a slot that failed to carry
 * across does not show up as an error anywhere — the event simply reads as
 * "Slot not confirmed" and drops out of the day grid, which looks exactly like
 * an event the committee has not scheduled yet.
 *
 * This screen is the check for that, and it reports DIFFERENCES ONLY: an event
 * whose two copies agree is not listed at all, so an empty table is the
 * all-clear. Four kinds of difference are reported, and they are deliberately
 * kept apart because they have different causes and different fixes:
 *
 * 1. Set in Gravity Forms, not in the module. The migration missed it, or the
 *    module's copy was cleared afterwards. This is the case that prompted the
 *    screen.
 * 2. Set in the module, not in Gravity Forms. The committee confirmed the slot
 *    in the module after cutover (the normal, expected case for anything
 *    scheduled since), or the legacy entry was edited to clear it.
 * 3. Both set, and different. One side was changed after the other.
 * 4. The labels agree but the dates derived from them do not. `_law_start` /
 *    `_law_end` are what the programme actually sorts and groups by, so a
 *    correct label over stale datetimes still renders wrongly.
 *
 * Punctuation is NOT a difference. Field 68 (Confirmed slot) carries en dashes
 * where field 77 (Preferred date & time slots) and some stored labels carry
 * plain hyphens, so both sides are compared through
 * law_events_slot_label_key(), the same normaliser the migration and the
 * settings list use. Comparing the raw strings would report all ninety-odd
 * events as mismatched and hide the handful that genuinely are.
 *
 * Only form 2 (Event > submit an event) is read. Receptions, the flagship and
 * external events (form 10, Event > external events) either never had a
 * legacy confirmed slot or deliberately hold an empty `_law_slot_label`
 * (law_event_apply_slot_label() is never called for them, and their datetimes
 * come from sessions instead), so including them would report a difference on
 * every one of them and mean nothing.
 *
 * The screen only reads. Nothing here writes, so it is safe to open on
 * production as often as it is useful.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every migrated law_event indexed by the legacy entry ID it carries.
 *
 * Built from `_law_gf_entry_id` in one pass rather than resolving each entry
 * with law_events_resolve_event_post_id(), for two reasons. It is one query
 * instead of a hundred, and that resolver treats a numeric argument that
 * happens to BE a law_event post ID as the post itself — harmless where it is
 * called with a post ID, wrong here, where every argument is an entry ID and a
 * collision would silently compare an event against the wrong entry.
 *
 * The entry-map option (`law_events_entry_map`) is consulted second, for an
 * event whose meta is missing but whose migration mapping survives. The meta
 * wins on a disagreement: it lives on the post and the option is a single
 * serialized blob that a partial re-run can leave stale.
 *
 * @return array<int,int> Entry ID => post ID.
 */
function law_slot_audit_event_index() {
	global $wpdb;

	$index = array();

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.meta_value AS entry_id, pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND p.post_type = %s",
			'_law_gf_entry_id',
			LAW_EVENT_CPT
		)
	);
	foreach ( (array) $rows as $row ) {
		$entry_id = (int) $row->entry_id;
		if ( $entry_id > 0 ) {
			$index[ $entry_id ] = (int) $row->post_id;
		}
	}

	$map = get_option( 'law_events_entry_map', array() );
	foreach ( (array) ( $map['events'] ?? array() ) as $entry_id => $post_id ) {
		$entry_id = (int) $entry_id;
		$post_id  = (int) $post_id;
		if ( $entry_id < 1 || isset( $index[ $entry_id ] ) ) {
			continue;
		}
		if ( $post_id && get_post_type( $post_id ) === LAW_EVENT_CPT ) {
			$index[ $entry_id ] = $post_id;
		}
	}

	return $index;
}

/**
 * The datetimes a slot label means, as the migration derives them.
 *
 * law_calendar_parse_slot() is the single parser both sides already use, so
 * the audit cannot disagree with the migration about what a label means.
 *
 * @param string $label Slot label.
 * @return array{start:string,end:string} Empty strings when the label is empty
 *                                        or unparseable.
 */
function law_slot_audit_derive_dates( $label ) {
	$label = trim( (string) $label );
	if ( '' === $label ) {
		return array( 'start' => '', 'end' => '' );
	}

	$slot = law_calendar_parse_slot( $label );
	if ( ! is_array( $slot ) || '' === (string) $slot['date'] ) {
		return array( 'start' => '', 'end' => '' );
	}

	return array(
		'start' => $slot['date'] . ' ' . $slot['start'],
		'end'   => $slot['end'] ? $slot['date'] . ' ' . $slot['end'] : '',
	);
}

/**
 * The comparison.
 *
 * @return array{
 *     differences: array<int,array>,
 *     unmigrated: array<int,array>,
 *     orphans: array<int,array>,
 *     checked: int,
 *     agreed: int
 * }
 */
function law_slot_audit_report() {
	$index   = law_slot_audit_event_index();
	$seen    = array();
	$rows    = array();
	$missing = array();
	$checked = 0;
	$agreed  = 0;

	// Entries are read in pages: LAW_MIGRATION_ENTRY_PAGE_SIZE is what the
	// migration itself reads in one go, and form 2 (Event > submit an event)
	// will outgrow a single page eventually.
	$offset = 0;
	do {
		$entries = law_migration_entries( 2, $offset, LAW_MIGRATION_ENTRY_PAGE_SIZE );
		foreach ( $entries as $entry ) {
			$entry_id = (int) rgar( $entry, 'id' );
			$post_id  = (int) ( $index[ $entry_id ] ?? 0 );
			$title    = trim( (string) rgar( $entry, '17' ) );

			if ( ! $post_id ) {
				// No copy in the module at all. Reported on its own: it is a
				// migration gap, not a slot disagreement, and a slot column
				// would have nothing to say about it.
				$missing[] = array(
					'entry_id'     => $entry_id,
					'entry_title'  => $title,
					'entry_status' => trim( (string) rgar( $entry, '95' ) ),
					'gf_label'     => trim( (string) rgar( $entry, '68' ) ),
				);
				continue;
			}

			$seen[ $post_id ] = true;
			$checked++;

			$gf_label  = trim( (string) rgar( $entry, '68' ) );
			$cpt_label = trim( (string) law_event_meta( $post_id, '_law_slot_label' ) );

			$gf_key  = law_events_slot_label_key( $gf_label );
			$cpt_key = law_events_slot_label_key( $cpt_label );

			$gf_dates  = law_slot_audit_derive_dates( $gf_label );
			$cpt_start = trim( (string) law_event_meta( $post_id, '_law_start' ) );
			$cpt_end   = trim( (string) law_event_meta( $post_id, '_law_end' ) );

			if ( '' === $gf_key && '' === $cpt_key ) {
				// Neither side has a confirmed slot. That is agreement, not a
				// difference: the event is simply unscheduled on both sides.
				$agreed++;
				continue;
			}

			if ( $gf_key === $cpt_key ) {
				if ( $gf_dates['start'] === $cpt_start && $gf_dates['end'] === $cpt_end ) {
					$agreed++;
					continue;
				}
				$issue = 'dates';
			} elseif ( '' === $cpt_key ) {
				$issue = 'missing-in-module';
			} elseif ( '' === $gf_key ) {
				$issue = 'missing-in-gf';
			} else {
				$issue = 'different';
			}

			$rows[] = array(
				'issue'        => $issue,
				'entry_id'     => $entry_id,
				'entry_title'  => $title,
				'entry_status' => trim( (string) rgar( $entry, '95' ) ),
				'gf_label'     => $gf_label,
				'gf_start'     => $gf_dates['start'],
				'gf_end'       => $gf_dates['end'],
				'post_id'      => $post_id,
				'post_title'   => get_the_title( $post_id ),
				'post_status'  => get_post_status( $post_id ),
				'cpt_label'    => $cpt_label,
				'cpt_start'    => $cpt_start,
				'cpt_end'      => $cpt_end,
			);
		}
		$offset += LAW_MIGRATION_ENTRY_PAGE_SIZE;
	} while ( count( $entries ) === LAW_MIGRATION_ENTRY_PAGE_SIZE );

	// The other direction: a module event that claims a legacy entry no form 2
	// (Event > submit an event) entry list will admit to. Usually the entry was
	// trashed after migration, which is fine and expected; it is here so that a
	// reader never has to wonder whether the two lists above cover every event
	// that has a legacy copy.
	//
	// External events, receptions and the flagship are dropped from it rather
	// than listed. `_law_gf_entry_id` on an external event names a form 10
	// (Event > external events) entry, which this screen never reads, so every
	// one of them would appear here under a heading saying its form 2 entry had
	// vanished — a sentence that is false about an event that never had one.
	$orphans = array();
	foreach ( $index as $entry_id => $post_id ) {
		if ( isset( $seen[ $post_id ] ) ) {
			continue;
		}
		if ( law_event_is_external( $post_id ) || law_reception_is( $post_id ) || law_flagship_is( $post_id ) ) {
			continue;
		}
		$orphans[] = array(
			'entry_id'    => (int) $entry_id,
			'post_id'     => (int) $post_id,
			'post_title'  => get_the_title( $post_id ),
			'post_status' => get_post_status( $post_id ),
			'cpt_label'   => trim( (string) law_event_meta( $post_id, '_law_slot_label' ) ),
		);
	}

	// Worst first, then by entry ID, so the cases that need a decision are at
	// the top of the table rather than scattered through ninety-nine rows.
	$order = array( 'missing-in-module' => 0, 'different' => 1, 'dates' => 2, 'missing-in-gf' => 3 );
	usort( $rows, function ( $a, $b ) use ( $order ) {
		$rank = ( $order[ $a['issue'] ] ?? 9 ) <=> ( $order[ $b['issue'] ] ?? 9 );
		return $rank ?: ( $a['entry_id'] <=> $b['entry_id'] );
	} );

	return array(
		'differences' => $rows,
		'unmigrated'  => $missing,
		'orphans'     => $orphans,
		'checked'     => $checked,
		'agreed'      => $agreed,
	);
}

/** The human label for one difference kind. */
function law_slot_audit_issue_label( $issue ) {
	$labels = array(
		'missing-in-module' => 'Set in Gravity Forms, missing in the module',
		'missing-in-gf'     => 'Set in the module, missing in Gravity Forms',
		'different'         => 'Different slot on each side',
		'dates'             => 'Same slot, different stored dates',
	);
	return $labels[ $issue ] ?? $issue;
}

/**
 * The wp-admin edit URL for one event.
 *
 * Built rather than taken from get_edit_post_link(), which returns an empty
 * string whenever the current context cannot edit the post — leaving a linkless
 * row exactly where the reader most wants to click through. This screen is
 * already gated on manage_options, so the link is always the right one to
 * offer.
 *
 * @param int $post_id Event post ID.
 * @return string
 */
function law_slot_audit_edit_url( $post_id ) {
	return admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
}

/** A value, or a dash that reads as "nothing here" rather than as an empty cell. */
function law_slot_audit_value( $value ) {
	$value = trim( (string) $value );
	return '' === $value ? '<span class="law-slot-audit__empty">not set</span>' : esc_html( $value );
}

/** The whole page body. */
function law_slot_audit_render( array $report ) {
	$entry_base = admin_url( 'admin.php?page=gf_entries&view=entry&id=2&lid=' );

	ob_start();
	?>
	<style>
		body#error-page { max-width: none; margin: 2em 3em; padding: 0; }
		.law-slot-audit h1 { font-size: 23px; font-weight: 400; margin: 0 0 6px; }
		.law-slot-audit h2 { font-size: 15px; margin: 32px 0 8px; }
		.law-slot-audit p { font-size: 13px; max-width: 46em; }
		.law-slot-audit table { border-collapse: collapse; width: 100%; background: #fff; font-size: 13px; }
		.law-slot-audit th, .law-slot-audit td { border: 1px solid #dcdcde; padding: 6px 8px; text-align: left; vertical-align: top; }
		.law-slot-audit th { background: #f6f7f7; font-weight: 600; }
		.law-slot-audit__empty { color: #b32d2e; font-style: italic; }
		.law-slot-audit__dates { color: #646970; display: block; font-size: 12px; }
		.law-slot-audit__ok { background: #edfaef; border: 1px solid #c3e6cb; padding: 10px 12px; }
	</style>
	<div class="law-slot-audit">
		<h1>Confirmed slots: Gravity Forms against the events module</h1>
		<p>
			Every active entry on form 2 (Event &gt; submit an event) is compared with its
			migrated <code>law_event</code> post: field 68 (Confirmed slot) against
			<code>_law_slot_label</code>, and the dates that label means against
			<code>_law_start</code> / <code>_law_end</code>. Only differences are listed.
			Punctuation differences (en dash against hyphen) are not differences.
			<?php echo esc_html( sprintf(
				'%d events were compared, %d agree.',
				(int) $report['checked'],
				(int) $report['agreed']
			) ); ?>
		</p>

		<h2><?php echo esc_html( sprintf( 'Differences (%d)', count( $report['differences'] ) ) ); ?></h2>
		<?php if ( ! $report['differences'] ) : ?>
			<p class="law-slot-audit__ok">No differences. Every compared event holds the same confirmed slot on both sides.</p>
		<?php else : ?>
			<table>
				<thead>
					<tr>
						<th>Difference</th>
						<th>Entry</th>
						<th>Event</th>
						<th>Gravity Forms: field 68 (Confirmed slot)</th>
						<th>Module: <code>_law_slot_label</code></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $report['differences'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( law_slot_audit_issue_label( $row['issue'] ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( $entry_base . $row['entry_id'] ); ?>">#<?php echo (int) $row['entry_id']; ?></a><br>
							<span class="law-slot-audit__dates"><?php echo esc_html( $row['entry_status'] ?: 'no status' ); ?></span>
						</td>
						<td>
							<a href="<?php echo esc_url( law_slot_audit_edit_url( $row['post_id'] ) ); ?>"><?php echo esc_html( $row['post_title'] ?: $row['entry_title'] ); ?></a><br>
							<span class="law-slot-audit__dates">post <?php echo (int) $row['post_id']; ?> · <?php echo esc_html( $row['post_status'] ); ?></span>
						</td>
						<td>
							<?php echo law_slot_audit_value( $row['gf_label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="law-slot-audit__dates"><?php echo esc_html( ( $row['gf_start'] ?: '—' ) . ' → ' . ( $row['gf_end'] ?: '—' ) ); ?></span>
						</td>
						<td>
							<?php echo law_slot_audit_value( $row['cpt_label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="law-slot-audit__dates"><?php echo esc_html( ( $row['cpt_start'] ?: '—' ) . ' → ' . ( $row['cpt_end'] ?: '—' ) ); ?></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $report['unmigrated'] ) : ?>
			<h2><?php echo esc_html( sprintf( 'Entries with no copy in the module (%d)', count( $report['unmigrated'] ) ) ); ?></h2>
			<p>These form 2 (Event &gt; submit an event) entries carry no matching <code>law_event</code> post, so there is nothing to compare their confirmed slot against.</p>
			<table>
				<thead>
					<tr>
						<th>Entry</th>
						<th>Field 17 (Event title)</th>
						<th>Field 95 (Event status)</th>
						<th>Field 68 (Confirmed slot)</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $report['unmigrated'] as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $entry_base . $row['entry_id'] ); ?>">#<?php echo (int) $row['entry_id']; ?></a></td>
						<td><?php echo esc_html( $row['entry_title'] ?: '(no title)' ); ?></td>
						<td><?php echo esc_html( $row['entry_status'] ?: '—' ); ?></td>
						<td><?php echo law_slot_audit_value( $row['gf_label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $report['orphans'] ) : ?>
			<h2><?php echo esc_html( sprintf( 'Module events whose entry is gone (%d)', count( $report['orphans'] ) ) ); ?></h2>
			<p>These hosted <code>law_event</code> posts name a form 2 (Event &gt; submit an event) entry that is no longer in the active entry list, usually because it was trashed after the migration. Nothing to compare, listed so the counts above add up. Receptions, the flagship and external events (form 10, Event &gt; external events) are not included.</p>
			<table>
				<thead>
					<tr>
						<th>Entry</th>
						<th>Event</th>
						<th>Module: <code>_law_slot_label</code></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $report['orphans'] as $row ) : ?>
					<tr>
						<td>#<?php echo (int) $row['entry_id']; ?></td>
						<td>
							<a href="<?php echo esc_url( law_slot_audit_edit_url( $row['post_id'] ) ); ?>"><?php echo esc_html( $row['post_title'] ); ?></a><br>
							<span class="law-slot-audit__dates">post <?php echo (int) $row['post_id']; ?> · <?php echo esc_html( $row['post_status'] ); ?></span>
						</td>
						<td><?php echo law_slot_audit_value( $row['cpt_label'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<p style="margin-top:28px"><a href="<?php echo esc_url( admin_url() ); ?>">Back to the dashboard</a></p>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * URL trigger: /wp-admin/?test-events-confirmed-slots (administrators only).
 *
 * A URL rather than a menu item, like the other one-off migration checks: it
 * is a diagnostic for whoever is running the cutover, not a screen the
 * committee should meet.
 */
add_action( 'admin_init', function () {
	if ( ! isset( $_GET['test-events-confirmed-slots'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You need to be an administrator to run this.', 'Confirmed slot comparison', 403 );
	}
	if ( ! class_exists( 'GFAPI' ) ) {
		wp_die( 'Gravity Forms is not active, so there is nothing to compare against.', 'Confirmed slot comparison', array( 'response' => 200 ) );
	}

	wp_die(
		law_slot_audit_render( law_slot_audit_report() ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'Confirmed slot comparison',
		array( 'response' => 200 )
	);
} );
