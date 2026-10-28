<?php
/**
 * Native Instagram Feed — modules/28-instagram.php
 *
 * A theme-native Instagram feed for a site's own Instagram account, with full
 * styling control. Pulls the account's own media (posts, carousels, reels)
 * from the Instagram Graph API (graph.instagram.com, "Instagram API with
 * Instagram Login"), sideloads images locally for speed, caches the result,
 * and renders grid / masonry / carousel layouts with a built-in lightbox.
 *
 * Token: we own it. A long-lived access token is entered once on the admin tab
 * (gasf_ig_set_token() validates it against /me and stores it); gasf_ig_cron
 * refreshes it (ig_refresh_token) well before the 60-day expiry, so it keeps
 * working with no plugin dependency. No Meta app or App Review needed — this
 * uses only /me/media, which the account's own token already permits.
 * (Hashtag/tagged feeds are intentionally out of scope: they require FB-Page
 * Graph access + Meta App Review.)
 *
 * Shortcode: [gasf_instagram layout="grid|masonry|carousel" count="12"
 *            columns="4" captions="0" gap="10" radius="8"]
 *
 * Gate: gasf_site_enable_instagram (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_instagram' ) ) {

	if ( ! defined( 'GASF_IG_GRAPH' ) ) { define( 'GASF_IG_GRAPH', 'https://graph.instagram.com/v21.0' ); }
	if ( ! defined( 'GASF_IG_DIR' ) )   { define( 'GASF_IG_DIR', 'gasf-ig' ); } // under uploads/

	/* ============================ token ============================ */

	function gasf_ig_token() { return (string) get_option( 'gasf_ig_token', '' ); }
	function gasf_ig_meta()  { return (array) get_option( 'gasf_ig_meta', array() ); }

	/**
	 * Validate a manually-entered long-lived Instagram token against the Graph
	 * API and store it, reading back the account name/type/expiry. Returns true
	 * on success or an error string. This is the one-time connect step; the
	 * hourly cron self-refreshes the token thereafter (no plugin dependency).
	 */
	function gasf_ig_set_token( $tok ) {
		$tok = trim( (string) $tok );
		if ( '' === $tok ) { return 'Paste an access token first.'; }

		$me = gasf_ig_api( '/me', array( 'fields' => 'id,username,account_type,media_count' ), $tok );
		if ( is_wp_error( $me ) ) { return 'Token did not validate: ' . $me->get_error_message(); }

		update_option( 'gasf_ig_token', $tok, false );
		$meta = gasf_ig_meta();
		update_option( 'gasf_ig_meta', array_merge( $meta, array(
			'user_id'     => $me['id'] ?? '',
			'username'    => $me['username'] ?? '',
			'type'        => $me['account_type'] ?? '',
			'media_count' => (int) ( $me['media_count'] ?? 0 ),
			// A long-lived token is ~60 days; the cron refreshes + corrects this.
			'expires'     => time() + 60 * DAY_IN_SECONDS,
			'set_at'      => time(),
		) ), false );
		return true;
	}

	/** Refresh the long-lived token (safe to call anytime; only refreshes when it makes sense). */
	function gasf_ig_refresh_token( $force = false ) {
		$tok  = gasf_ig_token();
		$meta = gasf_ig_meta();
		if ( ! $tok ) { return 'No token to refresh.'; }
		$exp = (int) ( $meta['expires'] ?? 0 );
		if ( ! $force && $exp && $exp - time() > 10 * DAY_IN_SECONDS ) { return 'ok (not near expiry)'; }
		$r = wp_remote_get( GASF_IG_GRAPH . '/refresh_access_token?grant_type=ig_refresh_token&access_token=' . rawurlencode( $tok ), array( 'timeout' => 20 ) );
		if ( is_wp_error( $r ) ) { return $r->get_error_message(); }
		$b = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( empty( $b['access_token'] ) ) { return 'Refresh failed: ' . wp_remote_retrieve_body( $r ); }
		update_option( 'gasf_ig_token', $b['access_token'], false );
		$meta['expires'] = time() + (int) ( $b['expires_in'] ?? ( 60 * DAY_IN_SECONDS ) );
		$meta['refreshed'] = time();
		update_option( 'gasf_ig_meta', $meta, false );
		return true;
	}

	/* ============================ API ============================ */

	function gasf_ig_api( $path, $args, $token = null ) {
		$token = $token ?: gasf_ig_token();
		if ( ! $token ) { return new WP_Error( 'no_token', 'No Instagram token configured.' ); }
		$args['access_token'] = $token;
		$url = GASF_IG_GRAPH . $path . '?' . http_build_query( $args );
		$r = wp_remote_get( $url, array( 'timeout' => 25 ) );
		if ( is_wp_error( $r ) ) { return $r; }
		$b = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( isset( $b['error'] ) ) { return new WP_Error( 'ig_' . ( $b['error']['code'] ?? 0 ), $b['error']['message'] ?? 'Instagram API error' ); }
		return $b;
	}

	/* ==================== image sideloading ==================== */

	function gasf_ig_cache_dir() {
		$u = wp_upload_dir();
		return array( 'dir' => trailingslashit( $u['basedir'] ) . GASF_IG_DIR, 'url' => trailingslashit( $u['baseurl'] ) . GASF_IG_DIR );
	}

	/**
	 * Download an image once; return a local URL (or the remote URL on failure).
	 *
	 * $key should be the stable Instagram media id (plus a child suffix for
	 * carousel frames). IG CDN URLs carry rotating signatures, so keying the
	 * filename on md5(url) — the old behavior, kept only as a fallback when no
	 * key is passed — re-downloaded every image under a new name on every
	 * hourly refresh and grew uploads/gasf-ig by ~2GB/day. Keyed on media id,
	 * an unchanged post maps to the same file forever and is never re-fetched.
	 */
	function gasf_ig_sideload( $remote_url, $key = '' ) {
		if ( ! $remote_url ) { return ''; }
		$c    = gasf_ig_cache_dir();
		$key  = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $key );
		$name = ( '' !== $key ? 'ig-' . $key : md5( $remote_url ) ) . '.jpg';
		$path = $c['dir'] . '/' . $name;
		$url  = $c['url'] . '/' . $name;
		if ( file_exists( $path ) ) { return $url; }
		if ( ! wp_mkdir_p( $c['dir'] ) ) { return $remote_url; }
		$r = wp_remote_get( $remote_url, array( 'timeout' => 25 ) );
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) { return $remote_url; }
		$body = wp_remote_retrieve_body( $r );
		if ( ! $body ) { return $remote_url; }
		file_put_contents( $path, $body ); // phpcs:ignore -- cache dir, not user content
		return file_exists( $path ) ? $url : $remote_url;
	}

	/**
	 * Prune sideloaded images no longer referenced by the current cache — a
	 * post deleted on Instagram, or a leftover from before files were keyed on
	 * the media id (when every refresh re-downloaded under a new md5 name).
	 * A file is kept if its name appears anywhere in the current cache blob,
	 * or if it's newer than the grace window (a file just written but not yet
	 * in the saved cache). Returns the number deleted.
	 */
	function gasf_ig_prune_cache( $grace_days = 2 ) {
		$c = gasf_ig_cache_dir();
		if ( ! is_dir( $c['dir'] ) ) { return 0; }
		$blob   = (string) wp_json_encode( (array) get_option( 'gasf_ig_media_cache', array() ) );
		$cutoff = time() - max( 1, (int) $grace_days ) * DAY_IN_SECONDS;
		$removed = 0;
		foreach ( (array) glob( $c['dir'] . '/*.jpg' ) as $f ) {
			$base = basename( $f );
			if ( '' !== $base && false !== strpos( $blob, $base ) ) { continue; } // still referenced
			if ( (int) @filemtime( $f ) > $cutoff ) { continue; }                  // too new — grace window
			if ( @unlink( $f ) ) { $removed++; }
		}
		return $removed;
	}

	/* ==================== fetch + normalize + cache ==================== */

	function gasf_ig_settings() {
		return wp_parse_args( (array) get_option( 'gasf_ig_settings', array() ), array(
			'count' => 24, 'ttl' => 3600, 'layout' => 'grid', 'columns' => 4, 'captions' => 0, 'gap' => 10, 'radius' => 8,
			'header' => 1, 'max' => 48, 'more' => 'button',
		) );
	}

	/** Normalize one Graph media object into our item shape (sideloads images). */
	function gasf_ig_normalize( $m ) {
		$type       = strtoupper( $m['media_type'] ?? 'IMAGE' );
		$is_reel    = ( ( $m['media_product_type'] ?? '' ) === 'REELS' );
		$poster_src = ( $type === 'VIDEO' ) ? ( $m['thumbnail_url'] ?? $m['media_url'] ?? '' ) : ( $m['media_url'] ?? '' );
		$mid  = (string) ( $m['id'] ?? '' );
		$item = array(
			'id'        => $mid,
			'type'      => $type === 'VIDEO' ? ( $is_reel ? 'reel' : 'video' ) : ( $type === 'CAROUSEL_ALBUM' ? 'album' : 'image' ),
			'permalink' => $m['permalink'] ?? '',
			'caption'   => (string) ( $m['caption'] ?? '' ),
			'time'      => $m['timestamp'] ?? '',
			'poster'    => gasf_ig_sideload( $poster_src, $mid ),
			'video'     => $type === 'VIDEO' ? ( $m['media_url'] ?? '' ) : '',
			'children'  => array(),
		);
		if ( ! empty( $m['children']['data'] ) ) {
			foreach ( $m['children']['data'] as $ch ) {
				$ct = strtoupper( $ch['media_type'] ?? 'IMAGE' );
				// Children carry their own stable ids; fall back to parent-id + index.
				$ckey = (string) ( $ch['id'] ?? '' );
				if ( '' === $ckey && '' !== $mid ) { $ckey = $mid . '_' . count( $item['children'] ); }
				$item['children'][] = array(
					'type'   => $ct === 'VIDEO' ? 'video' : 'image',
					'poster' => gasf_ig_sideload( $ct === 'VIDEO' ? ( $ch['thumbnail_url'] ?? '' ) : ( $ch['media_url'] ?? '' ), $ckey ),
					'video'  => $ct === 'VIDEO' ? ( $ch['media_url'] ?? '' ) : '',
				);
			}
		}
		return $item;
	}

	/** The normalized item pool from cache; paginates the Graph API up to the pool size when stale. */
	function gasf_ig_get_media( $force = false ) {
		$s     = gasf_ig_settings();
		$cache = (array) get_option( 'gasf_ig_media_cache', array() );
		$fresh = isset( $cache['ts'] ) && ( time() - (int) $cache['ts'] ) < (int) $s['ttl'];
		if ( ! $force && $fresh && ! empty( $cache['items'] ) ) { return $cache['items']; }
		// If another request is already fetching, serve whatever we have — even an
		// EMPTY pool. Without dropping the "&& ! empty" guard, a cold cache let
		// every concurrent visitor run the 45s Graph pagination loop at once.
		if ( ! $force && get_transient( 'gasf_ig_fetching' ) ) { return $cache['items'] ?? array(); }
		// Failure backoff: the fetching transient only guards CONCURRENT
		// requests. When IG is down or the token is dead, without this every
		// pageview past TTL re-entered the fetch serially, each blocking up to
		// 25s on the API timeout. The hourly cron (force=true) bypasses it.
		if ( ! $force && get_transient( 'gasf_ig_fetch_fail' ) ) { return $cache['items'] ?? array(); }
		// 300s, not 45s-budget-sized: the budget only bounds the Graph
		// pagination — each page then sideloads new images synchronously
		// (25s timeout apiece), so a cold rebuild can outlive a short lock and
		// let a second visitor start a duplicate full fetch.
		set_transient( 'gasf_ig_fetching', 1, 300 );

		$target = max( 1, min( 300, max( (int) $s['count'], (int) $s['max'] ) ) );
		$fields = 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,children{id,media_type,media_url,thumbnail_url}';
		$items  = array();
		$after  = '';
		$budget = microtime( true ) + 45; // bounded; the hourly cron keeps the pool warm
		do {
			$args = array( 'fields' => $fields, 'limit' => max( 1, min( 50, $target - count( $items ) ) ) );
			if ( $after ) { $args['after'] = $after; }
			$res = gasf_ig_api( '/me/media', $args );
			if ( is_wp_error( $res ) || empty( $res['data'] ) ) { break; }
			foreach ( $res['data'] as $m ) { $items[] = gasf_ig_normalize( $m ); }
			$after = $res['paging']['cursors']['after'] ?? '';
			$next  = $res['paging']['next'] ?? '';
		} while ( $after && ! empty( $next ) && count( $items ) < $target && microtime( true ) < $budget );

		if ( ! $items ) {
			set_transient( 'gasf_ig_fetch_fail', 1, 5 * MINUTE_IN_SECONDS );
			delete_transient( 'gasf_ig_fetching' );
			return $cache['items'] ?? array();
		}
		delete_transient( 'gasf_ig_fetch_fail' );
		update_option( 'gasf_ig_media_cache', array( 'ts' => time(), 'items' => $items ), false );
		delete_transient( 'gasf_ig_fetching' );
		return $items;
	}

	/** One grid tile button (shared by the shortcode and the load-more AJAX). */
	function gasf_ig_tile( $it, $idx, $captions ) {
		$badge = $it['type'] === 'reel' ? '&#9658;' : ( $it['type'] === 'video' ? '&#9658;' : ( $it['type'] === 'album' ? '&#9783;' : '' ) );
		$h  = '<button class="gig-tile" data-idx="' . (int) $idx . '" aria-label="Open post">';
		$h .= '<img loading="lazy" src="' . esc_url( $it['poster'] ) . '" alt="' . esc_attr( wp_trim_words( wp_strip_all_tags( $it['caption'] ), 12 ) ) . '">';
		if ( $badge ) { $h .= '<span class="gig-badge">' . $badge . '</span>'; }
		if ( $captions && $it['caption'] !== '' ) { $h .= '<span class="gig-cap">' . esc_html( wp_trim_words( wp_strip_all_tags( $it['caption'] ), 14 ) ) . '</span>'; }
		$h .= '</button>';
		return $h;
	}

	/** Lightbox data payload for one item. */
	function gasf_ig_item_data( $it ) {
		return array(
			't'  => $it['type'], 'p' => $it['poster'], 'v' => $it['video'],
			'c'  => wp_strip_all_tags( $it['caption'] ), 'l' => $it['permalink'],
			'ch' => array_map( function ( $x ) { return array( 't' => $x['type'], 'p' => $x['poster'], 'v' => $x['video'] ); }, $it['children'] ),
		);
	}

	/* ==================== cron: refresh token + warm cache ==================== */
	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_ig_cron' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'gasf_ig_cron' );
		}
	} );
	add_action( 'gasf_ig_cron', function () {
		if ( gasf_ig_token() ) { gasf_ig_refresh_token( false ); gasf_ig_get_media( true ); gasf_ig_prune_cache(); }
	} );

	/* ============================ shortcode ============================ */
	add_shortcode( 'gasf_instagram', 'gasf_ig_shortcode' );
	function gasf_ig_shortcode( $atts ) {
		$s = gasf_ig_settings();
		$a = shortcode_atts( array(
			'layout'   => $s['layout'],
			'count'    => $s['count'],
			'columns'  => $s['columns'],
			'captions' => $s['captions'],
			'gap'      => $s['gap'],
			'radius'   => $s['radius'],
			'header'   => $s['header'],
			'max'      => $s['max'],
			'more'     => $s['more'],
		), $atts, 'gasf_instagram' );

		$items = gasf_ig_get_media();
		if ( ! $items ) {
			return current_user_can( 'manage_options' )
				? '<p style="color:#b3122b">[gasf_instagram] No Instagram media yet — connect the token in GASF Utilities → Instagram.</p>'
				: '';
		}
		$layout = in_array( $a['layout'], array( 'grid', 'masonry', 'carousel' ), true ) ? $a['layout'] : 'grid';
		$cols   = max( 1, min( 8, (int) $a['columns'] ) );
		$uid    = 'gig' . wp_rand( 1000, 9999 );

		$page = max( 1, (int) $a['count'] );
		$cap  = min( count( $items ), max( $page, (int) $a['max'] ) );
		$more = in_array( $a['more'], array( 'off', 'button', 'infinite' ), true ) ? $a['more'] : 'button';
		if ( $layout === 'carousel' ) { $more = 'off'; } // the carousel is a fixed strip
		$initial = array_slice( $items, 0, $page );

		$style = sprintf(
			'--gig-cols:%d;--gig-gap:%dpx;--gig-radius:%dpx;',
			$cols, max( 0, (int) $a['gap'] ), max( 0, (int) $a['radius'] )
		);

		ob_start();
		gasf_ig_assets();

		// Linked header (no avatar): "Instagram: username" → the profile.
		$meta  = gasf_ig_meta();
		$uname = (string) ( $meta['username'] ?? '' );
		if ( (int) $a['header'] && $uname !== '' ) {
			echo '<a class="gig-head" href="' . esc_url( 'https://www.instagram.com/' . rawurlencode( $uname ) . '/' ) . '" target="_blank" rel="noopener" aria-label="Instagram: ' . esc_attr( $uname ) . '">';
			echo '<svg class="gig-head__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5.5"/><circle cx="12" cy="12" r="4.2"/><circle cx="17.6" cy="6.4" r="1.15" fill="currentColor" stroke="none"/></svg>';
			echo '<span class="gig-head__user">' . esc_html( $uname ) . '</span></a>';
		}

		echo '<div class="gig gig--' . esc_attr( $layout ) . '" id="' . esc_attr( $uid ) . '" style="' . esc_attr( $style ) . '">';
		if ( $layout === 'carousel' ) {
			echo '<button class="gig-arrow gig-prev" aria-label="Previous">&#8249;</button>';
		}
		echo '<div class="gig-track">';
		foreach ( $initial as $i => $it ) {
			echo gasf_ig_tile( $it, $i, (int) $a['captions'] ); // phpcs:ignore -- escaped inside the helper
		}
		echo '</div>';
		if ( $layout === 'carousel' ) {
			echo '<button class="gig-arrow gig-next" aria-label="Next">&#8250;</button>';
		}
		echo '</div>';
		if ( $more !== 'off' && $cap > $page ) {
			echo '<div class="gig-more-wrap"><button class="gig-more" data-uid="' . esc_attr( $uid ) . '" data-offset="' . (int) $page . '" data-page="' . (int) $page . '" data-cap="' . (int) $cap . '" data-captions="' . (int) $a['captions'] . '" data-mode="' . esc_attr( $more ) . '">Load more</button></div>';
		}
		// Lightbox data for the initial page only (light first paint); load-more appends the rest.
		$data = array_map( 'gasf_ig_item_data', $initial );
		echo '<script>window.gasfIg=window.gasfIg||{};window.gasfIg[' . wp_json_encode( $uid ) . ']=' . wp_json_encode( $data ) . ';</script>';
		return ob_get_clean();
	}

	/* ============================ load-more (AJAX) ============================ */
	add_action( 'wp_ajax_gasf_ig_more', 'gasf_ig_ajax_more' );
	add_action( 'wp_ajax_nopriv_gasf_ig_more', 'gasf_ig_ajax_more' );
	function gasf_ig_ajax_more() {
		$offset   = max( 0, (int) ( $_GET['offset'] ?? 0 ) );
		$page     = max( 1, min( 48, (int) ( $_GET['page'] ?? 12 ) ) );
		$cap      = max( 1, min( 300, (int) ( $_GET['cap'] ?? 48 ) ) );
		$captions = ! empty( $_GET['captions'] ) ? 1 : 0;
		// Serve from the cached pool ONLY — this endpoint is anonymous (nopriv), and
		// letting it fall through to gasf_ig_get_media() would let any visitor trigger
		// the 45s Graph API pagination loop on a cold cache (worker-pinning DoS + IG
		// quota burn). The hourly cron and the shortcode render keep the pool warm;
		// load-more never needs items fresher than the page it extends.
		$cache = (array) get_option( 'gasf_ig_media_cache', array() );
		$pool  = ! empty( $cache['items'] ) && is_array( $cache['items'] ) ? $cache['items'] : array();
		$end  = min( $cap, count( $pool ) );
		$html = '';
		$items = array();
		$i = $offset;
		foreach ( array_slice( $pool, $offset, $page ) as $it ) {
			if ( $i >= $end ) { break; }
			$html   .= gasf_ig_tile( $it, $i, $captions );
			$items[] = gasf_ig_item_data( $it );
			$i++;
		}
		wp_send_json( array( 'html' => $html, 'items' => $items, 'nextOffset' => $i, 'done' => ( $i >= $end ) ) );
	}

	/* ============================ assets (once) ============================ */
	function gasf_ig_assets() {
		static $done = false;
		if ( $done ) { return; }
		$done = true;
		?>
<style>
.gig{position:relative;--gig-cols:4;--gig-gap:10px;--gig-radius:8px}
.gig-head{display:inline-flex;align-items:center;gap:8px;margin:0 0 12px;font-size:var(--gig-head-size,18px);line-height:1;text-decoration:none;color:var(--gig-head-color,var(--gasf-gold,#EF9F27))}
.gig-head__icon{width:1.4em;height:1.4em;flex:0 0 auto}
.gig-head__user{font-weight:700;letter-spacing:.2px}
.gig-head:hover{filter:brightness(1.12)}
.gig-head:hover .gig-head__user{text-decoration:underline}
.gig-track{display:grid;grid-template-columns:repeat(var(--gig-cols),1fr);gap:var(--gig-gap)}
.gig--masonry .gig-track{display:block;column-count:var(--gig-cols);column-gap:var(--gig-gap)}
.gig--masonry .gig-tile{width:100%;margin:0 0 var(--gig-gap);break-inside:avoid}
.gig--carousel .gig-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;gap:var(--gig-gap);scrollbar-width:none}
.gig--carousel .gig-track::-webkit-scrollbar{display:none}
.gig--carousel .gig-tile{flex:0 0 calc((100% - (var(--gig-cols) - 1)*var(--gig-gap))/var(--gig-cols));scroll-snap-align:start}
.gig-tile{position:relative;display:block;padding:0;border:0;margin:0;cursor:pointer;background:#eee;border-radius:var(--gig-radius);overflow:hidden;aspect-ratio:1/1}
.gig--masonry .gig-tile{aspect-ratio:auto}
.gig-tile img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease}
.gig--masonry .gig-tile img{height:auto}
.gig-tile:hover img{transform:scale(1.06)}
.gig-badge{position:absolute;top:6px;right:8px;color:#fff;font-size:13px;text-shadow:0 1px 3px rgba(0,0,0,.6);pointer-events:none}
.gig-cap{position:absolute;left:0;right:0;bottom:0;padding:10px 9px 8px;color:#fff;font-size:12px;line-height:1.35;text-align:left;background:linear-gradient(rgba(32,32,32,.6),rgba(32,32,32,.95));text-shadow:0 1px 2px rgba(0,0,0,.55);opacity:0;transition:opacity .25s}
.gig-tile:hover .gig-cap{opacity:1}
.gig-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:2;width:38px;height:38px;border-radius:50%;border:0;background:rgba(255,255,255,.92);box-shadow:0 1px 6px rgba(0,0,0,.25);font-size:22px;line-height:1;cursor:pointer}
.gig-prev{left:-6px}.gig-next{right:-6px}
.gig-lb{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.9);display:none;align-items:center;justify-content:center}
.gig-lb.open{display:flex}
.gig-lb__stage{max-width:min(92vw,900px);max-height:88vh;display:flex;flex-direction:column;gap:10px}
.gig-lb__media{max-width:100%;max-height:70vh;display:flex;align-items:center;justify-content:center}
.gig-lb__media img,.gig-lb__media video{max-width:100%;max-height:70vh;border-radius:6px;display:block}
.gig-lb__cap{color:#eee;font-size:14px;line-height:1.45;max-height:14vh;overflow:auto}
.gig-lb__cap a{color:#f6c026}
.gig-lb__x,.gig-lb__nav{position:absolute;top:50%;transform:translateY(-50%);background:none;border:0;color:#fff;font-size:46px;cursor:pointer;opacity:.8;padding:0 14px}
.gig-lb__x{top:18px;right:14px;transform:none;font-size:34px}
.gig-lb__prev{left:6px}.gig-lb__next{right:6px}
.gig-lb__dots{display:flex;gap:6px;justify-content:center}
.gig-lb__dots i{width:8px;height:8px;border-radius:50%;background:#666}
.gig-lb__dots i.on{background:#fff}
.gig-more-wrap{text-align:center;margin:16px 0 0}
.gig-more{display:inline-block;padding:10px 26px;border:0;border-radius:30px;cursor:pointer;font-weight:700;font-size:14px;color:var(--gig-more-fg,#1a1a2e);background:var(--gig-more-bg,var(--gasf-gold,#EF9F27));transition:filter .2s}
.gig-more:hover{filter:brightness(1.08)}
.gig-more[data-busy]{opacity:.6;cursor:default}
@media(max-width:600px){.gig{--gig-cols:3}.gig--carousel{--gig-cols:2}}
</style>
<script>
window.gasfIgAjax=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
(function(){
if(window.gasfIgInit)return;window.gasfIgInit=1;
function el(t,c){var e=document.createElement(t);if(c)e.className=c;return e;}
var lb,stage,media,cap,dots,state={items:[],i:0,ci:0};
function build(){
  lb=el('div','gig-lb');
  lb.innerHTML='<button class="gig-lb__x" aria-label="Close">&times;</button><button class="gig-lb__nav gig-lb__prev" aria-label="Previous">&#8249;</button><button class="gig-lb__nav gig-lb__next" aria-label="Next">&#8250;</button>';
  stage=el('div','gig-lb__stage');media=el('div','gig-lb__media');cap=el('div','gig-lb__cap');dots=el('div','gig-lb__dots');
  stage.appendChild(media);stage.appendChild(dots);stage.appendChild(cap);lb.appendChild(stage);document.body.appendChild(lb);
  lb.querySelector('.gig-lb__x').onclick=close;
  lb.querySelector('.gig-lb__prev').onclick=function(e){e.stopPropagation();nav(-1);};
  lb.querySelector('.gig-lb__next').onclick=function(e){e.stopPropagation();nav(1);};
  lb.onclick=function(e){if(e.target===lb)close();};
  document.addEventListener('keydown',function(e){if(!lb.classList.contains('open'))return;if(e.key==='Escape')close();if(e.key==='ArrowLeft')nav(-1);if(e.key==='ArrowRight')nav(1);});
}
function frames(it){ if(it.t==='album'&&it.ch&&it.ch.length)return it.ch; return [{t:(it.t==='reel'||it.t==='video')?'video':'image',p:it.p,v:it.v}]; }
function render(){
  var it=state.items[state.i];var fr=frames(it);var f=fr[state.ci]||fr[0];
  media.innerHTML='';
  if(f.t==='video'&&f.v){var v=el('video');v.src=f.v;v.controls=true;v.autoplay=true;v.playsInline=true;v.poster=f.p||'';media.appendChild(v);}
  else{var img=el('img');img.src=f.p;media.appendChild(img);}
  dots.innerHTML='';if(fr.length>1){fr.forEach(function(_,k){var d=el('i',k===state.ci?'on':'');dots.appendChild(d);});}
  var txt=(it.c||'');if(txt.length>320)txt=txt.slice(0,320)+'…';
  cap.innerHTML=(txt?txt.replace(/</g,'&lt;')+'<br>':'')+(it.l?'<a href="'+it.l+'" target="_blank" rel="noopener">View on Instagram ↗</a>':'');
}
function nav(d){var it=state.items[state.i];var fr=frames(it);
  if(fr.length>1){var n=state.ci+d;if(n>=0&&n<fr.length){state.ci=n;return render();}}
  state.i=(state.i+d+state.items.length)%state.items.length;state.ci=0;render();
}
function open(items,i){state.items=items;state.i=i;state.ci=0;if(!lb)build();render();lb.classList.add('open');document.body.style.overflow='hidden';}
function close(){lb.classList.remove('open');media.innerHTML='';document.body.style.overflow='';}
function loadMore(btn){
  if(btn.getAttribute('data-busy'))return;btn.setAttribute('data-busy','1');var label=btn.textContent;btn.textContent='Loading…';
  var uid=btn.getAttribute('data-uid'),off=+btn.getAttribute('data-offset'),page=+btn.getAttribute('data-page'),cap=+btn.getAttribute('data-cap'),caps=btn.getAttribute('data-captions')||'0';
  var u=(window.gasfIgAjax||'/wp-admin/admin-ajax.php')+'?action=gasf_ig_more&offset='+off+'&page='+page+'&cap='+cap+'&captions='+caps;
  fetch(u,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
    var box=document.getElementById(uid);
    if(box&&d.html){box.querySelector('.gig-track').insertAdjacentHTML('beforeend',d.html);}
    if(window.gasfIg&&window.gasfIg[uid]&&d.items&&d.items.length){window.gasfIg[uid]=window.gasfIg[uid].concat(d.items);}
    btn.setAttribute('data-offset',d.nextOffset);btn.removeAttribute('data-busy');btn.textContent=label;
    if(d.done){var w=btn.closest('.gig-more-wrap');if(w&&w.parentNode)w.parentNode.removeChild(w);}
  }).catch(function(){btn.removeAttribute('data-busy');btn.textContent=label;});
}
var gio=('IntersectionObserver'in window)?new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting&&e.target.getAttribute('data-mode')==='infinite'){loadMore(e.target);}});},{rootMargin:'400px'}):null;
function gwire(){if(!gio)return;var bs=document.querySelectorAll('.gig-more[data-mode="infinite"]');for(var i=0;i<bs.length;i++){if(!bs[i]._gio){bs[i]._gio=1;gio.observe(bs[i]);}}}
if(document.readyState!=='loading')gwire();document.addEventListener('DOMContentLoaded',gwire);
document.addEventListener('click',function(e){
  var mb=e.target.closest('.gig-more');if(mb){e.preventDefault();loadMore(mb);return;}
  var t=e.target.closest('.gig-tile');if(t){var box=t.closest('.gig');var items=window.gasfIg&&window.gasfIg[box.id];if(items){e.preventDefault();open(items,+t.getAttribute('data-idx'));}return;}
  var ar=e.target.closest('.gig-arrow');if(ar){var g=ar.closest('.gig');var tr=g.querySelector('.gig-track');tr.scrollBy({left:(ar.classList.contains('gig-next')?1:-1)*tr.clientWidth*0.8,behavior:'smooth'});}
});
})();
</script>
		<?php
	}

	/* ============================ admin tab ============================ */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) {
			gasf_utilities_add_tab( 'instagram', 'Instagram', 'gasf_ig_admin_page', 60 );
		}
	} );

	function gasf_ig_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		if ( isset( $_POST['gasf_ig_action'] ) && check_admin_referer( 'gasf_ig_admin' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_ig_action'] ) );
			if ( $act === 'set_token' ) {
				$r = gasf_ig_set_token( wp_unslash( $_POST['gasf_ig_token_input'] ?? '' ) );
				echo $r === true ? '<div class="notice notice-success is-dismissible"><p>Token validated and saved — connected.</p></div>'
					: '<div class="notice notice-error"><p>' . esc_html( $r ) . '</p></div>';
			} elseif ( $act === 'refresh_token' ) {
				$r = gasf_ig_refresh_token( true );
				echo $r === true ? '<div class="notice notice-success is-dismissible"><p>Token refreshed.</p></div>'
					: '<div class="notice notice-warning"><p>' . esc_html( is_string( $r ) ? $r : 'done' ) . '</p></div>';
			} elseif ( $act === 'refresh_feed' ) {
				gasf_ig_get_media( true );
				echo '<div class="notice notice-success is-dismissible"><p>Feed cache refreshed.</p></div>';
			} elseif ( $act === 'save' ) {
				update_option( 'gasf_ig_settings', array(
					'count'    => max( 1, min( 90, (int) ( $_POST['count'] ?? 24 ) ) ),
					'ttl'      => max( 300, (int) ( $_POST['ttl'] ?? 3600 ) ),
					'layout'   => in_array( $_POST['layout'] ?? 'grid', array( 'grid', 'masonry', 'carousel' ), true ) ? $_POST['layout'] : 'grid',
					'columns'  => max( 1, min( 8, (int) ( $_POST['columns'] ?? 4 ) ) ),
					'captions' => ! empty( $_POST['captions'] ) ? 1 : 0,
					'gap'      => max( 0, (int) ( $_POST['gap'] ?? 10 ) ),
					'radius'   => max( 0, (int) ( $_POST['radius'] ?? 8 ) ),
					'header'   => ! empty( $_POST['header'] ) ? 1 : 0,
					'max'      => max( 1, min( 300, (int) ( $_POST['max'] ?? 48 ) ) ),
					'more'     => in_array( $_POST['more'] ?? 'button', array( 'off', 'button', 'infinite' ), true ) ? $_POST['more'] : 'button',
				), false );
				echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
			}
		}

		$meta  = gasf_ig_meta();
		$s     = gasf_ig_settings();
		$cache = (array) get_option( 'gasf_ig_media_cache', array() );
		$has   = gasf_ig_token() !== '';
		$exp   = (int) ( $meta['expires'] ?? 0 );
		?>
		<h2>Native Instagram Feed</h2>
		<?php
		if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
			gasf_utilities_doc_panel( array(
				'what'   => 'The site\'s own Instagram feed — the <code>[gasf_instagram]</code> shortcode renders <strong>@' . esc_html( $meta['username'] ?? 'your account' ) . '</strong>\'s posts, carousels and reels in a grid/masonry/carousel with a lightbox, fully styled to the theme — no third-party Instagram plugin required. Images are downloaded to this server (fast, no Instagram CDN dependency at view time) and the post list is cached; an hourly cron keeps everything fresh and auto-renews the access token before its 60-day expiry.',
				'needs'  => array(
					'A valid long-lived <strong>Instagram access token</strong> for this site&rsquo;s account — pasted once into <em>Connect</em> below, then self-refreshed hourly. No Instagram-feed plugin needs to be installed.',
					'The <code>[gasf_instagram]</code> shortcode on a page.',
				),
				'fields' => array(
					'Connection status'      => 'Shows the connected account, token expiry, and cache age. Green = healthy; the hourly cron handles renewal, so you should never need the buttons below in normal life.',
					'Connect / Access token' => 'Paste a long-lived Instagram access token to connect (or replace) the account. It&rsquo;s validated against the Instagram API before saving, and the username + expiry are read back automatically. One-time — the cron keeps it alive after that.',
					'Refresh feed now'       => 'Re-pulls the latest posts immediately instead of waiting for the cache TTL — use right after posting something you want on the site now.',
					'Refresh token'          => 'Manually renews the access token (normally automatic). Harmless to click.',
					'Layout'                 => 'Grid (uniform tiles), masonry (Pinterest-style variable heights), or carousel (horizontal scroller).',
					'Posts per page'         => 'How many tiles show initially and how many each "Load more" adds.',
					'Load more / up to N'    => 'Button, infinite scroll, or off — and the total cap visitors can page through. The cap also sets how many posts the cache pulls; 48–96 is the sweet spot.',
					'Columns / Gap / Radius' => 'Grid geometry: column count, spacing between tiles (px), and tile corner rounding (px).',
					'Hover captions'         => 'Shows the post caption over the image on hover (dark translucent backdrop for readability).',
					'Header link'            => 'The Instagram logo + @username header above the feed, linking to the profile, in theme gold.',
					'Cache TTL (sec)'        => 'How long the post list is served from cache before re-pulling from Instagram. 3600 (1 h) is right — visitors never wait on Instagram either way.',
				),
				'notes'  => 'Shortcode attributes override these defaults per page: <code>[gasf_instagram layout="carousel" count="8" columns="3" captions="1"]</code>. Hashtag/tagged feeds aren\'t possible with an own-account token (Meta requires an app-review\'d Facebook app).',
			) );
		}
		?>

		<h3 class="title">Connection</h3>
		<table class="widefat striped" style="max-width:640px">
			<tr><td>Token</td><td><?php echo $has ? '<span style="color:#1a7f37">● connected</span>' : '<span style="color:#b3122b">○ not connected</span>'; ?></td></tr>
			<tr><td>Account</td><td><?php echo esc_html( ( $meta['username'] ?? '—' ) . ( $meta['type'] ? ' (' . $meta['type'] . ')' : '' ) ); ?></td></tr>
			<tr><td>Token expires</td><td><?php echo $exp ? esc_html( wp_date( 'M j, Y', $exp ) ) . ' (auto-refreshed by cron)' : '—'; ?></td></tr>
			<tr><td>Cached posts</td><td><?php echo esc_html( count( $cache['items'] ?? array() ) ); ?><?php echo ! empty( $cache['ts'] ) ? ' · updated ' . esc_html( human_time_diff( (int) $cache['ts'] ) ) . ' ago' : ''; ?></td></tr>
		</table>
		<?php if ( $has ) : ?>
		<form method="post" style="margin-top:10px">
			<?php wp_nonce_field( 'gasf_ig_admin' ); ?>
			<button name="gasf_ig_action" value="refresh_feed" class="button button-primary">Refresh feed now</button>
			<button name="gasf_ig_action" value="refresh_token" class="button">Refresh token</button>
		</form>
		<?php endif; ?>

		<h3 class="title"><?php echo $has ? 'Replace access token' : 'Connect — add your access token'; ?></h3>
		<form method="post" style="margin-top:6px">
			<?php wp_nonce_field( 'gasf_ig_admin' ); ?>
			<p>
				<input type="text" name="gasf_ig_token_input" value="" class="regular-text code" style="width:560px;max-width:100%" placeholder="IGAA… long-lived Instagram access token" autocomplete="off" spellcheck="false">
				<button name="gasf_ig_action" value="set_token" class="button button-primary"><?php echo $has ? 'Replace token' : 'Connect'; ?></button>
			</p>
			<p class="description">
				Paste a <strong>long-lived Instagram access token</strong> for this site&rsquo;s Instagram account. It&rsquo;s validated against the Instagram API before saving, and the account name + expiry are read back automatically; the hourly cron then self-refreshes it, so this is a one-time step. Get one from the Meta / Instagram developer tools (<em>Instagram API with Instagram Login</em> &rarr; generate a long-lived access token), or from an Instagram-feed plugin&rsquo;s settings if the site happens to have one. No plugin needs to stay installed.
			</p>
		</form>

		<h3 class="title">Display defaults</h3>
		<form method="post">
			<?php wp_nonce_field( 'gasf_ig_admin' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Layout</th><td>
					<select name="layout">
						<?php foreach ( array( 'grid' => 'Grid', 'masonry' => 'Masonry', 'carousel' => 'Carousel' ) as $k => $lbl ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['layout'], $k ); ?>><?php echo esc_html( $lbl ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th scope="row">Posts per page</th><td><input type="number" name="count" min="1" max="90" value="<?php echo (int) $s['count']; ?>" class="small-text"> <span class="description">shown initially and per &ldquo;Load more&rdquo;</span></td></tr>
				<tr><th scope="row">Load more</th><td>
					<select name="more">
						<?php foreach ( array( 'off' => 'Off (one page only)', 'button' => 'Load-more button', 'infinite' => 'Infinite scroll' ) as $k => $lbl ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['more'], $k ); ?>><?php echo esc_html( $lbl ); ?></option>
						<?php endforeach; ?>
					</select>
					<label style="margin-left:10px">up to <input type="number" name="max" min="1" max="300" value="<?php echo (int) $s['max']; ?>" class="small-text"> posts total</label>
					<p class="description">How many posts visitors can page through. Higher totals pull more into the cache on refresh; ~48&ndash;96 is a good range.</p>
				</td></tr>
				<tr><th scope="row">Columns</th><td><input type="number" name="columns" min="1" max="8" value="<?php echo (int) $s['columns']; ?>" class="small-text"></td></tr>
				<tr><th scope="row">Gap (px)</th><td><input type="number" name="gap" min="0" max="40" value="<?php echo (int) $s['gap']; ?>" class="small-text"></td></tr>
				<tr><th scope="row">Corner radius (px)</th><td><input type="number" name="radius" min="0" max="40" value="<?php echo (int) $s['radius']; ?>" class="small-text"></td></tr>
				<tr><th scope="row">Hover captions</th><td><label><input type="checkbox" name="captions" value="1" <?php checked( $s['captions'], 1 ); ?>> Show caption on hover</label></td></tr>
				<tr><th scope="row">Header link</th><td><label><input type="checkbox" name="header" value="1" <?php checked( $s['header'], 1 ); ?>> Show the Instagram logo + username above the feed (links to the profile)</label>
					<p class="description">Colored with your theme gold (<code>--gasf-gold</code>). Override with CSS: <code>.gig-head{--gig-head-color:#…}</code>.</p></td></tr>
				<tr><th scope="row">Cache TTL (sec)</th><td><input type="number" name="ttl" min="300" step="300" value="<?php echo (int) $s['ttl']; ?>" class="small-text"> <span class="description">how often to re-pull from Instagram</span></td></tr>
			</table>
			<p><button name="gasf_ig_action" value="save" class="button button-primary">Save defaults</button></p>
		</form>

		<h3 class="title">Use it</h3>
		<p>Drop this shortcode on any page/widget (attributes override the defaults above):</p>
		<p><code>[gasf_instagram layout="grid" count="12" columns="4"]</code></p>
		<p class="description">Attributes override the saved defaults per placement. If the site previously showed its feed via another Instagram plugin, swap in this shortcode and remove that block — nothing else needs to stay installed.</p>
		<?php
	}
}
