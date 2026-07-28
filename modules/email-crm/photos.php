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

/**
 * The chase.
 *
 * A photo whose sender has been asked about it sits in purgatory: kept, but not
 * shown to a reviewer as needing anything, because the person who actually
 * knows what it is has been asked and deserves the chance to answer. One
 * reminder goes out on day 2. If day 5 arrives with nothing, the photo is
 * released and a volunteer tags it from what they can see.
 *
 * Release is DERIVED from the invite date rather than stored, so it cannot
 * drift out of step with reality or need a migration if these numbers change.
 */
define( 'GASF_CRM_PHOTO_REMIND_DAYS', 2 );
define( 'GASF_CRM_PHOTO_RELEASE_DAYS', 5 );

/** Caps on what one submitter can type, so a form post cannot become a flood. */
define( 'GASF_CRM_PHOTO_MAX_PEOPLE', 25 );
define( 'GASF_CRM_PHOTO_CAPTION_MAX', 150 );

/**
 * Most images one email may bring in by itself.
 *
 * A club submission is a handful of photos. A message carrying dozens is either
 * a mistake or somebody dumping something, and either way it should stop and
 * wait for a person rather than pour into the Media Library unattended. The
 * remainder is NOT silently dropped — the message stays unprocessed and says so
 * in the log, so a volunteer can take them in deliberately.
 */
define( 'GASF_CRM_PHOTO_MAX_PER_MESSAGE', 30 );

/**
 * Where a photo lives before anybody has looked at it.
 *
 * An unreviewed submission must not sit at a public URL. Nothing links to it,
 * but "nothing links to it" is not access control, and for the one category of
 * content nobody wants to receive, the difference between "it was in our
 * mailbox" and "it was being served from our website" is the whole difference.
 *
 * So intake writes into a directory the web server refuses to serve, and the
 * images are reachable only through a handler: by the submitter with the token
 * they were emailed, or by a signed-in volunteer who holds the photos stream.
 * Approving a photo moves it out into the normal uploads folder, where it
 * becomes an ordinary public attachment — which is what it is by then.
 */
define( 'GASF_CRM_PHOTO_REVIEW_DIR', 'gasf-photo-review' );

/**
 * The private root — OUTSIDE the web server's document root entirely.
 *
 * This used to be a folder inside uploads protected by .htaccess. That works
 * only for as long as Apache reads .htaccess: a move to nginx, a hosting
 * migration, or a control-panel setting flipping AllowOverride would remove the
 * protection silently, leaving unreviewed photos publicly served with nothing
 * anywhere reporting the change.
 *
 * A directory the web server has no path to cannot be served by
 * misconfiguration. The .htaccess is still written as a second line of defence,
 * but nothing depends on it now.
 *
 * ABSPATH's parent is the account home here, above public_html.
 */
function gasf_crm_photo_private_root() {
	// Named for the marker deliberately. During sideload the upload_dir filter
	// below reports this directory's PARENT as basedir, so WordPress's own
	// _wp_relative_upload_path() reduces the absolute path to
	// "gasf-photo-review/name.jpg" — the same prefix everything else tests for.
	// Without that alignment the path stays absolute and no marker check works.
	$root = dirname( untrailingslashit( ABSPATH ) ) . '/' . GASF_CRM_PHOTO_REVIEW_DIR;
	return (string) apply_filters( 'gasf_crm_photo_private_root', $root );
}

/**
 * Is this attachment's file in the private root?
 *
 * Decided from the stored relative path rather than from the filesystem, so it
 * stays correct while a file is mid-move and needs no disk access to answer.
 */
function gasf_crm_photo_private_rel( $attachment_id ) {
	$rel = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
	return ( 0 === strpos( $rel, GASF_CRM_PHOTO_REVIEW_DIR . '/' ) ) ? $rel : '';
}

/*
 * WordPress resolves _wp_attached_file against the uploads basedir, which is
 * the wrong place for anything we have put outside the webroot. These three
 * filters keep core able to find, size and delete those files without moving
 * them back somewhere servable.
 */
add_filter( 'get_attached_file', function ( $file, $id ) {
	$rel = gasf_crm_photo_private_rel( $id );
	return $rel ? gasf_crm_photo_private_root() . '/' . basename( $rel ) : $file;
}, 10, 2 );

add_filter( 'wp_get_attachment_url', function ( $url, $id ) {
	// A private file has no public URL. Returning the uploads path anyway would
	// hand out a 404 that looks like a broken image rather than a boundary.
	return gasf_crm_photo_private_rel( $id ) ? '' : $url;
}, 10, 2 );

