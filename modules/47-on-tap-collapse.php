<?php
/**
 * Folding tap lists — modules/47-on-tap-collapse.php
 *
 * [gasf_on_tap collapse="1"] draws each bar folded shut under its name, with a
 * chevron and "Click to see list", so the home page spends one line per bar
 * instead of a card of beers. A <details> per bar: it opens with a click or
 * the keyboard and needs no script, and the beer names stay in the HTML, where
 * Google reads them whether or not anybody opens it.
 *
 * The shortcode belongs to the GASF Sign plugin; this only reshapes its output
 * after the fact (do_shortcode_tag), and only when collapse is asked for — the
 * plugin's shortcode_atts drops the attribute, but the filter sees it raw. If
 * the plugin's markup ever changes shape, the output passes through untouched.
 *
 * Gate: gasf_site_enable_tapcollapse (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_tapcollapse' ) ) {

	add_filter( 'do_shortcode_tag', function ( $out, $tag, $attr ) {
		if ( 'gasf_on_tap' !== $tag || ! is_string( $out ) || '' === $out ) { return $out; }
		$want = is_array( $attr ) ? ( $attr['collapse'] ?? '' ) : '';
		if ( ! in_array( strtolower( (string) $want ), array( '1', 'yes', 'true', 'on' ), true ) ) { return $out; }

		$n = 0;
		$folded = preg_replace(
			'~<section class="(gasf-on-tap__bar[^"]*)">(<h3 class="gasf-on-tap__title">.*?</h3>)(<ul class="gasf-on-tap__beers">.*?</ul>)</section>~s',
			'<details class="$1"><summary>$2<span class="gasf-on-tap__hint">'
				. '<span class="gasf-on-tap__hint-open">Click to see list</span>'
				. '<span class="gasf-on-tap__hint-close">Click to hide list</span>'
				. '</span></summary>$3</details>',
			$out,
			-1,
			$n
		);
		if ( ! $n || ! is_string( $folded ) ) { return $out; }
		$folded = preg_replace( '~^<div class="gasf-on-tap">~', '<div class="gasf-on-tap gasf-on-tap--collapse">', $folded, 1 );

		static $styled = false;
		if ( ! $styled ) {
			$styled = true;
			$folded = '<style id="gasf-on-tap-collapse">'
				. '.gasf-on-tap--collapse summary{list-style:none;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;'
				. 'padding:10px 12px;border:1px solid rgba(239,159,39,.45);border-radius:10px;background:rgba(0,0,0,.18);transition:background .15s,border-color .15s}'
				. '.gasf-on-tap--collapse summary::-webkit-details-marker{display:none}'
				. '.gasf-on-tap--collapse summary:hover,.gasf-on-tap--collapse summary:focus-visible{background:rgba(0,0,0,.32);border-color:var(--gasf-gold,#EF9F27);outline:none}'
				. '.gasf-on-tap--collapse .gasf-on-tap__title{margin:0;color:var(--gasf-gold,#EF9F27)}'
				. '.gasf-on-tap--collapse .gasf-on-tap__hint{display:inline-flex;align-items:center;gap:8px;color:rgba(255,255,255,.78);font-size:.85em;font-weight:600}'
				// The chevron: points down while shut, turns up when open.
				. '.gasf-on-tap--collapse .gasf-on-tap__hint::before{content:"";width:7px;height:7px;border:solid var(--gasf-gold,#EF9F27);border-width:0 2px 2px 0;transform:translateY(-2px) rotate(45deg);transition:transform .2s}'
				. '.gasf-on-tap--collapse details[open] .gasf-on-tap__hint::before{transform:translateY(2px) rotate(-135deg)}'
				. '.gasf-on-tap--collapse .gasf-on-tap__hint-close,.gasf-on-tap--collapse details[open] .gasf-on-tap__hint-open{display:none}'
				. '.gasf-on-tap--collapse details[open] .gasf-on-tap__hint-close{display:inline}'
				. '.gasf-on-tap--collapse details[open] summary{margin-bottom:6px}'
				. '</style>' . $folded;
		}
		return $folded;
	}, 10, 3 );
}
