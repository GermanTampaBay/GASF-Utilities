<?php
/**
 * The tagging page — modules/email-crm/photos-page.php
 *
 * What the person who sent us photos sees when they follow the link.
 *
 * Written for somebody who has never seen this site's admin, is quite possibly
 * on a phone, and is doing us a favour. So: no jargon, no account, no password,
 * one screen, and an explicit "you can ignore this" rather than a nag.
 *
 * Rendered standalone rather than through the theme, for the same reason /email
 * is: the club's header, hero and cookie banner would be in the way of a form
 * whose entire job is to be finished quickly.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @param string     $state  form | thanks | expired | unknown
 * @param array|null $invite
 * @param string     $notice
 */
function gasf_crm_photo_page( $state, $invite = null, $notice = '' ) {
	$org = gasf_crm_cfg()['signature_org'];

	echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<meta name="robots" content="noindex, nofollow">';
	echo '<title>About your photos &mdash; ' . esc_html( get_bloginfo( 'name' ) ) . '</title>';
	gasf_crm_photo_styles();
	echo '</head><body>';

	echo '<header class="bar"><div class="wrap"><h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1></div></header>';
	echo '<div class="wrap main">';

	// Ours, not theirs. Somebody who followed a link we sent should not be left
	// wondering whether their photos went astray, and should not be told to try
	// again — nothing they do will help until a volunteer turns the catalogue
	// back on.
	if ( 'unavailable' === $state ) {
		echo '<div class="card pad">';
		echo '<h2>We cannot take this just now</h2>';
		echo '<p>Something at our end is switched off, so the form for describing your photos is not available. This is our problem, not anything you did.</p>';
		echo '<p><strong>Your photos are safe with us</strong> &mdash; nothing has been lost. Please try the link again in a day or so, and it should work.</p>';
		echo '<p>If it still does not, reply to the email you had from us and we will sort it out.</p>';
		echo '</div></div></body></html>';
		return;
	}

	if ( in_array( $state, array( 'unknown', 'expired', 'used' ), true ) ) {
		// 'used' is its own message rather than being folded into 'expired'.
		// Somebody who filled the form in and clicked their own link again has
		// done nothing wrong, and telling them it expired would be a small lie
		// that invites them to ask for a replacement they do not need.
		$head = array(
			'expired' => 'That link has expired',
			'used'    => 'You have already told us about these',
			'unknown' => 'That link does not work',
		);
		$body = array(
			'expired' => esc_html( sprintf( 'Tagging links last %d days. Your photos are safe and still with us — nothing has been lost.', GASF_CRM_PHOTO_INVITE_DAYS ) ),
			'used'    => 'Thank you — your answers reached us, and the link closes once it has been used. Your photos are safe with us.',
			'unknown' => 'It may have been mistyped, or it may have already been used. Your photos are safe and still with us either way.',
		);

		echo '<div class="card pad">';
		echo '<h2>' . esc_html( $head[ $state ] ) . '</h2>';
		echo '<p>' . $body[ $state ] . '</p>';
		echo '<p>' . ( 'used' === $state
			? 'If you spotted a mistake or remembered another name, just reply to the email you had from us and we will put it right.'
			: 'If you would still like to tell us about them, just reply to the email you had from us and we will send a fresh link.' ) . '</p>';
		echo '</div></div></body></html>';
		return;
	}

	if ( 'thanks' === $state ) {
		echo '<div class="card pad">';
		echo '<h2>Thank you &mdash; that is genuinely useful</h2>';
		echo '<p>One of our volunteers will check it over and add it to the photos. You do not need to do anything else.</p>';
		echo '<p class="muted">A picture nobody can identify is a picture nobody can use. What you have just written is the difference.</p>';
		echo '</div></div></body></html>';
		return;
	}

	// The token that opened this page is what its images are served with.
	$tok = (string) ( $invite['token'] ?? '' );

	// Only photos that still exist AND still have a file. Asking somebody to
	// describe a broken image is asking them to do our debugging, and a public
	// page is the last place a missing file should surface.
	$ids = array_values( array_filter( (array) $invite['ids'], function ( $id ) {
		if ( 'attachment' !== get_post_type( $id ) ) { return false; }
		$p = get_attached_file( $id );
		return $p && file_exists( $p );
	} ) );

	if ( ! $ids ) {
		echo '<div class="card pad"><h2>Nothing to describe</h2><p>These photos are no longer in the collection, so there is nothing to fill in. Nothing is wrong on your side.</p></div></div></body></html>';
		return;
	}

	// Suggest a date only when the photos agree on one. Offering the first
	// photo's date for a batch spanning a weekend would be a confident wrong
	// answer, which is worse than an empty box.
	$dates = array();
	foreach ( $ids as $id ) {
		$d = get_post_meta( $id, '_gasf_photo_taken', true );
		if ( $d ) { $dates[ $d ] = true; }
	}
	$suggest_date = ( 1 === count( $dates ) ) ? key( $dates ) : '';

	// Same for place: only pre-select when every photo carrying GPS agrees.
	$guesses = array();
	foreach ( $ids as $id ) {
		$g = (int) get_post_meta( $id, '_gasf_photo_place_guess', true );
		if ( $g ) { $guesses[ $g ] = true; }
	}
	$suggest_place = ( 1 === count( $guesses ) ) ? (int) key( $guesses ) : ( function_exists( 'gasf_photo_home_place' ) ? gasf_photo_home_place() : 0 );

	$places = get_terms( array( 'taxonomy' => 'gasf_photo_place', 'hide_empty' => false ) );
	if ( is_wp_error( $places ) ) { $places = array(); }

	if ( $notice ) { echo '<div class="note">' . esc_html( $notice ) . '</div>'; }

	echo '<div class="card pad intro">';
	printf(
		'<h2>%s</h2>',
		esc_html( sprintf(
			'Thank you for the %s',
			1 === count( $ids ) ? 'photo' : count( $ids ) . ' photos'
		) )
	);
	echo '<p>They are safely in the club\'s collection. Could you tell us what we are looking at? It takes a minute, and it is what stops these becoming an unlabelled pile in ten years\' time.</p>';
	echo '<p class="muted">Every box is optional — fill in what you know and leave the rest. Nothing you write appears on the website straight away; one of our volunteers checks it first.</p>';
	echo '</div>';

	echo '<form method="post" class="tagform">';
	wp_nonce_field( 'gasf_phototag', '_gasf_pt' );
	echo '<input type="hidden" name="gasf_phototag_save" value="1">';

	/*
	 * Everything the pickers need, shipped with the page.
	 *
	 * The club's calendar is already public on the website, so putting it in
	 * this page exposes nothing — and doing it here rather than through a live
	 * endpoint keeps an unauthenticated page from taking queries.
	 */
	$ev_list = array();
	if ( function_exists( 'gasf_photo_has_calendar' ) && gasf_photo_has_calendar() ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, CAST(m.meta_value AS UNSIGNED) ts
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_gasf_start_ts'
			  WHERE p.post_type = %s AND p.post_status = 'publish'
			    AND CAST(m.meta_value AS UNSIGNED) >= %d
			  ORDER BY ts DESC LIMIT 600",
			GASF_EVENTS_CPT, time() - ( 4 * YEAR_IN_SECONDS )
		), ARRAY_A );

		$seen = array();
		foreach ( (array) $rows as $r ) {
			$ts  = (int) $r['ts'];
			$key = strtolower( $r['post_title'] ) . '|' . $ts;
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$ev_list[] = array(
				'id'    => (int) $r['ID'],
				'title' => (string) $r['post_title'],
				'date'  => wp_date( 'Y-m-d', $ts ),
				'when'  => wp_date( 'j M Y', $ts ),
			);
		}
	}

	// Places, with the geofenced branch lifted to the top. Built once and reused
	// by every photo card — the vocabulary does not change between them.
	//
	// Scoped to THIS invitation's photos. Every place each of them geofenced to,
	// not just the batch-level suggestion above, because that one is only set
	// when they all agree — a mixed batch would otherwise be offered nothing to
	// refine. The club's own venue comes along as the fallback so a batch with
	// no GPS at all still has something sensible to pick.
	$candidates = array();
	foreach ( $ids as $id ) {
		$g = (int) get_post_meta( $id, '_gasf_photo_place_guess', true );
		if ( $g ) { $candidates[ $g ] = true; }
	}
	if ( function_exists( 'gasf_photo_home_place' ) ) { $candidates[ gasf_photo_home_place() ] = true; }

	$tree = function_exists( 'gasf_photo_place_tree_public' ) ? gasf_photo_place_tree_public( array_keys( $candidates ), $suggest_place ) : array();

	// Parent names travel with the list so the form can tell a REFINEMENT of the
	// geofence ("the grounds" -> "the Bierstube") from a contradiction of it
	// ("the grounds" -> "England Brothers Park"). Only the second is a reason to
	// keep the machine's answer.
	$pl_names = array();
	foreach ( $tree as $row ) { $pl_names[ (int) $row['term']->term_id ] = $row['term']->name; }

	$pl_list = array();
	foreach ( $tree as $row ) {
		$pid = (int) $row['term']->parent;
		$pl_list[] = array(
			'name'   => $row['term']->name,
			'depth'  => (int) $row['depth'],
			'parent' => isset( $pl_names[ $pid ] ) ? $pl_names[ $pid ] : '',
		);
	}

	/*
	 * Names to suggest as the submitter types.
	 *
	 * The point is not convenience — it is that "Mike Tressler" and "Michael
	 * Tressler" are two different people to a taxonomy, and the submitter is the
	 * one person who cannot be asked to check the existing spelling first. Every
	 * variation they invent is a redundancy a volunteer has to merge later.
	 *
	 * Only names already on a PUBLIC photo, where they are printed in the title
	 * and alt text anyway. Shipping the whole taxonomy here would hand anyone
	 * holding a photo link a roster of club members — the same mistake the place
	 * picker made before it was scoped.
	 */
	$people = function_exists( 'gasf_photo_public_people' ) ? gasf_photo_public_people() : array();

	printf(
		'<script>var GASF_EVENTS=%s,GASF_PLACES=%s,GASF_PEOPLE=%s;</script>',
		wp_json_encode( $ev_list ),
		wp_json_encode( $pl_list ),
		wp_json_encode( $people )
	);

	// The shared matcher, so this form and the volunteers' behave identically.
	echo '<script>' . gasf_photo_matcher_js() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput

	/*
	 * One set of fields per photo, not one for the batch.
	 *
	 * Six photos emailed together are often one afternoon, but "often" is not
	 * "always" — somebody clearing out their phone sends six different days from
	 * three different places, and a batch-only form quietly records all six as
	 * whatever the first one was. Wrong metadata is worse than none, because it
	 * looks like an answer.
	 *
	 * So each photo carries its own date, place and occasion. The FIRST photo's
	 * answers flow down into the others as editable defaults, which keeps the
	 * common case to one set of decisions — and a field stops following the
	 * moment somebody touches it, so correcting photo one later never overwrites
	 * an answer already given for photo four.
	 *
	 * A photo's own EXIF date and geofenced place still win over the inherited
	 * default: real evidence about this picture beats a guess copied from a
	 * different one.
	 */
	echo '<div class="card pad">';
	echo '<h3>The first one sets the rest</h3>';
	echo '<p class="muted" style="margin:0">Fill photo 1 in and the others start with the same answers &mdash; change any of them that differ. If a photo carried its own date or location we use that instead.</p>';

	echo '</div>';

	/* ---- one block per photo, each complete in itself ---- */
	foreach ( $ids as $i => $id ) {
		$own_date  = (string) get_post_meta( $id, '_gasf_photo_taken', true );
		$own_place = (int) get_post_meta( $id, '_gasf_photo_place_guess', true );
		$own_place = $own_place ? get_term( $own_place, 'gasf_photo_place' ) : null;
		$place_val = ( $own_place && ! is_wp_error( $own_place ) ) ? $own_place->name : '';

		printf( '<div class="card photo" data-photo="%d"%s>', (int) $id, 0 === $i ? ' data-first="1"' : '' );
		// Both URLs go through the token handler, not the uploads folder: an
		// unreviewed photo has no public address, and the token in the path is
		// the same one that opened this page.
		printf(
			'<a class="thumb" href="%s" target="_blank" rel="noopener" title="%s">' .
			'<img src="%s" alt="" loading="lazy"><span class="zoom">Tap to see it full size</span></a>',
			esc_url( gasf_crm_photo_img_url( $id, 'full', $tok ) ),
			esc_attr__( 'Open full size in a new tab', 'gasf' ),
			esc_url( gasf_crm_photo_img_url( $id, 'medium', $tok ) )
		);
		echo '<div class="pad fields">';
		printf( '<h3>Photo %d of %d</h3>', (int) $i + 1, count( $ids ) );

		// Date. data-own marks a value that came from the photo itself, which
		// the inheritance must not overwrite.
		// data-orig records what the camera said, so a correction to photo one
		// can be recognised as fixing a shared wrong clock and carried across,
		// while a photo that genuinely came from another day keeps its own.
		printf(
			'<label class="f"><span>When was it taken?</span>' .
			'<input type="date" class="f-date" name="photo[%d][taken]" value="%s" max="%s"%s>' .
			'<em>%s</em></label>',
			(int) $id,
			esc_attr( $own_date ),
			esc_attr( gmdate( 'Y-m-d' ) ),
			$own_date ? ' data-orig="' . esc_attr( $own_date ) . '"' : '',
			$own_date ? 'Read from the photo itself — change it if it looks wrong.' : 'A rough date is much better than none.'
		);

		// Where. Hierarchical rows, because GPS can put a photo on the grounds
		// but never in a particular room.
		echo '<div class="f"><span>Where?</span>';
		if ( $place_val ) {
			printf( '<p class="hint">The camera put this at <strong>%s</strong>. Say which part if you know.</p>', esc_html( function_exists( 'gasf_photo_label' ) ? gasf_photo_label( $place_val ) : $place_val ) );
		}
		// A dropdown, not a stack of radios. Eight places times six photos was
		// forty-eight rows of form; the hierarchy survives as indentation in the
		// option text, which is what it was there to convey.
		if ( $tree ) {
			printf(
				'<select class="f-place" name="photo[%d][place]"%s>',
				(int) $id,
				$place_val ? ' data-own="' . esc_attr( $place_val ) . '"' : ''
			);
			echo '<option value="">— not sure —</option>';
			foreach ( $tree as $row ) {
				$term = $row['term'];
				printf(
					// value keeps the STORED form so the term matches itself on
					// the way back; only the label is decoded for reading.
					'<option value="%s"%s>%s%s%s</option>',
					esc_attr( $term->name ),
					selected( $term->name, $place_val, false ),
					esc_html( str_repeat( "\xC2\xA0", 4 * min( 2, (int) $row['depth'] ) ) ),
					esc_html( function_exists( 'gasf_photo_label' ) ? gasf_photo_label( $term->name ) : $term->name ),
					// Only where it distinguishes something. On the grounds, which
					// contain rooms, "anywhere" separates it from "the Bierstube".
					// On England Brothers Park it answered a question nobody asked.
					! empty( $row['haskids'] ) ? esc_html__( ' (anywhere)', 'gasf' ) : ''
				);
			}
			echo '<option value="__other">Somewhere else…</option>';
			echo '</select>';
		}
		printf( '<input type="text" class="f-placeother" name="photo[%d][place_other]" maxlength="120" placeholder="Where was it?" hidden>', (int) $id );
		echo '</div>';

		// Occasion — the club's own calendar, searched. Whatever the date
		// matched goes above the search box, because 98% of the time it is the
		// answer and should not need looking for.
		echo '<div class="f"><span>What was the occasion?</span>';
		echo '<div class="evlist" data-for="' . (int) $id . '"></div>';
		printf(
			'<input type="text" class="f-evsearch" placeholder="%s" autocomplete="off" data-for="%d">',
			esc_attr__( 'Search for an event', 'gasf' ),
			(int) $id
		);
		echo '<button type="button" class="evopt evfree">Not at an event &mdash; let me type it</button>';
		printf( '<input type="text" class="f-evfree" name="photo[%d][event_other]" maxlength="120" placeholder="What was it?" hidden>', (int) $id );
		printf( '<input type="hidden" class="f-event" name="photo[%d][event]" value="">', (int) $id );
		printf( '<input type="hidden" class="f-eventid" name="photo[%d][event_id]" value="">', (int) $id );
		echo '</div>';

		echo '<div class="f"><span>Who is in it?</span>';
		echo '<div class="people" data-id="' . (int) $id . '">';
		printf( '<span class="pwrap"><input type="text" name="photo[%d][people][]" maxlength="80" placeholder="Name" autocomplete="off" spellcheck="false"></span>', (int) $id );
		echo '</div>';
		echo '<button type="button" class="addp" data-id="' . (int) $id . '">+ Add another person</button>';
		echo '<em>One name per box. Leave blank if you would rather not say, or if it is a picture of the building.</em></div>';

		printf(
			'<label class="f"><span>What is happening?</span>' .
			'<textarea name="photo[%1$d][caption]" maxlength="%2$d" rows="2" data-count></textarea>' .
			'<em><span class="cnt">%2$d</span> characters left.</em></label>',
			(int) $id,
			(int) GASF_CRM_PHOTO_CAPTION_MAX
		);

		echo '</div></div>';
	}

	/*
	 * Permission to use the photos.
	 *
	 * Its own card, immediately above the button, because it is the one thing
	 * on this page that is not optional and it should not read like small print
	 * tucked under a heading. The full wording is shown rather than linked —
	 * agreeing to something you have to click to read is not really agreeing.
	 *
	 * The person is told plainly what happens if they would rather not, because
	 * a required box with no stated alternative reads like a trap.
	 */
	echo '<div class="card pad consent">';
	echo '<h3>One last thing &mdash; may we use them?</h3>';
	printf(
		'<label class="cbox"><input type="checkbox" name="gasf_consent" value="1" required> <span>%s</span></label>',
		esc_html( gasf_crm_photo_consent_text() )
	);
	echo '<p class="muted">We have to ask, and we cannot put a photo in the newsletter or on the website without it. If you would rather not, just close this page &mdash; the photos stay safely in the club\'s archive either way, and nothing you have typed is wasted.</p>';
	echo '</div>';

	echo '<div class="card pad submit">';
	echo '<button class="btn" type="submit">Send this to the club</button>';
	echo '<p class="muted">If you would rather not fill this in, you can simply close the page. The photos stay with us regardless, and nobody will chase you.</p>';
	echo '</div>';
	echo '</form>';

	echo '<p class="foot">' . esc_html( $org ) . '</p>';
	echo '</div>';

	gasf_crm_photo_script();
	echo '</body></html>';
}

