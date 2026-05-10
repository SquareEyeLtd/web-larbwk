<?php
	
// Helper: theme asset URL
function law_asset( $path ) {
    return get_template_directory_uri() . '/' . ltrim( $path, '/' );
}