<?php
/**
 * Email CRM — Claude Haiku reply drafts (modules/email-crm/ai.php)
 *
 * Drafts are generated ON DEMAND from a button, never automatically when a
 * thread is opened. Two reasons: spam and newsletters would otherwise burn a
 * call every time someone glances at the list, and an auto-filled compose box
 * invites sending without reading.
 *
 * The corpus is read straight out of the WordPress database rather than
 * crawled over HTTP. This module runs ON the site, so the DB is right there —
 * no scraper to maintain, nothing to break when the theme changes, and the
 * content is current by definition.
 *
 * Uses the site-wide key from gasf_anthropic_key() (Settings tab), shared with
 * the AI SEO module.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'GASF_CRM_AI_MODEL', 'claude-haiku-4-5-20251001' );
define( 'GASF_CRM_CORPUS_MAX', 60000 ); // characters of site content
define( 'GASF_CRM_CORPUS_TTL', WEEK_IN_SECONDS );

/**
 * Site content as one plain-text block, cached for a week.
 *
 * Pages first, then events, then posts: a page ("Membership", "Hall Rental",
 * "Directions") answers far more inbound mail than a news post does, so when
 * the character budget runs out it should run out on the posts.
 */
function gasf_crm_corpus( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( 'gasf_crm_corpus' );
		if ( is_string( $cached ) && '' !== $cached ) { return $cached; }
	}

	$types = array( 'page', 'gasf_event', 'post' );
	$out   = '';

	foreach ( $types as $type ) {
		if ( ! post_type_exists( $type ) ) { continue; }
		$posts = get_posts( array(
			'post_type'        => $type,
			'post_status'      => 'publish',
			'numberposts'      => ( 'post' === $type ) ? 25 : 150,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => false,
		) );

		foreach ( $posts as $p ) {
			$body = wp_strip_all_tags( strip_shortcodes( (string) $p->post_content ) );
			$body = trim( preg_replace( '/\s+/', ' ', $body ) );
			if ( '' === $body ) { continue; }
			if ( mb_strlen( $body ) > 1500 ) { $body = mb_substr( $body, 0, 1500 ) . '…'; }

			$chunk = '## ' . $p->post_title . "\n" . get_permalink( $p ) . "\n" . $body . "\n\n";
			if ( mb_strlen( $out ) + mb_strlen( $chunk ) > GASF_CRM_CORPUS_MAX ) { break 2; }
			$out .= $chunk;
		}
	}

	set_transient( 'gasf_crm_corpus', $out, GASF_CRM_CORPUS_TTL );
	return $out;
}

/**
 * Past replies sent from the CRM, as tone examples.
 *
 * Thin at launch — the mailbox is new, so there is no back catalogue to learn
 * from and early drafts will read as generic-helpful. It fills in on its own as
 * volunteers answer mail. Seeding it from exported historical info@ replies
 * would skip that warm-up.
 */
function gasf_crm_tone_examples( $limit = 8 ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT body_preview FROM ' . gasf_crm_table( 'messages' ) . "
		  WHERE direction = 'out' AND sent_by_user_id > 0 AND body_preview <> ''
		  ORDER BY sent_at DESC LIMIT %d", (int) $limit
	), ARRAY_A );

	if ( ! $rows ) { return ''; }

	$out = "Previous replies this club has sent, as a guide to tone and length:\n\n";
	foreach ( $rows as $r ) {
		$t = trim( preg_replace( '/\s+/', ' ', (string) $r['body_preview'] ) );
		if ( mb_strlen( $t ) > 400 ) { $t = mb_substr( $t, 0, 400 ) . '…'; }
		$out .= "---\n" . $t . "\n";
	}
	return $out . "\n";
}

