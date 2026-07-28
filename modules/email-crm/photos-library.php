<?php
/**
 * The photo library — modules/email-crm/photos-library.php
 *
 * The point of all the tagging. Everything before this file takes photos IN and
 * works out what they show; this is where a volunteer finally gets them back
 * out — to look through, to pick from, and to download for a newsletter, a
 * poster or a Facebook post.
 *
 * Deliberately NOT the Media Library. That holds 1,400-odd files, almost all of
 * them website furniture — button backgrounds, sponsor logos, theme headers —
 * and a volunteer looking for "a good picture of the Biergarten" should not have
 * to wade through them. The library is only photos the club has actually
 * catalogued: submissions that were approved, plus anything a volunteer has
 * tagged by hand.
 *
 * Reads only. Nothing here changes a photo, so there is no revision, no lock and
 * no state machine — the worst a bug in this file can do is show somebody the
 * wrong list, which is a different order of problem from the rest of this build.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Most photos in one page of the grid. */
define( 'GASF_CRM_LIB_PER_PAGE', 60 );

/**
 * Budgets for a bulk download.
 *
 * A zip is the one thing here that costs real resources, and the person asking
 * for it has no idea how large the originals are — a "select all" on a year of
 * Oktoberfest is several gigabytes. Both caps are checked BEFORE anything is
 * written, and going over is refused with the actual numbers rather than
 * silently truncated: a zip missing four photos nobody mentioned is worse than
 * one that did not build.
 */
define( 'GASF_CRM_LIB_ZIP_MAX_FILES', 80 );
define( 'GASF_CRM_LIB_ZIP_MAX_BYTES', 750 * MB_IN_BYTES );

/** How long a built zip stays downloadable before it is swept. */
define( 'GASF_CRM_LIB_ZIP_TTL', 30 * MINUTE_IN_SECONDS );

/* =====================================================================
 * What counts as being in the library
 * ================================================================== */

/**
 * Every catalogued photo, newest first.
 *
 * Three ways in, deliberately:
 *   - approved through the submission workflow (_gasf_photo_confirmed), or
 *   - carrying any catalogue term, which is how a volunteer adds a photo that
 *     never came through the mailbox — a scan, a committee member's camera roll,
 *   - or catalogued by the EXIF backfill (_gasf_photo_autotag).
 *
 * That third route exists because the first version required a TERM, and a
 * photo the backfill could date but not place — no GPS, and several club events
 * that day — got none. Sixty-one real club photos were dated and then invisible:
 * a Valentine's dinner, a February camera roll, portraits. A known date is
 * cataloguing; the library is where catalogued photos live.
 *
 * Merged in PHP rather than expressed as one query because WP_Query cannot OR a
 * meta_query against a tax_query. At this size that is not worth a custom join:
 * both halves are indexed lookups and the union is a few hundred integers.
 *
 * Private photos are excluded outright. Anything still awaiting review is not
 * cleared for use, and this list exists to be used from.
 *
 * @param array $f person|place|event|year|q
 * @return int[]
 */
function gasf_crm_photo_library_ids( array $f = array() ) {
	$common = array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit', // never 'private' — see above
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	);

	$ids = get_posts( $common + array(
		'meta_query' => array(
			'relation' => 'OR',
			array( 'key' => '_gasf_photo_confirmed', 'compare' => 'EXISTS' ),
			array( 'key' => '_gasf_photo_autotag',   'compare' => 'EXISTS' ),
		),
	) );

	$tagged = get_posts( $common + array(
		'tax_query' => array(
			'relation' => 'OR',
			array( 'taxonomy' => 'gasf_photo_person', 'operator' => 'EXISTS' ),
			array( 'taxonomy' => 'gasf_photo_place',  'operator' => 'EXISTS' ),
			array( 'taxonomy' => 'gasf_photo_event',  'operator' => 'EXISTS' ),
		),
	) );

	$all = array_values( array_unique( array_map( 'intval', array_merge( $ids, $tagged ) ) ) );
	if ( ! $all ) { return array(); }

	$all = gasf_crm_photo_library_filter( $all, $f );

	// Newest first by when the photo was TAKEN, falling back to when it reached
	// us. A collection sorted by upload date puts a 1974 Fasching scan between
	// last week's two, which is not how anybody looks for a picture.
	usort( $all, function ( $a, $b ) {
		$ta = get_post_meta( $a, '_gasf_photo_taken', true ) ?: get_post_field( 'post_date', $a );
		$tb = get_post_meta( $b, '_gasf_photo_taken', true ) ?: get_post_field( 'post_date', $b );
		return strcmp( (string) $tb, (string) $ta ) ?: ( $b - $a );
	} );

	return $all;
}

