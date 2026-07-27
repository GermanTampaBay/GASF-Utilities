<?php
// Migrated from Code Snippet #15 "Bundesliga Top Scorers Shortcode" 2026-06-14 (task 260614-gj8).
// Gate: gasf_site_enable_bundesliga (default ON). Backed up in snippets-backup-20260614.sql.
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_site_enabled( 'gasf_site_enable_bundesliga' ) ) {
/**
 * Bundesliga Top Scorers Shortcode [bundesliga_top_scorers]
 *
 * Data: OpenLigaDB getgoalgetters endpoint — full league, pre-sorted by goals.
 * Cache: 1-hour WordPress transient.
 * Season: same auto-detect fallback as [bundesliga_table].
 * Usage: [bundesliga_top_scorers] or [bundesliga_top_scorers limit="10"]
 */
add_shortcode( 'bundesliga_top_scorers', function( $atts ) {

    $atts  = shortcode_atts( [ 'season' => '', 'limit' => 10 ], $atts );
    $limit = max( 1, intval( $atts['limit'] ) );

    // ── Fetch with season fallback ────────────────────────────
    $scorers = false;
    $season  = $atts['season'] ? intval( $atts['season'] ) : intval( date('Y') );

    for ( $try = $season; $try >= $season - 2; $try-- ) {
        $cache_key = 'gasf_buli_topscorers_' . $try;
        $scorers   = get_transient( $cache_key );
        if ( ! empty( $scorers ) ) { $season = $try; break; }

        // Negative cache — same pattern as [bundesliga_table]: an empty year
        // (off-season) must not re-fetch on every page render.
        if ( get_transient( $cache_key . '_miss' ) ) { continue; }

        $resp = wp_remote_get(
            "https://api.openligadb.de/getgoalgetters/bl1/{$try}",
            [ 'timeout' => 10 ]
        );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) { set_transient( $cache_key . '_miss', 1, HOUR_IN_SECONDS ); continue; }
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $data ) ) { set_transient( $cache_key . '_miss', 1, HOUR_IN_SECONDS ); continue; }

        // Already sorted by goals desc — just store
        $scorers = $data;
        $season  = $try;
        set_transient( $cache_key, $scorers, HOUR_IN_SECONDS );
        break;
    }

    if ( empty( $scorers ) ) {
        return '<p style="color:#999;font-size:13px;">Bundesliga top scorers unavailable — please check back later.</p>';
    }

    // Bayern ids for row highlighting. Read the shared cache first, and when
    // it is cold compute it directly — highlighting used to depend on
    // [bundesliga_scorers] having already rendered somewhere and left this
    // transient behind, so this widget silently lost its Bayern rows whenever
    // it rendered first (or alone) after a cache expiry.
    $bayern_ids = get_transient( 'gasf_buli_bayern_ids_' . $season ) ?: [];
    if ( ! $bayern_ids && function_exists( 'gasf_buli_bayern_data' ) ) {
        $d = gasf_buli_bayern_data( $season );
        if ( $d ) { $bayern_ids = $d['ids']; }
    }

    $season_display = $season . '/' . substr( $season + 1, -2 );
    $display        = array_slice( $scorers, 0, $limit );

    $html  = '<div class="gasf-buli-wrap" style="margin-top:16px;">';
    $html .= '<div class="gasf-buli-header">';
    $html .= '<span class="gasf-buli-logo">👟</span>';
    $html .= '<span class="gasf-buli-title">Top Scorers ' . esc_html( $season_display ) . '</span>';
    $html .= '</div>';
    $html .= '<div class="gasf-buli-scroll"><table class="gasf-buli-table">';
    $html .= '<thead><tr>';
    $html .= '<th class="gasf-buli-th gasf-buli-rank">#</th>';
    $html .= '<th class="gasf-buli-th gasf-buli-club">Player</th>';
    $html .= '<th class="gasf-buli-th gasf-buli-pts" title="Goals">G</th>';
    $html .= '</tr></thead><tbody>';

    foreach ( $display as $i => $s ) {
        $rank      = $i + 1;
        $name      = esc_html( $s['goalGetterName'] );
        $goals     = intval( $s['goalCount'] );
        $is_bayern = in_array( intval( $s['goalGetterId'] ), $bayern_ids, true );

        if ( $is_bayern ) {
            $row_style = 'background:var(--gasf-bundesliga-red,#dc052d);color:#fff;font-weight:700;';
        } elseif ( $rank % 2 === 0 ) {
            $row_style = 'background:#f8f8f8;';
        } else {
            $row_style = '';
        }

        $html .= '<tr style="' . $row_style . '">';
        $html .= '<td class="gasf-buli-td gasf-buli-rank">' . $rank . '</td>';
        $html .= '<td class="gasf-buli-td gasf-buli-club"><span class="gasf-buli-name">' . $name . '</span></td>';
        $html .= '<td class="gasf-buli-td gasf-buli-pts">' . $goals . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';
    $html .= '<p class="gasf-buli-source">Data: <a href="https://openligadb.de" target="_blank" rel="noopener" style="color:inherit;">OpenLigaDB</a></p>';
    $html .= '</div>';

    // Shared stylesheet (once per request) — this widget used to render bare
    // unless [bundesliga_table] happened to be on the same page.
    $html .= gasf_buli_styles();

    return $html;
} );
}
