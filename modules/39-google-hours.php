<?php
/**
 * Google Business Profile Hours — modules/39-google-hours.php
 *
 * Edits the club's opening hours on Google Maps / Search via the Business
 * Profile API family (approval-gated; this project is approved):
 *   - My Business Account Management API  → accounts.list (one-time discovery)
 *   - My Business Business Information API → locations get/patch
 *     (regularHours = the weekly schedule; specialHours = date-specific
 *     overrides like holiday closures or late World Cup nights).
 *
 * Auth: the Business Profile API does not support service accounts — it
 * needs OAuth as a user who manages the profile. One-time Connect flow on
 * the admin tab (client id/secret from the approved Cloud project) stores a
 * refresh token; access tokens are minted on demand and cached ~55 min.
 * Redirect URI to register in the Cloud Console OAuth client:
 *   <admin_url>/admin-post.php?action=gasf_ghours_oauth
 *
 * Gate: gasf_site_enable_ghours (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_ghours' ) : true ) {

	define( 'GASF_GH_INFO', 'https://mybusinessbusinessinformation.googleapis.com/v1' );
	define( 'GASF_GH_ACCT', 'https://mybusinessaccountmanagement.googleapis.com/v1' );
	define( 'GASF_GH_DAYS', 'MONDAY,TUESDAY,WEDNESDAY,THURSDAY,FRIDAY,SATURDAY,SUNDAY' );

	function gasf_gh_cfg() {
		return wp_parse_args( (array) get_option( 'gasf_ghours_config', array() ), array(
			'client_id' => '', 'client_secret' => '', 'refresh_token' => '',
			'account' => '', 'location' => '', 'location_title' => '',
		) );
	}
	function gasf_gh_save( $c ) { update_option( 'gasf_ghours_config', $c, false ); }
	function gasf_gh_redirect_uri() { return admin_url( 'admin-post.php' ) . '?action=gasf_ghours_oauth'; }

	/** Mint (and cache) an access token from the stored refresh token. */
	function gasf_gh_token() {
		$t = get_transient( 'gasf_ghours_at' );
		if ( is_string( $t ) && '' !== $t ) { return $t; }
		$c = gasf_gh_cfg();
		if ( ! $c['refresh_token'] ) { return new WP_Error( 'noauth', 'Not connected to Google yet.' ); }
		$r = wp_remote_post( 'https://oauth2.googleapis.com/token', array( 'timeout' => 20, 'body' => array(
			'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
			'refresh_token' => $c['refresh_token'], 'grant_type' => 'refresh_token',
		) ) );
		if ( is_wp_error( $r ) ) { return $r; }
		$b = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( empty( $b['access_token'] ) ) { return new WP_Error( 'token', 'Token refresh failed: ' . substr( wp_remote_retrieve_body( $r ), 0, 160 ) ); }
		set_transient( 'gasf_ghours_at', $b['access_token'], max( 300, (int) ( $b['expires_in'] ?? 3600 ) - 300 ) );
		return $b['access_token'];
	}

	/** Authenticated JSON call. Returns decoded array or WP_Error. */
	function gasf_gh_api( $method, $url, $body = null ) {
		$t = gasf_gh_token();
		if ( is_wp_error( $t ) ) { return $t; }
		$args = array( 'method' => $method, 'timeout' => 25, 'headers' => array(
			'Authorization' => 'Bearer ' . $t, 'Content-Type' => 'application/json',
		) );
		if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); }
		$r = wp_remote_request( $url, $args );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = (int) wp_remote_retrieve_response_code( $r );
		$b    = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'gh_' . $code, 'Google API HTTP ' . $code . ': ' . ( $b['error']['message'] ?? substr( wp_remote_retrieve_body( $r ), 0, 200 ) ) );
		}
		return is_array( $b ) ? $b : array();
	}

	/* ---------------- OAuth connect (one-time) ---------------- */

	function gasf_gh_connect_url() {
		$c = gasf_gh_cfg();
		return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array(
			'client_id'     => $c['client_id'],
			'redirect_uri'  => gasf_gh_redirect_uri(),
			'response_type' => 'code',
			'scope'         => 'https://www.googleapis.com/auth/business.manage',
			'access_type'   => 'offline',
			'prompt'        => 'consent', // force a refresh_token on every connect
			'state'         => wp_create_nonce( 'gasf_gh_oauth' ),
		) );
	}

	add_action( 'admin_post_gasf_ghours_oauth', function () {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Nope.' ); }
		if ( ! wp_verify_nonce( (string) ( $_GET['state'] ?? '' ), 'gasf_gh_oauth' ) ) { wp_die( 'Bad state.' ); }
		$back = admin_url( 'admin.php?page=gasf-utilities&tab=ghours' );
		$code = (string) ( $_GET['code'] ?? '' );
		if ( '' === $code ) { wp_safe_redirect( $back . '&gh_err=' . rawurlencode( (string) ( $_GET['error'] ?? 'no code' ) ) ); exit; }
		$c = gasf_gh_cfg();
		$r = wp_remote_post( 'https://oauth2.googleapis.com/token', array( 'timeout' => 20, 'body' => array(
			'code' => $code, 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
			'redirect_uri' => gasf_gh_redirect_uri(), 'grant_type' => 'authorization_code',
		) ) );
		$b = is_wp_error( $r ) ? array() : json_decode( wp_remote_retrieve_body( $r ), true );
		if ( empty( $b['refresh_token'] ) ) {
			wp_safe_redirect( $back . '&gh_err=' . rawurlencode( 'No refresh token returned: ' . substr( is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_body( $r ), 0, 140 ) ) );
			exit;
		}
		$c['refresh_token'] = (string) $b['refresh_token'];
		gasf_gh_save( $c );
		delete_transient( 'gasf_ghours_at' );
		if ( ! empty( $b['access_token'] ) ) { set_transient( 'gasf_ghours_at', $b['access_token'], 3000 ); }

		// Discovery: account → locations; auto-pick when there's exactly one.
		$acc = gasf_gh_api( 'GET', GASF_GH_ACCT . '/accounts' );
		if ( ! is_wp_error( $acc ) && ! empty( $acc['accounts'][0]['name'] ) ) {
			$c['account'] = (string) $acc['accounts'][0]['name'];
			$loc = gasf_gh_api( 'GET', GASF_GH_INFO . '/' . $c['account'] . '/locations?readMask=name,title' );
			if ( ! is_wp_error( $loc ) && ! empty( $loc['locations'] ) && 1 === count( $loc['locations'] ) ) {
				$c['location']       = (string) $loc['locations'][0]['name'];
				$c['location_title'] = (string) ( $loc['locations'][0]['title'] ?? '' );
			}
			gasf_gh_save( $c );
		}
		wp_safe_redirect( $back . '&gh_ok=1' );
		exit;
	} );

	/* ---------------- hours helpers ---------------- */

	/** "HH:MM" → TimeOfDay array. "24:00" (midnight close) supported. */
	function gasf_gh_tod( $s ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( (string) $s ), $m ) ) { return null; }
		return array( 'hours' => (int) $m[1], 'minutes' => (int) $m[2] );
	}
	function gasf_gh_tod_str( $t ) {
		if ( ! is_array( $t ) ) { return ''; }
		return sprintf( '%02d:%02d', (int) ( $t['hours'] ?? 0 ), (int) ( $t['minutes'] ?? 0 ) );
	}

	/* ---------------- admin tab ---------------- */

	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) { gasf_utilities_add_tab( 'ghours', 'Google Hours', 'gasf_gh_admin', 66 ); }
	} );

	function gasf_gh_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$days = explode( ',', GASF_GH_DAYS );

		if ( isset( $_POST['gasf_gh_action'] ) && check_admin_referer( 'gasf_gh_admin' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_gh_action'] ) );
			$c   = gasf_gh_cfg();
			if ( 'creds' === $act ) {
				$c['client_id']     = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
				$secret             = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
				if ( '' !== $secret ) { $c['client_secret'] = $secret; } // blank = keep existing
				gasf_gh_save( $c );
				echo '<div class="notice notice-success is-dismissible"><p>Credentials saved. Now click <strong>Connect Google account</strong> below.</p></div>';
			} elseif ( 'disconnect' === $act ) {
				$c['refresh_token'] = ''; $c['account'] = ''; $c['location'] = ''; $c['location_title'] = '';
				gasf_gh_save( $c ); delete_transient( 'gasf_ghours_at' );
				echo '<div class="notice notice-success is-dismissible"><p>Disconnected.</p></div>';
			} elseif ( 'pick_location' === $act ) {
				$c['location']       = sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) );
				$c['location_title'] = sanitize_text_field( wp_unslash( $_POST['location_title'] ?? '' ) );
				gasf_gh_save( $c );
				echo '<div class="notice notice-success is-dismissible"><p>Location selected.</p></div>';
			} elseif ( 'save_regular' === $act && $c['location'] ) {
				// Validate the whole week BEFORE any PATCH. A day whose times fail
				// to parse used to be silently dropped from the periods list — and
				// to Google, an omitted day IS a closed day. Mistype one time and
				// the club publishes itself closed that day, under a success
				// notice. Whole-request rejection, never a partial push.
				$periods = array();
				$bad     = array();
				foreach ( $days as $d ) {
					if ( ! empty( $_POST[ 'closed_' . $d ] ) ) { continue; }
					$open  = gasf_gh_tod( wp_unslash( $_POST[ 'open_' . $d ] ?? '' ) );
					$close = gasf_gh_tod( wp_unslash( $_POST[ 'close_' . $d ] ?? '' ) );
					if ( ! $open || ! $close ) {
						$bad[] = ucfirst( strtolower( $d ) );
						continue;
					}
					$periods[] = array( 'openDay' => $d, 'openTime' => $open, 'closeDay' => $d, 'closeTime' => $close );
				}
				if ( $bad ) {
					echo '<div class="notice notice-error"><p>Nothing was pushed to Google. These day(s) have missing or invalid times: <strong>'
						. esc_html( implode( ', ', $bad ) )
						. '</strong>. Enter both times as HH:MM, or tick <em>Closed</em> for that day.</p></div>';
				} else {
					$res = gasf_gh_api( 'PATCH', GASF_GH_INFO . '/' . $c['location'] . '?updateMask=regularHours', array( 'regularHours' => array( 'periods' => $periods ) ) );
					echo is_wp_error( $res )
						? '<div class="notice notice-error"><p>' . esc_html( $res->get_error_message() ) . '</p></div>'
						: '<div class="notice notice-success is-dismissible"><p>Weekly hours pushed to Google — Maps/Search usually reflect it within minutes.</p></div>';
				}
			} elseif ( 'save_special' === $act && $c['location'] ) {
				// One override per line: "2026-12-24 closed" or "2026-12-24 11:00-15:00".
				$rows = array(); $bad = array();
				foreach ( preg_split( '/[\r\n]+/', (string) wp_unslash( $_POST['special'] ?? '' ) ) as $line ) {
					$line = trim( $line );
					if ( '' === $line ) { continue; }
					if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})\s+closed$/i', $line, $m ) ) {
						$rows[] = array( 'startDate' => array( 'year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3] ), 'closed' => true );
					} elseif ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})\s+(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/', $line, $m ) ) {
						$rows[] = array(
							'startDate' => array( 'year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3] ),
							'openTime'  => gasf_gh_tod( $m[4] ), 'closeTime' => gasf_gh_tod( $m[5] ),
						);
					} else { $bad[] = $line; }
				}
				if ( $bad ) {
					echo '<div class="notice notice-error"><p>Unparsed line(s), nothing pushed: <code>' . esc_html( implode( ' | ', array_slice( $bad, 0, 3 ) ) ) . '</code></p></div>';
				} else {
					$res = gasf_gh_api( 'PATCH', GASF_GH_INFO . '/' . $c['location'] . '?updateMask=specialHours', array( 'specialHours' => array( 'specialHourPeriods' => $rows ) ) );
					echo is_wp_error( $res )
						? '<div class="notice notice-error"><p>' . esc_html( $res->get_error_message() ) . '</p></div>'
						: '<div class="notice notice-success is-dismissible"><p>' . (int) count( $rows ) . ' special-hours override(s) pushed (an empty list clears them all).</p></div>';
				}
			}
		}
		if ( isset( $_GET['gh_ok'] ) )  { echo '<div class="notice notice-success is-dismissible"><p>Google account connected.</p></div>'; }
		if ( isset( $_GET['gh_err'] ) ) { echo '<div class="notice notice-error"><p>Connect failed: ' . esc_html( sanitize_text_field( wp_unslash( $_GET['gh_err'] ) ) ) . '</p></div>'; }

		$c = gasf_gh_cfg();
		?>
		<h2>Google Business Profile — Opening Hours</h2>
		<?php
		if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
			gasf_utilities_doc_panel( array(
				'what'   => 'Edits the club\'s opening hours on <strong>Google Maps and Google Search</strong> directly from here — the weekly schedule (regular hours) and date-specific overrides (special hours: holiday closures, late event nights). Changes usually appear on Google within minutes.',
				'needs'  => array(
					'The <strong>approved</strong> Google Cloud project (Business Profile API access is application-gated — this project has it).',
					'Both APIs enabled in that project: <em>My Business Business Information API</em> + <em>My Business Account Management API</em>.',
					'An OAuth client (Web application) in that project with redirect URI <code>' . esc_html( gasf_gh_redirect_uri() ) . '</code>; paste its Client ID/Secret below.',
					'One-time <em>Connect</em> as a Google account that manages the Business Profile.',
				),
				'fields' => array(
					'Client ID / Secret' => 'From the Cloud Console OAuth client. The secret is stored server-side (autoload off) and never shown again.',
					'Connect'            => 'One-time Google consent as a profile manager/owner. Stores a refresh token; access tokens self-renew after that.',
					'Weekly hours'       => 'The standing schedule. One open/close range per day; check Closed for dark days. Use 24:00 to close at midnight.',
					'Special hours'      => 'Date overrides, one per line: <code>2026-12-24 closed</code> or <code>2026-07-19 12:00-23:00</code>. Pushing REPLACES the full override list (empty = clear all). Past dates are ignored by Google automatically.',
				),
				'notes'  => 'Google does not support service accounts for this API — that\'s why the one-time user consent. If the connected manager account loses access to the profile, pushes fail with 403: reconnect as a current manager.',
			) );
		}
		?>
		<h3 class="title">Connection</h3>
		<table class="widefat striped" style="max-width:680px">
			<tr><td>OAuth client</td><td><?php echo $c['client_id'] ? '<span style="color:#1a7f37">● saved</span> <code style="font-size:11px">' . esc_html( substr( $c['client_id'], 0, 24 ) ) . '…</code>' : '<span style="color:#b3122b">○ not set</span>'; ?></td></tr>
			<tr><td>Google account</td><td><?php echo $c['refresh_token'] ? '<span style="color:#1a7f37">● connected</span>' : '<span style="color:#b3122b">○ not connected</span>'; ?></td></tr>
			<tr><td>Location</td><td><?php echo $c['location'] ? esc_html( $c['location_title'] ?: $c['location'] ) . ' <code style="font-size:11px">' . esc_html( $c['location'] ) . '</code>' : '—'; ?></td></tr>
		</table>

		<form method="post" style="margin-top:10px">
			<?php wp_nonce_field( 'gasf_gh_admin' ); ?>
			<p>
				<input type="text" name="client_id" value="<?php echo esc_attr( $c['client_id'] ); ?>" class="regular-text code" placeholder="…apps.googleusercontent.com" style="width:420px">
				<input type="password" name="client_secret" value="" class="regular-text code" placeholder="<?php echo $c['client_secret'] ? 'secret saved — blank keeps it' : 'client secret'; ?>" autocomplete="new-password" style="width:260px">
				<button name="gasf_gh_action" value="creds" class="button button-primary">Save credentials</button>
			</p>
		</form>
		<?php if ( $c['client_id'] && $c['client_secret'] && ! $c['refresh_token'] ) : ?>
			<p><a class="button button-primary" href="<?php echo esc_url( gasf_gh_connect_url() ); ?>">Connect Google account →</a></p>
		<?php endif; ?>
		<?php if ( $c['refresh_token'] ) : ?>
			<form method="post" style="margin-top:6px"><?php wp_nonce_field( 'gasf_gh_admin' ); ?>
				<button name="gasf_gh_action" value="disconnect" class="button">Disconnect</button>
			</form>
		<?php endif; ?>

		<?php
		// Location picker (only when connected but not auto-picked).
		if ( $c['refresh_token'] && $c['account'] && ! $c['location'] ) {
			$loc = gasf_gh_api( 'GET', GASF_GH_INFO . '/' . $c['account'] . '/locations?readMask=name,title' );
			if ( is_wp_error( $loc ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $loc->get_error_message() ) . '</p></div>';
			} else {
				echo '<h3 class="title">Pick the location</h3>';
				foreach ( (array) ( $loc['locations'] ?? array() ) as $l ) {
					echo '<form method="post" style="margin:4px 0">';
					wp_nonce_field( 'gasf_gh_admin' );
					echo '<input type="hidden" name="location" value="' . esc_attr( $l['name'] ?? '' ) . '">';
					echo '<input type="hidden" name="location_title" value="' . esc_attr( $l['title'] ?? '' ) . '">';
					echo '<button name="gasf_gh_action" value="pick_location" class="button">' . esc_html( ( $l['title'] ?? '?' ) . ' — ' . ( $l['name'] ?? '' ) ) . '</button></form>';
				}
			}
		}

		// Hours editors (connected + location picked).
		if ( $c['refresh_token'] && $c['location'] ) {
			$live = gasf_gh_api( 'GET', GASF_GH_INFO . '/' . $c['location'] . '?readMask=regularHours,specialHours,title' );
			if ( is_wp_error( $live ) ) {
				echo '<div class="notice notice-error"><p>Could not read current hours: ' . esc_html( $live->get_error_message() ) . '</p></div>';
				$live = array();
			}
			$byday = array();
			foreach ( (array) ( $live['regularHours']['periods'] ?? array() ) as $p ) {
				$byday[ (string) ( $p['openDay'] ?? '' ) ] = array( gasf_gh_tod_str( $p['openTime'] ?? null ), gasf_gh_tod_str( $p['closeTime'] ?? null ) );
			}
			?>
			<h3 class="title">Weekly hours (live from Google)</h3>
			<form method="post"><?php wp_nonce_field( 'gasf_gh_admin' ); ?>
				<table class="form-table" role="presentation" style="max-width:560px">
					<?php foreach ( $days as $d ) : $has = isset( $byday[ $d ] ); ?>
					<tr>
						<th scope="row" style="padding:6px 10px 6px 0"><?php echo esc_html( ucfirst( strtolower( $d ) ) ); ?></th>
						<td style="padding:6px 0">
							<label style="margin-right:12px"><input type="checkbox" name="closed_<?php echo esc_attr( $d ); ?>" value="1" <?php checked( ! $has ); ?>> Closed</label>
							<input type="text" name="open_<?php echo esc_attr( $d ); ?>"  value="<?php echo esc_attr( $has ? $byday[ $d ][0] : '' ); ?>" placeholder="11:00" class="small-text" style="width:70px"> –
							<input type="text" name="close_<?php echo esc_attr( $d ); ?>" value="<?php echo esc_attr( $has ? $byday[ $d ][1] : '' ); ?>" placeholder="22:00" class="small-text" style="width:70px">
						</td>
					</tr>
					<?php endforeach; ?>
				</table>
				<p><button name="gasf_gh_action" value="save_regular" class="button button-primary">Push weekly hours to Google</button></p>
			</form>

			<h3 class="title">Special hours (date overrides)</h3>
			<?php
			$sp_lines = array();
			foreach ( (array) ( $live['specialHours']['specialHourPeriods'] ?? array() ) as $sp ) {
				$d = $sp['startDate'] ?? array();
				$date = sprintf( '%04d-%02d-%02d', (int) ( $d['year'] ?? 0 ), (int) ( $d['month'] ?? 0 ), (int) ( $d['day'] ?? 0 ) );
				$sp_lines[] = ! empty( $sp['closed'] ) ? $date . ' closed' : $date . ' ' . gasf_gh_tod_str( $sp['openTime'] ?? null ) . '-' . gasf_gh_tod_str( $sp['closeTime'] ?? null );
			}
			?>
			<form method="post"><?php wp_nonce_field( 'gasf_gh_admin' ); ?>
				<textarea name="special" rows="6" class="large-text code" placeholder="2026-12-24 closed&#10;2026-07-19 12:00-23:00"><?php echo esc_textarea( implode( "\n", $sp_lines ) ); ?></textarea>
				<p class="description">One per line: <code>YYYY-MM-DD closed</code> or <code>YYYY-MM-DD HH:MM-HH:MM</code>. Pushing replaces the whole list; empty clears all overrides.</p>
				<p><button name="gasf_gh_action" value="save_special" class="button button-primary">Push special hours to Google</button></p>
			</form>
			<?php
		}
	}
}
