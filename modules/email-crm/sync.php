<?php
/**
 * Email CRM — mailbox sync (modules/email-crm/sync.php)
 *
 * Pulls Inbox and Sent Items into the local tables and drives thread state.
 *
 * Sent Items is not optional. If someone answers a thread straight from Outlook
 * instead of the CRM — which they will — the CRM has to notice, or it sits
 * there showing an open thread that was dealt with two days ago. The rule is
 * that the mailbox is the truth and the CRM follows it, never the reverse.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Overlap window, in seconds, subtracted from last_sync before querying.
 *
 * Graph filters on the server's clock, not ours, and a message can be indexed a
 * moment after its receivedDateTime. Re-asking for the last 15 minutes each run
 * costs nothing at this volume and closes that gap; the UNIQUE index on
 * graph_message_id makes the re-reads free.
 */
define( 'GASF_CRM_SYNC_OVERLAP', 900 );

/** First-run lookback. Without it, last_sync = 0 asks Graph for all of history. */
define( 'GASF_CRM_SYNC_FIRSTRUN', 30 * DAY_IN_SECONDS );

function gasf_crm_sync() {
	$result = array( 'new' => 0, 'reopened' => 0, 'notified' => 0, 'errors' => array() );

	if ( ! gasf_crm_ready() ) {
		$result['errors'][] = 'Graph credentials not configured.';
		return $result;
	}

	// One sync at a time. The hourly WP-Cron event and a system cron calling
	// `wp gasf-crm sync` can both fire, and two concurrent runs would race on
	// thread status and double-notify.
	if ( ! gasf_crm_sync_lock() ) {
		$result['errors'][] = 'Another sync is already running.';
		return $result;
	}

	try {
		$cfg   = gasf_crm_cfg();
		$last  = (int) $cfg['last_sync'];
		$since = $last
			? gmdate( 'Y-m-d H:i:s', $last - GASF_CRM_SYNC_OVERLAP )
			: gmdate( 'Y-m-d H:i:s', time() - GASF_CRM_SYNC_FIRSTRUN );

		$started = time();

		$inbox = gasf_crm_graph_messages( 'Inbox', $since );
		if ( is_wp_error( $inbox ) ) {
			$result['errors'][] = $inbox->get_error_message();
			gasf_mec_log( 'CRM sync: inbox fetch failed — ' . $inbox->get_error_message() );
			return $result;
		}

		$fresh   = array();
		$touched = array();

		foreach ( $inbox as $m ) {
			$r = gasf_crm_ingest( $m, 'in' );
			if ( ! $r['thread_id'] ) { continue; }
			$touched[ $r['thread_id'] ] = true;

			if ( $r['inserted'] ) {
				$result['new']++;
				$fresh[ $r['thread_id'] ] = true;
				gasf_crm_log_event( $r['thread_id'], 'received', 'Message from ' . $r['from'], 0 );
			}
			if ( $r['reopened'] ) {
				$result['reopened']++;
				gasf_crm_log_event( $r['thread_id'], 'reopened', 'New message arrived on an answered thread', 0 );
			}
		}

		$sent = gasf_crm_graph_messages( 'SentItems', $since );
		if ( is_wp_error( $sent ) ) {
			// Non-fatal: inbound already landed, and this only affects whether a
			// thread shows as addressed. Log and carry on.
			$result['errors'][] = $sent->get_error_message();
			gasf_mec_log( 'CRM sync: sent-items fetch failed — ' . $sent->get_error_message() );
		} else {
			foreach ( $sent as $m ) {
				$r = gasf_crm_ingest( $m, 'out' );
				if ( ! $r['thread_id'] ) { continue; }
				$touched[ $r['thread_id'] ] = true;

				// Inserted rather than adopted means no placeholder matched, so
				// nothing in the CRM sent it — somebody answered from Outlook.
				if ( $r['inserted'] ) {
					gasf_crm_log_event( $r['thread_id'], 'replied_outlook', 'Answered from Outlook rather than the CRM', 0 );
					unset( $fresh[ $r['thread_id'] ] );
				}
			}
		}

		// Status is decided here, from the newest message in each thread, rather
		// than by whichever loop happened to run last.
		foreach ( array_keys( $touched ) as $thread_id ) {
			gasf_crm_settle_thread( $thread_id );
		}

		gasf_crm_expire_locks();

		foreach ( array_keys( $fresh ) as $thread_id ) {
			$t = gasf_crm_get_thread( $thread_id );
			// Re-check after settling: no point paging anyone about a thread that
			// turned out to have been answered in the same sync.
			if ( $t && 'new' === $t['status'] && gasf_crm_notify_thread( $thread_id ) ) {
				$result['notified']++;
			}
		}

		$cfg              = gasf_crm_cfg();
		$cfg['last_sync'] = $started; // the time the fetch STARTED, not finished
		gasf_crm_save_cfg( $cfg );

		if ( $result['new'] || $result['reopened'] ) {
			gasf_mec_log( sprintf(
				'CRM sync: %d new, %d reopened, %d notified.',
				$result['new'], $result['reopened'], $result['notified']
			) );
		}
	} finally {
		gasf_crm_sync_unlock();
	}

	return $result;
}

