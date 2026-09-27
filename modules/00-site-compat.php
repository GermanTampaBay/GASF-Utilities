<?php
/**
 * Site compatibility shim — provides the gate helper that the migrated
 * GASF_Site modules (rest-enum, bundesliga, print button, redirects, parking)
 * rely on, now that they live in this unified "GASF Utilities" plugin.
 * Guarded so it can never collide if the old gasf-site.php loader is present.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'GASF_SITE_VERSION' ) ) { define( 'GASF_SITE_VERSION', '1.0.0' ); }

/**
 * The club's name, corrected wherever a model invents one.
 *
 * Informally "German-American Society"; formally "German-American Society
 * Friendship of Pinellas County". "German-American Society of Tampa Bay" is not
 * and never was its name -- the domain is germantampabay.com, and prompts that
 * put "Tampa Bay" next to the name got it back as one phrase, in SEO
 * descriptions, blog headlines, and photo captions. Prompts now say not to;
 * this makes sure. Leaves the formal name and plain "Tampa Bay" (the region)
 * alone. Same patterns as the face scanner's fix_club_name().
 */
if ( ! function_exists( 'gasf_fix_club_name' ) ) {
	function gasf_fix_club_name( $text ) {
		return (string) preg_replace(
			array(
				'/German[\s-]*American\s+Society\s+of\s+(?:the\s+)?(?:Greater\s+)?Tampa\s+Bay(?:\s+Area)?/i',
				"/(?:Greater\\s+)?Tampa\\s+Bay(?:'s|\\s+Area(?:'s)?)?\\s+German[\\s-]*American\\s+Society/i",
			),
			'German-American Society',
			(string) $text
		);
	}
}

if ( ! function_exists( 'gasf_site_enabled' ) ) {
	function gasf_site_enabled( $option, $default = '1' ) {
		$v = get_option( $option, $default );
		return ! ( $v === '0' || $v === 0 || $v === false || $v === 'false' );
	}
}
