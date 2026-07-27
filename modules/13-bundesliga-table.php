<?php
// Migrated from Code Snippet #13 "Bundesliga Table Shortcode" 2026-06-14 (task 260614-gj8).
// Gate: gasf_site_enable_bundesliga (default ON). Backed up in snippets-backup-20260614.sql.
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_site_enabled( 'gasf_site_enable_bundesliga' ) ) {
/**
 * The shared .gasf-buli-* stylesheet, emitted once per request by whichever
 * Bundesliga shortcode renders first. It used to live inside [bundesliga_table]
 * alone, so a page carrying only the scorer widgets rendered them unstyled —
 * the styling depended on the table happening to be on the same page. All
 * three modules share one gate and load in file order (13 → 14 → 15), so this
 * definition always exists before the scorer shortcodes run.
 */
function gasf_buli_styles() {
    static $printed = false;
    if ( $printed ) { return ''; }
    $printed = true;
    return '
<style>
.gasf-buli-wrap{font-family:"Rubik",Arial,sans-serif;max-width:420px;background:#fff;color:var(--gasf-dark-bg,#222);border:1px solid #ddd;border-radius:6px;overflow:hidden;font-size:13px;}
.gasf-buli-header{background:var(--gasf-bundesliga-red,#dc052d);color:#fff;display:flex;align-items:center;gap:8px;padding:10px 14px;font-weight:700;font-size:15px;}
.gasf-buli-logo{font-size:18px;}
.gasf-buli-table{width:100%;border-collapse:collapse;}
.gasf-buli-th{background:var(--gasf-dark-bg,#1a1a1a);color:#fff;padding:5px 6px;text-align:center;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.4px;}
.gasf-buli-th.gasf-buli-club{text-align:left;padding-left:10px;}
.gasf-buli-td{padding:5px 6px;text-align:center;border-bottom:1px solid #eee;vertical-align:middle;color:var(--gasf-dark-bg,#222);}
.gasf-buli-td.gasf-buli-club{text-align:left;padding-left:10px;white-space:nowrap;}
.gasf-buli-rank{width:32px;position:relative;padding-left:14px !important;}
.gasf-buli-zone{display:inline-block;width:4px;height:20px;border-radius:2px;position:absolute;left:4px;top:50%;transform:translateY(-50%);}
.gasf-buli-logo-img{width:18px;height:18px;object-fit:contain;margin-right:6px;vertical-align:middle;}
.gasf-buli-name{vertical-align:middle;}
.gasf-buli-pts{font-weight:700;}
.gasf-buli-num{width:28px;}
.gasf-buli-legend{display:flex;flex-wrap:wrap;gap:8px;padding:8px 12px;background:#f5f5f5;border-top:1px solid #eee;}
.gasf-buli-legend-item{display:flex;align-items:center;gap:4px;font-size:11px;color:#555;}
.gasf-buli-legend-dot{display:inline-block;width:10px;height:10px;border-radius:50%;}
.gasf-buli-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;}
.gasf-buli-source{text-align:right;font-size:10px;color:#aaa;margin:0;padding:4px 10px 6px;background:#f5f5f5;}
</style>';
}

/**
 * Bundesliga Table Shortcode [bundesliga_table]
 *
 * Data: OpenLigaDB (free, no API key) — https://api.openligadb.de/
 * Cache: WordPress transient, 1 hour
 * Season: tries current year, then walks back until data is found (handles off-season)
 * Usage: [bundesliga_table] or [bundesliga_table season="2025"]
 */
add_shortcode( 'bundesliga_table', function( $atts ) {

    $atts = shortcode_atts( [ 'season' => '' ], $atts );

    // ── Fetch standings with season fallback ─────────────────
    $standings = false;
    $season    = $atts['season'] ? intval( $atts['season'] ) : intval( date('Y') );

    // Try current year, then walk back up to 2 years — handles off-season empty responses
    for ( $try = $season; $try >= $season - 2; $try-- ) {
        $cache_key = 'gasf_buli_table_' . $try;
        $standings = get_transient( $cache_key );
        if ( false !== $standings && ! empty( $standings ) ) {
            $season = $try;
            break;
        }
        // Negative cache: without it, an empty year (all of Jan–Aug before the
        // new season exists in OpenLigaDB) re-fetches on EVERY page render.
        if ( get_transient( $cache_key . '_miss' ) ) { continue; }
        $resp = wp_remote_get(
            "https://api.openligadb.de/getbltable/bl1/{$try}",
            [ 'timeout' => 10 ]
        );
        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( ! empty( $data ) ) {
                $standings = $data;
                $season    = $try;
                set_transient( $cache_key, $standings, HOUR_IN_SECONDS );
                break;
            }
        }
        set_transient( $cache_key . '_miss', 1, HOUR_IN_SECONDS );
    }

    if ( empty( $standings ) ) {
        return '<p style="color:#999;font-size:13px;">Bundesliga table unavailable — please check back later.</p>';
    }

    // ── Zone definitions ──────────────────────────────────────
    $zones = [
        1 => [ 'color' => '#1a6b3a', 'title' => 'Champions League' ],
        2 => [ 'color' => '#1a6b3a', 'title' => 'Champions League' ],
        3 => [ 'color' => '#1a6b3a', 'title' => 'Champions League' ],
        4 => [ 'color' => '#1a6b3a', 'title' => 'Champions League' ],
        5 => [ 'color' => '#e67e00', 'title' => 'Europa League' ],
        6 => [ 'color' => '#d4a017', 'title' => 'Conference League' ],
       16 => [ 'color' => '#cc5500', 'title' => 'Relegation Playoff' ],
       17 => [ 'color' => '#b22222', 'title' => 'Relegation' ],
       18 => [ 'color' => '#b22222', 'title' => 'Relegation' ],
    ];

    // ── Build table HTML ──────────────────────────────────────
    $season_display = $season . '/' . substr( $season + 1, -2 );

    $html  = '<div class="gasf-buli-wrap">';
    $html .= '<div class="gasf-buli-header">';
    $html .= '<span class="gasf-buli-logo">⚽</span>';
    $html .= '<span class="gasf-buli-title">Bundesliga ' . esc_html( $season_display ) . '</span>';
    $html .= '</div>';

    $html .= '<div class="gasf-buli-scroll"><table class="gasf-buli-table">';
    $html .= '<thead><tr>';
    $html .= '<th class="gasf-buli-th gasf-buli-rank">#</th>';
    $html .= '<th class="gasf-buli-th gasf-buli-club">Club</th>';
    $html .= '<th class="gasf-buli-th gasf-buli-num" title="Played">MP</th>';
    $html .= '<th class="gasf-buli-th gasf-buli-num" title="Won">W</th>';
    $html .= '<th class="gasf-buli-th gasf-buli-num" title="Drawn">D</th>';
    $html .= '<th class="gasf-buli-th gasf-buli-num" title="Lost">L</th>';
    $html .= '<th class="gasf-buli-th gasf-buli-num" title="Goal Difference">GD</th>';
    $html .= '<th class="gasf-buli-th gasf-buli-pts" title="Points">Pts</th>';
    $html .= '</tr></thead>';
    $html .= '<tbody>';

    foreach ( $standings as $i => $team ) {
        $rank   = $i + 1;
        $name   = esc_html( $team['teamName'] ?? $team['TeamName'] ?? '' );
        $logo   = esc_url( $team['teamIconUrl'] ?? $team['TeamIconUrl'] ?? '' );
        $played = intval( $team['matches'] ?? $team['Matches'] ?? 0 );
        $won    = intval( $team['won'] ?? $team['Won'] ?? 0 );
        $draw   = intval( $team['draw'] ?? $team['Draw'] ?? 0 );
        $lost   = intval( $team['lost'] ?? $team['Lost'] ?? 0 );
        $gd     = intval( $team['goalDiff'] ?? $team['GoalDiff'] ?? 0 );
        $pts    = intval( $team['points'] ?? $team['Points'] ?? 0 );
        $gd_str = $gd > 0 ? '+' . $gd : $gd;

        $team_id   = intval( $team['teamInfoId'] ?? $team['TeamInfoId'] ?? 0 );
        $is_bayern = ( $team_id === 40 );
        $zone      = $zones[ $rank ] ?? null;

        $row_style = '';
        if ( $is_bayern ) {
            $row_style = 'background:var(--gasf-bundesliga-red,#dc052d);color:#fff;font-weight:700;';
        } elseif ( $rank % 2 === 0 ) {
            $row_style = 'background:#f8f8f8;';
        }

        $zone_bar = $zone
            ? '<span class="gasf-buli-zone" style="background:' . $zone['color'] . ';" title="' . esc_attr( $zone['title'] ) . '"></span>'
            : '';

        $logo_html = $logo
            ? '<img src="' . $logo . '" alt="' . $name . '" class="gasf-buli-logo-img" loading="lazy">'
            : '';

        $html .= '<tr style="' . $row_style . '">';
        $html .= '<td class="gasf-buli-td gasf-buli-rank">' . $zone_bar . $rank . '</td>';
        $html .= '<td class="gasf-buli-td gasf-buli-club">' . $logo_html . '<span class="gasf-buli-name">' . $name . '</span></td>';
        $html .= '<td class="gasf-buli-td gasf-buli-num">' . $played . '</td>';
        $html .= '<td class="gasf-buli-td gasf-buli-num">' . $won . '</td>';
        $html .= '<td class="gasf-buli-td gasf-buli-num">' . $draw . '</td>';
        $html .= '<td class="gasf-buli-td gasf-buli-num">' . $lost . '</td>';
        $html .= '<td class="gasf-buli-td gasf-buli-num">' . $gd_str . '</td>';
        $html .= '<td class="gasf-buli-td gasf-buli-pts">' . $pts . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';

    $html .= '<div class="gasf-buli-legend">';
    $html .= '<span class="gasf-buli-legend-item"><span class="gasf-buli-legend-dot" style="background:#1a6b3a;"></span>Champions League</span>';
    $html .= '<span class="gasf-buli-legend-item"><span class="gasf-buli-legend-dot" style="background:#e67e00;"></span>Europa League</span>';
    $html .= '<span class="gasf-buli-legend-item"><span class="gasf-buli-legend-dot" style="background:#d4a017;"></span>Conference League</span>';
    $html .= '<span class="gasf-buli-legend-item"><span class="gasf-buli-legend-dot" style="background:#b22222;"></span>Relegation</span>';
    $html .= '</div>';

    $html .= '<p class="gasf-buli-source">Data: <a href="https://openligadb.de" target="_blank" rel="noopener" style="color:inherit;">OpenLigaDB</a></p>';
    $html .= '</div>';

    $html .= gasf_buli_styles();

    return $html;
} );
}
