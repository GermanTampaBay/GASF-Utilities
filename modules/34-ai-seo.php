<?php
/**
 * AI Event SEO — modules/34-ai-seo.php
 *
 * The in-house replacement for the old external "Yoast SEO access for Claude"
 * flow. Uses the Anthropic API (Claude) to write a meta description for every
 * event that lacks one, grouped by event name so all occurrences of e.g.
 * "Biergarten" share one description. Writes _gasf_seo_desc ONLY when it's
 * empty — never overwrites a hand-written description. The SEO module (30)
 * then serves it.
 *
 * Runs on demand (admin button) and a small daily cron backfill. The API key
 * lives server-side (autoload off) and is never emitted to the front end.
 *
 * Gate: gasf_site_enable_aiseo (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_aiseo' ) : true ) {

	function gasf_aiseo_cfg() {
		$c = wp_parse_args( (array) get_option( 'gasf_aiseo_config', array() ), array(
			'key'   => '',
			'model' => 'claude-haiku-4-5-20251001',
			'batch' => 12,
		) );
		// The site-wide key (Settings tab, option gasf_anthropic_key) wins; the
		// old per-module key stays as a fallback for installs that never set it.
		$g = (string) get_option( 'gasf_anthropic_key', '' );
		if ( '' !== $g ) { $c['key'] = $g; }
		return $c;
	}

	/** Call the Anthropic Messages API; return generated text or WP_Error. */
	function gasf_aiseo_call( $key, $model, $prompt ) {
		if ( ! $key ) { return new WP_Error( 'nokey', 'No Anthropic API key set.' ); }
		$r = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 30,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'      => $model,
				'max_tokens' => 200,
				'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
			) ),
		) );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = (int) wp_remote_retrieve_response_code( $r );
		$b    = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'api', 'Anthropic HTTP ' . $code . ': ' . ( $b['error']['message'] ?? substr( wp_remote_retrieve_body( $r ), 0, 140 ) ) );
		}
		return gasf_fix_club_name( trim( (string) ( $b['content'][0]['text'] ?? '' ) ) );
	}

	function gasf_aiseo_prompt( $title, $content ) {
		$content = wp_strip_all_tags( strip_shortcodes( (string) $content ) );
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );
		if ( mb_strlen( $content ) > 800 ) { $content = mb_substr( $content, 0, 800 ); }
		// Target 150 (not 155) so normal model overshoot still lands under Google's
		// ~155-char SERP truncation. gasf_seo_clip() is the hard backstop; this just
		// keeps the model close so the backstop rarely has to cut anything.
		return "Write ONE compelling SEO meta description for this event page at the German-American Society (formally German-American Society Friendship of Pinellas County), a German-American cultural club in Pinellas Park, Florida, in the Tampa Bay area.\n\n" .
			"Strict rules:\n" .
			"- If you name the club, call it the German-American Society. Never write German-American Society of Tampa Bay: that is not its name.\n" .
			"- HARD LIMIT: 150 characters maximum. Aim for 130-150. Shorter is fine; going over is not.\n" .
			"- Exactly one sentence. Plain text. No quotation marks, line breaks, or emojis.\n" .
			"- Must end on a complete word with normal end punctuation.\n\n" .
			"Event title: {$title}\nDetails: {$content}\n\n" .
			"Respond with ONLY the finished meta description (under 150 characters), nothing else.";
	}

	/** Distinct published-event titles with no description on any occurrence. */
	function gasf_aiseo_pending() {
		global $wpdb;
		return $wpdb->get_col(
			"SELECT DISTINCT p.post_title
			   FROM {$wpdb->posts} p
			  WHERE p.post_type='gasf_event' AND p.post_status='publish' AND p.post_title<>''
			    AND NOT EXISTS (
			        SELECT 1 FROM {$wpdb->postmeta} pm
			         WHERE pm.post_id=p.ID AND pm.meta_key='_gasf_seo_desc' AND pm.meta_value<>''
			    )
			  ORDER BY p.post_title ASC"
		);
	}

	/** Generate for up to $limit distinct titles; apply each to all its occurrences that lack a desc. */
	function gasf_aiseo_run( $limit ) {
		$cfg = gasf_aiseo_cfg();
		if ( ! $cfg['key'] ) { return array( 'done' => 0, 'errors' => array( 'No API key set.' ) ); }
		global $wpdb;

		// Failure backoff: a title that keeps erroring (refusal, dead key saved,
		// empty responses) must not camp at the head of the alphabetical queue
		// retrying daily forever — it both burns API calls and starves every
		// title after it. Exponential per-title backoff: 1d, 2d, 4d… capped 7d.
		$fails = (array) get_option( 'gasf_aiseo_fails', array() );
		$now   = time();
		$titles = array_values( array_filter( gasf_aiseo_pending(), function ( $t ) use ( $fails, $now ) {
			return empty( $fails[ $t ]['until'] ) || (int) $fails[ $t ]['until'] <= $now;
		} ) );
		$titles = array_slice( $titles, 0, max( 1, (int) $limit ) );

		$done = 0; $errors = array();
		$budget = microtime( true ) + 45; // stay under request limits
		foreach ( $titles as $title ) {
			if ( microtime( true ) > $budget ) { break; }

			// Recurring events: the series module creates a NEW post per
			// occurrence and doesn't copy _gasf_seo_desc, so every new
			// occurrence used to trigger a fresh (paid) API call — and sibling
			// occurrences ended up with divergent descriptions. If ANY
			// occurrence of this title already has a description, copy it to
			// the blanks instead of generating a new one. Any post status
			// counts as a donor — a past/trashed occurrence's copy is still
			// this title's description.
			$desc = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->posts} p
				   JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_gasf_seo_desc' AND pm.meta_value <> ''
				  WHERE p.post_type = 'gasf_event' AND p.post_title = %s
				  ORDER BY p.ID DESC LIMIT 1", $title
			) );

			if ( '' === $desc ) {
				$pid     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='gasf_event' AND post_status='publish' AND post_title=%s ORDER BY ID DESC LIMIT 1", $title ) );
				$content = $pid ? get_post( $pid )->post_content : '';
				$desc    = gasf_aiseo_call( $cfg['key'], $cfg['model'], gasf_aiseo_prompt( $title, $content ) );
				if ( is_wp_error( $desc ) ) { $errors[] = $title . ': ' . $desc->get_error_message(); $desc = ''; }
				else {
					$desc = trim( $desc, " \"'\n\r\t" );
					// Store clean: hard-cap so the DB never holds an over-length description
					// even if the model ignores the prompt. Same helper the SEO module serves
					// through (guarded — SEO module 30 loads before this one but stay safe).
					if ( '' !== $desc && function_exists( 'gasf_seo_clip' ) ) { $desc = gasf_seo_clip( $desc ); }
					if ( '' === $desc ) { $errors[] = $title . ': empty response'; }
				}
				if ( '' === $desc ) {
					$n = (int) ( $fails[ $title ]['n'] ?? 0 ) + 1;
					$fails[ $title ] = array( 'n' => $n, 'until' => $now + min( 7 * DAY_IN_SECONDS, DAY_IN_SECONDS * ( 2 ** ( $n - 1 ) ) ) );
					continue;
				}
			}

			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='gasf_event' AND post_status='publish' AND post_title=%s", $title ) ) as $id ) {
				if ( '' === (string) get_post_meta( $id, '_gasf_seo_desc', true ) ) { update_post_meta( $id, '_gasf_seo_desc', $desc ); }
			}
			unset( $fails[ $title ] );
			$done++;
		}

		// Drop backoff entries that have long expired (title renamed/deleted).
		foreach ( $fails as $t => $f ) {
			if ( (int) ( $f['until'] ?? 0 ) < $now - 30 * DAY_IN_SECONDS ) { unset( $fails[ $t ] ); }
		}
		update_option( 'gasf_aiseo_fails', $fails, false );

		update_option( 'gasf_aiseo_last', array( 'ts' => time(), 'done' => $done, 'errors' => $errors, 'remaining' => count( gasf_aiseo_pending() ) ), false );
		return array( 'done' => $done, 'errors' => $errors );
	}

	/* small daily backfill so new event names get covered automatically */
	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_aiseo_cron' ) ) { wp_schedule_event( time() + 900, 'daily', 'gasf_aiseo_cron' ); }
	} );
	add_action( 'gasf_aiseo_cron', function () {
		$c = gasf_aiseo_cfg();
		if ( $c['key'] ) { gasf_aiseo_run( 8 ); }
	} );

	/* ---- admin ---- */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) { gasf_utilities_add_tab( 'aiseo', 'Event SEO (AI)', 'gasf_aiseo_admin', 31 ); }
	} );

	function gasf_aiseo_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		if ( isset( $_POST['gasf_aiseo_action'] ) && check_admin_referer( 'gasf_aiseo' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_aiseo_action'] ) );
			if ( 'save' === $act ) {
				// Save to the RAW option (not gasf_aiseo_cfg(), which merges in the
				// site-wide key) so the global key never gets copied in here.
				$c = (array) get_option( 'gasf_aiseo_config', array() );
				$c['model'] = sanitize_text_field( wp_unslash( $_POST['model'] ?? ( $c['model'] ?? '' ) ) ) ?: 'claude-haiku-4-5-20251001';
				$c['batch'] = max( 1, min( 50, (int) ( $_POST['batch'] ?? 12 ) ) );
				update_option( 'gasf_aiseo_config', $c, false );
				echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
			} elseif ( 'test' === $act ) {
				$c = gasf_aiseo_cfg();
				$d = gasf_aiseo_call( $c['key'], $c['model'], gasf_aiseo_prompt( 'Euchre Night', 'Every third Thursday of the month. Cards, cold beer, and Gemütlichkeit. Free to play, all welcome.' ) );
				echo '<div class="notice notice-' . ( is_wp_error( $d ) ? 'error' : 'success' ) . '"><p>' . ( is_wp_error( $d ) ? esc_html( $d->get_error_message() ) : 'Sample: ' . esc_html( $d ) ) . '</p></div>';
			} elseif ( 'run' === $act ) {
				$res = gasf_aiseo_run( gasf_aiseo_cfg()['batch'] );
				echo '<div class="notice notice-' . ( empty( $res['errors'] ) ? 'success' : 'warning' ) . ' is-dismissible"><p>Generated ' . (int) $res['done'] . ' descriptions.' . ( ! empty( $res['errors'] ) ? ' Errors: ' . esc_html( implode( ' | ', array_slice( $res['errors'], 0, 3 ) ) ) : '' ) . '</p></div>';
			}
		}

		$c       = gasf_aiseo_cfg();
		$pending = gasf_aiseo_pending();
		global $wpdb;
		$withdesc = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.post_title) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_gasf_seo_desc' AND pm.meta_value<>'' WHERE p.post_type='gasf_event' AND p.post_status='publish'" );
		$last    = (array) get_option( 'gasf_aiseo_last', array() );
		?>
		<h2>Event SEO (AI)</h2>
		<?php
		if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
			gasf_utilities_doc_panel( array(
				'what'   => 'Uses Claude (Anthropic\'s AI) to write the ~155-character search-result meta description for every event that lacks one. Events are grouped by name — all "Biergarten" occurrences share one description — so hundreds of events need only dozens of AI calls. It fills blanks only and never overwrites a description you wrote by hand. The <strong>SEO</strong> tab\'s engine then serves what\'s written here.',
				'needs'  => array(
					'An <strong>Anthropic API key</strong> (from <code>console.anthropic.com</code>). Costs are pennies — each description is one small model call.',
					'Events on the GASF calendar; new event names are picked up automatically.',
				),
				'fields' => array(
					'Anthropic API key' => 'The <strong>site-wide</strong> Claude key — set it once on the <strong>Settings</strong> tab; this tab just shows whether one is saved.',
					'Model'             => 'Which Claude model writes the copy. The default Haiku model is fast, cheap, and plenty for meta descriptions; use a Sonnet model for richer phrasing at higher cost.',
					'Batch size'        => 'How many event names one click of Generate processes. Keep ≤ ~15 — each name is a live API call and too many can hit the page\'s time limit.',
					'Save'              => 'Stores model/batch (the API key lives on the Settings tab).',
					'Test (sample)'     => 'One throw-away API call with a sample event to confirm the key + model work. Nothing is saved to any event.',
					'Generate next N'   => 'Processes the next batch of blank event names and writes their descriptions. Click repeatedly to drain the backlog — the counter above shows what\'s left.',
				),
				'notes'  => 'A daily cron also backfills a few names automatically, so new recurring events get covered hands-free. Event structured data (JSON-LD) is separate — the GASF-Events plugin emits that.',
			) );
		}
		?>
		<table class="widefat striped" style="max-width:640px">
			<tr><td>Event names with a description</td><td><?php echo (int) $withdesc; ?></td></tr>
			<tr><td>Event names still needing one</td><td><strong><?php echo count( $pending ); ?></strong></td></tr>
			<?php if ( ! empty( $last['ts'] ) ) : ?><tr><td>Last run</td><td><?php echo esc_html( human_time_diff( (int) $last['ts'] ) ); ?> ago — generated <?php echo (int) ( $last['done'] ?? 0 ); ?><?php echo ! empty( $last['errors'] ) ? ' (' . count( $last['errors'] ) . ' errors)' : ''; ?></td></tr><?php endif; ?>
		</table>

		<h3 class="title">Settings</h3>
		<form method="post"><?php wp_nonce_field( 'gasf_aiseo' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Anthropic API key</th><td><?php echo $c['key'] ? '<span style="color:#1a7f37">● saved</span>' : '<span style="color:#b3122b">○ not set</span>'; ?> — site-wide key, managed on the <a href="<?php echo esc_url( admin_url( 'admin.php?page=gasf-utilities&tab=settings' ) ); ?>">Settings tab</a>.</td></tr>
				<tr><th scope="row">Model</th><td><input type="text" name="model" value="<?php echo esc_attr( $c['model'] ); ?>" class="regular-text code"><p class="description">Default <code>claude-haiku-4-5-20251001</code> — fast &amp; cheap for meta descriptions.</p></td></tr>
				<tr><th scope="row">Batch size</th><td><input type="number" name="batch" value="<?php echo (int) $c['batch']; ?>" min="1" max="50" class="small-text"> event names per click <span class="description">(keep ≤ ~15 to avoid request timeouts)</span></td></tr>
			</table>
			<p>
				<button name="gasf_aiseo_action" value="save" class="button button-primary">Save</button>
				<button name="gasf_aiseo_action" value="test" class="button">Test (sample, no save)</button>
				<button name="gasf_aiseo_action" value="run" class="button button-primary">Generate next <?php echo (int) $c['batch']; ?></button>
			</p>
		</form>
		<?php if ( $pending ) : ?>
		<p class="description">Click <strong>Generate</strong> repeatedly to work through the list — ~<?php echo count( $pending ); ?> names left (~<?php echo (int) ceil( count( $pending ) / max( 1, $c['batch'] ) ); ?> clicks). A daily cron also backfills a few automatically.</p>
		<?php else : ?>
		<p><em>All event names have descriptions. 🎉</em></p>
		<?php endif; ?>
		<?php
	}
}
