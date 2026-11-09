<?php
/**
 * Main Hall tap list from staging — modules/46-staging-taps-bridge.php
 *
 * TEMPORARY, until the new platform replaces this site.
 *
 * The Main Hall has no tap sign: its list is typed on the bar's tablet into
 * the new platform on staging.germantampabay.com. This copies that list from
 * staging's public tap route into the GASF Sign plugin's own store (option
 * gasf_sign_taps, through GASF_Sign_Taps::normalize/record) — the same place
 * the Home Assistant feeder's lists land — so [gasf_on_tap bar="main-hall"]
 * draws it like any other bar, with the plugin's own 21+ notice under it.
 *
 * Pulled, not pushed: the route is public and anonymous, so no token leaves
 * either site and nothing on staging has to know this site exists.
 *
 * Refreshed when a page carrying [gasf_on_tap] is drawn, at most once every
 * two minutes for the whole site, because WP-Cron here only runs every 20.
 * The fetch gives up after 3 seconds, so a slow or missing staging never holds
 * a page up, and a failed fetch keeps the last list rather than blanking it.
 * A good answer without the Main Hall in it means nothing is on, and clears it.
 *
 * Tear down when staging becomes the production site: delete this file, its
 * row in 02-settings.php, and the stored bar:
 *   wp eval '$b = GASF_Sign_Taps::bars(); unset( $b["main-hall"] ); update_option( GASF_Sign_Taps::OPT_BARS, $b, false );'
 *
 * Gate: gasf_site_enable_tapbridge (default ON). Inert on a site without the
 * GASF Sign plugin (Krampus).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_tapbridge' ) ) {

	if ( ! defined( 'GASF_TAPBRIDGE_URL' ) ) { define( 'GASF_TAPBRIDGE_URL', 'https://staging.germantampabay.com/api/v1/sign/public/taps' ); }
	if ( ! defined( 'GASF_TAPBRIDGE_BAR' ) ) { define( 'GASF_TAPBRIDGE_BAR', 'main-hall' ); }

	/**
	 * Copy the Main Hall's list from staging into the plugin's store.
	 * Returns 'same' or 'stored', or a WP_Error; never throws.
	 */
	function gasf_tapbridge_pull() {
		// Checked here, not at load: mu-plugins load before the plugins they lean on.
		if ( ! class_exists( 'GASF_Sign_Taps' ) ) { return new WP_Error( 'no_plugin', 'GASF Sign is not active.' ); }

		$r = wp_remote_get( GASF_TAPBRIDGE_URL, array(
			'timeout'     => 3,
			'redirection' => 0,
			'user-agent'  => 'gasf-legacy-tap-bridge/1',
			'headers'     => array( 'Accept' => 'application/json' ),
		) );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( 200 !== $code ) { return new WP_Error( 'http', 'staging answered ' . $code ); }
		$all = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $all ) || ! array_is_list( $all ) ) { return new WP_Error( 'shape', 'staging did not answer with a list of bars' ); }

		$label = 'Main Hall';
		$beers = array();
		foreach ( $all as $bar ) {
			if ( ! is_array( $bar ) || GASF_TAPBRIDGE_BAR !== ( $bar['bar'] ?? null ) ) { continue; }
			if ( is_string( $bar['label'] ?? null ) && '' !== $bar['label'] ) { $label = $bar['label']; }
			foreach ( (array) ( $bar['beers'] ?? array() ) as $b ) {
				// Staging has already turned "Bitburger" into "Bitburger Premium Pils"; take the long name.
				$name = is_array( $b ) ? ( $b['label'] ?? $b['name'] ?? '' ) : '';
				if ( is_string( $name ) && '' !== $name ) { $beers[] = $name; }
			}
		}

		$report = GASF_Sign_Taps::normalize( array(
			'bar'   => GASF_TAPBRIDGE_BAR,
			'label' => $label,
			'beers' => array_slice( $beers, 0, GASF_Sign_Taps::MAX_BEERS ),
		) );
		if ( is_string( $report ) ) { return new WP_Error( 'refused', $report ); }

		// Unchanged and heard from in the last 15 minutes: leave the row alone. Re-stamped
		// otherwise, like the HA feeder, so a shortcode with max_age_hours never ages it out.
		$stored = GASF_Sign_Taps::bars()[ GASF_TAPBRIDGE_BAR ] ?? null;
		if ( is_array( $stored )
			&& ( $stored['label'] ?? null ) === $report['label']
			&& ( $stored['beers'] ?? null ) === $report['beers']
			&& time() - (int) ( $stored['received_at'] ?? 0 ) < 900 ) {
			return 'same';
		}
		GASF_Sign_Taps::record( $report, time() );
		return 'stored';
	}

	// Just before [gasf_on_tap] draws. The transient is set before the fetch, so one
	// page view pays for it and the others in the same two minutes do not.
	add_filter( 'pre_do_shortcode_tag', function ( $out, $tag ) {
		if ( 'gasf_on_tap' !== $tag || get_transient( 'gasf_tapbridge_fresh' ) ) { return $out; }
		set_transient( 'gasf_tapbridge_fresh', 1, 120 );
		$res = gasf_tapbridge_pull();
		if ( is_wp_error( $res ) ) { gasf_mec_log( 'tap bridge: ' . $res->get_error_message() ); }
		return $out;
	}, 10, 2 );
}
