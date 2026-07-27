<?php
/**
 * Short Links — modules/32-shortlinks.php
 *
 * A lean branded URL shortener to replace URL Shortify. Unlike that plugin it
 * does NOT auto-create a short link for every page — you add only the ones you
 * want (e.g. /join, /75th). Each link picks a base domain from a configurable
 * dropdown, so short URLs can be branded across multiple domains (the redirect
 * itself resolves on whichever domain actually points at this site).
 *
 * Links live in option `gasf_shortlinks`; base domains in
 * `gasf_shortlink_bases`. Admin: GASF Utilities → Short Links.
 * Redirects are temporary (302/307) by default so targets stay re-pointable.
 *
 * Gate: gasf_site_enable_shortlinks (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_shortlinks' ) : true ) {

	function gasf_sl_get() { $r = get_option( 'gasf_shortlinks', array() ); return is_array( $r ) ? $r : array(); }
	function gasf_sl_save( $r ) { update_option( 'gasf_shortlinks', array_values( $r ), false ); }
	function gasf_sl_bases() {
		$b = get_option( 'gasf_shortlink_bases', array() );
		if ( ! is_array( $b ) || ! $b ) { $b = array( untrailingslashit( home_url() ) ); update_option( 'gasf_shortlink_bases', $b, false ); }
		return $b;
	}
	function gasf_sl_slug( $s ) { return trim( (string) strtok( (string) $s, '?#' ), "/ \t" ); }

	/* ---- redirect ---- */
	add_action( 'template_redirect', function () {
		$req = gasf_sl_slug( $_SERVER['REQUEST_URI'] ?? '' );
		if ( '' === $req || false !== strpos( $req, '/' ) ) { return; } // short links are single-segment
		foreach ( gasf_sl_get() as $i => $l ) {
			if ( 0 !== strcasecmp( gasf_sl_slug( $l['slug'] ?? '' ), $req ) ) { continue; }
			// continue, not return: a matched slug with an empty url must not
			// abort the whole handler — a later-indexed duplicate slug that DOES
			// have a url should still get its chance.
			if ( empty( $l['url'] ) ) { continue; }
			// Persist the click counter at most once/min per link (same throttle
			// as the redirects module): unauthenticated path, and every save
			// rewrites the whole option row — a crawler looping a QR link meant
			// a DB write per request. Burst clicks within the window go
			// uncounted; the counter is diagnostics, not analytics.
			if ( time() - (int) ( $l['last'] ?? 0 ) >= MINUTE_IN_SECONDS ) {
				$all = gasf_sl_get();
				$all[ $i ]['clicks'] = (int) ( $all[ $i ]['clicks'] ?? 0 ) + 1;
				$all[ $i ]['last']   = time();
				gasf_sl_save( $all );
			}
			wp_redirect( $l['url'], (int) ( $l['code'] ?? 307 ) );
			exit;
		}
	}, 2 );

	/* ---- admin ---- */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) {
			gasf_utilities_add_tab( 'shortlinks', 'Short Links', 'gasf_sl_admin', 40 );
		}
	} );

	function gasf_sl_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		if ( isset( $_POST['gasf_sl_action'] ) && check_admin_referer( 'gasf_sl' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_sl_action'] ) );
			if ( 'save' === $act ) {
				$slug = gasf_sl_slug( sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) ) );
				$slug = preg_replace( '/[^A-Za-z0-9_-]/', '', $slug ); // safe path segment, case preserved
				$url  = esc_url_raw( trim( (string) wp_unslash( $_POST['url'] ?? '' ) ) );
				$base = esc_url_raw( trim( (string) wp_unslash( $_POST['base'] ?? '' ) ) );
				$code = in_array( (int) ( $_POST['code'] ?? 307 ), array( 301, 302, 307 ), true ) ? (int) $_POST['code'] : 307;
				$orig = gasf_sl_slug( sanitize_text_field( wp_unslash( $_POST['orig'] ?? '' ) ) );
				// A shortlink fires at template_redirect before the page renders,
				// so a slug that collides with a real page SILENTLY shadows it —
				// /dinner-night the shortlink would eat /dinner-night the page,
				// and nothing anywhere would say why the page stopped loading.
				// Refuse the collision at save time, where it is cheap and visible.
				$collides = '' !== $slug && (
					url_to_postid( home_url( '/' . $slug . '/' ) )
					|| get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) )
				);
				if ( $collides ) {
					echo '<div class="notice notice-error"><p><code>/' . esc_html( $slug ) . '</code> is a real page or post on this site — a short link there would silently replace it. Pick a different slug.</p></div>';
				} elseif ( '' !== $slug && '' !== $url ) {
					$all = gasf_sl_get();
					// preserve clicks if editing an existing slug
					$clicks = 0;
					foreach ( $all as $l ) { if ( 0 === strcasecmp( gasf_sl_slug( $l['slug'] ?? '' ), $orig ?: $slug ) ) { $clicks = (int) ( $l['clicks'] ?? 0 ); } }
					$all = array_values( array_filter( $all, function ( $l ) use ( $slug, $orig ) {
						$s = gasf_sl_slug( $l['slug'] ?? '' );
						return 0 !== strcasecmp( $s, $slug ) && 0 !== strcasecmp( $s, $orig );
					} ) );
					$all[] = array( 'slug' => $slug, 'url' => $url, 'base' => $base ?: untrailingslashit( home_url() ), 'code' => $code, 'clicks' => $clicks, 'created' => time() );
					gasf_sl_save( $all );
					echo '<div class="notice notice-success is-dismissible"><p>Saved <code>/' . esc_html( $slug ) . '</code>.</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>Need a slug and a destination URL.</p></div>';
				}
			} elseif ( 'delete' === $act ) {
				$slug = gasf_sl_slug( sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) ) );
				gasf_sl_save( array_filter( gasf_sl_get(), function ( $l ) use ( $slug ) { return 0 !== strcasecmp( gasf_sl_slug( $l['slug'] ?? '' ), $slug ); } ) );
				echo '<div class="notice notice-success is-dismissible"><p>Deleted.</p></div>';
			} elseif ( 'addbase' === $act ) {
				$b = untrailingslashit( esc_url_raw( trim( (string) wp_unslash( $_POST['newbase'] ?? '' ) ) ) );
				if ( $b ) { $bs = gasf_sl_bases(); if ( ! in_array( $b, $bs, true ) ) { $bs[] = $b; update_option( 'gasf_shortlink_bases', $bs, false ); } echo '<div class="notice notice-success is-dismissible"><p>Base added: <code>' . esc_html( $b ) . '</code></p></div>'; }
			} elseif ( 'delbase' === $act ) {
				$b = untrailingslashit( esc_url_raw( trim( (string) wp_unslash( $_POST['base'] ?? '' ) ) ) );
				update_option( 'gasf_shortlink_bases', array_values( array_diff( gasf_sl_bases(), array( $b ) ) ), false );
				echo '<div class="notice notice-success is-dismissible"><p>Base removed.</p></div>';
			}
		}

		$links = gasf_sl_get();
		usort( $links, function ( $a, $b ) { return (int) ( $b['clicks'] ?? 0 ) <=> (int) ( $a['clicks'] ?? 0 ); } );
		$bases = gasf_sl_bases();
		$here  = untrailingslashit( home_url() );
		?>
		<h2>Short Links</h2>
		<?php
		if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
			gasf_utilities_doc_panel( array(
				'what'   => 'Branded short URLs for flyers, QR codes, and social posts — <code>germantampabay.com/join</code>, <code>gtbay.club/75th</code>, etc. Created by hand only (no auto-generated per-page links, unlike the old URL Shortify plugin). Each link counts its clicks. Because all your domains funnel to this one site, every short link automatically works on every domain.',
				'needs'  => array(
					'Nothing external. Extra domains (like <code>gtbay.club</code>) just need their DNS pointed/301\'d at this site — already true for all three.',
				),
				'fields' => array(
					'Base domain + slug' => 'The short URL. The domain dropdown is <em>display-only</em> (it controls which domain the copy button gives you — pick the shortest for print); the <strong>slug</strong> is the part after the slash, e.g. <code>join</code>. Case-insensitive. Keep it short and human: it will be read off flyers and spoken aloud.',
					'Destination URL'    => 'Where the visitor actually lands — any page on this site or an external URL (membership form, ticket page…). This is the only thing to change when a campaign moves.',
					'Redirect type'      => '<strong>307 Temporary (recommended)</strong> keeps browsers/Google re-checking, so you can re-point the slug later (e.g. /oktoberfest to each year\'s page). <strong>301 Permanent</strong> gets cached hard by browsers — use only for slugs that will never change destination.',
					'Clicks'             => 'Lifetime click count per link — a rough gauge of which flyers/QR codes actually get used.',
					'Copy buttons'       => 'One per base domain — copies the full short URL for that domain to your clipboard.',
					'Base domains list'  => 'The domains offered on the copy buttons. Add one here after pointing a new domain at the site; removing one never breaks existing links (they work on every domain regardless).',
				),
				'notes'  => 'Short links are for <em>outbound sharing</em>. For fixing old/broken URLs coming <em>into</em> the site, use the <strong>Redirects</strong> tab instead.',
			) );
		}
		?>

		<h3 class="title">Add / edit a short link</h3>
		<form method="post"><?php wp_nonce_field( 'gasf_sl' ); ?>
			<input type="hidden" name="orig" id="sl_orig" value="">
			<table class="form-table" role="presentation">
				<tr><th scope="row">Base domain</th><td><select name="base" id="sl_base"><?php foreach ( $bases as $b ) : ?><option value="<?php echo esc_attr( $b ); ?>"><?php echo esc_html( $b ); ?></option><?php endforeach; ?></select> <span class="description">/</span> <input type="text" name="slug" id="sl_slug" class="regular-text code" placeholder="join" style="width:180px"></td></tr>
				<tr><th scope="row">Destination URL</th><td><input type="url" name="url" id="sl_url" class="large-text code" placeholder="https://…"></td></tr>
				<tr><th scope="row">Redirect type</th><td><select name="code" id="sl_code"><option value="307">307 Temporary (recommended — re-pointable)</option><option value="302">302 Temporary</option><option value="301">301 Permanent</option></select></td></tr>
			</table>
			<p><button name="gasf_sl_action" value="save" class="button button-primary">Save short link</button> <button type="button" id="sl_reset" class="button" style="display:none">Cancel edit</button></p>
		</form>

		<h3 class="title">Short links (<?php echo count( $links ); ?>)</h3>
		<table class="widefat striped">
			<thead><tr><th>Short URL</th><th>Destination</th><th>Type</th><th>Clicks</th><th></th></tr></thead>
			<tbody>
			<?php if ( ! $links ) : ?><tr><td colspan="5">No short links yet.</td></tr><?php else : foreach ( $links as $l ) : ?>
				<tr>
					<td><code>/<?php echo esc_html( $l['slug'] ); ?></code>
						<div style="margin-top:5px;display:flex;flex-wrap:wrap;gap:4px">
						<?php foreach ( $bases as $b ) : $u = untrailingslashit( $b ) . '/' . $l['slug']; $host = preg_replace( '#^https?://#', '', untrailingslashit( $b ) ); ?>
							<button type="button" class="button button-small gasf-sl-copy" data-u="<?php echo esc_attr( $u ); ?>" title="Copy <?php echo esc_attr( $u ); ?>">⧉ <?php echo esc_html( $host ); ?>/…</button>
						<?php endforeach; ?>
						</div>
					</td>
					<td><small><?php echo esc_html( mb_substr( (string) ( $l['url'] ?? '' ), 0, 60 ) ); ?></small></td>
					<td><?php echo (int) ( $l['code'] ?? 307 ); ?></td>
					<td><?php echo (int) ( $l['clicks'] ?? 0 ); ?></td>
					<td style="white-space:nowrap">
						<button type="button" class="button button-small gasf-sl-edit"
							data-slug="<?php echo esc_attr( $l['slug'] ); ?>" data-url="<?php echo esc_attr( $l['url'] ?? '' ); ?>"
							data-base="<?php echo esc_attr( $l['base'] ?? '' ); ?>" data-code="<?php echo esc_attr( $l['code'] ?? 307 ); ?>">Edit</button>
						<form method="post" style="display:inline;margin:0" onsubmit="return confirm('Delete this short link?');"><?php wp_nonce_field( 'gasf_sl' ); ?><input type="hidden" name="slug" value="<?php echo esc_attr( $l['slug'] ); ?>"><button name="gasf_sl_action" value="delete" class="button-link-delete">Delete</button></form>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>

		<h3 class="title" style="margin-top:24px">Domains</h3>
		<p class="description">Domains available on the copy buttons above. All of them resolve to this site (aliases 301 to <?php echo esc_html( $here ); ?>), so every short link works on every one — this list just controls the copy options.</p>
		<table class="widefat striped" style="max-width:560px"><tbody>
		<?php foreach ( $bases as $b ) : ?>
			<tr><td><code><?php echo esc_html( $b ); ?></code><?php echo untrailingslashit( $b ) === $here ? ' <span style="color:#1a7f37">● this site</span>' : ' <span style="color:#666">alias → redirects here</span>'; ?></td>
			<td style="text-align:right"><?php if ( untrailingslashit( $b ) !== $here ) : ?><form method="post" style="margin:0"><?php wp_nonce_field( 'gasf_sl' ); ?><input type="hidden" name="base" value="<?php echo esc_attr( $b ); ?>"><button name="gasf_sl_action" value="delbase" class="button-link-delete">Remove</button></form><?php endif; ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<form method="post" style="margin-top:8px"><?php wp_nonce_field( 'gasf_sl' ); ?><input type="url" name="newbase" class="regular-text code" placeholder="https://anotherdomain.com"> <button name="gasf_sl_action" value="addbase" class="button">Add base domain</button></form>

		<script>
		jQuery(function($){
			$('.gasf-sl-edit').on('click',function(){var b=$(this);$('#sl_orig').val(b.data('slug'));$('#sl_slug').val(b.data('slug'));$('#sl_url').val(b.attr('data-url'));$('#sl_base').val(b.attr('data-base'));$('#sl_code').val(b.attr('data-code'));$('#sl_reset').show();$('html,body').animate({scrollTop:0},250);});
			$('#sl_reset').on('click',function(){$('#sl_orig,#sl_slug,#sl_url').val('');$(this).hide();});
			$('.gasf-sl-copy').on('click',function(){navigator.clipboard&&navigator.clipboard.writeText($(this).data('u'));});
		});
		</script>
		<?php
	}
}
