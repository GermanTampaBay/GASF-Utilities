<?php
/**
 * Email CRM — sign-in and approval (modules/email-crm/auth.php)
 *
 * OIDC authorization-code flow with PKCE against Google and Microsoft. Apple is
 * deliberately not here: it needs a paid Apple Developer membership and a
 * client secret JWT that has to be regenerated every six months, and admin
 * approval already gates who gets in.
 *
 * Identity is keyed on provider + subject claim, NEVER on email address. Email
 * is mutable at both providers, so using it as the key would let a re-used or
 * changed address inherit someone else's approved account. The practical
 * consequence: signing in with Google and with Microsoft at the same address
 * produces two separate accounts, each needing its own approval.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function gasf_crm_providers() {
	$c = gasf_crm_cfg();
	return array(
		'google'    => array(
			'label'     => 'Google',
			'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
			'token'     => 'https://oauth2.googleapis.com/token',
			'client_id' => $c['google_id'],
			'secret'    => $c['google_secret'],
			'scope'     => 'openid email profile',
		),
		'microsoft' => array(
			'label'     => 'Microsoft',
			'authorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
			'token'     => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
			'client_id' => $c['ms_id'],
			'secret'    => $c['ms_secret'],
			'scope'     => 'openid email profile',
		),
	);
}

/**
 * Ask the provider to POST the result instead of putting it in the query string.
 *
 * This host runs ModSecurity, whose remote-file-inclusion rule rejects any
 * request with a query parameter whose value BEGINS with http:// or https://.
 * Google's callback returns
 *   scope=https://www.googleapis.com/auth/userinfo.email ...
 * with the URL first, so every single sign-in was being answered with a 406
 * before PHP ever ran. Mid-value URLs pass; it is specifically the leading
 * scheme that trips it.
 *
 * The same value in a POST body sails through, because the rule inspects query
 * arguments. Both Google and Microsoft advertise form_post in their OIDC
 * discovery documents, so this is a supported mode rather than a trick — and it
 * is preferable to asking the host to weaken a firewall rule that is doing a
 * reasonable job for every other request on the site.
 */
function gasf_crm_response_mode() {
	return 'form_post';
}

/** Read a callback value from POST (form_post) or GET (fallback). */
function gasf_crm_cb_param( $key, $allowed = '/[^A-Za-z0-9._~\/-]/' ) {
	$raw = null;
	// phpcs:disable WordPress.Security.NonceVerification -- OAuth callbacks are
	// authenticated by the state parameter plus the browser-bound cookie below;
	// a WordPress nonce cannot exist on a redirect back from a third party.
	if ( isset( $_POST[ $key ] ) ) {
		$raw = wp_unslash( $_POST[ $key ] );
	} elseif ( isset( $_GET[ $key ] ) ) {
		$raw = wp_unslash( $_GET[ $key ] );
	}
	// phpcs:enable
	if ( ! is_string( $raw ) ) { return ''; }

	// Deliberately NOT sanitize_text_field: it strips percent-encoded octets,
	// which would silently corrupt an authorization code that contained one.
	return preg_replace( $allowed, '', $raw );
}


function gasf_crm_redirect_uri( $provider ) {
	return home_url( '/email/auth/' . $provider . '/callback' );
}

/** Providers that are actually configured — the sign-in page shows only these. */
function gasf_crm_enabled_providers() {
	return array_filter( gasf_crm_providers(), function ( $p ) {
		return '' !== $p['client_id'] && '' !== $p['secret'];
	} );
}

/* --------------------------------------------------------------------------
 * Flow start
 * -------------------------------------------------------------------------- */

