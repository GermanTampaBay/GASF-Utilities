<?php
/**
 * Photo submissions — modules/email-crm/photos.php
 *
 * The loop a photo takes from an email to a catalogued picture:
 *
 *   1. Somebody emails a photo to photos@germantampabay.com.
 *   2. A volunteer opens the thread at /email and APPROVES the images worth
 *      keeping. Each one is sideloaded into the Media Library, carrying its
 *      provenance and whatever EXIF the file still had (see 43-photo-catalog).
 *   3. The CRM emails the sender a private link asking what the photos are.
 *   4. They fill in date, place, event, who is in each one, and a caption.
 *   5. Their answers are stored PENDING. They are not terms yet.
 *   6. A volunteer confirms them, and only then do they become real tags.
 *
 * Step 5 is the important one. The tagging form is used by a member of the
 * public holding a link — nobody signs in — so anything typed there is
 * untrusted text. If it created taxonomy terms directly, the club's vocabulary
 * would be writable by anyone who was ever forwarded that email, and a
 * misspelling would be indistinguishable from a real person forever.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** How long a tagging link stays usable. */
define( 'GASF_CRM_PHOTO_INVITE_DAYS', 30 );

/** Caps on what one submitter can type, so a form post cannot become a flood. */
define( 'GASF_CRM_PHOTO_MAX_PEOPLE', 25 );
define( 'GASF_CRM_PHOTO_CAPTION_MAX', 150 );

/* =====================================================================
 * Approval — Graph attachment to Media Library
 * ================================================================== */

/**
 * Copy one inbound image into the Media Library.
 *
 * The file is fetched to a temp path and sideloaded, rather than written into
 * uploads directly, so it goes through the same handlers as any other upload:
 * MIME sniffing, unique naming, thumbnails, and the EXIF capture in
 * 43-photo-catalog, which hooks wp_handle_sideload at priority 1.
 *
 * @return int|WP_Error attachment ID
 */
function gasf_crm_photo_approve( array $thread, $graph_message_id, $graph_attachment_id ) {
	$stream = (string) $thread['stream'];

	$meta = gasf_crm_graph_attachment_meta( $graph_message_id, $graph_attachment_id, $stream );
	if ( is_wp_error( $meta ) ) { return $meta; }

	// Only real images. A cloud link or an attached .eml has no bytes to fetch,
	// and a PDF in the Media Library is fine but is not a photo to catalogue.
	$type = strtolower( (string) ( $meta['contentType'] ?? '' ) );
	if ( 0 !== strpos( $type, 'image/' ) ) {
		return new WP_Error( 'gasf_crm_notimage', 'That attachment is not an image, so there is nothing to catalogue.' );
	}

	$name = sanitize_file_name( (string) ( $meta['name'] ?? 'photo.jpg' ) );
	if ( '' === pathinfo( $name, PATHINFO_EXTENSION ) ) { $name .= '.jpg'; }

	$tmp = wp_tempnam( $name );
	if ( ! $tmp ) { return new WP_Error( 'gasf_crm_tmp', 'The server could not make room to fetch that photo.' ); }

	$ok = gasf_crm_graph_attachment_stream( $graph_message_id, $graph_attachment_id, $tmp, $stream );
	if ( is_wp_error( $ok ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $ok;
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0 );
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- sideload removes it on success
		return $id;
	}

	// Provenance. Recorded because the question that gets asked about a club
	// photo two years later is not "what is it" but "where did this come from
	// and may we use it" — and by then the thread may be long since answered.
	update_post_meta( $id, '_gasf_photo_source', array(
		'thread'      => (int) $thread['id'],
		'stream'      => $stream,
		'email'       => (string) $thread['last_from_addr'],
		'name'        => (string) $thread['last_from_name'],
		'subject'     => (string) $thread['subject'],
		'approved_by' => get_current_user_id(),
		'approved_at' => current_time( 'mysql', true ),
		'graph_msg'   => (string) $graph_message_id,
	) );

	gasf_crm_log_event( (int) $thread['id'], 'photo_approved', $name . ' → media #' . $id );

	return (int) $id;
}

