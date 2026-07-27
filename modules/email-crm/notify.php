<?php
/**
 * Email CRM — notifications (modules/email-crm/notify.php)
 *
 * WhatsApp was the original request. It is not the v1 default, because sending
 * WhatsApp from an application requires a WhatsApp Business Account, a phone
 * number not already registered to a personal WhatsApp, Meta business
 * verification, and a template approved per message shape — business-initiated
 * messages outside a 24-hour window cannot be free-form. That is a lot of
 * standing setup for roughly four emails a week.
 *
 * So channels are pluggable and email ships as the default. A WhatsApp or SMS
 * channel drops in by registering on the gasf_crm_notify_channels filter and
 * needs no changes anywhere else.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Notify everyone approved about a thread. Returns true if anything was sent.
 *
 * notified_at is stamped before dispatch, not after: if a channel throws, the
 * next hourly sync must not re-notify the same thread to everyone again. A
 * missed notification is a smaller problem than an hourly loop of duplicates.
 */
function gasf_crm_notify_thread( $thread_id ) {
	global $wpdb;

	$thread = gasf_crm_get_thread( $thread_id );
	if ( ! $thread || ! empty( $thread['notified_at'] ) ) { return false; }

	$recipients = array_filter( gasf_crm_all_users(), function ( $u ) {
		return gasf_crm_user_approved( $u->ID );
	} );
	if ( ! $recipients ) { return false; }

	$wpdb->update( gasf_crm_table( 'threads' ),
		array( 'notified_at' => current_time( 'mysql', true ) ),
		array( 'id' => (int) $thread_id )
	);

	$channels = apply_filters( 'gasf_crm_notify_channels', array(
		'email' => 'gasf_crm_notify_via_email',
	) );

	$cfg      = gasf_crm_cfg();
	$selected = (string) $cfg['notify_channel'];
	$sent     = false;

	foreach ( $channels as $name => $callback ) {
		// 'all' is the escape hatch for belt-and-braces during a channel change.
		if ( 'all' !== $selected && $name !== $selected ) { continue; }
		if ( ! is_callable( $callback ) ) { continue; }
		foreach ( $recipients as $user ) {
			if ( call_user_func( $callback, $user, $thread ) ) { $sent = true; }
		}
	}

	// Hand-configured addresses. Without these the people who actually run the
	// club get nothing: notifications otherwise reach only those who signed in
	// through /email, and an administrator is approved by definition and so
	// never has to — making them the one person never told.
	$covered = array();
	foreach ( $recipients as $u ) {
		$a         = get_user_meta( $u->ID, 'gasf_crm_email', true );
		$covered[] = strtolower( $a ? $a : $u->user_email );
	}
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) $cfg['notify_extra'] ) ) ) as $addr ) {
		if ( is_email( $addr ) && ! in_array( strtolower( $addr ), $covered, true )
			&& gasf_crm_notify_email_to( $addr, $thread ) ) {
			$sent = true;
		}
	}

	return $sent;
}

function gasf_crm_notify_via_email( $user, array $thread ) {
	$to = get_user_meta( $user->ID, 'gasf_crm_email', true );
	if ( ! $to ) { $to = $user->user_email; }
	if ( ! $to || false !== strpos( $to, '@invalid.local' ) ) { return false; }
	return gasf_crm_notify_email_to( $to, $thread );
}

function gasf_crm_notify_email_to( $to, array $thread ) {
	$from    = $thread['last_from_name'] ? $thread['last_from_name'] : $thread['last_from_addr'];
	$subject = 'New email to info@: ' . wp_specialchars_decode( (string) $thread['subject'] );

	$body = "A new message has arrived at info@germantampabay.com.\n\n"
		. 'From:    ' . $from . "\n"
		. 'Subject: ' . wp_specialchars_decode( (string) $thread['subject'] ) . "\n\n"
		. "Open the CRM to read and reply:\n"
		. home_url( '/email' ) . "\n";

	return (bool) wp_mail( $to, $subject, $body );
}

/**
 * Tell the site admins a new account is waiting.
 *
 * Without this an approval request is invisible until someone happens to open
 * the admin tab, and a volunteer sits at "awaiting approval" wondering whether
 * it is broken.
 */
function gasf_crm_notify_admin_pending( $user_id, $name, $email, $provider ) {
	$to = get_option( 'admin_email' );
	if ( ! $to ) { return; }

	wp_mail(
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
