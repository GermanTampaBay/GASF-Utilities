<?php
// Migrated from Code Snippet #11 "Block REST API user enumeration" 2026-06-14 (task 260614-gj8).
// Gate: gasf_site_enable_restenum (default ON). Backed up in snippets-backup-20260614.sql.
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_site_enabled( 'gasf_site_enable_restenum' ) ) {
	// Block REST API user enumeration.
	//
	// The Settings toggle is now the ONLY gate. Until 2026-07-27 this also
	// required a GASF_BLOCK_REST_USERS constant carried over from the Code
	// Snippets era — a double gate the repo never documented, meaning any
	// install without that hand-added wp-config line silently ran unprotected
	// while the Settings screen claimed otherwise. The live site HAS the
	// constant, so behaviour there is unchanged; the constant is simply inert
	// now and can be removed from wp-config whenever convenient.
	add_filter( 'rest_endpoints', function ( $endpoints ) {
		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		return $endpoints;
	} );
}