/** Photos already taken from a thread, newest first. */
function gasf_crm_photo_for_thread( $thread_id ) {
	$q = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 100,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array( array(
			'key'     => '_gasf_photo_source',
			'value'   => '"thread";i:' . (int) $thread_id . ';',
			'compare' => 'LIKE',
		) ),
	) );
	return array_map( 'intval', $q->posts );
}

/* =====================================================================
 * Invitations
 * ================================================================== */

function gasf_crm_photo_invite_url( $token ) {
	return home_url( '/photos/tag/' . rawurlencode( $token ) . '/' );
}

/**
 * Mint a tagging link for a set of photos.
 *
 * @return array{id:int,token:string,url:string}|WP_Error
 */
function gasf_crm_photo_invite_create( $thread_id, $email, $name, array $attachment_ids ) {
	global $wpdb;

	$email = sanitize_email( $email );
	$ids   = array_values( array_unique( array_map( 'intval', $attachment_ids ) ) );
	if ( ! is_email( $email ) ) { return new WP_Error( 'gasf_crm_bademail', 'That submitter has no usable email address.' ); }
	if ( ! $ids ) { return new WP_Error( 'gasf_crm_nophotos', 'There are no approved photos on this thread to ask about.' ); }

	// 32 bytes from the CSPRNG. Long enough that guessing is not a strategy,
	// and hex so it survives every mail client's idea of a clickable URL.
	$token = bin2hex( random_bytes( 32 ) );

	$ok = $wpdb->insert( gasf_crm_table( 'photo_invites' ), array(
		'token_hash'     => hash( 'sha256', $token ),
		'thread_id'      => (int) $thread_id,
		'email'          => $email,
		'name'           => sanitize_text_field( (string) $name ),
		'attachment_ids' => wp_json_encode( $ids ),
		'created_at'     => current_time( 'mysql', true ),
		'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + ( GASF_CRM_PHOTO_INVITE_DAYS * DAY_IN_SECONDS ) ),
	) );
	if ( ! $ok ) { return new WP_Error( 'gasf_crm_invite', 'Could not create the tagging link.' ); }

	return array(
		'id'    => (int) $wpdb->insert_id,
		'token' => $token,
		'url'   => gasf_crm_photo_invite_url( $token ),
	);
}

/**
 * Look an invite up by the token from the URL.
 *
 * Compared by hash, because that is all the database holds. Expiry is checked
 * here rather than by the caller so no route can forget to.
 */
function gasf_crm_photo_invite_by_token( $token ) {
	global $wpdb;

	$token = (string) $token;
	if ( ! preg_match( '~^[a-f0-9]{64}$~', $token ) ) { return null; }

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ' . gasf_crm_table( 'photo_invites' ) . ' WHERE token_hash = %s LIMIT 1',
		hash( 'sha256', $token )
	), ARRAY_A );
	if ( ! $row ) { return null; }

	if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) { return 'expired'; }

	$row['ids'] = array_map( 'intval', (array) json_decode( (string) $row['attachment_ids'], true ) );
	return $row;
}