add_action( 'delete_attachment', function ( $id ) {
	$rel = gasf_crm_photo_private_rel( $id );
	if ( ! $rel ) { return; }

	// Deleted here because core will look under uploads and find nothing —
	// leaving the actual bytes behind, which for unreviewed submissions is the
	// one outcome that must not happen quietly.
	$root = gasf_crm_photo_private_root();
	$meta = (array) wp_get_attachment_metadata( $id );

	$names = array( basename( $rel ) );
	if ( ! empty( $meta['original_image'] ) ) { $names[] = $meta['original_image']; }
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
		if ( ! empty( $s['file'] ) ) { $names[] = $s['file']; }
	}
	foreach ( array_unique( $names ) as $n ) {
		$p = $root . '/' . basename( $n );
		if ( is_file( $p ) ) { @unlink( $p ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
} );

/**
 * The review directory, created with its refusal in place.
 *
 * The .htaccess is written BEFORE the directory is used, never after, so there
 * is no window in which files are readable. If it cannot be written, intake
 * refuses to store anything rather than quietly falling back to a public
 * folder — a protection that silently is not one is worse than none.
 *
 * @return string|WP_Error absolute path
 */
function gasf_crm_photo_review_dir() {
	$path = gasf_crm_photo_private_root();

	if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
		return new WP_Error( 'gasf_crm_dir', 'Could not create the private review folder.' );
	}

	// Belt and braces only. The directory is above the document root, so the
	// web server has no path to it at all — but a future move that puts it back
	// under public_html should not silently lose the protection too.

	$ht = $path . '/.htaccess';
	if ( ! file_exists( $ht ) ) {
		$rules = "# Photos awaiting review. Served only through the CRM's handler.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
		if ( false === file_put_contents( $ht, $rules ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'gasf_crm_dir', 'Could not protect the review folder; refusing to store photos in the open.' );
		}
	}
	if ( ! file_exists( $path . '/index.php' ) ) {
		file_put_contents( $path . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	return $path;
}

/** Is this attachment still in the private folder? */
function gasf_crm_photo_is_private( $attachment_id ) {
	$rel = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
	return 0 === strpos( $rel, GASF_CRM_PHOTO_REVIEW_DIR . '/' );
}

/**
 * Move an approved photo out of the private folder into normal uploads.
 *
 * The whole set moves together — the full-size file, WordPress's untouched
 * original, and every generated size — because they share a directory and the
 * metadata records the sizes as bare filenames relative to it.
 *
 * The name was already made unique against the destination at intake, so this
 * cannot collide with an existing file and no size has to be renamed.
 */
function gasf_crm_photo_publish( $attachment_id ) {
	$id = (int) $attachment_id;
	if ( ! gasf_crm_photo_is_private( $id ) ) { return true; }

	$up = wp_upload_dir( get_post_field( 'post_date', $id ) );
	if ( ! empty( $up['error'] ) ) { return new WP_Error( 'gasf_crm_pub', $up['error'] ); }
	if ( ! wp_mkdir_p( $up['path'] ) ) { return new WP_Error( 'gasf_crm_pub', 'Could not prepare the uploads folder.' ); }

	$rel  = (string) get_post_meta( $id, '_wp_attached_file', true );
	$from = trailingslashit( gasf_crm_photo_private_root() );
	$meta = (array) wp_get_attachment_metadata( $id );

	$names = array( basename( $rel ) );
	if ( ! empty( $meta['original_image'] ) ) { $names[] = $meta['original_image']; }
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
		if ( ! empty( $s['file'] ) ) { $names[] = $s['file']; }
	}

	foreach ( array_unique( $names ) as $n ) {
		$src = $from . $n;
		$dst = trailingslashit( $up['path'] ) . $n;
		if ( ! file_exists( $src ) || file_exists( $dst ) ) { continue; }
		if ( ! @rename( $src, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'gasf_crm_pub', 'Could not move ' . $n . ' out of the review folder.' );
		}
	}

	$new_rel = ltrim( trailingslashit( ltrim( (string) $up['subdir'], '/' ) ) . basename( $rel ), '/' );
	update_post_meta( $id, '_wp_attached_file', $new_rel );
	if ( $meta ) {
		$meta['file'] = $new_rel;
		wp_update_attachment_metadata( $id, $meta );
	}

	gasf_mec_log( 'CRM photos: media #' . $id . ' published to ' . $new_rel );
	return true;
}

/**
 * Move an unreviewed photo the other way — out of public uploads and into the
 * private folder.
 *
 * Needed because photos taken in before this existed are sitting in the open.
 * Claiming "unreviewed photos are not public" while some of them are is worse
 * than not claiming it, so they get moved rather than explained away.
 *
 * Runs on demand, not on a schedule: it is a one-off correction of history.
 */
function gasf_crm_photo_unpublish( $attachment_id ) {
	$id = (int) $attachment_id;
	if ( gasf_crm_photo_is_private( $id ) ) { return true; }

	$review = gasf_crm_photo_review_dir();
	if ( is_wp_error( $review ) ) { return $review; }

	$rel  = (string) get_post_meta( $id, '_wp_attached_file', true );
	if ( '' === $rel ) { return new WP_Error( 'gasf_crm_unpub', 'No file on that attachment.' ); }

	// Coming FROM public uploads, where the relative path is still meaningful.
	$base = trailingslashit( wp_upload_dir()['basedir'] );
	$from = trailingslashit( dirname( $base . $rel ) );
	$meta = (array) wp_get_attachment_metadata( $id );

	$names = array( basename( $rel ) );
	if ( ! empty( $meta['original_image'] ) ) { $names[] = $meta['original_image']; }
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
		if ( ! empty( $s['file'] ) ) { $names[] = $s['file']; }
	}

	foreach ( array_unique( $names ) as $n ) {
		$src = $from . $n;
		$dst = trailingslashit( $review ) . $n;
		if ( ! file_exists( $src ) || file_exists( $dst ) ) { continue; }
		if ( ! @rename( $src, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'gasf_crm_unpub', 'Could not move ' . $n . ' into the review folder.' );
		}
	}

	$new_rel = GASF_CRM_PHOTO_REVIEW_DIR . '/' . basename( $rel );
	update_post_meta( $id, '_wp_attached_file', $new_rel );
	if ( $meta ) {
		$meta['file'] = $new_rel;
		wp_update_attachment_metadata( $id, $meta );
	}

	gasf_mec_log( 'CRM photos: media #' . $id . ' withdrawn from public uploads pending review' );
	return true;
}

/* =====================================================================
 * Approval — Graph attachment to Media Library
 * ================================================================== */

/**
 * Who sent a given message — read from THAT message, never from the thread.
 *
 * thread.last_from_* is whoever wrote most recently, which on a live thread is
 * routinely somebody else: a second person joining in, a bounce, or the club's
 * own reply. Binding a photo's provenance or an invitation to it means the
 * record can name the wrong person and the "tell us about your photos" link can
 * be emailed to somebody who never sent any.
 *
 * @return array{email:string,name:string}|WP_Error
 */
function gasf_crm_photo_message_sender( $graph_message_id ) {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT from_addr, from_name, direction FROM ' . gasf_crm_table( 'messages' ) . '
		  WHERE graph_message_id = %s LIMIT 1',
		(string) $graph_message_id
	), ARRAY_A );

	if ( ! $row ) {
		return new WP_Error( 'gasf_crm_nosender', 'No record of the message these photos came on.' );
	}
	// Outbound would mean addressing the club's own reply, which is never right.
	if ( 'in' !== $row['direction'] ) {
		return new WP_Error( 'gasf_crm_nosender', 'That message was sent by the club, not to it.' );
	}

	$email = sanitize_email( (string) $row['from_addr'] );
	if ( ! is_email( $email ) ) {
		// Refused rather than falling back to the thread. A wrong address here
		// emails a stranger about photos they did not send.
		return new WP_Error( 'gasf_crm_nosender', 'That message has no usable sender address.' );
	}

	return array( 'email' => $email, 'name' => sanitize_text_field( (string) $row['from_name'] ) );
}

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

	// Resolved before anything is downloaded. A photo whose sender cannot be
	// established is not taken in at all — an unattributable image in the club's
	// collection is worse than a missing one, because nobody can later say
	// whether there was permission to use it.
	$sender = gasf_crm_photo_message_sender( $graph_message_id );
	if ( is_wp_error( $sender ) ) { return $sender; }

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

	$review = gasf_crm_photo_review_dir();
	if ( is_wp_error( $review ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $review;
	}

	// Make the name unique against where it will EVENTUALLY live, not against
	// the review folder. Approving then moves the set without a single rename,
	// and no generated size ever has to be renumbered.
	$public = wp_upload_dir();
	if ( empty( $public['error'] ) ) { $name = wp_unique_filename( $public['path'], $name ); }

	// basedir moves too, not just path. WordPress derives _wp_attached_file by
	// stripping basedir from the absolute path, and a file outside the real
	// basedir would otherwise be recorded as an absolute path that no marker
	// check recognises.
	$to_review = function ( $dirs ) use ( $review ) {
		$dirs['basedir'] = dirname( $review );
		$dirs['path']    = $review;
		$dirs['subdir']  = '/' . GASF_CRM_PHOTO_REVIEW_DIR;
		// No public URL exists for any of this. Pointed at the site root rather
		// than left as a plausible-looking uploads path, so anything that does
		// reach for it fails obviously instead of 404ing like a broken image.
		$dirs['baseurl'] = home_url();
		$dirs['url']     = home_url();
		return $dirs;
	};

	add_filter( 'upload_dir', $to_review, 99 );
	$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0 );
	remove_filter( 'upload_dir', $to_review, 99 );

	if ( is_wp_error( $id ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- sideload removes it on success
		return $id;
	}

	// Provenance, bound to the message these bytes actually arrived on.
	//
	// The question asked about a club photo two years later is not "what is it"
	// but "who gave us this and may we use it", so the answer must not drift
	// when somebody else replies to the same thread. Written once and never
	// rewritten afterwards.
	update_post_meta( $id, '_gasf_photo_source', array(
		'thread'      => (int) $thread['id'],
		'stream'      => $stream,
		'email'       => $sender['email'],
		'name'        => $sender['name'],
		'subject'     => (string) $thread['subject'],
		'approved_by' => get_current_user_id(),
		'approved_at' => current_time( 'mysql', true ),
		'graph_msg'   => (string) $graph_message_id,
		'graph_att'   => (string) $graph_attachment_id,
	) );

	// One exact key per Graph attachment, so "have we already got this?" is an
	// indexed equality rather than a LIKE against serialised data. Without it
	// the automatic intake happily made a second copy of a photo a volunteer
	// had already kept by hand.
	update_post_meta( $id, '_gasf_photo_key', gasf_crm_photo_key( $graph_message_id, $graph_attachment_id ) );

	// Seeded at zero so the row exists before anybody can decide about it.
	// update_post_meta's compare-and-swap only compares when a row is there —
	// with none, it adds and ignores the expected value, so two simultaneous
	// first approvals would both succeed. Writing it here removes that case.
	update_post_meta( $id, '_gasf_photo_rev', 0 );

	gasf_crm_log_event( (int) $thread['id'], 'photo_approved', $name . ' → media #' . $id );

	return (int) $id;
}