/**
 * Narrow a set of photo ids by the filter bar.
 *
 * A place filter includes everything BENEATH it. Asking for photos at the
 * German-American Society and being shown none — because they are all tagged
 * Bierstube or Main Hall, which are rooms inside it — would make the hierarchy
 * an obstacle rather than the point of it.
 */
function gasf_crm_photo_library_filter( array $ids, array $f ) {
	$want_place = trim( (string) ( $f['place'] ?? '' ) );
	$places     = array();
	if ( '' !== $want_place ) {
		$places[] = $want_place;
		$term     = get_term_by( 'name', $want_place, 'gasf_photo_place' );
		if ( $term && ! is_wp_error( $term ) ) {
			foreach ( (array) get_term_children( $term->term_id, 'gasf_photo_place' ) as $kid ) {
				$k = get_term( (int) $kid, 'gasf_photo_place' );
				if ( $k && ! is_wp_error( $k ) ) { $places[] = $k->name; }
			}
		}
	}

	$person = trim( (string) ( $f['person'] ?? '' ) );
	$event  = trim( (string) ( $f['event'] ?? '' ) );
	$year   = preg_replace( '~\D~', '', (string) ( $f['year'] ?? '' ) );
	$q      = trim( (string) ( $f['q'] ?? '' ) );

	if ( '' === $person && '' === $event && '' === $year && '' === $q && ! $places ) { return $ids; }

	return array_values( array_filter( $ids, function ( $id ) use ( $places, $person, $event, $year, $q ) {
		$names = function ( $tax ) use ( $id ) {
			$t = wp_get_object_terms( $id, $tax, array( 'fields' => 'names' ) );
			return is_wp_error( $t ) ? array() : $t;
		};

		if ( $places && ! array_intersect( $places, $names( 'gasf_photo_place' ) ) ) { return false; }
		if ( '' !== $person && ! in_array( $person, $names( 'gasf_photo_person' ), true ) ) { return false; }
		if ( '' !== $event && ! in_array( $event, $names( 'gasf_photo_event' ), true ) ) { return false; }

		if ( '' !== $year ) {
			$taken = (string) get_post_meta( $id, '_gasf_photo_taken', true );
			if ( 0 !== strpos( $taken ?: (string) get_post_field( 'post_date', $id ), $year ) ) { return false; }
		}

		// Free text sweeps everything a person might half-remember: the caption,
		// the title, and every name on it. Someone looking for a photo rarely
		// remembers which field the word was in.
		if ( '' !== $q ) {
			$hay = strtolower( implode( ' ', array_merge(
				array( get_the_title( $id ), get_post_field( 'post_excerpt', $id ) ),
				$names( 'gasf_photo_person' ), $names( 'gasf_photo_place' ), $names( 'gasf_photo_event' )
			) ) );
			if ( false === strpos( $hay, strtolower( $q ) ) ) { return false; }
		}

		return true;
	} ) );
}

/**
 * One photo as the grid needs it.
 *
 * dlname is the descriptive filename the catalogue builds — "2026-07-11-
 * Oktoberfest-Biergarten-Hans-Mueller.jpg" rather than "PXL_20260711_233635720".
 * That name is the whole reason for tagging as far as a volunteer is concerned:
 * it survives being dropped into a folder, emailed, or handed to a designer,
 * where the tags themselves do not.
 */
