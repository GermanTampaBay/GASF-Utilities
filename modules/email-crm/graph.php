<?php
/**
 * Email CRM — Microsoft Graph client (modules/email-crm/graph.php)
 *
 * App-only (client credentials) against ONE shared mailbox. Every call goes
 * through /users/{mailbox}/... — there is no /me, because there is no signed-in
 * Microsoft user in this flow.
 *
 * If a call comes back 403 with ErrorAccessDenied, the usual cause is the
 * Exchange Application Access Policy: either it has not propagated yet (allow
 * ~30 minutes after creation) or the mailbox is not a member of the
 * gasf-crm-scope group the policy is scoped to.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'GASF_CRM_GRAPH', 'https://graph.microsoft.com/v1.0' );

/**
 * App-only access token, cached until shortly before it expires.
 *
 * The 300-second haircut on the TTL matters: tokens are good for ~60 minutes
 * and an hourly cron lands close enough to the boundary that an un-shaved
 * cache would hand out a token that expires mid-sync.
 */
function gasf_crm_graph_token( $force = false ) {
	$cached = get_transient( 'gasf_crm_graph_token' );
	if ( ! $force && is_string( $cached ) && '' !== $cached ) { return $cached; }

	$c = gasf_crm_cfg();
	if ( '' === $c['tenant_id'] || '' === $c['client_id'] || '' === $c['client_secret'] ) {
		return new WP_Error( 'gasf_crm_nocfg', 'Graph credentials are not configured.' );
	}

	$r = wp_remote_post(
		'https://login.microsoftonline.com/' . rawurlencode( $c['tenant_id'] ) . '/oauth2/v2.0/token',
		array(
			'timeout' => 20,
			'body'    => array(
				'client_id'     => $c['client_id'],
				'client_secret' => $c['client_secret'],
				'scope'         => 'https://graph.microsoft.com/.default',
				'grant_type'    => 'client_credentials',
			),
		)
	);
	if ( is_wp_error( $r ) ) { return $r; }

	$body = json_decode( wp_remote_retrieve_body( $r ), true );
	$code = (int) wp_remote_retrieve_response_code( $r );
	if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
		// error_description carries the actionable part (expired secret, wrong
		// tenant, consent not granted). Truncated so a stray HTML error page
		// can't flood the log.
		return new WP_Error( 'gasf_crm_token', 'Token HTTP ' . $code . ': ' . substr( (string) ( $body['error_description'] ?? wp_remote_retrieve_body( $r ) ), 0, 300 ) );
	}

	$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 300 );
	set_transient( 'gasf_crm_graph_token', $body['access_token'], $ttl );
	return $body['access_token'];
}

/**
 * Authenticated Graph request. $path is either a path under /v1.0 or a full
 * URL (so @odata.nextLink can be passed straight back in).
 *
 * Retries once on 401 with a forced token refresh: a token can be revoked
 * mid-life (secret rotated, app consent changed) and the cached copy would
 * otherwise keep failing until its TTL ran out.
 */
function gasf_crm_graph( $method, $path, $body = null, $retry = true ) {
	$token = gasf_crm_graph_token();
	if ( is_wp_error( $token ) ) { return $token; }

	$url  = ( 0 === strpos( $path, 'http' ) ) ? $path : GASF_CRM_GRAPH . $path;
	$args = array(
		'method'  => $method,
		'timeout' => 30,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
	);
	if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); }

	$r = wp_remote_request( $url, $args );
	if ( is_wp_error( $r ) ) { return $r; }

	$code = (int) wp_remote_retrieve_response_code( $r );
	$raw  = wp_remote_retrieve_body( $r );

	if ( 401 === $code && $retry ) {
		delete_transient( 'gasf_crm_graph_token' );
		gasf_crm_graph_token( true );
		return gasf_crm_graph( $method, $path, $body, false );
	}

	// 202/204 are normal for send and for PATCH-with-no-return.
	if ( 204 === $code || 202 === $code ) { return array(); }

	$json = json_decode( $raw, true );
	if ( $code < 200 || $code >= 300 ) {
		$msg = $json['error']['message'] ?? substr( $raw, 0, 300 );
		if ( 403 === $code ) {
			// Two very different causes, and the Graph message does not
			// distinguish them: either the app lacks the permission the endpoint
			// needs, or the Application Access Policy is excluding this mailbox.
			$msg .= ' — either the app is missing a permission this endpoint needs,'
				. ' or the Application Access Policy is excluding the mailbox.'
				. ' Confirm Mail.ReadWrite + Mail.Send are admin-consented, then run'
				. ' Test-ApplicationAccessPolicy against ' . gasf_crm_cfg()['mailbox'] . '.';
		}
		return new WP_Error( 'gasf_crm_graph', 'Graph HTTP ' . $code . ': ' . $msg );
	}
	return is_array( $json ) ? $json : array();
}

function gasf_crm_mailbox_path() {
	$c = gasf_crm_cfg();
	return '/users/' . rawurlencode( $c['mailbox'] );
}

/**
 * Cheap reachability probe for the admin tab's Test button.
 *
 * Deliberately reads the Inbox FOLDER, not the user object. GET /users/{id}
 * is a directory read requiring User.Read.All, which this app does not have
 * and should not be given — it holds Mail.ReadWrite and Mail.Send and nothing
 * else. Probing the user object made a correctly-configured install fail with
 * a 403 that pointed at the access policy instead of at the wrong endpoint.
 */