/** Send the "tell us about these" email, from the mailbox they wrote to. */
function gasf_crm_photo_invite_send( array $invite, $token, $stream = 'photos' ) {
	$count = count( gasf_crm_photo_for_thread( (int) $invite['thread_id'] ) );
	$org   = gasf_crm_cfg()['signature_org'];

	$body = sprintf(
		"Hello%s,\n\n" .
		"Thank you for the %s you sent us — %s now in the club's photo collection.\n\n" .
		"Could you tell us a little about them? Who is in them, roughly when they were taken, and where. It takes a minute and it is what makes the photos findable years from now, instead of being an unlabelled pile.\n\n" .
		"%s\n\n" .
		"The link works for the next %d days and is just for you — please do not forward it.\n\n" .
		"If you would rather not, that is completely fine. The photos are safe either way and you can ignore this.\n\n" .
		"With thanks,\n%s",
		$invite['name'] ? ' ' . $invite['name'] : '',
		1 === $count ? 'photo' : 'photos',
		1 === $count ? 'it is' : 'they are',
		gasf_crm_photo_invite_url( $token ),
		(int) GASF_CRM_PHOTO_INVITE_DAYS,
		$org
	);

	$sent = gasf_crm_graph_send(
		$invite['email'],
		1 === $count ? 'About the photo you sent us' : 'About the photos you sent us',
		$body,
		$stream
	);

	if ( is_wp_error( $sent ) ) {
		gasf_mec_log( 'CRM photos: invite send FAILED to ' . $invite['email'] . ' — ' . $sent->get_error_message() );
	} else {
		gasf_crm_log_event( (int) $invite['thread_id'], 'photo_invite', 'tagging link sent to ' . $invite['email'] );
	}

	return $sent;
}

/* =====================================================================
 * The public tagging page
 * ================================================================== */

add_action( 'init', function () {
	add_rewrite_rule( '^photos/tag/([a-f0-9]{64})/?$', 'index.php?gasf_phototag=$matches[1]', 'top' );
} );

add_filter( 'query_vars', function ( $v ) {
	$v[] = 'gasf_phototag';
	return $v;
} );

