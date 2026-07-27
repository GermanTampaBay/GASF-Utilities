<?php
/**
 * Email CRM — failure detection (modules/email-crm/health.php)
 *
 * The failure mode this exists for is silence. If Graph stops answering —
 * consent revoked, secret expired, mailbox renamed, tenant policy changed —
 * mail simply stops arriving and the inbox looks exactly like a quiet week.
 * Nobody investigates a quiet week. The club would go on not answering its
 * public email address until somebody happened to notice, which could be a
 * month.
 *
 * One failed sync means nothing: Graph has transient errors, and the hourly
 * cron will pick the mail up next time round. A full day of them means
 * something is actually broken and a person needs to look.
 *
 * The alert email is best effort and deliberately not the primary channel. If
 * Graph is what broke, we cannot send through it, and wp_mail on this host is
 * quarantined by the domain's own SPF record. The banner on /email and the
 * admin notice do not depend on mail working at all, which is the whole point.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** How long things must be broken before anyone is told. */
define( 'GASF_CRM_HEALTH_ALERT_AFTER', DAY_IN_SECONDS );

/** How often to repeat the alert while it stays broken. */
define( 'GASF_CRM_HEALTH_REPEAT', DAY_IN_SECONDS );

function gasf_crm_health_get() {
	return wp_parse_args( (array) get_option( 'gasf_crm_health', array() ), array(
		'first_fail'   => 0,  // when the current run of failures started
		'last_fail'    => 0,
		'last_success' => 0,
		'fail_count'   => 0,
		'last_error'   => '',
		'alerted_at'   => 0,
	) );
}

function gasf_crm_health_save( array $h ) {
	update_option( 'gasf_crm_health', $h, false );
}

/**
 * A sync reached the mailbox.
 *
 * If we had already alerted, say so — otherwise the only way to learn that
 * things recovered is to notice mail arriving again, and an unresolved alarm
 * quietly becomes background noise people stop believing.
 */
function gasf_crm_health_ok() {
	$h = gasf_crm_health_get();

	$was_alerting = ( $h['alerted_at'] > 0 );
	$down_since   = $h['first_fail'];

	gasf_crm_health_save( array(
		'first_fail'   => 0,
		'last_fail'    => 0,
		'last_success' => time(),
		'fail_count'   => 0,
		'last_error'   => '',
		'alerted_at'   => 0,
	) );

	if ( $was_alerting ) {
		gasf_mec_log( 'CRM health: mailbox reachable again.' );
		gasf_crm_health_notify(
			'Email CRM: the club mailbox is reachable again',
			"Mail collection is working again.\n\n"
				. ( $down_since ? 'It had been failing since ' . gmdate( 'Y-m-d H:i', $down_since ) . " UTC.\n\n" : '' )
				. "Anything that arrived while it was down has now been collected — nothing is lost, it was waiting in the mailbox the whole time.\n"
		);
	}
}

/** A sync could not reach the mailbox. */
function gasf_crm_health_fail( $message ) {
	$h = gasf_crm_health_get();
	$now = time();

	if ( ! $h['first_fail'] ) { $h['first_fail'] = $now; }
	$h['last_fail']  = $now;
	$h['fail_count'] = (int) $h['fail_count'] + 1;
	$h['last_error'] = (string) $message;

	gasf_crm_health_save( $h );
	gasf_crm_health_maybe_alert();
}

/**
 * Current state: ok | failing | alert.
 *
 * "failing" is a run of failures too short to mean anything yet — visible to an
 * administrator who goes looking, but not worth waking anyone over.
 */
function gasf_crm_health_state() {
	$h = gasf_crm_health_get();

	if ( ! $h['first_fail'] ) {
		return array( 'state' => 'ok' ) + $h;
	}

	$down = time() - (int) $h['first_fail'];
	return array(
		'state'    => ( $down >= GASF_CRM_HEALTH_ALERT_AFTER ) ? 'alert' : 'failing',
		'down_for' => $down,
	) + $h;
}

