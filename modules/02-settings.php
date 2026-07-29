<?php
/**
 * Module 02 — Settings tab: master on/off switches for every utility, plus
 * site-wide ("global") settings shared by more than one module.
 *
 * - The toggles read/write the existing gate options (gasf_site_enable_* /
 *   gasf_mec_enable_*; '0' = off, anything else / unset = on), so
 *   `wp option update <gate> 0` keeps working and nothing about the gate
 *   system changes — this is just the UI it never had.
 * - Per-utility configuration stays on each utility's own tab (Instagram
 *   connection on the Instagram tab, review keys on the Reviews tab, …).
 *   Only settings that affect more than one module belong here.
 * - Global settings: the Anthropic (Claude) API key — option
 *   `gasf_anthropic_key`, read via gasf_anthropic_key(), which falls back to
 *   the legacy per-module key inside `gasf_aiseo_config` so existing installs
 *   keep working untouched.
 *
 * Gates are evaluated when the plugin loads, so a toggle takes effect on the
 * NEXT page load; turning a utility off also removes its admin tab (this tab
 * itself is ungated and always available to turn it back on).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-wide Anthropic (Claude) API key. Set on the Settings tab; falls back
 * to the legacy AI-SEO per-module key so nothing breaks on upgrade.
 */
if ( ! function_exists( 'gasf_anthropic_key' ) ) {
	function gasf_anthropic_key() {
		$k = (string) get_option( 'gasf_anthropic_key', '' );
		if ( '' === $k ) {
			$legacy = (array) get_option( 'gasf_aiseo_config', array() );
			$k      = isset( $legacy['key'] ) ? (string) $legacy['key'] : '';
		}
		return $k;
	}
}

/**
 * Registry of every gated utility: group => rows of
 * { gate, label, desc, tab? }. The gate list must match what the modules
 * actually check — grep gasf_site_enable_ / gasf_mec_enable_ when adding one.
 */