function gasf_crm_auth_start( $provider ) {
	$providers = gasf_crm_enabled_providers();
	if ( ! isset( $providers[ $provider ] ) ) {
		wp_die( esc_html__( 'That sign-in method is not configured.', 'gasf' ), '', array( 'response' => 400 ) );
	}
	$p = $providers[ $provider ];

	$verifier  = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
	$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	$state     = bin2hex( random_bytes( 16 ) );

	// The state transient alone proves "we issued this state", not "the browser
	// finishing the flow is the one that started it". The browser token closes
	// that gap: an attacker who captures a state value still cannot complete a
	// login-CSRF without the cookie that went with it.
	$browser = bin2hex( random_bytes( 16 ) );
	setcookie( 'gasf_crm_oauth', $browser, array(
		'expires'  => time() + 900,
		'path'     => '/email',
		// Derived from the site URL, not is_ssl(). This host terminates TLS at a
		// proxy and never sets $_SERVER['HTTPS'], so is_ssl() reports false on a
		// perfectly good HTTPS request and the flag would silently be dropped.
		'secure'   => ( 0 === strpos( home_url(), 'https://' ) ),
		'httponly' => true,
		// None, not Lax, because response_mode=form_post means the provider
		// returns us via a cross-site POST — and Lax withholds cookies on
		// anything except a top-level GET navigation. Under Lax this cookie
		// would simply be absent on every callback and every sign-in would fail
		// the browser-binding check. None requires Secure, which is set above.
		'samesite' => 'None',
	) );

	set_transient( 'gasf_crm_oauth_' . $state, array(
		'provider' => $provider,
		'verifier' => $verifier,
		'browser'  => $browser,
	), 15 * MINUTE_IN_SECONDS );

	wp_redirect( $p['authorize'] . '?' . http_build_query( array(
		'client_id'             => $p['client_id'],
		'response_type'         => 'code',
		'redirect_uri'          => gasf_crm_redirect_uri( $provider ),
		'scope'                 => $p['scope'],
		'state'                 => $state,
		'code_challenge'        => $challenge,
		'code_challenge_method' => 'S256',
		'response_mode'         => gasf_crm_response_mode(),
		'prompt'                => 'select_account',
	) ) );
	exit;
}

/* --------------------------------------------------------------------------
 * Callback
 * -------------------------------------------------------------------------- */

function gasf_crm_auth_callback( $provider ) {
	$providers = gasf_crm_providers();
	if ( ! isset( $providers[ $provider ] ) ) { gasf_crm_auth_fail( 'Unknown provider.' ); }
	$p = $providers[ $provider ];

	if ( '' !== gasf_crm_cb_param( 'error', '/[^A-Za-z0-9._-]/' ) ) {
		gasf_crm_auth_fail( 'Sign-in was cancelled or refused.' );
	}

	// State is hex we generated, so it is constrained to hex on the way back in.
	$state = gasf_crm_cb_param( 'state', '/[^a-f0-9]/' );
	$code  = gasf_crm_cb_param( 'code' );
	if ( '' === $state || '' === $code ) { gasf_crm_auth_fail( 'Incomplete sign-in response.' ); }

	$stash = get_transient( 'gasf_crm_oauth_' . $state );
	delete_transient( 'gasf_crm_oauth_' . $state ); // single use, whatever happens next
	if ( ! is_array( $stash ) || $stash['provider'] !== $provider ) {
		gasf_crm_auth_fail( 'That sign-in link has expired. Please try again.' );
	}

	$browser = isset( $_COOKIE['gasf_crm_oauth'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['gasf_crm_oauth'] ) ) : '';
	if ( ! hash_equals( (string) $stash['browser'], $browser ) ) {
		gasf_crm_auth_fail( 'Sign-in could not be verified for this browser. Please try again.' );
	}

	$r = wp_remote_post( $p['token'], array(
		'timeout' => 20,
		'body'    => array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => gasf_crm_redirect_uri( $provider ),
			'client_id'     => $p['client_id'],
			'client_secret' => $p['secret'],
			'code_verifier' => $stash['verifier'],
		),
	) );
	if ( is_wp_error( $r ) ) { gasf_crm_auth_fail( 'Could not reach the sign-in provider.' ); }

	$tok = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( empty( $tok['id_token'] ) ) {
		gasf_mec_log( 'CRM auth: token exchange failed for ' . $provider . ' — ' . substr( wp_remote_retrieve_body( $r ), 0, 200 ) );
		gasf_crm_auth_fail( 'Sign-in failed at the provider.' );
	}

	$claims = gasf_crm_decode_id_token( $tok['id_token'], $p['client_id'] );
	if ( is_wp_error( $claims ) ) { gasf_crm_auth_fail( $claims->get_error_message() ); }

	$user_id = gasf_crm_find_or_create_user( $provider, $claims );
	if ( is_wp_error( $user_id ) ) { gasf_crm_auth_fail( $user_id->get_error_message() ); }

	// Secure flag passed explicitly, derived from the site URL. WordPress
	// otherwise decides from is_ssl(), which on this proxy-terminated host is
	// only true because of a hand-edited X-Forwarded-Proto line in wp-config —
	// a file outside this repo. The cookie that IS the session guarding the
	// club's mailbox should not depend on that line surviving.
	wp_set_auth_cookie( $user_id, false, ( 0 === strpos( home_url(), 'https://' ) ) );
	wp_set_current_user( $user_id );
	wp_safe_redirect( home_url( '/email' ) );
	exit;
}