function gasf_crm_photo_library_card( $attachment_id ) {
	$id = (int) $attachment_id;
	if ( 'attachment' !== get_post_type( $id ) ) { return null; }

	$info  = function_exists( 'gasf_photo_info' ) ? gasf_photo_info( $id ) : array();
	$file  = get_attached_file( $id );
	$meta  = (array) wp_get_attachment_metadata( $id );
	$src   = get_post_meta( $id, '_gasf_photo_source', true );

	return array(
		'id'      => $id,
		'thumb'   => wp_get_attachment_image_url( $id, 'medium' ),
		'full'    => wp_get_attachment_image_url( $id, 'large' ),
		'url'     => wp_get_attachment_url( $id ),
		'dlname'  => function_exists( 'gasf_photo_filename' ) ? gasf_photo_filename( $id ) : '',
		'title'   => get_the_title( $id ),
		'caption' => (string) ( $info['caption'] ?? '' ),
		'taken'   => (string) ( $info['taken'] ?? '' ),
		'people'  => (array) ( $info['people'] ?? array() ),
		'places'  => (array) ( $info['places'] ?? array() ),
		'events'  => (array) ( $info['events'] ?? array() ),
		'w'       => (int) ( $meta['width'] ?? 0 ),
		'h'       => (int) ( $meta['height'] ?? 0 ),
		'bytes'   => ( $file && is_file( $file ) ) ? (int) filesize( $file ) : 0,
		// Who gave it to the club. Shown because using a photo in marketing is
		// exactly when somebody needs to know whom to credit, or ask.
		'from'    => is_array( $src ) ? (string) ( $src['name'] ?: $src['email'] ) : '',

		/*
		 * Everything the editing form needs, in the shape it already reads.
		 *
		 * 'saved' carries RAW term names, not the decoded labels above: the
		 * picker's options are raw and writes match on them, so handing it
		 * "Welton Brewing Co & Oyster Bar" where the term holds &amp; would
		 * match nothing and mint a duplicate that looks identical on screen.
		 */
		/*
		 * Whether the club may publish this one.
		 *
		 *   granted  the submitter ticked the box, and we kept the wording
		 *   club     the club's own photo, already on its own website
		 *   unknown  submitted before we started asking — usable in the archive,
		 *            but nobody should put it on a poster without checking
		 *
		 * 'unknown' is deliberately its own answer rather than being folded into
		 * either of the others. Treating a missing record as permission is how a
		 * club ends up publishing a photo it was never given, and treating it as
		 * refusal would quietly bury photos people were perfectly happy to share.
		 */
		'consent'  => gasf_crm_photo_consent_state( $id ),
		'revision' => gasf_crm_photo_revision( $id ),
		'guess'    => ( ! empty( $info['place_guess'] ) && ! is_wp_error( $info['place_guess'] ) ) ? $info['place_guess']->name : '',
		'alts'     => ! empty( $info['place_alts'] ) ? wp_list_pluck( $info['place_alts'], 'name' ) : array(),
		'auto'     => (bool) get_post_meta( $id, '_gasf_photo_autotag', true ),
		'saved'    => array(
			'people'   => array_map( 'strval', (array) wp_get_object_terms( $id, 'gasf_photo_person', array( 'fields' => 'names' ) ) ),
			'place'    => (string) ( wp_get_object_terms( $id, 'gasf_photo_place', array( 'fields' => 'names' ) )[0] ?? '' ),
			'event'    => (string) ( wp_get_object_terms( $id, 'gasf_photo_event', array( 'fields' => 'names' ) )[0] ?? '' ),
			'event_id' => (int) get_post_meta( $id, '_gasf_photo_event_id', true ),
			'caption'  => (string) get_post_field( 'post_excerpt', $id ),
			'taken'    => (string) get_post_meta( $id, '_gasf_photo_taken', true ),
		),
	);
}

/**
 * What is worth offering in the filter bar, with counts.
 *
 * Built from the photos actually in the library rather than from the taxonomies,
 * so a filter can never come back empty. Offering "Kitchen" when no photo is
 * tagged Kitchen wastes a click and makes the whole bar less trustworthy.
 */
