<?php
/**
 * [gas_parking] — centralized 'Getting Here, Parking & Transit' block for event pages.
 * Single source of truth. Styled for LIGHT page backgrounds. Gate gasf_site_enable_parking.
 *
 * Attributes:
 *   mode="full" (default) — festival version: capacity warning, tow list, overflow + map.
 *   mode="simple"         — low-key version: address + free parking + bus only (no warnings).
 *   map="<image url>"      — override the parking/overflow map image (full mode only).
 *   fri="A, B, C"          — neighbouring lots this event may use on Friday.
 *   sat="A, B, C, D"       — the same for Saturday.
 *
 * The permitted lots are PER EVENT and deliberately not baked in here. This
 * block is shared by every event page on the site, and permission to park in a
 * neighbour's lot is given for particular days by particular businesses — the
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
		$permitted = '';
		$days      = array_filter( array(
			'Friday'   => trim( (string) $atts['fri'] ),
			'Saturday' => trim( (string) $atts['sat'] ),
		) );
		if ( $days ) {
			$lines = array();
			foreach ( $days as $day => $lots ) {
				$lines[] = '<strong>' . esc_html( $day ) . ':</strong> ' . esc_html( $lots );
			}
			$permitted = '<p style="background:#eef7ee;border-left:4px solid #2e7d32;padding:10px 14px;margin:14px 0;color:#2b2b2b">'
				. '<strong>Nearby lots you may use.</strong> These neighbours have given us permission for this event:<br>'
				. implode( '<br>', $lines )
				. '</p>';
		}

		// ---- SIMPLE: low-key events that don't tax parking ----
		if ( $atts['mode'] === 'simple' ) {
			ob_start(); ?>
<div class="gas-parking-info" style="margin:18px 0;line-height:1.5">
  <h2 style="margin-top:0">&#128658; Getting Here &amp; Parking</h2>
  <p><?php echo $addr; ?></p>
  <p><strong>Free</strong> on-site parking. &#128652; Prefer transit? <strong>PSTA Route&nbsp;66</strong> stops right in front of the club — <a style="text-decoration:underline" href="<?php echo esc_url( $route ); ?>" target="_blank" rel="noopener">view the schedule &amp; map &raquo;</a></p>
  <?php echo $permitted; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html'd parts above. ?>
</div>
<?php
			return ob_get_clean();
		}

		// ---- FULL: festival/high-attendance events ----
		ob_start(); ?>
<div class="gas-parking-info" style="margin:18px 0;line-height:1.5">
  <h2 style="margin-top:0">&#128658; Getting Here, Parking &amp; Transit</h2>
  <p><?php echo $addr; ?></p>
  <p>Our events draw a big crowd and <strong>on-site parking fills up fast</strong>. We recommend arriving early or using <strong>Uber, Lyft, or a taxi</strong>. (Parking is always <strong>free</strong>.)</p>
  <p>&#128652; <strong>Take the bus:</strong> PSTA <strong>Route 66</strong> runs right along 66th Street North with a <strong>stop directly in front of the club</strong>. It connects Largo Transit Center and downtown St.&nbsp;Petersburg (Grand Central Station), about every 30 minutes on weekdays and Saturdays (hourly on Sundays). <a style="text-decoration:underline" href="<?php echo esc_url( $route ); ?>" target="_blank" rel="noopener">View the Route&nbsp;66 schedule &amp; map &raquo;</a></p>
  <p style="background:#fdf2f2;border-left:4px solid #c0392b;padding:10px 14px;margin:14px 0;color:#2b2b2b"><strong>Do not park</strong> at the Zimring office park (north of the venue), at 8200 66th Street North (Southern Technical Institute / Caf&eacute; on the Bayou), or at the <strong>Shoppes at 66</strong>. <strong>You will be towed.</strong></p>
  <?php echo $permitted; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html'd parts above. ?>
  <p>For <strong>overflow parking</strong>, use the medical complex at the corner of <strong>66th Street North &amp; 78th Ave North</strong>.</p>
  <p><img src="<?php echo esc_url( $atts['map'] ); ?>" alt="Parking map" style="max-width:100%;height:auto;border-radius:8px" /></p>
</div>
<?php
		return ob_get_clean();
	} );
}