/**
 * Decode and validate an id_token.
 *
 * The signature is NOT verified, and that is deliberate rather than an
 * oversight: this token came straight back from the provider's token endpoint
 * over TLS, authenticated with our client secret. OIDC Core §3.1.3.7 explicitly
 * allows skipping signature validation in exactly this case. (Tokens arriving
 * any other way — implicit flow, or passed in by a client — would have to be
 * verified against JWKS.) Issuer, audience and expiry are still checked.
 */
function gasf_crm_decode_id_token( $jwt, $client_id ) {
	$parts = explode( '.', $jwt );
	if ( 3 !== count( $parts ) ) { return new WP_Error( 'jwt', 'Malformed sign-in token.' ); }

	$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
	if ( ! is_array( $payload ) ) { return new WP_Error( 'jwt', 'Unreadable sign-in token.' ); }

	$aud = $payload['aud'] ?? '';
	if ( is_array( $aud ) ? ! in_array( $client_id, $aud, true ) : $aud !== $client_id ) {
		return new WP_Error( 'jwt', 'Sign-in token was issued for a different application.' );
	}
	if ( isset( $payload['exp'] ) && (int) $payload['exp'] < ( time() - 60 ) ) {
		return new WP_Error( 'jwt', 'Sign-in token has expired. Please try again.' );
	}

	$iss = (string) ( $payload['iss'] ?? '' );
	$ok  = ( 0 === strpos( $iss, 'https://accounts.google.com' ) || 'accounts.google.com' === $iss
		|| 0 === strpos( $iss, 'https://login.microsoftonline.com/' )
		|| 0 === strpos( $iss, 'https://sts.windows.net/' ) );
	if ( ! $ok ) { return new WP_Error( 'jwt', 'Sign-in token came from an unexpected issuer.' ); }

	if ( empty( $payload['sub'] ) ) { return new WP_Error( 'jwt', 'Sign-in token has no subject.' ); }
	return $payload;
}

/**
 * Look up by provider+sub, or create a PENDING account.
 *
 * New accounts get role '' — no capabilities at all. Approval is what grants
 * access, and it grants it through gasf_crm_user_approved(), not through a WP
 * role, so an approved volunteer still cannot do anything in wp-admin.
 */
function gasf_crm_find_or_create_user( $provider, array $claims ) {
	$sub   = (string) $claims['sub'];
	$email = sanitize_email( (string) ( $claims['email'] ?? '' ) );
	$name  = sanitize_text_field( (string) ( $claims['name'] ?? $email ) );

	$existing = get_users( array(
		'meta_query'  => array(
			'relation' => 'AND',
			array( 'key' => 'gasf_crm_provider', 'value' => $provider ),
			array( 'key' => 'gasf_crm_sub', 'value' => $sub ),
		),
		'number'      => 1,
		'fields'      => 'ID',
		'count_total' => false,
	) );

	if ( ! empty( $existing ) ) {
		$user_id = (int) $existing[0];
		// Refresh the display fields — people change their name and address at
		// the provider, and the approval screen should show what's current.
		if ( $email ) { update_user_meta( $user_id, 'gasf_crm_email', $email ); }
		if ( $name ) { update_user_meta( $user_id, 'gasf_crm_name', $name ); }
		return $user_id;
	}

	// Username is derived from provider+sub, not from the email address, for the
	// same reason the lookup is: it has to be stable and collision-free even if
	// two providers hand us the same address.
	$login = 'crm_' . $provider . '_' . substr( hash( 'sha256', $provider . '|' . $sub ), 0, 16 );

	$user_id = wp_insert_user( array(
		'user_login'   => $login,
		'user_pass'    => wp_generate_password( 32, true, true ),
		'user_email'   => $email ? $email : $login . '@invalid.local',
		'display_name' => $name ? $name : $login,
		'role'         => '',
	) );
	if ( is_wp_error( $user_id ) ) { return $user_id; }

	update_user_meta( $user_id, 'gasf_crm_provider', $provider );
	update_user_meta( $user_id, 'gasf_crm_sub', $sub );
	update_user_meta( $user_id, 'gasf_crm_email', $email );
	update_user_meta( $user_id, 'gasf_crm_name', $name );
	update_user_meta( $user_id, 'gasf_crm_status', 'pending' );

	gasf_mec_log( 'CRM auth: new pending account ' . $login . ' (' . $email . ') via ' . $provider );
	gasf_crm_notify_admin_pending( $user_id, $name, $email, $provider );

	return (int) $user_id;
}