add_action( 'template_redirect', function () {
	$token = (string) get_query_var( 'gasf_phototag' );
	if ( '' === $token ) { return; }

	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );

	// Wrong or stale tokens are throttled per IP. Not because the token is
	// guessable — 32 random bytes are not — but because an endpoint that does a
	// database lookup for any string handed to it should not do so unboundedly.
	$invite = gasf_crm_photo_invite_by_token( $token );
	if ( null === $invite || 'expired' === $invite ) {
		gasf_crm_photo_throttle();
		gasf_crm_photo_page( 'expired' === $invite ? 'expired' : 'unknown' );
		exit;
	}

	if ( ! empty( $_POST['gasf_phototag_save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- checked inside
		gasf_crm_photo_save_pending( $invite );
		exit;
	}

	if ( empty( $invite['opened_at'] ) ) {
		global $wpdb;
		$wpdb->update(
			gasf_crm_table( 'photo_invites' ),
			array( 'opened_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $invite['id'] ),
			array( '%s' ), array( '%d' )
		);
	}

	gasf_crm_photo_page( 'form', $invite );
	exit;
}, 1 ); // ahead of redirect_canonical, same reason as the CRM's own routes

/** Crude per-IP throttle on bad tokens. */
function gasf_crm_photo_throttle() {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'gasf_pt_' . md5( $ip );
	$n   = (int) get_transient( $key );
	set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
	if ( $n > 30 ) {
		status_header( 429 );
		header( 'Retry-After: 600' );
		exit;
	}
}

/**
 * Store the submitter's answers. Nothing here becomes a term.
 *
 * Every value is sanitised and length-capped, and the set of photos it can
 * touch comes from the INVITE rather than from the form, so a doctored post
 * cannot reach an attachment the link was not issued for.
 */
function gasf_crm_photo_save_pending( array $invite ) {
	if ( ! isset( $_POST['_gasf_pt'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gasf_pt'] ) ), 'gasf_phototag' ) ) {
		gasf_crm_photo_page( 'form', $invite, 'That form had been open a long time and expired. Nothing was lost — please try again.' );
		return;
	}

	$batch = array(
		'taken' => gasf_crm_photo_clean_date( $_POST['taken'] ?? '' ),
		'place' => sanitize_text_field( wp_unslash( $_POST['place'] ?? '' ) ),
		'event' => sanitize_text_field( wp_unslash( $_POST['event'] ?? '' ) ),
	);
	// "Somewhere else" wins over the dropdown when it has been filled in.
	$other = sanitize_text_field( wp_unslash( $_POST['place_other'] ?? '' ) );
	if ( '' !== $other ) { $batch['place'] = $other; }

	$per = isset( $_POST['photo'] ) && is_array( $_POST['photo'] ) ? wp_unslash( $_POST['photo'] ) : array();

	foreach ( $invite['ids'] as $aid ) {
		$row    = isset( $per[ $aid ] ) && is_array( $per[ $aid ] ) ? $per[ $aid ] : array();
		$people = array();
		foreach ( (array) ( $row['people'] ?? array() ) as $p ) {
			$p = sanitize_text_field( $p );
			// mb_substr, not substr: a name cut mid-character produces mojibake,
			// and half these names have umlauts in them.
			if ( '' !== $p ) { $people[] = function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 80 ) : substr( $p, 0, 80 ); }
			if ( count( $people ) >= GASF_CRM_PHOTO_MAX_PEOPLE ) { break; }
		}

		$caption = sanitize_text_field( (string) ( $row['caption'] ?? '' ) );
		$caption = function_exists( 'mb_substr' )
			? mb_substr( $caption, 0, GASF_CRM_PHOTO_CAPTION_MAX )
			: substr( $caption, 0, GASF_CRM_PHOTO_CAPTION_MAX );

		update_post_meta( $aid, '_gasf_photo_pending', array(
			'people'  => array_values( array_unique( $people ) ),
			'caption' => $caption,
			'taken'   => gasf_crm_photo_clean_date( $row['taken'] ?? '' ) ?: $batch['taken'],
			'place'   => $batch['place'],
			'event'   => $batch['event'],
			'by'      => (string) $invite['email'],
			'at'      => current_time( 'mysql', true ),
		) );
	}

	global $wpdb;
	$wpdb->update(
		gasf_crm_table( 'photo_invites' ),
		array( 'submitted_at' => current_time( 'mysql', true ) ),
		array( 'id' => (int) $invite['id'] ),
		array( '%s' ), array( '%d' )
	);

	gasf_crm_log_event( (int) $invite['thread_id'], 'photo_tagged', $invite['email'] . ' described ' . count( $invite['ids'] ) . ' photo(s)' );
	gasf_crm_photo_notify_review( $invite, count( $invite['ids'] ) );

	gasf_crm_photo_page( 'thanks', $invite );
}

/**
 * Tell the photo volunteers that answers are waiting.
 *
 * The submitter is told, in as many words, that one of our volunteers will
 * check it over. Until this existed nothing made that true: the answers landed
 * in the database and sat there until somebody happened to reopen the right
 * thread. A promise made to a member of the public on the club's behalf is
 * worth actually keeping, and "it saved fine" is the most convincing way for
 * something to fail.
 */
function gasf_crm_photo_notify_review( array $invite, $count ) {
	$to = array();
	foreach ( gasf_crm_notify_recipients() as $addr => $streams ) {
		if ( in_array( 'photos', (array) $streams, true ) ) { $to[] = $addr; }
	}

	if ( ! $to ) {
		// Loudly, because from here it looks like success: the submitter has
		// been thanked and the data is stored, but nobody can see it.
		gasf_mec_log( 'CRM photos: ' . $invite['email'] . ' described ' . (int) $count
			. ' photo(s) but NOBODY holds the photos stream — the answers are waiting with no one to review them.' );
		return;
	}

	$body = sprintf(
		"%s has described %d photo%s they sent to the club.\n\n" .
		"Their answers are waiting for someone to check before they become tags. Nothing they wrote has been applied yet.\n\n" .
		"%s\n\n" .
		"Open the message from them and the photos are at the bottom, with what they told us in editable boxes. Correct anything that looks off and press \"Add these tags\".",
		$invite['name'] ? $invite['name'] . ' (' . $invite['email'] . ')' : $invite['email'],
		(int) $count,
		1 === (int) $count ? '' : 's',
		home_url( '/email' )
	);

	foreach ( $to as $addr ) {
		gasf_crm_graph_send( $addr, 'Photo descriptions waiting to be checked', $body, 'photos' );
	}
}

/**
 * Threads holding photos whose tags nobody has confirmed yet, thread => count.
 *
 * Drives the banner at the top of /email. Without it the only way to discover
 * a submission is to reopen the thread it came from and notice.
 */
function gasf_crm_photo_pending_threads() {
	$out = array();
	foreach ( gasf_crm_photo_pending_ids() as $aid ) {
		$src = get_post_meta( $aid, '_gasf_photo_source', true );
		$tid = (int) ( is_array( $src ) ? ( $src['thread'] ?? 0 ) : 0 );
		if ( $tid ) { $out[ $tid ] = ( $out[ $tid ] ?? 0 ) + 1; }
	}
	return $out;
}

/** Y-m-d or ''. checkdate rejects the 31st of February, which a text input will post. */
function gasf_crm_photo_clean_date( $raw ) {
	$d = trim( sanitize_text_field( (string) $raw ) );
	if ( ! preg_match( '~^(\d{4})-(\d{2})-(\d{2})$~', $d, $m ) ) { return ''; }
	if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) { return ''; }
	return $d;
}

/* =====================================================================
 * Review — pending answers become real tags
 * ================================================================== */

/** Photos with answers waiting to be confirmed. */
function gasf_crm_photo_pending_ids() {
	$q = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array( array( 'key' => '_gasf_photo_pending', 'compare' => 'EXISTS' ) ),
	) );
	return array_map( 'intval', $q->posts );
}

