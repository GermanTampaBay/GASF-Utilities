<?php
/**
 * FB → Blog importer — modules/40-fb-share.php
 *
 * Watches the German-American Society Facebook page for posts tagged with a
 * trigger hashtag (default #gasfweb) and turns each one into a WordPress post,
 * sideloading any attached photos (first photo → featured image, the rest →
 * a gallery) and appending an attribution link back to the Facebook post.
 *
 * No new credentials: it reuses the Page access token from the GASF-Events
 * Facebook feed (option `gasf_events_feeds`) — the same token module 35
 * (fb-token-health) probes daily and auto-heals. Reading the page's own
 * published posts needs only `pages_read_engagement`, which that token has.
 *
 * Design decisions (see admin doc panel):
 *   - Import-once: a post is imported a single time (dedup via post meta
 *     `_gasf_fbshare_id`); later FB edits are not re-synced. To force a
 *     re-import (e.g. after reworking the FB post), trash/delete the WP
 *     copy; to archive it so it never comes back, set it to draft.
 *   - New posts land as DRAFT until the "auto-publish" toggle is flipped.
 *   - Videos can't be downloaded via the API → the post links to FB instead.
 *
 * Gate: gasf_site_enable_fbshare (default ON — harmless with nothing tagged).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_fbshare' ) : true ) {

	if ( ! defined( 'GASF_FBS_GRAPH' ) ) { define( 'GASF_FBS_GRAPH', 'https://graph.facebook.com/v19.0/' ); }

	/* ============================ config ============================ */

	function gasf_fbs_cfg() {
		return wp_parse_args( (array) get_option( 'gasf_fbshare', array() ), array(
			'tag'      => '#gasfweb',  // trigger hashtag (whole word, case-insensitive)
			'status'   => 'draft',     // 'draft' | 'publish'
			'ai_title' => '1',         // Claude Haiku writes the post title (needs site-wide Anthropic key)
			'category' => 0,           // term_id, 0 = site default
			'author'   => 0,           // user id, 0 = first admin found at import time
			'link'     => '1',         // append "Originally posted on Facebook" attribution
			'last'     => array(),     // last-scan status { ts, ok, msg, seen, imported }
			'log'      => array(),     // capped ring of recent imports { fb_id, post_id, ts, title }
		) );
	}

	function gasf_fbs_save( $c ) { update_option( 'gasf_fbshare', $c, false ); }

	/** Page id + Page token, borrowed from the GASF-Events Facebook feed. */
	function gasf_fbs_creds() {
		foreach ( (array) get_option( 'gasf_events_feeds', array() ) as $f ) {
			if ( ( $f['type'] ?? '' ) === 'facebook' && ! empty( $f['access_token'] ) && ! empty( $f['page_id'] ) ) {
				return array( (string) $f['page_id'], (string) $f['access_token'] );
			}
		}
		return array( '', '' );
	}

	/* ============================ Graph fetch ============================ */

	/** GET → decoded array or WP_Error; token never leaks into error strings. */
	function gasf_fbs_get( $url, $args = array() ) {
		if ( $args ) { $url = add_query_arg( array_map( 'rawurlencode', $args ), $url ); }
		if ( 'graph.facebook.com' !== (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			return new WP_Error( 'host', 'unexpected Graph host' );
		}
		$r = wp_remote_get( $url, array( 'timeout' => 25, 'reject_unsafe_urls' => true ) );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = (int) wp_remote_retrieve_response_code( $r );
		$b    = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = (string) ( $b['error']['message'] ?? ( 'HTTP ' . $code ) );
			return new WP_Error( 'fb', preg_replace( '/access_token=[^&\s]+/i', 'access_token=REDACTED', $msg ) );
		}
		return is_array( $b ) ? $b : array();
	}

	/**
	 * Fetch the page's recent published posts (newest first, one page of 25 —
	 * plenty for an hourly scan; the trigger tag is for *new* posts).
	 * Returns array of raw post arrays or WP_Error.
	 */
	function gasf_fbs_fetch_posts() {
		list( $page, $token ) = gasf_fbs_creds();
		if ( '' === $page || '' === $token ) {
			return new WP_Error( 'creds', 'No Facebook feed configured in GASF-Events (Events → Feeds) to borrow the token from.' );
		}
		$b = gasf_fbs_get( GASF_FBS_GRAPH . rawurlencode( $page ) . '/published_posts', array(
			'fields'       => 'id,message,created_time,permalink_url,attachments{media_type,type,media,url,subattachments{media_type,media,target}}',
			'limit'        => '25',
			'access_token' => $token,
		) );
		if ( is_wp_error( $b ) ) { return $b; }
		return (array) ( $b['data'] ?? array() );
	}

	/* ============================ matching ============================ */

	/** Does this message carry the trigger tag (whole word, case-insensitive)? */
	function gasf_fbs_matches( $message, $tag ) {
		$tag = ltrim( trim( (string) $tag ), '#' );
		if ( '' === $tag || '' === trim( (string) $message ) ) { return false; }
		return (bool) preg_match( '/#' . preg_quote( $tag, '/' ) . '(?![\w])/iu', $message );
	}

	/** Strip the trigger tag (and any orphaned trailing whitespace) from the message. */
	function gasf_fbs_strip_tag( $message, $tag ) {
		$tag = ltrim( trim( (string) $tag ), '#' );
		$out = preg_replace( '/\s*#' . preg_quote( $tag, '/' ) . '(?![\w])/iu', '', (string) $message );
		return trim( (string) $out );
	}

	/**
	 * Already imported? Look the FB post id up in post meta.
	 *
	 * The status list deliberately EXCLUDES trash: trashing (or deleting) the
	 * WP copy is the "pull it back in" gesture — the next scan re-imports the
	 * current Facebook version fresh. Setting it to draft ARCHIVES it instead
	 * (found here → never re-imported).
	 */
	function gasf_fbs_existing_post( $fb_id ) {
		$q = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_gasf_fbshare_id',
			'meta_value'     => $fb_id,
			'no_found_rows'  => true,
		) );
		return $q ? (int) $q[0] : 0;
	}

	/* ============================ media ============================ */

	/**
	 * Collect photo URLs from a post's attachments/subattachments.
	 * Videos and shared links are skipped (returned separately as a note).
	 */
	function gasf_fbs_extract_images( $raw ) {
		$images = array(); $has_video = false;
		$atts = (array) ( $raw['attachments']['data'] ?? array() );
		foreach ( $atts as $a ) {
			$type = (string) ( $a['media_type'] ?? $a['type'] ?? '' );
			if ( in_array( $type, array( 'video', 'video_inline' ), true ) ) { $has_video = true; }
			$subs = (array) ( $a['subattachments']['data'] ?? array() );
			if ( $subs ) {
				foreach ( $subs as $s ) {
					if ( ( $s['media_type'] ?? '' ) === 'video' ) { $has_video = true; continue; }
					$src = (string) ( $s['media']['image']['src'] ?? '' );
					if ( $src ) { $images[] = $src; }
				}
			} else {
				$src = (string) ( $a['media']['image']['src'] ?? '' );
				if ( $src && 'photo' === $type || $src && 'album' === $type ) { $images[] = $src; }
			}
		}
		return array( array_values( array_unique( $images ) ), $has_video );
	}

	/**
	 * Sideload one image, SHA1-deduped (same pattern as GASF-Events'
	 * Cover_Sideloader). Returns attachment id or 0.
	 */
	function gasf_fbs_sideload( $url, $post_id ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$ok   = ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ) && (
			str_ends_with( $host, '.fbcdn.net' ) || str_ends_with( $host, '.facebook.com' ) || str_ends_with( $host, '.fbsbx.com' )
		);
		if ( ! $ok || ! wp_http_validate_url( $url ) ) { return 0; }

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) { return 0; }
		$sha = (string) sha1_file( $tmp );

		// Reuse an existing attachment with identical bytes.
		if ( $sha ) {
			$dupe = get_posts( array(
				'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1,
				'fields' => 'ids', 'meta_key' => '_gasf_fbshare_sha1', 'meta_value' => $sha, 'no_found_rows' => true,
			) );
			if ( $dupe ) { @unlink( $tmp ); return (int) $dupe[0]; } // phpcs:ignore
		}

		$file   = array( 'name' => 'gasf-fbshare-' . substr( $sha ?: md5( $url ), 0, 12 ) . '.jpg', 'tmp_name' => $tmp, 'error' => 0, 'size' => (int) @filesize( $tmp ) );
		$att_id = media_handle_sideload( $file, $post_id, null );
		if ( is_wp_error( $att_id ) ) { @unlink( $tmp ); return 0; } // phpcs:ignore
		if ( $sha ) { update_post_meta( $att_id, '_gasf_fbshare_sha1', $sha ); }
		return (int) $att_id;
	}

	/* ============================ AI title ============================ */

	/** Anthropic key, resolved like module 34: site-wide first, aiseo legacy fallback. */
	function gasf_fbs_anthropic_key() {
		$key = (string) get_option( 'gasf_anthropic_key', '' );
		if ( '' === $key ) {
			$aiseo = (array) get_option( 'gasf_aiseo_config', array() );
			$key   = (string) ( $aiseo['key'] ?? '' );
		}
		return $key;
	}

	/**
	 * Ask Claude Haiku for a headline (same key module 34 uses). Returns the
	 * title or '' on any failure, so the caller can always fall back to the
	 * first-line heuristic.
	 */
	function gasf_fbs_ai_title( $message ) {
		$key = gasf_fbs_anthropic_key();
		$message = trim( (string) $message );
		if ( '' === $key || '' === $message ) { return ''; }
		$r = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 30,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'      => 'claude-haiku-4-5-20251001',
				'max_tokens' => 100,
				'messages'   => array( array( 'role' => 'user', 'content' =>
					"Write a blog-post headline for the German-American Society of Tampa Bay's website, summarizing this Facebook post. " .
					"Under 70 characters. Same language as the post (English titles may keep German words like Biergarten or Stammtisch). " .
					"No hashtags, no quotes, no trailing period. Reply with the headline only.\n\n" .
					mb_substr( $message, 0, 4000 )
				) ),
			) ),
		) );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) { return ''; }
		$b = json_decode( wp_remote_retrieve_body( $r ), true );
		$t = trim( (string) ( $b['content'][0]['text'] ?? '' ) );
		$t = trim( $t, "\"'“”‘’ \t\r\n" );
		if ( '' === $t || mb_strlen( $t ) > 110 || false !== strpos( $t, "\n" ) ) { return ''; } // refuse rambling output
		return $t;
	}

	/* ============================ import ============================ */

	/** Turn one matching FB post into a WP post. Returns post id or WP_Error. */
	function gasf_fbs_import_post( $raw, $c ) {
		$fb_id   = (string) ( $raw['id'] ?? '' );
		$message = gasf_fbs_strip_tag( (string) ( $raw['message'] ?? '' ), $c['tag'] );
		$link    = (string) ( $raw['permalink_url'] ?? '' );

		// Title: Claude Haiku headline when enabled + key present; otherwise (or on
		// any AI failure) fall back to the first line of the message.
		$title = ( '1' === $c['ai_title'] ) ? gasf_fbs_ai_title( $message ) : '';
		$body  = $message;
		if ( '' === $title ) {
			$lines = preg_split( '/\r\n|\r|\n/', $message, 2 );
			$title = trim( (string) ( $lines[0] ?? '' ) );
			$body  = trim( (string) ( $lines[1] ?? '' ) );
			if ( mb_strlen( $title ) > 90 ) {                   // long first line → it IS the body
				$body  = $message;
				$title = mb_substr( $title, 0, 80 ) . '…';
			}
			if ( '' === $title ) {
				$title = 'From Facebook — ' . wp_date( 'M j, Y', strtotime( (string) ( $raw['created_time'] ?? 'now' ) ) );
			}
		}

		// Author: setting, else the first administrator.
		$author = (int) $c['author'];
		if ( ! $author ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			$author = $admins ? (int) $admins[0] : 0;
		}

		$post_id = wp_insert_post( array(
			'post_type'     => 'post',
			'post_status'   => in_array( $c['status'], array( 'draft', 'publish' ), true ) ? $c['status'] : 'draft',
			'post_title'    => $title,
			'post_content'  => '', // filled below once media ids exist
			'post_author'   => $author,
			'post_category' => $c['category'] ? array( (int) $c['category'] ) : array(),
			'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( (string) ( $raw['created_time'] ?? 'now' ) ) ) ),
		), true );
		if ( is_wp_error( $post_id ) ) { return $post_id; }
		update_post_meta( $post_id, '_gasf_fbshare_id', $fb_id );
		if ( $link ) { update_post_meta( $post_id, '_gasf_fbshare_permalink', esc_url_raw( $link ) ); }

		// Media: first image → featured, remainder → gallery block.
		list( $images, $has_video ) = gasf_fbs_extract_images( $raw );
		$att_ids = array();
		foreach ( $images as $src ) {
			$id = gasf_fbs_sideload( $src, $post_id );
			if ( $id ) { $att_ids[] = $id; }
		}
		if ( $att_ids ) { set_post_thumbnail( $post_id, $att_ids[0] ); }

		// Body as blocks: paragraphs + optional gallery + optional attribution.
		$content = '';
		if ( '' !== $body ) {
			foreach ( preg_split( '/\n{2,}/', $body ) as $para ) {
				$para = trim( $para );
				if ( '' !== $para ) {
					$content .= "<!-- wp:paragraph --><p>" . nl2br( esc_html( $para ) ) . "</p><!-- /wp:paragraph -->\n";
				}
			}
		}
		// ALL photos go into the content (the hoot-du-premium theme doesn't render
		// the featured image on single posts): one image block, or a gallery block
		// when there are several. The featured image still powers archive cards
		// and OpenGraph previews.
		if ( 1 === count( $att_ids ) ) {
			$content .= '<!-- wp:image {"id":' . (int) $att_ids[0] . ',"sizeSlug":"large","linkDestination":"media"} --><figure class="wp-block-image size-large">' . wp_get_attachment_image( $att_ids[0], 'large' ) . '</figure><!-- /wp:image -->' . "\n";
		} elseif ( count( $att_ids ) > 1 ) {
			$content .= '<!-- wp:gallery {"linkTo":"media"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped">';
			foreach ( $att_ids as $gid ) {
				$content .= '<!-- wp:image {"id":' . (int) $gid . ',"sizeSlug":"large","linkDestination":"media"} --><figure class="wp-block-image size-large">' . wp_get_attachment_image( $gid, 'large' ) . '</figure><!-- /wp:image -->';
			}
			$content .= '</figure><!-- /wp:gallery -->' . "\n";
		}
		if ( $has_video && $link ) {
			$content .= "<!-- wp:paragraph --><p><a href=\"" . esc_url( $link ) . "\">▶ Watch the video on Facebook</a></p><!-- /wp:paragraph -->\n";
		}
		// Footer: just the original FB post date (linked to the FB post when the
		// toggle is on — provenance without any "posted on Facebook" wording).
		$fb_date = wp_date( 'F j, Y', strtotime( (string) ( $raw['created_time'] ?? 'now' ) ) );
		$footer  = ( '1' === $c['link'] && $link )
			? '<a href="' . esc_url( $link ) . '">' . esc_html( $fb_date ) . '</a>'
			: esc_html( $fb_date );
		$content .= '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size"><em>' . $footer . '</em></p><!-- /wp:paragraph -->' . "\n";
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );

		return (int) $post_id;
	}

	/** Full scan cycle: fetch → match → dedupe → import. Persists status + log. */
	function gasf_fbs_scan() {
		$c  = gasf_fbs_cfg();
		$st = array( 'ts' => time(), 'ok' => false, 'msg' => '', 'seen' => 0, 'imported' => 0 );

		// One scan at a time. The hourly cron and the admin "Scan now" button
		// can genuinely overlap, and the dedupe is check-then-insert — two
		// concurrent scans both pass gasf_fbs_existing_post() for the same FB
		// post and both import it. add_option is an INSERT against the
		// option_name UNIQUE key, so exactly one concurrent caller wins; the
		// stale-break is compare-and-delete so two breakers cannot each clear
		// the other's fresh lock. (Same pattern as the Email CRM sync lock.)
		global $wpdb;
		$held = get_option( 'gasf_fbs_scan_lock' );
		if ( $held && ( time() - (int) $held ) < 5 * MINUTE_IN_SECONDS ) {
			$st['msg'] = 'another scan is already running';
			return $st;
		}
		if ( $held ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				'gasf_fbs_scan_lock', (string) $held
			) );
			wp_cache_delete( 'gasf_fbs_scan_lock', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		if ( ! add_option( 'gasf_fbs_scan_lock', (string) time(), '', false ) ) {
			$st['msg'] = 'another scan is already running';
			return $st;
		}

		try {
			$posts = gasf_fbs_fetch_posts();
			if ( is_wp_error( $posts ) ) {
				$st['msg'] = $posts->get_error_message();
				$c['last'] = $st; gasf_fbs_save( $c );
				return $st;
			}

			$st['seen'] = count( $posts );
			foreach ( $posts as $raw ) {
				$fb_id = (string) ( $raw['id'] ?? '' );
				if ( '' === $fb_id || ! gasf_fbs_matches( (string) ( $raw['message'] ?? '' ), $c['tag'] ) ) { continue; }
				if ( gasf_fbs_existing_post( $fb_id ) ) { continue; }
				$pid = gasf_fbs_import_post( $raw, $c );
				if ( ! is_wp_error( $pid ) ) {
					$st['imported']++;
					array_unshift( $c['log'], array( 'fb_id' => $fb_id, 'post_id' => $pid, 'ts' => time(), 'title' => get_the_title( $pid ) ) );
					$c['log'] = array_slice( $c['log'], 0, 20 );
					if ( function_exists( 'gasf_mec_log' ) ) { gasf_mec_log( "fbshare: imported FB {$fb_id} → post {$pid}" ); }
				} elseif ( function_exists( 'gasf_mec_log' ) ) {
					gasf_mec_log( 'fbshare: import failed for FB ' . $fb_id . ': ' . $pid->get_error_message() );
				}
			}

			$st['ok'] = true; $st['msg'] = 'ok';
			$c['last'] = $st; gasf_fbs_save( $c );
			return $st;
		} finally {
			delete_option( 'gasf_fbs_scan_lock' );
		}
	}

	/* ============================ cron ============================ */

	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_fbs_cron' ) ) { wp_schedule_event( time() + 900, 'hourly', 'gasf_fbs_cron' ); }
	} );
	add_action( 'gasf_fbs_cron', 'gasf_fbs_scan' );

	/* ============================ admin ============================ */

	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) { gasf_utilities_add_tab( 'fbshare', 'FB → Blog', 'gasf_fbs_admin', 63 ); }
	} );

	function gasf_fbs_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		if ( isset( $_POST['gasf_fbs_action'] ) && check_admin_referer( 'gasf_fbs' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_fbs_action'] ) );
			$c   = gasf_fbs_cfg();
			if ( 'save' === $act ) {
				$tag = trim( sanitize_text_field( wp_unslash( $_POST['tag'] ?? $c['tag'] ) ) );
				$c['tag']      = '#' . ltrim( $tag, '#' );
				$c['status']   = ( 'publish' === ( $_POST['status'] ?? '' ) ) ? 'publish' : 'draft';
				$c['ai_title'] = empty( $_POST['ai_title'] ) ? '0' : '1';
				$c['category'] = (int) ( $_POST['category'] ?? 0 );
				$c['author']   = (int) ( $_POST['author'] ?? 0 );
				$c['link']     = empty( $_POST['link'] ) ? '0' : '1';
				gasf_fbs_save( $c );
				echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
			} elseif ( 'scan' === $act ) {
				$st = gasf_fbs_scan();
				echo '<div class="notice notice-' . ( $st['ok'] ? 'success' : 'error' ) . ' is-dismissible"><p>Scan: ' .
					esc_html( $st['ok'] ? "checked {$st['seen']} posts, imported {$st['imported']}" : $st['msg'] ) . '</p></div>';
			}
		}

		$c  = gasf_fbs_cfg();
		$st = (array) $c['last'];
		?>
		<h2>Facebook → Blog Importer</h2>
		<?php
		if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
			gasf_utilities_doc_panel( array(
				'what'   => 'Turns tagged Facebook posts into WordPress posts. Add the trigger hashtag (below) anywhere in a post on the German-American Society Facebook page, and within the hour it appears here as a blog post — text, photos (first photo becomes the featured image, the rest a gallery), and a link back to the original. Each FB post is imported exactly once; editing it on Facebook afterwards does not update the blog copy.',
				'needs'  => array(
					'The GASF-Events Facebook feed configured in <strong>Events &rarr; Feeds</strong> — this module borrows its Page token (and the FB Token watchdog keeps that healthy).',
					'Nothing else. No new Meta app, token, or review.',
				),
				'fields' => array(
					'Trigger hashtag' => 'The tag that marks a Facebook post for import. Pick something that would never be typed organically — <code>#share</code> shows up in normal posts ("please #share!") and would import things you didn\'t mean to.',
					'New post status' => '<strong>Draft</strong> = imported posts wait for a human to hit Publish (recommended while trialing). <strong>Publish</strong> = fully automatic.',
					'AI title'        => 'Claude Haiku reads the post and writes a proper headline (uses the site-wide Anthropic key from the Settings tab; costs a fraction of a cent per import). If the key is missing or the call fails, the first line of the Facebook post is used instead.',
					'Category'        => 'Category assigned to every imported post.',
					'Author'          => 'WordPress user shown as the post author.',
					'Attribution link'=> 'Every imported post ends with a small line showing the original Facebook post date; this toggle controls whether that date links back to the Facebook post.',
					'Scan now'        => 'Runs the hourly check immediately.',
				),
				'notes'  => 'Videos cannot be downloaded through the API — a post with video gets a "Watch on Facebook" link instead. <strong>Re-import:</strong> deleting/trashing the WP copy makes the next scan pull the Facebook post back in fresh (handy after you rework a FB post — the re-import gets the current text, photos, and a new AI title). <strong>Archive:</strong> switch the WP copy to Draft instead — drafts are never re-imported. Don\'t restore the trashed copy after a re-import or you\'ll have two.',
			) );
		}
		?>

		<table class="widefat striped" style="max-width:720px">
			<tr><td>Last scan</td><td><?php echo ! empty( $st['ts'] ) ? esc_html( human_time_diff( (int) $st['ts'] ) ) . ' ago — ' . ( ! empty( $st['ok'] ) ? '✓ ' . (int) $st['seen'] . ' posts checked, ' . (int) $st['imported'] . ' imported' : '<span style="color:#b32d2e">✗ ' . esc_html( $st['msg'] ?? '' ) . '</span>' ) : 'never'; ?></td></tr>
			<tr><td>Token source</td><td><?php list( $pg, $tk ) = gasf_fbs_creds(); echo $tk ? 'GASF-Events feed (page ' . esc_html( $pg ) . ')' : '<strong style="color:#b32d2e">no Facebook feed found in Events → Feeds</strong>'; ?></td></tr>
		</table>

		<form method="post" style="margin:1em 0">
			<?php wp_nonce_field( 'gasf_fbs' ); ?>
			<button name="gasf_fbs_action" value="scan" class="button button-primary">Scan now</button>
		</form>

		<h3 class="title">Settings</h3>
		<form method="post">
			<?php wp_nonce_field( 'gasf_fbs' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Trigger hashtag</th><td><input type="text" name="tag" value="<?php echo esc_attr( $c['tag'] ); ?>" class="regular-text code"></td></tr>
				<tr><th scope="row">New post status</th><td>
					<label><input type="radio" name="status" value="draft" <?php checked( 'draft', $c['status'] ); ?>> Draft (review before publishing)</label><br>
					<label><input type="radio" name="status" value="publish" <?php checked( 'publish', $c['status'] ); ?>> Publish immediately</label>
				</td></tr>
				<tr><th scope="row">AI title</th><td><label><input type="checkbox" name="ai_title" value="1" <?php checked( '1', $c['ai_title'] ); ?>> Let Claude Haiku write the headline</label><?php if ( ! gasf_fbs_anthropic_key() ) { echo ' <span style="color:#b32d2e">(no Anthropic key found — falls back to first line)</span>'; } ?></td></tr>
				<tr><th scope="row">Category</th><td><?php wp_dropdown_categories( array( 'name' => 'category', 'selected' => (int) $c['category'], 'show_option_none' => '— site default —', 'option_none_value' => 0, 'hide_empty' => 0 ) ); ?></td></tr>
				<tr><th scope="row">Author</th><td><?php wp_dropdown_users( array( 'name' => 'author', 'selected' => (int) $c['author'], 'show_option_none' => '— first administrator —', 'option_none_value' => 0, 'capability' => 'edit_posts' ) ); ?></td></tr>
				<tr><th scope="row">Date link</th><td><label><input type="checkbox" name="link" value="1" <?php checked( '1', $c['link'] ); ?>> Footer date links back to the original Facebook post</label></td></tr>
			</table>
			<p><button name="gasf_fbs_action" value="save" class="button button-primary">Save</button></p>
		</form>

		<?php if ( $c['log'] ) : ?>
			<h3 class="title">Recent imports</h3>
			<table class="widefat striped" style="max-width:720px">
				<thead><tr><th>When</th><th>Blog post</th><th>FB post id</th></tr></thead>
				<tbody>
				<?php foreach ( $c['log'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( human_time_diff( (int) $row['ts'] ) ); ?> ago</td>
						<td><a href="<?php echo esc_url( get_edit_post_link( (int) $row['post_id'] ) ); ?>"><?php echo esc_html( $row['title'] ?: '(untitled)' ); ?></a></td>
						<td><code><?php echo esc_html( $row['fb_id'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
