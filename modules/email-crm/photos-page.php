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

	/* ---- things that are usually the same for the whole batch ---- */
	echo '<div class="card pad">';
	echo '<h3>All of them</h3>';

	echo '<label class="f"><span>When were they taken?</span>';
	echo '<input type="date" name="taken" value="' . esc_attr( $suggest_date ) . '" max="' . esc_attr( gmdate( 'Y-m-d' ) ) . '">';
	echo '<em>' . ( $suggest_date
		? 'We read this from the photo itself — change it if it looks wrong.'
		: 'The photos did not carry a date. A rough one is much better than none.' ) . '</em></label>';

	/*
	 * Where.
	 *
	 * Radio buttons rather than a <select>, and hierarchical. GPS can put a
	 * photo on the club's grounds but it can never say which room — indoors it
	 * is 50 m out on a good day, and the Bierstube and the Main Hall are a few
	 * paces apart. So the geofence answers "the club" and the ONE thing that
	 * actually knows which room, the person who was there, is asked directly.
	 *
	 * The suggested branch is lifted to the top so the likely answers are the
	 * first thing a thumb reaches, rather than something to scroll for.
	 */
	$tree = gasf_photo_place_tree( $suggest_place );

	echo '<div class="f"><span>Where?</span>';

	if ( $suggest_place ) {
		$sp = get_term( $suggest_place, 'gasf_photo_place' );
		if ( $sp && ! is_wp_error( $sp ) ) {
			printf(
				'<p class="hint">The camera put %s at <strong>%s</strong>. If you know which part, say so &mdash; and change it altogether if it looks wrong.</p>',
				1 === count( $ids ) ? 'this' : 'these',
				esc_html( $sp->name )
			);
		}
	}

	if ( $tree ) {
		echo '<div class="places">';
		foreach ( $tree as $row ) {
			$term = $row['term'];
			printf(
				'<label class="pl d%d"><input type="radio" name="place" value="%s"%s> <span>%s</span>%s</label>',
				min( 2, (int) $row['depth'] ),
				esc_attr( $term->name ),
				checked( (int) $term->term_id, $suggest_place, false ),
				esc_html( $term->name ),
				// Say which entries are a whole area rather than a specific
				// spot, so "German-American Society" does not look like a
				// worse answer than "Main Hall" when it is simply broader.
				$row['depth'] ? '' : ' <em>anywhere here</em>'
			);
		}
		printf(
			'<label class="pl d0"><input type="radio" name="place" value=""%s> <span>Somewhere else &mdash; or not sure</span></label>',
			checked( 0, $suggest_place, false )
		);
		echo '</div>';
	}

	echo '<input type="text" name="place_other" maxlength="120" placeholder="Somewhere not on the list">';
	echo '<em>Only if it is not above. Anything typed here wins.</em></div>';

	/*
	 * Occasion.
	 *
	 * If the photos carry a date and the club had something on that day, offer
	 * it. "Was it one of these?" is a far better question than "type the name of
	 * the event", which collects "oktoberfest", "Oktoberfest 2026" and "OKTOBER
	 * FEST" as three different answers to the same thing.
	 *
	 * Server-rendered from the suggested date rather than looked up live: this
	 * page is unauthenticated, and a search endpoint here would make the club's
	 * whole calendar queryable by anyone holding a photo link.
	 */
	$on_day = ( $suggest_date && function_exists( 'gasf_photo_events_on_date' ) )
		? gasf_photo_events_on_date( $suggest_date )
		: array();

	echo '<div class="f"><span>What was the occasion?</span>';

	if ( $on_day ) {
		printf(
			'<p class="hint">%s</p>',
			esc_html( sprintf(
				1 === count( $on_day )
					? 'The club had this on that day — was that it?'
					: 'The club had these on that day — was it one of them?',
				''
			) )
		);
		echo '<div class="places">';
		foreach ( $on_day as $ev ) {
			printf(
				'<label class="pl d0"><input type="radio" name="event" value="%s"> <span>%s</span> <em>%s</em></label>',
				esc_attr( $ev['title'] ),
				esc_html( $ev['title'] ),
				esc_html( $ev['when'] )
			);
		}
		echo '<label class="pl d0"><input type="radio" name="event" value="" checked> <span>Something else &mdash; or not sure</span></label>';
		echo '</div>';
		echo '<input type="text" name="event_other" maxlength="120" placeholder="Something not listed">';
		echo '<em>Only if it is not above. Anything typed here wins.</em>';
	} else {
		// No date, or nothing on that day. A list of the names we actually use
		// still beats a blank box: browsers offer them as you type.
		$titles = function_exists( 'gasf_photo_event_titles' ) ? gasf_photo_event_titles() : array();
		echo '<input type="text" name="event" maxlength="120" list="gasf-events" placeholder="Oktoberfest, Dinner Night, a work day…" autocomplete="off">';
		if ( $titles ) {
			echo '<datalist id="gasf-events">';
			foreach ( $titles as $tt ) { printf( '<option value="%s">', esc_attr( $tt ) ); }
			echo '</datalist>';
			echo '<em>Start typing and the club\'s own event names appear.</em>';
		}
	}
	echo '</div>';
	echo '</div>';

	/* ---- one block per photo ---- */
	foreach ( $ids as $i => $id ) {
		$img = wp_get_attachment_image( $id, 'medium', false, array( 'loading' => 'lazy', 'alt' => '' ) );
		echo '<div class="card photo">';
		echo '<div class="thumb">' . $img . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- core-generated markup
		echo '<div class="pad fields">';
		printf( '<h3>Photo %d of %d</h3>', (int) $i + 1, count( $ids ) );

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
