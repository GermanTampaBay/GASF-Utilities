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

/* register the built-in tabs (modules register theirs on admin_menu too;
 * the priority argument is only a fallback — see gasf_utilities_render) */
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
	// Tab order = the Settings registry's order (Overview, Settings, then each
	// group in turn), so the bar reads like the switchboard. A tab the registry
	// does not know falls back to the priority it registered with, at the end.
	$rank = array( 'overview' => 0, 'settings' => 1 );
	if ( function_exists( 'gasf_settings_registry' ) ) {
		$i = 10;
		foreach ( gasf_settings_registry() as $rows ) {
			foreach ( $rows as $row ) { if ( ! empty( $row['tab'] ) ) { $rank[ $row['tab'] ] = $i++; } }
		}
	}
	uksort( $tabs, function ( $ka, $kb ) use ( $rank, $tabs ) {
		return ( $rank[ $ka ] ?? 1000 + $tabs[ $ka ]['priority'] ) <=> ( $rank[ $kb ] ?? 1000 + $tabs[ $kb ]['priority'] );
	} );
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

/* ---------- Overview tab ----------
 * Generated from gasf_settings_registry() (modules/02-settings.php), which
 * is the one list of what this plugin contains. Until 2.8 this was prose
 * written by hand, and it was six releases behind: 9 of 14 tabs linked. */
function gasf_utilities_overview_tab() {
	global $gasf_util_tabs;
	$tabs = is_array( $gasf_util_tabs ) ? $gasf_util_tabs : array();
	$url  = function ( $slug ) { return esc_url( admin_url( 'admin.php?page=gasf-utilities&tab=' . $slug ) ); };

	echo '<p>All custom germantampabay.com functionality lives in this one must-use plugin — every tab above is one self-contained utility, and each opens with a collapsible <strong>&#128214; About this utility</strong> panel explaining what it does, what it needs, and what every field is for. The <a href="' . $url( 'settings' ) . '"><strong>Settings</strong></a> tab is the switchboard: turn any utility on or off and manage the site-wide settings (the Anthropic key and model).</p>';

	if ( ! function_exists( 'gasf_settings_registry' ) ) {
		echo '<p><em>The Settings module is not loaded, so the utility list cannot be shown.</em></p>';
		return;
	}
	foreach ( gasf_settings_registry() as $group => $rows ) {
		echo '<h3>' . wp_kses_post( $group ) . '</h3><ul style="list-style:disc;margin-left:22px">';
		foreach ( $rows as $row ) {
			$on    = gasf_site_enabled( $row['gate'] );
			$slug  = $row['tab'] ?? '';
			$label = '<strong>' . wp_kses_post( $row['label'] ) . '</strong>';
			if ( $slug && $on && isset( $tabs[ $slug ] ) ) { $label = '<a href="' . $url( $slug ) . '">' . $label . '</a>'; }
			echo '<li>' . $label . ( $on ? '' : ' <span style="color:#b32d2e;font-size:11px;font-weight:600;letter-spacing:.4px">OFF</span>' ) . ' — ' . wp_kses_post( $row['desc'] ) . '</li>';
		}
		echo '</ul>';
	}

	echo '<h3>In the GASF-Events plugin, not here</h3><ul style="list-style:disc;margin-left:22px">';
	echo '<li>The events calendar, its feeds (ICS/Facebook &rarr; site calendar and/or Google Calendar), Eventbrite publishing, the home-page heroes, and the event shortcodes <code>[gasf_events]</code>, <code>[gasf_upcoming_dates]</code>, <code>[gasf_dinner_events]</code>, <code>[gasf_bayern_events]</code>, <code>[gasf_welton_status]</code>, <code>[gas_hero]</code> — see Events &rarr; Feeds, Heroes and All Events.</li>';
	echo '</ul>';

	echo '<p style="color:#666;margin-top:18px">Git-backed (repo <code>GermanTampaBay/GASF-Utilities</code>); deploy = <code>git pull</code> in <code>/home4/germanta/gasf-muplugin</code>. Never edit these files on the server. Every row above is a gate option (<code>gasf_site_enable_*</code>) — toggle on the <a href="' . $url( 'settings' ) . '">Settings tab</a> or with <code>wp option update &lt;gate&gt; 0</code>.</p>';
}
