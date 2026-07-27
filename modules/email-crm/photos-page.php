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

	if ( 'unknown' === $state || 'expired' === $state ) {
		echo '<div class="card pad">';
		echo '<h2>' . ( 'expired' === $state ? 'That link has expired' : 'That link does not work' ) . '</h2>';
		echo '<p>' . ( 'expired' === $state
			? esc_html( sprintf( 'Tagging links last %d days. Your photos are safe and still with us — nothing has been lost.', GASF_CRM_PHOTO_INVITE_DAYS ) )
			: 'It may have been mistyped, or it may have already been used. Your photos are safe and still with us either way.' ) . '</p>';
		echo '<p>If you would still like to tell us about them, just reply to the email you had from us and we will send a fresh link.</p>';
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

	$ids = array_values( array_filter( (array) $invite['ids'], function ( $id ) {
		return 'attachment' === get_post_type( $id );
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
	$suggest_place = ( 1 === count( $guesses ) ) ? (int) key( $guesses ) : gasf_photo_home_place();

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
	$tree = gasf_photo_place_tree( $suggest_place );

	$pl_list = array();
	foreach ( $tree as $row ) {
		$pl_list[] = array( 'name' => $row['term']->name, 'depth' => (int) $row['depth'] );
	}

	printf(
		'<script>var GASF_EVENTS=%s,GASF_PLACES=%s;</script>',
		wp_json_encode( $ev_list ),
		wp_json_encode( $pl_list )
	);

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

		$img = wp_get_attachment_image( $id, 'medium', false, array( 'loading' => 'lazy', 'alt' => '' ) );
		printf( '<div class="card photo" data-photo="%d"%s>', (int) $id, 0 === $i ? ' data-first="1"' : '' );
		echo '<div class="thumb">' . $img . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- core-generated markup
		echo '<div class="pad fields">';
		printf( '<h3>Photo %d of %d</h3>', (int) $i + 1, count( $ids ) );

		// Date. data-own marks a value that came from the photo itself, which
		// the inheritance must not overwrite.
		printf(
			'<label class="f"><span>When was it taken?</span>' .
			'<input type="date" class="f-date" name="photo[%d][taken]" value="%s" max="%s"%s>' .
			'<em>%s</em></label>',
			(int) $id,
			esc_attr( $own_date ),
			esc_attr( gmdate( 'Y-m-d' ) ),
			$own_date ? ' data-own="1"' : '',
			$own_date ? 'Read from the photo itself — change it if it looks wrong.' : 'A rough date is much better than none.'
		);

		// Where. Hierarchical rows, because GPS can put a photo on the grounds
		// but never in a particular room.
		echo '<div class="f"><span>Where?</span>';
		if ( $place_val ) {
			printf( '<p class="hint">The camera put this at <strong>%s</strong>. Say which part if you know.</p>', esc_html( $place_val ) );
		}
		if ( $tree ) {
			echo '<div class="places">';
			foreach ( $tree as $row ) {
				$term = $row['term'];
				printf(
					'<label class="pl d%d"><input type="radio" class="f-place" name="photo[%d][place]" value="%s"%s%s> <span>%s</span>%s</label>',
					min( 2, (int) $row['depth'] ),
					(int) $id,
					esc_attr( $term->name ),
					checked( $term->name, $place_val, false ),
					$place_val && $term->name === $place_val ? ' data-own="1"' : '',
					esc_html( $term->name ),
					$row['depth'] ? '' : ' <em>anywhere here</em>'
				);
			}
			printf(
				'<label class="pl d0"><input type="radio" class="f-place" name="photo[%d][place]" value=""%s> <span>Somewhere else &mdash; or not sure</span></label>',
				(int) $id,
				checked( '', $place_val, false )
			);
			echo '</div>';
		}
		printf( '<input type="text" class="f-placeother" name="photo[%d][place_other]" maxlength="120" placeholder="Somewhere not on the list">', (int) $id );
		echo '<em>Only if it is not above. Anything typed here wins.</em></div>';

		// Occasion — a search over the club's calendar, not a blank box.
		echo '<div class="f"><span>What was the occasion?</span>';
		printf(
			'<input type="text" class="f-evsearch" placeholder="Start typing an event name…" autocomplete="off" data-for="%d">',
			(int) $id
		);
		echo '<div class="evlist" data-for="' . (int) $id . '"></div>';
		printf( '<input type="hidden" class="f-event" name="photo[%d][event]" value="">', (int) $id );
		printf( '<input type="hidden" class="f-eventid" name="photo[%d][event_id]" value="">', (int) $id );
		echo '<em class="evnote">Events on the date above come up first. Nothing matching? Tick <strong>Not a club event</strong> and type it.</em>';
		echo '</div>';

		echo '<div class="f"><span>Who is in it?</span>';
		echo '<div class="people" data-id="' . (int) $id . '">';
		printf( '<input type="text" name="photo[%d][people][]" maxlength="80" placeholder="Name" autocomplete="off">', (int) $id );
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
.people input{margin-bottom:8px}
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
		var n = inputs[inputs.length - 1].cloneNode(true);
		n.value = '';
		box.appendChild(n);
		n.focus();
	});

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
		var id = box.dataset.for;
		box.innerHTML = list.map(function(e){
			return '<button type="button" class="evopt' + (chosen === e.title ? ' on' : '') +
				'" data-title="' + esc(e.title) + '" data-id="' + e.id + '">' +
				esc(e.title) + ' <em>' + esc(e.when) + '</em></button>';
		}).join('') +
			'<button type="button" class="evopt evfree" data-title="" data-id="0">Not a club event &mdash; let me type it</button>';

		Array.prototype.forEach.call(box.querySelectorAll('.evopt'), function(b){
			b.onclick = function(){
				var card = box.closest('.photo');
				Array.prototype.forEach.call(box.querySelectorAll('.evopt'), function(x){ x.classList.remove('on'); });
				b.classList.add('on');

				var free = card.querySelector('.f-evfree');
				if (b.classList.contains('evfree')) {
					if (!free) {
						free = document.createElement('input');
						free.type = 'text'; free.className = 'f-evfree'; free.maxLength = 120;
						free.placeholder = 'What was it?';
						b.parentNode.parentNode.insertBefore(free, b.parentNode.nextSibling);
						free.oninput = function(){
							card.querySelector('.f-event').value = free.value;
							card.querySelector('.f-eventid').value = '';
							touch(card, 'event');
						};
					}
					free.hidden = false; free.focus();
					card.querySelector('.f-event').value = free.value;
					card.querySelector('.f-eventid').value = '';
				} else {
					if (free) { free.hidden = true; }
					card.querySelector('.f-event').value = b.dataset.title;
					card.querySelector('.f-eventid').value = b.dataset.id;
				}
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
		evRender(box, list, chosen);
	}

	/* -------- photo 1's answers flow down, until touched -------- */
	function touch(card, what){ card.dataset['touched' + what] = '1'; }
	function touched(card, what){ return card.dataset['touched' + what] === '1'; }

	var cards = Array.prototype.slice.call(document.querySelectorAll('.photo'));
	var first = cards[0];

	function inherit(){
		if (!first || cards.length < 2) { return; }
		var d = first.querySelector('.f-date');
		var p = first.querySelector('.f-place:checked');
		var e = first.querySelector('.f-event');
		var i = first.querySelector('.f-eventid');

		cards.slice(1).forEach(function(c){
			// A value the photo itself carried is evidence about THIS picture and
			// outranks anything copied from a different one.
			var cd = c.querySelector('.f-date');
			if (cd && !cd.dataset.own && !touched(c, 'date') && d) { cd.value = d.value; }

			if (p && !touched(c, 'place')) {
				var own = c.querySelector('.f-place[data-own]');
				if (!own) {
					var match = c.querySelector('.f-place[value="' + (window.CSS && CSS.escape ? CSS.escape(p.value) : p.value) + '"]');
					if (match) { match.checked = true; }
				}
			}

			if (e && !touched(c, 'event')) {
				c.querySelector('.f-event').value = e.value;
				c.querySelector('.f-eventid').value = i ? i.value : '';
				evFor(c);
			}
		});
	}

	cards.forEach(function(card){
		var search = card.querySelector('.f-evsearch');
		var date   = card.querySelector('.f-date');

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
		Array.prototype.forEach.call(card.querySelectorAll('.f-place'), function(r){
			r.onchange = function(){ touch(card, 'place'); if (card === first) { inherit(); } };
		});
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
