<?php
/**
 * Events module loader (EVENTS_4.1_REBUILD.md).
 *
 * The custom replacement for the Gravity Forms / Gravity Flow / Make events
 * stack: CPT-backed events, speakers and sessions, a code-level workflow,
 * direct Stripe invoicing and the migration tooling.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/post-types.php';
require_once __DIR__ . '/statuses.php';
require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/rich-text.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/capabilities.php';
require_once __DIR__ . '/fees.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/comments.php';
require_once __DIR__ . '/unread.php';
require_once __DIR__ . '/co-owners.php';
require_once __DIR__ . '/ics.php';
require_once __DIR__ . '/discounts.php';
require_once __DIR__ . '/bookings.php';
require_once __DIR__ . '/waitlist.php';
require_once __DIR__ . '/bookings-dashboard.php';
require_once __DIR__ . '/discounts-dashboard.php';
require_once __DIR__ . '/test-mode.php';
require_once __DIR__ . '/notifications.php';
// The committee's front-end copy of the wp-admin Emails screen. After
// notifications.php, whose registry and shared save helpers it renders and
// writes through.
require_once __DIR__ . '/emails-dashboard.php';
// The per-event booking confirmation. After the emails dashboard because it
// reads the same registry through law_events_email(), and after notifications
// because law_event_override_slug_map() lives there.
require_once __DIR__ . '/email-override.php';
require_once __DIR__ . '/speakers.php';
require_once __DIR__ . '/speakers-dashboard.php';
require_once __DIR__ . '/flagship.php';
require_once __DIR__ . '/flagship-form.php';
require_once __DIR__ . '/flagship-dashboard.php';
require_once __DIR__ . '/flagship-bookings.php';
require_once __DIR__ . '/flagship-bookings-dashboard.php';
// The receptions (RECEPTIONS.md). After flagship-bookings.php, because the
// included places are granted from a confirmed flagship booking, and before
// the dashboards, which render their lists.
require_once __DIR__ . '/receptions.php';
require_once __DIR__ . '/receptions-dashboard.php';
// External events (EVENTS_4.2_SPECS.md): the third kind of law_event the
// committee owns outright. After the receptions because it follows their
// shape, and before source.php, which maps the flag onto the calendar event.
require_once __DIR__ . '/external-events.php';
require_once __DIR__ . '/source.php';
require_once __DIR__ . '/edit-lock.php';
require_once __DIR__ . '/submission-form.php';
require_once __DIR__ . '/registration.php';
require_once __DIR__ . '/committee.php';
// The committee's timeline view. After committee.php, whose law_committee_events()
// it filters through, so the chart and the table can never select different
// events; before export.php only to keep the dashboard's files together.
require_once __DIR__ . '/slot-chart.php';
require_once __DIR__ . '/export.php';
require_once __DIR__ . '/stripe/client.php';
require_once __DIR__ . '/stripe/service.php';
require_once __DIR__ . '/stripe/attendees.php';
require_once __DIR__ . '/stripe/webhook.php';
// After webhook.php: both read law_stripe_webhook_event_types() and the
// endpoint URL from it, so the health check and the sweep can never describe
// an endpoint the dispatcher does not actually serve.
require_once __DIR__ . '/stripe/reconcile.php';
require_once __DIR__ . '/stripe/health.php';
require_once __DIR__ . '/admin/fields.php';
require_once __DIR__ . '/admin/event-screen.php';
require_once __DIR__ . '/admin/booking-screen.php';
require_once __DIR__ . '/admin/speaker-screen.php';
require_once __DIR__ . '/admin/session-screen.php';
require_once __DIR__ . '/admin/flagship-screen.php';
require_once __DIR__ . '/admin/columns.php';
require_once __DIR__ . '/admin/emails-screen.php';
require_once __DIR__ . '/migration/report.php';
require_once __DIR__ . '/migration/runner.php';
require_once __DIR__ . '/migration/page.php';
require_once __DIR__ . '/migration/repair-owners.php';
require_once __DIR__ . '/migration/repair-references.php';
require_once __DIR__ . '/migration/repair-payment-status.php';
require_once __DIR__ . '/migration/repair-stripe-invoice-ids.php';
// After it: the reconciliation panel falls back to its lookup for a legacy
// event that has no invoice ID yet.
require_once __DIR__ . '/migration/reconcile-payments.php';
require_once __DIR__ . '/migration/repair-nbsp.php';
require_once __DIR__ . '/migration/repair-signoff.php';
require_once __DIR__ . '/migration/backfill-session-agenda.php';
require_once __DIR__ . '/migration/fetch-speaker-photos.php';
require_once __DIR__ . '/migration/content-transfer.php';
// The confirmed-slot comparison (/wp-admin/?test-events-confirmed-slots). After
// the runner, whose entry reader and page size it borrows.
require_once __DIR__ . '/migration/test-confirmed-slots.php';

/**
 * Which data source the front end reads: 'gf' (legacy Gravity Forms entries)
 * or 'cpt' (the migrated law_event posts). Flipped at cutover; flipping back
 * is the rollback (EVENTS_4.1_REBUILD.md section 5.4).
 */
function law_events_source() {
	$source = get_option( 'law_events_source', 'gf' );
	return 'cpt' === $source ? 'cpt' : 'gf';
}
