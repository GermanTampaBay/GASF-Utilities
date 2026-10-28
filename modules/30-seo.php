<?php
/**
 * Lightweight SEO — modules/30-seo.php
 *
 * A native replacement for Yoast: document title, meta description, canonical,
 * robots, Open Graph + Twitter cards, and WebSite JSON-LD — plus a per-page
 * SEO editor box and a one-click import of Yoast's titles/descriptions into
 * our own meta (`_gasf_seo_*`) so nothing is lost when Yoast is removed.
 *
 * Cutover-safe: every front-end hook DEFERS while Yoast is still active, so
 * this can be deployed alongside Yoast and takes over automatically once Yoast
 * is deactivated/deleted. Organization schema lives in modules/29-schema-jsonld.php.
 *
 * Sitemaps: handled by WordPress core (/wp-sitemap.xml) once Yoast is gone;
 * the old /sitemap_index.xml is 301'd to it so Search Console keeps working.
 *
 * Gate: gasf_site_enable_seo (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_seo' ) ) {

	if ( ! defined( 'GASF_SEO_TYPES' ) ) { define( 'GASF_SEO_TYPES', array( 'post', 'page', 'gasf_event' ) ); }

	function gasf_seo_yoast_active() {
		return defined( 'WPSEO_VERSION' ) || function_exists( 'YoastSEO' ) || class_exists( 'WPSEO_Frontend' );
	}

	function gasf_seo_settings() {
		return wp_parse_args( (array) get_option( 'gasf_seo_settings', array() ), array(
			'sep'          => '–',
			'title_home'   => '%%sitename%% %%sep%% %%sitedesc%%',
			'desc_home'    => get_bloginfo( 'description' ),
			'title_single' => '%%title%% %%sep%% %%sitename%%',
			'title_term'   => '%%term_title%% %%sep%% %%sitename%%',
			'og_image'     => '',
			'twitter'      => '',
		) );
	}

	/* ============================ front-end ============================ */

	add_action( 'wp', function () {
		if ( ! gasf_seo_yoast_active() ) {
			remove_action( 'wp_head', 'rel_canonical' ); // we emit our own
		}
	} );
	add_filter( 'pre_get_document_title', function ( $title ) {
		return gasf_seo_yoast_active() ? $title : gasf_seo_title();
	}, 20 );
	add_action( 'wp_head', function () {
		if ( ! gasf_seo_yoast_active() ) { gasf_seo_head(); }
	}, 1 );
	add_filter( 'wp_robots', function ( $robots ) {
		if ( gasf_seo_yoast_active() ) { return $robots; }
		if ( is_singular() && get_post_meta( get_queried_object_id(), '_gasf_seo_noindex', true ) ) {
			$robots['noindex'] = true;
		}
		$robots['max-image-preview'] = 'large';
		$robots['max-snippet']       = -1;
		$robots['max-video-preview'] = -1;
		return $robots;
	} );

	// robots.txt FILE (distinct from the wp_robots META filter above): block
	// crawling of internal search results — WordPress `?s=` search generates
	// infinite, low-value, duplicate URLs. Well-behaved crawlers (incl. the AI
	// bots now allowed) then skip them, saving crawl budget.
	//
	// Scope note: this robots.txt is served at the DOMAIN ROOT by the MAIN
	// install and governs the WHOLE domain — crawlers only ever read
	// /robots.txt, never /krampus/robots.txt (which 404s). So the wildcard
	// `/*?s=` is deliberate: one rule covers /?s=, /krampus/?s=, /dancers/?s=.
	// (As of 2026-07-22 robots.txt comes straight from WordPress — Cloudflare's
	// "Manage your robots.txt" was disabled, so it no longer overrides this.)
	add_filter( 'robots_txt', function ( $output, $public ) {
		if ( ! $public ) { return $output; } // "discourage indexing" on → leave core's Disallow: /
		$extra = "Disallow: /*?s=\n";
		// Keep the rule inside core's "User-agent: *" group (Disallow lines must
		// sit in a group, before the Sitemap line the core sitemap filter adds).
		if ( false !== strpos( $output, 'admin-ajax.php' ) ) {
			return preg_replace( '/(admin-ajax\.php\n)/', '$1' . $extra, $output, 1 );
		}
		if ( false !== strpos( $output, 'Disallow: /wp-admin/' ) ) {
			return preg_replace( '#(Disallow: /wp-admin/\n)#', '$1' . $extra, $output, 1 );
		}
		return "User-agent: *\n" . $extra . "\n" . $output; // fallback: own group
	}, 10, 2 );

	// Old Yoast sitemap URLs → core sitemap, so crawlers/GSC don't 404.
	// NOTE: strpos/preg_match here are deliberately NOT start-anchored to the
	// literal request path — on a subdirectory install (e.g. /krampus/) the
	// real REQUEST_URI is "/krampus/wp-sitemap.xml", not "/wp-sitemap.xml".
	add_action( 'template_redirect', function () {
		if ( gasf_seo_yoast_active() ) { return; }
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( false !== strpos( $uri, '/wp-sitemap' ) ) { return; } // core sitemaps — never touch
		if ( preg_match( '#/sitemap_index\.xml#', $uri ) || preg_match( '#-sitemap\d*\.xml$#', $uri ) ) {
			wp_redirect( home_url( '/wp-sitemap.xml' ), 301 );
			exit;
		}
	}, 0 );

	// Guard against WordPress core's redirect_canonical() looping /wp-sitemap.xml
	// to itself. Seen in the wild after a conflicting SEO plugin (e.g. SiteSEO)
	// disabled core sitemaps and was later deactivated — core's canonical-redirect
	// logic can get stuck redirecting the sitemap request to its own URL forever
	// (X-Redirect-By: WordPress, Location === the request URL). Runs before
	// redirect_canonical's default priority 10, so it can unhook it in time.
	// Same subdirectory-install note as above: match anywhere in the URI, not
	// just at position 0.
	add_action( 'template_redirect', function () {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( false !== strpos( $uri, '/wp-sitemap' ) ) {
			remove_action( 'template_redirect', 'redirect_canonical', 10 );
		}
	}, 0 );

	/* ---------- title ---------- */
	function gasf_seo_title() {
		$s = gasf_seo_settings();
		if ( is_front_page() ) {
			$fp  = (int) get_option( 'page_on_front' );
			$tpl = ( $fp ? gasf_seo_meta( $fp, 'title' ) : '' ) ?: $s['title_home'];
		} elseif ( is_home() ) {
			$pid = (int) get_option( 'page_for_posts' );
			$tpl = ( $pid ? gasf_seo_meta( $pid, 'title' ) : '' ) ?: $s['title_single'];
		} elseif ( is_singular() ) {
			$tpl = gasf_seo_meta( get_queried_object_id(), 'title' ) ?: $s['title_single'];
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$tpl = $s['title_term'];
		} elseif ( is_author() ) {
			$tpl = '%%name%% %%sep%% %%sitename%%';
		} elseif ( is_post_type_archive() ) {
			$tpl = post_type_archive_title( '', false ) . ' %%sep%% %%sitename%%';
		} elseif ( is_search() ) {
			$tpl = 'Search results %%sep%% %%sitename%%';
		} elseif ( is_404() ) {
			$tpl = 'Page not found %%sep%% %%sitename%%';
		} else {
			$tpl = '%%title%% %%sep%% %%sitename%%';
		}
		return gasf_seo_expand( $tpl );
	}

	/** Expand Yoast-style %%variables%% for the current context. */
	function gasf_seo_expand( $tpl ) {
		$s   = gasf_seo_settings();
		$rep = array(
			'%%sitename%%'   => get_bloginfo( 'name' ),
			'%%sitedesc%%'   => get_bloginfo( 'description' ),
			'%%sep%%'        => $s['sep'],
			'%%title%%'      => is_singular() ? wp_strip_all_tags( get_the_title( get_queried_object_id() ) ) : '',
			'%%term_title%%' => ( is_category() || is_tag() || is_tax() ) ? single_term_title( '', false ) : '',
			'%%name%%'       => is_author() ? (string) get_the_author_meta( 'display_name', get_queried_object_id() ) : '',
			'%%page%%'       => gasf_seo_page_bit(),
			'%%excerpt%%'    => is_singular() ? gasf_seo_excerpt( get_queried_object_id() ) : '',
		);
		$out = strtr( (string) $tpl, $rep );
		$out = preg_replace( '/%%[a-z0-9_-]+%%/i', '', $out );      // drop unknown vars
		$out = preg_replace( '/\s{2,}/', ' ', (string) $out );      // collapse gaps
		$sep = preg_quote( $s['sep'], '/' );
		$out = preg_replace( "/^(\\s|$sep)+|(\\s|$sep)+$/u", '', $out ); // trim dangling sep
		return $out !== '' ? $out : get_bloginfo( 'name' );
	}

	function gasf_seo_page_bit() {
		$paged = (int) max( get_query_var( 'paged' ), get_query_var( 'page' ) );
		return $paged > 1 ? ( '%%sep%% Page ' . $paged ) : '';
	}

	/* ---------- <head> block ---------- */
	function gasf_seo_head() {
		$desc  = gasf_seo_desc();
		$canon = gasf_seo_canonical();
		$title = gasf_seo_title();
		$img   = gasf_seo_image();
		$type  = is_singular() && ! is_front_page() ? 'article' : 'website';
		$out   = "\n";
		if ( $desc !== '' ) { $out .= '<meta name="description" content="' . esc_attr( $desc ) . "\">\n"; }
		if ( $canon ) { $out .= '<link rel="canonical" href="' . esc_url( $canon ) . "\">\n"; }
		// Open Graph
		$out .= '<meta property="og:locale" content="' . esc_attr( str_replace( '-', '_', get_bloginfo( 'language' ) ) ) . "\">\n";
		$out .= '<meta property="og:type" content="' . esc_attr( $type ) . "\">\n";
		$out .= '<meta property="og:title" content="' . esc_attr( $title ) . "\">\n";
		if ( $desc !== '' ) { $out .= '<meta property="og:description" content="' . esc_attr( $desc ) . "\">\n"; }
		if ( $canon ) { $out .= '<meta property="og:url" content="' . esc_url( $canon ) . "\">\n"; }
		$out .= '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . "\">\n";
		if ( $img ) {
			$out .= '<meta property="og:image" content="' . esc_url( $img ) . "\">\n";
		}
		if ( 'article' === $type ) {
			$p = get_post( get_queried_object_id() );
			if ( $p ) {
				$out .= '<meta property="article:published_time" content="' . esc_attr( get_post_time( 'c', true, $p ) ) . "\">\n";
				$out .= '<meta property="article:modified_time" content="' . esc_attr( get_post_modified_time( 'c', true, $p ) ) . "\">\n";
			}
		}
		// Twitter
		$out .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
		$tw = gasf_seo_settings()['twitter'];
		if ( $tw ) { $out .= '<meta name="twitter:site" content="' . esc_attr( $tw ) . "\">\n"; }
		$out .= '<meta name="twitter:title" content="' . esc_attr( $title ) . "\">\n";
		if ( $desc !== '' ) { $out .= '<meta name="twitter:description" content="' . esc_attr( $desc ) . "\">\n"; }
		if ( $img ) { $out .= '<meta name="twitter:image" content="' . esc_url( $img ) . "\">\n"; }
		// WebSite schema (once, on the front page) — Organization is in module 29.
		if ( is_front_page() ) {
			$site = array(
				'@context' => 'https://schema.org',
				'@type'    => 'WebSite',
				'name'     => get_bloginfo( 'name' ),
				'url'      => home_url( '/' ),
				'potentialAction' => array(
					'@type'       => 'SearchAction',
					'target'      => array( '@type' => 'EntryPoint', 'urlTemplate' => home_url( '/?s={search_term_string}' ) ),
					'query-input' => 'required name=search_term_string',
				),
			);
			$out .= '<script type="application/ld+json">' . wp_json_encode( $site, JSON_UNESCAPED_SLASHES ) . "</script>\n";
		}
		// Breadcrumb schema (skip the front page).
		if ( ! is_front_page() ) {
			$bc = gasf_seo_breadcrumbs();
			if ( '' !== $bc ) { $out .= '<script type="application/ld+json">' . $bc . "</script>\n"; }
		}
		echo $out; // phpcs:ignore -- all values escaped above
	}

	function gasf_seo_breadcrumbs() {
		$crumbs = array( array( 'name' => 'Home', 'url' => home_url( '/' ) ) );
		if ( is_singular() ) {
			$id = get_queried_object_id();
			if ( is_page() ) {
				foreach ( array_reverse( get_post_ancestors( $id ) ) as $anc ) {
					$crumbs[] = array( 'name' => wp_strip_all_tags( get_the_title( $anc ) ), 'url' => get_permalink( $anc ) );
				}
			}
			$crumbs[] = array( 'name' => wp_strip_all_tags( get_the_title( $id ) ), 'url' => get_permalink( $id ) );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$l = get_term_link( get_queried_object() );
			$crumbs[] = array( 'name' => single_term_title( '', false ), 'url' => is_wp_error( $l ) ? home_url( '/' ) : $l );
		} elseif ( is_post_type_archive() ) {
			$crumbs[] = array( 'name' => post_type_archive_title( '', false ), 'url' => get_post_type_archive_link( get_query_var( 'post_type' ) ) ?: home_url( '/' ) );
		} else {
			return '';
		}
		if ( count( $crumbs ) < 2 ) { return ''; }
		$items = array();
		foreach ( $crumbs as $i => $c ) {
			$items[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['url'] );
		}
		return wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items ), JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Hard-cap a meta description at $max characters on a clean boundary.
	 *
	 * The SERP snippet Google shows is truncated at ~155–160 chars, so this is
	 * the single enforcement point: the AI writer (module 34) is *asked* for a
	 * short description but LLMs can't reliably count characters, so we guarantee
	 * it here at serve time — for AI, hand-written, and excerpt-fallback text
	 * alike. Prefers ending on a complete sentence if one lands in the back
	 * portion; otherwise cuts at the last word boundary (never mid-word) and
	 * strips trailing punctuation. No ellipsis (cleaner for meta text; Google
	 * adds its own if it truncates further). Non-destructive — stored
	 * `_gasf_seo_desc` values are untouched; only the rendered tag is capped.
	 */
	function gasf_seo_clip( $text, $max = 155 ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( '' === $text || mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$slice = mb_substr( $text, 0, $max );
		// Full-sentence end within the back ~40% → keep the clean sentence.
		if ( preg_match( '/^(.*[.!?])(?:\s|$)/us', $slice, $m ) && mb_strlen( $m[1] ) >= (int) ( $max * 0.6 ) ) {
			return $m[1];
		}
		// Else cut at the last space (drop the partial trailing word), tidy punctuation.
		$sp = mb_strrpos( $slice, ' ' );
		if ( false !== $sp && $sp >= (int) ( $max * 0.5 ) ) {
			$slice = mb_substr( $slice, 0, $sp );
		}
		return rtrim( $slice, " ,;:.!?-–—" );
	}

	function gasf_seo_desc() {
		$s = gasf_seo_settings();
		if ( is_front_page() ) {
			$fp = (int) get_option( 'page_on_front' );
			$d  = ( $fp ? gasf_seo_meta( $fp, 'desc' ) : '' ) ?: $s['desc_home'];
		} elseif ( is_singular() ) {
			$id = get_queried_object_id();
			$d  = gasf_seo_meta( $id, 'desc' ) ?: gasf_seo_excerpt( $id );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$d = wp_strip_all_tags( term_description() );
		} else {
			$d = '';
		}
		$d = gasf_seo_expand( $d );
		return gasf_seo_clip( trim( wp_strip_all_tags( $d ) ) );
	}

	function gasf_seo_canonical() {
		if ( is_front_page() ) { return home_url( '/' ); }
		if ( is_singular() ) {
			$c = gasf_seo_meta( get_queried_object_id(), 'canonical' );
			return $c ?: get_permalink( get_queried_object_id() );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$l = get_term_link( get_queried_object() );
			return is_wp_error( $l ) ? '' : $l;
		}
		if ( is_post_type_archive() ) { return get_post_type_archive_link( get_query_var( 'post_type' ) ) ?: ''; }
		return '';
	}

	function gasf_seo_image() {
		if ( is_singular() ) {
			$id = get_queried_object_id();
			$ov = gasf_seo_meta( $id, 'og_image' );
			if ( $ov ) { return $ov; }
			if ( has_post_thumbnail( $id ) ) { return get_the_post_thumbnail_url( $id, 'full' ); }
			// Fall back to the first image in the content (matches Yoast's behaviour).
			$p = get_post( $id );
			if ( $p && preg_match( '/<img[^>]+src=["\']([^"\']+)/i', (string) $p->post_content, $m ) ) {
				return $m[1];
			}
		}
		$def = gasf_seo_settings()['og_image'];
		return $def ?: '';
	}

	/** Read our meta, falling back to Yoast's (until the import copies it over). */
	function gasf_seo_meta( $post_id, $field ) {
		$map = array(
			'title'     => array( '_gasf_seo_title', '_yoast_wpseo_title' ),
			'desc'      => array( '_gasf_seo_desc', '_yoast_wpseo_metadesc' ),
			'canonical' => array( '_gasf_seo_canonical', '_yoast_wpseo_canonical' ),
			'og_image'  => array( '_gasf_seo_og_image', '_yoast_wpseo_opengraph-image' ),
		);
		foreach ( $map[ $field ] ?? array() as $key ) {
			$v = (string) get_post_meta( $post_id, $key, true );
			if ( '' !== $v ) { return $v; }
		}
		return '';
	}

	function gasf_seo_excerpt( $id ) {
		$p = get_post( $id );
		if ( ! $p ) { return ''; }
		$t = has_excerpt( $id ) ? $p->post_excerpt : $p->post_content;
		$t = wp_strip_all_tags( strip_shortcodes( (string) $t ) );
		$t = trim( preg_replace( '/\s+/', ' ', $t ) );
		if ( mb_strlen( $t ) > 160 ) { $t = rtrim( mb_substr( $t, 0, 157 ) ) . '…'; }
		return $t;
	}

	/* ============================ post editor box ============================ */
	add_action( 'add_meta_boxes', function () {
		if ( gasf_seo_yoast_active() ) { return; } // Yoast still owns the UI until it's gone
		foreach ( GASF_SEO_TYPES as $pt ) {
			add_meta_box( 'gasf_seo', 'SEO (GASF)', 'gasf_seo_box', $pt, 'normal', 'default' );
		}
	} );
	function gasf_seo_box( $post ) {
		wp_nonce_field( 'gasf_seo_save', 'gasf_seo_nonce' );
		$t = gasf_seo_meta( $post->ID, 'title' );
		$d = gasf_seo_meta( $post->ID, 'desc' );
		$n = (bool) get_post_meta( $post->ID, '_gasf_seo_noindex', true );
		echo '<p><label><strong>SEO title</strong><br><input type="text" name="gasf_seo_title" value="' . esc_attr( $t ) . '" class="widefat" placeholder="' . esc_attr( wp_strip_all_tags( get_the_title( $post ) ) . ' – ' . get_bloginfo( 'name' ) ) . '"></label></p>';
		echo '<p><label><strong>Meta description</strong><br><textarea name="gasf_seo_desc" rows="3" class="widefat" placeholder="Falls back to the excerpt">' . esc_textarea( $d ) . '</textarea></label><span class="description">~155 characters is ideal.</span></p>';
		echo '<p><label><input type="checkbox" name="gasf_seo_noindex" value="1" ' . checked( $n, true, false ) . '> Ask search engines <strong>not</strong> to index this page</label></p>';
		echo '<p class="description">Supports <code>%%title%%</code>, <code>%%sitename%%</code>, <code>%%sep%%</code>, <code>%%excerpt%%</code>.</p>';
		$kw = get_post_meta( $post->ID, '_gasf_seo_keyphrases', true );
		echo '<hr style="margin:14px 0"><p><label><strong>Focus keyphrase(s)</strong> <span class="description">— comma-separated; terms you want this page to rank for</span><br><input type="text" id="gasf_seo_kw" name="gasf_seo_keyphrases" value="' . esc_attr( $kw ) . '" class="widefat" placeholder="e.g. german festival tampa, oktoberfest pinellas park"></label></p>';
		echo '<div id="gasf_seo_analysis" style="font-size:13px;line-height:1.8"></div>';
		gasf_seo_box_js();
	}

	function gasf_seo_box_js() {
		?>
		<script>
		(function(){
			if ( window.gasfSeoBoxInit ) { return; } window.gasfSeoBoxInit = 1;
			var $ = window.jQuery; if ( ! $ ) { return; }
			function norm(s){ return (s||'').toString().toLowerCase(); }
			function content(fmt){
				try { if ( window.tinymce ) { var e = tinymce.get('content'); if ( e && ! e.isHidden() ) { return e.getContent({format: fmt||'text'}); } } } catch(x){}
				var ta = document.getElementById('content'); return ta ? ta.value : '';
			}
			function slug(){ var el=document.getElementById('editable-post-name'); if(el) return el.textContent; var t=document.getElementById('title'); return t? t.value : ''; }
			function esc(s){ return $('<div>').text(s).html(); }
			function row(ok,txt){ return '<div style="color:'+(ok?'#1a7f37':'#b3122b')+'">'+(ok?'✓':'✗')+' '+txt+'</div>'; }
			function analyze(){
				var box=document.getElementById('gasf_seo_analysis'); if(!box) return;
				var kws=((document.getElementById('gasf_seo_kw')||{}).value||'').split(',').map(function(s){return s.trim();}).filter(Boolean);
				var title=norm(($('[name=gasf_seo_title]').val()||'')+' '+($('#title').val()||''));
				var desc=norm($('[name=gasf_seo_desc]').val()||'');
				var text=norm(content('text'));
				var html=norm(content('html'));
				var sl=norm(slug());
				var words=(text.match(/\S+/g)||[]).length;
				var out=row(words>=300,'Content length: '+words+' words'+(words>=300?'':' (aim for 300+)'));
				if(!kws.length){ box.innerHTML=out+'<div class="description" style="margin-top:6px">Add a focus keyphrase to see keyphrase checks.</div>'; return; }
				var heads=html.match(/<h[1-6][^>]*>[\s\S]*?<\/h[1-6]>/g)||[];
				kws.forEach(function(kw,i){
					var k=norm(kw);
					var inHead=false; heads.forEach(function(h){ if(norm(h.replace(/<[^>]+>/g,'')).indexOf(k)>-1) inHead=true; });
					out+='<div style="margin-top:8px"><strong>'+(i===0?'Primary: ':'')+esc(kw)+'</strong>';
					out+=row(title.indexOf(k)>-1,'In the title');
					out+=row(desc.indexOf(k)>-1,'In the meta description');
					out+=row(text.indexOf(k)>-1,'In the content');
					out+=row(inHead,'In a subheading (H2–H6)');
					out+=row(sl.indexOf(k.replace(/\s+/g,'-'))>-1||sl.indexOf(k.replace(/\s+/g,''))>-1,'In the URL slug');
					out+='</div>';
				});
				box.innerHTML=out;
			}
			$(document).on('input','#gasf_seo_kw, [name=gasf_seo_title], [name=gasf_seo_desc], #title', analyze);
			setInterval(analyze, 2000);
			$(analyze);
		})();
		</script>
		<?php
	}
	add_action( 'save_post', function ( $post_id ) {
		if ( ! isset( $_POST['gasf_seo_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['gasf_seo_nonce'] ), 'gasf_seo_save' ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		// save_post also fires for the revision copy in the same request —
		// without this, every save duplicated the SEO meta rows onto the
		// revision post (steady postmeta growth, and revisions don't need it).
		if ( wp_is_post_revision( $post_id ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		$set = function ( $key, $val ) use ( $post_id ) {
			$val = trim( (string) $val );
			if ( '' === $val ) { delete_post_meta( $post_id, $key ); } else { update_post_meta( $post_id, $key, $val ); }
		};
		$set( '_gasf_seo_title', sanitize_text_field( wp_unslash( $_POST['gasf_seo_title'] ?? '' ) ) );
		$set( '_gasf_seo_desc', sanitize_textarea_field( wp_unslash( $_POST['gasf_seo_desc'] ?? '' ) ) );
		$set( '_gasf_seo_keyphrases', sanitize_text_field( wp_unslash( $_POST['gasf_seo_keyphrases'] ?? '' ) ) );
		if ( ! empty( $_POST['gasf_seo_noindex'] ) ) { update_post_meta( $post_id, '_gasf_seo_noindex', 1 ); } else { delete_post_meta( $post_id, '_gasf_seo_noindex' ); }
	} );

	/* ============================ admin tab ============================ */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) {
			gasf_utilities_add_tab( 'seo', 'SEO', 'gasf_seo_admin_page', 30 );
		}
	} );

	/** Copy Yoast titles/descriptions into our meta + seed settings from Yoast config. */
	function gasf_seo_import_from_yoast() {
		global $wpdb;
		$pairs = array(
			'_yoast_wpseo_title'                => '_gasf_seo_title',
			'_yoast_wpseo_metadesc'             => '_gasf_seo_desc',
			'_yoast_wpseo_canonical'            => '_gasf_seo_canonical',
			'_yoast_wpseo_opengraph-image'      => '_gasf_seo_og_image',
			'_yoast_wpseo_meta-robots-noindex'  => '_gasf_seo_noindex',
		);
		$copied = 0;
		foreach ( $pairs as $from => $to ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value<>''", $from ) );
			foreach ( $rows as $r ) {
				$val = $r->meta_value;
				if ( '_gasf_seo_noindex' === $to ) { if ( '1' !== (string) $val ) { continue; } $val = 1; }
				if ( '' === (string) get_post_meta( $r->post_id, $to, true ) ) {
					update_post_meta( $r->post_id, $to, $val );
					$copied++;
				}
			}
		}
		// Seed settings from Yoast titles config.
		$y   = get_option( 'wpseo_titles', array() );
		$sepmap = array( 'sc-dash' => '–', 'sc-ndash' => '–', 'sc-mdash' => '—', 'sc-middot' => '·', 'sc-bull' => '•', 'sc-star' => '*', 'sc-pipe' => '|', 'sc-tilde' => '~', 'sc-laquo' => '«', 'sc-raquo' => '»', 'sc-lt' => '<', 'sc-gt' => '>' );
		$s = gasf_seo_settings();
		if ( ! empty( $y['separator'] ) && isset( $sepmap[ $y['separator'] ] ) ) { $s['sep'] = $sepmap[ $y['separator'] ]; }
		if ( ! empty( $y['title-home-wpseo'] ) )    { $s['title_home'] = $y['title-home-wpseo']; }
		if ( ! empty( $y['metadesc-home-wpseo'] ) ) { $s['desc_home'] = $y['metadesc-home-wpseo']; }
		if ( ! empty( $y['title-page'] ) )          { $s['title_single'] = $y['title-page']; }
		update_option( 'gasf_seo_settings', $s, false );
		return $copied;
	}

	function gasf_seo_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		if ( isset( $_POST['gasf_seo_action'] ) && check_admin_referer( 'gasf_seo_admin' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_seo_action'] ) );
			if ( 'import' === $act ) {
				$n = gasf_seo_import_from_yoast();
				echo '<div class="notice notice-success is-dismissible"><p>Imported ' . (int) $n . ' SEO values from Yoast, and seeded settings.</p></div>';
			} elseif ( 'save' === $act ) {
				update_option( 'gasf_seo_settings', array(
					'sep'          => sanitize_text_field( wp_unslash( $_POST['sep'] ?? '–' ) ) ?: '–',
					'title_home'   => sanitize_text_field( wp_unslash( $_POST['title_home'] ?? '' ) ),
					'desc_home'    => sanitize_textarea_field( wp_unslash( $_POST['desc_home'] ?? '' ) ),
					'title_single' => sanitize_text_field( wp_unslash( $_POST['title_single'] ?? '' ) ),
					'og_image'     => esc_url_raw( wp_unslash( $_POST['og_image'] ?? '' ) ),
					'twitter'      => sanitize_text_field( wp_unslash( $_POST['twitter'] ?? '' ) ),
				), false );
				echo '<div class="notice notice-success is-dismissible"><p>SEO settings saved.</p></div>';
			}
		}

		$s      = gasf_seo_settings();
		$yoast  = gasf_seo_yoast_active();
		global $wpdb;
		$mine   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_gasf_seo_title' AND meta_value<>''" );
		?>
		<h2>SEO</h2>
		<?php
		if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
			gasf_utilities_doc_panel( array(
				'what'   => 'The site\'s SEO engine — the native replacement for Yoast. On every page it outputs the title tag, meta description, canonical URL, robots directives, Open Graph + Twitter share cards, and WebSite/Breadcrumb JSON-LD. Per-page values are edited in the <strong>"SEO (GASF)"</strong> box when editing that page (title, description, noindex, focus keyphrases with a live checklist); this tab holds the site-wide defaults that fill in everything you haven\'t set by hand.',
				'needs'  => array(
					'Nothing external — no API keys, no other plugin. (Yoast must stay deactivated or this module goes dormant to avoid double tags.)',
				),
				'fields' => array(
					'Title separator'           => 'The character between the page name and site name in browser-tab titles, e.g. the "–" in "Events – German-American Society". Cosmetic but keep it consistent.',
					'Home title'                => 'The exact title tag for the home page — your single most important SEO string. Lead with what you want to rank for (e.g. "German-American Society – German Club &amp; Events in Tampa Bay").',
					'Home meta description'     => 'The ~155-character snippet Google shows under the home-page result. Write it like ad copy: who you are, where, and why to click.',
					'Page / post title'         => 'The template for every other page\'s title tag. Variables: <code>%%title%%</code> (page name), <code>%%sitename%%</code>, <code>%%sitedesc%%</code>, <code>%%sep%%</code> (the separator above), <code>%%page%%</code> (pagination). The default "%%title%% %%sep%% %%sitename%%" is right for most sites.',
					'Default share image (OG)'  => 'The image Facebook/WhatsApp/Slack show when a shared page has no featured or content image of its own. Should be ≥1200×630 px for full-size cards. Currently the building photo.',
					'Twitter @handle'           => 'Attributed as the site\'s account on Twitter/X share cards. Optional — blank just omits the attribution.',
					'Re-import from Yoast meta' => 'One-time migration tool: copies leftover Yoast titles/descriptions into this module\'s meta, never overwriting values already set here. Already ran; re-running is safe but unnecessary.',
				),
				'notes'  => 'Sitemap is WordPress core at <code>/wp-sitemap.xml</code> (the old Yoast <code>/sitemap_index.xml</code> 301s there). Event pages get their descriptions auto-filled by <strong>Event SEO (AI)</strong>; URL redirects live on the <strong>Redirects</strong> tab.',
			) );
		}
		?>
		<p>To edit one page, use the <strong>&ldquo;SEO (GASF)&rdquo;</strong> box on that page; the defaults below fill in everything you haven&rsquo;t set by hand.</p>
		<table class="widefat striped" style="max-width:620px">
			<tr><td>Status</td><td><?php echo $yoast ? '<strong style="color:#8a6d3b">dormant</strong> — Yoast is still active' : '<strong style="color:#1a7f37">● active</strong>'; ?></td></tr>
			<tr><td>Pages with a custom SEO title</td><td><?php echo esc_html( $mine ); ?></td></tr>
			<tr><td>Sitemap</td><td><code>/wp-sitemap.xml</code> <span class="description">(WordPress core; old <code>/sitemap_index.xml</code> redirects here)</span></td></tr>
		</table>

		<h3 class="title">Templates &amp; defaults</h3>
		<form method="post"><?php wp_nonce_field( 'gasf_seo_admin' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Title separator</th><td><input type="text" name="sep" value="<?php echo esc_attr( $s['sep'] ); ?>" class="small-text"></td></tr>
				<tr><th scope="row">Home title</th><td><input type="text" name="title_home" value="<?php echo esc_attr( $s['title_home'] ); ?>" class="large-text"></td></tr>
				<tr><th scope="row">Home meta description</th><td><textarea name="desc_home" rows="2" class="large-text"><?php echo esc_textarea( $s['desc_home'] ); ?></textarea></td></tr>
				<tr><th scope="row">Page / post title</th><td><input type="text" name="title_single" value="<?php echo esc_attr( $s['title_single'] ); ?>" class="large-text"><p class="description">Vars: <code>%%title%%</code> <code>%%sitename%%</code> <code>%%sitedesc%%</code> <code>%%sep%%</code> <code>%%page%%</code></p></td></tr>
				<tr><th scope="row">Default share image (OG)</th><td><input type="url" name="og_image" value="<?php echo esc_attr( $s['og_image'] ); ?>" class="large-text" placeholder="used when a page has no image of its own"></td></tr>
				<tr><th scope="row">Twitter @handle</th><td><input type="text" name="twitter" value="<?php echo esc_attr( $s['twitter'] ); ?>" class="regular-text" placeholder="@germantampa"></td></tr>
			</table>
			<p><button name="gasf_seo_action" value="save" class="button button-primary">Save settings</button></p>
		</form>

		<details style="margin-top:20px">
			<summary style="cursor:pointer;color:#666">Yoast migration <?php echo $yoast ? '' : '&mdash; completed &#10003;'; ?></summary>
			<p class="description" style="max-width:640px;margin-top:8px">Your Yoast titles &amp; descriptions were already imported into this plugin&rsquo;s own meta, so nothing depends on Yoast. Re-running is safe (it never overwrites values already set here) but is normally unnecessary.</p>
			<form method="post"><?php wp_nonce_field( 'gasf_seo_admin' ); ?>
				<button name="gasf_seo_action" value="import" class="button">Re-import from Yoast meta</button>
			</form>
		</details>
		<?php
	}
}
