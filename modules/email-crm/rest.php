<?php
/**
 * Email CRM — REST API (modules/email-crm/rest.php)
 *
 * Namespace gasf/v1, all routes under /crm. Every route re-checks approval
 * server-side on every request via gasf_crm_rest_guard(). Approval is never
 * inferred from anything the browser sends — an account revoked ten seconds ago
 * has to be locked out on its next call, not on its next page load.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function gasf_crm_rest_guard() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'gasf_crm_auth', 'Not signed in.', array( 'status' => 401 ) );
	}
	if ( ! gasf_crm_user_approved() ) {
		return new WP_Error( 'gasf_crm_pending', 'Your account is not approved.', array( 'status' => 403 ) );
	}
	return true;
}

add_action( 'rest_api_init', function () {
	$guard = 'gasf_crm_rest_guard';

	register_rest_route( 'gasf/v1', '/crm/threads', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$status  = $req->get_param( 'status' ) ? sanitize_key( $req->get_param( 'status' ) ) : 'open';
			$threads = gasf_crm_list_threads( $status );

			return array_map( function ( $t ) {
				$holder = $t['locked_by'] ? get_userdata( (int) $t['locked_by'] ) : null;
				return array(
					'id'         => (int) $t['id'],
					'subject'    => (string) $t['subject'],
					'from'       => $t['last_from_name'] ? $t['last_from_name'] : $t['last_from_addr'],
					'from_addr'  => (string) $t['last_from_addr'],
					'status'     => (string) $t['status'],
					'last'       => (string) $t['last_message_at'],
					'locked_by'  => $holder ? $holder->display_name : null,
					'locked_mine' => (int) $t['locked_by'] === get_current_user_id(),
				);
			}, $threads );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id     = (int) $req['id'];
			$thread = gasf_crm_get_thread( $id );
			if ( ! $thread ) {
				return new WP_Error( 'gasf_crm_404', 'Thread not found.', array( 'status' => 404 ) );
			}

			// Opening a thread claims it — that IS the lock, so two volunteers
			// cannot start writing the same reply. Failing to claim is not an
			// error: they still get to read it, just not send.
			$mine = gasf_crm_claim_thread( $id, get_current_user_id() );

			$messages = array_map( function ( $m ) {
				return array(
					'id'          => (int) $m['id'],
					'direction'   => (string) $m['direction'],
					'from'        => $m['from_name'] ? $m['from_name'] : $m['from_addr'],
					'from_addr'   => (string) $m['from_addr'],
					'sent_at'     => (string) $m['sent_at'],
					'body'        => (string) $m['body_html'],
					'attachments' => ! empty( $m['has_attachments'] )
						? gasf_crm_attachment_list( $m['graph_message_id'] )
						: array(),
				);
			}, gasf_crm_thread_messages( $id ) );

			$thread = gasf_crm_get_thread( $id ); // re-read: the claim changed it
			$holder = $thread['locked_by'] ? get_userdata( (int) $thread['locked_by'] ) : null;

			return array(
				'id'        => $id,
				'subject'   => (string) $thread['subject'],
				'status'    => (string) $thread['status'],
				'can_reply' => (bool) $mine,
				'locked_by' => ( ! $mine && $holder ) ? $holder->display_name : null,
				'messages'  => $messages,
				'events'    => array_map( function ( $e ) {
					return array(
						'actor'  => (string) $e['actor'],
						'action' => (string) $e['action'],
						'detail' => (string) $e['detail'],
						'at'     => (string) $e['created_at'],
					);
				}, gasf_crm_thread_events( $id ) ),
			);
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/release', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			gasf_crm_release_thread( (int) $req['id'], get_current_user_id() );
			return array( 'ok' => true );
		},
	) );

	// Closing a thread by hand, three ways. Separate routes rather than one
	// taking a status parameter, so the audit log records the operator's actual
	// intent — "answered elsewhere" and "this is spam" are different claims
	// about the same thread and should not be collapsed into one entry.
	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/addressed', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id = (int) $req['id'];
			gasf_crm_set_status( $id, 'addressed' );
			gasf_crm_log_event( $id, 'addressed', 'Marked answered without sending a reply' );
			return array( 'ok' => true );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/ignore', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id     = (int) $req['id'];
			$reason = sanitize_text_field( (string) $req->get_param( 'reason' ) );
			gasf_crm_set_status( $id, 'ignored' );
			gasf_crm_log_event( $id, 'ignored', $reason ? $reason : 'Spam or no reply needed' );
			return array( 'ok' => true );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/restore', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id = (int) $req['id'];
			gasf_crm_set_status( $id, 'new' );
			gasf_crm_log_event( $id, 'restored', 'Returned to the open queue' );
			return array( 'ok' => true );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/draft', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			// Rate limit per user: the draft endpoint is the only one that costs
			// money, and a stuck retry loop in a browser tab should not be able
			// to run up a bill.
			$k = 'gasf_crm_draft_' . get_current_user_id();
			$n = (int) get_transient( $k );
			if ( $n >= 20 ) {
				return new WP_Error( 'gasf_crm_rate', 'Too many drafts in the last hour. Try again later.', array( 'status' => 429 ) );
			}
			set_transient( $k, $n + 1, HOUR_IN_SECONDS );

			$text = gasf_crm_draft_reply( (int) $req['id'] );
			if ( is_wp_error( $text ) ) {
				return new WP_Error( 'gasf_crm_draft', $text->get_error_message(), array( 'status' => 400 ) );
			}
			return array( 'draft' => $text );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/reply', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => 'gasf_crm_rest_reply',
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/forward', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => 'gasf_crm_rest_forward',
	) );

	register_rest_route( 'gasf/v1', '/crm/contacts', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$rows = gasf_crm_contacts( sanitize_text_field( (string) $req->get_param( 'q' ) ), 200 );
			return array_map( function ( $c ) {
				return array(
					'email' => (string) $c['email'],
					'name'  => (string) $c['name'],
					'sent'  => (int) $c['sent_count'],
					'recv'  => (int) $c['recv_count'],
					'last'  => (string) $c['last_seen'],
				);
			}, $rows );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/attachment', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => 'gasf_crm_rest_attachment',
	) );
} );

/**
 * Forward the newest message in a thread to somebody else, and close it.
 *
 * Forwarding counts as answered. Handing something to the treasurer or the hall
 * booking person IS dealing with it — they reply directly, and that reply never
 * comes back through this mailbox, so waiting for one would leave the thread
 * sitting in Open forever. If it turns out we do still owe a reply, the thread
 * can be put back in Open from the Answered list.
 */
