<?php
/**
 * Template Name: Manage emails (committee)
 *
 * The committee's front-end copy of the wp-admin Emails screen
 * (/account/dashboard/emails/, functions/events/emails-dashboard.php): every
 * events-module notification in one table, and an editor at
 * ?law_email=<slug>. Restrict the page with Members, as the other committee
 * dashboards are (the setup helper copies the parent's restriction).
 */

// Committee-only in every branch, so nothing in front of PHP may key a copy of
// the HTML on the URL alone.
nocache_headers();

get_header();

$law_can      = law_user_is_committee();
$law_asked    = $law_can ? law_emails_dashboard_requested() : '';
$law_notice   = isset( $_GET['law_notice'] ) ? sanitize_key( wp_unslash( $_GET['law_notice'] ) ) : '';
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container law-dashboard law-emails-dashboard">

	<?php if ( ! $law_can ) : ?>
		<p><?php esc_html_e( 'This dashboard is for the LAW committee.', 'law' ); ?></p>

	<?php else : ?>

		<?php
		$law_notices = array(
			'email-saved'       => array( 'is-success', __( 'The notification has been saved.', 'law' ) ),
			'email-reset'       => array( 'is-success', __( 'The notification has been returned to its standard wording.', 'law' ) ),
			'email-tested'      => array( 'is-success', __( 'A test has been sent to your own email address. Nothing was saved: use Save changes to keep this wording.', 'law' ) ),
			// Test mode was on, so "sent to you" would not have been true.
			'email-tested-diverted' => array( 'is-warning', __( 'Test mode is on, so the test went to the test address rather than to you. Nothing was saved: use Save changes to keep this wording.', 'law' ) ),
			'email-recipients'  => array( 'is-error', __( 'None of those recipients is a valid email address, so nothing was saved. Separate addresses with commas, or untick "Send this notification" to stop it being sent at all.', 'law' ) ),
			'email-empty-body'  => array( 'is-error', __( 'The message is empty, so nothing was saved. To stop this notification being sent, untick "Send this notification" instead.', 'law' ) ),
			'email-test-failed' => array( 'is-error', __( 'The test email could not be sent. Please try again.', 'law' ) ),
			'email-missing'     => array( 'is-error', __( 'That notification could not be found.', 'law' ) ),
			'email-denied'      => array( 'is-error', __( 'Sorry, managing the events emails is for the committee.', 'law' ) ),
			'rate-limited'      => array( 'is-error', __( 'Too many changes in a short time; please wait a moment and try again.', 'law' ) ),
		);
		?>
		<?php if ( isset( $law_notices[ $law_notice ] ) ) : ?>
			<p class="law-form-notice <?php echo esc_attr( $law_notices[ $law_notice ][0] ); ?>" role="alert">
				<?php echo esc_html( $law_notices[ $law_notice ][1] ); ?>
			</p>
		<?php endif; ?>

		<?php
		// Test mode is an administrator control (functions/events/test-mode.php)
		// and stays one: it diverts every email the site sends, password resets
		// included. But somebody editing wording here has to know when their
		// test will land somewhere other than their own inbox, so it is
		// announced on both views whenever it is actually on.
		?>
		<?php if ( law_events_is_test_mode() ) : ?>
			<p class="law-form-notice is-warning" role="status">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the address every email is being delivered to. */
						__( 'Test mode is on: every email the site sends, including anything you test from here, is being delivered to %s instead of its real recipients. An administrator can switch it off.', 'law' ),
						law_events_test_mode_address()
					)
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $law_asked ) : ?>
			<?php get_template_part( 'parts/events/emails-manage', null, array( 'slug' => $law_asked ) ); ?>
		<?php else : ?>

			<h1 class="law-dashboard__title"><?php esc_html_e( 'Manage emails', 'law' ); ?></h1>

			<?php
			// What this screen does and does not cover, said once and here,
			// because "why is the contact form not in this list" is the first
			// question it raises.
			?>
			<p class="law-form-notice" role="status">
				<?php esc_html_e( 'Every notification the events module sends, but not the contact form\'s. An edit applies from the next send onwards, never to anything already sent.', 'law' ); ?>
			</p>

			<?php get_template_part( 'parts/events/emails-list' ); ?>

		<?php endif; ?>
	<?php endif; ?>

	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