/** Stable identity for one attachment on one message. */
function gasf_crm_photo_key( $graph_message_id, $graph_attachment_id ) {
	return sha1( (string) $graph_message_id . '|' . (string) $graph_attachment_id );
}

/** Have we already taken this exact attachment in? Returns the attachment ID or 0. */
function gasf_crm_photo_already_kept( $graph_message_id, $graph_attachment_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_gasf_photo_key' AND meta_value = %s LIMIT 1",
		gasf_crm_photo_key( $graph_message_id, $graph_attachment_id )
	) );
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
function gasf_crm_photo_invite_create( $thread_id, $email, $name, array $attachment_ids, $stream = '', $graph_message_id = '' ) {
	global $wpdb;

	$email = sanitize_email( $email );
	$ids   = array_values( array_unique( array_map( 'intval', $attachment_ids ) ) );
	if ( ! is_email( $email ) ) { return new WP_Error( 'gasf_crm_bademail', 'That submitter has no usable email address.' ); }
	if ( ! $ids ) { return new WP_Error( 'gasf_crm_nophotos', 'There are no approved photos on this thread to ask about.' ); }

	// Every photo in one invitation must have come from the same person on the
	// same message. Otherwise a thread carrying two people's photos would send
	// one of them a link showing the other's, which is the same disclosure the
	// stream boundary exists to prevent.
	foreach ( $ids as $aid ) {
		$src = get_post_meta( $aid, '_gasf_photo_source', true );
		if ( ! is_array( $src ) || 0 !== strcasecmp( (string) ( $src['email'] ?? '' ), $email ) ) {
			return new WP_Error(
				'gasf_crm_mixed',
				'Those photos did not all come from the same sender, so no link has been created.'
			);
		}
	}

	// 32 bytes from the CSPRNG. Long enough that guessing is not a strategy,
	// and hex so it survives every mail client's idea of a clickable URL.
	$token = bin2hex( random_bytes( 32 ) );

	$ok = $wpdb->insert( gasf_crm_table( 'photo_invites' ), array(
		'token_hash'     => hash( 'sha256', $token ),
		'thread_id'      => (int) $thread_id,
		// Recorded immutably: which mailbox and which message this link was
		// issued for, so an invitation can always be traced to its origin
		// without re-deriving it from a thread that has since moved on.
		'stream'         => substr( (string) $stream, 0, 32 ),
		'graph_message_id' => (string) $graph_message_id,
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
	// Handed back so the page can build image URLs with it. Not a leak: the
	// caller supplied it, and the database still holds only the hash.
	$row['token'] = $token;
	return $row;
}

/**
 * Spend an invitation, atomically.
 *
 * The database decides, not the caller. A single UPDATE with the conditions in
 * its WHERE clause means exactly one request can win however many arrive at
 * once: the row moves from unsubmitted to submitted or it does not, and the
 * affected-row count says which happened. Checking first and writing second —
 * what this used to do — leaves a window between the two in which a second
 * submission passes the same check.
 *
 * Winning also invalidates the siblings. A reminder mints a second live link
 * for the same photos, and once somebody has answered, the other link must stop
 * working rather than allow a second, different set of answers later.
 *
 * @return bool true if THIS caller consumed it
 */
function gasf_crm_photo_invite_consume( $invite ) {
	global $wpdb;

	$t   = gasf_crm_table( 'photo_invites' );
	$now = current_time( 'mysql', true );

	$won = (int) $wpdb->query( $wpdb->prepare(
		"UPDATE {$t} SET submitted_at = %s
		  WHERE id = %d AND submitted_at IS NULL AND expires_at > %s",
		$now, (int) $invite['id'], $now
	) );
	if ( 1 !== $won ) { return false; }

	// Siblings: same thread, same message, still open. Closed with the same
	// timestamp so the history reads as one event rather than several.
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$t} SET submitted_at = %s
		  WHERE id <> %d AND thread_id = %d AND submitted_at IS NULL",
		$now, (int) $invite['id'], (int) $invite['thread_id']
	) );

	return true;
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
	// Images for the tagging page. The token in the path is the same one that
	// opens the form, so somebody can see exactly the photos they were asked
	// about and nothing else.
	add_rewrite_rule(
		'^photos/img/([a-f0-9]{64})/(\d+)/([a-z0-9_-]+)/?$',
		'index.php?gasf_photoimg=$matches[1]&gasf_photoid=$matches[2]&gasf_photosize=$matches[3]',
		'top'
	);
} );

add_filter( 'query_vars', function ( $v ) {
	$v[] = 'gasf_phototag';
	$v[] = 'gasf_photoimg';
	$v[] = 'gasf_photoid';
	$v[] = 'gasf_photosize';
	return $v;
} );

/**
 * URL for a photo that is not public.
 *
 * $token gives the submitter's path; without one the volunteer's REST route is
 * used, which needs their session. A photo already published falls through to
 * its ordinary URL — once approved there is nothing to gate.
 */
function gasf_crm_photo_img_url( $attachment_id, $size = 'medium', $token = '' ) {
	$id = (int) $attachment_id;
	if ( ! gasf_crm_photo_is_private( $id ) ) {
		$u = wp_get_attachment_image_url( $id, $size );
		return $u ? $u : (string) wp_get_attachment_url( $id );
	}

	$size = preg_replace( '~[^a-z0-9_-]~', '', strtolower( (string) $size ) ) ?: 'full';

	if ( $token ) {
		return home_url( '/photos/img/' . rawurlencode( $token ) . '/' . $id . '/' . $size . '/' );
	}
	return add_query_arg( array(
		'photo'    => $id,
		'size'     => $size,
		'_wpnonce' => wp_create_nonce( 'wp_rest' ),
	), rest_url( 'gasf/v1/crm/photos/file' ) );
}

/**
 * Send one private image, or die trying — never a redirect to the real file,
 * which would hand out the very URL this exists to withhold.
 */
