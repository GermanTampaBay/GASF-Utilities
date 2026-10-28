<?php
/**
 * Internal Calendar Print — modules/27-gcal-print.php
 *
 * A print-friendly month grid rendered from the *Google Calendar*
 * (thegermanamericansociety@gmail.com) rather than the public gasf_event
 * posts. The Google Calendar holds everything: the synced public events
 * (tagged [GASF]/[GCESV]/[GASCF]) plus events staff add by hand directly in
 * Google (internal/private, untagged). This gives the club one printable
 * "all events" sheet — the internal counterpart to the public print at
 * /events/print/YYYY-MM/.
 *
 * Reads the calendar through the GASF-Events plugin's Google engine
 * (GASF_Events\Google_Calendar::api_get + the gasf_events_gcal option), so it
 * needs no extra auth. (Module 26 "Calendar Sync" was retired in v1.7.0 — the
 * ICS→Google sync now lives in GASF-Events → Feeds.) NOTE: this mu-plugin
 * loads BEFORE regular plugins, so the GASF-Events class is checked at call
 * time, never at file load.
 *
 * Access: it exposes private events, so it is gated behind a secret token —
 *   /internal-calendar/print/?key=SECRET                 (current month)
 *   /internal-calendar/print/YYYY-MM/?key=SECRET         (specific month)
 *   /?gasf_gcal_print=YYYY-MM&key=SECRET                 (rewrite-free fallback)
 * The token + links live on the "Internal Calendar" tab in GASF Utilities.
 *
 * Gate: gasf_site_enable_gcalprint (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_gcalprint' ) ) {

	if ( ! defined( 'GASF_GCALPRINT_KEY_OPTION' ) ) { define( 'GASF_GCALPRINT_KEY_OPTION', 'gasf_gcal_print_key' ); }

	/** GASF-Events (a regular plugin) loads after this mu-plugin — call-time check. */
	function gasf_gcalprint_gcal_ready() {
		return class_exists( 'GASF_Events\\Google_Calendar' )
			&& method_exists( 'GASF_Events\\Google_Calendar', 'api_get' )
			&& \GASF_Events\Google_Calendar::available();
	}

	/** Destination calendar id: GASF-Events global setting (legacy calsync fallback). */
	function gasf_gcalprint_calendar_id() {
		$g = (array) get_option( 'gasf_events_gcal', array() );
		if ( ! empty( $g['calendar_id'] ) ) { return (string) $g['calendar_id']; }
		$legacy = (array) get_option( 'gasf_calsync_settings', array() );
		return (string) ( $legacy['calendar_id'] ?? '' );
	}

	/* ---------- secret token ---------- */
	function gasf_gcalprint_key() {
		$k = (string) get_option( GASF_GCALPRINT_KEY_OPTION, '' );
		if ( $k === '' ) {
			$k = wp_generate_password( 40, false, false );
			update_option( GASF_GCALPRINT_KEY_OPTION, $k, false );
		}
		return $k;
	}

	/* ---------- routing ---------- */
	add_action( 'init', function () {
		gasf_gcalprint_key(); // ensure it exists
		add_rewrite_rule( '^internal-calendar/print/?$', 'index.php?gasf_gcal_print=current', 'top' );
		add_rewrite_rule( '^internal-calendar/print/([0-9]{4})-([0-9]{2})/?$', 'index.php?gasf_gcal_print=$matches[1]-$matches[2]', 'top' );
		if ( ! get_option( 'gasf_gcal_print_rw_v1' ) ) {
			flush_rewrite_rules( false );
			update_option( 'gasf_gcal_print_rw_v1', '1' );
		}
	} );
	add_filter( 'query_vars', function ( $v ) { $v[] = 'gasf_gcal_print'; return $v; } );

	add_action( 'template_redirect', 'gasf_gcalprint_maybe_render' );
	function gasf_gcalprint_maybe_render() {
		$m = get_query_var( 'gasf_gcal_print' );
		if ( ! $m && isset( $_GET['gasf_gcal_print'] ) ) {
			$m = sanitize_text_field( wp_unslash( $_GET['gasf_gcal_print'] ) );
		}
		if ( ! $m ) { return; } // not our request

		/* Secret-token gate (exposes private events — no token, no page). */
		$key  = gasf_gcalprint_key();
		$prov = isset( $_GET['key'] ) ? (string) wp_unslash( $_GET['key'] ) : '';
		if ( $prov === '' || ! hash_equals( $key, $prov ) ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Forbidden';
			exit;
		}

		if ( $m === 'current' || ! preg_match( '/^\d{4}-\d{2}$/', $m ) ) {
			$m = wp_date( 'Y-m' );
		}
		nocache_headers();
		status_header( 200 );
		gasf_gcalprint_render( $m, ! empty( $_GET['auto'] ) );
		exit;
	}

	/* ---------- data: fetch a month's events from the Google Calendar ---------- */
	/**
	 * @return array{days:array<string,array>,error:string} map Y-m-d => event rows.
	 */
	function gasf_gcalprint_fetch( \DateTimeInterface $grid_start, \DateTimeInterface $range_end ) {
		$out = array( 'days' => array(), 'error' => '' );
		if ( ! gasf_gcalprint_gcal_ready() ) { $out['error'] = 'GASF-Events plugin (Google engine) not available.'; return $out; }
		$cal = gasf_gcalprint_calendar_id();
		if ( ! $cal ) { $out['error'] = 'No destination calendar configured (Events → Feeds).'; return $out; }

		$enc  = rawurlencode( $cal );
		$page = null;
		$pages = 0;
		do {
			$params = 'singleEvents=true&orderBy=startTime&maxResults=2500'
				. '&timeMin=' . rawurlencode( $grid_start->format( 'c' ) )
				. '&timeMax=' . rawurlencode( $range_end->format( 'c' ) );
			if ( $page ) { $params .= '&pageToken=' . rawurlencode( $page ); }
			$resp = \GASF_Events\Google_Calendar::api_get( '/calendars/' . $enc . '/events?' . $params );
			if ( is_wp_error( $resp ) ) { $out['error'] = $resp->get_error_message(); return $out; }
			foreach ( (array) ( $resp['items'] ?? array() ) as $it ) {
				gasf_gcalprint_place( $it, $grid_start, $range_end, $out['days'] );
			}
			$page = $resp['nextPageToken'] ?? null;
		} while ( $page && ++$pages < 12 );

		foreach ( $out['days'] as $k => $rows ) {
			usort( $rows, function ( $a, $b ) { return $a['ts'] <=> $b['ts']; } );
			$out['days'][ $k ] = $rows;
		}
		return $out;
	}

	/** Place one Google event onto each grid day it covers. */
	function gasf_gcalprint_place( array $it, \DateTimeInterface $grid_start, \DateTimeInterface $range_end, array &$days ) {
		$tz = wp_timezone();
		$summary = trim( (string) ( $it['summary'] ?? '' ) );
		if ( $summary === '' ) { $summary = '(no title)'; }
		$is_managed = ( ( $it['extendedProperties']['private']['gasf_mgr'] ?? '' ) === 'calsync' );
		$cancelled  = ( ( $it['status'] ?? '' ) === 'cancelled' );

		try {
			if ( isset( $it['start']['date'] ) ) {
				// All-day; Google's end.date is exclusive.
				$s        = new \DateTimeImmutable( $it['start']['date'] . ' 00:00:00', $tz );
				$end_excl = new \DateTimeImmutable( ( $it['end']['date'] ?? $it['start']['date'] ) . ' 00:00:00', $tz );
				$last_day = $end_excl->modify( '-1 day' );
				if ( $last_day < $s ) { $last_day = $s; }
				$all_day  = true;
				$time_lbl = '';
			} else {
				$s        = ( new \DateTimeImmutable( (string) $it['start']['dateTime'] ) )->setTimezone( $tz );
				$e        = isset( $it['end']['dateTime'] ) ? ( new \DateTimeImmutable( (string) $it['end']['dateTime'] ) )->setTimezone( $tz ) : $s;
				$last_day = $e;
				$all_day  = false;
				$time_lbl = $s->format( 'g:ia' );
			}
		} catch ( \Exception $ex ) {
			return;
		}

		$cur  = $s < $grid_start ? \DateTimeImmutable::createFromInterface( $grid_start ) : $s;
		$cur  = $cur->setTime( 0, 0 );
		$stop = $last_day > $range_end ? \DateTimeImmutable::createFromInterface( $range_end ) : $last_day;
		$row  = array(
			'time'   => $time_lbl,
			'title'  => $summary,
			'manual' => ! $is_managed,
			'cancel' => $cancelled,
			'ts'     => $all_day ? ( $s->getTimestamp() - 1 ) : $s->getTimestamp(), // all-day sorts first
		);
		$guard = 0;
		while ( $cur <= $stop && $guard++ < 45 ) {
			$days[ $cur->format( 'Y-m-d' ) ][] = $row;
			$cur = $cur->modify( '+1 day' );
		}
	}

	/* ---------- render: standalone print sheet ---------- */
	function gasf_gcalprint_render( $month, $auto ) {
		$tz = wp_timezone();
		list( $y, $mo ) = array_map( 'intval', explode( '-', $month ) );
		$first = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $y, $mo ), $tz );
		$last  = $first->modify( 'last day of this month' );

		$start_dow  = (int) $first->format( 'w' );
		$grid_start = $first->modify( '-' . $start_dow . ' day' )->setTime( 0, 0 );

		$weeks = array();
		$cursor = $grid_start;
		$grid_end = $cursor;
		for ( $w = 0; $w < 6; $w++ ) {
			$row = array();
			for ( $i = 0; $i < 7; $i++ ) {
				$row[] = array( 'date' => $cursor, 'in_month' => ( (int) $cursor->format( 'n' ) === $mo ) );
				$grid_end = $cursor;
				$cursor   = $cursor->modify( '+1 day' );
			}
			$weeks[] = $row;
			if ( $cursor > $last && (int) $cursor->format( 'n' ) !== $mo ) { break; }
		}
		$range_end = $grid_end->setTime( 23, 59, 59 );

		$data  = gasf_gcalprint_fetch( $grid_start, $range_end );
		$byDay = $data['days'];

		$key      = gasf_gcalprint_key();
		$prev     = $first->modify( '-1 month' )->format( 'Y-m' );
		$next     = $first->modify( '+1 month' )->format( 'Y-m' );
		$base     = home_url( '/internal-calendar/print/' );
		$link     = function ( $ym ) use ( $base, $key ) { return esc_url( $base . $ym . '/?key=' . $key ); };
		$weekdays = array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' );
		$org      = get_bloginfo( 'name' );
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="robots" content="noindex,nofollow">
	<title><?php echo esc_html( wp_date( 'F Y', $first->getTimestamp() ) ); ?> — Internal Calendar</title>
	<style>
		@page { size: Letter landscape; margin: 0.4in; }
		* { box-sizing: border-box; }
		html,body { margin:0; padding:0; font-family: Arial, Helvetica, sans-serif; color:#000; }
		.p-head { display:flex; justify-content:space-between; align-items:baseline; margin:0 0 8px; }
		.p-head h1 { font-size:20pt; margin:0; }
		.p-head .org { font-size:10pt; color:#333; text-align:right; }
		.p-head .org b { display:block; font-size:11pt; color:#000; }
		table.p-cal { width:100%; border-collapse:collapse; table-layout:fixed; }
		table.p-cal th { font-size:9pt; text-align:left; padding:3px 4px; border:1px solid #000; background:#eee; }
		table.p-cal td { border:1px solid #000; vertical-align:top; height:1.35in; padding:2px 3px; overflow:hidden; }
		td.out { background:#f7f7f7; }
		td .d { font-size:10pt; font-weight:bold; }
		td.out .d { color:#aaa; }
		td .ev { font-size:7.5pt; line-height:1.1; margin-top:1px; overflow:hidden; overflow-wrap:break-word; display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2; line-clamp:2; }
		td .ev b { font-weight:bold; }
		td .ev.internal { color:#b3122b; } /* hand-added / private events stand out */
		td .ev.cancelled { text-decoration:line-through; color:#777; }
		.p-foot { margin-top:6px; font-size:8pt; color:#555; display:flex; justify-content:space-between; }
		.p-foot .legend b { color:#b3122b; }
		.err { color:#b3122b; font-size:11pt; padding:12px; border:1px solid #b3122b; margin:10px 0; }
		@media screen { body { background:#f3f3f3; } .sheet { background:#fff; max-width:11in; margin:16px auto; padding:0.4in; box-shadow:0 1px 6px rgba(0,0,0,.25); } .toolbar{ text-align:center; margin:12px; } .toolbar a{ margin:0 6px; } }
		@media print { .toolbar { display:none; } .sheet { box-shadow:none; margin:0; padding:0; max-width:none; } }
	</style>
</head>
<body>
	<div class="toolbar">
		<a href="<?php echo $link( $prev ); ?>">&#8249; <?php echo esc_html( wp_date( 'M Y', $first->modify( '-1 month' )->getTimestamp() ) ); ?></a>
		<button onclick="window.print()">Print</button>
		<a href="<?php echo $link( $next ); ?>"><?php echo esc_html( wp_date( 'M Y', $first->modify( '+1 month' )->getTimestamp() ) ); ?> &#8250;</a>
	</div>
	<div class="sheet">
		<div class="p-head">
			<h1><?php echo esc_html( wp_date( 'F Y', $first->getTimestamp() ) ); ?></h1>
			<span class="org"><b><?php echo esc_html( $org ); ?></b>Internal Calendar — all events (includes private)</span>
		</div>
		<?php if ( $data['error'] ) : ?>
			<div class="err">Could not load the Google Calendar: <?php echo esc_html( $data['error'] ); ?></div>
		<?php endif; ?>
		<table class="p-cal">
			<thead><tr><?php foreach ( $weekdays as $wd ) : ?><th><?php echo esc_html( $wd ); ?></th><?php endforeach; ?></tr></thead>
			<tbody>
				<?php foreach ( $weeks as $week ) : ?>
					<tr>
						<?php foreach ( $week as $cell ) :
							$k    = $cell['date']->format( 'Y-m-d' );
							$rows = $byDay[ $k ] ?? array();
							?>
							<td class="<?php echo $cell['in_month'] ? '' : 'out'; ?>">
								<div class="d"><?php echo esc_html( $cell['date']->format( 'j' ) ); ?></div>
								<?php foreach ( $rows as $e ) : ?>
									<div class="ev<?php echo $e['manual'] ? ' internal' : ''; ?><?php echo $e['cancel'] ? ' cancelled' : ''; ?>">
										<?php if ( $e['time'] !== '' ) : ?><b><?php echo esc_html( $e['time'] ); ?></b> <?php endif; ?><?php echo esc_html( $e['title'] ); ?>
									</div>
								<?php endforeach; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<div class="p-foot">
			<span class="legend"><b>Red</b> = added directly in Google (internal/private). Tagged <em>[GASF]/[GCESV]/[GASCF]</em> = synced from public/partner calendars.</span>
			<span>Internal print — <?php echo esc_html( $org ); ?></span>
		</div>
	</div>
	<?php if ( $auto ) : ?><script>window.addEventListener('load',function(){window.print();});</script><?php endif; ?>
</body>
</html>
		<?php
	}

	/* ---------- admin tab ---------- */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) {
			gasf_utilities_add_tab( 'gcal-print', 'Internal Calendar', 'gasf_gcalprint_admin_page', 51 );
		}
	} );

	function gasf_gcalprint_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		if ( isset( $_POST['gasf_gcalprint_regen'] ) && check_admin_referer( 'gasf_gcalprint_action' ) ) {
			update_option( GASF_GCALPRINT_KEY_OPTION, wp_generate_password( 40, false, false ), false );
			echo '<div class="notice notice-success is-dismissible"><p>Secret link regenerated — old links no longer work.</p></div>';
		}

		$key  = gasf_gcalprint_key();
		$this_month = wp_date( 'Y-m' );
		$pretty = home_url( '/internal-calendar/print/' . $this_month . '/?key=' . $key );
		$curr   = home_url( '/internal-calendar/print/?key=' . $key );
		$fallbk = home_url( '/?gasf_gcal_print=' . $this_month . '&key=' . $key );
		$cal    = gasf_gcalprint_calendar_id();
		?>
		<h2>Internal Calendar (print)</h2>
		<?php
		if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
			gasf_utilities_doc_panel( array(
				'what'   => 'A printable one-sheet month grid of <strong>every</strong> event on the internal Google Calendar — including events staff add by hand in Google that never appear on the public website (board meetings, hall holds, private rentals). Meant for the bulletin board and the office wall. It\'s the internal counterpart to the public print view at <code>/events/print/</code>.',
				'needs'  => array(
					'The <strong>GASF-Events</strong> plugin with its Google Calendar destination configured (Events &rarr; Feeds) — this reads the same Google Calendar via the same service account.',
					'The secret link below — the page is public-but-unguessable, so no WordPress login is needed to open or print it.',
				),
				'fields' => array(
					'Open internal calendar' => 'Opens the current month\'s grid in a new tab, ready to print.',
					'This month'             => 'A link pinned to the specific month shown — use when you need to print/share a particular month.',
					'Always current month'   => 'The evergreen link — it always renders whatever month it is when opened. This is the one to bookmark or pin in the office.',
					'Fallback link'          => 'Same page via query parameters instead of a pretty URL — only needed if permalinks ever misbehave.',
					'Regenerate secret link' => 'Invalidates ALL existing links and issues a new secret. Use if a link leaks beyond staff (it grants view access to private hall events, so treat it like a key). Everyone with the old bookmark will need the new one.',
				),
				'notes'  => 'Add <code>&amp;auto=1</code> to any link to pop the browser print dialog automatically on open — handy for a "print this every month" routine.',
			) );
		}
		?>
		<p>Reads Google Calendar <code><?php echo esc_html( $cal ?: '(not configured)' ); ?></code>.</p>
		<p><a href="<?php echo esc_url( $curr ); ?>" target="_blank" rel="noopener" class="button button-primary">Open internal calendar (current month) &#8599;</a></p>

		<h3 class="title">Secret link</h3>
		<p class="description">Anyone with this link can view the internal calendar (no login). Keep it private; regenerate below if it leaks.</p>
		<table class="form-table" role="presentation">
			<tr><th scope="row">This month</th><td><input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( $pretty ); ?>"></td></tr>
			<tr><th scope="row">Always current month</th><td><input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( $curr ); ?>"><p class="description">Bookmark this — it always opens the current month.</p></td></tr>
			<tr><th scope="row">Fallback (no pretty URLs)</th><td><input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( $fallbk ); ?>"></td></tr>
		</table>
		<form method="post" onsubmit="return confirm('Regenerate the secret link? Existing bookmarks will stop working.');">
			<?php wp_nonce_field( 'gasf_gcalprint_action' ); ?>
			<p><button type="submit" name="gasf_gcalprint_regen" value="1" class="button">Regenerate secret link</button></p>
		</form>
		<p class="description">Tip: add <code>&amp;auto=1</code> to any link to open the browser print dialog automatically.</p>
		<?php
	}
}
