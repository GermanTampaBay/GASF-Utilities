<?php
/**
 * Email CRM — notifications (modules/email-crm/notify.php)
 *
 * Batched, not per-message. A spam run that drops eighty messages into the
 * mailbox in a quarter of an hour must not produce eighty notifications —
 * that trains people to ignore the notifications, which costs more than the
 * spam did. New arrivals are queued and go out as one summary, at most once
 * per GASF_CRM_NOTIFY_INTERVAL.
 *
 * Delivery goes through Microsoft Graph rather than wp_mail. The domain
 * publishes "v=spf1 include:spf.protection.outlook.com -all" with DMARC
 * p=quarantine, so mail sent from the web server claiming to be
 * @germantampabay.com fails SPF hard and Microsoft quarantines it. Nothing
 * sent by wp_mail from this host was ever going to arrive.
 *
 * WhatsApp remains out of scope: sending it requires a WhatsApp Business
 * Account, a dedicated number, Meta verification and a pre-approved template
 * per message shape. The gasf_crm_notify_send filter is where such a channel
 * would attach.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Minimum gap between notifications, however much mail arrives in between. */
define( 'GASF_CRM_NOTIFY_INTERVAL', HOUR_IN_SECONDS );

/** Most subject lines to list before summarising the remainder. */
define( 'GASF_CRM_NOTIFY_LIST_MAX', 10 );

/**
 * Mark a thread as needing a notification.
 *
 * notified_at is stamped here rather than at send time, and that ordering is
 * deliberate: it makes queuing idempotent, so a thread re-seen in the sync
 * overlap window cannot be queued twice, and a failure later in the run cannot
 * turn into the same thread being announced every hour forever.
 */
function gasf_crm_queue_notification( $thread_id ) {
	global $wpdb;

	$thread = gasf_crm_get_thread( $thread_id );
	if ( ! $thread || ! empty( $thread['notified_at'] ) ) { return false; }

	$wpdb->update( gasf_crm_table( 'threads' ),
		array( 'notified_at' => current_time( 'mysql', true ) ),
		array( 'id' => (int) $thread_id )
	);

	$queue = (array) get_option( 'gasf_crm_notify_queue', array() );
	$queue[] = (int) $thread_id;
	update_option( 'gasf_crm_notify_queue', array_values( array_unique( $queue ) ), false );

	return true;
}

/**
 * Send the queued summary if enough time has passed. Returns threads announced.
 *
 * $force skips the interval check — used by the admin tab's test button, never
 * by the sync.
 */
function gasf_crm_flush_notifications( $force = false ) {
	$queue = array_map( 'intval', (array) get_option( 'gasf_crm_notify_queue', array() ) );
	if ( ! $queue ) { return 0; }

	$last = (int) get_option( 'gasf_crm_notify_last', 0 );
	if ( ! $force && ( time() - $last ) < GASF_CRM_NOTIFY_INTERVAL ) {
		return 0; // hold them; the next run will pick the whole batch up
	}

	// Drop anything already dealt with. During a flood the queue can sit for an
	// hour, and by then a volunteer may have ignored half of it — announcing
	// work that no longer exists is worse than saying nothing.
	$threads = array();
	foreach ( $queue as $id ) {
		$t = gasf_crm_get_thread( $id );
		if ( $t && 'new' === $t['status'] ) { $threads[] = $t; }
	}

	// Clear regardless: threads filtered out above are settled, and leaving them
	// queued would make them reappear in every future summary.
	update_option( 'gasf_crm_notify_queue', array(), false );
	update_option( 'gasf_crm_notify_last', time(), false );

	if ( ! $threads ) { return 0; }

	$recipients = gasf_crm_notify_recipients();
	if ( ! $recipients ) { return 0; }

	list( $subject, $body ) = gasf_crm_notify_digest( $threads );

	foreach ( $recipients as $to ) {
		gasf_crm_notify_send( $to, $subject, $body );
	}

	gasf_mec_log( sprintf( 'CRM: notified %d recipient(s) about %d thread(s).', count( $recipients ), count( $threads ) ) );

	return count( $threads );
}

