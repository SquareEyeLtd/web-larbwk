<?php
	
add_filter( 'gettext_gravityflow', function ( $translation, $text, $domain ) {
	if ( 'Revert' === $text ) {
		return 'Send back';
	}
	if ( 'Approve' === $text ) {
		return 'Proceed';
	}
	if ( 'Are you sure you want to revert this entry?' === $text ) {
		return 'Are you sure you want to send this back?';
	}
	
	if ( 'Are you sure you want to approve this entry?' === $text ) {
		return 'Are you sure you want to proceed?';
	}
	
	return $translation;
}, 10, 3 );