/**
 * Normalise one Graph message into the local tables.
 *
 * Messages with no conversationId are skipped rather than guessed at — without
 * it there is no thread to attach to, and inventing one would produce a
 * singleton thread that never groups with its own replies.
 */
function gasf_crm_ingest( array $m, $direction ) {
	$none = array( 'inserted' => false, 'adopted' => false, 'reopened' => false, 'thread_id' => 0, 'from' => '' );

	$conversation_id = (string) ( $m['conversationId'] ?? '' );
	$graph_id        = (string) ( $m['id'] ?? '' );
	if ( '' === $conversation_id || '' === $graph_id ) { return $none; }

	$from_name = (string) ( $m['from']['emailAddress']['name'] ?? '' );
	$from_addr = (string) ( $m['from']['emailAddress']['address'] ?? '' );

	$to = array();
	foreach ( (array) ( $m['toRecipients'] ?? array() ) as $r ) {
		$addr = $r['emailAddress']['address'] ?? '';
		if ( $addr ) { $to[] = $addr; }
	}

	$stamp   = (string) ( $m['receivedDateTime'] ?? $m['sentDateTime'] ?? '' );
	$sent_at = $stamp ? gmdate( 'Y-m-d H:i:s', strtotime( $stamp ) ) : current_time( 'mysql', true );

	$thread = gasf_crm_upsert_thread(
		$conversation_id,
		(string) ( $m['subject'] ?? '(no subject)' ),
		$from_name,
		$from_addr,
		$sent_at,
		'in' === $direction
	);

	// An outbound message is usually the CRM's own reply coming back around via
	// Sent Items. Adopt the placeholder the send path wrote instead of inserting
	// a second copy — and use that as the signal for where the reply came from.
	$adopted = ( 'out' === $direction )
		? gasf_crm_adopt_placeholder( $thread['id'], $graph_id, $sent_at )
		: false;

	$inserted = $adopted ? false : gasf_crm_insert_message( array(
		'thread_id'        => $thread['id'],
		'graph_message_id' => $graph_id,
		'direction'        => $direction,
		'from_name'        => $from_name,
		'from_addr'        => $from_addr,
		'to_addrs'         => wp_json_encode( $to ),
		'sent_at'          => $sent_at,
		'body_preview'     => (string) ( $m['bodyPreview'] ?? '' ),
		'body_html'        => gasf_crm_clean_body( $m['body'] ?? array() ),
		'has_attachments'  => ! empty( $m['hasAttachments'] ),
		'sent_by_user_id'  => 0,
	) );

	// File the sender only on a genuinely new message. The overlap window
	// re-reads the same messages every run, and counting those would inflate the
	// address book with phantom traffic.
	if ( $inserted && 'in' === $direction && $from_addr ) {
		gasf_crm_touch_contact( $from_addr, $from_name, 'in', (string) ( $m['subject'] ?? '' ) );
	}

	return array(
		'inserted'  => $inserted,
		'adopted'   => $adopted,
		'reopened'  => $thread['reopened'],
		'thread_id' => $thread['id'],
		'from'      => $from_name ? $from_name : $from_addr,
	);
}