/**
 * Turn one photo's pending answers into terms.
 *
 * $keep is the volunteer's edit of what the submitter wrote — corrected names,
 * dropped ones. Terms are created here and nowhere else, which is the whole
 * point of the pending step: the vocabulary can only grow through somebody who
 * is signed in and permitted.
 *
 * Capability is checked by the CRM's own model rather than by a WordPress role.
 * Volunteers are deliberately created with no role at all, so user_can() would
 * refuse every one of them; holding the photos stream IS the permission.
 */
function gasf_crm_photo_confirm( $attachment_id, array $keep ) {
	$id = (int) $attachment_id;
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) { return new WP_Error( 'gasf_crm_404', 'No such photo.' ); }
	if ( ! gasf_crm_user_can_stream( 'photos' ) ) {
		return new WP_Error( 'gasf_crm_403', 'You do not have access to photo submissions.' );
	}

	$people = array();
	foreach ( (array) ( $keep['people'] ?? array() ) as $p ) {
		$p = trim( sanitize_text_field( $p ) );
		if ( '' !== $p ) { $people[] = $p; }
	}
	$people = array_slice( array_values( array_unique( $people ) ), 0, GASF_CRM_PHOTO_MAX_PEOPLE );

	// wp_set_object_terms with names creates any that do not exist. That is
	// exactly what is wanted HERE and exactly what must not happen on the public
	// form.
	wp_set_object_terms( $id, $people, 'gasf_photo_person', false );

	foreach ( array( 'place' => 'gasf_photo_place', 'event' => 'gasf_photo_event' ) as $k => $tax ) {
		$v = trim( sanitize_text_field( (string) ( $keep[ $k ] ?? '' ) ) );
		wp_set_object_terms( $id, '' === $v ? array() : array( $v ), $tax, false );
	}

	$taken = gasf_crm_photo_clean_date( $keep['taken'] ?? '' );
	if ( $taken ) { update_post_meta( $id, '_gasf_photo_taken', $taken ); }

	$caption = trim( sanitize_text_field( (string) ( $keep['caption'] ?? '' ) ) );
	if ( '' !== $caption ) {
		wp_update_post( array( 'ID' => $id, 'post_excerpt' => $caption ) );
	}

	// Clearing the pending record is what takes it off the review list. Kept as
	// history on the photo so "who said this was Hans" stays answerable.
	$was = get_post_meta( $id, '_gasf_photo_pending', true );
	delete_post_meta( $id, '_gasf_photo_pending' );
	update_post_meta( $id, '_gasf_photo_confirmed', array(
		'from' => $was,
		'by'   => get_current_user_id(),
		'at'   => current_time( 'mysql', true ),
	) );

	return true;
}

