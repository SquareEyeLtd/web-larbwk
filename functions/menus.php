<?php

/**
 * Foundation 6 markup for theme navigation submenus.
 */
add_filter( 'nav_menu_submenu_css_class', 'law_nav_submenu_classes', 10, 3 );
add_filter( 'nav_menu_css_class', 'law_nav_item_classes', 10, 4 );

function law_nav_menu_supports_submenus( $args ) {
	if ( empty( $args->theme_location ) || empty( $args->law_menu_mode ) ) {
		return false;
	}

	return in_array( $args->theme_location, array( 'main-menu', 'top-menu' ), true );
}

function law_nav_submenu_classes( $classes, $args, $depth ) {
	if ( ! law_nav_menu_supports_submenus( $args ) ) {
		return $classes;
	}

	$classes[] = 'menu';
	$classes[] = 'vertical';
	$classes[] = 'nested';
	$classes[] = 'submenu';

	if ( 'accordion' === $args->law_menu_mode ) {
		$classes[] = 'is-accordion-submenu';
	} else {
		$classes[] = 'is-dropdown-submenu';
	}

	return $classes;
}

function law_nav_item_classes( $classes, $item, $args, $depth ) {
	if ( ! law_nav_menu_supports_submenus( $args ) ) {
		return $classes;
	}

	if ( ! in_array( 'menu-item-has-children', $classes, true ) ) {
		return $classes;
	}

	if ( 'accordion' === $args->law_menu_mode ) {
		$classes[] = 'is-accordion-submenu-parent';
	} else {
		$classes[] = 'is-dropdown-submenu-parent';
	}

	return $classes;
}