/**
 * Set a thread's status from the direction of its newest message.
 *
 * Loop ordering cannot decide this correctly. A thread can take a reply and
 * then a fresh inbound message inside the same overlap window, and whichever
 * loop runs last would win regardless of which message is actually newer —
 * leaving a thread with an unanswered question sitting in the answered pile.
 * Deciding from the data is the only version that holds.
 *
 * Ignored threads are never touched: that status is a human judgement about
 * spam, and no amount of incoming mail should overturn it.
 */
function gasf_crm_settle_thread( $thread_id ) {
	global $wpdb;

	$thread = gasf_crm_get_thread( $thread_id );
	if ( ! $thread || 'ignored' === $thread['status'] ) { return; }

	$last = $wpdb->get_var( $wpdb->prepare(
		'SELECT direction FROM ' . gasf_crm_table( 'messages' ) . '
		  WHERE thread_id = %d ORDER BY sent_at DESC, id DESC LIMIT 1', (int) $thread_id
	) );
	if ( ! $last ) { return; }

	$want = ( 'out' === $last ) ? 'addressed' : 'new';
	if ( $thread['status'] === $want ) { return; }

	// Never yank a thread away from someone who has it open and is mid-reply.
	if ( 'claimed' === $thread['status'] && 'new' === $want ) { return; }

	gasf_crm_set_status( $thread_id, $want );
}

/**
 * Sanitise a Graph message body for storage and later display.
 *
 * This is untrusted HTML from anyone on the internet, rendered inside an
 * authenticated page — so it gets stripped hard here (at write) AND again at
 * render. wp_kses with a deliberately small allowlist: no script, no style, no
 * iframe, no form, no event handlers, and no img (remote images are tracking
 * pixels and are not worth the pixel).
 */
function gasf_crm_clean_body( $body ) {
	$content = (string) ( $body['content'] ?? '' );
	$type    = strtolower( (string) ( $body['contentType'] ?? 'text' ) );

	if ( 'html' !== $type ) {
		return wpautop( esc_html( $content ) );
	}

	// Strip script/style blocks WITH their contents first — wp_kses drops the
	// tags but would otherwise leave the CSS or JS body as visible text.
	$content = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $content );

	return wp_kses( $content, array(
		'p'          => array(),
		'br'         => array(),
		'strong'     => array(), 'b' => array(),
		'em'         => array(), 'i' => array(), 'u' => array(),
		'ul'         => array(), 'ol' => array(), 'li' => array(),
		'blockquote' => array(),
		'a'          => array( 'href' => array(), 'title' => array() ),
		'h1'         => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(),
		'table'      => array(), 'thead' => array(), 'tbody' => array(),
		'tr'         => array(), 'td' => array(), 'th' => array(),
		'div'        => array(), 'span' => array(),
		'hr'         => array(),
	) );
}

/* --------------------------------------------------------------------------
 * Sync lock. A transient, not a DB row: it expires on its own, so a fatal
 * error mid-sync can't wedge the CRM permanently the way a sticky flag would.
 * -------------------------------------------------------------------------- */

function gasf_crm_sync_lock( $ttl = 600 ) {
	if ( get_transient( 'gasf_crm_syncing' ) ) { return false; }
	set_transient( 'gasf_crm_syncing', time(), $ttl );
	return true;
}

function gasf_crm_sync_unlock() {
	delete_transient( 'gasf_crm_syncing' );
}