/* =====================================================================
 * REST — the volunteer side, from /email
 *
 * Registered here rather than in rest.php so the module owns its own surface.
 * Every route goes through gasf_crm_rest_thread(), which is the single gate
 * that resolves a thread AND checks the caller holds its stream — a photos
 * volunteer must not be able to reach a general thread by guessing an ID, and
 * an approver must not be able to pull an attachment from an inbox they cannot
 * list.
 * ================================================================== */

add_action( 'rest_api_init', function () {
	$guard = 'gasf_crm_rest_guard';

	register_rest_route( 'gasf/v1', '/crm/photos/approve', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$thread = gasf_crm_rest_thread( (int) $req->get_param( 'id' ) );
			if ( is_wp_error( $thread ) ) { return $thread; }

			$id = gasf_crm_photo_approve(
				$thread,
				(string) $req->get_param( 'msg' ),
				(string) $req->get_param( 'att' )
			);
			if ( is_wp_error( $id ) ) { return $id; }

			return array(
				'id'    => $id,
				'thumb' => wp_get_attachment_image_url( $id, 'thumbnail' ),
				'count' => count( gasf_crm_photo_for_thread( (int) $thread['id'] ) ),
			);
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/invite', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$thread = gasf_crm_rest_thread( (int) $req->get_param( 'id' ) );
			if ( is_wp_error( $thread ) ) { return $thread; }

			$ids = gasf_crm_photo_for_thread( (int) $thread['id'] );
			$inv = gasf_crm_photo_invite_create(
				(int) $thread['id'],
				(string) $thread['last_from_addr'],
				(string) $thread['last_from_name'],
				$ids
			);
			if ( is_wp_error( $inv ) ) { return $inv; }

			$sent = gasf_crm_photo_invite_send( array(
				'thread_id' => (int) $thread['id'],
				'email'     => (string) $thread['last_from_addr'],
				'name'      => (string) $thread['last_from_name'],
			), $inv['token'], (string) $thread['stream'] );

			if ( is_wp_error( $sent ) ) {
				return new WP_Error(
					'gasf_crm_sendfail',
					'The link was created but the email did not go out: ' . $sent->get_error_message(),
					array( 'status' => 502 )
				);
			}

			return array( 'sent' => true, 'to' => (string) $thread['last_from_addr'], 'photos' => count( $ids ) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/confirm', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$thread = gasf_crm_rest_thread( (int) $req->get_param( 'id' ) );
			if ( is_wp_error( $thread ) ) { return $thread; }

			// The photo must belong to THIS thread. Without this a volunteer
			// could confirm tags onto any attachment ID in the library by
			// pairing it with a thread they legitimately hold.
			$aid = (int) $req->get_param( 'photo' );
			if ( ! in_array( $aid, gasf_crm_photo_for_thread( (int) $thread['id'] ), true ) ) {
				return new WP_Error( 'gasf_crm_404', 'That photo is not part of this submission.', array( 'status' => 404 ) );
			}

			$ok = gasf_crm_photo_confirm( $aid, array(
				'people'  => (array) $req->get_param( 'people' ),
				'place'   => (string) $req->get_param( 'place' ),
				'event'   => (string) $req->get_param( 'event' ),
				'taken'   => (string) $req->get_param( 'taken' ),
				'caption' => (string) $req->get_param( 'caption' ),
			) );
			if ( is_wp_error( $ok ) ) { return $ok; }

			gasf_crm_log_event( (int) $thread['id'], 'photo_confirmed', 'tags approved for media #' . $aid );
			return array( 'ok' => true, 'photo' => $aid );
		},
	) );
} );

