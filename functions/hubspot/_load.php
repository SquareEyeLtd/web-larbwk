<?php
/**
 * HubSpot module loader (_docs/HUBSPOT_SYNC.md §6).
 *
 * The native replacement for the Make scenario: the site creates and updates
 * HubSpot contacts and their Contact type tags itself, from what it knows
 * about accounts, events, bookings and speakers, through a queue and a cron
 * worker. Loaded after functions/events/_load.php, whose settings, statuses
 * and booking, event and speaker functions the rules read.
 *
 * Everything is loaded in every mode so the admin screen can say "off"; what
 * the mode gates is the client (no requests when off), the worker schedule
 * (cleared when off) and the hooks (each callback checks the mode).
 *
 * Files, in dependency order:
 *   config.php      modes, token, property names, tags
 *   client.php      HTTP to the CRM API with retries and dry-run
 *   log.php         log and queue tables
 *   properties.php  schema check and create
 *   rules.php       desired state for one person (§5)
 *   sync.php        plan, push and preview (§6.3, §8)
 *   queue.php       dirty queue and the cron worker (§6.2)
 *   hooks.php       WordPress events that enqueue people (§6.4)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/properties.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/hooks.php';

if ( is_admin() ) {
	require_once __DIR__ . '/admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/cli.php';
}