function gasf_crm_photo_library_facets( array $ids ) {
	$out = array( 'people' => array(), 'places' => array(), 'events' => array(), 'years' => array() );

	foreach ( $ids as $id ) {
		foreach ( array( 'people' => 'gasf_photo_person', 'places' => 'gasf_photo_place', 'events' => 'gasf_photo_event' ) as $k => $tax ) {
			$t = wp_get_object_terms( $id, $tax, array( 'fields' => 'names' ) );
			foreach ( ( is_wp_error( $t ) ? array() : $t ) as $name ) {
				$label = function_exists( 'gasf_photo_label' ) ? gasf_photo_label( $name ) : $name;
				if ( ! isset( $out[ $k ][ $name ] ) ) { $out[ $k ][ $name ] = array( 'value' => $name, 'label' => $label, 'n' => 0 ); }
				$out[ $k ][ $name ]['n']++;
			}
		}
		$taken = (string) get_post_meta( $id, '_gasf_photo_taken', true );
		$year  = substr( $taken ?: (string) get_post_field( 'post_date', $id ), 0, 4 );
		if ( preg_match( '~^\d{4}$~', $year ) ) {
			if ( ! isset( $out['years'][ $year ] ) ) { $out['years'][ $year ] = array( 'value' => $year, 'label' => $year, 'n' => 0 ); }
			$out['years'][ $year ]['n']++;
		}
	}

	foreach ( array( 'people', 'places', 'events' ) as $k ) {
		usort( $out[ $k ], function ( $a, $b ) { return strnatcasecmp( $a['label'], $b['label'] ); } );
		$out[ $k ] = array_values( $out[ $k ] );
	}
	krsort( $out['years'] );
	$out['years'] = array_values( $out['years'] );

	return $out;
}

/* =====================================================================
 * Editing a catalogued photo
 * ================================================================== */

/**
 * How much a volunteer may write about a photo.
 *
 * Longer than the 150 the public form allows. That cap exists to keep a
 * stranger's form to one screen on a phone; a volunteer writing up who is in a
 * 1974 Fasching picture is doing the archive's actual work and should not be
 * cut off mid-sentence.
 */
define( 'GASF_CRM_LIB_NOTE_MAX', 600 );

/**
 * Save changes to a photo already in the collection.
 *
 * Deliberately NOT gasf_crm_photo_confirm(). That approves a submission — it
 * publishes the file, stamps _gasf_photo_confirmed and moves the workflow on.
 * A library photo is already public and most of these never went through the
 * workflow at all, so running approval over them would record a decision nobody
 * made. Correcting a caption is not approving anything.
 *
 * What it shares with approval is the compare-and-swap, and for the same
 * reason: two volunteers tidying the collection on a Sunday afternoon is the
 * ordinary case, and the second one's save silently overwriting the first is
 * how people stop trusting the tool.
 *
 * @return array|WP_Error the refreshed card
 */
