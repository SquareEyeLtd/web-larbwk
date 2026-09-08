<?php
/**
 * The header's top bar.
 *
 * get_template_part( 'parts/layout/top-nav', null, array(
 *   'variant' => 'desktop',   // 'desktop' | 'mobile'. Required in practice.
 * ) );
 *
 * Signed out this renders Sign in / Create an account; signed in, one
 * account dropdown reading "Logged in as [name]". What appears is decided by
 * law_header_nav() (functions/header-nav.php); this file only draws it.
 *
 * Every control is the site's outlined white button (see .law-topnav__button
 * in assets/css/app.css), the same treatment the header's original
 * "MY ACCOUNT" button had.
 *
 * Both variants are in the DOM at once. The desktop copy sits inside the
 * .show-for-large grid cell above the main menu; the mobile copy is a
 * .hide-for-large element in the logo row, positioned to the left of the
 * burger. Foundation hides one with display:none, so the hidden copy is out
 * of the accessibility tree and nothing is announced twice. The dropdown is
 * a native <details>, so there are no ids or aria-controls to collide
 * between the two.
 *
 * The mobile variant is smaller and narrower, so it differs in two ways:
 * the trigger shows the first name only ("Logged in as" stays for assistive
 * technology, hidden visually), and signed out it shows Sign in alone,
 * because two buttons do not fit beside the logo and burger on a phone. The
 * sign-in page links to registration (templates/login.php).
 *
 * <details> rather than a hand-built button/aria-expanded widget: it gives
 * the correct role, expanded state and keyboard behaviour for free, it still
 * works with JavaScript disabled, and it is already the theme's disclosure
 * pattern (parts/calendar-body.php, parts/events/speaker-card.php,
 * templates/account-dashboard.php). assets/js/header-nav.js only adds the
 * parts the element does not do itself: Escape, outside click, and closing
 * when focus leaves.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_variant = isset( $args['variant'] ) && 'mobile' === $args['variant'] ? 'mobile' : 'desktop';
$law_mobile  = 'mobile' === $law_variant;
$law_nav     = law_header_nav();
$law_account = $law_nav['account'];
$law_links   = $law_nav['links'];

if ( $law_mobile && $law_links ) {
	// One button beside the burger: Sign in. Registration is one tap further,
	// on the sign-in page.
	$law_links = array_values(
		array_filter(
			$law_links,
			static function ( $link ) {
				return 'signin' === $link['key'];
			}
		)
	);
}

if ( ! $law_account && ! $law_links ) {
	return;
}

$law_classes = 'law-topnav law-topnav--' . $law_variant;
if ( $law_mobile ) {
	$law_classes .= ' hide-for-large';
}
?>
<div class="<?php echo esc_attr( $law_classes ); ?>">
	<nav class="law-topnav__inner" aria-label="<?php esc_attr_e( 'Account', 'law' ); ?>">
		<ul class="law-topnav__bar">
			<?php if ( $law_account ) : ?>
				<li class="law-topnav__item law-topnav__item--account">
					<details class="law-topnav__account" data-law-topnav>
						<summary class="law-topnav__button law-topnav__trigger">
							<span class="law-topnav__label"><?php esc_html_e( 'Logged in as', 'law' ); ?></span>
							<span class="law-topnav__name"><?php echo esc_html( $law_mobile ? $law_account['short_name'] : $law_account['name'] ); ?></span>
							<span class="law-topnav__caret" aria-hidden="true"></span>
						</summary>
						<ul class="law-topnav__panel">
							<?php foreach ( $law_account['items'] as $law_item ) : ?>
								<li>
									<a class="law-topnav__panel-link<?php echo $law_item['current'] ? ' is-current' : ''; ?>"
										href="<?php echo esc_url( $law_item['url'] ); ?>"
										<?php echo $law_item['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $law_item['label'] ); ?></a>
								</li>
							<?php endforeach; ?>
						</ul>
					</details>
				</li>
			<?php else : ?>
				<?php foreach ( $law_links as $law_link ) : ?>
					<li class="law-topnav__item">
						<a class="law-topnav__button law-topnav__link law-topnav__link--<?php echo esc_attr( $law_link['key'] ); ?>" href="<?php echo esc_url( $law_link['url'] ); ?>"><?php echo esc_html( $law_link['label'] ); ?></a>
					</li>
				<?php endforeach; ?>
			<?php endif; ?>
		</ul>
	</nav>
</div>