function gasf_settings_registry() {
	return array(
		'Content & home page' => array(
			// Home Page Hero + Recurring Heroes moved to the GASF-Events plugin
			// (Events → Heroes / Recurring Heroes; gate on Events → Settings).
			array( 'gate' => 'gasf_site_enable_parking', 'label' => 'Parking block', 'desc' => 'The <code>[gas_parking]</code> "Getting Here, Parking &amp; Transit" block for event pages.' ),
			array( 'gate' => 'gasf_mec_enable_wc_schedule', 'label' => 'World Cup schedule', 'desc' => 'The <code>[world_cup_schedule]</code> watch-party schedule.' ),
		),
		'Sports shortcodes' => array(
			array( 'gate' => 'gasf_site_enable_bundesliga', 'label' => 'Bundesliga tables &amp; scorers', 'desc' => 'One gate for <code>[bundesliga_table]</code>, <code>[bundesliga_scorers]</code> and <code>[bundesliga_top_scorers]</code> (OpenLigaDB, no key needed).' ),
		),
		'Search & links' => array(
			array( 'gate' => 'gasf_site_enable_seo', 'label' => 'SEO engine', 'desc' => 'Titles, meta descriptions, canonical/robots, OpenGraph/Twitter, JSON-LD, per-page SEO box — the Yoast replacement.', 'tab' => 'seo' ),
			array( 'gate' => 'gasf_site_enable_aiseo', 'label' => 'Event SEO (AI)', 'desc' => 'Claude writes meta descriptions for events that lack one. Uses the site-wide Anthropic API key below.', 'tab' => 'aiseo' ),
			array( 'gate' => 'gasf_site_enable_shortlinks', 'label' => 'Short Links', 'desc' => 'Branded short URLs (<code>/join</code>, <code>/75th</code>…) with click counts.', 'tab' => 'shortlinks' ),
			array( 'gate' => 'gasf_site_enable_redirects', 'label' => 'Redirects &amp; 404 log', 'desc' => '301/302/410 manager plus the live 404 monitor.', 'tab' => 'redirects' ),
		),
		'Calendars' => array(
			array( 'gate' => 'gasf_site_enable_gcalprint', 'label' => 'Internal Calendar (print)', 'desc' => 'Secret-link printable month view of the internal Google Calendar. (The ICS&rarr;Google sync itself lives in GASF-Events &rarr; Feeds.)', 'tab' => 'gcal-print' ),
		),
		'Social & reputation' => array(
			array( 'gate' => 'gasf_site_enable_instagram', 'label' => 'Instagram feed', 'desc' => 'The native <code>[gasf_instagram]</code> feed. The source account/token and display defaults live on the Instagram tab.', 'tab' => 'instagram' ),
			array( 'gate' => 'gasf_site_enable_reviews', 'label' => 'Reviews wall', 'desc' => 'The <code>[gasf_reviews]</code> Google + TripAdvisor + curated reviews wall. API keys live on the Reviews tab.', 'tab' => 'reviews' ),
			array( 'gate' => 'gasf_site_enable_fbhealth', 'label' => 'FB token watchdog', 'desc' => 'Daily probe + auto-heal + email alert for the GASF-Events Facebook feed token.', 'tab' => 'fbhealth' ),
			array( 'gate' => 'gasf_site_enable_fbshare', 'label' => 'FB &rarr; Blog importer', 'desc' => 'Hourly scan of the Facebook page for posts tagged with the trigger hashtag (default <code>#gasfweb</code>); each becomes a WordPress post with its photos. Borrows the GASF-Events feed token.', 'tab' => 'fbshare' ),
			array( 'gate' => 'gasf_site_enable_recentposts', 'label' => 'Recent posts rail', 'desc' => 'The <code>[gasf_recent_posts]</code> "Latest News" card list on the home-page right rail — surfaces the FB-imported blog posts. Purges the page cache when a post publishes.' ),
		),
		'Site hardening & performance' => array(
			array( 'gate' => 'gasf_site_enable_restenum', 'label' => 'REST user-enumeration block', 'desc' => 'Blocks REST API user listing / author enumeration.' ),
			array( 'gate' => 'gasf_site_enable_schema', 'label' => 'Schema JSON-LD', 'desc' => 'Organization + festival Event structured data in <code>&lt;head&gt;</code>.' ),
			array( 'gate' => 'gasf_site_enable_assetdiet', 'label' => 'Asset diet', 'desc' => 'Drops jQuery Migrate, scopes dFlip to its page, self-hosts theme fonts.' ),
			array( 'gate' => 'gasf_site_enable_perf', 'label' => 'Performance (LCP &amp; images)', 'desc' => 'Hero preload + right-sizing, image CDN preconnect — the mobile-LCP fixes.' ),
			array( 'gate' => 'gasf_site_enable_imgcompress', 'label' => 'Image compressor', 'desc' => 'Converts oversized JPEG/PNG uploads to WebP on this server (no quota, no size limit) and rewrites all references. On-demand + optional 4-hourly cron.', 'tab' => 'images' ),
			array( 'gate' => 'gasf_site_enable_deferjs', 'label' => 'Defer JavaScript', 'desc' => 'Part of the Performance module: adds <code>defer</code> to front-end scripts. Toggle off first if a slider/menu misbehaves.' ),
		),
	);
	// The MEC-importer-era gates (gasf_mec_enable_cron/defaults/window/recurrence/
	// sweep/single_template/fb_refresh/feierabend, gasf_mec_sweep_dryrun,
	// gasf_site_enable_us_branded) were removed with their modules in v1.3.0.
	// gasf_mec_enable_welton + gasf_mec_enable_dinner_events went in v1.5.0, and
	// gasf_mec_enable_bayern_events in v1.6.0 — GASF-Events' native
	// [gasf_welton_status] / [gasf_dinner_events] / [gasf_bayern_events] replaced them.
}