function gasf_crm_photo_send_file( $attachment_id, $size ) {
	$id   = (int) $attachment_id;
	$file = get_attached_file( $id );

	if ( 'full' !== $size ) {
		$img = image_get_intermediate_size( $id, $size );
		if ( $img && ! empty( $img['path'] ) ) {
			// Sizes live beside the original. For a private attachment that is
			// the private root, not uploads, which is what image_get_intermediate_size
			// assumes when it builds its relative path.
			$file = gasf_crm_photo_private_rel( $id )
				? gasf_crm_photo_private_root() . '/' . basename( $img['path'] )
				: trailingslashit( wp_upload_dir()['basedir'] ) . $img['path'];
		}
	}
	if ( ! $file || ! file_exists( $file ) ) { status_header( 404 ); exit; }

	$type = wp_check_filetype( $file );
	// Whitelisted from our own check, never from the request: this streams a
	// file straight to a browser and the Content-Type is what decides how it
	// gets interpreted.
	$mime = ( ! empty( $type['type'] ) && 0 === strpos( $type['type'], 'image/' ) ) ? $type['type'] : 'application/octet-stream';

	nocache_headers();
	header( 'Content-Type: ' . $mime );
	header( 'Content-Length: ' . filesize( $file ) );
	header( 'Content-Disposition: inline; filename="' . sanitize_file_name( basename( $file ) ) . '"' );
	// Not public and not shared: no proxy or CDN should keep a copy of a photo
	// nobody has reviewed yet.
	header( 'Cache-Control: private, no-store, max-age=0' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

add_action( 'template_redirect', function () {
	// Images first: same token, and it must only ever open the photos that
	// token was actually issued for.
	$imgtok = (string) get_query_var( 'gasf_photoimg' );
	if ( '' !== $imgtok ) {
		$inv = gasf_crm_photo_invite_by_token( $imgtok );
		if ( ! is_array( $inv ) ) { gasf_crm_photo_throttle(); status_header( 404 ); exit; }

		$want = (int) get_query_var( 'gasf_photoid' );
		if ( ! in_array( $want, (array) $inv['ids'], true ) ) { status_header( 404 ); exit; }

		gasf_crm_photo_send_file( $want, (string) get_query_var( 'gasf_photosize' ) );
	}

	$token = (string) get_query_var( 'gasf_phototag' );
	if ( '' === $token ) { return; }

	// Same reasoning as /email: a cached tagging page would show one submitter
	// another's photos, and a cached image is the exact thing the private
	// folder exists to prevent.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
	nocache_headers();
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true );
	header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );
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

	// Spend the token BEFORE writing anything. Losing this race means somebody
	// else already answered — most often the same person double-tapping, or
	// refreshing the page they just submitted — and the honest response is to
	// thank them, because they did tell us. It is not an error to report at
	// them; it is simply already done.
	if ( ! gasf_crm_photo_invite_consume( $invite ) ) {
		gasf_crm_photo_page( 'thanks', $invite );
		return;
	}

	// Everything is per photo now. Six photos emailed together are often one
	// afternoon, but "often" is not "always" — and recording six different days
	// as whatever the first one was is worse than recording nothing, because it
	// looks like an answer. The form inherits photo one's values as defaults;
	// what arrives here is already whatever each photo ended up with.
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

		// A typed answer beats a picked one: somebody who bothered to write in
		// the "somewhere else" box has told us the list was wrong. __other is
		// the sentinel the dropdown uses to reveal that box and is never itself
		// a place.
		$place = sanitize_text_field( (string) ( $row['place'] ?? '' ) );
		$other = sanitize_text_field( (string) ( $row['place_other'] ?? '' ) );
		if ( '__other' === $place ) { $place = ''; }
		if ( '' !== $other ) { $place = $other; }

		$event = sanitize_text_field( (string) ( $row['event'] ?? '' ) );
		$ev_other = sanitize_text_field( (string) ( $row['event_other'] ?? '' ) );
		if ( '' !== $ev_other ) { $event = $ev_other; }
		// Only trust an event ID that names a real published event, and only
		// when the title still matches it. A stale or doctored pair must not
		// attach a photo to an event it was never at.
		$event_id = (int) ( $row['event_id'] ?? 0 );
		if ( $event_id && function_exists( 'gasf_photo_has_calendar' ) && gasf_photo_has_calendar() ) {
			$p = get_post( $event_id );
			if ( ! $p || GASF_EVENTS_CPT !== $p->post_type || 'publish' !== $p->post_status
				|| 0 !== strcasecmp( trim( $p->post_title ), trim( $event ) ) ) {
				$event_id = 0;
			}
		} else {
			$event_id = 0;
		}

		update_post_meta( $aid, '_gasf_photo_pending', array(
			'people'   => array_values( array_unique( $people ) ),
			'caption'  => $caption,
			'taken'    => gasf_crm_photo_clean_date( $row['taken'] ?? '' ),
			'place'    => $place,
			'event'    => $event,
			'event_id' => $event_id,
			'by'       => (string) $invite['email'],
			'at'       => current_time( 'mysql', true ),
		) );
	}

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

/* =====================================================================
 * Automatic intake
 * ================================================================== */

/**
 * Take in photos as they arrive and ask the sender about them, unprompted.
 *
 * The first version made a volunteer press "Keep photo" before anything could
 * be asked. That was the wrong way round. Somebody who bothered to email photos
 * in AND is willing to say what they are wants us to have them — and the moment
 * they are willing is now, not whenever a volunteer next opens the CRM. Holding
 * the ask behind a manual step spends that goodwill for nothing.
 *
 * Approval still happens. It moved to where there is actually something to
 * approve: the photo AND what it turned out to be, together, in the Photos
 * screen. A photo admin can reject anything at that point, and nothing is
 * published anywhere in the meantime.
 *
 * Runs after each sync. photos_done on the message row is the guard, so a
 * message is never taken in twice however often this runs.
 */
/**
 * Single-flight guard for the intake.
 *
 * The per-attachment key check is check-then-act, which is not enough on its
 * own: fetching five phone photos takes long enough that the hourly system cron
 * and a WP-Cron run triggered by page traffic overlapped, both saw the same
 * attachment as missing, and both kept it. Six photos from a five-photo email.
 *
 * add_option is atomic on the UNIQUE option_name index, so only one process can
 * create the row. Same pattern the mail sync already uses.
 *
 * @return string token, or '' if somebody else holds it
 */
function gasf_crm_photo_lock() {
	$token = wp_generate_password( 12, false );
	$row   = array( 'token' => $token, 'at' => time() );

	if ( add_option( 'gasf_crm_photo_lock', $row, '', false ) ) { return $token; }

	// Held. Break it only if it is old enough to be from a run that died —
	// longer than any real batch of photos could take.
	$held = (array) get_option( 'gasf_crm_photo_lock' );
	if ( ! empty( $held['at'] ) && ( time() - (int) $held['at'] ) < 20 * MINUTE_IN_SECONDS ) { return ''; }

	gasf_mec_log( 'CRM photos: breaking a stale intake lock' );
	delete_option( 'gasf_crm_photo_lock' );
	return add_option( 'gasf_crm_photo_lock', $row, '', false ) ? $token : '';
}

function gasf_crm_photo_unlock( $token ) {
	$held = (array) get_option( 'gasf_crm_photo_lock' );
	// Only release our own: a lock we broke as stale may since be somebody's.
	if ( ! empty( $held['token'] ) && $held['token'] === $token ) {
		delete_option( 'gasf_crm_photo_lock' );
	}
}

function gasf_crm_photo_autoprocess() {
	global $wpdb;

	$cfg = gasf_crm_cfg();
	if ( empty( $cfg['photos_auto'] ) ) { return 0; }

	$lock = gasf_crm_photo_lock();
	if ( ! $lock ) { return 0; }

	try {
		return gasf_crm_photo_autoprocess_run();
	} finally {
		// finally, not a trailing call: a Graph exception must still release the
		// lock, or the intake stops for twenty minutes over one bad fetch.
		gasf_crm_photo_unlock( $lock );
	}
}

/**
 * Delete imports that were interrupted between sideload and provenance.
 *
 * media_handle_sideload creates the attachment and generates every size, and
 * only THEN is _gasf_photo_source written. A run killed in that gap leaves an
 * attachment with files on disk and no record of who sent it — invisible to
 * every query the CRM makes, because they all filter on that meta key, and
 * therefore never reviewed, never published and never cleaned up.
 *
 * Observed rather than theorised: a cron intake was interrupted mid-session and
 * left exactly one such row.
 *
 * The proper fix is the photo_items claim, where a row is written BEFORE the
 * download and an interruption leaves a retryable record rather than a ghost.
 * Until that is wired, this sweeps at the start of every intake, which is the
 * only moment we know no import is legitimately in flight — the lock is held.
 */
function gasf_crm_photo_sweep_orphans() {
	global $wpdb;

	$rows = $wpdb->get_col( $wpdb->prepare(
		"SELECT p.post_id FROM {$wpdb->postmeta} p
		  LEFT JOIN {$wpdb->postmeta} s
		         ON s.post_id = p.post_id AND s.meta_key = '_gasf_photo_source'
		  WHERE p.meta_key = '_wp_attached_file'
		    AND p.meta_value LIKE %s
		    AND s.post_id IS NULL",
		$wpdb->esc_like( GASF_CRM_PHOTO_REVIEW_DIR . '/' ) . '%'
	) );

	foreach ( $rows as $id ) {
		gasf_mec_log( 'CRM photos: removing interrupted import #' . (int) $id . ' (no provenance recorded)' );
		wp_delete_attachment( (int) $id, true );
	}
	return count( $rows );
}

function gasf_crm_photo_autoprocess_run() {
	global $wpdb;
	$cfg = gasf_crm_cfg();

	// Under the lock, so nothing legitimately mid-import can be mistaken for
	// wreckage from a previous one.
	gasf_crm_photo_sweep_orphans();

	$photo_streams = array();
	foreach ( gasf_crm_active_streams() as $key => $s ) {
		if ( 'general' !== $key ) { $photo_streams[] = $key; }
	}
	if ( ! $photo_streams ) { return 0; }

	$in = implode( ',', array_fill( 0, count( $photo_streams ), '%s' ) );
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT m.id, m.graph_message_id, m.thread_id, m.from_addr, m.from_name
		   FROM ' . gasf_crm_table( 'messages' ) . ' m
		   JOIN ' . gasf_crm_table( 'threads' ) . ' t ON t.id = m.thread_id
		  WHERE m.direction = \'in\' AND m.has_attachments = 1 AND m.photos_done = 0
		    AND t.stream IN (' . $in . ')
		  ORDER BY m.id ASC LIMIT 10', // phpcs:ignore WordPress.DB.PreparedSQL
		$photo_streams
	), ARRAY_A );

	$taken = 0;
	foreach ( $rows as $row ) {
		$thread = gasf_crm_get_thread( (int) $row['thread_id'] );
		if ( ! $thread ) { continue; }

		$kept   = array();  // everything on this message, new or already here
		$fresh  = 0;        // how many this run actually fetched
		$failed = false;

		$all = gasf_crm_graph_attachments( $row['graph_message_id'], (string) $thread['stream'] );
		if ( is_wp_error( $all ) ) {
			gasf_mec_log( 'CRM photos: could not list attachments on message ' . (int) $row['id'] . ' — ' . $all->get_error_message() );
			continue; // left unmarked, so the next run tries again
		}

		// Too many to take unattended. Left entirely unprocessed rather than
		// part-imported: half a submission is worse than none, because it looks
		// finished. A volunteer keeps them by hand from the thread.
		$images = 0;
		foreach ( (array) $all as $a ) {
			if ( 0 === strpos( strtolower( (string) ( $a['contentType'] ?? '' ) ), 'image/' ) ) { $images++; }
		}
		if ( $images > GASF_CRM_PHOTO_MAX_PER_MESSAGE ) {
			gasf_mec_log( sprintf(
				'CRM photos: message %d from %s carries %d images, over the %d limit — NOT taken in automatically, needs a volunteer.',
				(int) $row['id'], $thread['last_from_addr'], $images, GASF_CRM_PHOTO_MAX_PER_MESSAGE
			) );
			gasf_crm_log_event( (int) $thread['id'], 'photo_held', $images . ' images — too many to take in automatically' );
			continue;
		}

		foreach ( (array) $all as $a ) {
			$type = strtolower( (string) ( $a['contentType'] ?? '' ) );
			$kind = (string) ( $a['@odata.type'] ?? '' );
			if ( 0 !== strpos( $type, 'image/' ) ) { continue; }
			if ( false !== strpos( $kind, 'referenceAttachment' ) || false !== strpos( $kind, 'itemAttachment' ) ) { continue; }

			// Already here — from a previous run, or because a volunteer kept it
			// by hand before this got to it. Either way it is not fetched twice.
			$have = gasf_crm_photo_already_kept( $row['graph_message_id'], (string) ( $a['id'] ?? '' ) );
			if ( $have ) { $kept[] = $have; continue; }

			$id = gasf_crm_photo_approve( $thread, (string) $row['graph_message_id'], (string) ( $a['id'] ?? '' ) );
			if ( is_wp_error( $id ) ) {
				gasf_mec_log( 'CRM photos: auto-keep failed for ' . ( $a['name'] ?? '?' ) . ' — ' . $id->get_error_message() );
				$failed = true;
				continue;
			}
			$kept[] = (int) $id;
			$fresh++;
		}

		// Marked only once every image on the message is in. The first version
		// marked it BEFORE the work, to stop a retry sending a second email —
		// but a run killed halfway then abandoned the remaining photos for good,
		// silently. Per-attachment keys make a retry harmless, so the flag can
		// wait until there is genuinely nothing left to fetch.
		if ( ! $failed ) {
			$wpdb->update( gasf_crm_table( 'messages' ), array( 'photos_done' => 1 ), array( 'id' => (int) $row['id'] ), array( '%d' ), array( '%d' ) );
		}

		$taken += $fresh;
		if ( ! $kept ) { continue; }

		// One ask per submission. A retry, or more photos arriving on the same
		// thread later, must not start the clock again on somebody who is
		// already holding a live link.
		$live = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . gasf_crm_table( 'photo_invites' ) . '
			  WHERE thread_id = %d AND submitted_at IS NULL AND expires_at > %s',
			(int) $thread['id'], current_time( 'mysql', true )
		) );
		if ( $live ) { continue; }

		// Addressed to whoever sent THIS message, not to whoever wrote last.
		$to_addr = sanitize_email( (string) $row['from_addr'] );
		$to_name = sanitize_text_field( (string) $row['from_name'] );
		if ( ! is_email( $to_addr ) ) {
			gasf_mec_log( 'CRM photos: message ' . (int) $row['id'] . ' has no usable sender — photos kept, no link sent.' );
			continue;
		}

		$inv = gasf_crm_photo_invite_create(
			(int) $thread['id'],
			$to_addr,
			$to_name,
			$kept,
			(string) $thread['stream'],
			(string) $row['graph_message_id']
		);
		if ( is_wp_error( $inv ) ) {
			gasf_mec_log( 'CRM photos: took in ' . count( $kept ) . ' photo(s) from thread ' . (int) $thread['id']
				. ' but could not mint a tagging link — ' . $inv->get_error_message() );
			continue;
		}

		gasf_crm_photo_invite_send( array(
			'thread_id' => (int) $thread['id'],
			'email'     => $to_addr,
			'name'      => $to_name,
		), $inv['token'], (string) $thread['stream'] );
	}

	return $taken;
}

