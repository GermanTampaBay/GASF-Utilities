<?php
/**
 * Shared Claude client — modules/03-claude.php
 *
 * The one place the plugin talks to Anthropic. Every Claude-powered utility
 * (Event SEO, FB → Blog titles, whatever comes next) goes through here, so
 * the API key, the model, and the HTTP call exist exactly once. Ungated:
 * this is infrastructure, and the Settings tab's "Global settings" form is
 * the UI for it.
 *
 *   gasf_anthropic_key()     — the site-wide key (Settings tab), with the
 *                              pre-2.3 per-module AI-SEO key as a fallback.
 *   gasf_anthropic_model()   — the site-wide model id (Settings tab), with
 *                              the pre-2.7 AI-SEO model as a fallback, then
 *                              GASF_CLAUDE_DEFAULT_MODEL.
 *   gasf_anthropic_models()  — the models this key can use, from Anthropic's
 *                              Models API, cached 12 h. Feeds the dropdown so
 *                              a newly released model appears on its own.
 *   gasf_claude_text()       — one prompt in, one trimmed text out (or
 *                              WP_Error). Fixes the club's name on the way.
 *
 * The key is stored with autoload off and never reaches the front end.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'GASF_CLAUDE_DEFAULT_MODEL' ) ) { define( 'GASF_CLAUDE_DEFAULT_MODEL', 'claude-haiku-4-5-20251001' ); }
if ( ! defined( 'GASF_CLAUDE_API_VERSION' ) )   { define( 'GASF_CLAUDE_API_VERSION', '2023-06-01' ); }

/** Site-wide Anthropic key; falls back to the legacy AI-SEO per-module key. */
function gasf_anthropic_key() {
	$k = (string) get_option( 'gasf_anthropic_key', '' );
	if ( '' === $k ) {
		$legacy = (array) get_option( 'gasf_aiseo_config', array() );
		$k      = isset( $legacy['key'] ) ? (string) $legacy['key'] : '';
	}
	return $k;
}

/** Site-wide model id; falls back to the pre-2.7 AI-SEO setting, then the default. */
function gasf_anthropic_model() {
	$m = trim( (string) get_option( 'gasf_anthropic_model', '' ) );
	if ( '' === $m ) {
		$legacy = (array) get_option( 'gasf_aiseo_config', array() );
		$m      = trim( (string) ( $legacy['model'] ?? '' ) );
	}
	return '' !== $m ? $m : GASF_CLAUDE_DEFAULT_MODEL;
}

/** Request headers shared by every call. */
function gasf_claude_headers( $key ) {
	return array(
		'x-api-key'         => $key,
		'anthropic-version' => GASF_CLAUDE_API_VERSION,
		'content-type'      => 'application/json',
	);
}

/**
 * Models the saved key can use, newest first.
 *
 * Returns array( 'models' => [ id => display name ], 'live' => bool,
 * 'ts' => int, 'error' => string ). 'live' is false when the list is the
 * built-in fallback (no key, or the API could not be reached) — the UI says
 * so rather than presenting a stale list as current. The saved model is
 * always present, so the dropdown never silently drops the current choice.
 *
 * @param bool $force Bypass the 12-hour cache (the "Refresh model list" button).
 */