add_action( 'admin_menu', function () {
	if ( function_exists( 'gasf_utilities_add_tab' ) ) {
		gasf_utilities_add_tab( 'settings', 'Settings', 'gasf_settings_tab', 2 );
	}
} );

function gasf_settings_tab() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }

	if ( isset( $_POST['gasf_settings_action'] ) && check_admin_referer( 'gasf_settings' ) ) {
		$act = sanitize_text_field( wp_unslash( $_POST['gasf_settings_action'] ) );

		if ( 'save_gates' === $act ) {
			$checked = isset( $_POST['gate'] ) && is_array( $_POST['gate'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['gate'] ) ) ) : array();
			$changed = 0;
			foreach ( gasf_settings_registry() as $rows ) {
				foreach ( $rows as $row ) {
					$new = in_array( $row['gate'], $checked, true ) ? '1' : '0';
					if ( gasf_mec_enabled( $row['gate'] ) !== ( '1' === $new ) ) { $changed++; }
					update_option( $row['gate'], $new );
				}
			}
			echo '<div class="notice notice-success is-dismissible"><p>Saved' . ( $changed ? ' — ' . (int) $changed . ' toggle(s) changed' : '' ) . '. Toggles take effect on the next page load; a disabled utility\'s tab disappears until re-enabled here. Flush the page cache if a front-end change doesn\'t show.</p></div>';
		}

		if ( 'save_global' === $act ) {
			if ( ! empty( $_POST['anthropic_key_clear'] ) ) {
				delete_option( 'gasf_anthropic_key' );
				// Blank the legacy fallback too, or the old key would silently resurrect.
				$legacy = (array) get_option( 'gasf_aiseo_config', array() );
				if ( ! empty( $legacy['key'] ) ) {
					$legacy['key'] = '';
					update_option( 'gasf_aiseo_config', $legacy, false );
				}
				echo '<div class="notice notice-success is-dismissible"><p>Anthropic API key removed.</p></div>';
			} else {
				$newkey = trim( sanitize_text_field( wp_unslash( $_POST['anthropic_key'] ?? '' ) ) );
				if ( '' !== $newkey ) {
					update_option( 'gasf_anthropic_key', $newkey, false ); // autoload off: admin/cron use only
					echo '<div class="notice notice-success is-dismissible"><p>Anthropic API key saved.</p></div>';
				} else {
					echo '<div class="notice notice-info is-dismissible"><p>No changes — leaving the key blank keeps the saved one.</p></div>';
				}
			}
		}
	}

	echo '<h2>Settings</h2>';
	if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
		gasf_utilities_doc_panel( array(
			'what'   => 'The master switchboard: turn each utility on or off, and manage the few <em>site-wide</em> settings shared by more than one module. Per-utility configuration (the Instagram account, review API keys, calendar sources, …) stays on that utility\'s own tab — the <strong>Open tab</strong> links jump straight there.',
			'needs'  => array( 'Nothing — this tab is always available, even when every other utility is off.' ),
			'fields' => array(
				'Utility toggles'      => 'ON = the module loads. Toggles apply from the next page load; disabling a utility also removes its admin tab (come back here to restore it). Under the hood each toggle is the module\'s existing gate option, so <code>wp option update &lt;gate&gt; 0</code> still works.',
				'Anthropic API key'    => 'The site-wide Claude key (<code>sk-ant-…</code> from console.anthropic.com), used by Event SEO (AI) and any future AI utility. Stored server-side, never shown again — the field stays blank once saved; type only to replace, or tick "remove" to delete it.',
			),
			'notes'  => 'This page manages <em>this site\'s</em> gates only — the main site and the Krampus install each keep their own switchboard (the Krampus copy runs with most main-site utilities off).',
		) );
	}

	/* ---------- global settings ---------- */
	$key_set = gasf_anthropic_key() !== '';
	?>
	<h3 class="title">Global settings <span style="font-weight:400;color:#646970">(shared by more than one utility)</span></h3>
	<form method="post">
		<?php wp_nonce_field( 'gasf_settings' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Anthropic (Claude) API key</th>
				<td>
					<?php echo $key_set ? '<span style="color:#1a7f37">● saved</span>' : '<span style="color:#b3122b">○ not set</span>'; ?>
					<input type="text" name="anthropic_key" value="" class="regular-text code" style="margin-left:8px" placeholder="<?php echo $key_set ? 'saved — leave blank to keep' : 'sk-ant-…'; ?>" autocomplete="off">
					<label style="margin-left:10px"><input type="checkbox" name="anthropic_key_clear" value="1"> remove saved key</label>
					<p class="description">Used by <strong>Event SEO (AI)</strong> (and any future Claude-powered utility). Stored server-side only.</p>
				</td>
			</tr>
		</table>
		<p><button name="gasf_settings_action" value="save_global" class="button button-primary">Save global settings</button></p>
	</form>

	<?php /* ---------- utility toggles ---------- */ ?>
	<h3 class="title">Utilities on / off</h3>
	<p class="description" style="max-width:760px">Applies from the next page load. Disabling a utility removes its shortcodes/behaviour <em>and</em> its admin tab; its saved settings are kept, so re-enabling restores it exactly as it was.</p>
	<form method="post">
		<?php wp_nonce_field( 'gasf_settings' ); ?>
		<?php foreach ( gasf_settings_registry() as $group => $rows ) : ?>
			<h4 style="margin:18px 0 6px"><?php echo wp_kses_post( $group ); ?></h4>
			<table class="widefat striped" style="max-width:960px">
				<tbody>
				<?php foreach ( $rows as $row ) :
					$on = gasf_mec_enabled( $row['gate'] ); ?>
					<tr>
						<td style="width:60px;text-align:center">
							<input type="checkbox" name="gate[<?php echo esc_attr( $row['gate'] ); ?>]" value="1" <?php checked( $on ); ?> title="<?php echo esc_attr( $row['gate'] ); ?>">
						</td>
						<td style="width:240px"><strong><?php echo wp_kses_post( $row['label'] ); ?></strong><br><code style="font-size:11px;color:#646970"><?php echo esc_html( $row['gate'] ); ?></code></td>
						<td><?php echo wp_kses_post( $row['desc'] ); ?></td>
						<td style="width:110px"><?php if ( ! empty( $row['tab'] ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=gasf-utilities&tab=' . $row['tab'] ) ); ?>">Open tab &rarr;</a>
						<?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
		<p style="margin-top:14px"><button name="gasf_settings_action" value="save_gates" class="button button-primary">Save toggles</button></p>
	</form>
	<?php
}

/**
 * Gate-off cron reconciliation. A module toggled OFF no longer loads, so it
 * can never clear its own recurring cron — the orphan fires forever with no
 * handler (the same ghost-cron class as the retired gasf_calsync_cron).
 * This file is ungated and always loads, so it clears any gated module's
 * cron whenever that module's gate is off. (38-image-compress reconciles
 * its own cron against its 'cron' setting and isn't listed here.)
 */
add_action( 'init', function () {
	$map = array(
		'gasf_site_enable_instagram' => 'gasf_ig_cron',
		'gasf_site_enable_reviews'   => 'gasf_reviews_cron',
		'gasf_site_enable_fbhealth'  => 'gasf_fbh_cron',
		'gasf_site_enable_aiseo'     => 'gasf_aiseo_cron',
	);
	foreach ( $map as $gate => $hook ) {
		if ( function_exists( 'gasf_site_enabled' ) && ! gasf_site_enabled( $gate ) && wp_next_scheduled( $hook ) ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
	// (Heroes moved to GASF-Events; it owns the gasf_hero_recurring_cron lifecycle now.)
} );
