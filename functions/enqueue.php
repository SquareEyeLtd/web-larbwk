<?php
add_action( 'wp_enqueue_scripts', function () {
	$uri  = get_template_directory_uri();
	$path = get_template_directory();

	// Version by file mtime, falling back to a string if the file is missing.
	$v = function ( $rel ) use ( $path ) {
		$file = $path . $rel;
		return file_exists( $file ) ? filemtime( $file ) : '1.0';
	};

	// CSS
	wp_enqueue_style( 'law-foundation', $uri . '/assets/css/foundation.css', array(), $v( '/assets/css/foundation.css' ) );
	wp_enqueue_style( 'law-animate', $uri . '/assets/css/animate.min.css', array(), $v( '/assets/css/animate.min.css' ) );
	wp_enqueue_style( 'law-app', $uri . '/assets/css/app.css', array( 'law-foundation' ), $v( '/assets/css/app.css' ) );
	wp_enqueue_style( 'law-accordions', $uri . '/assets/css/accordions.css', array( 'law-app' ), $v( '/assets/css/accordions.css' ) );

	wp_enqueue_style( 'law-wp', get_stylesheet_uri(), array( 'law-app' ), $v( '/style.css' ) );

	// After theme + plugin styles so GF/GV/GFlow overrides win.
	wp_enqueue_style(
		'gravity-forms',
		get_theme_file_uri( '/assets/css/gravity-forms.css' ),
		array( 'law-wp' ),
		filemtime( get_theme_file_path( '/assets/css/gravity-forms.css' ) )
	);
	wp_enqueue_style(
		'gravity-flow',
		get_theme_file_uri( '/assets/css/gravity-flow.css' ),
		array( 'law-wp' ),
		filemtime( get_theme_file_path( '/assets/css/gravity-flow.css' ) )
	);

	// GravityView table/search tweaks.
	wp_enqueue_style(
		'gravity-kit',
		get_theme_file_uri( '/assets/css/gravity-kit.css' ),
		array( 'law-wp' ),
		filemtime( get_theme_file_path( '/assets/css/gravity-kit.css' ) )
	);

	// Typekit (Poppins)
	wp_enqueue_style( 'law-typekit-1', 'https://use.typekit.net/vum0moo.css', array(), null );
	wp_enqueue_style( 'law-typekit-2', 'https://use.typekit.net/dje5apx.css', array(), null );

	// JS
	wp_enqueue_script( 'law-what-input', $uri . '/assets/js/vendor/what-input.js', array( 'jquery' ), $v( '/assets/js/vendor/what-input.js' ), true );
	wp_enqueue_script( 'law-foundation', $uri . '/assets/js/vendor/foundation.js', array( 'jquery' ), $v( '/assets/js/vendor/foundation.js' ), true );
	wp_enqueue_script( 'law-wow', $uri . '/assets/js/vendor/wow.min.js', array(), $v( '/assets/js/vendor/wow.min.js' ), true );
	wp_enqueue_script( 'law-matchheight', 'https://cdnjs.cloudflare.com/ajax/libs/jquery.matchHeight/0.7.2/jquery.matchHeight-min.js', array( 'jquery' ), '0.7.2', true );
	wp_enqueue_script( 'law-app', $uri . '/assets/js/app.js', array( 'jquery', 'law-foundation', 'law-wow', 'law-matchheight' ), $v( '/assets/js/app.js' ), true );
}, 20 );