function gasf_crm_photo_library_save( $attachment_id, array $in ) {
	$id = (int) $attachment_id;
	if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
		return new WP_Error( 'gasf_crm_404', 'No such photo.', array( 'status' => 404 ) );
	}
	if ( ! gasf_crm_user_can_stream( 'photos' ) ) {
		return new WP_Error( 'gasf_crm_403', 'You do not have access to the photo library.', array( 'status' => 403 ) );
	}
	if ( ! gasf_crm_photos_available() ) {
		return new WP_Error( 'gasf_crm_nocatalog', 'The Photo Catalogue module is switched off, so there is nowhere to record this.', array( 'status' => 503 ) );
	}

	$have = gasf_crm_photo_revision( $id );
	$want = $in['revision'] ?? null;
	if ( null !== $want && '' !== $want && (int) $want !== $have ) {
		return new WP_Error( 'gasf_crm_stale', 'Somebody else has edited this photo since you opened it. Reload to see their version.', array( 'status' => 409 ) );
	}
	if ( ! update_post_meta( $id, '_gasf_photo_rev', $have + 1, $have ) ) {
		return new WP_Error( 'gasf_crm_stale', 'Somebody else was editing this at the same moment. Reload to see where it got to.', array( 'status' => 409 ) );
	}

	$people = array();
	foreach ( (array) ( $in['people'] ?? array() ) as $p ) {
		$p = trim( sanitize_text_field( $p ) );
		if ( '' !== $p ) { $people[] = $p; }
	}
	$people = array_slice( array_values( array_unique( $people ) ), 0, GASF_CRM_PHOTO_MAX_PEOPLE );
	wp_set_object_terms( $id, $people, 'gasf_photo_person', false );

	// Emptying a field is a real answer — "this is not at a place we know" has
	// to be expressible, or a wrong tag can never be removed, only replaced.
	foreach ( array( 'place' => 'gasf_photo_place', 'event' => 'gasf_photo_event' ) as $k => $tax ) {
		$v = trim( sanitize_text_field( (string) ( $in[ $k ] ?? '' ) ) );
		wp_set_object_terms( $id, '' === $v ? array() : array( $v ), $tax, false );
	}

	$eid = (int) ( $in['event_id'] ?? 0 );
	if ( $eid && gasf_photo_has_calendar() && defined( 'GASF_EVENTS_CPT' ) && GASF_EVENTS_CPT === get_post_type( $eid ) ) {
		update_post_meta( $id, '_gasf_photo_event_id', $eid );
	} else {
		delete_post_meta( $id, '_gasf_photo_event_id' );
	}

	$taken = gasf_crm_photo_clean_date( $in['taken'] ?? '' );
	if ( $taken ) {
		update_post_meta( $id, '_gasf_photo_taken', $taken );
	} else {
		delete_post_meta( $id, '_gasf_photo_taken' );
	}

	$note = trim( sanitize_textarea_field( (string) ( $in['caption'] ?? '' ) ) );
	if ( mb_strlen( $note ) > GASF_CRM_LIB_NOTE_MAX ) { $note = mb_substr( $note, 0, GASF_CRM_LIB_NOTE_MAX ); }
	wp_update_post( array( 'ID' => $id, 'post_excerpt' => $note ) );

	// Title and alt follow the tags. The FILENAME deliberately does not: the
	// file may already be linked from a page or sitting in somebody's downloads,
	// and renaming it would break both. The catalogued name is applied at
	// download time instead, which is where it actually matters.
	if ( function_exists( 'gasf_photo_apply_names' ) ) { gasf_photo_apply_names( $id ); }

	/*
	 * A human has now had their say, so the backfill's claim on this photo ends.
	 *
	 * The receipt stays — it is what keeps a date-only photo in the library —
	 * but it is marked edited, and the undo skips those. Otherwise running
	 * --undo after a tidy-up session would strip a volunteer's work on the
	 * grounds that a machine put something similar there first.
	 */
	$rec = get_post_meta( $id, '_gasf_photo_autotag', true );
	if ( is_array( $rec ) ) {
		$rec['edited']    = current_time( 'mysql', true );
		$rec['edited_by'] = get_current_user_id();
		update_post_meta( $id, '_gasf_photo_autotag', $rec );
	}

	gasf_crm_log_event( 0, 'photo_edited', 'media #' . $id . ' retagged in the library by user ' . get_current_user_id() );

	return gasf_crm_photo_library_card( $id );
}

/* =====================================================================
 * Bulk download
 * ================================================================== */

/** Where built zips live — the private root, never the webroot. */
function gasf_crm_photo_zip_dir() {
	$dir = gasf_crm_photo_private_root() . '/zips';
	if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
	return $dir;
}

/**
 * Build a zip of the requested photos.
 *
 * The ORIGINAL files, not the web-sized copies — the entire point is using them
 * somewhere the web size is not good enough. Each one is named by the catalogue,
 * so what lands on the volunteer's desktop is already labelled.
 *
 * @return array{token:string,name:string,files:int,bytes:int}|WP_Error
 */
