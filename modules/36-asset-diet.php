<?php
/**
 * Front-end asset diet — modules/36-asset-diet.php
 *
 * Trims globally-loaded weight the page doesn't actually need:
 *   1. Drops jQuery Migrate on the front end (nothing here needs the deprecated
 *      jQuery APIs it shims).
 *   2. Loads the 3D-flipbook (dFlip) JS + CSS only on pages that actually contain
 *      a [dflip] shortcode — it was loading site-wide but is used on ~1 page.
 *   3. Self-hosts the theme's Google Fonts (Poppins / Fira Sans / Patua One) when
 *      a local bundle exists at uploads/gasf-fonts/gasf-fonts.css — removing the
 *      third-party request to fonts.googleapis.com. Inert until the bundle is
 *      generated, so deploying this file alone changes nothing about fonts.
 *
 * All three are reversible: set gasf_site_enable_assetdiet to '0' to disable.
 * Gate: gasf_site_enable_assetdiet (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_assetdiet' ) ) {

	/* 1. jQuery Migrate — front end only. */
	add_action( 'wp_default_scripts', function ( $scripts ) {
		if ( is_admin() ) { return; }
		$jq = $scripts->registered['jquery'] ?? null;
		if ( $jq && ! empty( $jq->deps ) ) {
			$jq->deps = array_values( array_diff( $jq->deps, array( 'jquery-migrate' ) ) );
		}
	} );

	/* 2. dFlip only where a [dflip] shortcode is present. */
	add_action( 'wp_enqueue_scripts', function () {
		$needs = false;
		if ( is_singular() ) {
			$content = (string) get_post_field( 'post_content', get_queried_object_id() );
			$needs   = has_shortcode( $content, 'dflip' );
		}
		if ( ! $needs ) {
			foreach ( array( 'dflip-script', 'dflip' ) as $h ) { wp_dequeue_script( $h ); wp_deregister_script( $h ); }
			wp_dequeue_style( 'dflip-style' );
			wp_deregister_style( 'dflip-style' );
		}
	}, 100 );

	/* 3. Self-host Google Fonts when the local bundle exists. */
	add_action( 'wp_enqueue_scripts', function () {
		$up  = wp_upload_dir();
		$css = trailingslashit( $up['basedir'] ) . 'gasf-fonts/gasf-fonts.css';
		if ( ! file_exists( $css ) ) { return; } // inert until generated
		wp_dequeue_style( 'hootdu-googlefont' );
		wp_deregister_style( 'hootdu-googlefont' );
		wp_enqueue_style( 'gasf-local-fonts', trailingslashit( $up['baseurl'] ) . 'gasf-fonts/gasf-fonts.css', array(), null );
	}, 100 );

	/* Germania One on H2 headings — self-hosted replacement for the (removed) Olympus
	 * "Google Fonts" plugin, which applied this from gstatic. Same @font-face + exact
	 * selectors, printed late in <head> like the plugin did, so headings look identical. */
	add_action( 'wp_head', function () {
		$up   = wp_upload_dir();
		$file = trailingslashit( $up['basedir'] ) . 'gasf-fonts/germania-one-400-normal-latin.woff2';
		if ( ! file_exists( $file ) ) { return; }
		$url = trailingslashit( $up['baseurl'] ) . 'gasf-fonts/germania-one-400-normal-latin.woff2';
		echo "<style id=\"gasf-germania\">@font-face{font-family:'Germania One';font-style:normal;font-weight:400;font-display:swap;src:url(" . esc_url( $url ) . ") format('woff2');}"
			. ".entry-content h2,.post-content h2,.page-content h2,#content h2,.type-post h2,.type-page h2,.elementor h2{font-family:\"Germania One\";}</style>\n";
	}, 99 );

	/* Fonts are local now — drop the dns-prefetch/preconnect hints to Google's font hosts. */
	add_filter( 'wp_resource_hints', function ( $urls, $relation ) {
		if ( ! in_array( $relation, array( 'dns-prefetch', 'preconnect' ), true ) ) { return $urls; }
		return array_values( array_filter( $urls, function ( $u ) {
			$href = is_array( $u ) ? ( $u['href'] ?? '' ) : (string) $u;
			return false === strpos( $href, 'fonts.googleapis.com' ) && false === strpos( $href, 'fonts.gstatic.com' );
		} ) );
	}, 20, 2 );
}
