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
					// Sanitised again on the way OUT, not only at ingest: rows
					// written before the sanitiser existed, or altered in the
					// database by anything else, would otherwise reach innerHTML
					// on trust. Costs microseconds on a handful of messages.
					'body'        => gasf_crm_email_kses( (string) $m['body_html'] ),
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
			$reason = trim( sanitize_text_field( (string) $req->get_param( 'reason' ) ) );

			// A reason is required, and validated here rather than only in the
			// browser: this is what the audit log will show months later, and
			// "ignored" with no stated cause is the entry nobody can act on.
			if ( '' === $reason ) {
				return new WP_Error( 'gasf_crm_noreason',
					'Choose a reason for ignoring this message.', array( 'status' => 400 ) );
			}
			if ( mb_strlen( $reason ) > 120 ) {
				$reason = mb_substr( $reason, 0, 120 ) . '…';
			}

			gasf_crm_set_status( $id, 'ignored' );
			gasf_crm_log_event( $id, 'ignored', $reason );
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

	register_rest_route( 'gasf/v1', '/crm/sync', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function () {
			// Shared cooldown across all users, not per-user. The expensive thing
			// is the round trip to Microsoft, and it costs the same whether one
			// person presses this ten times or ten people press it once. The sync
			// lock already stops overlapping runs; this stops a queue of
			// back-to-back runs each fetching the same nothing.
			if ( get_transient( 'gasf_crm_manual_sync' ) ) {
				return array( 'throttled' => true, 'new' => 0, 'reopened' => 0 );
			}
			set_transient( 'gasf_crm_manual_sync', time(), MINUTE_IN_SECONDS );

			$r = gasf_crm_sync();

			// Report a genuine upstream failure rather than a silent "nothing
			// new" — otherwise a broken mailbox connection looks identical to a
			// quiet morning, which is the worst possible failure mode here.
			if ( ! empty( $r['errors'] ) ) {
				return new WP_Error( 'gasf_crm_sync', implode( '; ', $r['errors'] ), array( 'status' => 502 ) );
			}

			return array(
				'throttled' => false,
				'new'       => (int) $r['new'],
				'reopened'  => (int) $r['reopened'],
			);
		},
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

	// Outbound attachments: upload one, or list the shared library.
	register_rest_route( 'gasf/v1', '/crm/attachments', array(
		array(
			'methods'             => 'POST',
			'permission_callback' => $guard,
			'callback'            => function ( WP_REST_Request $req ) {
				$files = $req->get_file_params();
				if ( empty( $files['file'] ) ) {
					return new WP_Error( 'gasf_crm_nofile', 'No file was received.', array( 'status' => 400 ) );
				}
				$row = gasf_crm_attach_store(
					$files['file'],
					(bool) $req->get_param( 'keep' ),
					(string) $req->get_param( 'label' )
				);
				if ( is_wp_error( $row ) ) {
					// 400 rather than 500: every rejection here is something the
					// person can act on — wrong type, too big, empty file.
					return new WP_Error( 'gasf_crm_upload', $row->get_error_message(), array( 'status' => 400 ) );
				}
				return gasf_crm_attach_public( $row );
			},
		),
		array(
			'methods'             => 'GET',
			'permission_callback' => $guard,
			'callback'            => function () {
				return array_values( array_filter( array_map( 'gasf_crm_attach_public', gasf_crm_attach_library() ) ) );
			},
		),
	) );

	register_rest_route( 'gasf/v1', '/crm/attachments/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$row = gasf_crm_attach_get( (int) $req['id'] );
			if ( ! $row ) {
				return new WP_Error( 'gasf_crm_404', 'That file no longer exists.', array( 'status' => 404 ) );
			}
			// One-off uploads belong to their uploader. Ids are guessable
			// sequential integers, so without this any approved account could
			// delete another volunteer's pending upload out from under them.
			if ( ! gasf_crm_attach_can_use( $row, get_current_user_id() ) ) {
				return new WP_Error( 'gasf_crm_forbidden',
					'That file belongs to somebody else.', array( 'status' => 403 ) );
			}
			// A library document is shared property. Anyone may drop their own
			// upload, but removing one somebody else put there is an admin act.
			if ( ! empty( $row['in_library'] )
				&& ! current_user_can( 'manage_options' )
				&& (int) $row['uploaded_by'] !== get_current_user_id() ) {
				return new WP_Error( 'gasf_crm_forbidden',
					'Only an administrator can remove a shared document that somebody else added.',
					array( 'status' => 403 ) );
			}
			gasf_crm_attach_delete( (int) $req['id'] );
			return array( 'ok' => true );
		},
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
	$target = $wpdb->get_row( $wpdb->prepare(
		'SELECT graph_message_id, has_attachments FROM ' . gasf_crm_table( 'messages' ) . '
		  WHERE thread_id = %d AND graph_message_id NOT LIKE %s
		  ORDER BY sent_at DESC, id DESC LIMIT 1',
		$thread_id, 'local-%'
	), ARRAY_A );

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

	$sent = gasf_crm_graph_forward( $target['graph_message_id'], $to, $comment );
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
		// Graph /forward carries the original's attachments with it, so the
		// history row reflects what the forwarded message actually had.
		'has_attachments'  => ! empty( $target['has_attachments'] ),
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
	$clean = gasf_crm_email_kses( (string) $req->get_param( 'body' ), 'compose' );

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

	// Attachments are referenced by id, never uploaded as part of the send: the
	// file already sits on the server from the moment it was picked, so a slow
	// or dropped connection at send time cannot lose it.
	//
	// Every id is permission-checked, not merely resolved — ids are guessable
	// sequential integers. An id that fails the check behaves exactly like one
	// that no longer exists, so the response does not reveal whether a guessed
	// id was real.
	$requested   = array_values( array_filter( array_map( 'intval', (array) $req->get_param( 'attachments' ) ) ) );
	$attachments = array();
	$names       = array();
	foreach ( $requested as $aid ) {
		$row = gasf_crm_attach_get( $aid );
		if ( ! $row || ! gasf_crm_attach_can_use( $row, $user_id ) ) { continue; }
		$a = gasf_crm_attach_for_graph( $aid );
		if ( $a ) {
			$attachments[] = $a;
			$names[]       = $a['name'];
		}
	}

	// A file that vanished (or was never yours) between picking and sending is
	// worth stopping for. Silently sending a reply that says "the form is
	// attached" without the form is worse than making somebody press the
	// button again.
	if ( count( $requested ) !== count( $attachments ) ) {
		return new WP_Error( 'gasf_crm_attachlost',
			'One of the attachments could no longer be found. Attach it again and resend.',
			array( 'status' => 409 ) );
	}

	if ( $names ) {
		$html .= '<p><em>Attached: ' . esc_html( implode( ', ', $names ) ) . '</em></p>';
	}

	$sent = $attachments
		? gasf_crm_graph_reply_with_attachments( $target['graph_message_id'], $html, $attachments )
		: gasf_crm_graph_reply( $target['graph_message_id'], $html );

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
		// From the files actually sent — a hardcoded false here meant a reply
		// that carried the membership form showed no paperclip in History.
		'has_attachments'  => ! empty( $attachments ),
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
		// Cloud links and attached emails carry no bytes. Marking them here lets
		// the chip say so up front, rather than sending someone to an error page
		// to find out.
		$kind = (string) ( $a['@odata.type'] ?? '' );
		$out[] = array(
			'id'   => (string) ( $a['id'] ?? '' ),
			'name' => (string) ( $a['name'] ?? 'attachment' ),
			'size' => (int) ( $a['size'] ?? 0 ),
			'kind' => false !== strpos( $kind, 'referenceAttachment' ) ? 'link'
				: ( false !== strpos( $kind, 'itemAttachment' ) ? 'email' : 'file' ),
			// _wpnonce is what makes this link WORK at all. It renders as a plain
			// <a href>, and a bare navigation carries no X-WP-Nonce header — and
			// WordPress REST cookie auth without a nonce demotes the request to
			// anonymous, so every paperclip click was answered with a 401. The
			// query-param form is REST cookie-auth's sanctioned equivalent. The
			// nonce is minted fresh each time the thread is opened and is
			// useless without the session cookie it is bound to.
			'url'  => add_query_arg( array(
				'msg'      => rawurlencode( $graph_message_id ),
				'att'      => rawurlencode( (string) ( $a['id'] ?? '' ) ),
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
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
		gasf_crm_attachment_problem( 'That download link is incomplete. Go back and open the message again.' );
	}

	$file = gasf_crm_graph_attachment( $msg, $att );
	if ( is_wp_error( $file ) ) {
		// A plain WP_Error return would render as a JSON blob, because this link
		// is a top-level navigation rather than a fetch — the volunteer would
		// get {"code":"gasf_crm_att_ref","message":...} filling the tab. Say it
		// in a sentence instead.
		gasf_crm_attachment_problem( $file->get_error_message() );
	}

	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file['name'] ) . '"' );
	header( 'Content-Length: ' . strlen( $file['bytes'] ) );
	header( 'X-Content-Type-Options: nosniff' );

	// Drop any buffered output before writing binary. WordPress or a plugin may
	// hold an ob_start() from earlier in the request, and a stray notice landing
	// in front of the bytes corrupts the file the volunteer receives — with no
	// error anywhere, just a download that will not open.
	while ( ob_get_level() > 0 ) { ob_end_clean(); }

	echo $file['bytes']; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

/** Human-readable dead end for a download that cannot be served. Never returns. */
function gasf_crm_attachment_problem( $message ) {
	nocache_headers();
	status_header( 404 );
	header( 'Content-Type: text/html; charset=utf-8' );
	printf(
		'<!DOCTYPE html><html><head><meta charset="utf-8"><title>Attachment unavailable</title>'
		. '<style>body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;'
		. 'max-width:34em;margin:14vh auto;padding:0 20px;color:#1d2327}'
		. 'h1{font-size:19px;margin:0 0 10px}p{color:#50575e}'
		. 'a{display:inline-block;margin-top:18px;padding:9px 16px;background:#2271b1;color:#fff;'
		. 'border-radius:4px;text-decoration:none}</style></head><body>'
		. '<h1>That attachment cannot be downloaded</h1><p>%s</p><a href="%s">Back to the inbox</a></body></html>',
		esc_html( $message ),
		esc_url( home_url( '/email/' ) )
	);
	exit;
}
