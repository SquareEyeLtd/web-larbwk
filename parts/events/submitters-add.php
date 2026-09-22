<?php
/**
 * The Add a submitter control for templates/account-dashboard-submitters.php:
 * a button above the table that opens a dialog (Denis, 11 September 2026 —
 * an add form never sits open at the foot of a list), holding a people picker
 * that searches accounts by name or email over AJAX.
 *
 * The opener ships hidden and law-modal.js reveals it once the dialog it names
 * is on the page, so a browser without JavaScript is never shown a button that
 * cannot open anything: it gets the <noscript> disclosure at the foot instead,
 * which holds the same form with the same field names. The field is a real
 * email input in both, and the handler resolves a typed address, so the no-JS
 * path is a working route rather than a courtesy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_sa_modal = 'law-submitter-add';

/**
 * The hidden inputs both copies of the form carry.
 */
$law_sa_hidden = function () {
	?>
	<input type="hidden" name="action" value="law_submitter_add">
	<?php wp_nonce_field( 'law_submitter_add' ); ?>
	<?php law_events_honeypot_field(); ?>
	<?php
};
?>

<?php // .law-dashboard__actions, the same wrapper the discount catalogue's Add button uses, so the control sits inline and this screen does not invent a second look for the same thing. ?>
<p class="law-dashboard__actions">
	<button type="button" class="button orange"
		data-law-modal-open="<?php echo esc_attr( $law_sa_modal ); ?>"
		data-law-modal-enhanced hidden>
		<?php esc_html_e( 'Add a submitter', 'law' ); ?>
	</button>
</p>

<div class="law-modal" id="<?php echo esc_attr( $law_sa_modal ); ?>" hidden>
	<div class="law-modal__overlay" data-law-modal-close></div>
	<div class="law-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $law_sa_modal ); ?>-title" tabindex="-1">
		<button type="button" class="law-modal__close" data-law-modal-close aria-label="<?php esc_attr_e( 'Close', 'law' ); ?>">&times;</button>
		<h2 class="law-modal__title" id="<?php echo esc_attr( $law_sa_modal ); ?>-title"><?php esc_html_e( 'Add a submitter', 'law' ); ?></h2>

		<form class="law-event-form law-event-form--light law-booking-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $law_sa_hidden(); ?>
			<p class="law-modal__copy"><?php esc_html_e( 'Search for somebody who already has an account. They keep everything they can do today and gain the ability to propose an event.', 'law' ); ?></p>

			<div class="law-submitter-search" data-law-submitter-search>
				<?php
				// data-law-modal-field, so law-modal.js enables and focuses this
				// input when the dialog opens and disables it again on close.
				// That is also why it ships disabled: a page carrying several
				// dialogs must post one value per field name, and the cursor
				// should be in the search box the moment the dialog appears.
				//
				// role="combobox" with aria-expanded and aria-activedescendant:
				// focus never leaves the input, and the active option is
				// announced from there. autocomplete="off" because a browser's
				// own address suggestions would cover the live results.
				?>
				<?php
				// A <div>, not the usual <p>, for one structural reason: the
				// results list is a <ul> and has to live INSIDE this wrapper so
				// it can be positioned against it (top: 100%), which a <p>
				// cannot legally contain. Anchored to the field rather than to
				// the whole picker, the list opens below the help line instead
				// of on top of it.
				?>
				<div class="law-form-field law-submitter-search__field">
					<label for="law-submitter-input"><?php esc_html_e( 'Search by name or email address', 'law' ); ?></label>
					<input type="text" id="law-submitter-input" name="law_submitter_email"
						class="law-submitter-search__input"
						role="combobox" aria-expanded="false" aria-autocomplete="list"
						aria-controls="law-submitter-results" autocomplete="off"
						placeholder="<?php esc_attr_e( 'Start typing a name or email…', 'law' ); ?>"
						data-law-modal-field data-law-submitter-input disabled>
					<span class="law-form-help"><?php esc_html_e( 'Only people who have registered can be added.', 'law' ); ?></span>

					<ul class="law-submitter-search__results" id="law-submitter-results" role="listbox"
						aria-label="<?php esc_attr_e( 'Matching accounts', 'law' ); ?>"
						data-law-submitter-results hidden></ul>
				</div>

				<?php // The live region the script writes result counts into, for a screen reader that cannot see the list appear. ?>
				<p class="show-for-sr" role="status" aria-live="polite" data-law-submitter-status></p>

				<?php
				// Who is about to be promoted, in words, above the button that
				// does it. The picker fills a hidden ID and the field shows an
				// email; without this line the difference between "typed" and
				// "chosen" would be invisible at the moment it matters.
				?>
				<p class="law-submitter-search__chosen" data-law-submitter-chosen hidden>
					<?php esc_html_e( 'Adding:', 'law' ); ?>
					<strong data-law-submitter-chosen-name></strong>
					<button type="button" class="button-link law-submitter-search__clear" data-law-submitter-clear><?php esc_html_e( 'Change', 'law' ); ?></button>
				</p>

				<input type="hidden" name="law_submitter_id" value="" data-law-submitter-id>
			</div>

			<p class="law-modal__actions">
				<button type="button" class="button second" data-law-modal-close><?php esc_html_e( 'Cancel', 'law' ); ?></button>
				<button type="submit" class="button orange" data-law-submitter-submit
					data-law-modal-busy="<?php esc_attr_e( 'Adding…', 'law' ); ?>"><?php esc_html_e( 'Add submitter', 'law' ); ?></button>
			</p>
		</form>
	</div>
</div>

<noscript>
	<details class="law-submitters__fallback">
		<summary><?php esc_html_e( 'Add a submitter', 'law' ); ?></summary>
		<p class="law-booking-note"><?php esc_html_e( 'Enter the email address of somebody who already has an account. They keep everything they can do today and gain the ability to propose an event.', 'law' ); ?></p>
		<form class="law-event-form law-event-form--light" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $law_sa_hidden(); ?>
			<p class="law-form-field">
				<label for="law-submitter-input-nojs"><?php esc_html_e( 'Email address', 'law' ); ?></label>
				<input type="email" id="law-submitter-input-nojs" name="law_submitter_email" autocomplete="off">
			</p>
			<p class="law-modal__actions law-booking-actions-start">
				<button type="submit" class="button orange"><?php esc_html_e( 'Add submitter', 'law' ); ?></button>
			</p>
		</form>
	</details>
</noscript>