function gasf_crm_rest_forward( WP_REST_Request $req ) {
	global $wpdb;

	$thread_id = (int) $req['id'];
	$user_id   = get_current_user_id();

	$to = array_filter( array_map( 'trim', preg_split( '/[,;\s]+/', (string) $req->get_param( 'to' ) ) ) );
	if ( ! $to ) {
		return new WP_Error( 'gasf_crm_norecip', 'Enter at least one address to forward to.', array( 'status' => 400 ) );
	}
	foreach ( $to as $addr ) {
		if ( ! is_email( $addr ) ) {
			return new WP_Error( 'gasf_crm_bademail', 'That does not look like an email address: ' . $addr, array( 'status' => 400 ) );
		}
	}

	$thread = gasf_crm_get_thread( $thread_id );
	if ( ! $thread ) {
		return new WP_Error( 'gasf_crm_404', 'Thread not found.', array( 'status' => 404 ) );
	}

	// Forward the newest message, whichever direction it came from — that is the
	// one carrying whatever prompted the forward.
	$target = $wpdb->get_var( $wpdb->prepare(
		'SELECT graph_message_id FROM ' . gasf_crm_table( 'messages' ) . '
		  WHERE thread_id = %d AND graph_message_id NOT LIKE %s
		  ORDER BY sent_at DESC, id DESC LIMIT 1',
		$thread_id, 'local-%'
	) );

	// Placeholders are excluded above: they are rows the CRM wrote for its own
	// replies and carry synthetic ids Graph has never heard of.
	if ( ! $target ) {
		return new WP_Error( 'gasf_crm_nomsg',
			'Nothing in this conversation can be forwarded yet. If you just sent a reply, wait for the next sync.',
			array( 'status' => 400 ) );
	}

	$cfg     = gasf_crm_cfg();
	$name    = get_user_meta( $user_id, 'gasf_crm_name', true );
	$name    = $name ? $name : wp_get_current_user()->display_name;
	$note    = trim( (string) $req->get_param( 'comment' ) );
	$comment = ( '' !== $note ? wpautop( esc_html( $note ) ) : '' )
		. '<p>--<br>Forwarded by ' . esc_html( $name ) . '<br>' . esc_html( $cfg['signature_org'] ) . '</p>';

	$sent = gasf_crm_graph_forward( $target, $to, $comment );
	if ( is_wp_error( $sent ) ) {
		gasf_mec_log( 'CRM forward failed (thread ' . $thread_id . '): ' . $sent->get_error_message() );
		return new WP_Error( 'gasf_crm_send', $sent->get_error_message(), array( 'status' => 502 ) );
	}

	foreach ( $to as $addr ) {
		gasf_crm_touch_contact( $addr, '', 'out', (string) $thread['subject'] );
	}

	// Record our own copy, as the reply path does. Three things depend on it:
	// the thread timeline showing that this was passed on; the next sync finding
	// this forward in Sent Items and adopting the placeholder instead of logging
	// it as "answered from Outlook"; and gasf_crm_settle_thread, which decides
	// status from the newest message and would otherwise see the inbound message
	// on top and reopen the thread we just closed.
	//
	// sent_by_user_id stays 0 deliberately: that flag is what feeds the AI
	// corpus, and "Forwarded to treasurer@…" is not a reply worth learning from.
	// The audit log records who did it.
	gasf_crm_insert_message( array(
		'thread_id'        => $thread_id,
		'graph_message_id' => 'local-fwd-' . $thread_id . '-' . time() . '-' . $user_id,
		'direction'        => 'out',
		'from_name'        => $name,
		'from_addr'        => $cfg['mailbox'],
		'to_addrs'         => wp_json_encode( $to ),
		'sent_at'          => current_time( 'mysql', true ),
		'body_preview'     => 'Forwarded to ' . implode( ', ', $to ) . ( '' !== $note ? ' — ' . $note : '' ),
		'body_html'        => '<p><em>Forwarded to ' . esc_html( implode( ', ', $to ) ) . '</em></p>'
			. ( '' !== $note ? wpautop( esc_html( $note ) ) : '' ),
		'has_attachments'  => false,
		'sent_by_user_id'  => 0,
	) );

	gasf_crm_log_event( $thread_id, 'forwarded', 'Forwarded to ' . implode( ', ', $to ) . ' — closed as answered' );
	gasf_crm_set_status( $thread_id, 'addressed' );
	gasf_mec_log( 'CRM: thread ' . $thread_id . ' forwarded to ' . implode( ', ', $to ) . ' by user ' . $user_id );

	return array( 'ok' => true, 'to' => $to );
}