function gasf_crm_auth_fail( $msg ) {
	wp_die(
		esc_html( $msg ) . '<p><a href="' . esc_url( home_url( '/email' ) ) . '">Back to sign in</a></p>',
		'Sign-in problem',
		array( 'response' => 403 )
	);
}

/* --------------------------------------------------------------------------
 * Approval state
 * -------------------------------------------------------------------------- */

function gasf_crm_user_status( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) { return 'anonymous'; }
	// Site admins are in by definition — otherwise the first admin to open
	// /email would be locked out of the tool they administer.
	if ( user_can( $user_id, 'manage_options' ) ) { return 'approved'; }
	$s = get_user_meta( $user_id, 'gasf_crm_status', true );
	return $s ? $s : 'none';
}

function gasf_crm_user_approved( $user_id = 0 ) {
	return 'approved' === gasf_crm_user_status( $user_id );
}

/**
 * Which streams this user may see.
 *
 * Administrators get everything — they configure the thing and can read the
 * mailboxes in Outlook regardless, so withholding here would be theatre.
 *
 * Everyone else gets exactly what was granted on the approval screen. An
 * approved volunteer with no grants sees NOTHING, deliberately: approval says
 * "this is a real person", the grant says "and this is their inbox". Defaulting
 * an ungranted account to the general stream would mean every future stream
 * silently opened itself to everybody already approved.
 */
function gasf_crm_user_streams( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id || ! gasf_crm_user_approved( $user_id ) ) { return array(); }

	$active = array_keys( gasf_crm_active_streams() );
	if ( user_can( $user_id, 'manage_options' ) ) { return $active; }

	$granted = (array) get_user_meta( $user_id, 'gasf_crm_streams', true );

	// Accounts approved before streams existed keep working: an empty grant on
	// a pre-existing account means general, which is all there was to see.
	if ( ! $granted && (int) get_user_meta( $user_id, 'gasf_crm_streams_set', true ) !== 1 ) {
		$granted = array( 'general' );
	}

	return array_values( array_intersect( $active, array_map( 'strval', $granted ) ) );
}

function gasf_crm_user_can_stream( $stream, $user_id = 0 ) {
	return in_array( (string) $stream, gasf_crm_user_streams( $user_id ), true );
}

/** Set a user's stream grants. Records that they were set, even if empty. */
function gasf_crm_set_user_streams( $user_id, array $streams ) {
	$valid = array_values( array_intersect( array_keys( gasf_crm_streams() ), array_map( 'strval', $streams ) ) );
	update_user_meta( (int) $user_id, 'gasf_crm_streams', $valid );
	update_user_meta( (int) $user_id, 'gasf_crm_streams_set', 1 );
	gasf_mec_log( 'CRM: user ' . (int) $user_id . ' stream grants set to [' . implode( ',', $valid ) . '] by ' . get_current_user_id() );
	return $valid;
}

/** Every CRM account, for the approval screen. */
function gasf_crm_all_users() {
	return get_users( array(
		'meta_key' => 'gasf_crm_provider',
		'orderby'  => 'registered',
		'order'    => 'DESC',
		'number'   => 200,
	) );
}

function gasf_crm_set_user_status( $user_id, $status ) {
	if ( ! in_array( $status, array( 'pending', 'approved', 'denied' ), true ) ) { return false; }
	update_user_meta( (int) $user_id, 'gasf_crm_status', $status );
	update_user_meta( (int) $user_id, 'gasf_crm_status_by', get_current_user_id() );
	update_user_meta( (int) $user_id, 'gasf_crm_status_at', current_time( 'mysql' ) );
	gasf_mec_log( 'CRM: user ' . (int) $user_id . ' set to ' . $status . ' by ' . get_current_user_id() );
	return true;
}

/* --------------------------------------------------------------------------
 * Keep CRM accounts out of wp-admin.
 *
 * They hold no capabilities so they could not DO anything there, but landing on
 * a WordPress dashboard is confusing for a volunteer who signed in with Google
 * to answer email. Bounce them back to the tool.
 * -------------------------------------------------------------------------- */

add_filter( 'show_admin_bar', function ( $show ) {
	if ( is_user_logged_in() && ! current_user_can( 'manage_options' )
		&& get_user_meta( get_current_user_id(), 'gasf_crm_provider', true ) ) {
		return false;
	}
	return $show;
} );

add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || ! is_user_logged_in() ) { return; }
	if ( current_user_can( 'manage_options' ) ) { return; }
	if ( get_user_meta( get_current_user_id(), 'gasf_crm_provider', true ) ) {
		wp_safe_redirect( home_url( '/email' ) );
		exit;
	}
} );
