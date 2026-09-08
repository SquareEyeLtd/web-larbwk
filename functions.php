<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
define('WEBSITE_NAME', 'London Arbitration Week'); 
define('WEBSITE_SLUG', 'larbwk');
define('EIO_LAZY_FOLD', 3);

require_once(get_theme_file_path('/functions/_init.php'));
require_once(get_theme_file_path('/functions/wordpress.php'));
require_once(get_theme_file_path('/functions/editor.php'));
require_once(get_theme_file_path('/functions/menus.php'));
require_once(get_theme_file_path('/functions/helpers.php'));
require_once(get_theme_file_path('/functions/enqueue.php'));
require_once(get_theme_file_path('/functions/modal.php'));
require_once(get_theme_file_path('/functions/shortcodes.php'));

require_once(get_theme_file_path('/functions/gravity-forms.php'));
require_once(get_theme_file_path('/functions/gravity-flow.php'));
require_once(get_theme_file_path('/functions/hubspot.php'));
require_once(get_theme_file_path('/functions/event-workflow.php'));
require_once(get_theme_file_path('/functions/users.php'));
require_once(get_theme_file_path('/functions/auth.php'));
require_once(get_theme_file_path('/functions/calendar.php'));
require_once(get_theme_file_path('/functions/account-events.php'));
require_once(get_theme_file_path('/functions/account-bookings.php'));
require_once(get_theme_file_path('/functions/speakers.php'));
require_once(get_theme_file_path('/functions/header-nav.php'));
require_once(get_theme_file_path('/functions/migrate-speakers.php'));
require_once(get_theme_file_path('/functions/setup-account-pages.php'));
require_once(get_theme_file_path('/functions/events/_load.php'));