/**
 * Send a reply, then mark the thread addressed.
 *
 * Replies to the most recent INBOUND message: replying to our own last outbound
 * would thread the message under the wrong parent, and some clients would show
 * the club talking to itself.
 */
function gasf_crm_rest_reply( WP_REST_Request $req ) {
	global $wpdb;

	$thread_id = (int) $req['id'];
	$user_id   = get_current_user_id();

	// The body is HTML now, from the contenteditable composer. Only approved
	// users can reach here, but it is still sanitised on arrival: an account can
	// be compromised, and this markup is both stored and re-rendered to every
	// other volunteer who opens the thread. Links are restricted to protocols
	// that cannot execute anything — the editor checks that too, but a
	// client-side check is a convenience, not a control.
	// Strip script/style WITH their contents first. wp_kses drops the tags but
	// keeps the text inside them, which would otherwise arrive in the sent email
	// as a stray line of code. Matches what the inbound path already does.
	$raw = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $req->get_param( 'body' ) );

	$clean = wp_kses( $raw, array(
		'p'          => array(),
		'br'         => array(),
		'strong'     => array(), 'b' => array(),
		'em'         => array(), 'i' => array(), 'u' => array(),
		'ul'         => array(), 'ol' => array(), 'li' => array(),
		'blockquote' => array(),
		'a'          => array( 'href' => array(), 'title' => array() ),
	), array( 'http', 'https', 'mailto' ) );

	// Emptiness is judged on the text, not the markup: a composer left untouched
	// can still hand back "<p><br></p>", which is not a reply.
	if ( '' === trim( wp_strip_all_tags( $clean ) ) ) {
		return new WP_Error( 'gasf_crm_empty', 'The reply is empty.', array( 'status' => 400 ) );
	}

	$thread = gasf_crm_get_thread( $thread_id );
	if ( ! $thread ) {
		return new WP_Error( 'gasf_crm_404', 'Thread not found.', array( 'status' => 404 ) );
	}

	// Re-check the lock at send time. The claim happened when the thread was
	// opened, possibly an hour ago — by now it may have expired and been taken.
	if ( ! gasf_crm_claim_thread( $thread_id, $user_id ) ) {
		$holder = get_userdata( (int) $thread['locked_by'] );
		return new WP_Error( 'gasf_crm_locked',
			( $holder ? $holder->display_name : 'Someone else' ) . ' is replying to this thread.',
			array( 'status' => 409 )
		);
	}

	$target = $wpdb->get_row( $wpdb->prepare(
		'SELECT graph_message_id FROM ' . gasf_crm_table( 'messages' ) . "
		  WHERE thread_id = %d AND direction = 'in'
		  ORDER BY sent_at DESC LIMIT 1", $thread_id
	), ARRAY_A );

	if ( ! $target ) {
		return new WP_Error( 'gasf_crm_noinbound', 'No inbound message to reply to.', array( 'status' => 400 ) );
	}

	$cfg  = gasf_crm_cfg();
	$user = wp_get_current_user();
	$name = get_user_meta( $user_id, 'gasf_crm_name', true );
	$name = $name ? $name : $user->display_name;

	$html = $clean
		. '<p>--<br>' . esc_html( $name ) . '<br>' . esc_html( $cfg['signature_org'] ) . '</p>';

	$sent = gasf_crm_graph_reply( $target['graph_message_id'], $html );
	if ( is_wp_error( $sent ) ) {
		gasf_mec_log( 'CRM reply failed (thread ' . $thread_id . '): ' . $sent->get_error_message() );
		return new WP_Error( 'gasf_crm_send', $sent->get_error_message(), array( 'status' => 502 ) );
	}

	// Record our own copy immediately rather than waiting for the next sync to
	// find it in Sent Items, so the thread reads correctly the moment it is sent.
	// The Graph id is unknown here; a synthetic one keeps the UNIQUE index happy
	// and cannot collide with a real Graph id.
	gasf_crm_insert_message( array(
		'thread_id'        => $thread_id,
		'graph_message_id' => 'local-' . $thread_id . '-' . time() . '-' . $user_id,
		'direction'        => 'out',
		'from_name'        => $name,
		'from_addr'        => $cfg['mailbox'],
		'to_addrs'         => wp_json_encode( array( $thread['last_from_addr'] ) ),
		'sent_at'          => current_time( 'mysql', true ),
		'body_preview'     => mb_substr( trim( wp_strip_all_tags( $clean ) ), 0, 500 ),
		'body_html'        => $html,
		'has_attachments'  => false,
		'sent_by_user_id'  => $user_id,
	) );

	gasf_crm_touch_contact( $thread['last_from_addr'], $thread['last_from_name'], 'out', (string) $thread['subject'] );
	gasf_crm_set_status( $thread_id, 'addressed' );
	gasf_crm_log_event( $thread_id, 'replied', 'Replied to ' . $thread['last_from_addr'] );
	gasf_mec_log( 'CRM: thread ' . $thread_id . ' answered by user ' . $user_id );

	return array( 'ok' => true );
}