/**
 * The photo block for a thread, as the reading pane needs it.
 *
 * Called from the thread-detail route so a volunteer sees the state of the
 * whole submission in one place: what has been kept, what the sender said
 * about it, and what is still waiting on somebody.
 */
function gasf_crm_photo_thread_block( $thread_id ) {
	$out = array();
	foreach ( gasf_crm_photo_for_thread( $thread_id ) as $id ) {
		$pending   = get_post_meta( $id, '_gasf_photo_pending', true );
		$confirmed = get_post_meta( $id, '_gasf_photo_confirmed', true );
		$info      = function_exists( 'gasf_photo_info' ) ? gasf_photo_info( $id ) : array();

		$out[] = array(
			'id'        => $id,
			'thumb'     => wp_get_attachment_image_url( $id, 'thumbnail' ),
			'link'      => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			'taken'     => $info['taken'] ?? '',
			'guess'     => ( ! empty( $info['place_guess'] ) && ! is_wp_error( $info['place_guess'] ) ) ? $info['place_guess']->name : '',
			'alts'      => ! empty( $info['place_alts'] ) ? wp_list_pluck( $info['place_alts'], 'name' ) : array(),
			'people'    => $info['people'] ?? array(),
			'caption'   => $info['caption'] ?? '',
			'pending'   => is_array( $pending ) ? $pending : null,
			'confirmed' => ! empty( $confirmed ),
		);
	}
	return $out;
}

/**
 * Provenance on the Media Library screen.
 *
 * The question asked about a club photo two years from now is rarely "what is
 * it" — it is "who gave us this, and may we use it". By then the thread has
 * long been answered, the volunteer who kept it may have moved on, and the
 * mailbox may have been tidied. Recording it against the photo and never
 * showing it would have been filing the answer somewhere nobody looks.
 *
 * Read-only. This is a record of what happened, not a field to curate; an
 * editable provenance is worth less than none.
 */
add_filter( 'attachment_fields_to_edit', function ( $fields, $post ) {
	$src = get_post_meta( $post->ID, '_gasf_photo_source', true );
	if ( ! is_array( $src ) || empty( $src['email'] ) ) { return $fields; }

	$who = trim( (string) ( $src['name'] ?? '' ) );
	$out = '<span class="description">' . esc_html( $who ? $who . ' <' . $src['email'] . '>' : $src['email'] ) . '<br>';

	if ( ! empty( $src['subject'] ) ) {
		$out .= '<em>' . esc_html( $src['subject'] ) . '</em><br>';
	}
	if ( ! empty( $src['approved_at'] ) ) {
		$by = ! empty( $src['approved_by'] ) ? get_userdata( (int) $src['approved_by'] ) : null;
		$out .= esc_html( sprintf(
			'Kept %s%s',
			mysql2date( get_option( 'date_format' ), $src['approved_at'] ),
			$by ? ' by ' . $by->display_name : ''
		) ) . '<br>';
	}

	// Say where it is in the loop, so a half-finished submission is visible
	// from the photo as well as from the CRM.
	if ( get_post_meta( $post->ID, '_gasf_photo_pending', true ) ) {
		$out .= '<strong style="color:#b32d2e">The sender has described this, and it is waiting to be checked.</strong>';
	} elseif ( get_post_meta( $post->ID, '_gasf_photo_confirmed', true ) ) {
		$out .= '<strong style="color:#2c7a3f">Described by the sender and confirmed.</strong>';
	} else {
		$out .= 'Nobody has described it yet.';
	}

	$fields['gasf_photo_src'] = array(
		'label' => __( 'Sent to us by', 'gasf' ),
		'input' => 'html',
		'html'  => $out . '</span>',
	);

	return $fields;
}, 20, 2 );

require_once __DIR__ . '/photos-page.php';
