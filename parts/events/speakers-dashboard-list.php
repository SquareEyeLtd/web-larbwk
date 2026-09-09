<?php
/**
 * The Manage Speakers list (or empty message) for
 * templates/account-speakers-dashboard.php. Rendered inside #law-cal-events and
 * returned on its own by the &law_partial=1 endpoint
 * (functions/events/speakers-dashboard.php), so filtering swaps it in place.
 *
 * A flat table, one row per speaker record, the committee dashboard's idiom.
 * Read-only: every change is made in the edit view the Edit link opens, so
 * there is one place where an appearance is written.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_sd_filters = law_speakers_dashboard_filters();
$law_sd_data    = law_speakers_dashboard_rows( $law_sd_filters );
$law_sd_rows    = $law_sd_data['rows'];
?>

<?php if ( ! $law_sd_rows ) : ?>
	<p class="law-cal__empty"><?php esc_html_e( 'No speakers match.', 'law' ); ?></p>
<?php else : ?>
	<p class="law-speakers-dashboard__summary">
		<?php
		// Two plurals, two _n() calls: one selector cannot serve nouns whose
		// counts diverge. A speaker appears at as many events as they appear at.
		echo esc_html( sprintf(
			/* translators: 1: "N speaker(s)", 2: "N appearance(s)". */
			__( '%1$s, %2$s.', 'law' ),
			sprintf( _n( '%s speaker', '%s speakers', $law_sd_data['speakers'], 'law' ), number_format_i18n( $law_sd_data['speakers'] ) ),
			sprintf( _n( '%s event appearance', '%s event appearances', $law_sd_data['appearances'], 'law' ), number_format_i18n( $law_sd_data['appearances'] ) )
		) );
		?>
	</p>
	<?php if ( $law_sd_data['truncated'] ) : ?>
		<p class="law-speakers-dashboard__truncated" role="status"><?php echo esc_html( sprintf( __( 'Showing the first %s speakers. Narrow the filters, or use an export for the full set.', 'law' ), number_format_i18n( LAW_SPEAKERS_DASHBOARD_SCREEN_CAP ) ) ); ?></p>
	<?php endif; ?>

	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table law-speakers-table">
			<thead><tr>
				<th><span class="show-for-sr"><?php esc_html_e( 'Photo', 'law' ); ?></span></th>
				<th><?php esc_html_e( 'Speaker', 'law' ); ?></th>
				<th><?php esc_html_e( 'Organisation', 'law' ); ?></th>
				<th><?php esc_html_e( 'Job title', 'law' ); ?></th>
				<th><?php esc_html_e( 'Appears at', 'law' ); ?></th>
				<th><?php esc_html_e( 'Website', 'law' ); ?></th>
				<th><span class="show-for-sr"><?php esc_html_e( 'Actions', 'law' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $law_sd_rows as $law_sd_row ) :
				$law_sd_edit = law_speakers_dashboard_url( $law_sd_row['id'] );
				?>
				<tr>
					<td class="law-speakers-table__photo">
						<?php if ( '' !== $law_sd_row['photo'] ) : ?>
							<img src="<?php echo esc_url( $law_sd_row['photo'] ); ?>" alt="" width="40" height="40" loading="lazy">
						<?php else : ?>
							<?php // The theme's shared two-letter placeholder (functions/calendar.php), the same one the event cards and the bio dialog use. ?>
							<span class="law-speakers-table__initials" aria-hidden="true"><?php echo esc_html( law_calendar_name_initials( $law_sd_row['name'] ) ); ?></span>
						<?php endif; ?>
					</td>
					<td class="law-speakers-table__name">
						<strong><a href="<?php echo esc_url( $law_sd_edit ); ?>"><?php echo esc_html( $law_sd_row['name'] ); ?></a></strong>
						<?php if ( '' !== $law_sd_row['email'] ) : ?>
							<br><small><?php echo esc_html( $law_sd_row['email'] ); ?></small>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $law_sd_row['organisation'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $law_sd_row['job_title'] ?: '—' ); ?></td>
					<td class="law-speakers-table__events">
						<?php if ( ! $law_sd_row['appearances'] ) : ?>
							<?php esc_html_e( 'No events', 'law' ); ?>
						<?php
						else :
							// The status goes on anything but a Confirmed event, as in the
							// event filter: drafts and re-submissions show up here, and two
							// of them can share a title, which read as a duplicate without it.
							$law_sd_titles = array();
							foreach ( $law_sd_row['appearances'] as $law_sd_appearance ) {
								$law_sd_titles[] = 'publish' === $law_sd_appearance['event_status']
									? $law_sd_appearance['event_title']
									: $law_sd_appearance['event_title'] . ' (' . law_event_status_label( $law_sd_appearance['event_status'] ) . ')';
							}
							?>
							<strong><?php echo esc_html( number_format_i18n( count( $law_sd_row['appearances'] ) ) ); ?></strong>
							<br><small><?php echo esc_html( implode( ', ', $law_sd_titles ) ); ?></small>
						<?php endif; ?>
					</td>
					<td class="law-speakers-table__website">
						<?php if ( '' !== $law_sd_row['website'] ) : ?>
							<a href="<?php echo esc_url( $law_sd_row['website'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Profile', 'law' ); ?></a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td class="law-speakers-table__actions">
						<a class="button second" href="<?php echo esc_url( $law_sd_edit ); ?>"><?php esc_html_e( 'Edit', 'law' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
