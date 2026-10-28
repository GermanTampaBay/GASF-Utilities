<?php
/**
 * Facebook feed token health — modules/35-fb-token-health.php
 *
 * The GASF-Events Facebook feed authenticates with a Page access token stored
 * in the `gasf_events_feeds` option. That token is "non-expiring" (expires_at 0)
 * but can still silently stop working: Facebook's data-access window lapses
 * (~every 14–90 days depending on the app's access level), the page token gets
 * rotated, or the app grant is revoked. When that happened in April the feed
 * died with no warning.
 *
 * This module watches it:
 *   - Daily cron runs a LIVE events probe (the empirical "does it work?" signal).
 *   - If the probe fails and we hold a valid long-lived USER token, it
 *     auto-re-derives a fresh Page token from /me/accounts and writes it back
 *     into the feed (self-heal).
 *   - If it still fails, or the data-access window is about to lapse (only a
 *     human re-auth can fix that), it emails the admin with exact next steps.
 *   - Admin tab shows live status and lets you paste refreshed credentials.
 *
 * Credentials live in option `gasf_fb_health` (autoload off, never emitted to
 * the front end); the probe's results, alert throttle and warning dedupe live
 * in `gasf_fb_health_state`, so a daily check never rewrites the credentials
 * row. Gate: gasf_site_enable_fbhealth (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_fbhealth' ) ) {

	/** Warn this many days before the data-access window lapses. */
	if ( ! defined( 'GASF_FBH_WARN_DAYS' ) ) { define( 'GASF_FBH_WARN_DAYS', 5 ); }
	if ( ! defined( 'GASF_FBH_GRAPH' ) )     { define( 'GASF_FBH_GRAPH', 'https://graph.facebook.com/v19.0/' ); }
	/** The German-American Society Facebook Page (numeric id) — default + fallback for a blanked field. */
	if ( ! defined( 'GASF_FBH_PAGE_ID' ) )   { define( 'GASF_FBH_PAGE_ID', '156837460875' ); }

	function gasf_fbh_cfg() {
		$c = wp_parse_args( (array) get_option( 'gasf_fb_health', array() ), array(
			'app_id'     => '',
			'app_secret' => '',
			'user_token' => '',
			'page_id'    => GASF_FBH_PAGE_ID,
			'email'      => '',
		) );
		if ( '' === $c['email'] ) { $c['email'] = get_option( 'admin_email' ); }
		return $c;
	}

	/** Save credentials/settings only — never the probe state. */
	function gasf_fbh_save( $c ) {
		unset( $c['last'], $c['alert'], $c['warn_key'] );
		update_option( 'gasf_fb_health', $c, false );
	}

	/**
	 * Runtime state (option gasf_fb_health_state): the last probe result, the
	 * failure-alert throttle { key, ts } and the data-access warning dedupe.
	 * Until 2.8 these sat inside the credentials option. Reads the pre-2.8
	 * keys out of it once, until the first state save.
	 */
	function gasf_fbh_state() {
		$st = get_option( 'gasf_fb_health_state', null );
		if ( ! is_array( $st ) ) {
			$legacy = (array) get_option( 'gasf_fb_health', array() );
			$st     = array( 'last' => $legacy['last'] ?? array(), 'alert' => $legacy['alert'] ?? array(), 'warn_key' => $legacy['warn_key'] ?? '' );
		}
		return wp_parse_args( $st, array( 'last' => array(), 'alert' => array(), 'warn_key' => '' ) );
	}
	function gasf_fbh_state_save( $st ) { update_option( 'gasf_fb_health_state', $st, false ); }

	function gasf_fbh_app_token( $c ) {
		return ( $c['app_id'] && $c['app_secret'] ) ? $c['app_id'] . '|' . $c['app_secret'] : '';
	}

	/** GET helper → decoded body array or WP_Error (with HTTP-code check). */
	function gasf_fbh_get( $url, $args ) {
		$full = add_query_arg( array_map( 'rawurlencode', $args ), $url );
		$r    = wp_remote_get( $full, array( 'timeout' => 20 ) );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = (int) wp_remote_retrieve_response_code( $r );
		$body = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $body['error']['message'] ?? ( 'HTTP ' . $code );
			return new WP_Error( 'fb', preg_replace( '/access_token=[^&\s]+/i', 'access_token=REDACTED', (string) $msg ) );
		}
		return is_array( $body ) ? $body : array();
	}

	/** Locate the Facebook feed in gasf_events_feeds; return [index,feed] or [null,null]. */
	function gasf_fbh_find_feed() {
		$feeds = (array) get_option( 'gasf_events_feeds', array() );
		$page  = gasf_fbh_cfg()['page_id'];
		foreach ( $feeds as $i => $f ) {
			if ( ( $f['type'] ?? '' ) === 'facebook' && ( '' === $page || ( $f['page_id'] ?? '' ) === $page ) ) {
				return array( $i, $f );
			}
		}
		return array( null, null );
	}

	/** Write a fresh token (and data-access expiry) back into the feed config. */
	function gasf_fbh_set_feed_token( $token, $expire_at ) {
		$feeds = (array) get_option( 'gasf_events_feeds', array() );
		list( $i ) = gasf_fbh_find_feed();
		if ( null === $i || ! isset( $feeds[ $i ] ) ) { return false; }
		$feeds[ $i ]['access_token'] = $token;
		$feeds[ $i ]['expire_at']    = (int) $expire_at; // surfaces in the GASF-Events Feeds UI too
		update_option( 'gasf_events_feeds', array_values( $feeds ), false );
		return true;
	}

	/** debug_token → data array (is_valid, expires_at, data_access_expires_at, scopes) or WP_Error. */
	function gasf_fbh_debug( $c, $token ) {
		$app = gasf_fbh_app_token( $c );
		if ( ! $app || ! $token ) { return new WP_Error( 'cfg', 'Missing app credentials or token.' ); }
		$b = gasf_fbh_get( GASF_FBH_GRAPH . 'debug_token', array( 'input_token' => $token, 'access_token' => $app ) );
		if ( is_wp_error( $b ) ) { return $b; }
		return (array) ( $b['data'] ?? array() );
	}

	/** Live probe: try to read one upcoming event. Returns [ok(bool), msg, count]. */
	function gasf_fbh_probe( $c, $token ) {
		if ( ! $token ) { return array( false, 'no token', 0 ); }
		$b = gasf_fbh_get( GASF_FBH_GRAPH . rawurlencode( $c['page_id'] ) . '/events', array(
			'fields' => 'id,name,start_time', 'time_filter' => 'upcoming', 'limit' => '3', 'access_token' => $token,
		) );
		if ( is_wp_error( $b ) ) { return array( false, $b->get_error_message(), 0 ); }
		return array( true, 'ok', count( (array) ( $b['data'] ?? array() ) ) );
	}

	/** Re-derive a fresh Page token from the stored long-lived user token. */
	function gasf_fbh_rederive( $c ) {
		if ( ! $c['user_token'] ) { return new WP_Error( 'nouser', 'No user token stored to heal from.' ); }
		$b = gasf_fbh_get( GASF_FBH_GRAPH . 'me/accounts', array( 'access_token' => $c['user_token'] ) );
		if ( is_wp_error( $b ) ) { return $b; }
		foreach ( (array) ( $b['data'] ?? array() ) as $p ) {
			if ( (string) ( $p['id'] ?? '' ) === (string) $c['page_id'] && ! empty( $p['access_token'] ) ) {
				return (string) $p['access_token'];
			}
		}
		return new WP_Error( 'nopage', 'Page not found in /me/accounts (user token may lack access).' );
	}

	function gasf_fbh_email( $c, $subject, $body ) {
		$to = $c['email'] ?: get_option( 'admin_email' );
		if ( $to ) { wp_mail( $to, $subject, $body ); }
	}

	/**
	 * Core check. Returns the status array it also persists under 'last'.
	 * $auto = attempt self-heal + send alert emails (cron path). UI "Check now"
	 * also passes true; a silent status read passes false.
	 */
	function gasf_fbh_check( $auto = true ) {
		$c     = gasf_fbh_cfg();
		$state = gasf_fbh_state();
		list( $idx, $feed ) = gasf_fbh_find_feed();
		$now  = time();
		$st   = array( 'ts' => $now, 'ok' => false, 'msg' => '', 'valid' => null,
			'page_expires_at' => null, 'data_expires_at' => null, 'fetched' => 0, 'healed' => false );

		if ( null === $idx ) {
			$st['msg'] = 'No Facebook feed configured in GASF-Events.';
			$state['last'] = $st; gasf_fbh_state_save( $state );
			return $st;
		}

		$token = (string) ( $feed['access_token'] ?? '' );

		// 1. Empirical probe first — the signal that actually matters.
		list( $ok, $msg, $n ) = gasf_fbh_probe( $c, $token );

		// 2. Self-heal on failure if we can.
		if ( ! $ok && $auto && $c['user_token'] ) {
			$fresh = gasf_fbh_rederive( $c );
			if ( ! is_wp_error( $fresh ) ) {
				$dbg = gasf_fbh_debug( $c, $fresh );
				$exp = is_wp_error( $dbg ) ? 0 : (int) ( $dbg['data_access_expires_at'] ?? 0 );
				gasf_fbh_set_feed_token( $fresh, $exp );
				$token = $fresh;
				list( $ok, $msg, $n ) = gasf_fbh_probe( $c, $token );
				$st['healed'] = $ok;
			}
		}

		// 3. Diagnostics from debug_token (best-effort).
		$dbg = gasf_fbh_debug( $c, $token );
		if ( ! is_wp_error( $dbg ) ) {
			$st['valid']           = ! empty( $dbg['is_valid'] );
			$st['page_expires_at'] = (int) ( $dbg['expires_at'] ?? 0 );
			$st['data_expires_at'] = (int) ( $dbg['data_access_expires_at'] ?? 0 );
		}
		$st['ok'] = $ok; $st['msg'] = $msg; $st['fetched'] = $n;

		// 4. Alerting (throttled) on the cron/auto path.
		if ( $auto ) {
			if ( ! $ok ) {
				$key   = 'fail:' . substr( md5( $msg ), 0, 8 );
				$prev  = (array) $state['alert'];
				$stale = ( ( $prev['key'] ?? '' ) !== $key ) || ( $now - (int) ( $prev['ts'] ?? 0 ) > DAY_IN_SECONDS );
				if ( $stale ) {
					gasf_fbh_email( $c,
						'[GASF] Facebook events feed has STOPPED working',
						"The GASF-Events Facebook feed can no longer read events and could not be auto-healed.\n\n" .
						"Error: {$msg}\n\n" .
						"Fix (≈3 min): generate a fresh long-lived token and paste it in\n" .
						admin_url( 'admin.php?page=gasf-utilities&tab=fbhealth' ) . "\n\n" .
						"1. Graph API Explorer → select your app → Get User Access Token\n" .
						"   (permissions: pages_show_list, pages_read_engagement)\n" .
						"2. Paste that short-lived user token into the 'New user token' box and Save —\n" .
						"   this module exchanges it, re-derives the Page token, and re-enables the feed.\n"
					);
					$state['alert'] = array( 'key' => $key, 'ts' => $now );
				}
			} else {
				$state['alert'] = array(); // recovered → reset failure throttle
				// Early warning: data-access window about to lapse (only re-auth fixes it).
				$de = (int) $st['data_expires_at'];
				if ( $de > 0 && $de - $now < GASF_FBH_WARN_DAYS * DAY_IN_SECONDS ) {
					$wk = 'warn:' . gmdate( 'Y-m-d', $de );
					if ( $state['warn_key'] !== $wk ) {
						$days = max( 0, (int) floor( ( $de - $now ) / DAY_IN_SECONDS ) );
						gasf_fbh_email( $c,
							'[GASF] Facebook feed token needs a refresh soon',
							"Heads up: the Facebook feed still works, but its data-access window lapses in about {$days} day(s) (" . gmdate( 'M j, Y', $de ) . ").\n\n" .
							"Re-authorize before then to avoid an outage:\n" .
							admin_url( 'admin.php?page=gasf-utilities&tab=fbhealth' ) . "\n\n" .
							"Tip: putting the Meta app in Live mode with Advanced Access extends this window to ~90 days so this is far less frequent.\n"
						);
						$state['warn_key'] = $wk;
					}
				} elseif ( $de > 0 && $de - $now >= GASF_FBH_WARN_DAYS * DAY_IN_SECONDS ) {
					$state['warn_key'] = ''; // window refreshed → arm the next warning
				}
			}
		}

		$state['last'] = $st;
		gasf_fbh_state_save( $state );
		return $st;
	}

	/* daily monitor */
	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_fbh_cron' ) ) { wp_schedule_event( time() + 1800, 'daily', 'gasf_fbh_cron' ); }
	} );
	add_action( 'gasf_fbh_cron', function () { gasf_fbh_check( true ); } );

	/* ---- admin ---- */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) { gasf_utilities_add_tab( 'fbhealth', 'FB Token', 'gasf_fbh_admin', 62 ); }
	} );

	function gasf_fbh_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		if ( isset( $_POST['gasf_fbh_action'] ) && check_admin_referer( 'gasf_fbh' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_fbh_action'] ) );
			$c   = gasf_fbh_cfg();
			if ( 'save' === $act ) {
				$c['app_id']  = trim( sanitize_text_field( wp_unslash( $_POST['app_id'] ?? $c['app_id'] ) ) );
				$c['page_id'] = trim( sanitize_text_field( wp_unslash( $_POST['page_id'] ?? $c['page_id'] ) ) ) ?: GASF_FBH_PAGE_ID;
				$c['email']   = sanitize_email( wp_unslash( $_POST['email'] ?? $c['email'] ) ) ?: $c['email'];
				$sec = trim( (string) wp_unslash( $_POST['app_secret'] ?? '' ) );
				if ( '' !== $sec ) { $c['app_secret'] = $sec; }
				$newu = trim( (string) wp_unslash( $_POST['user_token'] ?? '' ) );
				if ( '' !== $newu ) {
					// Exchange short-lived → long-lived if app creds are present; else store as-is.
					$app = gasf_fbh_app_token( $c );
					$stored = $newu;
					if ( $app ) {
						$ex = gasf_fbh_get( GASF_FBH_GRAPH . 'oauth/access_token', array(
							'grant_type' => 'fb_exchange_token', 'client_id' => $c['app_id'],
							'client_secret' => $c['app_secret'], 'fb_exchange_token' => $newu,
						) );
						if ( ! is_wp_error( $ex ) && ! empty( $ex['access_token'] ) ) { $stored = (string) $ex['access_token']; }
						else { echo '<div class="notice notice-warning"><p>Token stored, but the long-lived exchange failed: ' . esc_html( is_wp_error( $ex ) ? $ex->get_error_message() : 'unknown' ) . '</p></div>'; }
					}
					$c['user_token'] = $stored;
				}
				gasf_fbh_save( $c );
				echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
			} elseif ( 'check' === $act ) {
				gasf_fbh_check( true );
				echo '<div class="notice notice-success is-dismissible"><p>Checked.</p></div>';
			} elseif ( 'heal' === $act ) {
				$fresh = gasf_fbh_rederive( gasf_fbh_cfg() );
				if ( is_wp_error( $fresh ) ) {
					echo '<div class="notice notice-error"><p>Re-derive failed: ' . esc_html( $fresh->get_error_message() ) . '</p></div>';
				} else {
					$dbg = gasf_fbh_debug( gasf_fbh_cfg(), $fresh );
					$exp = is_wp_error( $dbg ) ? 0 : (int) ( $dbg['data_access_expires_at'] ?? 0 );
					gasf_fbh_set_feed_token( $fresh, $exp );
					gasf_fbh_check( true );
					echo '<div class="notice notice-success"><p>Re-derived and wrote a fresh Page token into the feed.</p></div>';
				}
			}
		}

		$c   = gasf_fbh_cfg();
		$st  = (array) gasf_fbh_state()['last'];
		list( $idx, $feed ) = gasf_fbh_find_feed();
		$now = time();
		$fmt = function ( $ts ) { return $ts ? esc_html( gmdate( 'M j, Y', (int) $ts ) ) : '—'; };
		$days = function ( $ts ) use ( $now ) { return $ts ? max( 0, (int) floor( ( (int) $ts - $now ) / DAY_IN_SECONDS ) ) . ' days' : '—'; };
		?>
		<h2>Facebook Feed Token Health</h2>
		<?php
		if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
			gasf_utilities_doc_panel( array(
				'what'   => 'A watchdog for the access token the GASF-Events Facebook feed uses to import events. That token can silently stop working (Facebook\'s data-access window lapses, permissions change, password reset) — which is exactly how the feed died unnoticed in April 2026. This module probes the feed daily by <em>actually fetching events</em>, auto-repairs the token when it can, and emails you when only a human re-authorization will fix it.',
				'needs'  => array(
					'The Facebook feed configured in <strong>Events &rarr; Feeds</strong> (that\'s what is being monitored).',
					'Your Meta app\'s <strong>App ID + App Secret</strong> (developers.facebook.com &rarr; your app &rarr; Settings &rarr; Basic) — used to inspect token health via Facebook\'s debug endpoint.',
					'A stored <strong>user token</strong> (below) to enable auto-heal.',
				),
				'fields' => array(
					'Status table'        => 'Live health: whether the last probe actually fetched events, token validity, hard expiry (should read "never"), the <strong>data-access window</strong> end date (the real countdown — when it lapses, only re-auth fixes it; you\'ll get a warning email ~5 days out), and whether auto-heal is armed.',
					'Check now'           => 'Runs the full probe + heal + alert cycle immediately instead of waiting for the daily cron.',
					'Re-derive Page token'=> 'Manually regenerates the feed\'s Page token from the stored user token and writes it into the feed — the same repair auto-heal performs.',
					'Meta App ID / Secret'=> 'Identifies your Meta app to Facebook\'s token-debug API. The secret is stored server-side, shown never; leave blank to keep the saved one.',
					'New user token'      => 'The self-heal fuel. When Facebook makes you re-authorize: Graph API Explorer &rarr; select your app &rarr; Get User Access Token (permissions <code>pages_show_list</code>, <code>pages_read_engagement</code>) &rarr; paste the token here and Save. Short-lived is fine — it\'s exchanged to long-lived and the Page token is re-derived automatically.',
					'Page ID'             => 'The numeric Facebook Page being monitored (the German-American Society page). Only change if the page itself changes.',
					'Alert email'         => 'Where failure and expiry-warning emails go. Alerts are throttled — one per distinct problem per day, not a daily nag.',
				),
				'notes'  => 'The alert emails contain the exact step-by-step fix, so future-you doesn\'t need to remember any of this. Putting the Meta app in Live mode with Advanced Access stretches the data-access window from ~14 to ~90 days (quarterly instead of biweekly re-auth).',
			) );
		}
		?>

		<table class="widefat striped" style="max-width:720px">
			<tr><td>Feed</td><td><?php echo null === $idx ? '<strong style="color:#b32d2e">none configured</strong>' : esc_html( $feed['label'] ?? '' ) . ' — ' . ( ! empty( $feed['enabled'] ) ? 'enabled' : '<em>disabled</em>' ); ?></td></tr>
			<tr><td>Live events probe</td><td><?php echo ! empty( $st['ok'] ) ? '<span style="color:#207520">✓ working</span> (' . (int) ( $st['fetched'] ?? 0 ) . ' upcoming fetched)' : '<span style="color:#b32d2e">✗ ' . esc_html( $st['msg'] ?? 'not checked' ) . '</span>'; ?></td></tr>
			<tr><td>Page token valid</td><td><?php echo isset( $st['valid'] ) ? ( $st['valid'] ? 'yes' : 'no' ) : '—'; ?></td></tr>
			<tr><td>Page token hard expiry</td><td><?php echo ( isset( $st['page_expires_at'] ) && 0 === (int) $st['page_expires_at'] ) ? 'never' : $fmt( $st['page_expires_at'] ?? 0 ); ?></td></tr>
			<tr><td>Data-access window ends</td><td><?php $de = (int) ( $st['data_expires_at'] ?? 0 ); $soon = $de && ( $de - $now < GASF_FBH_WARN_DAYS * DAY_IN_SECONDS ); echo '<span style="color:' . ( $soon ? '#b32d2e' : 'inherit' ) . '">' . $fmt( $de ) . ' (' . $days( $de ) . ' left)</span>'; ?></td></tr>
			<tr><td>Stored user token (self-heal)</td><td><?php echo $c['user_token'] ? 'present' : '<em>none — auto-heal disabled</em>'; ?></td></tr>
			<tr><td>Last checked</td><td><?php echo ! empty( $st['ts'] ) ? esc_html( human_time_diff( (int) $st['ts'] ) ) . ' ago' . ( ! empty( $st['healed'] ) ? ' (auto-healed)' : '' ) : 'never'; ?></td></tr>
			<tr><td>Alert email</td><td><?php echo esc_html( $c['email'] ); ?></td></tr>
		</table>

		<form method="post" style="margin-top:1em">
			<?php wp_nonce_field( 'gasf_fbh' ); ?>
			<p>
				<button name="gasf_fbh_action" value="check" class="button button-primary">Check now</button>
				<button name="gasf_fbh_action" value="heal" class="button">Re-derive Page token from user token</button>
			</p>
		</form>

		<h3 class="title">Credentials</h3>
		<form method="post">
			<?php wp_nonce_field( 'gasf_fbh' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Meta App ID</th><td><input type="text" name="app_id" value="<?php echo esc_attr( $c['app_id'] ); ?>" class="regular-text code"></td></tr>
				<tr><th scope="row">Meta App Secret</th><td><input type="text" name="app_secret" value="" class="regular-text code" placeholder="<?php echo $c['app_secret'] ? 'saved — leave blank to keep' : 'app secret'; ?>"></td></tr>
				<tr><th scope="row">New user token</th><td><textarea name="user_token" rows="3" class="large-text code" placeholder="<?php echo $c['user_token'] ? 'saved — paste a fresh short/long-lived USER token to replace' : 'paste a User access token (pages_show_list, pages_read_engagement)'; ?>"></textarea><p class="description">Short-lived is fine — it's exchanged to long-lived automatically, then the Page token is re-derived from it.</p></td></tr>
				<tr><th scope="row">Page ID</th><td><input type="text" name="page_id" value="<?php echo esc_attr( $c['page_id'] ); ?>" class="regular-text code"></td></tr>
				<tr><th scope="row">Alert email</th><td><input type="email" name="email" value="<?php echo esc_attr( $c['email'] ); ?>" class="regular-text"></td></tr>
			</table>
			<p><button name="gasf_fbh_action" value="save" class="button button-primary">Save</button></p>
		</form>
		<?php
	}
}
