<?php
/**
 * The Manage time slots table, for templates/account-dashboard-slots.php.
 *
 * One form, one table, one row per slot: the repeater is event-form.js's
 * (data-law-rows-group, a hidden template row, .law-row-add and
 * .law-row-remove), drawn as table rows rather than cards because the list is
 * a couple of dozen short rows the committee reads down a column at a time
 * (Denis prefers a table for list data). A row added on screen goes at the
 * bottom; the saver puts the whole list in date and time order.
 *
 * A row whose slot any event holds is locked: its label, date and times are
 * read-only and its delete control disabled, rather than hidden, with the
 * reason in the key above the table, so Retired is the only thing that can
 * change on it. The saver refuses the same changes all the same. Every row
 * posts the label it was drawn with (`original`), which is how the saver tells
 * a free slot's rename from a deletion plus an addition.
 *
 * A refused save redirects back with the rows as typed, each bad control
 * marked and described by its line in the error list, and the shared
 * failed-save dialog open over the page (law_events_form_error_modal()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Checked here too, not only by the template: the part renders an editing
// form and is reachable the moment anything else calls get_template_part().
if ( ! function_exists( 'law_user_is_committee' ) || ! law_user_is_committee() ) {
	echo '<p>' . esc_html__( 'This dashboard is for the LAW committee.', 'law' ) . '</p>';
	return;
}

$law_ts_state  = law_slots_dashboard_state();
$law_ts_errors = (array) $law_ts_state['errors'];
$law_ts_rows   = law_slots_dashboard_rows( (array) $law_ts_state['input'] );
$law_ts_notice = isset( $_GET['law_notice'] ) ? sanitize_key( wp_unslash( $_GET['law_notice'] ) ) : '';

// Each error's line in the list below gets an id, so the control it is about
// can point at it with aria-describedby.
$law_ts_error_ids = array();
foreach ( array_keys( $law_ts_errors ) as $law_ts_i => $law_ts_key ) {
	$law_ts_error_ids[ $law_ts_key ] = 'law-slots-error-' . $law_ts_i;
}

/**
 * One input's name, value and error state, for a real row or the template.
 *
 * @param array  $row   The row.
 * @param string $field label|date|start|end|original.
 * @param bool   $is_template Whether this is the hidden template row.
 */
$law_ts_attrs = function ( array $row, $field, $is_template ) use ( $law_ts_error_ids ) {
	$name = 'slots[' . ( $is_template ? '__i__' : (int) $row['index'] ) . '][' . $field . ']';
	$out  = ( $is_template ? 'data-name' : 'name' ) . '="' . esc_attr( $name ) . '"';
	if ( ! $is_template && isset( $law_ts_error_ids[ $name ] ) ) {
		$out .= ' aria-invalid="true" aria-describedby="' . esc_attr( $law_ts_error_ids[ $name ] ) . '"';
	}
	return $out;
};
$law_ts_invalid = function ( array $row, $field, $is_template ) use ( $law_ts_error_ids ) {
	return ! $is_template && isset( $law_ts_error_ids[ 'slots[' . (int) $row['index'] . '][' . $field . ']' ] ) ? ' is-invalid' : '';
};
?>

<h1 class="law-dashboard__title"><?php esc_html_e( 'Manage time slots', 'law' ); ?></h1>

<?php if ( 'slots-saved' === $law_ts_notice ) : ?>
	<div class="law-form-notice is-success" role="status"><?php esc_html_e( 'The time slots have been saved.', 'law' ); ?></div>
<?php elseif ( 'slots-denied' === $law_ts_notice ) : ?>
	<div class="law-form-notice is-error" role="alert"><?php esc_html_e( 'Sorry, managing the time slots is for the committee.', 'law' ); ?></div>
<?php elseif ( 'rate-limited' === $law_ts_notice ) : ?>
	<div class="law-form-notice is-error" role="alert"><?php esc_html_e( 'Too many changes in a short time; please wait a moment and try again.', 'law' ); ?></div>
<?php endif; ?>

