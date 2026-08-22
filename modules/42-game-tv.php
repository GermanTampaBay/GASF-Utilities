<?php
/**
 * Module 42 — Game TV: pinned game tiles + per-game "how to watch" overrides
 * for the Bierstube kiosk (GASF-Tablets).
 *
 * The kiosk's TV → Watch grid shows schedule-driven game tiles (World Cup,
 * FC Bayern, …). Historically which HDMI input / DirecTV channel / Google TV
 * app each game used was HARDCODED in the tablet bundle — every new fixture
 * on an unusual channel meant a tablet code deploy. This module moves all of
 * that server-side:
 *
 *   1. PINNED GAME SEARCHES (option gasf_gametv_tiles) — each tile is a saved
 *      title-keyword search over upcoming gasf_event posts with a DEFAULT
 *      input + channel/app, timing windows, and optional per-keyword rules
 *      (e.g. Bayern games whose title contains "champions league" → Paramount+).
 *      Served to the kiosk at GET /wp-json/gasf-util/v1/pinned-games.
 *
 *   2. PER-GAME OVERRIDES — two post-meta fields on each matching event
 *      (_gasf_tv_input + _gasf_tv_channel, editable in a table on this tab)
 *      that ride the GASF-Events public feed as tv_input / tv_channel
 *      (GASF-Events ≥ 0.23.0). The kiosk resolves each game as:
 *      event override → tile rule → tile default.
 *
 * Needs the GASF-Events plugin (gasf_event CPT + its REST feed) to be useful,
 * but degrades gracefully without it (the tab just lists no games).
 *
 * Gate: gasf_site_enable_gametv.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_mec_enabled( 'gasf_site_enable_gametv' ) ) {

	/* ---------------------------------------------------------------------
	 * Vocabulary shared by the admin UI, the sanitizer, and the REST route.
	 * ------------------------------------------------------------------- */

	/** HDMI inputs the Bierstube AV rack knows how to route. */
	function gasf_gametv_inputs() {
		return array(
			'directv'  => 'DirecTV (Input 1)',
			'googletv' => 'Google TV (Input 4)',
			'roku'     => 'Roku (Input 3)',
			'fire'     => 'Fire TV (Input 2)',
		);
	}

	/** Tile color treatments that exist in the kiosk CSS. */
	function gasf_gametv_styles() {
		return array( 'gold' => 'Gold (World Cup)', 'red' => 'Bayern Red' );
	}

	/**
	 * Saved pinned tiles. Seeds the two historical tiles on first run so
	 * enabling the module reproduces the kiosk's existing behaviour exactly
	 * (World Cup → DirecTV ch 13; Bayern → Fandango app, Champions League
	 * fixtures → Paramount+).
	 */
	function gasf_gametv_tiles() {
		$tiles = get_option( 'gasf_gametv_tiles', null );
		if ( ! is_array( $tiles ) ) {
			$tiles = array(
				array(
					'id' => 'worldcup', 'label' => 'WORLD CUP', 'icon' => '🏆', 'style' => 'gold',
					'keyword' => 'world cup', 'input' => 'directv', 'channel' => '13',
					'lead_min' => 360, 'match_min' => 120, 'rollover_min' => 15, 'lookahead_days' => 30,
					'rules' => array(),
				),
				array(
					'id' => 'bayern', 'label' => 'FC BAYERN', 'icon' => '⚽', 'style' => 'red',
					'keyword' => 'bayern', 'input' => 'googletv', 'channel' => 'fandango',
					'lead_min' => 90, 'match_min' => 120, 'rollover_min' => 15, 'lookahead_days' => 60,
					'rules' => array(
						array( 'match' => 'champions league', 'input' => 'googletv', 'channel' => 'paramount' ),
					),
				),
			);
			update_option( 'gasf_gametv_tiles', $tiles, false );
		}
		return $tiles;
	}

	/** One tile, sanitized from a posted row. Returns null if the row is empty. */
	function gasf_gametv_sanitize_tile( $row ) {
		$label   = trim( sanitize_text_field( $row['label'] ?? '' ) );
		$keyword = strtolower( trim( sanitize_text_field( $row['keyword'] ?? '' ) ) );
		if ( '' === $label && '' === $keyword ) { return null; } // untouched blank "add" row
		$inputs = gasf_gametv_inputs();
		$styles = gasf_gametv_styles();
		$input  = sanitize_key( $row['input'] ?? '' );
		$style  = sanitize_key( $row['style'] ?? '' );

		// Rules textarea: one per line, "title keyword | input | channel/app".
		$rules = array();
		foreach ( preg_split( '/[\r\n]+/', (string) ( $row['rules'] ?? '' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) < 3 || '' === $parts[0] ) { continue; }
			$r_input = sanitize_key( $parts[1] );
			if ( ! isset( $inputs[ $r_input ] ) ) { continue; }
			$rules[] = array(
				'match'   => strtolower( sanitize_text_field( $parts[0] ) ),
				'input'   => $r_input,
				'channel' => sanitize_text_field( $parts[2] ),
			);
		}

		return array(
			'id'             => sanitize_key( $row['id'] ?? '' ) ?: sanitize_title( $label ),
			'label'          => $label ?: strtoupper( $keyword ),
			'icon'           => trim( sanitize_text_field( $row['icon'] ?? '' ) ) ?: '⚽',
			'style'          => isset( $styles[ $style ] ) ? $style : 'gold',
			'keyword'        => $keyword,
			'input'          => isset( $inputs[ $input ] ) ? $input : 'directv',
			'channel'        => trim( sanitize_text_field( $row['channel'] ?? '' ) ),
			'lead_min'       => max( 5, (int) ( $row['lead_min'] ?? 90 ) ),
			'match_min'      => max( 30, (int) ( $row['match_min'] ?? 120 ) ),
			'rollover_min'   => max( 0, (int) ( $row['rollover_min'] ?? 15 ) ),
			'lookahead_days' => max( 1, (int) ( $row['lookahead_days'] ?? 30 ) ),
			'rules'          => $rules,
		);
	}

	/**
	 * Upcoming published gasf_event posts whose title contains the keyword,
	 * soonest first. Same query shape as [world_cup_schedule].
	 */
	/**
	 * What the tablet will actually do for one game — same order it uses:
	 * per-event override → first matching tile rule → tile default.
	 * Returns input, channel, and a human 'via' so the admin can see WHY.
	 */
	function gasf_gametv_resolve( $tile, $title, $ov_input, $ov_channel ) {
		if ( '' !== $ov_input ) {
			return array( 'input' => $ov_input, 'channel' => $ov_channel, 'via' => 'override' );
		}
		$t = strtolower( html_entity_decode( $title, ENT_QUOTES ) );
		foreach ( (array) ( $tile['rules'] ?? array() ) as $r ) {
			if ( '' !== $r['match'] && false !== strpos( $t, $r['match'] ) ) {
				return array( 'input' => $r['input'], 'channel' => $r['channel'], 'via' => 'rule "' . $r['match'] . '"' );
			}
		}
		return array( 'input' => $tile['input'], 'channel' => $tile['channel'], 'via' => 'tile default' );
	}

	function gasf_gametv_upcoming( $keyword, $limit = 15 ) {
		if ( '' === $keyword ) { return array(); }
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->prefix}posts p
			 JOIN {$wpdb->prefix}postmeta ts ON ts.post_id=p.ID AND ts.meta_key='_gasf_start_ts'
			 WHERE p.post_type='gasf_event' AND p.post_status='publish'
			 AND p.post_title LIKE %s AND ts.meta_value+0 >= %d
			 ORDER BY ts.meta_value+0 ASC LIMIT %d",
			'%' . $wpdb->esc_like( $keyword ) . '%', time(), $limit ) );
		return array_map( 'intval', $ids );
	}

	/* ---------------------------------------------------------------------
	 * Public REST route — the kiosk's tile configuration.
	 * GET /wp-json/gasf-util/v1/pinned-games  →  { "tiles": [ … ] }
	 * Read-only; per-event overrides ride the GASF-Events feed itself.
	 * ------------------------------------------------------------------- */
	add_action( 'rest_api_init', function () {
		register_rest_route( 'gasf-util/v1', '/pinned-games', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function () {
				$resp = new WP_REST_Response( array( 'tiles' => array_values( gasf_gametv_tiles() ) ) );
				$resp->header( 'Cache-Control', 'public, max-age=120' );
				return $resp;
			},
		) );
	} );

	/* ---------------------------------------------------------------------
	 * Admin tab.
	 * ------------------------------------------------------------------- */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) {
			gasf_utilities_add_tab( 'gametv', 'Game TV', 'gasf_gametv_tab', 30 );
		}
	} );

	function gasf_gametv_tab() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$inputs = gasf_gametv_inputs();
		$styles = gasf_gametv_styles();

		/* ---- save handlers ---- */
		if ( isset( $_POST['gasf_gametv_action'] ) && check_admin_referer( 'gasf_gametv' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_gametv_action'] ) );

			if ( 'save_tiles' === $act ) {
				$posted = isset( $_POST['tile'] ) && is_array( $_POST['tile'] ) ? wp_unslash( $_POST['tile'] ) : array();
				$tiles  = array();
				foreach ( $posted as $row ) {
					if ( ! empty( $row['delete'] ) ) { continue; }
					$t = gasf_gametv_sanitize_tile( $row );
					if ( $t && '' !== $t['keyword'] ) { $tiles[] = $t; }
				}
				update_option( 'gasf_gametv_tiles', $tiles, false );
				echo '<div class="notice notice-success is-dismissible"><p>Pinned games saved — ' . count( $tiles ) . ' tile(s). The kiosk picks the change up within 15 minutes (or reload the kiosk page).</p></div>';
			}

			if ( 'save_overrides' === $act ) {
				$ov      = isset( $_POST['ov'] ) && is_array( $_POST['ov'] ) ? wp_unslash( $_POST['ov'] ) : array();
				$changed = 0;
				foreach ( $ov as $post_id => $pair ) {
					$post_id = (int) $post_id;
					if ( ! $post_id || 'gasf_event' !== get_post_type( $post_id ) ) { continue; }
					$in = sanitize_key( $pair['input'] ?? '' );
					$ch = trim( sanitize_text_field( $pair['channel'] ?? '' ) );
					if ( ! isset( $inputs[ $in ] ) ) { $in = ''; }
					// Blank input = "use default": clear both metas so the feed
					// emits '' and the kiosk falls back to the tile.
					$old_in = (string) get_post_meta( $post_id, '_gasf_tv_input', true );
					$old_ch = (string) get_post_meta( $post_id, '_gasf_tv_channel', true );
					if ( '' === $in ) {
						if ( '' !== $old_in || '' !== $old_ch ) { $changed++; }
						delete_post_meta( $post_id, '_gasf_tv_input' );
						delete_post_meta( $post_id, '_gasf_tv_channel' );
					} else {
						if ( $old_in !== $in || $old_ch !== $ch ) { $changed++; }
						update_post_meta( $post_id, '_gasf_tv_input', $in );
						update_post_meta( $post_id, '_gasf_tv_channel', $ch );
					}
				}
				echo '<div class="notice notice-success is-dismissible"><p>Per-game overrides saved — ' . (int) $changed . ' game(s) changed. The kiosk sees them on its next feed poll (≤ 5 minutes).</p></div>';
			}
		}

		echo '<h2>Game TV — kiosk game tiles &amp; channels</h2>';
		if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
			gasf_utilities_doc_panel( array(
				'what'   => 'Controls the schedule-driven game tiles on the Bierstube tablet\'s TV screen. A <strong>pinned game</strong> is a saved search (by event-title keyword) with a default way to watch — which HDMI input and which channel or app. The tablet shows a big tile for the next matching game and one tap powers the AV rack, routes the HDMI matrix, and tunes the channel / launches the app. When a specific game airs somewhere unusual, set a <strong>per-game override</strong> below — no tablet redeploy needed for any of this.',
				'needs'  => array(
					'The GASF-Events plugin (its <code>gasf_event</code> posts and public feed carry the per-game override to the tablet).',
					'GASF-Tablets ≥ the "server-driven game tiles" build on the Bierstube kiosk.',
				),
				'fields' => array(
					'Label / Icon / Color' => 'What the kiosk tile shows: big label (e.g. <code>FC BAYERN</code>), an emoji, and the tile\'s color treatment.',
					'Title keyword'        => 'Case-insensitive match against upcoming event titles (e.g. <code>bayern</code> matches every FC Bayern watch party). The next matching event becomes the tile.',
					'Default input'        => 'Which HDMI source the AV rack routes when the tile is tapped: DirecTV, Google TV, Roku, or Fire TV.',
					'Default channel/app'  => 'DirecTV → a channel number (e.g. <code>13</code>). Google TV → an app: <code>fandango</code>, <code>paramount</code>, or <code>youtube</code>. Roku / Fire TV → informational for now (the kiosk routes the input and opens the remote).',
					'Show / Game / Roll'   => 'Tile timing: appear <em>Show</em> minutes before kickoff; a game lasts <em>Game</em> minutes; the tile rolls to the next game <em>Roll</em> minutes after the scheduled end.',
					'Lookahead'            => 'How many days ahead the kiosk queries the events feed for this search.',
					'Rules'                => 'Optional, one per line: <code>title keyword | input | channel</code>. First matching rule beats the default (e.g. <code>champions league | googletv | paramount</code>). A per-game override below beats both.',
					'Per-game overrides'   => 'Every upcoming game each pinned search currently matches. Leave the input on "— default —" to follow the tile; pick an input + channel/app to override just that game (e.g. Supercup on DirecTV <code>242</code>).',
				),
				'notes'  => 'Resolution order on the tablet: <strong>per-game override → first matching rule → tile default</strong>. Config is served at <code>/wp-json/gasf-util/v1/pinned-games</code>; overrides ride the GASF-Events feed as <code>tv_input</code> / <code>tv_channel</code> (needs GASF-Events ≥ 0.23.0).',
			) );
		}

		$tiles = gasf_gametv_tiles();

		/* ---- pinned tiles editor ---- */
		?>
		<h3 class="title">Pinned games</h3>
		<form method="post">
			<?php wp_nonce_field( 'gasf_gametv' ); ?>
			<table class="widefat striped" style="max-width:1200px">
				<thead><tr>
					<th style="width:36px">Del</th><th>Label</th><th style="width:52px">Icon</th><th>Color</th><th>Title keyword</th>
					<th>Default input</th><th>Default channel/app</th>
					<th style="width:64px" title="Show tile this many minutes before kickoff">Show (min)</th>
					<th style="width:64px" title="Scheduled game length, minutes">Game (min)</th>
					<th style="width:60px" title="Roll to the next game this many minutes after scheduled end">Roll (min)</th>
					<th style="width:70px" title="How many days ahead to search the feed">Lookahead (d)</th>
					<th>Rules (one per line: <code>keyword | input | channel</code>)</th>
				</tr></thead>
				<tbody>
				<?php
				$rows = array_merge( $tiles, array( array() ) ); // + one blank "add" row
				foreach ( $rows as $i => $t ) :
					$is_new = empty( $t );
					$v = function ( $k, $d = '' ) use ( $t ) { return esc_attr( $t[ $k ] ?? $d ); };
					$rules_text = '';
					foreach ( (array) ( $t['rules'] ?? array() ) as $r ) {
						$rules_text .= $r['match'] . ' | ' . $r['input'] . ' | ' . $r['channel'] . "\n";
					}
				?>
					<tr<?php echo $is_new ? ' style="background:#f0f6fc"' : ''; ?>>
						<td style="text-align:center"><?php if ( ! $is_new ) : ?><input type="checkbox" name="tile[<?php echo (int) $i; ?>][delete]" value="1" title="Delete this tile"><?php endif; ?>
							<input type="hidden" name="tile[<?php echo (int) $i; ?>][id]" value="<?php echo $v( 'id' ); ?>"></td>
						<td><input type="text" name="tile[<?php echo (int) $i; ?>][label]" value="<?php echo $v( 'label' ); ?>" style="width:110px" placeholder="<?php echo $is_new ? 'NEW TILE…' : ''; ?>"></td>
						<td><input type="text" name="tile[<?php echo (int) $i; ?>][icon]" value="<?php echo $v( 'icon' ); ?>" style="width:36px" maxlength="4"></td>
						<td><select name="tile[<?php echo (int) $i; ?>][style]">
							<?php foreach ( $styles as $sk => $sl ) : ?><option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $t['style'] ?? 'gold', $sk ); ?>><?php echo esc_html( $sl ); ?></option><?php endforeach; ?>
						</select></td>
						<td><input type="text" name="tile[<?php echo (int) $i; ?>][keyword]" value="<?php echo $v( 'keyword' ); ?>" style="width:110px" placeholder="e.g. bayern"></td>
						<td><select name="tile[<?php echo (int) $i; ?>][input]">
							<?php foreach ( $inputs as $ik => $il ) : ?><option value="<?php echo esc_attr( $ik ); ?>" <?php selected( $t['input'] ?? 'directv', $ik ); ?>><?php echo esc_html( $il ); ?></option><?php endforeach; ?>
						</select></td>
						<td><input type="text" name="tile[<?php echo (int) $i; ?>][channel]" value="<?php echo $v( 'channel' ); ?>" style="width:90px" placeholder="13 / fandango"></td>
						<td><input type="number" name="tile[<?php echo (int) $i; ?>][lead_min]" value="<?php echo $v( 'lead_min', 90 ); ?>" style="width:60px" min="5"></td>
						<td><input type="number" name="tile[<?php echo (int) $i; ?>][match_min]" value="<?php echo $v( 'match_min', 120 ); ?>" style="width:60px" min="30"></td>
						<td><input type="number" name="tile[<?php echo (int) $i; ?>][rollover_min]" value="<?php echo $v( 'rollover_min', 15 ); ?>" style="width:55px" min="0"></td>
						<td><input type="number" name="tile[<?php echo (int) $i; ?>][lookahead_days]" value="<?php echo $v( 'lookahead_days', 30 ); ?>" style="width:55px" min="1"></td>
						<td><textarea name="tile[<?php echo (int) $i; ?>][rules]" rows="2" style="width:230px;font-family:monospace;font-size:11px" placeholder="champions league | googletv | paramount"><?php echo esc_textarea( trim( $rules_text ) ); ?></textarea></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button name="gasf_gametv_action" value="save_tiles" class="button button-primary">Save pinned games</button>
			<span class="description" style="margin-left:8px">The last (blue) row adds a new tile — fill in at least a keyword. Tick <strong>Del</strong> to remove a tile.</span></p>
		</form>

		<?php /* ---- per-game overrides ---- */ ?>
		<h3 class="title" style="margin-top:28px">Per-game overrides</h3>
		<p class="description" style="max-width:860px">Every upcoming game the pinned searches match right now. "— default —" follows the tile (or a matching rule); choosing an input overrides <em>just that game</em>. Overrides travel to the tablet via the events feed within ~5 minutes.</p>
		<form method="post">
			<?php wp_nonce_field( 'gasf_gametv' ); ?>
			<?php
			$any = false;
			foreach ( $tiles as $t ) :
				$ids = gasf_gametv_upcoming( $t['keyword'] );
				if ( ! $ids ) { continue; }
				$any = true;
				$default_desc = ( $inputs[ $t['input'] ] ?? $t['input'] ) . ( '' !== $t['channel'] ? ' · ' . $t['channel'] : '' );
			?>
				<h4 style="margin:16px 0 6px"><?php echo esc_html( $t['icon'] . ' ' . $t['label'] ); ?>
					<span style="font-weight:400;color:#646970">— default: <?php echo esc_html( $default_desc ); ?></span></h4>
				<table class="widefat striped" style="max-width:1180px">
					<thead><tr><th style="width:170px">Kickoff</th><th>Game</th><th style="width:190px">Input override</th><th style="width:150px">Channel/app</th><th style="width:230px">Resolves to</th></tr></thead>
					<tbody>
					<?php foreach ( $ids as $pid ) :
						$start = get_post_meta( $pid, '_gasf_start', true );
						$dt    = $start ? date_create_immutable_from_format( 'Y-m-d H:i:s', $start, wp_timezone() ) : false;
						$cur_in = (string) get_post_meta( $pid, '_gasf_tv_input', true );
						$cur_ch = (string) get_post_meta( $pid, '_gasf_tv_channel', true );
						$res    = gasf_gametv_resolve( $t, get_the_title( $pid ), $cur_in, $cur_ch );
						$res_label = ( $inputs[ $res['input'] ] ?? $res['input'] ) . ( '' !== $res['channel'] ? ' · ' . $res['channel'] : '' );
						$res_color = 'override' === $res['via'] ? '#d63638' : ( 'tile default' === $res['via'] ? '#646970' : '#2271b1' );
					?>
						<tr>
							<td><?php echo $dt ? esc_html( $dt->format( 'D, M j — g:i A' ) ) : '—'; ?></td>
							<td><a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>"><?php echo esc_html( get_the_title( $pid ) ); ?></a></td>
							<td><select name="ov[<?php echo (int) $pid; ?>][input]">
								<option value="">— default —</option>
								<?php foreach ( $inputs as $ik => $il ) : ?><option value="<?php echo esc_attr( $ik ); ?>" <?php selected( $cur_in, $ik ); ?>><?php echo esc_html( $il ); ?></option><?php endforeach; ?>
							</select></td>
							<td><input type="text" name="ov[<?php echo (int) $pid; ?>][channel]" value="<?php echo esc_attr( $cur_ch ); ?>" style="width:130px" placeholder="<?php echo esc_attr( $res['channel'] ); ?>"></td>
							<td style="color:<?php echo $res_color; ?>"><strong><?php echo esc_html( $res_label ); ?></strong>
								<span style="display:block;font-size:11px;opacity:.8">via <?php echo esc_html( $res['via'] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
			<?php if ( ! $any ) : ?>
				<p><em>No upcoming events match any pinned search. Overrides appear here as soon as matching events exist on the calendar.</em></p>
			<?php else : ?>
				<p style="margin-top:14px"><button name="gasf_gametv_action" value="save_overrides" class="button button-primary">Save per-game overrides</button></p>
			<?php endif; ?>
		</form>
		<?php
	}
}
