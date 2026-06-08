<?php
	
	add_filter( 'gettext_gravityflow', function ( $translation, $text, $domain ) {
	if ( 'Revert' === $text ) {
		return 'Send back';
	}
	if ( 'Are you sure you want to revert this entry?' === $text ) {
		return 'Are you sure you want to send this back?';
	}
	return $translation;
}, 10, 3 );