/** Send the alert if we are past the threshold and have not said so recently. */
function gasf_crm_health_maybe_alert() {
	$s = gasf_crm_health_state();
	if ( 'alert' !== $s['state'] ) { return false; }

	if ( $s['alerted_at'] && ( time() - (int) $s['alerted_at'] ) < GASF_CRM_HEALTH_REPEAT ) {
		return false; // already said so today
	}

	$cfg   = gasf_crm_cfg();
	$hours = (int) round( $s['down_for'] / HOUR_IN_SECONDS );

	$body = "Mail has not been collected from " . $cfg['mailbox'] . " for " . $hours . " hours.\n\n"
		. "New messages sent to the club are NOT reaching the inbox at " . home_url( '/email/' ) . ".\n"
		. "Nothing is lost — mail is piling up in the mailbox and will be collected once this is fixed — but nobody is seeing it, so nobody is replying.\n\n"
		. "Failing since : " . gmdate( 'Y-m-d H:i', (int) $s['first_fail'] ) . " UTC\n"
		. "Attempts       : " . (int) $s['fail_count'] . "\n"
		. "Last error     : " . $s['last_error'] . "\n\n";

	// The likeliest single cause, and the one with a fixed recipe.
	if ( false !== stripos( $s['last_error'], '401' ) || false !== stripos( $s['last_error'], 'secret' )
		|| false !== stripos( $s['last_error'], 'token' ) || false !== stripos( $s['last_error'], '403' ) ) {
		$body .= "This looks like a credentials problem. The usual cause is the Microsoft client\n"
			. "secret expiring. Renewal steps are on the Email CRM settings page:\n"
			. admin_url( 'admin.php?page=gasf-utilities&tab=emailcrm' ) . "\n\n";
	}

	$body .= "Check Graph status on that page — it reports credentials, admin consent and\n"
		. "mailbox access separately, so the first red row is the thing to fix.\n";

	gasf_crm_health_notify( 'Email CRM: mail has not been collected for ' . $hours . ' hours', $body );

	$h = gasf_crm_health_get();
	$h['alerted_at'] = time();
	gasf_crm_health_save( $h );

	gasf_mec_log( 'CRM health: ALERT sent — mailbox unreachable for ' . $hours . 'h.' );
	return true;
}

/**
 * Deliver an operational alert.
 *
 * Goes to the administrators, not to every volunteer: a volunteer can do
 * nothing about a Graph credential, and the /email banner already tells them
 * what they need to know.
 *
 * gasf_crm_notify_send tries Graph first and falls back to wp_mail. Both may
 * well fail here — that is the nature of alerting about a broken mail path —
 * which is exactly why the banner and the admin notice exist and do not depend
 * on this working.
 */
function gasf_crm_health_notify( $subject, $body ) {
	$to = array();

	$admin = get_option( 'admin_email' );
	if ( is_email( $admin ) ) { $to[] = strtolower( $admin ); }

	$cfg = gasf_crm_cfg();
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) $cfg['notify_extra'] ) ) ) as $addr ) {
		if ( is_email( $addr ) ) { $to[] = strtolower( $addr ); }
	}

	foreach ( array_unique( $to ) as $addr ) {
		gasf_crm_notify_send( $addr, $subject, $body );
	}
}

/**
 * Site-wide admin notice.
 *
 * Shown from the first sustained failure rather than only after the full day,
 * because an administrator looking at wp-admin is already in a position to act
 * and there is no reason to withhold it from them.
 */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	if ( ! gasf_crm_ready() ) { return; }

	$s = gasf_crm_health_state();
	if ( 'ok' === $s['state'] ) { return; }

	$hours = (int) round( $s['down_for'] / HOUR_IN_SECONDS );
	$class = ( 'alert' === $s['state'] ) ? 'notice-error' : 'notice-warning';

	echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>Email CRM:</strong> mail has not been collected from '
		. esc_html( gasf_crm_cfg()['mailbox'] ) . ' for ' . (int) $hours . ' hour(s). '
		. 'New enquiries are not reaching the club inbox. '
		. '<a href="' . esc_url( admin_url( 'admin.php?page=gasf-utilities&tab=emailcrm' ) ) . '">Check Graph status</a>.'
		. '</p><p><code>' . esc_html( $s['last_error'] ) . '</code></p></div>';
} );
