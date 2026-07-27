<?php
/**
 * Email CRM — modules/42-email-crm.php
 *
 * A shared-inbox CRM for info@germantampabay.com. Approved volunteers sign in
 * at /email with Google or Microsoft, see inbound mail, and reply from a web
 * form with a Claude Haiku draft as a starting point.
 *
 * Design contract: docs/EMAIL-CRM-SPEC.md
 *
 * Mail access is Microsoft Graph APP-ONLY against a SHARED mailbox — there is
 * no per-user delegation, because the volunteers' Google/Microsoft identities
 * have no relationship to the M365 tenant. The Graph app is restricted to that
 * one mailbox by an Exchange Application Access Policy scoped to the
 * gasf-crm-scope@germantampabay.com mail-enabled security group. That policy is
 * the security boundary; without it the app registration can read every mailbox
 * in the tenant. Verify with Test-ApplicationAccessPolicy after any app change.
 *
 * This file is only the loader — gate, includes, rewrite rules, cron wiring and
 * schema upgrades. Everything else lives in modules/email-crm/*.php, which the
 * plugin's own modules/*.php glob deliberately does NOT pick up (it is not
 * recursive), so load order stays under our control here.
 *
 * Gate: gasf_site_enable_emailcrm (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_emailcrm' ) : true ) {

	define( 'GASF_CRM_DIR', __DIR__ . '/email-crm' );

	// Schema/rewrite version. Bump when tables or rewrite rules change — the
	// upgrade check below runs dbDelta and flushes rules on any change. This
	// plugin runs as an mu-plugin on the main site, where activation hooks
	// never fire, so a version-compare on every load is the only reliable hook.
	define( 'GASF_CRM_SCHEMA', '1.2.0' );

	require_once GASF_CRM_DIR . '/db.php';
	require_once GASF_CRM_DIR . '/graph.php';
	require_once GASF_CRM_DIR . '/sync.php';
	require_once GASF_CRM_DIR . '/auth.php';
	require_once GASF_CRM_DIR . '/ai.php';
	require_once GASF_CRM_DIR . '/notify.php';
	require_once GASF_CRM_DIR . '/rest.php';
	require_once GASF_CRM_DIR . '/ui.php';
	require_once GASF_CRM_DIR . '/admin.php';

	/**
	 * Module config. The client SECRET lives here too — this file is in a public
	 * repo, so it is read from wp_options at runtime and never written to disk
	 * by anything in this tree. Tenant/client IDs are not secret; the secret is.
	 *
	 * autoload is off (see gasf_crm_save_cfg) so the secret is not loaded into
	 * every single page request just to render the front page.
	 */
	function gasf_crm_cfg() {
		return wp_parse_args( (array) get_option( 'gasf_crm_config', array() ), array(
			'mailbox'        => 'info@germantampabay.com',
			'tenant_id'      => '',
			'client_id'      => '',
			'client_secret'  => '',
			'google_id'      => '',
			'google_secret'  => '',
			'ms_id'          => '',
			'ms_secret'      => '',
			'signature_org'  => 'German-American Society Friendship of Pinellas County',
			'notify_channel' => 'email',
			'last_sync'      => 0,
		) );
	}

	function gasf_crm_save_cfg( array $cfg ) {
		update_option( 'gasf_crm_config', $cfg, false ); // autoload off — holds secrets
	}

	/** True once the Graph side is configured enough to talk to the mailbox. */
	function gasf_crm_ready() {
		$c = gasf_crm_cfg();
		return '' !== $c['tenant_id'] && '' !== $c['client_id'] && '' !== $c['client_secret'];
	}

	/* ---------------------------------------------------------------------
	 * Rewrite rules — /email and its OAuth callbacks.
	 *
	 * A virtual route, not a WordPress page: the spec calls for an unlinked
	 * surface, and a real page would show up in menus, sitemaps, search and the
	 * Pages list where someone would eventually "tidy" it away.
	 * ------------------------------------------------------------------- */
	add_action( 'init', function () {
		add_rewrite_rule( '^email/?$', 'index.php?gasf_crm=app', 'top' );
		add_rewrite_rule( '^email/auth/([a-z]+)/?$', 'index.php?gasf_crm=start&gasf_crm_provider=$matches[1]', 'top' );
		add_rewrite_rule( '^email/auth/([a-z]+)/callback/?$', 'index.php?gasf_crm=callback&gasf_crm_provider=$matches[1]', 'top' );
		add_rewrite_rule( '^email/logout/?$', 'index.php?gasf_crm=logout', 'top' );
	} );

	add_filter( 'query_vars', function ( $vars ) {
		$vars[] = 'gasf_crm';
		$vars[] = 'gasf_crm_provider';
		return $vars;
	} );

	/**
	 * Schema + rewrite upgrade. Cheap option read on every load; the expensive
	 * work only runs when GASF_CRM_SCHEMA changes.
	 */
	add_action( 'init', function () {
		if ( get_option( 'gasf_crm_schema' ) === GASF_CRM_SCHEMA ) { return; }
		gasf_crm_install_tables();
		flush_rewrite_rules( false );
		update_option( 'gasf_crm_schema', GASF_CRM_SCHEMA, false );
		gasf_mec_log( 'CRM: schema/rewrites upgraded to ' . GASF_CRM_SCHEMA );
	}, 20 );

	/* ---------------------------------------------------------------------
	 * Sync cron — hourly, per spec (~4 emails/week; Graph change
	 * notifications would need subscription renewal inside a 3-day window,
	 * which is not worth building at this volume).
	 *
	 * WP-Cron only fires on page traffic, and a club site can sit idle for
	 * hours. A real system cron hitting `wp gasf-crm sync` is the supported
	 * path; this scheduled event is the fallback so the thing still works if
	 * nobody sets that up.
	 * ------------------------------------------------------------------- */
	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_crm_sync_event' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'gasf_crm_sync_event' );
		}
	} );
	add_action( 'gasf_crm_sync_event', 'gasf_crm_sync' );

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'gasf-crm sync', function () {
			$r = gasf_crm_sync();
			WP_CLI::success( sprintf(
				'%d new message(s), %d thread(s) reopened, %d notified.',
				$r['new'], $r['reopened'], $r['notified']
			) );
		} );
	}
}
