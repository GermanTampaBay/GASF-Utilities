<?php
/**
 * Image Compressor — modules/38-image-compress.php
 *
 * Self-contained media-library image compression: converts oversized JPEG/PNG
 * attachments to WebP with GD/Imagick (no external API, no quota, no file-size
 * ceiling — built after Imagify's free tier refused a 5.7 MB PNG). Replaces
 * the in-production image: the attachment's file, mime and thumbnail set all
 * become the WebP, and every stored reference (post content, serialized
 * postmeta like SiteOrigin panels_data, widgets/options, Smart Slider's own
 * tables) is rewritten to the new URL. Originals are KEPT on disk by default
 * (rollback safety); old URLs keep working for anything external that cached
 * them.
 *
 * Naming: original.png -> original-compressed.webp. If the result would be
 * too long, the END of the original base name is trimmed to fit
 * (picture-compressed.webp -> pict-compressed.webp).
 *
 * Failure-averse by design: files over any size are fine; extension/header
 * mismatches are handled by a content-sniffing fallback decoder
 * (imagecreatefromstring / Imagick blob, which don't trust the filename);
 * odd characters in filenames are transliterated + sanitized for the NEW name
 * (the old file is read as-is, whatever it's called). A file that truly can't
 * be decoded, or whose WebP comes out LARGER, is simply marked done and left
 * untouched — logged, never fatal, never blocks the rest of the batch.
 *
 * Runs on demand (Images tab) and/or via WP-cron every 4 hours (off by
 * default — enable in settings). Gate: gasf_site_enable_imgcompress.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_imgcompress' ) : true ) {

	define( 'GASF_IMGC_META', '_gasf_imgc' );          // per-attachment result (its absence = candidate)
	define( 'GASF_IMGC_MAX_NAME', 60 );                // max total filename length incl. "-compressed.webp"

	function gasf_imgc_settings() {
		return wp_parse_args( (array) get_option( 'gasf_imgc_settings', array() ), array(
			'cron'         => 0,     // 4-hourly background batch (opt-in)
			'threshold_kb' => 500,   // only files larger than this are compressed
			'quality'      => 78,    // WebP quality
			'max_w'        => 2560,  // cap longest side (matches Imagify's old resize setting)
			'batch'        => 5,     // attachments per run
			'delete_orig'  => 0,     // keep originals on disk by default (rollback safety)
		) );
	}

	/* ============================ activity log ============================ */

	/**
	 * Rolling activity log (option-backed, newest first, capped). Every file
	 * gets a "processing" line BEFORE work starts and a completion line after —
	 * so if a run ever dies mid-image, the log shows exactly which file it was
	 * on. Mirrored to the server log (gasf_mec_log) too.
	 */
	function gasf_imgc_log_add( $line ) {
		$log = (array) get_option( 'gasf_imgc_log', array() );
		array_unshift( $log, '[' . wp_date( 'Y-m-d H:i:s' ) . '] ' . $line );
		update_option( 'gasf_imgc_log', array_slice( $log, 0, 200 ), false );
		if ( function_exists( 'gasf_mec_log' ) ) { gasf_mec_log( 'IMGC ' . $line ); }
	}

	/* ============================ naming ============================ */

	/**
	 * original.png -> original-compressed.webp, transliterated/sanitized, with
	 * the END of the base trimmed if the whole name would exceed the cap.
	 * wp_unique_filename() guards collisions.
	 */
	function gasf_imgc_new_name( $old_basename, $dir ) {
		$base = pathinfo( $old_basename, PATHINFO_FILENAME );
		$base = sanitize_file_name( remove_accents( (string) $base ) );
		$base = trim( $base, '-_.' );
		if ( '' === $base ) { $base = 'image'; }
		$suffix = '-compressed.webp';
		$keep   = GASF_IMGC_MAX_NAME - strlen( $suffix );
		if ( strlen( $base ) > $keep ) {
			$base = rtrim( substr( $base, 0, $keep ), '-_.' );
		}
		return wp_unique_filename( $dir, $base . $suffix );
	}

	/* ============================ encoding ============================ */

	/** JPEG EXIF orientation -> degrees for WP_Image_Editor::rotate() (counter-clockwise). */
	function gasf_imgc_exif_rotation( $path ) {
		if ( ! function_exists( 'exif_read_data' ) ) { return 0; }
		$exif = @exif_read_data( $path );
		$o    = (int) ( $exif['Orientation'] ?? 1 );
		if ( 3 === $o ) { return 180; }
		if ( 6 === $o ) { return 270; }
		if ( 8 === $o ) { return 90; }
		return 0;
	}

	/**
	 * Encode $src as WebP at $dest. Returns true or a short reason string.
	 * Path A: WP_Image_Editor (Imagick or GD, canonical + memory-managed).
	 * Path B: content-sniffing fallback — imagecreatefromstring / Imagick blob
	 * decode by CONTENT, so a ".png" that's really a JPEG, or a file with a
	 * cosmetically odd header, still converts instead of failing.
	 */
	function gasf_imgc_make_webp( $src, $dest, $quality, $max_w, $is_jpeg ) {
		wp_raise_memory_limit( 'image' );
		$rot = $is_jpeg ? gasf_imgc_exif_rotation( $src ) : 0;

		// Giant-image routing: estimate decode memory BEFORE loading. Web
		// requests on shared hosting get far less memory than CLI; a 8000px
		// PNG needs ~5.5 bytes/pixel in GD and an OOM there is a FATAL (kills
		// the whole request, uncatchable). If it won't fit, go straight to
		// Imagick with tight resource limits — Imagick spills its pixel cache
		// to disk instead of dying, so no image is ever "too big".
		$dims = @getimagesize( $src );
		if ( is_array( $dims ) && ! empty( $dims[0] ) && ! empty( $dims[1] ) ) {
			$est   = (int) ( $dims[0] * $dims[1] * 5.5 ) + (int) ( @filesize( $src ) * 2 );
			$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
			$avail = ( $limit > 0 ) ? $limit - memory_get_usage( true ) - 32 * MB_IN_BYTES : PHP_INT_MAX;
			if ( $est > $avail ) {
				if ( ! class_exists( 'Imagick' ) ) {
					return 'too-large for available memory (' . size_format( $est ) . ' needed) and Imagick unavailable';
				}
				try {
					$ik = new Imagick();
					$ik->setResourceLimit( Imagick::RESOURCETYPE_MEMORY, 96 * MB_IN_BYTES );
					$ik->setResourceLimit( Imagick::RESOURCETYPE_MAP, 192 * MB_IN_BYTES );
					$ik->readImage( $src );
					$ik->setIteratorIndex( 0 );
					if ( $rot ) { $ik->rotateImage( '#000', 360 - $rot ); }
					$d = $ik->getImageGeometry();
					if ( max( $d['width'], $d['height'] ) > $max_w ) {
						$ik->thumbnailImage( $max_w, $max_w, true );
					}
					$ik->setImageFormat( 'webp' );
					$ik->setImageCompressionQuality( $quality );
					$ok = $ik->writeImage( $dest );
					$ik->clear();
					return ( $ok && file_exists( $dest ) && filesize( $dest ) > 0 ) ? true : 'encode-failed';
				} catch ( Throwable $e ) {
					@unlink( $dest );
					return 'imagick: ' . $e->getMessage();
				}
			}
		}

		$ed = wp_get_image_editor( $src );
		if ( ! is_wp_error( $ed ) ) {
			if ( $rot ) { $ed->rotate( $rot ); }
			$size = $ed->get_size();
			if ( is_array( $size ) && max( (int) $size['width'], (int) $size['height'] ) > $max_w ) {
				$ed->resize( $max_w, $max_w, false );
			}
			$ed->set_quality( $quality );
			$saved = $ed->save( $dest, 'image/webp' );
			if ( ! is_wp_error( $saved ) && file_exists( $dest ) && filesize( $dest ) > 0 ) {
				return true;
			}
			@unlink( $dest );
		}

		$raw = @file_get_contents( $src );
		if ( false === $raw || '' === $raw ) { return 'unreadable'; }

		$im = @imagecreatefromstring( $raw );
		if ( false === $im && class_exists( 'Imagick' ) ) {
			try {
				$ik = new Imagick();
				$ik->readImageBlob( $raw );
				$ik->setIteratorIndex( 0 );
				$d = $ik->getImageGeometry();
				if ( max( $d['width'], $d['height'] ) > $max_w ) {
					$ik->thumbnailImage( $max_w, $max_w, true );
				}
				$ik->setImageFormat( 'webp' );
				$ik->setImageCompressionQuality( $quality );
				$ok = $ik->writeImage( $dest );
				$ik->clear();
				return ( $ok && file_exists( $dest ) && filesize( $dest ) > 0 ) ? true : 'encode-failed';
			} catch ( Exception $e ) {
				return 'undecodable: ' . $e->getMessage();
			}
		}
		if ( false === $im ) { return 'undecodable'; }

		if ( $rot ) { $r = imagerotate( $im, $rot, 0 ); if ( $r ) { imagedestroy( $im ); $im = $r; } }
		$w = imagesx( $im );
		$h = imagesy( $im );
		if ( max( $w, $h ) > $max_w ) {
			$scale = $max_w / max( $w, $h );
			$im2   = imagescale( $im, (int) round( $w * $scale ), (int) round( $h * $scale ), IMG_BICUBIC );
			if ( $im2 ) { imagedestroy( $im ); $im = $im2; }
		}
		if ( ! imageistruecolor( $im ) ) { imagepalettetotruecolor( $im ); }
		imagealphablending( $im, false );
		imagesavealpha( $im, true );
		$ok = imagewebp( $im, $dest, $quality );
		imagedestroy( $im );
		return ( $ok && file_exists( $dest ) && filesize( $dest ) > 0 ) ? true : 'encode-failed';
	}

	/* ============================ reference rewriting ============================ */

	/** Recursive str_replace across arrays/scalars; refuses rows containing objects. */
	function gasf_imgc_deep_replace( $data, array $pairs, &$ok ) {
		if ( is_string( $data ) ) {
			return str_replace( array_keys( $pairs ), array_values( $pairs ), $data );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) { $data[ $k ] = gasf_imgc_deep_replace( $v, $pairs, $ok ); }
			return $data;
		}
		if ( is_object( $data ) ) { $ok = false; }
		return $data;
	}

	/**
	 * Rewrite every stored reference from the old uploads-relative paths to the
	 * new ones. Tokens are uploads-RELATIVE (e.g. "2024/12/pic.png") so they
	 * match inside absolute URLs, protocol-relative URLs, and Smart Slider's
	 * "$upload$/..." placeholders alike. Each token is also replaced in its
	 * JSON-escaped form ("2024\/12\/pic.png") for JSON-in-text columns.
	 * Returns total rows updated.
	 */
	function gasf_imgc_replace_refs( array $map ) {
		global $wpdb;
		$pairs = array();
		foreach ( $map as $old => $new ) {
			if ( '' === $old || $old === $new ) { continue; }
			$pairs[ $old ] = $new;
			$pairs[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
		}
		if ( ! $pairs ) { return 0; }
		$rows = 0;
		$nextend_rows = 0;

		foreach ( $pairs as $old => $new ) {
			$like = '%' . $wpdb->esc_like( $old ) . '%';

			// 1) post_content (pages, posts, revisions — plain text/HTML/JSON blocks).
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s", $like ) );
			foreach ( $ids as $pid ) {
				$c = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $pid ) );
				$n = str_replace( $old, $new, (string) $c );
				if ( $n !== $c ) {
					$wpdb->update( $wpdb->posts, array( 'post_content' => $n ), array( 'ID' => (int) $pid ) );
					clean_post_cache( (int) $pid );
					$rows++;
				}
			}

			// 2) postmeta — serialized-aware (SiteOrigin panels_data etc.).
			$metas = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s", $like ) );
			foreach ( $metas as $m ) {
				$v = (string) $m->meta_value;
				if ( is_serialized( $v ) ) {
					$data = @unserialize( $v, array( 'allowed_classes' => false ) );
					if ( false === $data && 'b:0;' !== $v ) { continue; } // undecodable — leave alone
					$ok   = true;
					$data = gasf_imgc_deep_replace( $data, array( $old => $new ), $ok );
					if ( ! $ok ) { continue; } // contains objects — hands off
					$n = serialize( $data ); // phpcs:ignore -- rewriting existing serialized data
				} else {
					$n = str_replace( $old, $new, $v );
				}
				if ( $n !== $v ) {
					$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $n ), array( 'meta_id' => (int) $m->meta_id ) );
					$rows++;
				}
			}

			// 3) options (widgets etc.) — serialized-aware; transients excluded.
			$opts = $wpdb->get_col( $wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s",
				$like, $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%'
			) );
			foreach ( $opts as $name ) {
				$v  = get_option( $name );
				$ok = true;
				$n  = gasf_imgc_deep_replace( $v, array( $old => $new ), $ok );
				if ( $ok && $n !== $v ) { update_option( $name, $n ); $rows++; }
			}

			// 4) Smart Slider (Nextend) keeps slide params in its own tables.
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'nextend2' ) . '%' ) );
			foreach ( $tables as $table ) {
				$cols = $wpdb->get_results( "SHOW COLUMNS FROM `$table`" ); // phpcs:ignore
				foreach ( $cols as $col ) {
					if ( ! preg_match( '/text|char/i', (string) $col->Type ) ) { continue; }
					$field = $col->Field;
					$done  = $wpdb->query( $wpdb->prepare(
						"UPDATE `$table` SET `$field` = REPLACE(`$field`, %s, %s) WHERE `$field` LIKE %s", // phpcs:ignore
						$old, $new, $like
					) );
					if ( $done ) { $rows += (int) $done; $nextend_rows += (int) $done; }
				}
			}
		}

		// Smart Slider caches generated copies of source images on disk AND the
		// fully-rendered slider HTML in its section_storage table (application =
		// 'cache' rows — what its own "Clear cache" button deletes). Purge both,
		// or the served markup keeps pointing at the old files, which Smart
		// Slider then happily regenerates from the kept originals. ONLY when a
		// nextend row actually referenced this image — unconditional purging
		// meant a batch of 5 forced 5 full slider-cache rebuilds every 4h even
		// when no slider used any of the images.
		if ( $nextend_rows ) {
			$up   = wp_upload_dir();
			$slcache = trailingslashit( $up['basedir'] ) . 'slider/cache';
			if ( is_dir( $slcache ) ) {
				foreach ( glob( $slcache . '/*' ) ?: array() as $entry ) {
					if ( is_dir( $entry ) ) {
						foreach ( glob( $entry . '/*' ) ?: array() as $f ) { @unlink( $f ); }
						@rmdir( $entry );
					} else {
						@unlink( $entry );
					}
				}
			}
			$section = $wpdb->prefix . 'nextend2_section_storage';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $section ) ) === $section ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM `$section` WHERE application = %s", 'cache' ) ); // phpcs:ignore
			}
		}

		return $rows;
	}

	/* ============================ per-attachment ============================ */

	/**
	 * Compress one attachment. Returns array{status, detail} — 'compressed',
	 * or a benign terminal status ('small','missing-file','kept-original',
	 * 'skipped'). Never throws.
	 */
	function gasf_imgc_process( $att_id ) {
		$s    = gasf_imgc_settings();
		$file = get_attached_file( $att_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return array( 'status' => 'missing-file', 'detail' => (string) $file );
		}
		$size = (int) filesize( $file );
		if ( $size <= $s['threshold_kb'] * 1024 ) {
			return array( 'status' => 'small', 'detail' => size_format( $size ) );
		}

		$mime    = get_post_mime_type( $att_id );
		$is_jpeg = ( false !== strpos( (string) $mime, 'jpeg' ) );
		$dir     = dirname( $file );
		$name    = gasf_imgc_new_name( basename( $file ), $dir );
		$dest    = trailingslashit( $dir ) . $name;

		$res = gasf_imgc_make_webp( $file, $dest, (int) $s['quality'], (int) $s['max_w'], $is_jpeg );
		if ( true !== $res ) {
			@unlink( $dest );
			return array( 'status' => 'skipped', 'detail' => $res );
		}
		$new_size = (int) filesize( $dest );
		if ( $new_size >= $size ) {
			@unlink( $dest );
			return array( 'status' => 'kept-original', 'detail' => 'webp not smaller (' . size_format( $new_size ) . ' vs ' . size_format( $size ) . ')' );
		}

		// Capture the OLD reference tokens before anything changes.
		$up      = wp_upload_dir();
		$old_rel = (string) get_post_meta( $att_id, '_wp_attached_file', true ); // e.g. 2024/12/pic.png
		$old_meta = wp_get_attachment_metadata( $att_id );
		$old_dir  = ( false !== strpos( $old_rel, '/' ) ) ? trailingslashit( dirname( $old_rel ) ) : '';
		$old_sizes = array();
		if ( is_array( $old_meta ) && ! empty( $old_meta['sizes'] ) ) {
			foreach ( $old_meta['sizes'] as $sz ) {
				if ( ! empty( $sz['file'] ) ) {
					$old_sizes[ $old_dir . $sz['file'] ] = array( (int) ( $sz['width'] ?? 0 ), (int) ( $sz['height'] ?? 0 ) );
				}
			}
		}

		// Point the attachment at the WebP and rebuild its thumbnail set.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		update_attached_file( $att_id, $dest );
		wp_update_post( array( 'ID' => $att_id, 'post_mime_type' => 'image/webp' ) );
		$new_meta = wp_generate_attachment_metadata( $att_id, $dest );
		if ( is_array( $new_meta ) && $new_meta ) { wp_update_attachment_metadata( $att_id, $new_meta ); }

		$new_rel = (string) get_post_meta( $att_id, '_wp_attached_file', true );
		$new_dir = ( false !== strpos( $new_rel, '/' ) ) ? trailingslashit( dirname( $new_rel ) ) : '';

		// Old URL -> new URL map: full file, plus each old size mapped to the
		// same-dimensions new size when one exists (else the new full file).
		$map = array( $old_rel => $new_rel );
		$new_sizes = array();
		if ( is_array( $new_meta ) && ! empty( $new_meta['sizes'] ) ) {
			foreach ( $new_meta['sizes'] as $sz ) {
				if ( ! empty( $sz['file'] ) ) {
					$new_sizes[ ( (int) ( $sz['width'] ?? 0 ) ) . 'x' . ( (int) ( $sz['height'] ?? 0 ) ) ] = $new_dir . $sz['file'];
				}
			}
		}
		foreach ( $old_sizes as $old_size_rel => $dims ) {
			$key = $dims[0] . 'x' . $dims[1];
			$map[ $old_size_rel ] = $new_sizes[ $key ] ?? $new_rel;
		}
		$rows = gasf_imgc_replace_refs( $map );

		// Optionally remove the originals (default: keep for rollback + old URLs).
		if ( ! empty( $s['delete_orig'] ) ) {
			@unlink( $file );
			foreach ( array_keys( $old_sizes ) as $old_size_rel ) {
				@unlink( trailingslashit( $up['basedir'] ) . $old_size_rel );
			}
		}

		return array(
			'status'   => 'compressed',
			'detail'   => sprintf( '%s -> %s (%s -> %s, %d refs)', basename( $old_rel ), basename( $new_rel ), size_format( $size ), size_format( $new_size ), $rows ),
			'saved'    => $size - $new_size,
			'orig'     => $size,
			'new'      => $new_size,
			'new_name' => basename( $new_rel ),
			'refs'     => $rows,
		);
	}

	/* ============================ batch runner ============================ */

	/** All attachments not yet visited, stat'ed, biggest first. Marks small ones done as it goes. */
	function gasf_imgc_candidates( $limit = 0 ) {
		$ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'post_mime_type' => array( 'image/jpeg', 'image/png' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => GASF_IMGC_META, 'compare' => 'NOT EXISTS' ) ),
		) );
		$s    = gasf_imgc_settings();
		$list = array();
		foreach ( $ids as $id ) {
			/*
			 * Never touch a photo still awaiting review.
			 *
			 * post_status 'any' picks up the CRM's private submissions, and
			 * compressing one rewrites the file, the mime and the whole
			 * thumbnail set underneath a workflow holding its own record of
			 * exactly those things — the item's private_path, the metadata the
			 * publish step reads to know which files to move and strip. It also
			 * silently destroys the EXIF the catalogue has not necessarily
			 * finished reading.
			 *
			 * Skipped WITHOUT marking done, so it becomes a candidate again the
			 * moment it is approved and genuinely is an ordinary media item.
			 */
			if ( function_exists( 'gasf_crm_photo_is_private' ) && gasf_crm_photo_is_private( $id ) ) { continue; }
			if ( get_post_meta( $id, '_gasf_photo_source', true ) && ! get_post_meta( $id, '_gasf_photo_confirmed', true ) ) { continue; }

			$f = get_attached_file( $id );
			if ( ! $f || ! file_exists( $f ) ) {
				update_post_meta( $id, GASF_IMGC_META, array( 'status' => 'missing-file', 'ts' => time() ) );
				continue;
			}
			$size = (int) filesize( $f );
			if ( $size <= $s['threshold_kb'] * 1024 ) {
				update_post_meta( $id, GASF_IMGC_META, array( 'status' => 'small', 'ts' => time() ) );
				continue;
			}
			$list[ $id ] = $size;
		}
		arsort( $list, SORT_NUMERIC );
		return $limit > 0 ? array_slice( $list, 0, $limit, true ) : $list;
	}

	/**
	 * Process up to settings[batch] attachments, biggest first. Built to
	 * survive shared-hosting WEB limits (CLI is roomier): each image gets a
	 * fresh set_time_limit, exceptions are caught per image, and the loop
	 * pauses gracefully when the request's wall-clock budget runs low —
	 * the next click (or cron tick) simply resumes where it left off.
	 */
	/**
	 * Take the batch lock, or fail.
	 *
	 * add_option, not get-then-set. The old pair was check-then-act with a
	 * database round trip in the middle: two runs starting together — a cron
	 * tick and somebody clicking the button — both saw no lock and both
	 * proceeded, and two processes rewriting the same image files is how an
	 * attachment ends up pointing at a half-written WebP. add_option is atomic
	 * on the UNIQUE option_name index, so exactly one caller can create the row.
	 *
	 * Same mechanism as the photo intake's lock, deliberately: two locks in one
	 * codebase that behave differently is one of them being wrong.
	 *
	 * @return string token, or '' if somebody else holds it
	 */
	function gasf_imgc_lock() {
		$token = wp_generate_password( 12, false );

		if ( add_option( 'gasf_imgc_lock', array( 'token' => $token, 'at' => time() ), '', false ) ) {
			return $token;
		}

		// Held. Break it only if it is far older than any live run could be —
		// the heartbeat below refreshes 'at' every image, so a working batch
		// never looks stale however long it legitimately takes.
		$held = (array) get_option( 'gasf_imgc_lock' );
		if ( ! empty( $held['at'] ) && ( time() - (int) $held['at'] ) < 10 * MINUTE_IN_SECONDS ) { return ''; }

		gasf_imgc_log_add( 'Breaking a stale lock — the run holding it has not touched an image in over ten minutes' );
		delete_option( 'gasf_imgc_lock' );
		return add_option( 'gasf_imgc_lock', array( 'token' => $token, 'at' => time() ), '', false ) ? $token : '';
	}

	/** Keep the lock alive while long work is genuinely still running. */
	function gasf_imgc_lock_touch( $token ) {
		$held = (array) get_option( 'gasf_imgc_lock' );
		if ( ! empty( $held['token'] ) && $held['token'] === $token ) {
			update_option( 'gasf_imgc_lock', array( 'token' => $token, 'at' => time() ), false );
		}
	}

	/** Release, but only our own — a lock we broke as stale may since be somebody's. */
	function gasf_imgc_unlock( $token ) {
		$held = (array) get_option( 'gasf_imgc_lock' );
		if ( ! empty( $held['token'] ) && $held['token'] === $token ) { delete_option( 'gasf_imgc_lock' ); }
	}

	function gasf_imgc_run_batch() {
		$lock = gasf_imgc_lock();
		if ( ! $lock ) {
			gasf_imgc_log_add( 'Run skipped — another run holds the lock' );
			return array( 'compressed' => 0, 'other' => 0, 'saved' => 0, 'lines' => array( 'already running' ) );
		}

		$s      = gasf_imgc_settings();
		$stats  = array( 'compressed' => 0, 'other' => 0, 'saved' => 0, 'lines' => array() );
		$queue  = gasf_imgc_candidates( (int) $s['batch'] );
		$t0     = microtime( true );
		$budget = ( defined( 'WP_CLI' ) && WP_CLI ) ? 3600 : 45; // seconds of wall clock per web request
		gasf_imgc_log_add( $queue
			? sprintf( 'Batch started — %d image(s) this run', count( $queue ) )
			: 'Batch started — nothing to do (backlog empty)' );

		foreach ( $queue as $id => $size ) {
			if ( ( microtime( true ) - $t0 ) > $budget ) {
				gasf_imgc_log_add( sprintf( 'Batch paused after %ds — request time budget reached; remaining images continue on the next run/click', (int) ( microtime( true ) - $t0 ) ) );
				break;
			}
			if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); } // fresh per-image allowance

			// Heartbeat. A CLI batch runs to a 3600s budget while the lock was
			// only good for 600, so it expired mid-run and a second process
			// could start on the same queue. Refreshed per image, the lock
			// lasts exactly as long as work is actually happening.
			gasf_imgc_lock_touch( $lock );

			$name = basename( (string) get_attached_file( $id ) );
			gasf_imgc_log_add( sprintf( '%s — processing (%s)', $name, size_format( $size ) ) );

			try {
				$r = gasf_imgc_process( $id );
			} catch ( Throwable $e ) {
				$r = array( 'status' => 'skipped', 'detail' => 'error: ' . $e->getMessage() );
			}
			update_post_meta( $id, GASF_IMGC_META, array( 'status' => $r['status'], 'detail' => $r['detail'], 'ts' => time() ) );

			if ( 'compressed' === $r['status'] ) {
				gasf_imgc_log_add( sprintf(
					'%s — completed. Original size: %s · New size: %s (%d%% smaller, %d reference(s) updated) → %s',
					$name,
					size_format( (int) $r['orig'] ),
					size_format( (int) $r['new'] ),
					$r['orig'] > 0 ? round( 100 * ( $r['orig'] - $r['new'] ) / $r['orig'] ) : 0,
					(int) $r['refs'],
					$r['new_name']
				) );
				$stats['compressed']++;
				$stats['saved'] += (int) ( $r['saved'] ?? 0 );
			} else {
				gasf_imgc_log_add( sprintf( '%s — %s: %s', $name, $r['status'], $r['detail'] ) );
				$stats['other']++;
			}
			$stats['lines'][] = sprintf( '#%d %s: %s', $id, $r['status'], $r['detail'] );
		}

		gasf_imgc_log_add( sprintf( 'Batch finished — %d compressed, %d other, saved %s', $stats['compressed'], $stats['other'], size_format( $stats['saved'] ) ) );
		if ( $stats['compressed'] > 0 ) {
			wp_cache_flush();
		}
		update_option( 'gasf_imgc_last', array( 'ts' => time() ) + $stats, false );
		gasf_imgc_unlock( $lock );
		return $stats;
	}

	/* ============================ cron (every 4 hours, opt-in) ============================ */

	add_filter( 'cron_schedules', function ( $sch ) {
		if ( ! isset( $sch['gasf_4h'] ) ) {
			$sch['gasf_4h'] = array( 'interval' => 4 * HOUR_IN_SECONDS, 'display' => 'Every 4 hours (GASF)' );
		}
		return $sch;
	} );

	add_action( 'init', function () {
		$want      = ! empty( gasf_imgc_settings()['cron'] );
		$scheduled = (bool) wp_next_scheduled( 'gasf_imgc_cron' );
		if ( $want && ! $scheduled ) {
			wp_schedule_event( time() + 120, 'gasf_4h', 'gasf_imgc_cron' );
		} elseif ( ! $want && $scheduled ) {
			wp_clear_scheduled_hook( 'gasf_imgc_cron' );
		}
	} );
	add_action( 'gasf_imgc_cron', 'gasf_imgc_run_batch' );

	/* ============================ admin tab ============================ */

	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) {
			gasf_utilities_add_tab( 'images', 'Images', 'gasf_imgc_admin_page', 63 );
		}
	} );

	function gasf_imgc_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		$scan = null;
		if ( isset( $_POST['gasf_imgc_action'] ) && check_admin_referer( 'gasf_imgc' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_imgc_action'] ) );
			if ( 'save' === $act ) {
				$prev_thr = (int) gasf_imgc_settings()['threshold_kb'];
				$new_thr  = max( 50, (int) ( $_POST['threshold_kb'] ?? 500 ) );
				update_option( 'gasf_imgc_settings', array(
					'cron'         => ! empty( $_POST['cron'] ) ? 1 : 0,
					'threshold_kb' => $new_thr,
					'quality'      => max( 30, min( 95, (int) ( $_POST['quality'] ?? 78 ) ) ),
					'max_w'        => max( 800, min( 6000, (int) ( $_POST['max_w'] ?? 2560 ) ) ),
					'batch'        => max( 1, min( 25, (int) ( $_POST['batch'] ?? 5 ) ) ),
					'delete_orig'  => ! empty( $_POST['delete_orig'] ) ? 1 : 0,
				), false );
				echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
				// Candidacy is NOT-EXISTS on _gasf_imgc, and sub-threshold files
				// get stamped 'small' — so lowering the threshold would never
				// revisit them. Clear the 'small' marks so they're re-evaluated
				// against the new bar. ('compressed'/'skipped' marks stay.)
				if ( $new_thr < $prev_thr ) {
					global $wpdb;
					// The mark is a serialized array — array('status'=>'small','ts'=>...)
					// — so the old equality against the bare string 'small' matched
					// nothing, ever: lowering the threshold never requeued a single
					// file, and the success notice below simply never printed. Match
					// the serialized fragment instead; its shape is fixed because this
					// module is the only writer of the key.
					$n = (int) $wpdb->query( $wpdb->prepare(
						"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
						GASF_IMGC_META,
						'%' . $wpdb->esc_like( 's:6:"status";s:5:"small"' ) . '%'
					) );
					if ( $n ) {
						echo '<div class="notice notice-info is-dismissible"><p>Threshold lowered — ' . (int) $n . ' previously-skipped "small" image(s) are eligible again.</p></div>';
					}
				}
			} elseif ( 'scan' === $act ) {
				$scan = gasf_imgc_candidates();
				echo '<div class="notice notice-info is-dismissible"><p>' . (int) count( $scan ) . ' image(s) above the size threshold (largest first below). Nothing was changed.</p></div>';
			} elseif ( 'run' === $act ) {
				$r = gasf_imgc_run_batch();
				echo '<div class="notice notice-' . ( $r['compressed'] ? 'success' : 'info' ) . ' is-dismissible"><p>Compressed ' . (int) $r['compressed'] . ' image(s), ' . (int) $r['other'] . ' other outcome(s), saved ' . esc_html( size_format( (int) $r['saved'] ) ) . '.' . ( $r['compressed'] ? ' Flush the page cache to see it live.' : '' ) . ' Detail in the activity log below.</p></div>';
			} elseif ( 'clearlog' === $act ) {
				delete_option( 'gasf_imgc_log' );
				echo '<div class="notice notice-success is-dismissible"><p>Activity log cleared.</p></div>';
			}
		}

		$s    = gasf_imgc_settings();
		$last = (array) get_option( 'gasf_imgc_last', array() );
		global $wpdb;
		$done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", GASF_IMGC_META ) );
		?>
		<h2>Image Compressor</h2>
		<?php
		if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
			gasf_utilities_doc_panel( array(
				'what'   => 'Converts oversized JPEG/PNG media-library images to WebP <strong>on this server</strong> (GD/Imagick) — no external service, no quota, no file-size ceiling. The attachment, its thumbnails, and every stored reference (page content, page-builder data, widgets, Smart Slider) are switched to the compressed file, renamed <code>name-compressed.webp</code>. Originals stay on disk by default so old URLs keep working and everything is reversible.',
				'needs'  => array( 'Nothing external — works out of the box. Compresses only files above the size threshold.' ),
				'fields' => array(
					'Scan'                  => 'Lists every image above the threshold, largest first. Read-only — changes nothing.',
					'Compress next batch'   => 'Processes the biggest N offenders now (N = batch size). Safe to click repeatedly to work through the backlog.',
					'Size threshold'        => 'Files at or under this size are left alone (and marked done so they are not re-checked).',
					'WebP quality'          => '30–95. 78 is visually clean for photos; lower = smaller files.',
					'Max dimension'         => 'Longest side is capped to this many pixels — phone photos and social exports are often needlessly huge.',
					'Batch size'            => 'Images per run (on-demand click or cron tick). Keep modest so runs finish comfortably.',
					'Run every 4 hours'     => 'Background cron: quietly compresses a batch every 4 hours until the backlog is empty, then keeps watching new uploads.',
					'Delete originals'      => 'Off (default) keeps the old files on disk for rollback and for anything that cached the old URL. Turn on only to reclaim disk space.',
				'Activity log'          => 'A running history of everything the compressor does — "filename — processing", then "filename — completed. Original size … New size …" (or why it was skipped). Latest 200 entries, newest first.',
				),
				'notes'  => 'Deliberately failure-averse: huge files are fine; a wrong extension or oddball header is handled by decoding the actual file content; strange characters in names are cleaned in the NEW filename only. Anything truly undecodable — or whose WebP would be <em>larger</em> — is logged, marked done, and left untouched.',
			) );
		}
		?>
		<table class="widefat striped" style="max-width:640px">
			<tr><td>Images processed / marked done</td><td><?php echo (int) $done; ?></td></tr>
			<tr><td>Last run</td><td><?php echo ! empty( $last['ts'] ) ? esc_html( human_time_diff( (int) $last['ts'] ) . ' ago — compressed ' . (int) ( $last['compressed'] ?? 0 ) . ', saved ' . size_format( (int) ( $last['saved'] ?? 0 ) ) ) : '—'; ?></td></tr>
			<tr><td>Background cron</td><td><?php $n = wp_next_scheduled( 'gasf_imgc_cron' ); echo $n ? '<span style="color:#1a7f37">● on</span> — next ' . esc_html( wp_date( 'M j, g:i a', $n ) ) : '<span style="color:#646970">○ off</span>'; ?></td></tr>
		</table>

		<form method="post" style="margin-top:10px">
			<?php wp_nonce_field( 'gasf_imgc' ); ?>
			<button name="gasf_imgc_action" value="scan" class="button">Scan (read-only)</button>
			<button name="gasf_imgc_action" value="run" class="button button-primary">Compress next <?php echo (int) $s['batch']; ?> now</button>
		</form>

		<?php if ( is_array( $scan ) && $scan ) : ?>
			<h3 class="title">Largest candidates</h3>
			<table class="widefat striped" style="max-width:760px">
				<thead><tr><th>File</th><th>Size</th></tr></thead><tbody>
				<?php foreach ( array_slice( $scan, 0, 15, true ) as $id => $sz ) : ?>
					<tr><td><code><?php echo esc_html( basename( (string) get_attached_file( $id ) ) ); ?></code> <span class="description">(#<?php echo (int) $id; ?>)</span></td><td><?php echo esc_html( size_format( $sz ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3 class="title">Settings</h3>
		<form method="post">
			<?php wp_nonce_field( 'gasf_imgc' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Size threshold</th><td><input type="number" name="threshold_kb" min="50" value="<?php echo (int) $s['threshold_kb']; ?>" class="small-text"> KB <span class="description">— smaller files are left alone</span></td></tr>
				<tr><th scope="row">WebP quality</th><td><input type="number" name="quality" min="30" max="95" value="<?php echo (int) $s['quality']; ?>" class="small-text"></td></tr>
				<tr><th scope="row">Max dimension</th><td><input type="number" name="max_w" min="800" max="6000" value="<?php echo (int) $s['max_w']; ?>" class="small-text"> px (longest side)</td></tr>
				<tr><th scope="row">Batch size</th><td><input type="number" name="batch" min="1" max="25" value="<?php echo (int) $s['batch']; ?>" class="small-text"> images per run</td></tr>
				<tr><th scope="row">Run every 4 hours</th><td><label><input type="checkbox" name="cron" value="1" <?php checked( $s['cron'], 1 ); ?>> Background cron batch</label></td></tr>
				<tr><th scope="row">Delete originals</th><td><label><input type="checkbox" name="delete_orig" value="1" <?php checked( $s['delete_orig'], 1 ); ?>> Remove old files after compression</label> <span class="description">(default off — keeps rollback + old URLs working)</span>
					<?php if ( empty( $s['delete_orig'] ) ) : ?><p class="description" style="color:#996800">⚠ With this off, each compression <strong>adds</strong> the WebP + a new thumbnail set while keeping the original + its old thumbnails (~1.5–2× disk per image, permanent). On this host's 20&nbsp;GB quota the "compressor" grows disk until originals are deleted.</p><?php endif; ?></td></tr>
			</table>
			<p><button name="gasf_imgc_action" value="save" class="button button-primary">Save settings</button></p>
		</form>

		<?php $log = (array) get_option( 'gasf_imgc_log', array() ); ?>
		<h3 class="title">Activity log</h3>
		<p class="description">Newest first. Every image gets a <em>processing</em> line before work starts and a <em>completed</em> line after (with original &rarr; new size) — if a run ever stalls, the last <em>processing</em> line names the file it was on. Keeps the latest 200 entries; also mirrored to the server log.</p>
		<?php if ( $log ) : ?>
			<pre style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:340px;overflow:auto;font-size:12px;line-height:1.5"><?php echo esc_html( implode( "\n", array_slice( $log, 0, 100 ) ) ); ?></pre>
			<form method="post" onsubmit="return confirm('Clear the activity log?');">
				<?php wp_nonce_field( 'gasf_imgc' ); ?>
				<p><button name="gasf_imgc_action" value="clearlog" class="button">Clear log</button></p>
			</form>
		<?php else : ?>
			<p><em>No activity yet — run a Scan or Compress batch above.</em></p>
		<?php endif; ?>
		<?php
	}
}