function gasf_crm_photo_zip_build( array $ids ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'gasf_crm_nozip', 'This server cannot build zip files, so photos have to be downloaded one at a time.' );
	}

	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	if ( ! $ids ) { return new WP_Error( 'gasf_crm_nozip', 'No photos were selected.' ); }

	if ( count( $ids ) > GASF_CRM_LIB_ZIP_MAX_FILES ) {
		return new WP_Error( 'gasf_crm_toomany', sprintf(
			'That is %d photos; %d is the most in one download. Narrow the filter, or take them in a couple of goes.',
			count( $ids ), GASF_CRM_LIB_ZIP_MAX_FILES
		) );
	}

	// Sized up before a byte is written. Discovering the limit halfway through
	// means throwing away work AND leaving a part-built file behind.
	$files = array();
	$bytes = 0;
	foreach ( $ids as $id ) {
		if ( 'attachment' !== get_post_type( $id ) ) { continue; }
		if ( gasf_crm_photo_is_private( $id ) ) { continue; } // not cleared for use
		$path = get_attached_file( $id );
		if ( ! $path || ! is_file( $path ) ) { continue; }
		$bytes  += (int) filesize( $path );
		$files[] = array( 'path' => $path, 'id' => $id );
	}

	if ( ! $files ) { return new WP_Error( 'gasf_crm_nozip', 'None of those photos have a file to download.' ); }
	if ( $bytes > GASF_CRM_LIB_ZIP_MAX_BYTES ) {
		return new WP_Error( 'gasf_crm_toobig', sprintf(
			'That selection is %s; %s is the most in one download. Narrow the filter, or take them in a couple of goes.',
			size_format( $bytes ), size_format( GASF_CRM_LIB_ZIP_MAX_BYTES )
		) );
	}

	$dir   = gasf_crm_photo_zip_dir();
	$token = bin2hex( random_bytes( 16 ) );
	$path  = $dir . '/' . $token . '.zip';

	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		return new WP_Error( 'gasf_crm_nozip', 'The server could not start building that download.' );
	}

	$used = array();
	foreach ( $files as $f ) {
		$name = function_exists( 'gasf_photo_filename' ) ? gasf_photo_filename( $f['id'] ) : '';
		if ( '' === $name ) { $name = basename( $f['path'] ); }

		// Two photos of the same people at the same event on the same day get
		// the same catalogued name. Numbered rather than overwritten — a zip
		// that quietly contains four files when five were asked for is the kind
		// of silent loss this whole build exists to avoid.
		if ( isset( $used[ $name ] ) ) {
			$used[ $name ]++;
			$ext  = pathinfo( $name, PATHINFO_EXTENSION );
			$stem = $ext ? substr( $name, 0, -( strlen( $ext ) + 1 ) ) : $name;
			$name = $stem . '-' . $used[ $name ] . ( $ext ? '.' . $ext : '' );
		} else {
			$used[ $name ] = 1;
		}

		$zip->addFile( $f['path'], $name );
	}

	$org  = sanitize_file_name( gasf_crm_cfg()['signature_org'] ?: 'photos' );
	$name = 'GASF-photos-' . gmdate( 'Y-m-d' ) . '-' . count( $files ) . '.zip';
	$zip->setArchiveComment( $org . ' — ' . count( $files ) . ' photo(s), downloaded ' . gmdate( 'Y-m-d' ) );

	if ( ! $zip->close() ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'gasf_crm_nozip', 'The download could not be finished. Try a smaller selection.' );
	}

	set_transient( 'gasf_crm_zip_' . $token, array(
		'path' => $path,
		'name' => $name,
		'by'   => get_current_user_id(),
	), GASF_CRM_LIB_ZIP_TTL );

	gasf_mec_log( sprintf( 'CRM library: built a %s zip of %d photo(s) for user %d',
		size_format( filesize( $path ) ), count( $files ), get_current_user_id() ) );

	return array( 'token' => $token, 'name' => $name, 'files' => count( $files ), 'bytes' => (int) filesize( $path ) );
}

/**
 * Remove zips whose download window has passed.
 *
 * Transients expire on their own; the FILES do not, and these are the only
 * things in this system that hold full-size copies of the whole collection in
 * one place. Left alone they would accumulate until the disk filled.
 */
function gasf_crm_photo_zip_sweep() {
	$dir = gasf_crm_photo_private_root() . '/zips';
	if ( ! is_dir( $dir ) ) { return 0; }

	$n = 0;
	foreach ( (array) glob( $dir . '/*.zip' ) as $f ) {
		if ( ( time() - (int) filemtime( $f ) ) < GASF_CRM_LIB_ZIP_TTL ) { continue; }
		@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$n++;
	}
	if ( $n ) { gasf_mec_log( 'CRM library: swept ' . $n . ' expired download(s)' ); }
	return $n;
}
add_action( 'gasf_crm_sync_event', 'gasf_crm_photo_zip_sweep', 30 );

/* =====================================================================
 * REST
 * ================================================================== */