/* =====================================================================
 * The chase — remind on day 2, release on day 5
 * ================================================================== */

/**
 * Send the one reminder, to anyone asked more than REMIND_DAYS ago who has not
 * answered.
 *
 * A reminder needs a working link and the token is stored hashed, so the
 * original cannot be re-sent — that is the price of not keeping a replayable
 * credential in the database, and it is worth paying. So this mints a fresh
 * invite for the same photos and leaves the first one alive: somebody who digs
 * the original email out of their inbox on day 4 still gets a page that works,
 * which rotating the token would have taken away from them.
 *
 * Both rows point at the same photos, and either can be filled in.
 */
function gasf_crm_photo_chase() {
	global $wpdb;

	$t   = gasf_crm_table( 'photo_invites' );
	$now = current_time( 'mysql', true );
	$due = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$t}
		  WHERE submitted_at IS NULL
		    AND reminded_at IS NULL
		    AND expires_at > %s
		    AND created_at <= %s
		  ORDER BY created_at ASC LIMIT 20",
		$now,
		gmdate( 'Y-m-d H:i:s', time() - ( GASF_CRM_PHOTO_REMIND_DAYS * DAY_IN_SECONDS ) )
	), ARRAY_A );

	$sent = 0;
	foreach ( $due as $row ) {
		// Mark first. A send that throws must not leave the row eligible to be
		// chased again on the next run — one reminder is a nudge, four is
		// nagging somebody who did us a favour.
		$wpdb->update( $t, array( 'reminded_at' => $now ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );

		$ids = array_map( 'intval', (array) json_decode( (string) $row['attachment_ids'], true ) );
		$ids = array_values( array_filter( $ids, function ( $id ) {
			// A photo deleted since the ask is not worth chasing about.
			return 'attachment' === get_post_type( $id );
		} ) );
		if ( ! $ids ) { continue; }

		// The reminder inherits the ORIGINAL invitation's binding — same sender,
		// same message, same mailbox — rather than re-deriving any of it from a
		// thread that may have moved on in the two days since.
		$fresh = gasf_crm_photo_invite_create(
			(int) $row['thread_id'], $row['email'], $row['name'], $ids,
			(string) ( $row['stream'] ?? '' ), (string) ( $row['graph_message_id'] ?? '' )
		);
		if ( is_wp_error( $fresh ) ) {
			gasf_mec_log( 'CRM photos: reminder for invite ' . (int) $row['id'] . ' could not be minted — ' . $fresh->get_error_message() );
			continue;
		}
		// The fresh row is the reminder, so it never earns one of its own.
		$wpdb->update( $t, array( 'reminded_at' => $now ), array( 'id' => (int) $fresh['id'] ), array( '%s' ), array( '%d' ) );

		$body = sprintf(
			"Hello%s,\n\n" .
			"A little while ago we asked about the %s you sent the club, and said we would love to know what they show. In case it slipped past — it usually does, and no harm done — here is the link again:\n\n" .
			"%s\n\n" .
			"It takes a minute, and there is no account or password.\n\n" .
			"This is the only reminder you will get. If you would rather not, just ignore it: the photos stay with us either way and one of our volunteers will label them as best we can.\n\n" .
			"With thanks,\n%s",
			$row['name'] ? ' ' . $row['name'] : '',
			1 === count( $ids ) ? 'photo' : 'photos',
			$fresh['url'],
			gasf_crm_cfg()['signature_org']
		);

		$ok = gasf_crm_graph_send( $row['email'], 'A gentle nudge about your photos', $body, 'photos' );
		if ( is_wp_error( $ok ) ) {
			gasf_mec_log( 'CRM photos: reminder to ' . $row['email'] . ' FAILED — ' . $ok->get_error_message() );
			continue;
		}

		gasf_crm_log_event( (int) $row['thread_id'], 'photo_reminded', 'reminder sent to ' . $row['email'] );
		$sent++;
	}

	return $sent;
}

