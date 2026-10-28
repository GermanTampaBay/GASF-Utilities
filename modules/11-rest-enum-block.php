<?php
/**
 * User-enumeration block — modules/11-rest-enum-block.php
 *
 * Closes the three public paths that hand a scanner the site's login names:
 *   1. REST  — /wp/v2/users and /wp/v2/users/{id} are removed from the API.
 *   2. Query — /?author=N resolves the Nth user and core's canonical
 *      redirect then reveals the user slug (= the login name, by default)
 *      in the Location header. Visitors get a plain 404 instead.
 *   3. Sitemap — core's /wp-sitemap-users-1.xml lists every author archive.
 *      The users provider is dropped, so the sitemap index never links it.
 * Logged-in users are unaffected; /author/<slug>/ archives still work for
 * anyone who already knows a slug (that is not enumeration).
 *
 * Until 2026-10-28 only (1) existed, while the Settings tab and Overview both
 * claimed an "author-enumeration block". Migrated from Code Snippet #11
 * (2026-06-14); the GASF_BLOCK_REST_USERS wp-config constant it once also
 * required is inert since 2026-07-27 and can be removed from wp-config.
 *
 * Gate: gasf_site_enable_restenum (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_site_enabled( 'gasf_site_enable_restenum' ) ) {

	/* 1. REST user listing. */
	add_filter( 'rest_endpoints', function ( $endpoints ) {
		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		return $endpoints;
	} );

	/* 2. ?author=N lookups → 404 for visitors. Priority 0 so it runs before
	 *    redirect_canonical() (priority 10) can leak the slug. */
	add_action( 'template_redirect', function () {
		if ( is_admin() || is_user_logged_in() ) { return; }
		if ( ! isset( $_GET['author'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check on a public GET
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}, 0 );

	/* 3. No users sitemap. */
	add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}, 10, 2 );
}
