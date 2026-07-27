<?php
/**
 * Photo catalogue — modules/43-photo-catalog.php
 *
 * Collects information ABOUT a photo, not just the photo: who is in it, where
 * and when it was taken, and what it shows.
 *
 * WHERE THE DATA LIVES, and why it is not a custom table:
 *
 *   people / place / event   taxonomies on the attachment post type
 *   date taken, GPS, camera  post meta on the attachment
 *   description              the attachment's own Caption (post_excerpt)
 *
 * An attachment is already a post, so all of this is native: it survives
 * export, it is searchable, deleting the photo takes its data with it, and a
 * misspelled name is fixed once rather than in forty rows. A parallel table
 * keyed on attachment ID would orphan itself the first time somebody deleted a
 * photo in the Media Library, and would throw away the term UI for nothing.
 *
 * People, places and events are TAXONOMIES rather than text fields for one
 * reason: "every photo of Hans" is a question you can only answer if Hans is
 * one thing. Stored as free text you get "Hans Müller", "Hans Muller" and
 * "hans müller" as three different people, permanently, and no amount of later
 * tidying finds them all.
 *
 * They are registered non-public and NOT exposed over REST. A list of the names
 * of people in the club's photos should not be a public JSON endpoint, and a
 * public taxonomy would also generate front-end archive pages for every person
 * named — which nobody asked for and which would be indexed.
 *
 * Gate: gasf_site_enable_photocatalog (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_photocatalog' ) : true ) {

	/**
	 * How close a photo's GPS has to be to a known place to be counted as
	 * taken there, when that place has no radius of its own. Deliberately
	 * generous: consumer phone GPS is routinely 20–50 m out, and indoors or
	 * under a roof it is far worse.
	 */
	define( 'GASF_PHOTO_DEFAULT_RADIUS_M', 150 );

	/* ---------------------------------------------------------------------
	 * Taxonomies
	 * ------------------------------------------------------------------- */

	add_action( 'init', function () {
		$common = array(
			// Non-public: no front-end archives, not publicly queryable, and
			// absent from REST. The admin UI is the only way in.
			'public'             => false,
			'publicly_queryable' => false,
			'show_in_rest'       => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_admin_column'  => true,
			'hierarchical'       => false,
			'rewrite'            => false,
		);

		register_taxonomy( 'gasf_photo_person', array( 'attachment' ), array_merge( $common, array(
			'labels' => gasf_photo_labels( __( 'Person', 'gasf' ), __( 'People', 'gasf' ) ),
		) ) );

		register_taxonomy( 'gasf_photo_place', array( 'attachment' ), array_merge( $common, array(
			'labels' => gasf_photo_labels( __( 'Place', 'gasf' ), __( 'Places', 'gasf' ) ),
		) ) );

		register_taxonomy( 'gasf_photo_event', array( 'attachment' ), array_merge( $common, array(
			'labels' => gasf_photo_labels( __( 'Event', 'gasf' ), __( 'Events', 'gasf' ) ),
		) ) );

		gasf_photo_seed_home_place();
	}, 5 );

	function gasf_photo_labels( $single, $plural ) {
		return array(
			'name'          => $plural,
			'singular_name' => $single,
			'search_items'  => sprintf( __( 'Search %s', 'gasf' ), $plural ),
			'all_items'     => $plural,
			'edit_item'     => sprintf( __( 'Edit %s', 'gasf' ), $single ),
			'add_new_item'  => sprintf( __( 'Add %s', 'gasf' ), $single ),
			'not_found'     => sprintf( __( 'No %s yet.', 'gasf' ), strtolower( $plural ) ),
			'menu_name'     => $plural,
		);
	}

	/**
	 * The club's own address, created once so the tagging form has a sensible
	 * default and the geofence has something to match against on day one.
	 *
	 * Guarded by an option rather than by "does the term exist", so deleting it
	 * on purpose does not bring it straight back on the next page load.
	 */
	function gasf_photo_seed_home_place() {
		if ( get_option( 'gasf_photo_seeded' ) ) { return; }
		update_option( 'gasf_photo_seeded', 1, false );

		if ( term_exists( 'German-American Society', 'gasf_photo_place' ) ) { return; }
		$t = wp_insert_term( 'German-American Society', 'gasf_photo_place' );
		if ( ! is_wp_error( $t ) ) {
			update_option( 'gasf_photo_home_place', (int) $t['term_id'], false );
		}
	}

	/** The default place — what the tagging form pre-selects. 0 if unset. */
	function gasf_photo_home_place() {
		return (int) get_option( 'gasf_photo_home_place', 0 );
	}

	/* ---------------------------------------------------------------------
	 * EXIF
	 *
	 * Read from the ORIGINAL file, before it reaches the Media Library: the
	 * image-compress module converts uploads to WebP and the conversion drops
	 * EXIF entirely. Pulling it out first and storing it as meta means the
	 * information survives, is queryable, and the published file no longer
	 * carries GPS — which is the right outcome for a photo that might have been
	 * taken at somebody's house.
	 *
	 * Everything here is best-effort and must stay that way. Mail clients and
	 * phone share-sheets routinely strip EXIF, plenty of people never had
	 * location on, and a re-saved image has nothing left. This PRE-FILLS a form;
	 * it never gates one.
	 * ------------------------------------------------------------------- */

	/**
	 * @return array{taken:string,lat:?float,lon:?float,camera:string,raw:array}
	 */
	function gasf_photo_read_exif( $path ) {
		$out = array( 'taken' => '', 'lat' => null, 'lon' => null, 'camera' => '', 'raw' => array() );

		if ( ! function_exists( 'exif_read_data' ) || ! is_readable( $path ) ) { return $out; }

		// JPEG/TIFF only. A PNG or WebP is not an error here, it simply has
		// nothing to read, so this returns empties rather than warning.
		$type = @exif_imagetype( $path );
		if ( IMAGETYPE_JPEG !== $type && IMAGETYPE_TIFF_II !== $type && IMAGETYPE_TIFF_MM !== $type ) {
			return $out;
		}

		$exif = @exif_read_data( $path, 'ANY_TAG', true );
		if ( ! is_array( $exif ) ) { return $out; }

		// DateTimeOriginal is when the shutter fired. DateTime is when the file
		// was last written, which a crop or a rotate silently updates — so the
		// fallback order matters.
		$when = '';
		foreach ( array(
			array( 'EXIF', 'DateTimeOriginal' ),
			array( 'EXIF', 'DateTimeDigitized' ),
			array( 'IFD0', 'DateTime' ),
		) as $k ) {
			if ( ! empty( $exif[ $k[0] ][ $k[1] ] ) ) { $when = (string) $exif[ $k[0] ][ $k[1] ]; break; }
		}
		if ( $when ) {
			// EXIF writes "2026:07:27 14:03:11" — colons in the date part, which
			// strtotime does not understand.
			$parts = explode( ' ', trim( $when ), 2 );
			$date  = str_replace( ':', '-', $parts[0] );
			$ts    = strtotime( $date . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' ) );
			// Cameras with a dead clock stamp 1970 or 2000-01-01; a date before
			// the club had digital cameras is noise, not data.
			if ( $ts && $ts > strtotime( '1990-01-01' ) && $ts < time() + DAY_IN_SECONDS ) {
				$out['taken'] = gmdate( 'Y-m-d', $ts );
				$out['raw']['taken_at'] = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$make  = trim( (string) ( $exif['IFD0']['Make'] ?? '' ) );
		$model = trim( (string) ( $exif['IFD0']['Model'] ?? '' ) );
		// "Apple Apple iPhone 15" reads badly; drop the make when the model
		// already starts with it, which most phones do.
		if ( $make && $model && 0 === stripos( $model, $make ) ) { $make = ''; }
		$out['camera'] = trim( $make . ' ' . $model );

		$gps = isset( $exif['GPS'] ) && is_array( $exif['GPS'] ) ? $exif['GPS'] : array();
		if ( isset( $gps['GPSLatitude'], $gps['GPSLongitude'] ) ) {
			$lat = gasf_photo_gps_decimal( $gps['GPSLatitude'], $gps['GPSLatitudeRef'] ?? 'N' );
			$lon = gasf_photo_gps_decimal( $gps['GPSLongitude'], $gps['GPSLongitudeRef'] ?? 'E' );
			// 0,0 is in the Atlantic and is what a confused GPS chip writes.
			if ( null !== $lat && null !== $lon && ( abs( $lat ) > 0.0001 || abs( $lon ) > 0.0001 ) ) {
				$out['lat'] = $lat;
				$out['lon'] = $lon;
			}
		}

		return $out;
	}

	/** EXIF stores GPS as three rationals plus a hemisphere letter. */
	function gasf_photo_gps_decimal( $coord, $ref ) {
		if ( ! is_array( $coord ) || count( $coord ) < 3 ) { return null; }

		$d = gasf_photo_rational( $coord[0] );
		$m = gasf_photo_rational( $coord[1] );
		$s = gasf_photo_rational( $coord[2] );
		if ( null === $d || null === $m || null === $s ) { return null; }

		$dec = $d + ( $m / 60 ) + ( $s / 3600 );
		if ( $dec > 180 ) { return null; }
		if ( in_array( strtoupper( trim( (string) $ref ) ), array( 'S', 'W' ), true ) ) { $dec = -$dec; }

		return round( $dec, 7 );
	}

	/** "57/1" -> 57.0. Division by a zero denominator is a malformed tag. */
	function gasf_photo_rational( $v ) {
		if ( is_numeric( $v ) ) { return (float) $v; }
		if ( is_string( $v ) && false !== strpos( $v, '/' ) ) {
			$bits = explode( '/', $v, 2 );
			$den  = (float) trim( $bits[1] );
			if ( 0.0 === $den ) { return null; }
			return (float) trim( $bits[0] ) / $den;
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * Geofence
	 *
	 * Places carry their own coordinates, so "which of our venues is this?" is a
	 * distance comparison against a handful of terms — no geocoding API, no key,
	 * no quota, no third party told where the club's members were on Saturday.
	 * It also returns OUR name for a place ("England Brothers Park") rather than
	 * a geocoder's idea of it ("1500 16th St N").
	 * ------------------------------------------------------------------- */

	/** Metres between two points. Haversine — accurate enough at these scales. */
	function gasf_photo_distance_m( $lat1, $lon1, $lat2, $lon2 ) {
		$r    = 6371000.0;
		$dlat = deg2rad( $lat2 - $lat1 );
		$dlon = deg2rad( $lon2 - $lon1 );
		$a    = sin( $dlat / 2 ) ** 2
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlon / 2 ) ** 2;
		return $r * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/**
	 * Closest place whose radius contains this point, or 0.
	 *
	 * Closest rather than first-match: two venues can overlap once radii are
	 * generous, and the nearer one is the better guess.
	 */
	function gasf_photo_place_for( $lat, $lon ) {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lon ) ) { return 0; }

		$terms = get_terms( array(
			'taxonomy'   => 'gasf_photo_place',
			'hide_empty' => false,
		) );
		if ( is_wp_error( $terms ) ) { return 0; }

		$best = 0;
		$best_d = null;
		foreach ( $terms as $t ) {
			$plat = get_term_meta( $t->term_id, 'gasf_lat', true );
			$plon = get_term_meta( $t->term_id, 'gasf_lon', true );
			if ( '' === $plat || '' === $plon ) { continue; }

			$radius = (float) get_term_meta( $t->term_id, 'gasf_radius', true );
			if ( $radius <= 0 ) { $radius = GASF_PHOTO_DEFAULT_RADIUS_M; }

			$d = gasf_photo_distance_m( (float) $lat, (float) $lon, (float) $plat, (float) $plon );
			if ( $d <= $radius && ( null === $best_d || $d < $best_d ) ) {
				$best   = (int) $t->term_id;
				$best_d = $d;
			}
		}

		return $best;
	}

	/* ---------------------------------------------------------------------
	 * Place coordinates — term meta fields on the Places screen
	 * ------------------------------------------------------------------- */

	add_action( 'gasf_photo_place_add_form_fields', function () {
		?>
		<div class="form-field">
			<label for="gasf_lat">Latitude / Longitude</label>
			<input type="text" name="gasf_lat" id="gasf_lat" placeholder="27.8756" style="width:47%">
			<input type="text" name="gasf_lon" placeholder="-82.7784" style="width:47%">
			<p>Optional. Fill these in and a submitted photo carrying GPS is matched to this place automatically. Copy them from Google Maps: right-click the spot, and the first line of the menu is the pair.</p>
		</div>
		<div class="form-field">
			<label for="gasf_radius">Radius (metres)</label>
			<input type="number" name="gasf_radius" id="gasf_radius" min="10" max="20000" placeholder="<?php echo (int) GASF_PHOTO_DEFAULT_RADIUS_M; ?>">
			<p>How far from that point still counts as here. Blank uses <?php echo (int) GASF_PHOTO_DEFAULT_RADIUS_M; ?> m. Phone GPS is often 20–50 m out and much worse indoors, so err generous.</p>
		</div>
		<?php
	} );

	add_action( 'gasf_photo_place_edit_form_fields', function ( $term ) {
		$lat = get_term_meta( $term->term_id, 'gasf_lat', true );
		$lon = get_term_meta( $term->term_id, 'gasf_lon', true );
		$rad = get_term_meta( $term->term_id, 'gasf_radius', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="gasf_lat">Latitude / Longitude</label></th>
			<td>
				<input type="text" name="gasf_lat" id="gasf_lat" value="<?php echo esc_attr( $lat ); ?>" placeholder="27.8756" style="width:47%">
				<input type="text" name="gasf_lon" value="<?php echo esc_attr( $lon ); ?>" placeholder="-82.7784" style="width:47%">
				<p class="description">A submitted photo carrying GPS inside the radius below is matched to this place automatically. Copy the pair from Google Maps: right-click the spot and the first menu line is the coordinates.</p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="gasf_radius">Radius (metres)</label></th>
			<td>
				<input type="number" name="gasf_radius" id="gasf_radius" min="10" max="20000" value="<?php echo esc_attr( $rad ); ?>" placeholder="<?php echo (int) GASF_PHOTO_DEFAULT_RADIUS_M; ?>">
				<p class="description">Blank uses <?php echo (int) GASF_PHOTO_DEFAULT_RADIUS_M; ?> m.</p>
			</td>
		</tr>
		<?php
	} );

	/**
	 * Save place coordinates.
	 *
	 * Out-of-range values are DISCARDED rather than clamped: a clamped
	 * coordinate is a real point somewhere else in the world, which would
	 * silently geofence photos to the wrong venue. Wrong input should leave the
	 * field empty, where it is obvious.
	 */
	foreach ( array( 'created_gasf_photo_place', 'edited_gasf_photo_place' ) as $hook ) {
		add_action( $hook, function ( $term_id ) {
			if ( ! current_user_can( 'manage_categories' ) ) { return; }

			$lat = isset( $_POST['gasf_lat'] ) ? trim( wp_unslash( $_POST['gasf_lat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- WP verifies the term form's own nonce before this fires.
			$lon = isset( $_POST['gasf_lon'] ) ? trim( wp_unslash( $_POST['gasf_lon'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$rad = isset( $_POST['gasf_radius'] ) ? (int) $_POST['gasf_radius'] : 0;            // phpcs:ignore WordPress.Security.NonceVerification

			$ok_lat = is_numeric( $lat ) && abs( (float) $lat ) <= 90;
			$ok_lon = is_numeric( $lon ) && abs( (float) $lon ) <= 180;

			// Both or neither — half a coordinate cannot place anything, and
			// storing one would make the Places screen look configured.
			if ( $ok_lat && $ok_lon ) {
				update_term_meta( $term_id, 'gasf_lat', (float) $lat );
				update_term_meta( $term_id, 'gasf_lon', (float) $lon );
			} else {
				delete_term_meta( $term_id, 'gasf_lat' );
				delete_term_meta( $term_id, 'gasf_lon' );
			}

			if ( $rad >= 10 && $rad <= 20000 ) {
				update_term_meta( $term_id, 'gasf_radius', $rad );
			} else {
				delete_term_meta( $term_id, 'gasf_radius' );
			}
		} );
	}

	// Show the coordinates in the Places list, so a place with no geofence is
	// visible at a glance rather than only when a photo fails to match.
	add_filter( 'manage_edit-gasf_photo_place_columns', function ( $cols ) {
		$cols['gasf_geo'] = 'Geofence';
		return $cols;
	} );
	add_filter( 'manage_gasf_photo_place_custom_column', function ( $out, $col, $term_id ) {
		if ( 'gasf_geo' !== $col ) { return $out; }
		$lat = get_term_meta( $term_id, 'gasf_lat', true );
		$lon = get_term_meta( $term_id, 'gasf_lon', true );
		if ( '' === $lat || '' === $lon ) { return '<span style="color:#8c8f94">not set</span>'; }
		$rad = (int) get_term_meta( $term_id, 'gasf_radius', true );
		return esc_html( sprintf( '%.5f, %.5f · %d m', (float) $lat, (float) $lon, $rad ?: GASF_PHOTO_DEFAULT_RADIUS_M ) );
	}, 10, 3 );

	/* ---------------------------------------------------------------------
	 * Storing what we learned
	 * ------------------------------------------------------------------- */

	/**
	 * Attach EXIF findings to an attachment.
	 *
	 * Never overwrites a value already there: by the time this runs a human may
	 * have corrected the date, and a re-run of the importer must not undo them.
	 */
	function gasf_photo_store_exif( $attachment_id, array $exif ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) { return; }

		if ( $exif['taken'] && ! get_post_meta( $attachment_id, '_gasf_photo_taken', true ) ) {
			update_post_meta( $attachment_id, '_gasf_photo_taken', $exif['taken'] );
		}
		if ( $exif['camera'] ) {
			update_post_meta( $attachment_id, '_gasf_photo_camera', $exif['camera'] );
		}
		if ( null !== $exif['lat'] && null !== $exif['lon'] ) {
			update_post_meta( $attachment_id, '_gasf_photo_lat', $exif['lat'] );
			update_post_meta( $attachment_id, '_gasf_photo_lon', $exif['lon'] );

			// The geofence guess is recorded separately from any place a human
			// later assigns, so "the camera said here" and "somebody decided
			// here" never get confused for one another.
			$place = gasf_photo_place_for( $exif['lat'], $exif['lon'] );
			if ( $place ) {
				update_post_meta( $attachment_id, '_gasf_photo_place_guess', $place );
			}
		}
	}

	/**
	 * Everything known about a photo, in one shape, for the admin panel, the
	 * tagging form and the CRM review screen.
	 */
	function gasf_photo_info( $attachment_id ) {
		$id = (int) $attachment_id;

		$terms = array();
		foreach ( array( 'person' => 'gasf_photo_person', 'place' => 'gasf_photo_place', 'event' => 'gasf_photo_event' ) as $k => $tax ) {
			$t = wp_get_object_terms( $id, $tax, array( 'fields' => 'names' ) );
			$terms[ $k ] = is_wp_error( $t ) ? array() : $t;
		}

		$guess = (int) get_post_meta( $id, '_gasf_photo_place_guess', true );

		return array(
			'id'          => $id,
			'taken'       => (string) get_post_meta( $id, '_gasf_photo_taken', true ),
			'camera'      => (string) get_post_meta( $id, '_gasf_photo_camera', true ),
			'lat'         => get_post_meta( $id, '_gasf_photo_lat', true ),
			'lon'         => get_post_meta( $id, '_gasf_photo_lon', true ),
			'place_guess' => $guess ? get_term( $guess, 'gasf_photo_place' ) : null,
			'caption'     => (string) get_post_field( 'post_excerpt', $id ),
			'people'      => $terms['person'],
			'places'      => $terms['place'],
			'events'      => $terms['event'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Media Library fields
	 *
	 * attachment_fields_to_edit rather than a metabox, so these appear in BOTH
	 * the full Edit Media screen and the modal that opens from a post — a
	 * metabox only ever shows up in one of them.
	 * ------------------------------------------------------------------- */

	add_filter( 'attachment_fields_to_edit', function ( $fields, $post ) {
		if ( 0 !== strpos( (string) get_post_mime_type( $post ), 'image/' ) ) { return $fields; }

		$info = gasf_photo_info( $post->ID );

		$fields['gasf_photo_taken'] = array(
			'label' => __( 'Date taken', 'gasf' ),
			'input' => 'html',
			'html'  => sprintf(
				'<input type="date" name="attachments[%1$d][gasf_photo_taken]" id="attachments-%1$d-gasf_photo_taken" value="%2$s" style="width:100%%">',
				(int) $post->ID,
				esc_attr( $info['taken'] )
			),
			'helps' => $info['taken']
				? __( 'From the camera unless somebody changed it.', 'gasf' )
				: __( 'The photo carried no date — most likely stripped in transit, which is normal.', 'gasf' ),
		);

		// Read-only, because these are observations rather than opinions: they
		// say what the file claimed, and editing them would destroy the only
		// independent evidence of where a photo came from.
		$where = __( 'No location in the file.', 'gasf' );
		if ( '' !== $info['lat'] && '' !== $info['lon'] ) {
			$where = sprintf( '%.5f, %.5f', (float) $info['lat'], (float) $info['lon'] );
			if ( $info['place_guess'] && ! is_wp_error( $info['place_guess'] ) ) {
				$where .= ' — ' . sprintf( __( 'inside %s', 'gasf' ), $info['place_guess']->name );
			} else {
				$where .= ' — ' . __( 'not inside any place you have mapped', 'gasf' );
			}
		}
		$fields['gasf_photo_where'] = array(
			'label' => __( 'Camera said', 'gasf' ),
			'input' => 'html',
			'html'  => '<span class="description">' . esc_html( $where )
				. ( $info['camera'] ? '<br>' . esc_html( $info['camera'] ) : '' ) . '</span>',
		);

		return $fields;
	}, 10, 2 );

	add_filter( 'attachment_fields_to_save', function ( $post, $attachment ) {
		if ( ! isset( $attachment['gasf_photo_taken'] ) ) { return $post; }
		if ( ! current_user_can( 'edit_post', $post['ID'] ) ) { return $post; }

		$d = trim( (string) $attachment['gasf_photo_taken'] );
		if ( '' === $d ) {
			delete_post_meta( (int) $post['ID'], '_gasf_photo_taken' );
			return $post;
		}

		// Y-m-d and nothing else. checkdate rejects 2026-02-31, which the date
		// input will happily post from a browser that has no native picker.
		if ( preg_match( '~^(\d{4})-(\d{2})-(\d{2})$~', $d, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			update_post_meta( (int) $post['ID'], '_gasf_photo_taken', $d );
		}

		return $post;
	}, 10, 2 );

	/* ---------------------------------------------------------------------
	 * Catching EXIF before something else eats it
	 *
	 * This host runs the Bluehost/Newfold performance module, which converts
	 * uploads to WebP from wp_handle_upload and add_attachment (both at priority
	 * 10) — and the conversion discards EXIF completely.
	 *
	 * This is not a guess. The first version of this file read EXIF at
	 * wp_generate_attachment_metadata priority 5, which sounds early and is not:
	 * it runs after both of those. The test uploaded a JPEG carrying a date, a
	 * camera and GPS, and the attachment came out with none of the three.
	 *
	 * So the read happens at wp_handle_upload / wp_handle_sideload priority 1 —
	 * the first moment the file is on disk and still the original — and the
	 * result waits there until the attachment exists to hang it on.
	 *
	 * It waits KEYED BY PATH rather than in a queue. An upload rejected after
	 * this point never reaches add_attachment, and a queue would then hand its
	 * coordinates to the next photo along. One photo wearing another's GPS is
	 * far worse than a photo with no GPS.
	 * ------------------------------------------------------------------- */

	/** Directory + filename without extension: conversion changes only the extension. */
	function gasf_photo_park_key( $path ) {
		return dirname( $path ) . '/' . pathinfo( $path, PATHINFO_FILENAME );
	}

	function gasf_photo_park_exif( $path, array $exif ) {
		$GLOBALS['gasf_photo_exif_park'][ gasf_photo_park_key( $path ) ] = $exif;
	}

	/** Retrieve and remove — nothing should be able to consume the same read twice. */
	function gasf_photo_take_exif( $path ) {
		$k = gasf_photo_park_key( $path );
		if ( ! isset( $GLOBALS['gasf_photo_exif_park'][ $k ] ) ) { return null; }
		$e = $GLOBALS['gasf_photo_exif_park'][ $k ];
		unset( $GLOBALS['gasf_photo_exif_park'][ $k ] );
		return $e;
	}

	foreach ( array( 'wp_handle_upload', 'wp_handle_sideload' ) as $gasf_photo_hook ) {
		add_filter( $gasf_photo_hook, function ( $upload ) {
			if ( empty( $upload['file'] ) || empty( $upload['type'] ) ) { return $upload; }
			if ( 0 !== strpos( (string) $upload['type'], 'image/' ) ) { return $upload; }

			$exif = gasf_photo_read_exif( $upload['file'] );
			// Only park a read that found something. Parking an empty result
			// would consume the fallback below for no benefit.
			if ( $exif['taken'] || $exif['camera'] || null !== $exif['lat'] ) {
				gasf_photo_park_exif( $upload['file'], $exif );
			}
			return $upload;
		}, 1 );
	}
	unset( $gasf_photo_hook );

	add_action( 'add_attachment', function ( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) { return; }

		$exif = gasf_photo_take_exif( $file );

		// Nothing parked — an attachment created by some path that does not go
		// through the upload handlers. Read the file as it stands: correct when
		// nothing has converted it, and harmlessly empty when something has.
		// gasf_photo_store_exif ignores empty values, so this can never clobber.
		if ( null === $exif ) { $exif = gasf_photo_read_exif( $file ); }

		gasf_photo_store_exif( $attachment_id, $exif );
	}, 1 );
}
