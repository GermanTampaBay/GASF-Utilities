<?php
/**
 * Security headers — modules/43-security-headers.php
 *
 * [gate: gasf_site_enable_secheaders, default ON]
 *
 * Four response headers the site sent none of until now. Each one tells the
 * BROWSER to refuse a category of trick; none of them patches code, and none is
 * a substitute for the checks the plugins already do. They are cheap, and they
 * close off whole classes of attack that application code cannot reach.
 *
 * Replaces the "HTTP Headers" plugin, removed 2026-10 after Jetpack Protect
 * flagged it. That plugin was not sending security headers at all: it wrote a
 * mod_expires block that Newfold's own caching block overrode twenty lines
 * later, and held a sitewide "Access-Control-Allow-Origin: *" in the database
 * that it never actually wrote out. Nothing it emitted is reproduced here,
 * because nothing it emitted was reaching a browser.
 *
 * WHY EACH ONE, for this site specifically:
 *
 *   Strict-Transport-Security
 *     A first request to http:// travels in the clear before the redirect to
 *     https:// happens, and /email carries a volunteer's login session.
 *
 *   X-Content-Type-Options: nosniff
 *     Stops a browser re-deciding what an uploaded file is. The party photo
 *     doors and the vendor insurance certificates both accept files from
 *     strangers; this is what keeps one from being re-interpreted as script.
 *
 *   X-Frame-Options + frame-ancestors
 *     /email is a signed-in tool whose buttons approve photos, delete them, and
 *     countersign contracts. Framing it invisibly and harvesting a volunteer's
 *     click is the attack these refuse. Both headers are sent: the CSP form is
 *     current, the X- form is what older browsers understand.
 *
 *   Referrer-Policy
 *     The one with a live exposure rather than a theoretical one. A party door
 *     URL is /photos/send/<64 hex> and that token IS the credential. Follow any
 *     external link from such a page and the default policy hands the whole URL,
 *     token included, to the destination. strict-origin-when-cross-origin sends
 *     the bare origin instead.
 *
 * HSTS max-age STARTS SHORT, DELIBERATELY. Once a browser sees this header it
 * refuses plain HTTP for the whole duration, and no visitor can click past that
 * — so a lapsed certificate becomes an outage nobody can work around. A day is
 * long enough to be useful and short enough to recover from. Raise it through
 * the filter once the site has run on it for a while.
 *
 * NOTE ON /email: GASF-CRM sets its own, longer HSTS on that route during
 * template_redirect, which runs after send_headers — so the CRM's value wins
 * there and this module does not fight it. Neither sets includeSubDomains, for
 * the reason the CRM records: a subdomain install is not ours to force.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_secheaders' ) ) {

	add_action( 'send_headers', 'gasf_security_headers', 1 );

	function gasf_security_headers() {
		// Nothing to send down a pipe that is not a browser response.
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		/*
		 * HTTPS only. Sending HSTS over plain HTTP is meaningless — the spec
		 * says to ignore it there — and this host terminates TLS at a proxy, so
		 * is_ssl() alone reports false on a perfectly good HTTPS request. The
		 * site URL is the reliable signal, the same test the CRM uses.
		 */
		if ( 0 === strpos( home_url(), 'https://' ) ) {
			$max_age = (int) apply_filters( 'gasf_hsts_max_age', DAY_IN_SECONDS );
			if ( $max_age > 0 ) {
				header( 'Strict-Transport-Security: max-age=' . $max_age );
			}
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: ' . apply_filters( 'gasf_referrer_policy', 'strict-origin-when-cross-origin' ) );

		$frame = (string) apply_filters( 'gasf_frame_ancestors', "'self'" );
		if ( '' !== $frame ) {
			header( 'X-Frame-Options: SAMEORIGIN' );

			/*
			 * Added as its own CSP header only when nothing else has set one.
			 *
			 * Two Content-Security-Policy headers are not merged — a browser
			 * enforces BOTH, and a resource must satisfy every policy present.
			 * Appending ours beside a real CSP somebody else wrote would quietly
			 * intersect with it, and the symptom would be images or scripts
			 * vanishing on pages nobody connected to this module.
			 */
			$has_csp = false;
			foreach ( headers_list() as $sent ) {
				if ( 0 === stripos( $sent, 'content-security-policy:' ) ) { $has_csp = true; break; }
			}
			if ( ! $has_csp ) {
				header( 'Content-Security-Policy: frame-ancestors ' . $frame );
			}
		}
	}
}