/** Build the summary. Returns array( subject, body ). */
function gasf_crm_notify_digest( array $threads ) {
	$cfg   = gasf_crm_cfg();
	$count = count( $threads );

	$subject = 1 === $count
		? 'New email to ' . $cfg['mailbox']
		: $count . ' new emails to ' . $cfg['mailbox'];

	$body = 1 === $count
		? "A new message has arrived at " . $cfg['mailbox'] . ".\n\n"
		: $count . " new messages have arrived at " . $cfg['mailbox'] . ".\n\n";

	foreach ( array_slice( $threads, 0, GASF_CRM_NOTIFY_LIST_MAX ) as $t ) {
		$from = $t['last_from_name'] ? $t['last_from_name'] : $t['last_from_addr'];
		$body .= '  - ' . $from . ' — ' . wp_specialchars_decode( (string) $t['subject'] ) . "\n";
	}
	if ( $count > GASF_CRM_NOTIFY_LIST_MAX ) {
		$body .= '  ...and ' . ( $count - GASF_CRM_NOTIFY_LIST_MAX ) . " more.\n";
	}

	$body .= "\nOpen the club inbox to read and reply:\n" . home_url( '/email/' ) . "\n\n"
		. "You will not get another one of these for at least an hour, however much arrives in the meantime.\n";

	return array( $subject, $body );
}

/**
 * Everyone who should be told: approved volunteers, plus any hand-configured
 * addresses. Deduplicated — an administrator is commonly both.
 */
function gasf_crm_notify_recipients() {
	$out = array();

	foreach ( gasf_crm_all_users() as $u ) {
		if ( ! gasf_crm_user_approved( $u->ID ) ) { continue; }
		$addr = get_user_meta( $u->ID, 'gasf_crm_email', true );
		if ( ! $addr ) { $addr = $u->user_email; }
		// Placeholder addresses are minted when a provider gives us no email.
		if ( $addr && false === strpos( $addr, '@invalid.local' ) ) { $out[] = strtolower( $addr ); }
	}

	$cfg = gasf_crm_cfg();
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) $cfg['notify_extra'] ) ) ) as $addr ) {
		if ( is_email( $addr ) ) { $out[] = strtolower( $addr ); }
	}

	return array_values( array_unique( $out ) );
}

/**
 * Deliver one notification. Graph first, wp_mail only as a last resort.
 *
 * The fallback is nearly certain to be silently discarded on this host — see
 * the SPF note at the top — but a discarded message is still better than
 * throwing away the notification entirely if the Graph token happens to be
 * unavailable at that moment.
 */
function gasf_crm_notify_send( $to, $subject, $body ) {
	$handled = apply_filters( 'gasf_crm_notify_send', null, $to, $subject, $body );
	if ( null !== $handled ) { return (bool) $handled; }

	if ( gasf_crm_ready() ) {
		$sent = gasf_crm_graph_send( $to, $subject, $body );
		if ( ! is_wp_error( $sent ) ) { return true; }
		gasf_mec_log( 'CRM notify: Graph send failed for ' . $to . ' — ' . $sent->get_error_message() );
	}

	return (bool) wp_mail( $to, $subject, $body );
}

/**
 * Tell the administrators a new account is waiting.
 *
 * Deliberately outside the digest. Approvals are rare, and someone sitting at
 * "awaiting approval" is blocked until a human acts — that should not wait an
 * hour behind a batching window built for spam floods.
 */
function gasf_crm_notify_admin_pending( $user_id, $name, $email, $provider ) {
	$to = get_option( 'admin_email' );
	if ( ! $to ) { return; }

	gasf_crm_notify_send(
		$to,
		'Email CRM: account awaiting approval',
		'Someone has signed in to the Email CRM and is waiting for approval.' . "\n\n"
			. 'Name:     ' . $name . "\n"
			. 'Email:    ' . $email . "\n"
			. 'Provider: ' . $provider . "\n\n"
			. 'Approve or deny here:' . "\n"
			. admin_url( 'admin.php?page=gasf-utilities&tab=emailcrm' ) . "\n"
	);
}
