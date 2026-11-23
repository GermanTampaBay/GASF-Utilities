<?php
/**
 * AI assistants and crawlers — modules/48-llms.php
 *
 * 1. /llms.txt at the domain root (llmstxt.org): who the society is, its menu
 *    as a list of pages with their search descriptions, the coming events,
 *    and a pointer to the Krampus Verein's own /krampus/llms.txt (served by
 *    the new platform on the VPS).
 *
 *    The host answers a missing .txt file itself (an Apache 404) before
 *    WordPress ever sees the request, so this cannot be a virtual route like
 *    robots.txt. Instead the file is WRITTEN to the web root: hourly by cron,
 *    and right away when it is missing. Atomic (temp file + rename), so a
 *    reader never sees half a file.
 *
 * 2. robots.txt names the Krampus sitemap (/krampus/sitemap.xml, served by
 *    the platform), beside core's own wp-sitemap.xml, so crawlers find the
 *    Krampus pages without waiting to follow links.
 *
 * Main install only (home_url has no path): the root belongs to it.
 * Gate: gasf_site_enable_llms (default ON). Turning it off stops the rewrite;
 * delete public_html/llms.txt by hand to withdraw the file.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_llms' ) && '' === trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ) ) {

	add_filter( 'robots_txt', function ( $output, $public ) {
		if ( ! $public ) { return $output; }
		$line = 'Sitemap: ' . home_url( '/krampus/sitemap.xml' ) . "\n";
		return false === strpos( $output, $line ) ? rtrim( $output, "\n" ) . "\n" . $line : $output;
	}, 99, 2 );

	add_action( 'gasf_llms_cron', 'gasf_llms_write' );
	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_llms_cron' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'gasf_llms_cron' );
		}
		if ( ! file_exists( ABSPATH . 'llms.txt' ) && ! get_transient( 'gasf_llms_tried' ) ) {
			set_transient( 'gasf_llms_tried', 1, 10 * MINUTE_IN_SECONDS ); // one try per 10 minutes, not one per request
			gasf_llms_write();
		}
	} );

	/** One line of Markdown text: no newlines, entities decoded. */
	function gasf_llms_clean( $s ) {
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/** The search description a page has, or ''. */
	function gasf_llms_desc( $post_id ) {
		return gasf_llms_clean( get_post_meta( $post_id, '_gasf_seo_desc', true ) );
	}

	function gasf_llms_build() {
		$home  = home_url( '/' );
		$front = (int) get_option( 'page_on_front' );
		$desc  = $front ? gasf_llms_desc( $front ) : '';
		if ( '' === $desc ) { $desc = gasf_llms_clean( get_bloginfo( 'description' ) ); }

		$out   = array();
		$out[] = '# German-American Society';
		$out[] = '> ' . $desc;
		$out[] = implode( "\n\n", array(
			'The German-American Society (formally the German-American Society Friendship of Pinellas County) is a social club for German culture in Tampa Bay, founded in 1951, at 8098 66th Street North, Pinellas Park, FL 33781. Its festivals (Oktoberfest, Bock Fest, Maifest, Sausage Fest, Krampusnacht), dinners, dances and FC Bayern watch parties are open to the public; members join for the club life around them.',
			'Groups within the society include the Krampus Verein Pinellas (its own pages and llms.txt under ' . home_url( '/krampus/' ) . '), the Schuhplattler dancers, and the FC Bayern München fan club.',
		) );

		// The site's own menu, in its order: the pages the club means people to find.
		$pages = array();
		$locs  = get_nav_menu_locations();
		foreach ( $locs as $menu_id ) {
			$items = $menu_id ? wp_get_nav_menu_items( $menu_id ) : false;
			if ( ! $items ) { continue; }
			foreach ( $items as $it ) {
				$url = (string) $it->url;
				if ( '' === $url || '#' === $url[0] || false !== strpos( $url, '/wp-admin' ) ) { continue; }
				$title = gasf_llms_clean( $it->title );
				if ( '' === $title || isset( $pages[ $url ] ) ) { continue; }
				$d = ( 'post_type' === $it->type && $it->object_id ) ? gasf_llms_desc( (int) $it->object_id ) : '';
				$pages[ $url ] = '- [' . $title . '](' . $url . ')' . ( '' !== $d ? ': ' . $d : '' );
			}
			break; // the first menu that has items is the main one
		}
		if ( $pages ) { $out[] = "## Pages\n\n" . implode( "\n", $pages ); }

		// Coming events, soonest first.
		$q = new WP_Query( array(
			'post_type'      => 'gasf_event',
			'post_status'    => 'publish',
			'posts_per_page' => 25,
			'no_found_rows'  => true,
			'meta_key'       => '_gasf_start_ts',
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
			'meta_query'     => array( array( 'key' => '_gasf_start_ts', 'value' => time(), 'compare' => '>=', 'type' => 'NUMERIC' ) ),
		) );
		$events = array();
		foreach ( $q->posts as $p ) {
			$start = (string) get_post_meta( $p->ID, '_gasf_start', true ); // site-local wall time
			$ts    = strtotime( $start . ' UTC' );
			if ( ! $ts ) { continue; }
			$all   = (bool) get_post_meta( $p->ID, '_gasf_all_day', true ) || (bool) get_post_meta( $p->ID, '_gasf_hide_time', true );
			$when  = gmdate( 'l j F Y', $ts ) . ( $all ? '' : ', ' . gmdate( 'g:i A', $ts ) );
			$st    = (string) get_post_meta( $p->ID, '_gasf_status', true );
			$note  = in_array( $st, array( 'cancelled', 'postponed' ), true ) ? ' (' . $st . ')' : '';
			$events[] = '- ' . $when . ': [' . gasf_llms_clean( $p->post_title ) . '](' . get_permalink( $p ) . ')' . $note;
		}
		if ( $events ) { $out[] = "## Coming events\n\n" . implode( "\n", $events ) . "\n\nThe full calendar: " . home_url( '/events/' ); }

		$out[] = "## Optional\n\n- [Krampus Verein Pinellas](" . home_url( '/krampus/llms.txt' ) . "): the Krampus group, Krampusnacht and its dates\n- [Sitemap](" . home_url( '/wp-sitemap.xml' ) . "): every page, post and event";
		return implode( "\n\n", $out ) . "\n";
	}

	function gasf_llms_write() {
		$body = gasf_llms_build();
		$path = ABSPATH . 'llms.txt';
		if ( file_exists( $path ) && md5_file( $path ) === md5( $body ) ) { return true; }
		$tmp = $path . '.tmp';
		if ( false === @file_put_contents( $tmp, $body ) || ! @rename( $tmp, $path ) ) {
			if ( function_exists( 'gasf_mec_log' ) ) { gasf_mec_log( 'llms.txt: could not write ' . $path ); }
			return false;
		}
		@chmod( $path, 0644 );
		return true;
	}
}