/**
 * Where one photo sits in the loop.
 *
 * @return array{state:string,release:string,invite:int}
 *   confirmed  tags applied, done
 *   described  the sender answered; waiting on a volunteer
 *   waiting    asked, inside the grace period — purgatory, nobody is chased
 *   released   asked, grace period passed, no answer — a volunteer tags it
 *   untagged   nobody was ever asked, so nothing is being waited for
 */
function gasf_crm_photo_state( $attachment_id ) {
	global $wpdb;
	$id = (int) $attachment_id;

	if ( get_post_meta( $id, '_gasf_photo_pending', true ) ) {
		return array( 'state' => 'described', 'release' => '', 'invite' => 0 );
	}
	if ( get_post_meta( $id, '_gasf_photo_confirmed', true ) ) {
		return array( 'state' => 'confirmed', 'release' => '', 'invite' => 0 );
	}

	// The EARLIEST invite covering this photo. A reminder mints a second row,
	// and dating the grace period from that one would silently extend purgatory
	// by two days every time somebody was nudged.
	$t   = gasf_crm_table( 'photo_invites' );
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, created_at, submitted_at FROM {$t}
		  WHERE attachment_ids LIKE %s ORDER BY created_at ASC LIMIT 1",
		'%' . $wpdb->esc_like( '[' . $id . ',' ) . '%'
	), ARRAY_A );
	if ( ! $row ) {
		// LIKE on a JSON list needs all three positions: first, middle, last/only.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, created_at, submitted_at FROM {$t}
			  WHERE attachment_ids LIKE %s OR attachment_ids LIKE %s
			  ORDER BY created_at ASC LIMIT 1",
			'%' . $wpdb->esc_like( ',' . $id . ',' ) . '%',
			'%' . $wpdb->esc_like( ',' . $id . ']' ) . '%'
		), ARRAY_A );
	}
	if ( ! $row ) {
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, created_at, submitted_at FROM {$t} WHERE attachment_ids = %s LIMIT 1",
			'[' . $id . ']'
		), ARRAY_A );
	}

	// Nobody was asked, so nothing is owed and it is a volunteer's to tag now.
	if ( ! $row ) { return array( 'state' => 'untagged', 'release' => '', 'invite' => 0 ); }

	$free = strtotime( $row['created_at'] . ' UTC' ) + ( GASF_CRM_PHOTO_RELEASE_DAYS * DAY_IN_SECONDS );
	if ( time() < $free ) {
		return array( 'state' => 'waiting', 'release' => gmdate( 'Y-m-d H:i:s', $free ), 'invite' => (int) $row['id'] );
	}
	return array( 'state' => 'released', 'release' => gmdate( 'Y-m-d H:i:s', $free ), 'invite' => (int) $row['id'] );
}

/**
 * What actually needs a volunteer, thread => counts.
 *
 * Purgatory is deliberately absent: a photo whose sender has been asked and
 * still has days to answer is not work, and putting it in front of somebody as
 * though it were is how a queue stops being believed.
 *
 * @return array<int,array{described:int,released:int}>
 */
function gasf_crm_photo_actionable_threads() {
	$out = array();

	$consider = array_unique( array_merge(
		gasf_crm_photo_pending_ids(),
		gasf_crm_photo_untagged_ids()
	) );

	foreach ( $consider as $aid ) {
		$src = get_post_meta( $aid, '_gasf_photo_source', true );
		$tid = (int) ( is_array( $src ) ? ( $src['thread'] ?? 0 ) : 0 );
		if ( ! $tid ) { continue; }

		$st = gasf_crm_photo_state( $aid );
		if ( 'described' !== $st['state'] && 'released' !== $st['state'] ) { continue; }

		if ( ! isset( $out[ $tid ] ) ) { $out[ $tid ] = array( 'described' => 0, 'released' => 0 ); }
		$out[ $tid ][ $st['state'] ]++;
	}

	return $out;
}

/** Photos kept from a submission that carry no tags and no pending answers. */
function gasf_crm_photo_untagged_ids() {
	$q = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => '_gasf_photo_source', 'compare' => 'EXISTS' ),
			array( 'key' => '_gasf_photo_confirmed', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_gasf_photo_pending', 'compare' => 'NOT EXISTS' ),
		),
	) );
	return array_map( 'intval', $q->posts );
}

