<?php
/**
 * [gas_parking] — centralized 'Getting Here, Parking & Transit' block for event pages.
 * Single source of truth. Styled for LIGHT page backgrounds. Gate gasf_site_enable_parking.
 *
 * Attributes:
 *   mode="full" (default) — festival version: capacity warning, tow list, overflow + map.
 *   mode="simple"         — low-key version: address + free parking + bus only (no warnings).
 *   map="<image url>"      — the overflow map image (full mode only).
 *   map_fri="<image url>"  — a separate map shown only on Friday. Empty by
 *                            default, so pages with one map keep one map.
 *   fri="A, B, C"          — neighboring lots this event may use on Friday.
 *   sat="A, B, C, D"       — the same for Saturday.
 *   overflow_days="Saturday"
 *                          — the overflow lot is only open on these days. Empty
 *                            (the default) means always, which is what the five
 *                            pages already using this block have always said.
 *
 * ON THE DAY, only that day's parking is shown. That decision is made in the
 * browser, not here, and the reason is page caching: this block is rendered
 * once and served to everybody until the cache turns over, so a server-side
 * choice would bake Friday's lots into the page somebody reads on Saturday --
 * which is worse than showing both, because it is confidently wrong.
 *
 * The weekday is read in the CLUB'S timezone rather than the visitor's, so
 * somebody checking from another state still sees what applies at the venue.
 * With no JavaScript, every day is shown with its own label, which is simply
 * the behavior before this existed.
 *
 * The permitted lots are PER EVENT and deliberately not baked in here. This
 * block is shared by every event page on the site, and permission to park in a
 * neighbor's lot is given for particular days by particular businesses — the
 * Friday list and the Saturday list are genuinely different, and neither has
 * anything to do with Bock Fest. Pass them and they appear; leave them out and
 * the block is exactly what it was.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_parking' ) ) {
	add_shortcode( 'gas_parking', function ( $atts ) {
		$atts = shortcode_atts( array(
			'mode' => 'full',
			'map'  => 'https://germantampabay.com/wp-content/uploads/2026/02/2026-Sausage-fest-Overflow-Parking.webp',
			'fri'  => '',
			'sat'  => '',
			'overflow_days' => '',
			'map_fri'       => '',
		), $atts, 'gas_parking' );
		$gmap  = 'https://maps.app.goo.gl/1xSuisW1G7Z6puXLA';
		$route = 'https://psta.net/routes/route-66/';
		$addr  = '<a style="text-decoration:underline" href="' . esc_url( $gmap ) . '" target="_blank" rel="noopener"><strong>8098 66th Street North, Pinellas Park, FL 33781</strong></a>';

		/*
		 * Where you MAY park, in green, next to where you may not, in red.
		 *
		 * The tow warning below has always been the only list on this block, and
		 * a page that tells somebody four places they will be towed from and no
		 * place they are welcome has answered the easy half of the question. The
		 * days are listed separately because the permissions genuinely differ:
		 * one of these lots is open to us on Saturday and not on Friday, and a
		 * combined list would put a vendor's car somewhere it should not be.
		 */
		$overflow_day  = trim( (string) $atts['overflow_days'] );
		$overflow_lead = '' !== $overflow_day
			? '<strong>' . esc_html( $overflow_day ) . ' only:</strong> for <strong>overflow parking</strong>,'
			: 'For <strong>overflow parking</strong>,';

		/*
		 * One lot, one name.
		 *
		 * This sentence described the overflow purely by its location while the
		 * permitted list above named it -- so the page appeared to offer two
		 * different places, one of which a driver could not find on any sign.
		 * It is the same lot: Pinellas Professional Center, on that corner.
		 */

		$permitted = '';
		$days      = array_filter( array(
			'Friday'   => trim( (string) $atts['fri'] ),
			'Saturday' => trim( (string) $atts['sat'] ),
		) );
		if ( $days ) {
			$lines = array();
			foreach ( $days as $day => $lots ) {
				// A div per day, with no inline display, so hiding one with the
				// hidden attribute actually hides it. An inline display:block
				// would beat [hidden] and the day would stay on screen.
				$lines[] = '<div data-parking-day="' . esc_attr( $day ) . '" style="margin-top:4px">'
					. '<strong>' . esc_html( $day ) . ':</strong> ' . esc_html( $lots )
					. '</div>';
			}
			$permitted = '<div style="background:#eef7ee;border-left:4px solid #2e7d32;padding:10px 14px;margin:14px 0;color:#2b2b2b">'
				. '<p style="margin:0"><strong>Nearby lots you may use.</strong> These neighbors have given us permission for this event:</p>'
				. implode( '', $lines )
				. '</div>';
		}

		/*
		 * Hide the days that are not today.
		 *
		 * Runs in the browser because the page is cached; see the note at the
		 * top. Pinned to the club's timezone so a visitor in another state is
		 * told what applies at the venue rather than where they are sitting.
		 * Anything unexpected -- an old browser, a timezone database that does
		 * not know the zone -- leaves every day visible, which is informative
		 * rather than wrong.
		 */
		/*
		 * The links name their own color.
		 *
		 * This block is documented as being for LIGHT page backgrounds, and its
		 * text and panels all say what color they are -- but its three links
		 * said nothing and took the theme's, which is built for the dark pages
		 * everywhere else on the site. On the Oktoberfest page's cream that made
		 * the venue address white on near-white: the single most important line
		 * in the whole block, and the one a driver actually needs.
		 *
		 * Scoped to the block so it cannot reach the page around it, and set as
		 * a rule rather than three inline attributes so the next link added here
		 * is readable without anybody remembering this.
		 */
		$css = '<style>.gas-parking-info a{color:#7a4a00}.gas-parking-info a:hover,.gas-parking-info a:focus{color:#5a3600}</style>';

		$day_script = <<<'HTML'