function gasf_anthropic_models( $force = false ) {
	$key   = gasf_anthropic_key();
	$cache = $force ? false : get_transient( 'gasf_anthropic_models' );
	$out   = array( 'models' => array(), 'live' => false, 'ts' => 0, 'error' => '' );

	if ( is_array( $cache ) && ! empty( $cache['models'] ) ) {
		$out = array_merge( $out, $cache, array( 'live' => true ) );
	} elseif ( '' === $key ) {
		$out['error'] = 'No API key saved — showing the built-in list.';
	} else {
		$models = array();
		$after  = '';
		$pages  = 0;
		do {
			$url = 'https://api.anthropic.com/v1/models?limit=100' . ( $after ? '&after_id=' . rawurlencode( $after ) : '' );
			$r   = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => gasf_claude_headers( $key ) ) );
			if ( is_wp_error( $r ) ) { $out['error'] = $r->get_error_message(); break; }
			$code = (int) wp_remote_retrieve_response_code( $r );
			$b    = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( $code < 200 || $code >= 300 ) {
				$out['error'] = 'Anthropic HTTP ' . $code . ': ' . ( $b['error']['message'] ?? substr( wp_remote_retrieve_body( $r ), 0, 140 ) );
				break;
			}
			foreach ( (array) ( $b['data'] ?? array() ) as $m ) {
				$id = (string) ( $m['id'] ?? '' );
				if ( '' === $id ) { continue; }
				$models[ $id ] = (string) ( $m['display_name'] ?? $id );
			}
			$after = ! empty( $b['has_more'] ) ? (string) ( $b['last_id'] ?? '' ) : '';
		} while ( '' !== $after && ++$pages < 5 );

		if ( $models ) {
			$out = array( 'models' => $models, 'live' => true, 'ts' => time(), 'error' => '' );
			set_transient( 'gasf_anthropic_models', array( 'models' => $models, 'ts' => $out['ts'] ), 12 * HOUR_IN_SECONDS );
		} elseif ( '' === $out['error'] ) {
			$out['error'] = 'Anthropic returned no models.';
		}
	}

	if ( ! $out['models'] ) {
		$out['models'] = array( GASF_CLAUDE_DEFAULT_MODEL => 'Claude Haiku 4.5 (built-in default)' );
	}
	$current = gasf_anthropic_model();
	if ( ! isset( $out['models'][ $current ] ) ) {
		$out['models'] = array( $current => $current . ' (saved)' ) + $out['models'];
	}
	return $out;
}

/**
 * One prompt → one piece of text. Returns the trimmed text or WP_Error.
 *
 * Deliberately the minimum request body (model, max_tokens, messages): it is
 * accepted unchanged by every model generation, so switching models on the
 * Settings tab can never produce a 400 from a parameter the new model
 * rejects. Callers do their own output shaping (quote-stripping, length caps).
 *
 * @param string $prompt     The user turn.
 * @param int    $max_tokens Output cap for this call.
 * @param string $model      Override; '' = the site-wide setting.
 */
function gasf_claude_text( $prompt, $max_tokens = 200, $model = '' ) {
	$key = gasf_anthropic_key();
	if ( '' === $key ) { return new WP_Error( 'nokey', 'No Anthropic API key set (GASF Utilities → Settings).' ); }
	$model = '' !== (string) $model ? (string) $model : gasf_anthropic_model();
	$r = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
		'timeout' => 30,
		'headers' => gasf_claude_headers( $key ),
		'body'    => wp_json_encode( array(
			'model'      => $model,
			'max_tokens' => max( 1, (int) $max_tokens ),
			'messages'   => array( array( 'role' => 'user', 'content' => (string) $prompt ) ),
		) ),
	) );
	if ( is_wp_error( $r ) ) { return $r; }
	$code = (int) wp_remote_retrieve_response_code( $r );
	$b    = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'api', 'Anthropic HTTP ' . $code . ' (' . $model . '): ' . ( $b['error']['message'] ?? substr( wp_remote_retrieve_body( $r ), 0, 140 ) ) );
	}
	// Newer models can decline a request (stop_reason "refusal") with a 200.
	if ( 'refusal' === (string) ( $b['stop_reason'] ?? '' ) ) {
		return new WP_Error( 'refusal', 'Claude declined this request (' . (string) ( $b['stop_details']['category'] ?? 'refusal' ) . ').' );
	}
	$text = '';
	foreach ( (array) ( $b['content'] ?? array() ) as $block ) {
		if ( 'text' === ( $block['type'] ?? '' ) ) { $text .= (string) ( $block['text'] ?? '' ); }
	}
	return gasf_fix_club_name( trim( $text ) );
}
