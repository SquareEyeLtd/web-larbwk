<?php

/**
 * Block editor typography and canvas styles.
 */
add_action(
	'after_setup_theme',
	function () {
		add_theme_support( 'editor-styles' );

		add_editor_style(
			array(
				'https://use.typekit.net/vum0moo.css',
				'assets/css/editor.css',
			)
		);
	},
	20
);

add_action(
	'enqueue_block_editor_assets',
	function () {
		wp_enqueue_style(
			'law-typekit-editor',
			'https://use.typekit.net/vum0moo.css',
			array(),
			null
		);
	}
);