/** Back-compat for anything still asking the old question. */
function gasf_crm_photo_pending_threads() {
	$out = array();
	foreach ( gasf_crm_photo_actionable_threads() as $tid => $n ) {
		$total = $n['described'] + $n['released'];
		if ( $total ) { $out[ $tid ] = $total; }
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
/**
 * The photo's current revision — how many times it has been decided about.
 *
 * Handed to the browser with the card and sent back with the decision, so a
 * volunteer who has been looking at a stale screen can be told rather than
 * silently overwriting somebody.
 */
function gasf_crm_photo_revision( $attachment_id ) {
	return (int) get_post_meta( (int) $attachment_id, '_gasf_photo_rev', true );
}

function gasf_crm_photo_confirm( $attachment_id, array $keep ) {
	$id = (int) $attachment_id;
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) { return new WP_Error( 'gasf_crm_404', 'No such photo.' ); }
	if ( ! gasf_crm_user_can_stream( 'photos' ) ) {
		return new WP_Error( 'gasf_crm_403', 'You do not have access to photo submissions.' );
	}

	// Compare-and-swap before anything is written.
	//
	// update_post_meta with a previous value compiles to UPDATE ... WHERE
	// meta_value = <expected>, so exactly one of two simultaneous approvals can
	// move the revision forward. The loser is told, rather than having its
	// taxonomy writes quietly land on top of the winner's — two volunteers
	// working the queue at once is the ordinary case, not the exotic one.
	//
	// A caller that sends no revision is treated as accepting whatever it finds,
	// which keeps older clients working; the UI always sends one.
	$have = gasf_crm_photo_revision( $id );
	if ( isset( $keep['revision'] ) && '' !== $keep['revision'] ) {
		$want = (int) $keep['revision'];
		if ( $want !== $have ) {
			return new WP_Error(
				'gasf_crm_stale',
				'Somebody else has already dealt with this photo. Reload to see where it got to.',
				array( 'status' => 409 )
			);
		}
	}
	if ( ! update_post_meta( $id, '_gasf_photo_rev', $have + 1, $have ) ) {
		// Lost between the read above and here.
		return new WP_Error(
			'gasf_crm_stale',
			'Somebody else was approving this at the same moment. Reload to see where it got to.',
			array( 'status' => 409 )
		);
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

	// Which occurrence, when the occasion came from the club's own calendar.
	//
	// The TERM stays the plain event name — "Oktoberfest", not "Oktoberfest
	// 2026" — because "every Oktoberfest photo we have" is the question people
	// actually ask, and a term per occurrence would mean 200 Biergarten terms
	// for 200 Wednesdays. The exact event is kept alongside it as meta, so
	// "photos from THIS one" stays answerable without polluting the vocabulary.
	$eid = (int) ( $keep['event_id'] ?? 0 );
	if ( $eid && gasf_photo_has_calendar() && GASF_EVENTS_CPT === get_post_type( $eid ) ) {
		update_post_meta( $id, '_gasf_photo_event_id', $eid );
	} else {
		delete_post_meta( $id, '_gasf_photo_event_id' );
	}

	$taken = gasf_crm_photo_clean_date( $keep['taken'] ?? '' );
	if ( $taken ) { update_post_meta( $id, '_gasf_photo_taken', $taken ); }

	$caption = trim( sanitize_text_field( (string) ( $keep['caption'] ?? '' ) ) );
	if ( '' !== $caption ) {
		wp_update_post( array( 'ID' => $id, 'post_excerpt' => $caption ) );
	}

	// Approving is what makes a photo public. Until this moment it has only ever
	// been served through the handler; from here it is an ordinary attachment
	// with an ordinary URL, which is what it now is.
	$moved = gasf_crm_photo_publish( $id );
	if ( is_wp_error( $moved ) ) {
		gasf_mec_log( 'CRM photos: could not publish media #' . $id . ' — ' . $moved->get_error_message() );
		return $moved;
	}

	// Title and alt, now that there is finally something true to say. Both are
	// safe to rewrite — nothing links to them — unlike the filename, which is
	// left exactly where it is and described at download time instead.
	if ( function_exists( 'gasf_photo_apply_names' ) ) {
		gasf_photo_apply_names( $id );
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
			if ( ! $ids ) { return new WP_Error( 'gasf_crm_nophotos', 'No photos have been kept from this email.', array( 'status' => 400 ) ); }

			// Addressed from the PHOTOS' own recorded sender, not from whoever
			// wrote to the thread most recently. On a thread where a second
			// person has since replied, the thread's address is theirs, and the
			// link would show them somebody else's photos.
			$src = get_post_meta( $ids[0], '_gasf_photo_source', true );
			$to_addr = sanitize_email( (string) ( $src['email'] ?? '' ) );
			$to_name = sanitize_text_field( (string) ( $src['name'] ?? '' ) );
			if ( ! is_email( $to_addr ) ) {
				return new WP_Error( 'gasf_crm_nosender', 'These photos have no recorded sender to ask.', array( 'status' => 409 ) );
			}

			$inv = gasf_crm_photo_invite_create(
				(int) $thread['id'],
				$to_addr,
				$to_name,
				$ids,
				(string) $thread['stream'],
				(string) ( $src['graph_msg'] ?? '' )
			);
			if ( is_wp_error( $inv ) ) { return $inv; }

			$sent = gasf_crm_photo_invite_send( array(
				'thread_id' => (int) $thread['id'],
				'email'     => $to_addr,
				'name'      => $to_name,
			), $inv['token'], (string) $thread['stream'] );

			if ( is_wp_error( $sent ) ) {
				return new WP_Error(
					'gasf_crm_sendfail',
					'The link was created but the email did not go out: ' . $sent->get_error_message(),
					array( 'status' => 502 )
				);
			}

			return array( 'sent' => true, 'to' => $to_addr, 'photos' => count( $ids ) );
		},
	) );

	/*
	 * The Photos screen.
	 *
	 * Every route here is gated on holding the photos stream, NOT on a
	 * WordPress capability. Photo volunteers are created with no role at all,
	 * so current_user_can() refuses every one of them — a photo admin and a
	 * WordPress admin are different people and the code has to say so.
	 */
	$photo_guard = function () {
		$sess = gasf_crm_rest_guard();
		if ( is_wp_error( $sess ) || ! $sess ) { return $sess; }
		return gasf_crm_user_can_stream( 'photos' )
			? true
			: new WP_Error( 'gasf_crm_403', 'You do not look after photo submissions.', array( 'status' => 403 ) );
	};

	register_rest_route( 'gasf/v1', '/crm/photos/list', array(
		'methods'             => 'GET',
		'permission_callback' => $photo_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			return gasf_crm_photo_gallery( (string) $req->get_param( 'state' ) );
		},
	) );

	// The volunteer's way to see a photo that is not public yet.
	register_rest_route( 'gasf/v1', '/crm/photos/file', array(
		'methods'             => 'GET',
		'permission_callback' => $photo_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id = (int) $req->get_param( 'photo' );
			// Must be a submitted photo, not any attachment ID handed to us.
			if ( ! gasf_crm_photo_card( $id ) ) { status_header( 404 ); exit; }
			gasf_crm_photo_send_file( $id, (string) $req->get_param( 'size' ) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/detail', array(
		'methods'             => 'GET',
		'permission_callback' => $photo_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$one = gasf_crm_photo_card( (int) $req->get_param( 'photo' ) );
			return $one ? $one : new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/save', array(
		'methods'             => 'POST',
		'permission_callback' => $photo_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$aid = (int) $req->get_param( 'photo' );
			if ( ! gasf_crm_photo_card( $aid ) ) {
				return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
			}
			$ok = gasf_crm_photo_confirm( $aid, array(
				'people'   => (array) $req->get_param( 'people' ),
				'place'    => (string) $req->get_param( 'place' ),
				'event'    => (string) $req->get_param( 'event' ),
				'event_id' => (int) $req->get_param( 'event_id' ),
				'taken'    => (string) $req->get_param( 'taken' ),
				'caption'  => (string) $req->get_param( 'caption' ),
				'revision' => $req->get_param( 'revision' ),
			) );
			return is_wp_error( $ok ) ? $ok : gasf_crm_photo_card( $aid );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/reject', array(
		'methods'             => 'POST',
		'permission_callback' => $photo_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$aid  = (int) $req->get_param( 'photo' );
			$card = gasf_crm_photo_card( $aid );
			if ( ! $card ) { return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) ); }

			// Only ever a photo that came in through a submission. Without this
			// the route would delete any attachment ID in the Media Library.
			$src = get_post_meta( $aid, '_gasf_photo_source', true );
			if ( ! is_array( $src ) || empty( $src['email'] ) ) {
				return new WP_Error( 'gasf_crm_403', 'That is not a submitted photo.', array( 'status' => 403 ) );
			}

			gasf_mec_log( sprintf(
				'CRM photos: media #%d (%s, from %s) rejected by user %d',
				$aid, get_the_title( $aid ), $src['email'], get_current_user_id()
			) );
			if ( ! empty( $src['thread'] ) ) {
				gasf_crm_log_event( (int) $src['thread'], 'photo_rejected', 'media #' . $aid . ' removed' );
			}

			wp_delete_attachment( $aid, true );
			return array( 'ok' => true, 'photo' => $aid );
		},
	) );

	// What was on at the club that day, or a name search when the date is no
	// help. Authenticated: the public tagging form gets its suggestions
	// server-rendered instead, so the calendar is not queryable by anyone
	// holding a photo link.
	register_rest_route( 'gasf/v1', '/crm/photos/events', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			if ( ! function_exists( 'gasf_photo_has_calendar' ) || ! gasf_photo_has_calendar() ) {
				return array( 'calendar' => false, 'events' => array() );
			}
			$q = trim( (string) $req->get_param( 'q' ) );
			return array(
				'calendar' => true,
				'events'   => '' !== $q
					? gasf_photo_events_search( $q )
					: gasf_photo_events_on_date( (string) $req->get_param( 'date' ) ),
			);
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
				'people'   => (array) $req->get_param( 'people' ),
				'place'    => (string) $req->get_param( 'place' ),
				'event'    => (string) $req->get_param( 'event' ),
				'event_id' => (int) $req->get_param( 'event_id' ),
				'taken'    => (string) $req->get_param( 'taken' ),
				'caption'  => (string) $req->get_param( 'caption' ),
				'revision' => $req->get_param( 'revision' ),
			) );
			if ( is_wp_error( $ok ) ) { return $ok; }

			gasf_crm_log_event( (int) $thread['id'], 'photo_confirmed', 'tags approved for media #' . $aid );
			return array( 'ok' => true, 'photo' => $aid );
		},
	) );
} );

