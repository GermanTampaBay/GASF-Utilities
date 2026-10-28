<?php
/**
 * [world_cup_schedule] — live schedule of upcoming 'World Cup Watch Party' events.
 * Queries native gasf_event posts on render (past games drop off automatically),
 * each row links to the event page. 1-hour transient cache. Gate gasf_mec_enable_wc_schedule.
 * Task: world-cup page reorg.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_mec_enable_wc_schedule' ) ) {

	function gasf_wc_schedule_flag( $name ) {
		$has_usa = stripos( $name, 'USA' ) !== false || stripos( $name, 'United States' ) !== false;
		$has_ger = stripos( $name, 'Germany' ) !== false;
		if ( $has_usa && $has_ger ) return '&#127482;&#127480;&#127465;&#127466; ';
		if ( $has_usa ) return '&#127482;&#127480; ';
		if ( $has_ger ) return '&#127465;&#127466; ';
		if ( stripos( $name, '3rd Place' ) !== false ) return '&#129353; ';
		if ( stripos( $name, 'Final' ) !== false || stripos( $name, 'Semifinal' ) !== false ) return '&#127942; ';
		return '&#9917; ';
	}

	function gasf_wc_schedule_shortcode() {
		$cached = get_transient( 'gasf_wc_schedule_html' );
		if ( $cached !== false ) return $cached;

		global $wpdb;
		$now = time();
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} ts ON ts.post_id=p.ID AND ts.meta_key='_gasf_start_ts'
			 WHERE p.post_type='gasf_event' AND p.post_status='publish'
			 AND p.post_title LIKE 'World Cup Watch Party%' AND ts.meta_value >= %d
			 ORDER BY ( ts.meta_value + 0 ) ASC", $now ) );

		$rows = array();
		foreach ( $ids as $id ) {
			$start = get_post_meta( $id, '_gasf_start', true ); // 'Y-m-d H:i:s' site-local
			$dt    = $start ? date_create_immutable_from_format( 'Y-m-d H:i:s', $start, wp_timezone() ) : false;
			if ( ! $dt ) { continue; }
			$rows[] = array( 'id'=>$id, 'dt'=>$dt,
				'name'=>trim( preg_replace( '/^World Cup Watch Party:?\s*/i', '', html_entity_decode( get_the_title( $id ), ENT_QUOTES ) ) ),
				'url'=>get_permalink( $id ) );
		}

		if ( empty( $rows ) ) {
			$html = '<p style="padding:14px 0"><em>The next watch parties will appear here as soon as the knockout-round matchups are confirmed. Check back soon!</em></p>';
			set_transient( 'gasf_wc_schedule_html', $html, HOUR_IN_SECONDS );
			return $html;
		}

		$cell = 'style="display:block;padding:10px 12px;color:inherit;text-decoration:none"';
		$cellnw = 'style="display:block;padding:10px 12px;color:inherit;text-decoration:none;white-space:nowrap"';
		$h  = '<table class="gasf-wc-schedule" style="width:100%;border-collapse:collapse;margin:16px 0 28px">';
		$h .= '<thead><tr style="background:var(--gasf-cf-green,#0F6E56);color:#fff"><th style="text-align:left;padding:10px 12px">Date</th><th style="text-align:left;padding:10px 12px">Time</th><th style="text-align:left;padding:10px 12px">Match</th></tr></thead><tbody>';
		$n = count( $rows );
		foreach ( $rows as $i => $r ) {
			$u = esc_url( $r['url'] );
			$dlabel = $r['dt']->format( 'D, M j' );
			$tlabel = $r['dt']->format( 'g:i A' );
			$match  = gasf_wc_schedule_flag( $r['name'] ) . esc_html( $r['name'] );
			$border = ( $i < $n - 1 ) ? ' style="border-bottom:1px solid #ddd"' : '';
			$h .= '<tr class="gasf-wc-row"' . $border . '>'
				. '<td style="padding:0"><a href="'.$u.'" '.$cell.'>'.$dlabel.'</a></td>'
				. '<td style="padding:0"><a href="'.$u.'" '.$cellnw.'>'.$tlabel.'</a></td>'
				. '<td style="padding:0"><a href="'.$u.'" '.$cell.'>'.$match.'</a></td></tr>';
		}
		$h .= '</tbody></table>';
		set_transient( 'gasf_wc_schedule_html', $h, HOUR_IN_SECONDS );
		return $h;
	}
	add_shortcode( 'world_cup_schedule', 'gasf_wc_schedule_shortcode' );

	// keep it fresh: clear the cache whenever a gasf_event is saved
	add_action( 'save_post_gasf_event', function(){ delete_transient( 'gasf_wc_schedule_html' ); } );
}