add_action( 'rest_api_init', function () {
	// Same gate as the rest of the photo surface: holding the photos stream,
	// which is not the same thing as being a WordPress administrator.
	$lib_guard = function () {
		$sess = gasf_crm_rest_guard();
		if ( is_wp_error( $sess ) || ! $sess ) { return $sess ?: false; }
		return gasf_crm_user_can_stream( 'photos' );
	};

	register_rest_route( 'gasf/v1', '/crm/photos/library', array(
		'methods'             => 'GET',
		'permission_callback' => $lib_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$filters = array(
				'person' => (string) $req->get_param( 'person' ),
				'place'  => (string) $req->get_param( 'place' ),
				'event'  => (string) $req->get_param( 'event' ),
				'year'   => (string) $req->get_param( 'year' ),
				'q'      => (string) $req->get_param( 'q' ),
			);

			// Facets come from the UNFILTERED set, so the bar does not collapse
			// to whatever is left after the first choice — picking a place must
			// not hide every year you might narrow it to next.
			$all      = gasf_crm_photo_library_ids();
			$matching = gasf_crm_photo_library_filter( $all, $filters );

			$page  = max( 1, (int) $req->get_param( 'page' ) );
			$slice = array_slice( $matching, ( $page - 1 ) * GASF_CRM_LIB_PER_PAGE, GASF_CRM_LIB_PER_PAGE );

			$photos = array();
			foreach ( $slice as $id ) {
				$card = gasf_crm_photo_library_card( $id );
				if ( $card ) { $photos[] = $card; }
			}

			return array(
				'photos'  => $photos,
				'total'   => count( $matching ),
				'all'     => count( $all ),
				'page'    => $page,
				'pages'   => max( 1, (int) ceil( count( $matching ) / GASF_CRM_LIB_PER_PAGE ) ),
				'facets'  => gasf_crm_photo_library_facets( $all ),
				// Every matching id, so "select all" can act on the whole result
				// rather than only the page in front of you.
				'ids'     => array_map( 'intval', $matching ),
				'zipmax'  => GASF_CRM_LIB_ZIP_MAX_FILES,
			);
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/edit', array(
		'methods'             => 'POST',
		'permission_callback' => $lib_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			return gasf_crm_photo_library_save( (int) $req->get_param( 'photo' ), array(
				'people'   => (array) $req->get_param( 'people' ),
				'place'    => (string) $req->get_param( 'place' ),
				'event'    => (string) $req->get_param( 'event' ),
				'event_id' => (int) $req->get_param( 'event_id' ),
				'taken'    => (string) $req->get_param( 'taken' ),
				'caption'  => (string) $req->get_param( 'caption' ),
				'revision' => $req->get_param( 'revision' ),
			) );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/zip', array(
		'methods'             => 'POST',
		'permission_callback' => $lib_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$ids = (array) $req->get_param( 'ids' );
			$res = gasf_crm_photo_zip_build( $ids );
			if ( is_wp_error( $res ) ) { return $res; }

			$res['url'] = add_query_arg( array(
				'token'    => $res['token'],
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
			), rest_url( 'gasf/v1/crm/photos/zipfile' ) );

			return $res;
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/photos/zipfile', array(
		'methods'             => 'GET',
		'permission_callback' => $lib_guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$token = preg_replace( '~[^a-f0-9]~', '', (string) $req->get_param( 'token' ) );
			$row   = $token ? get_transient( 'gasf_crm_zip_' . $token ) : false;

			if ( ! is_array( $row ) || empty( $row['path'] ) || ! is_file( $row['path'] ) ) {
				return new WP_Error( 'gasf_crm_404', 'That download has expired. Build it again — it only takes a moment.', array( 'status' => 404 ) );
			}
			// Built for one person. The token is not a capability anyone else
			// inherits by being handed the URL.
			if ( (int) $row['by'] !== get_current_user_id() ) {
				return new WP_Error( 'gasf_crm_403', 'That download belongs to somebody else.', array( 'status' => 403 ) );
			}

			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $row['name'] . '"' );
			header( 'Content-Length: ' . filesize( $row['path'] ) );
			header( 'X-Robots-Tag: noindex, nofollow', true );

			readfile( $row['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			// Gone the moment it has been taken. One download per build keeps
			// full-size copies of the collection from lingering on disk.
			@unlink( $row['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			delete_transient( 'gasf_crm_zip_' . $token );
			exit;
		},
	) );
} );