/** Draft a reply for a thread. Returns plain text or WP_Error. */
function gasf_crm_draft_reply( $thread_id ) {
	$key = function_exists( 'gasf_anthropic_key' ) ? gasf_anthropic_key() : '';
	if ( '' === $key ) {
		return new WP_Error( 'gasf_crm_nokey', 'No Anthropic API key is set (GASF Utilities → Settings).' );
	}

	$thread = gasf_crm_get_thread( $thread_id );
	if ( ! $thread ) { return new WP_Error( 'gasf_crm_nothread', 'Thread not found.' ); }

	$messages = gasf_crm_thread_messages( $thread_id );
	if ( ! $messages ) { return new WP_Error( 'gasf_crm_nomsg', 'That thread has no messages to reply to.' ); }

	$transcript = '';
	foreach ( $messages as $m ) {
		$who  = ( 'out' === $m['direction'] ) ? 'The club' : ( $m['from_name'] ? $m['from_name'] : $m['from_addr'] );
		$body = trim( wp_strip_all_tags( (string) $m['body_html'] ) );
		$body = preg_replace( '/\s+/', ' ', $body );
		if ( mb_strlen( $body ) > 2000 ) { $body = mb_substr( $body, 0, 2000 ) . '…'; }
		$transcript .= $who . " wrote:\n" . $body . "\n\n";
	}

	$cfg = gasf_crm_cfg();

	$system = "You are drafting a reply on behalf of the " . $cfg['signature_org'] . ", a German-American cultural club in Pinellas Park, Florida. "
		. "Someone has emailed the club's public info address and a volunteer is about to answer.\n\n"
		. "Write the reply body only — no subject line, no greeting header block, no signature (one is appended automatically).\n"
		. "Warm but brief: a few short paragraphs at most. Plain text, no markdown.\n"
		. "Answer only from the club information below. If it does not cover what they asked, say plainly that you will check and come back to them — never invent hours, prices, dates, or policies.\n"
		. "If the message is spam, a newsletter, or an automated notice, reply with exactly: NO_REPLY_NEEDED\n\n"
		. "=== CLUB INFORMATION ===\n" . gasf_crm_corpus() . "\n"
		. gasf_crm_tone_examples();

	$r = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
		'timeout' => 45,
		'headers' => array(
			'x-api-key'         => $key,
			'anthropic-version' => '2023-06-01',
			'content-type'      => 'application/json',
		),
		'body'    => wp_json_encode( array(
			'model'      => GASF_CRM_AI_MODEL,
			'max_tokens' => 700,
			// cache_control on the system block: the corpus is tens of thousands
			// of characters and identical on every draft, so caching it keeps the
			// per-draft cost to roughly the size of the email itself.
			'system'     => array(
				array(
					'type'          => 'text',
					'text'          => $system,
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
			),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => "Draft a reply to this email thread:\n\nSubject: " . $thread['subject'] . "\n\n" . $transcript,
				),
			),
		) ),
	) );

	if ( is_wp_error( $r ) ) { return $r; }

	$code = (int) wp_remote_retrieve_response_code( $r );
	$body = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'gasf_crm_ai', 'Anthropic HTTP ' . $code . ': '
			. ( $body['error']['message'] ?? substr( wp_remote_retrieve_body( $r ), 0, 160 ) ) );
	}

	$text = trim( (string) ( $body['content'][0]['text'] ?? '' ) );
	if ( '' === $text ) { return new WP_Error( 'gasf_crm_ai', 'The model returned nothing.' ); }

	if ( 0 === strpos( $text, 'NO_REPLY_NEEDED' ) ) {
		return new WP_Error( 'gasf_crm_noreply', 'This looks like spam or an automated message — no reply drafted. Mark it addressed if it needs nothing.' );
	}

	return $text;
}

/** Rebuild the corpus weekly so site edits reach the drafts without a manual step. */
add_action( 'gasf_crm_corpus_event', function () { gasf_crm_corpus( true ); } );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'gasf_crm_corpus_event' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'gasf_crm_corpus_event' );
	}
} );

// WordPress ships daily/twicedaily/hourly only; the corpus rebuild is weekly.
add_filter( 'cron_schedules', function ( $s ) {
	if ( ! isset( $s['weekly'] ) ) {
		$s['weekly'] = array( 'interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly' );
	}
	return $s;
} );