<script>
(function () {
	var marked = document.querySelectorAll('[data-parking-day]');
	if (!marked.length) { return; }

	var today = '';
	try {
		today = new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', weekday: 'long' }).format(new Date());
	} catch (e) { return; }

	var named = false;
	Array.prototype.forEach.call(marked, function (el) {
		if (el.getAttribute('data-parking-day') === today) { named = true; }
	});
	if (!named) { return; }

	Array.prototype.forEach.call(marked, function (el) {
		el.hidden = el.getAttribute('data-parking-day') !== today;
	});
}());
</script>
HTML;

		// ---- SIMPLE: low-key events that don't tax parking ----
		if ( $atts['mode'] === 'simple' ) {
			ob_start();
			echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed literal.
			?>
<div class="gas-parking-info" style="margin:18px 0;line-height:1.5">
  <h2 style="margin-top:0">&#128658; Getting Here &amp; Parking</h2>
  <p><?php echo $addr; ?></p>
  <p><strong>Free</strong> on-site parking. &#128652; Prefer transit? <strong>PSTA Route&nbsp;66</strong> stops right in front of the club — <a style="text-decoration:underline" href="<?php echo esc_url( $route ); ?>" target="_blank" rel="noopener">view the schedule &amp; map &raquo;</a></p>
  <?php echo $permitted; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html'd parts above. ?>
</div>
<?php echo $day_script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed literal. ?>
<?php
			return ob_get_clean();
		}

		// ---- FULL: festival/high-attendance events ----
		ob_start();
		echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed literal.
		?>
<div class="gas-parking-info" style="margin:18px 0;line-height:1.5">
  <h2 style="margin-top:0">&#128658; Getting Here, Parking &amp; Transit</h2>
  <p><?php echo $addr; ?></p>
  <p>Our events draw a big crowd and <strong>on-site parking fills up fast</strong>. We recommend arriving early or using <strong>Uber, Lyft, or a taxi</strong>. (Parking is always <strong>free</strong>.)</p>
  <p>&#128652; <strong>Take the bus:</strong> PSTA <strong>Route 66</strong> runs right along 66th Street North with a <strong>stop directly in front of the club</strong>. It connects Largo Transit Center and downtown St.&nbsp;Petersburg (Grand Central Station), about every 30 minutes on weekdays and Saturdays (hourly on Sundays). <a style="text-decoration:underline" href="<?php echo esc_url( $route ); ?>" target="_blank" rel="noopener">View the Route&nbsp;66 schedule &amp; map &raquo;</a></p>
  <p style="background:#fdf2f2;border-left:4px solid #c0392b;padding:10px 14px;margin:14px 0;color:#2b2b2b"><strong>Do not park</strong> at the Zimring office park (north of the venue), at 8200 66th Street North (Southern Technical Institute / Caf&eacute; on the Bayou), or at the <strong>Shoppes at 66</strong>. <strong>You will be towed.</strong></p>
  <?php echo $permitted; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html'd parts above. ?>
  <?php if ( '' !== trim( (string) $atts['map_fri'] ) ) : ?>
    <p data-parking-day="Friday"><img src="<?php echo esc_url( $atts['map_fri'] ); ?>" alt="Friday parking map" style="max-width:100%;height:auto;border-radius:8px" /></p>
  <?php endif; ?>
  <p<?php echo '' !== $overflow_day ? ' data-parking-day="' . esc_attr( $overflow_day ) . '"' : ''; ?>><?php echo $overflow_lead; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from an esc_html'd part above. ?> use <strong>Pinellas Professional Center</strong> &mdash; the medical complex at the corner of <strong>66th Street North &amp; 78th Ave North</strong>.</p>
  <p<?php echo '' !== $overflow_day ? ' data-parking-day="' . esc_attr( $overflow_day ) . '"' : ''; ?>><img src="<?php echo esc_url( $atts['map'] ); ?>" alt="Overflow parking map" style="max-width:100%;height:auto;border-radius:8px" /></p>
</div>
<?php echo $day_script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed literal. ?>
<?php
		return ob_get_clean();
	} );
}
