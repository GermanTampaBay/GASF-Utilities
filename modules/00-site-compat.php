<?php
/**
 * Site compatibility shim — small helpers every module may rely on, loaded
 * first by the glob. The gate helper gasf_site_enabled() itself now lives in
 * gasf-mec-importer.php (defined before any module loads); GASF_SITE_VERSION
 * stays for anything outside this repo that checks it.
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