function gasf_crm_photo_styles() {
	?>
<style>
*,*::before,*::after{box-sizing:border-box}
:root{--gasf-accent:#b8860b;--ink:#8a6508;--text:#1a1a1a;--muted:#6b6b6b;--border:#c9c4ba;--chip:#f3efe6;--page:#f7f5f0;--r:8px}
body{margin:0;background:var(--page);color:var(--text);font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.wrap{max-width:720px;margin:0 auto;padding:0 16px}
header.bar{background:#1a1a1a;color:#fff;padding:14px 0;border-bottom:3px solid var(--gasf-accent)}
header.bar h1{margin:0;font-size:16px;font-weight:600}
.main{padding:20px 16px 48px}
.card{background:#fff;border:1px solid var(--border);border-radius:var(--r);margin:0 0 16px;overflow:hidden}
.pad{padding:18px 20px}
.intro h2{margin:0 0 8px;font-size:20px}
.intro p{margin:0 0 10px}
h3{margin:0 0 14px;font-size:15px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
.f{display:block;margin:0 0 18px}
.f>span{display:block;font-weight:600;font-size:15px;margin-bottom:5px}
.f em{display:block;font-style:normal;font-size:13px;color:var(--muted);margin-top:5px}
input[type=text],input[type=date],select,textarea{
	width:100%;padding:11px 12px;border:1px solid var(--border);border-radius:6px;
	font:inherit;font-size:16px;background:#fff;color:var(--text)} /* 16px: anything smaller makes iOS zoom on focus */
textarea{resize:vertical}
input:focus,select:focus,textarea:focus{outline:2px solid var(--gasf-accent);outline-offset:1px;border-color:var(--gasf-accent)}
.people .pwrap{display:block;position:relative;margin-bottom:8px}
.people input{width:100%;margin:0}
/* Suggestions. Sized for a thumb, because this form is mostly used on a phone
   by somebody standing up. */
.psug{position:absolute;top:100%;left:0;right:0;z-index:40;background:#fff;border:1px solid var(--border);
	border-top:0;border-radius:0 0 6px 6px;box-shadow:0 8px 22px rgba(0,0,0,.16);max-height:240px;overflow:auto}
.psugi{display:flex;justify-content:space-between;gap:10px;width:100%;text-align:left;background:none;border:0;
	padding:12px 12px;font:inherit;font-size:16px;color:var(--ink);cursor:pointer;min-height:44px;align-items:center}
.psugi.on,.psugi:hover{background:var(--chip)}
.psugn{color:var(--muted);font-size:12px;flex:0 0 auto}
.hint{font-size:14px;color:var(--muted);margin:0 0 10px;background:var(--chip);border-radius:6px;padding:9px 11px}
.places{margin:0 0 10px}
/* Rows, not a dropdown: a phone renders a <select> as a modal wheel, which
   hides the hierarchy that is the whole point of this control. */
.pl{display:flex;align-items:center;gap:10px;padding:11px 12px;border:1px solid var(--border);
	border-radius:6px;margin:0 0 6px;cursor:pointer;background:#fff}
.pl:has(input:checked){border-color:var(--gasf-accent);background:var(--chip);box-shadow:inset 0 0 0 1px var(--gasf-accent)}
.pl input{width:auto;flex:none;margin:0}
.pl span{font-weight:600;font-size:15px}
.pl em{font-style:normal;font-size:13px;color:var(--muted)}
.pl.d1{margin-left:22px}
.pl.d2{margin-left:44px}
.evlist{display:flex;flex-direction:column;gap:5px;margin:6px 0}
.evopt{text-align:left;border:1px solid var(--border);background:#fff;border-radius:6px;
	padding:10px 12px;font:inherit;font-size:15px;cursor:pointer;color:var(--text)}
.evopt em{font-style:normal;color:var(--muted);font-size:13px}
.evopt:hover{background:var(--chip)}
.evopt.on{border-color:var(--gasf-accent);background:var(--chip);box-shadow:inset 0 0 0 1px var(--gasf-accent);font-weight:600}
.evopt.evfree{border-style:dashed;color:var(--muted)}
.evnote{margin-top:2px}
.f-evsearch{margin:6px 0}
select{appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8'><path d='M1 1l5 5 5-5' fill='none' stroke='%236b6b6b' stroke-width='2'/></svg>");
	background-repeat:no-repeat;background-position:right 12px center;background-size:12px;padding-right:34px}
/* Tap target for the full-size view — the answer to "who is in it" is rarely
   visible in a thumbnail. */
.photo .thumb{position:relative;display:block;text-decoration:none}
.photo .thumb .zoom{position:absolute;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);color:#fff;
	font-size:12px;text-align:center;padding:5px 4px}
.photo .thumb:hover .zoom{background:rgba(0,0,0,.72)}
/* :has() is unsupported on older Android browsers, where the radio dot alone
   still shows the selection — degraded, never broken. */
.addp{background:none;border:1px dashed var(--border);color:var(--ink);border-radius:6px;padding:8px 14px;font:inherit;font-size:14px;cursor:pointer}
.addp:hover{background:var(--chip)}
.photo{display:flex;gap:0;align-items:flex-start}
.photo .thumb{flex:0 0 190px;background:var(--chip);align-self:stretch;display:flex;align-items:center;justify-content:center}
.photo .thumb img{width:100%;height:100%;max-height:260px;object-fit:cover;display:block}
.photo .fields{flex:1 1 auto;min-width:0}
@media(max-width:620px){
	.photo{display:block}
	.photo .thumb{width:100%;flex:none}
	.photo .thumb img{max-height:220px}
}
.btn{background:var(--ink);color:#fff;border:0;border-radius:6px;padding:14px 22px;font:inherit;font-size:16px;font-weight:600;cursor:pointer;width:100%}
.btn:hover{filter:brightness(.9)}
.submit .muted{margin:12px 0 0}
/* Permission. Given room rather than shrunk — a box somebody has to tick is
   the last place to make the type small. */
.consent h3{margin:0 0 10px;font-size:17px}
.cbox{display:flex;gap:12px;align-items:flex-start;line-height:1.5;cursor:pointer}
.cbox input{width:24px;height:24px;flex:0 0 auto;margin-top:2px;accent-color:var(--gasf-accent,#c8a13a)}
.muted{color:var(--muted);font-size:14px}
.note{background:#fdf8e7;border-left:4px solid #dba617;border-radius:6px;padding:12px 14px;margin:0 0 16px;font-size:14px}
.foot{text-align:center;color:var(--muted);font-size:13px;margin:26px 0 0}
</style>
	<?php
}

function gasf_crm_photo_script() {
	?>
<script>
(function(){
	// Event titles are club-authored, but they reach the DOM as markup here, so
	// they are escaped for both text and quoted-attribute positions.
	function esc(s){
		return String(s == null ? '' : s)
			.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
			.replace(/"/g,'&quot;').replace(/'/g,'&#39;');
	}
	// "+ Add another person" clones the last box in that photo's group. Kept to
	// one behaviour with no framework: this page is opened once, on a phone, by
	// somebody doing us a favour.
	document.addEventListener('click', function(e){
		var b = e.target.closest ? e.target.closest('.addp') : null;
		if(!b) return;
		var box = document.querySelector('.people[data-id="' + b.dataset.id + '"]');
		if(!box) return;
		var inputs = box.querySelectorAll('input');
		if(inputs.length >= <?php echo (int) GASF_CRM_PHOTO_MAX_PEOPLE; ?>) { b.disabled = true; return; }
		// Clone the WRAPPER, so the new box keeps somewhere to hang its
		// suggestion list.
		var w = inputs[inputs.length - 1].closest('.pwrap').cloneNode(true);
		var n = w.querySelector('input');
		n.value = '';
		var sug = w.querySelector('.psug'); if (sug) { sug.remove(); }
		box.appendChild(w);
		n.focus();
	});

	/* ============ name suggestions ============
	 *
	 * The reason this is here and not only on the volunteers' side: "Mike
	 * Tressler" and "Michael Tressler" are two different people to a taxonomy,
	 * and the submitter is the one person who cannot be asked to check the
	 * existing spelling first. Every variation invented here is a redundancy
	 * somebody has to merge later.
	 *
	 * Only names already printed on public photos are offered — see
	 * gasf_photo_public_people. Matching is the shared implementation, so this
	 * form and the CRM behave identically rather than drifting apart.
	 */
	(function(){
		var PEOPLE = gasfPrepare(window.GASF_PEOPLE || []);
		var open = null, items = [], sel = -1;

		function close(){ if (open) { open.remove(); open = null; items = []; sel = -1; } }

		function paint(input){
			// close FIRST — it resets items, and computing them before closing
			// wipes the results on every keystroke after the one that opened it.
			close();
			var q = input.value.trim();
			if (q.length < 2) { return; }

			var taken = [];
			var box = input.closest('.people');
			if (box) {
				Array.prototype.forEach.call(box.querySelectorAll('input'), function(o){
					if (o !== input && o.value.trim()) { taken.push(o.value.trim()); }
				});
			}

			items = gasfPeopleMatch(q, PEOPLE, taken);
			if (!items.length) { return; }

			var d = document.createElement('div');
			d.className = 'psug';
            d.innerHTML = items.map(function(p, i){
				return '<button type="button" class="psugi' + (i === 0 ? ' on' : '') + '" data-i="' + i + '">' +
					p.label.replace(/[<>&]/g, '') + '</button>';
			}).join('');
			input.parentNode.appendChild(d);
			open = d; sel = 0;

			// mousedown, not click: blur fires first on click and would close the
			// list out from under the finger.
			d.addEventListener('mousedown', function(ev){
				var b = ev.target.closest('.psugi');
				if (!b) { return; }
				ev.preventDefault();
				choose(input, items[parseInt(b.dataset.i, 10)]);
			});
		}

		function choose(input, p){
			if (!p) { return; }
			input.value = p.value;   // the stored spelling, not the typed one
			close();
		}

		function move(step){
			if (!open || !items.length) { return; }
			sel = (sel + step + items.length) % items.length;
			Array.prototype.forEach.call(open.querySelectorAll('.psugi'), function(b, i){
				b.classList.toggle('on', i === sel);
			});
		}

		document.addEventListener('input', function(ev){
			if (ev.target.closest && ev.target.closest('.people')) { paint(ev.target); }
		});

		document.addEventListener('keydown', function(ev){
			if (!ev.target.closest || !ev.target.closest('.people')) { return; }
			if (!open) { return; }
			if (ev.key === 'ArrowDown') { move(1); ev.preventDefault(); }
			else if (ev.key === 'ArrowUp') { move(-1); ev.preventDefault(); }
			else if (ev.key === 'Enter') { if (sel >= 0) { choose(ev.target, items[sel]); ev.preventDefault(); ev.stopPropagation(); } }
			else if (ev.key === 'Escape') { close(); ev.stopPropagation(); }
		}, true);

		document.addEventListener('focusout', function(ev){
			if (ev.target.closest && ev.target.closest('.people')) { setTimeout(close, 150); }
		});
	}());

	// Enter must never submit this.
	//
	// Somebody typed an event name, pressed Enter to confirm it the way every
	// search box on earth works, and the whole form went. Everything they had
	// filled in for six photos was submitted half-finished. A text input inside
	// a form does that by default; a textarea keeps its newlines, and the send
	// button still works by click or space.
	var form = document.querySelector('.tagform');
	if (form) {
		form.addEventListener('keydown', function(e){
			if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT') { e.preventDefault(); }
		});
	}

	/* -------- the club's calendar, searched not typed --------
	   98% of these photos are of something the club put on, so a blank box is
	   the wrong default: it collects "oktoberfest", "Oktoberfest 2026" and
	   "OKTOBER FEST" as three answers to one question. Free text is kept, but
	   as the deliberate last resort it actually is. */
	var EV = (typeof GASF_EVENTS !== 'undefined') ? GASF_EVENTS : [];

	function evRender(box, list, chosen){
		var card = box.closest('.photo');
		var free = card.querySelector('.evfree');

		box.innerHTML = list.map(function(e){
			return '<button type="button" class="evopt' + (chosen === e.title ? ' on' : '') +
				'" data-title="' + esc(e.title) + '" data-id="' + e.id + '">' +
				esc(e.title) + ' <em>' + esc(e.when) + '</em></button>';
		}).join('');

		Array.prototype.forEach.call(box.querySelectorAll('.evopt'), function(b){
			b.onclick = function(){
				Array.prototype.forEach.call(box.querySelectorAll('.evopt'), function(x){ x.classList.remove('on'); });
				if (free) { free.classList.remove('on'); }
				b.classList.add('on');
				card.querySelector('.f-evfree').hidden = true;
				card.querySelector('.f-evfree').value = '';
				card.querySelector('.f-event').value = b.dataset.title;
				card.querySelector('.f-eventid').value = b.dataset.id;
				touch(card, 'event');
			};
		});
	}

	function evFor(card){
		var box   = card.querySelector('.evlist');
		var search= card.querySelector('.f-evsearch');
		var date  = card.querySelector('.f-date');
		var q     = search.value.trim().toLowerCase();
		var chosen= card.querySelector('.f-event').value;

		var list;
		if (q) {
			list = EV.filter(function(e){ return e.title.toLowerCase().indexOf(q) !== -1; }).slice(0, 12);
		} else if (date && date.value) {
			list = EV.filter(function(e){ return e.date === date.value; });
			// Nothing that day: show the nearest handful rather than an empty
			// panel, since a date can easily be a day out.
			if (!list.length) { list = EV.slice(0, 8); }
		} else {
			list = EV.slice(0, 8);
		}

		// One event on that day: choose it. Somebody who reads "World Cup Watch
		// Party, 29 Jun" and moves on has answered the question in their head —
		// leaving it unticked collects a blank from someone who thought they had
		// told us. With SEVERAL on that day it stays unticked, because picking
		// one of three would be inventing an answer rather than saving a click.
		if (!q && date && date.value && 1 === list.length && !touched(card, 'event')) {
			chosen = list[0].title;
			card.querySelector('.f-event').value = list[0].title;
			card.querySelector('.f-eventid').value = list[0].id;
		}

		evRender(box, list, chosen);
	}

	/* -------- photo 1's answers flow down, until touched -------- */
	function touch(card, what){ card.dataset['touched' + what] = '1'; }
	function touched(card, what){ return card.dataset['touched' + what] === '1'; }

	// Are two places on the same branch — one inside the other, either way up?
	// "German-American Society" and "Bierstube" are; "Bierstube" and "England
	// Brothers Park" are not.
	var PL = {};
	(typeof GASF_PLACES !== 'undefined' ? GASF_PLACES : []).forEach(function(p){ PL[p.name] = p; });

	function lineage(name){
		var out = [], cur = PL[name], guard = 0;
		while (cur && guard++ < 12) { out.push(cur.name); cur = cur.parent ? PL[cur.parent] : null; }
		return out;
	}
	function sameBranch(a, b){
		if (!a || !b || a === b) { return true; }
		return lineage(a).indexOf(b) !== -1 || lineage(b).indexOf(a) !== -1;
	}

	var cards = Array.prototype.slice.call(document.querySelectorAll('.photo'));
	var first = cards[0];

	function inherit(){
		if (!first || cards.length < 2) { return; }
		var d = first.querySelector('.f-date');
		var p = first.querySelector('.f-place');
		var e = first.querySelector('.f-event');
		var i = first.querySelector('.f-eventid');
		var o = first.querySelector('.f-placeother');

		cards.slice(1).forEach(function(c){
			// Dates: a photo that came from another day keeps its own. But when
			// this photo's camera said the same thing photo one's did, a
			// correction to photo one is fixing a clock they share, so it
			// carries across.
			var cd = c.querySelector('.f-date');
			if (cd && d && !touched(c, 'date')) {
				var sameClock = !cd.dataset.orig || (d.dataset.orig && cd.dataset.orig === d.dataset.orig);
				if (sameClock) { cd.value = d.value; }
			}

			// Places: a human choice beats a machine guess whenever the two are
			// on the same branch. GPS can say "the grounds" and can never say
			// "the Bierstube", so picking the room REFINES the guess rather than
			// contradicting it — and refusing to inherit there was the bug that
			// left five photos stuck on the geofence's answer. A genuinely
			// different place, on another branch, still keeps its own.
			var cp = c.querySelector('.f-place');
			if (p && cp && !touched(c, 'place')) {
				var own = cp.dataset.own || '';
				if (!own || sameBranch(p.value, own)) {
					cp.value = p.value;
					var co = c.querySelector('.f-placeother');
					if (co) {
						co.hidden = ('__other' !== p.value);
						if (o) { co.value = o.value; }
					}
				}
			}

			if (e && !touched(c, 'event')) {
				c.querySelector('.f-event').value = e.value;
				c.querySelector('.f-eventid').value = i ? i.value : '';
				var cf = c.querySelector('.f-evfree');
				var ff = first.querySelector('.f-evfree');
				if (cf && ff) { cf.hidden = ff.hidden; cf.value = ff.value; }
				evFor(c);
			}
		});
	}

	cards.forEach(function(card){
		var search = card.querySelector('.f-evsearch');
		var date   = card.querySelector('.f-date');
		var place  = card.querySelector('.f-place');
		var other  = card.querySelector('.f-placeother');
		var free   = card.querySelector('.evfree');
		var freein = card.querySelector('.f-evfree');

		if (search) {
			var t = null;
			search.oninput = function(){ clearTimeout(t); t = setTimeout(function(){ evFor(card); }, 150); };
		}
		if (date) {
			date.onchange = function(){
				touch(card, 'date');
				evFor(card);
				if (card === first) { inherit(); }
			};
		}
		if (place) {
			place.onchange = function(){
				touch(card, 'place');
				// The free-text box only exists when it is being used.
				if (other) {
					other.hidden = ('__other' !== place.value);
					if (!other.hidden) { other.focus(); }
				}
				if (card === first) { inherit(); }
			};
		}
		if (free && freein) {
			free.onclick = function(){
				Array.prototype.forEach.call(card.querySelectorAll('.evlist .evopt'), function(x){ x.classList.remove('on'); });
				free.classList.add('on');
				freein.hidden = false;
				freein.focus();
				card.querySelector('.f-event').value = freein.value;
				card.querySelector('.f-eventid').value = '';
				touch(card, 'event');
			};
			freein.oninput = function(){
				card.querySelector('.f-event').value = freein.value;
				card.querySelector('.f-eventid').value = '';
				touch(card, 'event');
			};
		}
		evFor(card);
	});

	// Characters remaining, counted down rather than up: the limit is the thing
	// worth knowing, and maxlength stops typing without explaining why.
	Array.prototype.forEach.call(document.querySelectorAll('textarea[data-count]'), function(t){
		var out = t.parentNode.querySelector('.cnt');
		if(!out) return;
		var max = parseInt(t.getAttribute('maxlength'), 10);
		var upd = function(){ out.textContent = String(max - t.value.length); };
		t.addEventListener('input', upd);
		upd();
	});
})();
</script>
	<?php
}