function gasf_crm_graph_test() {
	// Always take a fresh token. The reason anyone presses this button is that
	// they just changed something in Entra — and roles are baked into the token
	// at issue time, so a cached one keeps reporting the OLD permission state for
	// most of an hour after the problem was actually fixed.
	delete_transient( 'gasf_crm_graph_token' );

	$r = gasf_crm_graph( 'GET', gasf_crm_mailbox_path()
		. '/mailFolders/Inbox?$select=displayName,totalItemCount,unreadItemCount' );

	// A 403 has two very different causes and Graph's message does not separate
	// them. The token itself does: no roles claim means consent was never
	// granted, which is a different fix from a mailbox the policy excludes.
	if ( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'HTTP 403' ) ) {
		$token = gasf_crm_graph_token();
		if ( ! is_wp_error( $token ) ) {
			$parts  = explode( '.', $token );
			$claims = isset( $parts[1] ) ? json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ) : array();
			if ( empty( $claims['roles'] ) ) {
				return new WP_Error( 'gasf_crm_noconsent',
					'The access token carries no application roles, so admin consent has not been granted. In the app registration, open API permissions and click "Grant admin consent" — Mail.ReadWrite and Mail.Send must show Granted in the Status column. (The "Admin consent required" column saying Yes is not the same thing.)' );
			}
		}
	}
	return $r;
}

/**
 * Messages in a folder at or after $since (a UTC 'Y-m-d H:i:s').
 *
 * Paged, with a hard page cap — a misconfigured $since (epoch 0 on a first run
 * against a mailbox with history) must not turn one cron tick into a thousand
 * Graph calls. The cap is a circuit breaker, not a pagination strategy.
 */
function gasf_crm_graph_messages( $folder, $since, $max_pages = 10 ) {
	$select = 'id,conversationId,subject,from,sender,toRecipients,receivedDateTime,sentDateTime,bodyPreview,body,hasAttachments';
	$field  = ( 'SentItems' === $folder ) ? 'sentDateTime' : 'receivedDateTime';
	$iso    = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $since . ' UTC' ) );

	$url = gasf_crm_mailbox_path() . '/mailFolders/' . rawurlencode( $folder ) . '/messages?' . http_build_query( array(
		'$select'  => $select,
		'$filter'  => $field . ' ge ' . $iso,
		'$orderby' => $field . ' asc',
		'$top'     => 50,
	) );

	$out   = array();
	$pages = 0;
	while ( $url && $pages < $max_pages ) {
		$res = gasf_crm_graph( 'GET', $url );
		if ( is_wp_error( $res ) ) { return $res; }
		foreach ( (array) ( $res['value'] ?? array() ) as $m ) { $out[] = $m; }
		$url = $res['@odata.nextLink'] ?? null;
		$pages++;
	}
	if ( $url ) {
		gasf_mec_log( 'CRM: page cap hit in ' . $folder . ' — ' . count( $out ) . ' fetched, more remain.' );
	}
	return $out;
}

/**
 * Reply to a message, as the shared mailbox.
 *
 * Uses Graph's own /reply rather than composing a fresh message so the
 * In-Reply-To and References headers are built by Exchange. A hand-rolled
 * sendMail would start a NEW thread in the recipient's client, which quietly
 * breaks the one thing this whole CRM is organised around.
 *
 * The sent copy lands in the shared mailbox's Sent Items, which is also how the
 * next sync learns the thread was answered.
 */
function gasf_crm_graph_reply( $graph_message_id, $html ) {
	return gasf_crm_graph(
		'POST',
		gasf_crm_mailbox_path() . '/messages/' . rawurlencode( $graph_message_id ) . '/reply',
		array( 'comment' => $html )
	);
}

function gasf_crm_graph_attachments( $graph_message_id ) {
	$res = gasf_crm_graph( 'GET', gasf_crm_mailbox_path() . '/messages/' . rawurlencode( $graph_message_id )
		. '/attachments?$select=id,name,contentType,size' );
	return is_wp_error( $res ) ? $res : (array) ( $res['value'] ?? array() );
}

/** Raw attachment bytes. Returns array( name, type, bytes ) or WP_Error. */
function gasf_crm_graph_attachment( $graph_message_id, $attachment_id ) {
	$res = gasf_crm_graph( 'GET', gasf_crm_mailbox_path() . '/messages/' . rawurlencode( $graph_message_id )
		. '/attachments/' . rawurlencode( $attachment_id ) );
	if ( is_wp_error( $res ) ) { return $res; }
	if ( ! isset( $res['contentBytes'] ) ) {
		// itemAttachment / referenceAttachment have no contentBytes — out of
		// scope for v1 rather than silently handing back an empty file.
		return new WP_Error( 'gasf_crm_att', 'Unsupported attachment type.' );
	}
	return array(
		'name'  => (string) ( $res['name'] ?? 'attachment' ),
		'type'  => (string) ( $res['contentType'] ?? 'application/octet-stream' ),
		'bytes' => base64_decode( $res['contentBytes'] ),
	);
}