/**
 * One photo, everything the Photos screen shows about it.
 *
 * Returns null for anything that did not arrive through a submission, which is
 * what keeps the routes above from reaching arbitrary Media Library items.
 */
function gasf_crm_photo_card( $attachment_id ) {
	$id = (int) $attachment_id;
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) { return null; }

	$src = get_post_meta( $id, '_gasf_photo_source', true );
	if ( ! is_array( $src ) ) { return null; }

	$st      = gasf_crm_photo_state( $id );
	$info    = function_exists( 'gasf_photo_info' ) ? gasf_photo_info( $id ) : array();
	$pending = get_post_meta( $id, '_gasf_photo_pending', true );

	// An attachment row whose file is gone. WordPress renders that as a broken
	// image and says nothing, which is the worst of both — it looks like the
	// page is broken rather than the photo being absent. Say which it is.
	$path    = get_attached_file( $id );
	$missing = ! $path || ! file_exists( $path );

	// Private until approved, so these go through the handler rather than
	// straight at the uploads folder.
	$private = gasf_crm_photo_is_private( $id );

	return array(
		'id'      => $id,
		'private' => $private,
		'thumb'   => gasf_crm_photo_img_url( $id, 'medium' ),
		'full'    => gasf_crm_photo_img_url( $id, 'large' ),
		'url'     => gasf_crm_photo_img_url( $id, 'full' ),
		'dlname'  => function_exists( 'gasf_photo_filename' ) ? gasf_photo_filename( $id ) : '',
		'state'   => $st['state'],
		'release' => $st['release'] ? mysql2date( get_option( 'date_format' ), $st['release'] ) : '',
		'from'    => trim( (string) ( $src['name'] ?? '' ) ) ?: (string) ( $src['email'] ?? '' ),
		'email'   => (string) ( $src['email'] ?? '' ),
		// Whether the club has heard from this address before. Not a verdict —
		// most first-timers are exactly who they say they are — but "we have
		// never had a word from this person and here are photos" is worth a
		// second look, and it costs one indexed read to say.
		'known'   => gasf_crm_photo_sender_known( (string) ( $src['email'] ?? '' ) ),
		'subject' => (string) ( $src['subject'] ?? '' ),
		'thread'  => (int) ( $src['thread'] ?? 0 ),
		'taken'   => $info['taken'] ?? '',
		'guess'   => ( ! empty( $info['place_guess'] ) && ! is_wp_error( $info['place_guess'] ) ) ? $info['place_guess']->name : '',
		'alts'    => ! empty( $info['place_alts'] ) ? wp_list_pluck( $info['place_alts'], 'name' ) : array(),
		'people'  => $info['people'] ?? array(),
		'places'  => $info['places'] ?? array(),
		'events'  => $info['events'] ?? array(),
		'caption' => $info['caption'] ?? '',
		'pending'   => is_array( $pending ) ? $pending : null,
		// Sent out with the card and required back with the decision, so a
		// volunteer acting on a stale screen is told rather than obeyed.
		'revision'  => gasf_crm_photo_revision( $id ),
		'confirmed' => 'confirmed' === $st['state'],
		'title'     => get_the_title( $id ),
		'missing'   => $missing,
	);
}

/**
 * Has the club exchanged anything with this address before this submission?
 *
 * The address book counts the message that carried these photos, so "seen more
 * than once, or ever written to" is what distinguishes a correspondent from a
 * complete stranger.
 */
function gasf_crm_photo_sender_known( $email ) {
	global $wpdb;
	$email = sanitize_email( $email );
	if ( ! is_email( $email ) ) { return false; }

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT sent_count, recv_count FROM ' . gasf_crm_table( 'contacts' ) . ' WHERE email = %s LIMIT 1',
		$email
	), ARRAY_A );
	if ( ! $row ) { return false; }

	return ( (int) $row['sent_count'] > 0 ) || ( (int) $row['recv_count'] > 1 );
}

/**
 * The gallery, newest first.
 *
 * 'review' is the default because it is the only bucket that is actually work:
 * a sender has answered, or the grace period ran out and nobody did.
 */
function gasf_crm_photo_gallery( $state = '' ) {
	$q = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 300,
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array( array( 'key' => '_gasf_photo_source', 'compare' => 'EXISTS' ) ),
	) );

	$out    = array();
	$counts = array( 'review' => 0, 'waiting' => 0, 'done' => 0, 'all' => 0 );

	foreach ( $q->posts as $id ) {
		$card = gasf_crm_photo_card( (int) $id );
		if ( ! $card ) { continue; }

		$bucket = 'confirmed' === $card['state'] ? 'done'
			: ( 'waiting' === $card['state'] ? 'waiting' : 'review' );
		$card['bucket'] = $bucket;

		$counts[ $bucket ]++;
		$counts['all']++;

		if ( '' === $state || 'all' === $state || $state === $bucket ) { $out[] = $card; }
	}

	return array( 'photos' => $out, 'counts' => $counts );
}

/**
 * The photo block for a thread, as the reading pane needs it.
 *
 * Called from the thread-detail route so a volunteer sees the state of the
 * whole submission in one place: what has been kept, what the sender said
 * about it, and what is still waiting on somebody.
 */
function gasf_crm_photo_thread_block( $thread_id ) {
	// The same shape the Photos screen uses. It was a second, slightly
	// different array until the sender's address and whether we had ever heard
	// from them turned out to be missing here and present there — two shapes
	// for one thing means the next field will go missing too.
	$out = array();
	foreach ( gasf_crm_photo_for_thread( $thread_id ) as $id ) {
		$card = gasf_crm_photo_card( $id );
		if ( $card ) { $out[] = $card; }
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