/** Attachment metadata for a message, best-effort — never fatal to a thread view. */
function gasf_crm_attachment_list( $graph_message_id ) {
	$list = gasf_crm_graph_attachments( $graph_message_id );
	if ( is_wp_error( $list ) ) { return array(); }

	$out = array();
	foreach ( $list as $a ) {
		$out[] = array(
			'id'   => (string) ( $a['id'] ?? '' ),
			'name' => (string) ( $a['name'] ?? 'attachment' ),
			'size' => (int) ( $a['size'] ?? 0 ),
			'url'  => add_query_arg( array(
				'msg' => rawurlencode( $graph_message_id ),
				'att' => rawurlencode( (string) ( $a['id'] ?? '' ) ),
			), rest_url( 'gasf/v1/crm/attachment' ) ),
		);
	}
	return $out;
}

/**
 * Stream an attachment through the server.
 *
 * Always Content-Disposition: attachment, never inline. These are files from
 * strangers; rendering one in the browser on our own origin would let an HTML
 * or SVG attachment run script against a signed-in volunteer's session.
 */
function gasf_crm_rest_attachment( WP_REST_Request $req ) {
	$msg = (string) $req->get_param( 'msg' );
	$att = (string) $req->get_param( 'att' );
	if ( '' === $msg || '' === $att ) {
		return new WP_Error( 'gasf_crm_badreq', 'Missing parameters.', array( 'status' => 400 ) );
	}

	$file = gasf_crm_graph_attachment( $msg, $att );
	if ( is_wp_error( $file ) ) {
		return new WP_Error( 'gasf_crm_att', $file->get_error_message(), array( 'status' => 404 ) );
	}

	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file['name'] ) . '"' );
	header( 'Content-Length: ' . strlen( $file['bytes'] ) );
	header( 'X-Content-Type-Options: nosniff' );
	echo $file['bytes']; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}