<?php if ( $law_ts_errors ) : ?>
	<div class="law-form-notice is-error" role="alert">
		<?php if ( isset( $law_ts_errors['locked'] ) ) : ?>
			<p><?php echo esc_html( (string) $law_ts_errors['locked'][0] ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'The time slots were not saved:', 'law' ); ?></p>
			<ul>
				<?php foreach ( $law_ts_errors as $law_ts_key => $law_ts_messages ) : ?>
					<li id="<?php echo esc_attr( $law_ts_error_ids[ $law_ts_key ] ); ?>"><?php echo esc_html( (string) ( $law_ts_messages[0] ?? '' ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php law_events_form_error_modal( $law_ts_errors, __( 'The time slots were not saved', 'law' ) ); ?>
<?php endif; ?>

<?php
// What the two less obvious columns mean, and why some rows cannot be
// deleted, said once above the table rather than on every row.
?>
<ul class="law-slots__key">
	<li><?php esc_html_e( 'These are the dates and times hosts choose from when they submit an event.', 'law' ); ?></li>
	<li><?php echo wp_kses( __( '<strong>No end time</strong> means the slot runs from its start time onwards.', 'law' ), array( 'strong' => array() ) ); ?></li>
	<li><?php echo wp_kses( __( '<strong>Retired</strong> slots are no longer offered to hosts. Events that already hold one keep it.', 'law' ), array( 'strong' => array() ) ); ?></li>
	<li><?php esc_html_e( 'A slot that any event holds is locked: it cannot be renamed, moved or deleted, only retired. To offer a different time, retire it and add a new slot.', 'law' ); ?></li>
</ul>

<form class="law-event-form law-event-form--light law-slots-form" method="post"
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="law_slots_manage">
	<?php wp_nonce_field( 'law_slots_manage' ); ?>
	<?php law_events_honeypot_field(); ?>
	<input type="hidden" name="law_slots_version" value="<?php echo esc_attr( law_events_slots_version() ); ?>">

	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table law-slots__table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Label', 'law' ); ?> <span aria-hidden="true">*</span></th>
				<th scope="col"><?php esc_html_e( 'Date', 'law' ); ?> <span aria-hidden="true">*</span></th>
				<th scope="col"><?php esc_html_e( 'Start', 'law' ); ?> <span aria-hidden="true">*</span></th>
				<th scope="col"><?php esc_html_e( 'End', 'law' ); ?></th>
				<th scope="col" class="law-slots__retired"><?php esc_html_e( 'Retired', 'law' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Events', 'law' ); ?></th>
				<th scope="col" class="law-dashboard__row-actions"><span class="show-for-sr"><?php esc_html_e( 'Delete', 'law' ); ?></span></th>
			</tr></thead>
			<tbody data-law-rows-group="slots">
			<?php
			$law_ts_all   = $law_ts_rows;
			$law_ts_all[] = array( 'index' => 0, 'label' => '', 'date' => '', 'start' => '', 'end' => '', 'retired' => false, 'original' => '', 'confirmed' => 0, 'preferred' => 0, 'held' => 0 );
			foreach ( $law_ts_all as $law_ts_i => $law_ts_row ) :
				$law_ts_template = count( $law_ts_all ) - 1 === $law_ts_i;
				// The template row's controls carry data-law-required instead of
				// required: a hidden empty required control would stop the form
				// submitting at all. event-form.js turns it into required on the
				// copy it makes.
				$law_ts_required = $law_ts_template ? 'data-law-required' : 'required';
				// A held slot's label, date and times are read-only: an event
				// stores the label, and its datetimes were written from these.
				$law_ts_locked   = ! $law_ts_template && $law_ts_row['held'] ? ' readonly' : '';
				$law_ts_name     = '' !== (string) $law_ts_row['label'] ? (string) $law_ts_row['label'] : __( 'this slot', 'law' );
				?>
				<tr class="law-row law-slots__row" <?php echo $law_ts_template ? 'data-law-row-template hidden' : ''; ?>>
					<td>
						<label class="law-slots__field<?php echo esc_attr( $law_ts_invalid( $law_ts_row, 'label', $law_ts_template ) ); ?>">
							<span class="show-for-sr"><?php esc_html_e( 'Label', 'law' ); ?></span>
							<input type="text" <?php echo $law_ts_attrs( $law_ts_row, 'label', $law_ts_template ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure. ?> <?php echo esc_attr( $law_ts_required ); ?><?php echo esc_attr( $law_ts_locked ); ?>
								value="<?php echo esc_attr( (string) $law_ts_row['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Tue 1st Dec: 08:30–10:00', 'law' ); ?>">
						</label>
					</td>
					<td>
						<label class="law-slots__field<?php echo esc_attr( $law_ts_invalid( $law_ts_row, 'date', $law_ts_template ) ); ?>">
							<span class="show-for-sr"><?php esc_html_e( 'Date', 'law' ); ?></span>
							<input type="date" <?php echo $law_ts_attrs( $law_ts_row, 'date', $law_ts_template ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure. ?> <?php echo esc_attr( $law_ts_required ); ?><?php echo esc_attr( $law_ts_locked ); ?>
								value="<?php echo esc_attr( (string) $law_ts_row['date'] ); ?>">
						</label>
					</td>
					<td>
						<label class="law-slots__field<?php echo esc_attr( $law_ts_invalid( $law_ts_row, 'start', $law_ts_template ) ); ?>">
							<span class="show-for-sr"><?php esc_html_e( 'Start time', 'law' ); ?></span>
							<input type="time" <?php echo $law_ts_attrs( $law_ts_row, 'start', $law_ts_template ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure. ?> <?php echo esc_attr( $law_ts_required ); ?><?php echo esc_attr( $law_ts_locked ); ?>
								value="<?php echo esc_attr( (string) $law_ts_row['start'] ); ?>">
						</label>
					</td>
					<td>
						<label class="law-slots__field<?php echo esc_attr( $law_ts_invalid( $law_ts_row, 'end', $law_ts_template ) ); ?>">
							<span class="show-for-sr"><?php esc_html_e( 'End time (empty means onwards)', 'law' ); ?></span>
							<input type="time" <?php echo $law_ts_attrs( $law_ts_row, 'end', $law_ts_template ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure. ?><?php echo esc_attr( $law_ts_locked ); ?>
								value="<?php echo esc_attr( (string) $law_ts_row['end'] ); ?>">
						</label>
					</td>
					<td class="law-slots__retired">
						<label class="law-slots__check">
							<span class="show-for-sr"><?php esc_html_e( 'Retired', 'law' ); ?></span>
							<input type="checkbox" <?php echo $law_ts_attrs( $law_ts_row, 'retired', $law_ts_template ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure. ?> value="1" <?php checked( ! empty( $law_ts_row['retired'] ) ); ?>>
						</label>
					</td>
					<td class="law-slots__usage">
						<?php if ( $law_ts_row['confirmed'] || $law_ts_row['preferred'] ) : ?>
							<?php if ( $law_ts_row['confirmed'] ) : ?>
								<?php /* translators: %d: number of events with this confirmed slot. */ ?>
								<span><?php echo esc_html( sprintf( __( '%d confirmed', 'law' ), (int) $law_ts_row['confirmed'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( $law_ts_row['preferred'] ) : ?>
								<?php /* translators: %d: number of events listing this slot as a preference. */ ?>
								<span><?php echo esc_html( sprintf( __( '%d preferred', 'law' ), (int) $law_ts_row['preferred'] ) ); ?></span>
							<?php endif; ?>
						<?php else : ?>
							<span aria-hidden="true">&mdash;</span><span class="show-for-sr"><?php esc_html_e( 'None', 'law' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="law-dashboard__row-actions">
						<?php
						// After the visible controls, so event-form.js focuses the
						// label on a new row rather than this hidden input.
						?>
						<input type="hidden" <?php echo $law_ts_attrs( $law_ts_row, 'original', $law_ts_template ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure. ?> value="<?php echo esc_attr( (string) $law_ts_row['original'] ); ?>">
						<?php if ( $law_ts_row['held'] ) : ?>
							<button type="button" class="law-row-remove" disabled
								aria-label="<?php echo esc_attr( sprintf( /* translators: 1: slot label, 2: number of events. */ _n( '%1$s cannot be deleted: %2$d event holds it', '%1$s cannot be deleted: %2$d events hold it', (int) $law_ts_row['held'], 'law' ), $law_ts_name, (int) $law_ts_row['held'] ) ); ?>"
								title="<?php echo esc_attr( sprintf( /* translators: %d: number of events. */ _n( 'Held by %d event: retire it instead', 'Held by %d events: retire it instead', (int) $law_ts_row['held'], 'law' ), (int) $law_ts_row['held'] ) ); ?>">&times;</button>
						<?php else : ?>
							<button type="button" class="law-row-remove"
								aria-label="<?php echo esc_attr( sprintf( /* translators: %s: slot label. */ __( 'Delete %s', 'law' ), $law_ts_name ) ); ?>"
								title="<?php esc_attr_e( 'Delete', 'law' ); ?>">&times;</button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<?php if ( ! $law_ts_rows ) : ?>
		<p class="law-cal__empty"><?php esc_html_e( 'No time slots yet. Add the first one below.', 'law' ); ?></p>
	<?php endif; ?>

	<p class="law-slots__add">
		<button type="button" class="button law-row-add" data-law-add="slots"><?php esc_html_e( 'Add a slot', 'law' ); ?></button>
	</p>

	<p class="law-form-buttons">
		<button type="submit" class="button orange"><?php esc_html_e( 'Save time slots', 'law' ); ?></button>
	</p>
</form>
