<?php
/**
 * Unified "GASF Utilities" admin page — ONE menu, tabbed.
 *
 * A single top-level "GASF Utilities" menu renders a tabbed page. Modules add
 * their own tab with:
 *     gasf_utilities_add_tab( 'slug', 'Label', 'render_callback', $priority );
 * Built-in tabs: Overview (here) and Settings (modules/02-settings.php).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ---------- tab registry ---------- */
function gasf_utilities_add_tab( $slug, $label, $callback, $priority = 10 ) {
	global $gasf_util_tabs;
	if ( ! is_array( $gasf_util_tabs ) ) { $gasf_util_tabs = array(); }
	$gasf_util_tabs[ $slug ] = array(
		'label'    => $label,
		'callback' => $callback,
		'priority' => (int) $priority,
	);
}

/* ---------- single top-level menu ---------- */
add_action( 'admin_menu', function () {
	add_menu_page( 'GASF Utilities', 'GASF Utilities', 'manage_options', 'gasf-utilities', 'gasf_utilities_render', 'dashicons-admin-tools', 26 );
}, 9 );

/* register the built-in tabs (modules register theirs on admin_menu too) */
add_action( 'admin_menu', function () {
	gasf_utilities_add_tab( 'overview', 'Overview', 'gasf_utilities_overview_tab', 1 );
}, 10 );

/* ---------- shared "About this utility" docs panel ----------
 * Every tab calls this first so documentation is consistent:
 *   gasf_utilities_doc_panel( array(
 *     'what'   => 'One-paragraph overview of what the utility does.',
 *     'needs'  => array( 'Requirement 1', 'Requirement 2' ),   // to run at all
 *     'fields' => array( 'Field label' => 'What to enter and why it is needed.' ),
 *     'notes'  => 'Optional extra paragraph (gotchas, related tabs).',
 *   ) );
 * Renders a collapsible panel so the docs never crowd the working UI. */
