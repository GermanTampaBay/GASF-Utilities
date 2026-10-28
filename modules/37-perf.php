<?php
/**
 * Performance — modules/37-perf.php
 *
 * Targets the mobile LCP (was ~9.9s on PageSpeed). Two independently-gated parts:
 *
 * A) LCP & images  [gate: gasf_site_enable_perf, default ON]
 *    - The home hero ([gas_hero]) is the LCP element. It was (a) lazy-loaded — a
 *      duplicate loading="lazy" added downstream beat our loading="eager", the
 *      classic LCP killer — and (b) served at full 2048px to phones. We preload
 *      it high-priority in <head> (so it starts immediately, regardless of any
 *      lazy attr), cap its responsive `sizes` so mobile fetches a right-sized
 *      version, and strip the stray lazy attribute.
 *    - preconnect to the Photon image CDN (i0.wp.com) that serves every image.
 *
 * B) Defer JavaScript  [gate: gasf_site_enable_deferjs, default ON]
 *    - Adds `defer` to front-end scripts to cut render-blocking, EXCLUDING
 *      jquery-core and any handle carrying inline before/after code (deferring
 *      those would reorder execution and break init). Toggle off if a menu,
 *      slider, or widget misbehaves.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ---------------- A) LCP & images ---------------- */
if ( gasf_site_enabled( 'gasf_site_enable_perf' ) ) {

	/** Resolve the hero entry the [gas_hero] shortcode will render (front page only). */
	function gasf_perf_hero_entry() {
		if ( ! function_exists( 'gasf_hero_active' ) ) { return null; }
		$e = apply_filters( 'gasf_hero_active_entry', gasf_hero_active() );
		return ( is_array( $e ) && ! empty( $e['image_id'] ) ) ? $e : null;
	}

	/** Responsive sizes cap for the hero — keeps phones off the 2048px original. */
	function gasf_perf_hero_sizes() { return '(max-width: 1080px) 100vw, 1080px'; }

	// A1: right-size + high-priority + eager on the hero <img>.
	add_filter( 'wp_get_attachment_image_attributes', function ( $attr ) {
		if ( ! empty( $attr['class'] ) && false !== strpos( $attr['class'], 'gasf-hero__img' ) ) {
			$attr['sizes']         = gasf_perf_hero_sizes();
			$attr['fetchpriority'] = 'high';
			$attr['loading']       = 'eager';
		}
		return $attr;
	}, 20 );

	// A2: preload the hero image so LCP starts in the <head>, not after a lazy trigger.
	add_action( 'wp_head', function () {
		if ( ! is_front_page() ) { return; }
		$e = gasf_perf_hero_entry();
		if ( ! $e ) { return; }
		$id  = (int) $e['image_id'];
		$src = wp_get_attachment_image_url( $id, 'full' );
		if ( ! $src ) { return; }
		$srcset = wp_get_attachment_image_srcset( $id, 'full' );
		echo '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url( $src ) . '"'
			. ( $srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="' . esc_attr( gasf_perf_hero_sizes() ) . '"' : '' )
			. ">\n";
	}, 2 );

	// A3: preconnect to the Photon image CDN (every <img> is served from i0.wp.com).
	add_filter( 'wp_resource_hints', function ( $urls, $relation ) {
		if ( 'preconnect' === $relation ) { $urls[] = 'https://i0.wp.com'; }
		return $urls;
	}, 10, 2 );

	// A4: strip a duplicate loading="lazy" from the hero (belt-and-suspenders to the preload).
	add_filter( 'the_content', function ( $html ) {
		if ( false === strpos( $html, 'gasf-hero__img' ) ) { return $html; }
		return preg_replace_callback( '/<img\b[^>]*gasf-hero__img[^>]*>/i', function ( $m ) {
			$tag = $m[0];
			if ( false !== strpos( $tag, 'fetchpriority' ) || false !== strpos( $tag, 'loading="eager"' ) ) {
				$tag = preg_replace( '/\sloading="lazy"/', '', $tag );
			}
			return $tag;
		}, $html );
	}, PHP_INT_MAX );
}

/* ---------------- B) Defer JavaScript ---------------- */
if ( gasf_site_enabled( 'gasf_site_enable_deferjs' ) ) {

	add_filter( 'script_loader_tag', function ( $tag, $handle ) {
		if ( is_admin() ) { return $tag; }
		if ( 'jquery-core' === $handle ) { return $tag; }                       // keep $ available synchronously
		if ( preg_match( '/\s(defer|async)(=|\s|>)/', $tag ) ) { return $tag; }  // already deferred/async
		if ( false !== strpos( $tag, 'type="module"' ) ) { return $tag; }       // modules already defer
		global $wp_scripts;
		if ( $wp_scripts instanceof WP_Scripts ) {
			// Deferring a script that has attached inline code would run that inline
			// before the external loads → breakage. Leave those alone.
			if ( $wp_scripts->get_data( $handle, 'after' ) || $wp_scripts->get_data( $handle, 'before' ) ) {
				return $tag;
			}
		}
		return preg_replace( '/<script\s/', '<script defer ', $tag, 1 );
	}, 10, 2 );
}