function gasf_utilities_doc_panel( $args ) {
	$a = wp_parse_args( $args, array( 'what' => '', 'needs' => array(), 'fields' => array(), 'notes' => '' ) );
	echo '<details class="gasf-docs" style="background:#f6f7f7;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:2px;padding:10px 14px;margin:0 0 16px;max-width:960px">';
	echo '<summary style="cursor:pointer;font-weight:600;color:#2271b1">&#128214; About this utility — what it does &amp; how to fill in each field</summary>';
	echo '<div style="margin-top:10px">';
	if ( $a['what'] ) {
		echo '<p style="margin-top:0">' . wp_kses_post( $a['what'] ) . '</p>';
	}
	if ( $a['needs'] ) {
		echo '<p style="margin-bottom:4px"><strong>Needs to run:</strong></p><ul style="list-style:disc;margin:0 0 10px 22px">';
		foreach ( (array) $a['needs'] as $n ) {
			echo '<li>' . wp_kses_post( $n ) . '</li>';
		}
		echo '</ul>';
	}
	if ( $a['fields'] ) {
		echo '<p style="margin-bottom:4px"><strong>Fields &amp; controls:</strong></p>';
		echo '<table class="widefat striped" style="max-width:920px"><tbody>';
		foreach ( (array) $a['fields'] as $label => $desc ) {
			echo '<tr><td style="width:220px;font-weight:600">' . wp_kses_post( $label ) . '</td><td>' . wp_kses_post( $desc ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	if ( $a['notes'] ) {
		echo '<p style="color:#646970;margin-bottom:0">' . wp_kses_post( $a['notes'] ) . '</p>';
	}
	echo '</div></details>';
}

/* ---------- page renderer (tabs) ---------- */
function gasf_utilities_render() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	global $gasf_util_tabs;
	$tabs = is_array( $gasf_util_tabs ) ? $gasf_util_tabs : array();
	uasort( $tabs, function ( $a, $b ) { return $a['priority'] <=> $b['priority']; } );
	$first   = $tabs ? array_key_first( $tabs ) : '';
	$req      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	$current = ( $req && isset( $tabs[ $req ] ) ) ? $req : $first;

	echo '<div class="wrap">';
	echo '<h1>GASF Utilities</h1>';
	echo '<h2 class="nav-tab-wrapper">';
	foreach ( $tabs as $slug => $t ) {
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( admin_url( 'admin.php?page=gasf-utilities&tab=' . $slug ) ),
			$slug === $current ? 'nav-tab-active' : '',
			esc_html( $t['label'] )
		);
	}
	echo '</h2>';
	echo '<div class="gasf-util-tab" style="margin-top:18px">';
	if ( $current && isset( $tabs[ $current ] ) && is_callable( $tabs[ $current ]['callback'] ) ) {
		call_user_func( $tabs[ $current ]['callback'] );
	}
	echo '</div></div>';
}

/* ---------- Overview tab ---------- */
function gasf_utilities_overview_tab() {
	$tab = function ( $slug ) { return esc_url( admin_url( 'admin.php?page=gasf-utilities&tab=' . $slug ) ); };
	echo '<p>All custom germantampabay.com functionality lives in this one must-use plugin — every tab above is one self-contained utility. Each tab opens with a collapsible <strong>&#128214; About this utility</strong> panel explaining what it does, what it needs to run, and what every field is for. This Overview is the map. The <a href="' . $tab( 'settings' ) . '"><strong>Settings</strong></a> tab is the switchboard: turn any utility on/off and manage site-wide settings (e.g. the Anthropic API key).</p>';

	// Home-page Heroes + Recurring Heroes moved to the GASF-Events plugin
	// (Events → Heroes / Recurring Heroes). See the note at the bottom of this page.

	echo '<h3>Search &amp; links</h3><ul style="list-style:disc;margin-left:22px">';
	echo '<li><a href="' . $tab( 'seo' ) . '"><strong>SEO</strong></a> — the native replacement for Yoast: titles, meta descriptions, canonical/robots, OpenGraph/Twitter cards, JSON-LD, sitemap redirects, plus the per-page "SEO (GASF)" editor box.</li>';
	echo '<li><a href="' . $tab( 'aiseo' ) . '"><strong>Event SEO (AI)</strong></a> — Claude writes a meta description for every event name that lacks one (all "Biergarten" occurrences share one). Fills blanks only.</li>';
	echo '<li><a href="' . $tab( 'shortlinks' ) . '"><strong>Short Links</strong></a> — branded short URLs (<code>/join</code>, <code>/75th</code>…) with click counts, replacing URL Shortify. Works on all three domains.</li>';
	echo '<li><a href="' . $tab( 'redirects' ) . '"><strong>Redirects</strong></a> — 301/302/410 redirect manager + a live 404 log with one-click "create redirect" fixes.</li>';
	echo '</ul>';

	echo '<h3>Calendars</h3><ul style="list-style:disc;margin-left:22px">';
	echo '<li><a href="' . $tab( 'gcal-print' ) . '"><strong>Internal Calendar</strong></a> — a secret-link printable month view of the internal Google Calendar (includes hand-added private events) for the bulletin board. The calendar syncing itself (any ICS/Facebook source &rarr; site calendar and/or Google Calendar, with filters, title prefixes and colors) lives in the <strong>GASF-Events</strong> plugin: Events &rarr; Feeds.</li>';
	echo '</ul>';

	echo '<h3>Social &amp; reputation</h3><ul style="list-style:disc;margin-left:22px">';
	echo '<li><a href="' . $tab( 'instagram' ) . '"><strong>Instagram</strong></a> — the native feed behind <code>[gasf_instagram]</code> (grid/masonry/carousel + lightbox), replacing Smash Balloon\'s display. Self-refreshing token.</li>';
	echo '<li><a href="' . $tab( 'reviews' ) . '"><strong>Reviews</strong></a> — the <code>[gasf_reviews]</code> wall: live Google + TripAdvisor reviews plus hand-curated entries (Facebook).</li>';
	echo '<li><a href="' . $tab( 'fbhealth' ) . '"><strong>FB Token</strong></a> — watchdog for the GASF-Events Facebook feed token: daily live probe, auto-heal, email alert before it can silently die.</li>';
	echo '</ul>';

	echo '<h3>Runs automatically (no tab)</h3><ul style="list-style:disc;margin-left:22px">';
	echo '<li><strong>Shortcodes</strong> — <code>[gas_parking]</code>, <code>[world_cup_schedule]</code>, <code>[bundesliga_table]</code>, <code>[bundesliga_scorers]</code>, <code>[bundesliga_top_scorers]</code>, <code>[gasf_instagram]</code>, <code>[gasf_reviews]</code>. (Event lists, the Welton blurb &amp; the home-page hero are native GASF-Events shortcodes: <code>[gasf_events]</code>, <code>[gasf_upcoming_dates]</code>, <code>[gasf_dinner_events]</code>, <code>[gasf_bayern_events]</code>, <code>[gasf_welton_status]</code>, <code>[gas_hero]</code>.)</li>';
	echo '<li><strong>Schema JSON-LD</strong> — Organization + festival Event markup (migrated from the retired HFCM plugin).</li>';
	echo '<li><strong>Site hardening</strong> — REST user-listing &amp; author-enumeration blocks, misc content 301s.</li>';
	echo '</ul>';

	echo '<p style="color:#666;margin-top:18px">Git-backed (repo <code>GermanTampaBay/GASF-Utilities</code>); deploy = <code>git pull</code> in <code>/home4/germanta/gasf-muplugin</code>. Never edit these files on the server. Feature gates are <code>gasf_site_enable_*</code> / <code>gasf_mec_enable_*</code> options — toggle them on the <a href="' . $tab( 'settings' ) . '">Settings tab</a> (or <code>wp option update &lt;gate&gt; 0</code>). Related but separate: the <strong>GASF-Events</strong> plugin (events calendar, Facebook feed, Eventbrite publishing — see Events &rarr; Feeds and All Events).</p>';
}

// (The old "Event Calendars" tab — gasf_utilities_events_tab — was removed in
// v1.3.0 along with the MEC importer modules; the Settings tab shows gate state.)
