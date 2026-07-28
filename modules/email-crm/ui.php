<?php
/**
 * Email CRM — front end at /email (modules/email-crm/ui.php)
 *
 * Rendered standalone rather than through the theme: this is a tool, not a page
 * of the website, and inheriting the club's header, hero and cookie banner
 * would only get in the way of reading email.
 *
 * Deliberately unlinked and noindex — the only way in is knowing the URL, and
 * then being approved.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'template_redirect', function () {
	$route = get_query_var( 'gasf_crm' );
	if ( ! $route ) { return; }

	/*
	 * Nothing under /email may be cached, by anyone, ever.
	 *
	 * This was not theoretical. The host's Endurance cache was stamping
	 * "Cache-Control: max-age=7200" onto the OAuth start route — a 302 carrying
	 * a one-time state parameter. A 302 with explicit freshness is cacheable,
	 * so browsers dutifully kept it and replayed the SAME state on every later
	 * attempt. A volunteer tapping "Continue with Google" was being sent
	 * straight back with a state consumed hours earlier, never reaching Google
	 * at all: three failures in sixteen seconds, and "that sign-in link has
	 * expired" every time.
	 *
	 * Set before any route runs, and repeated with replace=true after
	 * nocache_headers() because something downstream had been overwriting it.
	 * DONOTCACHEPAGE is the convention the page caches on this host look for.
	 */
	if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
	nocache_headers();
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true );
	header( 'Pragma: no-cache', true );
	// Forced into the past, NOT removed. Removing it was my own error: it
	// deleted WordPress's protective "Expires: 1984" and left mod_expires —
	// which runs after PHP and has ExpiresByType text/html "plus 2 hours" — free
	// to supply a future one instead. An empty slot is an invitation.
	header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );

	$provider = sanitize_key( (string) get_query_var( 'gasf_crm_provider' ) );

	switch ( $route ) {
		case 'start':
			gasf_crm_auth_start( $provider );
			return;

		case 'callback':
			gasf_crm_auth_callback( $provider );
			return;

		case 'logout':
			if ( function_exists( 'gasf_crm_auth_log' ) && is_user_logged_in() ) {
				$u = wp_get_current_user();
				gasf_crm_auth_log( 'signout', 'ok', array(
					'user_id' => (int) $u->ID,
					'email'   => (string) $u->user_email,
				) );
			}
			wp_logout();
			wp_safe_redirect( home_url( '/email' ) );
			exit;

		case 'app':
			gasf_crm_render_app();
			exit;
	}
	// Priority 1, ahead of WordPress's own redirect_canonical. Left at the
	// default it 301s /email/auth/google/callback to a trailing-slash variant
	// in the middle of an OAuth callback. The query string does survive that
	// hop, but an extra redirect mid-callback is a well-known way to lose it.
}, 1 );

function gasf_crm_render_app() {
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );

	// HSTS: after one visit the browser refuses plain HTTP for this host, so a
	// captured session cookie cannot be replayed over an HTTP downgrade.
	// Deliberately WITHOUT includeSubDomains — krampus.germantampabay.com is a
	// separate install this module knows nothing about, and force-HTTPS-ing it
	// sight unseen from here would be wrong.
	if ( 0 === strpos( home_url(), 'https://' ) ) {
		header( 'Strict-Transport-Security: max-age=15552000' );
	}

	$status = gasf_crm_user_status();

	// The whole page's colour scheme hangs off a data-stream attribute (see the
	// palette block in gasf_crm_styles). Somebody who holds exactly one mailbox
	// gets it themed to theirs from the first paint rather than flashing the
	// wrong colour and correcting itself; with several, the switcher sets it,
	// and '' means "all", which wears the club's own gold.
	$my_streams  = gasf_crm_user_streams();
	$body_stream = ( 1 === count( $my_streams ) ) ? (string) $my_streams[0] : '';

	echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<meta name="robots" content="noindex, nofollow">';
	echo '<title>Email — ' . esc_html( get_bloginfo( 'name' ) ) . '</title>';
	gasf_crm_styles();
	echo '</head><body data-stream="' . esc_attr( $body_stream ) . '">';

	if ( 'anonymous' === $status ) {
		gasf_crm_render_signin();
	} elseif ( 'approved' === $status ) {
		gasf_crm_render_inbox();
	} else {
		gasf_crm_render_pending( $status );
	}

	echo '</body></html>';
}

function gasf_crm_styles() {
	?>
<style>
*,*::before,*::after{box-sizing:border-box}
/* Design tokens — the same names and values the rest of germantampabay.com
   uses (GASF Events' gasf-events.css defines them for the theme side).

   Deliberately NOT the theme's stylesheet: pulling that in would drag the site
   header, hero, menu and cookie banner into a tool whose entire purpose is an
   uncluttered view of an email. Tokens give us the club's colours without its
   furniture — and an admin who overrides --gasf-* in the theme moves this page
   along with everything else, which is what "the site's CSS" should mean. */
:root{
	--gasf-accent:#b8860b;
	--gasf-text:#1a1a1a;
	--gasf-muted:#6b6b6b;
	--gasf-border:#c9c4ba;
	--gasf-surface:#fff;
	--gasf-chip:#f3efe6;
	--gasf-radius:8px;
	--gasf-dark:#1a1a1a;
	--gasf-page:#f7f5f0;
	--ok:#2c7a3f;
	--danger:#b32d2e;
	--hair:#ece9e2;
}

/* Per-stream palette.

   One block of rules, re-pointed by [data-stream] — an attribute that already
   sits on the switcher buttons, and which we now also stamp on <body>, on every
   list row and on the reading pane. Each subtree therefore colours itself from
   the stream it actually belongs to, rather than from one global "current
   stream" that goes stale the moment you open a photo thread from the All list.

   General wears the club gold. Photo submissions wear the Bayern red/blue
   already used by the Bundesliga table and /fcbmc/ — a palette the club owns,
   rather than a third one invented here.

   --s-accent is decoration (rules, edges, dots); --s-ink is anything carrying
   text. They differ for gold because #b8860b on white is 3.3:1, short of the
   4.5:1 body text needs; #8a6508 is the same hue at 5.3:1. Bayern blue is
   10.6:1 and needs no such split. */
[data-stream]{ /* unknown / future stream: neutral, never borrowed from a sibling */
	--s-accent:var(--gasf-muted);--s-ink:#4a473f;--s-wash:#faf9f7;--s-tint:#eeece7;
}
[data-stream=""],[data-stream="general"]{
	--s-accent:var(--gasf-accent);--s-ink:#8a6508;--s-wash:#fdfaf1;--s-tint:#f6efdd;
}
[data-stream="photos"]{
	--s-accent:#0033a0;--s-ink:#0033a0;--s-wash:#f5f8fd;--s-tint:#e6edf9;--s-mark:#dc052d;
}

body{margin:0;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--gasf-text);background:var(--gasf-page)}
a{color:var(--s-ink)}
.wrap{max-width:1200px;margin:0 auto;padding:0 16px}
/* The bar carries the active mailbox's colour along its bottom edge, so the
   page says which inbox you are in before you have read a word of it. */
header.bar{background:var(--gasf-dark);color:#fff;padding:12px 0;border-bottom:3px solid var(--s-accent)}
header.bar .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
header.bar h1{font-size:16px;margin:0;font-weight:600}
header.bar h1 .box{font-weight:400;opacity:.75}
header.bar a{color:#d9d4c8;text-decoration:none;font-size:13px}
header.bar .hbtn{background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.26);padding:5px 12px;border-radius:4px;cursor:pointer;font:inherit;font-size:13px;margin-right:8px}
header.bar .hbtn:hover{background:rgba(255,255,255,.26)}
/* Signed-in volunteer's photo. The initials are the BACKGROUND of the circle
   and the photo sits on top, so an image that fails degrades to them rather
   than to a broken-image icon. */
.me{position:relative;display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;margin-right:7px;border-radius:50%;background:rgba(255,255,255,.18);color:#fff;font-size:10px;font-weight:700;line-height:1;vertical-align:middle;overflow:hidden;flex:none}
.me img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
.center{max-width:420px;margin:12vh auto;background:var(--gasf-surface);padding:32px;border-radius:var(--gasf-radius);box-shadow:0 1px 3px rgba(0,0,0,.13);text-align:center;border-top:4px solid var(--gasf-accent)}
.center h1{font-size:20px;margin:0 0 8px}
.center p{color:var(--gasf-muted);margin:0 0 24px}
.btn{display:inline-block;padding:9px 16px;border:1px solid var(--s-ink);background:var(--s-ink);color:#fff;border-radius:5px;cursor:pointer;font-size:14px;text-decoration:none;font-family:inherit}
/* brightness() rather than a second hex per stream — one rule that stays
   correct whatever colour a future stream turns out to be. */
.btn:hover{filter:brightness(.86)}
.btn[disabled]{opacity:.5;cursor:default;filter:none}
.btn.sec{background:var(--gasf-surface);color:var(--s-ink);border-color:var(--gasf-border)}
.btn.sec:hover{background:var(--gasf-chip);filter:none}
.btn.warn{background:var(--gasf-surface);color:var(--danger);border-color:var(--danger)}
.btn.warn:hover{background:#fcf0f1;filter:none}
.btn.block{display:block;width:100%;margin:0 0 10px;padding:11px}
.layout{display:grid;grid-template-columns:340px 1fr;gap:16px;padding:16px 0;align-items:start}
@media(max-width:820px){.layout{grid-template-columns:1fr}}
.card{background:var(--gasf-surface);border:1px solid var(--gasf-border);border-radius:var(--gasf-radius);overflow:hidden}
.list{max-height:78vh;overflow:auto}

/* Two rows of near-identical tabs was the main reason this page read as a wall
   of grey. They do different jobs, so they now have different shapes: the top
   row is a segmented switcher for WHICH MAILBOX, coloured per stream; the row
   below it is quiet underlined tabs for WHICH LIST. */
.tabs.streams{display:flex;gap:4px;padding:6px;background:var(--gasf-chip);border-bottom:1px solid var(--gasf-border)}
.tabs.streams button{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:7px 8px;border:0;border-radius:5px;background:none;cursor:pointer;font:inherit;font-size:12px;font-weight:600;line-height:1.2;color:var(--gasf-muted)}
/* The swatch reads its colour from the button's OWN data-stream, so the legend
   and the list can never disagree about which colour means which inbox. */
.tabs.streams button::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--s-accent);flex:none}
.tabs.streams button[data-stream=""]::before{display:none} /* "All" is not a colour */
.tabs.streams button:hover{background:rgba(0,0,0,.05)}
.tabs.streams button.on{background:var(--s-ink);color:#fff}
.tabs.streams button.on::before{background:#fff}

.tabs:not(.streams){display:flex;border-bottom:1px solid var(--gasf-border)}
.tabs:not(.streams) button{flex:1;padding:9px 6px;border:0;background:none;cursor:pointer;font:inherit;font-size:13px;color:var(--gasf-muted);border-bottom:2px solid transparent}
.tabs:not(.streams) button:hover{color:var(--gasf-text)}
.tabs:not(.streams) button.on{color:var(--s-ink);border-bottom-color:var(--s-accent);font-weight:600}

.streamtag{display:inline-block;font-size:10px;font-weight:600;letter-spacing:.02em;padding:1px 7px;border-radius:9px;background:var(--s-tint);color:var(--s-ink);margin-left:6px;vertical-align:middle}
/* Every row wears its own mailbox's colour on the left edge. In the All view
   that is the point: which inbox a message came from is legible at a glance,
   without stopping to read the tag on each one. */
.item{padding:12px 14px 12px 13px;border-bottom:1px solid var(--hair);border-left:3px solid var(--s-accent);cursor:pointer;background:var(--gasf-surface)}
.item:last-child{border-bottom:0}
.item:hover{background:var(--s-wash)}
/* Selection changes colour and weight, never geometry — a border that grows on
   click shifts every row below it. */
.item.on{background:var(--s-wash);box-shadow:inset 0 0 0 1px var(--s-tint)}
.item .who{font-weight:600;font-size:13px;display:flex;justify-content:space-between;gap:8px;color:var(--gasf-text)}
.item .subj{font-size:13px;margin:2px 0 0;color:#3c3a35}
.item .meta{font-size:11px;color:var(--gasf-muted);margin-top:4px;font-weight:400}
.dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#d63638;margin-right:6px;vertical-align:middle}
.pane{padding:20px;min-height:300px}
/* The reading pane takes the colour of the thread's own mailbox, whichever list
   you reached it from — and so answers "which address is this about to send
   from?", which matters more here than anywhere else on the page. */
#pane{border-top:3px solid var(--s-accent)}
.frombox{font-size:12px;color:var(--gasf-muted);margin:-10px 0 16px}
.frombox code{background:var(--s-tint);color:var(--s-ink);padding:1px 6px;border-radius:3px;font-size:12px;user-select:all}
.msg{border-bottom:1px solid var(--hair);padding:0 0 16px;margin:0 0 16px}
.msg:last-of-type{border-bottom:0}
.msg .hd{font-size:12px;color:var(--gasf-muted);margin-bottom:8px}
.msg .hd b{color:var(--gasf-text);font-size:13px}
/* Revealed on hover, but it is real selectable text the whole time — opacity
   keeps it in the layout so nothing shifts, and the reveal is triggered by the
   whole message block so it stays visible while you drag across it to select. */
.msg .addr{opacity:0;transition:opacity .12s;font-weight:400}
.msg:hover .addr,.msg .addr:focus-within{opacity:1}
.msg .addr code{background:var(--gasf-chip);padding:1px 5px;border-radius:3px;font-size:12px;user-select:all}
.copy{border:1px solid var(--gasf-border);background:var(--gasf-surface);border-radius:3px;cursor:pointer;font:inherit;font-size:11px;padding:1px 6px;margin-left:4px;color:var(--s-ink)}
.copy:hover{background:var(--s-wash)}
.copy.done{color:var(--ok);border-color:var(--ok)}
/* Touch devices have no hover at all — never hide it there. */
@media(hover:none){.msg .addr{opacity:1}}
/* Outbound stays green in every stream: this marks a DIRECTION, not a mailbox,
   and re-colouring it per stream would collide with the one meaning readers
   already have for it. */
.msg.out{background:#f4f8f4;border-left:3px solid var(--ok);padding:12px;border-radius:4px}
.msg .body{overflow-wrap:anywhere}
.msg .body table{max-width:100%;border-collapse:collapse}
.msg .body img{max-width:100%;height:auto}
textarea{width:100%;min-height:150px;padding:10px;border:1px solid var(--gasf-border);border-radius:5px;font:inherit;resize:vertical;background:var(--gasf-surface);color:var(--gasf-text)}
.ed{border:1px solid var(--gasf-border);border-radius:5px;overflow:hidden;background:var(--gasf-surface)}
.edbar{display:flex;flex-wrap:wrap;align-items:center;gap:2px;padding:6px;border-bottom:1px solid var(--gasf-border);background:var(--gasf-chip)}
.edbar button{min-width:32px;height:28px;padding:0 9px;border:1px solid transparent;background:none;border-radius:3px;cursor:pointer;font:inherit;font-size:13px;color:var(--gasf-text);line-height:1}
.edbar button:hover{background:var(--gasf-surface);border-color:var(--gasf-border)}
.edbar .sep{width:1px;height:18px;background:var(--gasf-border);margin:0 5px}
.edbody{min-height:170px;max-height:50vh;overflow:auto;padding:10px;outline:none;font:inherit;overflow-wrap:anywhere}
.edbody:empty::before{content:attr(data-ph);color:#9a958a}
.edbody:focus{box-shadow:inset 0 0 0 2px var(--s-accent)}
.edbody p{margin:0 0 10px}
.edbody ul,.edbody ol{margin:0 0 10px;padding-left:24px}
.actions{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.note{padding:10px 12px;border-radius:5px;font-size:13px;margin:12px 0}
.note.warn{background:#fdf8e7;border-left:4px solid #dba617}
.note.err{background:#fcf0f1;border-left:4px solid #d63638}
/* Green, not blue: "this is answered" is a settled-good state, and blue is now
   a stream colour rather than a status one. */
.note.ok{background:#f0f6ec;border-left:4px solid var(--ok)}
.muted{color:var(--gasf-muted);font-size:13px}
.att{display:inline-block;margin:4px 8px 0 0;padding:4px 10px;background:var(--gasf-chip);border:1px solid var(--gasf-border);border-radius:4px;font-size:12px;text-decoration:none;color:var(--s-ink)}
.att:hover{background:var(--s-tint)}
.att--noload{color:var(--gasf-muted);font-style:italic}
.spin{opacity:.6}
.hist{margin-top:28px;border-top:1px solid var(--gasf-border);padding-top:14px}
.hist h3{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--gasf-muted);margin:0 0 10px}
.hist ul{list-style:none;margin:0;padding:0}
.hist li{font-size:13px;padding:5px 0 5px 16px;border-left:2px solid var(--gasf-border);color:#4a473f}
.hist li b{color:var(--gasf-text)}
.hist li .t{color:var(--gasf-muted);font-size:12px}
/* Help wears the club gold in every stream — it is about the whole page, not
   about whichever inbox happens to be selected behind it. */
.help{background:var(--gasf-surface);border:1px solid var(--gasf-border);border-top:4px solid var(--gasf-accent);border-radius:var(--gasf-radius);padding:20px 24px;margin:16px 0}
.help h2{font-size:17px;margin:0 0 4px}
.help h3{font-size:14px;margin:18px 0 4px}
.help p,.help li{font-size:14px;color:#3c3a35}
.help ul{margin:4px 0;padding-left:20px}
.help .close{float:right}
.help .key{display:flex;flex-wrap:wrap;gap:16px;margin:8px 0 0;padding:0;list-style:none}
.help .key li{display:flex;align-items:center;gap:7px}
.help .key i{width:11px;height:11px;border-radius:3px;background:var(--s-accent);flex:none}
.fwd{border:1px solid var(--gasf-border);border-radius:5px;padding:14px;margin-top:12px;background:var(--s-wash)}
.fwd label{display:block;font-size:13px;font-weight:600;margin-bottom:12px}
.fwd input[type=text]{width:100%;max-width:440px;padding:8px;border:1px solid var(--gasf-border);border-radius:4px;font:inherit;font-weight:400;margin-top:3px}
.fwd textarea{min-height:70px;font-weight:400;margin-top:3px}
.ignpicks{display:flex;flex-wrap:wrap;gap:8px}
.ignpicks .btn{margin:0}
.chip{display:inline-block;background:var(--s-tint);border:1px solid var(--gasf-border);border-radius:14px;padding:3px 6px 3px 11px;font-size:12px;margin:4px 6px 0 0;color:var(--s-ink)}
.chip button{border:0;background:none;cursor:pointer;font:inherit;font-size:14px;color:var(--danger);padding:0 5px;line-height:1}
.lib{margin-top:14px;border-top:1px solid var(--gasf-border);padding-top:12px}
.lib h4{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--gasf-muted)}
.lib .row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:5px 0;font-size:13px;border-bottom:1px solid var(--hair)}
.lib .row:last-child{border-bottom:0}
/* Photo submissions */
.keep{border:1px solid var(--s-accent);background:var(--gasf-surface);color:var(--s-ink);border-radius:4px;cursor:pointer;font:inherit;font-size:12px;padding:4px 10px;margin:4px 8px 0 0}
.keep:hover{background:var(--s-tint)}
.keep[disabled]{opacity:.6;cursor:default}
.photos{margin-top:28px;border-top:1px solid var(--gasf-border);padding-top:14px}
/* When photos lead, the first block needs no rule above it and the message
   below needs one, so the order reads as deliberate rather than jumbled. */
.pane > .photos:first-of-type{margin-top:0;border-top:0;padding-top:0}
.mailhead{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--gasf-muted);
	margin:26px 0 12px;padding-top:14px;border-top:1px solid var(--gasf-border)}
.photos h3{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--gasf-muted);margin:0 0 12px}
.pcard{display:flex;gap:14px;border:1px solid var(--gasf-border);border-radius:6px;padding:12px;margin:0 0 10px;background:var(--s-wash)}
.pthumb{flex:0 0 90px;height:90px;border-radius:4px;overflow:hidden;background:var(--gasf-chip);display:block}
.pthumb img{width:100%;height:100%;object-fit:cover;display:block}
.pbody{flex:1 1 auto;min-width:0}
.pfrom{font-size:12px;font-weight:600;color:var(--s-ink);margin-bottom:8px}
.pf{display:block;margin:0 0 8px}
.pf>span{display:block;font-size:11px;color:var(--gasf-muted);margin-bottom:2px}
.pf input,.pf select{width:100%;padding:6px 8px;border:1px solid var(--gasf-border);border-radius:4px;font:inherit;font-size:13px;background:var(--gasf-surface);color:var(--gasf-text)}
.pf .p-placeother{margin-top:5px}
.p-people .p-person+.p-person{margin-top:5px}
.addp{background:none;border:0;padding:2px 0;margin:4px 0 0;font:inherit;font-size:12px;color:var(--s-accent);cursor:pointer}
.addp:hover{text-decoration:underline}
.prow{display:flex;gap:8px;flex-wrap:wrap}
.prow .pf{flex:1 1 130px}
.pgeo{font-size:12px;color:var(--gasf-muted);margin:2px 0 8px}
.pev{margin:0 0 8px}
.pevlist{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:6px}
.pevlist.muted{display:block;font-size:12px}
.evpick{border:1px solid var(--gasf-border);background:var(--gasf-surface);border-radius:12px;
	padding:3px 10px;font:inherit;font-size:12px;cursor:pointer;color:var(--gasf-text)}
.evpick em{font-style:normal;color:var(--gasf-muted)}
.evpick:hover{background:var(--s-tint);border-color:var(--s-accent)}
.evpick.on{background:var(--s-ink);border-color:var(--s-ink);color:#fff}
.evpick.on em{color:rgba(255,255,255,.75)}
.p-evsearch{width:100%;padding:5px 8px;border:1px dashed var(--gasf-border);border-radius:4px;font:inherit;font-size:12px}
.pdone{font-size:13px;font-weight:600;color:var(--ok)}
/* Photos screen */
.tabs.pstates button{font-size:12px}
.pgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;padding:10px}
.pgrid .pane{grid-column:1/-1;padding:14px 4px}
.pthumbcard{border:1px solid var(--gasf-border);background:var(--gasf-surface);border-radius:6px;
	padding:0;cursor:pointer;overflow:hidden;display:block;text-align:left;font:inherit}
.pthumbcard img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;background:var(--gasf-chip)}
.pthumbcard .pmeta{display:block;padding:5px 7px;font-size:11px;color:var(--gasf-muted);
	overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pthumbcard .pmeta em{display:block;font-style:normal;font-weight:600;color:var(--s-ink)}
.pthumbcard:hover{border-color:var(--s-accent)}
.pthumbcard.on{border-color:var(--s-ink);box-shadow:inset 0 0 0 2px var(--s-ink)}
.pbig{display:block;border-radius:6px;overflow:hidden;background:var(--gasf-chip)}
.pbig img{width:100%;max-height:46vh;object-fit:contain;display:block}
.firsttime{display:inline-block;font-size:11px;font-weight:600;background:#fdf8e7;color:#8a6508;
	border:1px solid #dba617;border-radius:9px;padding:1px 8px;margin-left:4px}
.badge{display:inline-block;font-size:11px;padding:1px 7px;border-radius:9px;background:var(--gasf-chip);color:var(--gasf-muted);vertical-align:middle}
.badge.ig{background:#fcf0f1;color:var(--danger)}
.badge.an{background:#edf4ea;color:var(--ok)}
</style>
	<?php
}

function gasf_crm_render_signin() {
	$providers = gasf_crm_enabled_providers();
	echo '<div class="center"><h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1>';
	echo '<p>Sign in to read and answer mail sent to the club.</p>';

	if ( ! $providers ) {
		echo '<div class="note err">No sign-in method is configured yet. An administrator needs to add Google or Microsoft credentials in GASF Utilities &rarr; Email CRM.</div>';
	}
	// A form, not a link.
	//
	// Starting a sign-in is not a read: it mints a one-time state and sets a
	// cookie. As a GET it was a redirect a browser could cache and replay — and
	// did, once the host's mod_expires stamped two hours of freshness onto it,
	// sending people back to Google with a state consumed hours earlier. Browsers
	// do not reuse POST responses from cache, so this cannot recur however the
	// host's caching is configured next.
	foreach ( $providers as $key => $p ) {
		printf(
			// The hidden field is insurance, not decoration. This host's
			// ModSecurity rejects a POST it considers empty, and a form whose
			// only control is a submit button posts nothing but the
			// Content-Type. One field guarantees a body.
			'<form method="post" action="%s"><input type="hidden" name="go" value="1">'
				. '<button class="btn block" type="submit">Continue with %s</button></form>',
			esc_url( home_url( '/email/auth/' . $key ) ),
			esc_html( $p['label'] )
		);
	}
	echo '</div>';
}

/**
 * Waiting-room screen.
 *
 * No sign-out button here on purpose. There is nothing useful to sign out of at
 * this point — the account has no access to withdraw — and offering it only
 * invites someone to end a browser session they wanted to keep.
 */
function gasf_crm_render_pending( $status ) {
	echo '<div class="center"><h1>Awaiting approval</h1>';
	if ( 'denied' === $status ) {
		echo '<p>This account does not have access to the club inbox. If you think that is a mistake, speak to whoever looks after the website.</p>';
	} else {
		echo '<p>Your account has been created and is waiting for an administrator to approve it. You will not be able to see the inbox until then — check back later, there is nothing else to do here.</p>';
	}
	echo '</div>';
}

/**
 * Plain-language help.
 *
 * Written for a volunteer who has never seen a ticketing system — no jargon,
 * no "thread", no "queue". The two things it has to get across are that opening
 * a message locks it so two people cannot answer the same one, and that the AI
 * draft is a starting point rather than an answer.
 */
function gasf_crm_render_help() {
	// Name the mailboxes THIS reader actually holds. The old text hardcoded
	// info@, which for a photos-only volunteer described an inbox they have no
	// access to and cannot act on — help that confidently describes the wrong
	// thing is worse than no help.
	$my_streams = gasf_crm_user_streams();
	$boxes      = array();
	foreach ( $my_streams as $k ) {
		$boxes[] = '<strong>' . esc_html( gasf_crm_stream_mailbox( $k ) ) . '</strong>';
	}
	// wp_sprintf's %l is the "a, b and c" list joiner.
	$box_list = $boxes ? wp_sprintf( '%l', $boxes ) : '<strong>the club address</strong>';
	?>
<div class="wrap"><div class="help" id="help" style="display:none">
	<button class="btn sec close" onclick="document.getElementById('help').style.display='none'">Close</button>
	<h2>What this page is</h2>
	<?php /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $box_list is built from esc_html'd parts above. */ ?>
	<p>This is the club's shared mailbox. Anything sent to <?php echo $box_list; ?> turns up here, and any of us can answer it. Replies go out from the club address &mdash; the same one the message arrived at &mdash; with your name at the bottom, so the person who wrote in sees a reply from the club, not from your personal email.</p>

	<?php if ( count( $my_streams ) > 1 ) : ?>
	<h3>You can see more than one mailbox</h3>
	<p>The buttons at the very top of the list switch between them, and <strong>All</strong> shows everything together. Each mailbox has its own colour, shown down the left-hand edge of every message, so you can tell them apart at a glance without stopping to read the label:</p>
	<ul class="key">
		<?php foreach ( $my_streams as $k ) : ?>
		<li data-stream="<?php echo esc_attr( $k ); ?>"><i></i> <span><strong><?php echo esc_html( gasf_crm_stream_label( $k ) ); ?></strong> &mdash; <?php echo esc_html( gasf_crm_stream_mailbox( $k ) ); ?></span></li>
		<?php endforeach; ?>
	</ul>
	<p>A reply always goes back out from the address the message came to. Answer a photo submission and it leaves from the photo address, not the general one &mdash; you do not have to think about it, and the top of the message tells you which it will be.</p>
	<?php endif; ?>

	<h3>The three lists</h3>
	<ul>
		<li><strong>Open</strong> — needs somebody to deal with it. A red dot means nobody has opened it yet.</li>
		<li><strong>Answered</strong> — dealt with. Things you replied to land here, and so do things you forwarded to somebody else. If that person writes to us again, it pops back into Open by itself.</li>
		<li><strong>Ignored</strong> — spam and junk. These stay gone even if the sender emails again.</li>
	</ul>

	<h3>Answering something</h3>
	<ul>
		<li>Click a message in the left-hand list to read it.</li>
		<li>While you have it open it is <strong>locked to you</strong>, so nobody else can answer the same one at the same time. If you wander off it unlocks itself after about 15 minutes.</li>
		<li>Type your reply in the box and press <strong>Send reply</strong>. That is it — it sends, and the message moves to Answered.</li>
	</ul>

	<h3>The other buttons</h3>
	<ul>
		<li><strong>Draft with AI</strong> writes a first attempt for you, based on the club website and the replies the rest of us have already sent. <em>Read it before you send it.</em> It can get things wrong, and it only knows what it has been shown. Edit it freely — it is a starting point to save you typing, not an answer.</li>
		<li><strong>Forward</strong> sends the message on to somebody else — the treasurer, the hall booking person, whoever it really belongs to. You can add a note at the top, and as you type an address it suggests people we have written to before.
			<br>Once you forward something it moves to <strong>Answered</strong> and leaves your list. That is on purpose: it is now their job, and they will write back to the person themselves. You are not waiting on anything.
			<br>There is also a <strong>Forward to Board</strong> button for anything the committee should see. It ignores the address box and goes straight to the board address. It needs <strong>two clicks</strong> — the first arms it and it turns red, the second actually sends. That is on purpose, so a stray click cannot mail the Board by accident. If you change your mind, just wait: it disarms itself after a few seconds.
			<br>Changed your mind, or they need something from us after all? Find it in Answered, open it, and press <em>Put back in Open</em>.</li>
		<li><strong>Attach</strong> adds a file to your reply, from either place:
			<br>&mdash; <strong>Your own computer.</strong> Pick the file and press <em>Attach this file</em>. If it is something we send often, tick the box first and it is saved to the shared library so nobody has to go looking for it again.
			<br>&mdash; <strong>The shared library.</strong> Documents we send regularly &mdash; the membership form, for instance &mdash; are already there. Press <em>Attach</em> next to the one you want.
			<br>Attached files show as small tags above the buttons; press the &times; on one to take it off again. Up to 3 MB per file.</li>
		<li><strong>Ignore</strong> is for anything that needs no reply at all &mdash; spam, junk, mailing lists, sales pitches, messages meant for somebody else. Nothing is sent and the sender hears nothing back.
			<br>It asks you why first &mdash; <em>Spam</em>, <em>Sales pitch</em>, <em>Not relevant</em>, <em>Political</em>, or <em>Other</em> where you type a few words. Picking a reason ignores it straight away, so it takes two deliberate clicks and a stray one cannot bin a message.
			<br>The reason is recorded in the message's History, so months later anyone can see not just that it was ignored but why.</li>
		<li><strong>Mark answered</strong> is for when you handled it some other way — you rang them, or caught them at the club. Nothing is sent, it just clears it off the list.</li>
	</ul>

	<h3>Attachments</h3>
	<p>Files someone sent us appear as small tags under their message — click one and it downloads. Pictures that are part of the message itself, like a logo in somebody's signature, are not listed: you can already see those in the text.</p>
	<p>Two kinds cannot be downloaded here and say so on the tag: a <strong>cloud link</strong> (the sender shared a OneDrive or Dropbox file rather than attaching it) and an <strong>attached email</strong> (they forwarded a message as an attachment). Both need Outlook to open.</p>

	<h3>Seeing who really sent something</h3>
	<p>The sender's name is shown at the top of each message. Hover over the message and their actual email address appears next to it — you can select it, or press <strong>Copy</strong> to put it on the clipboard. Handy when a name looks familiar but the address does not.</p>

	<h3>Who did what</h3>
	<p>At the bottom of every message there is a <strong>History</strong> list. It shows who replied, who forwarded it, who ignored it, and when each of those happened. Nobody can quietly undo something — it is all written down.</p>

	<h3>Why a new message can take a while to show up</h3>
	<p>Two different things happen here, at two different speeds. It is worth knowing which is which, so you do not think something is broken.</p>
	<ul>
		<li><strong>This page updates itself every minute.</strong> The list on the left refreshes on its own. You never need to press anything or reload.</li>
		<li><strong>The club's mailbox is only checked once an hour.</strong> So when somebody sends us an email, it can sit there for up to an hour before it reaches this page.</li>
	</ul>
	<p>That gap is normal, and nothing has gone wrong when it happens.</p>
	<p>If you are expecting something and do not want to wait, press <strong>Check for new mail</strong> at the top of the page. That goes and looks in the mailbox right now, and tells you what it found — including "Nothing new", which is a real answer and not a failure. Otherwise you can simply leave it: everything arrives on its own within the hour.</p>
</div></div>
	<?php
}

/**
 * Header avatar: the provider's photo laid over a circle of initials.
 *
 * The initials are not a placeholder waiting to be swapped out — they ARE the
 * background, and the <img> is painted on top. So a Google avatar URL that has
 * expired or 404'd (they do rotate) removes itself and the initials show
 * through, instead of leaving a volunteer staring at a broken-image icon. A
 * Microsoft account, which never carries a photo at all, lands on exactly the
 * same fallback with no special case.
 *
 * aria-hidden: the name follows in plain text, so this is decoration and a
 * screen reader announcing initials would only say it twice.
 *
 * $name overrides which string the initials come from. The admin table shows
 * the gasf_crm_name meta, which IS refreshed on every sign-in, while
 * display_name is only set at account creation — so the two drift apart the
 * first time somebody renames themselves at the provider, and initials that
 * disagree with the name printed beside them look like a bug.
 */
function gasf_crm_avatar_html( WP_User $user, $name = '' ) {
	$name  = '' !== trim( (string) $name ) ? (string) $name : (string) $user->display_name;
	$words = preg_split( '~\s+~', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
	$cut   = function_exists( 'mb_substr' ) ? 'mb_substr' : 'substr';
	$upper = function_exists( 'mb_strtoupper' ) ? 'mb_strtoupper' : 'strtoupper';

	$ini = '';
	foreach ( array_slice( $words ? $words : array(), 0, 2 ) as $word ) {
		$ini .= $cut( $word, 0, 1 );
	}
	$ini = '' === $ini ? '?' : $upper( $ini );

	$out = '<span class="me" aria-hidden="true">' . esc_html( $ini );

	$url = (string) get_user_meta( $user->ID, 'gasf_crm_avatar', true );
	if ( '' !== $url ) {
		// referrerpolicy: /email is deliberately unlinked and noindex, and the
		// Referer header would otherwise hand its URL to the image host on
		// every single page load.
		$out .= '<img src="' . esc_url( $url ) . '" alt="" referrerpolicy="no-referrer" onerror="this.remove()">';
	}

	return $out . '</span>';
}

function gasf_crm_render_inbox() {
	$user       = wp_get_current_user();
	$my_streams = gasf_crm_user_streams();
	// This used to be hardcoded to info@. With a second mailbox that is simply
	// wrong for a photos-only volunteer — it names an address they cannot see
	// and have no business knowing about. One stream: name theirs. Several: the
	// switcher fills it in, and "All" leaves it blank because no single address
	// applies to a mixed list.
	$one_box = ( 1 === count( $my_streams ) ) ? gasf_crm_stream_mailbox( $my_streams[0] ) : '';
	?>
<header class="bar"><div class="wrap">
	<h1>Club inbox<span class="box" id="hbox"><?php echo $one_box ? ' &mdash; ' . esc_html( $one_box ) : ''; ?></span></h1>
	<div>
		<?php if ( gasf_crm_user_can_stream( 'photos' ) ) : ?>
			<button class="hbtn" id="toview" data-view="photos">Photos</button>
		<?php endif; ?>
		<button class="hbtn" id="checkmail">Check for new mail</button>
		<button class="hbtn" onclick="var h=document.getElementById('help');h.style.display=h.style.display==='none'?'block':'none';window.scrollTo(0,0)">Help</button>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
		echo gasf_crm_avatar_html( $user );
		echo esc_html( $user->display_name );
		?> &middot;
		<a href="<?php echo esc_url( home_url( '/email/logout' ) ); ?>">Sign out</a>
	</div>
</div></header>

<?php
	// Only at 'alert', never at 'failing'. A volunteer can do nothing about a
	// transient Graph blip, and a banner that cries wolf over one failed hourly
	// run is a banner people learn to read past.
	$health = gasf_crm_health_state();
	if ( 'alert' === $health['state'] ) :
		$down_hours = (int) round( $health['down_for'] / HOUR_IN_SECONDS );
		?>
	<div class="wrap"><div class="note err" style="margin-top:16px">
		<strong>New mail is not arriving.</strong>
		The club mailbox has not been reachable for <?php echo (int) $down_hours; ?> hours, so anything sent to us
		since then is not shown below. Nothing is lost &mdash; it is sitting in the mailbox and will appear as soon
		as this is fixed &mdash; but nobody is seeing it, so nobody is replying. Please tell whoever looks after the website.
	</div></div>
	<?php endif; ?>

<?php
	// Somebody who filled in the tagging form was told a volunteer would check
	// it over. This is where that becomes visible without having to guess which
	// thread to reopen. Only shown to people who hold the photos stream, and
	// only when there is genuinely something waiting.
	if ( function_exists( 'gasf_crm_photo_actionable_threads' ) && gasf_crm_user_can_stream( 'photos' ) ) :
		$waiting   = gasf_crm_photo_actionable_threads();
		$described = array_sum( wp_list_pluck( $waiting, 'described' ) );
		$released  = array_sum( wp_list_pluck( $waiting, 'released' ) );
		if ( $described + $released ) : ?>
	<div class="wrap"><div class="note ok" style="margin-top:16px">
		<?php
		// Two different jobs, said separately. "Check what they told us" and
		// "nobody replied, work it out yourself" take different amounts of
		// effort, and a single blended number tells you neither.
		$bits = array();
		if ( $described ) {
			$bits[] = sprintf( '<strong>%d photo%s described by the sender</strong>, waiting for you to check',
				(int) $described, 1 === (int) $described ? '' : 's' );
		}
		if ( $released ) {
			$bits[] = sprintf( '<strong>%d photo%s the sender never replied about</strong>, now yours to label',
				(int) $released, 1 === (int) $released ? '' : 's' );
		}
		echo wp_kses_post( ucfirst( implode( ', and ', $bits ) ) ) . '.';
		?>
		<div style="margin-top:8px">
			<?php foreach ( $waiting as $tid => $n ) :
				$th = gasf_crm_get_thread( (int) $tid );
				if ( ! $th || ! gasf_crm_user_can_stream( (string) $th['stream'] ) ) { continue; } ?>
				<button class="btn sec" data-openthread="<?php echo (int) $tid; ?>" style="margin:0 6px 6px 0">
					<?php echo esc_html( $th['subject'] ? $th['subject'] : '(no subject)' ); ?>
					&middot; <?php echo (int) ( $n['described'] + $n['released'] ); ?>
				</button>
			<?php endforeach; ?>
		</div>
	</div></div>
		<?php endif;
	endif;
	?>

<?php gasf_crm_render_help(); ?>

<?php if ( gasf_crm_user_can_stream( 'photos' ) ) : ?>
<div class="wrap" id="photoview" hidden><div class="layout">
	<div class="card">
		<div class="tabs pstates">
			<button class="on" data-pstate="review">Needs you</button>
			<button data-pstate="waiting">With the sender</button>
			<button data-pstate="done">Done</button>
			<button data-pstate="all">All</button>
		</div>
		<div class="list pgrid" id="pgrid"><div class="pane muted">Loading…</div></div>
	</div>
	<div class="card"><div class="pane" id="ppane" data-stream="photos">
		<p class="muted">Pick a photo on the left.</p>
	</div></div>
</div></div>
<?php endif; ?>

<div class="wrap" id="mailview"><div class="layout">
	<div class="card">
		<?php
		// The mailbox switcher only appears for somebody who holds more than one
		// stream. A volunteer granted photos alone sees no switcher at all — the
		// existence of a general inbox is not their business.
		$my_streams = gasf_crm_user_streams();
		if ( count( $my_streams ) > 1 ) : ?>
		<div class="tabs streams">
			<button class="on" data-stream="">All</button>
			<?php foreach ( $my_streams as $k ) : ?>
				<button data-stream="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( gasf_crm_stream_label( $k ) ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<div class="tabs">
			<button class="on" data-status="open">Open</button>
			<button data-status="addressed">Answered</button>
			<button data-status="ignored">Ignored</button>
		</div>
		<div class="list" id="list"><div class="pane muted">Loading…</div></div>
	</div>
	<div class="card"><div class="pane" id="pane">
		<p class="muted">Select a message on the left.</p>
	</div></div>
</div></div>

<datalist id="contacts"></datalist>

<script>
(function(){
	var API   = <?php echo wp_json_encode( rest_url( 'gasf/v1/crm' ) ); ?>;
	var NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
	var BOARD = <?php echo wp_json_encode( (string) gasf_crm_cfg()['board_address'] ); ?>;
	var IGNORE_REASONS = <?php echo wp_json_encode( array_values( gasf_crm_ignore_reasons() ) ); ?>;
	// Only the streams THIS user may see. The server intersects anyway, so this
	// is for rendering, not for security.
	var STREAMS = <?php
		$mine = array();
		foreach ( gasf_crm_user_streams() as $k ) {
			$mine[] = array( 'key' => $k, 'label' => gasf_crm_stream_label( $k ), 'mailbox' => gasf_crm_stream_mailbox( $k ) );
		}
		echo wp_json_encode( $mine );
	?>;
	// The same places the submitter is offered, so a volunteer correcting an
	// answer picks from the same vocabulary rather than retyping into a box and
	// inventing a near-duplicate term. label is decoded for reading; name is the
	// stored form, which is what has to go back.
	var PLACES = <?php
		$pl = array();
		if ( function_exists( 'gasf_photo_place_tree' ) ) {
			$names = array();
			foreach ( gasf_photo_place_tree( 0 ) as $r ) { $names[ (int) $r['term']->term_id ] = $r['term']->name; }
			foreach ( gasf_photo_place_tree( 0 ) as $r ) {
				$pid = (int) $r['term']->parent;
				$pl[] = array(
					'name'   => $r['term']->name,
					'label'  => gasf_photo_label( $r['term']->name ),
					'depth'  => (int) $r['depth'],
					'parent' => isset( $names[ $pid ] ) ? $names[ $pid ] : '',
				);
			}
		}
		echo wp_json_encode( $pl );
	?>;

	var stream = ''; // '' = every stream this user can see
	var list = document.getElementById('list'), pane = document.getElementById('pane');
	var status = 'open', current = null, currentStamp = null;

	function api(path, opts){
		opts = opts || {};
		opts.headers = Object.assign({'X-WP-Nonce': NONCE, 'Content-Type':'application/json'}, opts.headers||{});
		opts.credentials = 'same-origin';
		return fetch(API + path, opts).then(function(r){
			return r.json().then(function(b){
				if(!r.ok){ throw new Error((b && b.message) || ('Error ' + r.status)); }
				return b;
			});
		});
	}
	// Escapes for BOTH text and quoted-attribute positions.
	//
	// The obvious implementation — textContent in, innerHTML out — escapes <, >
	// and & but leaves quotes alone. That is safe in a text node and unsafe in
	// an attribute, and this file interpolates into attributes constantly
	// (data-addr, data-name, href). A sender address or an attachment filename
	// containing a double quote would close the attribute and open a new one:
	// both are chosen by whoever emailed the club.
	function esc(s){
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}
	function when(s){
		if(!s) return '';
		// Stored UTC — the trailing Z is what makes the browser render it in the
		// reader's own timezone instead of treating it as local and shifting it.
		var d = new Date(s.replace(' ','T') + 'Z');
		return isNaN(d) ? s : d.toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
	}

	// Clearing the pane also drops its stream colour, so the empty state follows
	// the page instead of keeping the tint of whatever was last open.
	function clearPane(){
		pane.removeAttribute('data-stream');
		pane.innerHTML = '<p class="muted">Select a message on the left.</p>';
	}

	function loadList(){
		return api('/threads?status=' + status + (stream ? '&stream=' + encodeURIComponent(stream) : '')).then(function(rows){
			// If the thread on screen has grown a newer message, say so rather
			// than reloading underneath someone who is mid-reply.
			if(current){
				for(var i=0;i<rows.length;i++){
					if(rows[i].id === current && currentStamp && rows[i].last !== currentStamp){
						flagNewMessage();
						break;
					}
				}
			}
			if(!rows.length){ list.innerHTML = '<div class="pane muted">Nothing here.</div>'; return; }
			list.innerHTML = rows.map(function(t){
				var lock = t.locked_by && !t.locked_mine
					? '<div class="meta">🔒 ' + esc(t.locked_by) + ' is replying</div>' : '';
				// Which inbox a thread came from, but only when the reader can see
				// more than one — otherwise every row would carry a label that
				// never varies.
				var tag = '';
				if (STREAMS.length > 1 && !stream) {
					for (var s = 0; s < STREAMS.length; s++) {
						if (STREAMS[s].key === t.stream) { tag = '<span class="streamtag">' + esc(STREAMS[s].label) + '</span>'; }
					}
				}
				// data-stream drives the row's colour: the palette block keys off
				// it, so the left edge and the tag cannot drift apart.
				return '<div class="item' + (current === t.id ? ' on' : '') + '" data-id="' + t.id +
					'" data-stream="' + esc(t.stream) + '">' +
					'<div class="who"><span>' + (t.status === 'new' ? '<span class="dot"></span>' : '') +
					esc(t.from) + '</span><span class="meta">' + esc(when(t.last)) + '</span></div>' +
					'<div class="subj">' + esc(t.subject || '(no subject)') + tag + '</div>' + lock + '</div>';
			}).join('');
			Array.prototype.forEach.call(list.querySelectorAll('.item'), function(el){
				el.onclick = function(){ open(parseInt(el.dataset.id, 10)); };
			});
		}).catch(function(e){ list.innerHTML = '<div class="pane note err">' + esc(e.message) + '</div>'; });
	}

	function flagNewMessage(){
		if(document.getElementById('newmsg')) return;
		var b = document.createElement('div');
		b.id = 'newmsg'; b.className = 'note warn';
		b.innerHTML = 'A new message has arrived on this conversation. ' +
			'<a href="#" id="reloadthread">Reload it</a> — your draft below will be lost.';
		pane.insertBefore(b, pane.firstChild);
		document.getElementById('reloadthread').onclick = function(ev){ ev.preventDefault(); open(current); };
	}

	function history(events){
		if(!events || !events.length) return '';
		var rows = events.map(function(e){
			var verb = {
				received:        'received a message',
				replied:         'replied',
				replied_outlook: 'replied outside this page',
				forwarded:       'forwarded it on',
				addressed:       'marked it answered',
				ignored:         'ignored it',
				restored:        'put it back in Open',
				reopened:        'reopened — new message arrived'
			}[e.action] || e.action;
			return '<li><b>' + esc(e.actor) + '</b> ' + esc(verb) +
				' <span class="t">— ' + esc(when(e.at)) + '</span>' +
				(e.detail ? '<br><span class="t">' + esc(e.detail) + '</span>' : '') + '</li>';
		}).join('');
		return '<div class="hist"><h3>History</h3><ul>' + rows + '</ul></div>';
	}

	function open(id){
		current = id;
		attached = []; // attachments belong to the reply being written, not to the app
		pane.innerHTML = '<p class="muted">Loading…</p>';
		api('/threads/' + id).then(function(t){
			// The pane takes the THREAD's mailbox colour, whichever list it was
			// opened from: in the All view the surrounding chrome is the club's
			// gold, but this particular message may not be a general one.
			pane.setAttribute('data-stream', t.stream || '');
			var badge = t.status === 'ignored' ? ' <span class="badge ig">Ignored</span>'
				: (t.status === 'addressed' ? ' <span class="badge an">Answered</span>' : '');
			var html = '<h2 style="margin:0 0 16px;font-size:18px">' + esc(t.subject || '(no subject)') + badge + '</h2>';

			// Which address the reply will leave from. Only worth saying to
			// somebody who holds more than one mailbox — otherwise it never
			// varies and is just another line to read past.
			if(STREAMS.length > 1 && t.mailbox){
				html += '<div class="frombox">Replies go out from <code>' + esc(t.mailbox) + '</code></div>';
			}

			if(!t.can_reply && t.locked_by){
				html += '<div class="note warn">' + esc(t.locked_by) + ' is replying to this. You can read it, but not send.</div>';
			}

			// On a submission thread the photos ARE the job, so they go above the
			// message and the reply box goes under it. The email on these is
			// almost always "see attached"; leading with a reply form asks the
			// wrong question and buries the right one.
			//
			// True whenever the thread has photos at all, not only when one is
			// waiting on somebody: even a card that just says "with the sender
			// until the 1st" tells a volunteer more, at a glance, than the words
			// "See attached." ever will.
			var pb = photoBlock(t);
			var photosFirst = (t.photos || []).length > 0;
			if (photosFirst) {
				html += pb;
				html += '<h3 class="mailhead">The email it arrived with</h3>';
			}

			t.messages.forEach(function(m){
				// A cloud link or an attached email has nothing to download, so it
				// is labelled rather than dressed up as a file — clicking still
				// explains why, but the chip says it first.
				var atts = (m.attachments||[]).map(function(a){
					var icon = a.kind === 'link' ? '🔗' : (a.kind === 'email' ? '✉️' : '📎');
					var note = a.kind === 'link' ? ' (cloud link)' : (a.kind === 'email' ? ' (attached email)' : '');
					var cls  = a.kind === 'file' ? 'att' : 'att att--noload';
					var chip = '<a class="' + cls + '" href="' + esc(a.url) + '">' + icon + ' ' +
						esc(a.name) + esc(note) + '</a>';
					// Only real image attachments can be kept. A cloud link has no
					// bytes and an attached email is an .eml, so offering this on
					// either would be a button whose only outcome is an error.
					if (a.image && t.can_reply) {
						chip += '<button class="keep" data-msg="' + esc(a.msg) + '" data-att="' + esc(a.id) +
							'" title="Copy this into the club’s photo collection">Keep photo</button>';
					}
					return chip;
				}).join('');
				// Only on inbound: outbound is always the club mailbox, so showing
				// it on every reply would be noise rather than information.
				var addr = (m.direction === 'in' && m.from_addr)
					? ' <span class="addr"><code>' + esc(m.from_addr) + '</code>' +
					  '<button type="button" class="copy" data-addr="' + esc(m.from_addr) + '">Copy</button></span>'
					: '';
				html += '<div class="msg ' + (m.direction === 'out' ? 'out' : '') + '">' +
					'<div class="hd"><b>' + esc(m.from) + '</b>' + addr + ' &middot; ' + esc(when(m.sent_at)) + '</div>' +
					'<div class="body">' + m.body + '</div>' + atts + '</div>';
			});

			if(t.status === 'ignored'){
				html += '<div class="note warn">This was marked as spam or junk, so it stays out of the Open list even if the sender writes again.</div>' +
					'<div class="actions"><button class="btn sec" id="restore">Put back in Open</button></div><div id="msg"></div>';
			} else if(t.status === 'addressed'){
				// Answered threads get a way back too. Forwarding closes a thread
				// now, and sometimes the answer turns out to be "they still need
				// something from us" — without this that is a dead end.
				html += '<div class="note ok">This is answered. If they write again it returns to Open by itself.</div>' +
					'<div class="actions"><button class="btn sec" id="restore">Put back in Open</button></div><div id="msg"></div>';
			} else if(t.can_reply){
				html += '<div class="ed"><div class="edbar">' +
						'<button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>' +
						'<button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>' +
						'<button type="button" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>' +
						'<span class="sep"></span>' +
						'<button type="button" data-cmd="insertUnorderedList" title="Bulleted list">&bull; List</button>' +
						'<button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>' +
						'<span class="sep"></span>' +
						'<button type="button" id="edlink" title="Add a link">&#128279; Link</button>' +
						'<button type="button" data-cmd="unlink" title="Remove the link">Unlink</button>' +
						'<span class="sep"></span>' +
						'<button type="button" data-cmd="removeFormat" title="Clear formatting">Clear</button>' +
					'</div>' +
					'<div class="edbody" id="reply" contenteditable="true" data-ph="Write your reply…"></div></div>' +
					'<div id="atrow"></div>' +
					'<div class="actions">' +
					'<button class="btn" id="send">Send reply</button>' +
					'<button class="btn sec" id="draft">Draft with AI</button>' +
					'<button class="btn sec" id="attopen">Attach…</button>' +
					'<button class="btn sec" id="fwdopen">Forward…</button>' +
					'<button class="btn sec" id="done">Mark answered</button>' +
					// "Ignore…", not "Ignore (spam)". The parenthetical was a useful
					// hint when the button just binned things, but the picker now
					// offers four non-spam reasons and a label narrower than the
					// action suppresses correct use: a volunteer looking at a
					// vendor pitch decides it "isn't spam" and leaves it in Open.
					// The ellipsis matches Attach… and Forward… — it opens something.
					'<button class="btn warn" id="ignore">Ignore…</button>' +
					'</div>' +
					// The reason picker IS the confirmation — opening it is one
					// deliberate click and choosing a reason is a second, so a
					// stray click cannot bin a message, and we get a real audit
					// entry instead of a yes/no nobody can interpret later.
					'<div class="fwd" id="ign" style="display:none">' +
						'<label>Why are you ignoring this? Picking a reason ignores it straight away.</label>' +
						'<div class="ignpicks">' +
						IGNORE_REASONS.map(function(r){
							return '<button type="button" class="btn sec ignpick" data-r="' + esc(r) + '">' + esc(r) + '</button>';
						}).join('') +
						'<button type="button" class="btn sec" id="ignother">Other…</button>' +
						'<button type="button" class="btn sec" id="igncancel">Cancel</button>' +
						'</div>' +
						'<div id="ignotherbox" style="display:none;margin-top:12px">' +
							'<label>Say why, in a few words<input type="text" id="ignreason" maxlength="120" ' +
								'placeholder="e.g. Not relevant to our organization"></label>' +
							'<div class="actions"><button class="btn warn" id="ignsend">Ignore this message</button></div>' +
						'</div>' +
					'</div>' +
					'<div class="fwd" id="att" style="display:none">' +
						'<label>Attach a file from your computer<input type="file" id="atfile"></label>' +
						'<label style="font-weight:400"><input type="checkbox" id="atkeep"> ' +
							'Also keep this in the shared library, so anyone can attach it next time</label>' +
						'<div class="actions">' +
						'<button class="btn" id="atupload">Attach this file</button>' +
						'<button class="btn sec" id="atclose">Close</button></div>' +
						'<div class="lib" id="atlib"><h4>Shared library</h4>' +
							'<p class="muted">Loading…</p></div>' +
					'</div>' +
					'<div class="fwd" id="fwd" style="display:none">' +
						'<label>Send this on to<input type="text" id="fwdto" list="contacts" ' +
							'placeholder="name@example.com" autocomplete="off"></label>' +
						'<label>Add a note (optional)<textarea id="fwdnote" ' +
							'placeholder="e.g. Karl, can you take this one?"></textarea></label>' +
						'<div class="actions">' +
						'<button class="btn" id="fwdsend">Send forward</button>' +
						(BOARD ? '<button class="btn sec" id="fwdboard">Forward to Board</button>' : '') +
						'<button class="btn sec" id="fwdcancel">Cancel</button></div>' +
						(BOARD ? '<p class="muted" style="margin:8px 0 0">The Board button ignores the address above and sends to <strong>' +
							esc(BOARD) + '</strong>. It needs two clicks.</p>' : '') +
					'</div>' +
					'<div id="msg"></div>';
			}

			if (!photosFirst) { html += pb; }
			html += history(t.events);
			pane.innerHTML = html;
			wire(id, t.status);
			wireCopy();
			wirePhotos(id, t);

			// Remember where this conversation was, so the minute refresh can
			// tell whether it has moved on since.
			api('/threads?status=' + status).then(function(rows){
				rows.forEach(function(r){ if(r.id === id){ currentStamp = r.last; } });
			}).catch(function(){});

			loadList();
		}).catch(function(e){ pane.innerHTML = '<div class="note err">' + esc(e.message) + '</div>'; });
	}

	// Minimal rich text on a contenteditable div. execCommand is deprecated but
	// universally supported, and pulling in an editor library would be a large
	// dependency on a page whose entire requirement is bold, italic and links.
	function setupEditor(ed){
		Array.prototype.forEach.call(document.querySelectorAll('.edbar button[data-cmd]'), function(b){
			// mousedown default would move focus out of the editor and collapse
			// the selection before the command could apply to it.
			b.onmousedown = function(ev){ ev.preventDefault(); };
			b.onclick = function(){ document.execCommand(b.dataset.cmd, false, null); ed.focus(); };
		});

		var link = document.getElementById('edlink');
		if(link){
			link.onmousedown = function(ev){ ev.preventDefault(); };
			link.onclick = function(){
				var url = prompt('Link address:', 'https://');
				if(!url) return;
				// Only protocols that cannot execute anything. The server checks
				// this again — a client-side filter is a convenience, not a control.
				if(!/^(https?:|mailto:)/i.test(url.trim())){
					alert('Links must start with http://, https:// or mailto:');
					return;
				}
				ed.focus();
				document.execCommand('createLink', false, url.trim());
			};
		}

		// Paste as plain text. Pasting from Word or a web page otherwise drags in
		// fonts, colours and background shading that look wrong in an email and
		// get stripped by the server regardless.
		ed.addEventListener('paste', function(ev){
			ev.preventDefault();
			var text = ((ev.clipboardData || window.clipboardData).getData('text/plain') || '');
			document.execCommand('insertText', false, text);
		});
	}

	// Copy-to-clipboard for sender addresses. navigator.clipboard needs a secure
	// context and can still be refused by permissions policy, so the old
	// select-and-execCommand route stays as a fallback rather than leaving the
	// button silently doing nothing.
	function wireCopy(){
		Array.prototype.forEach.call(document.querySelectorAll('.copy'), function(b){
			b.onclick = function(){
				var value = b.getAttribute('data-addr');
				var flash = function(){
					b.textContent = 'Copied'; b.classList.add('done');
					setTimeout(function(){ b.textContent = 'Copy'; b.classList.remove('done'); }, 1500);
				};
				if(navigator.clipboard && navigator.clipboard.writeText){
					navigator.clipboard.writeText(value).then(flash, function(){ copyFallback(value, flash); });
				} else {
					copyFallback(value, flash);
				}
			};
		});
	}

	function copyFallback(value, done){
		var i = document.createElement('input');
		i.value = value;
		i.style.position = 'fixed'; i.style.opacity = '0';
		document.body.appendChild(i);
		i.select();
		try { document.execCommand('copy'); done(); } catch(e){ /* selection stays; copy by hand */ }
		document.body.removeChild(i);
	}

	function edText(ed){ return (ed.textContent || '').trim(); }
	function edSet(ed, plain){
		// The AI draft arrives as plain text. Split on blank lines so it lands in
		// the editor already looking like an email rather than one long block.
		ed.innerHTML = String(plain).split(/\n{2,}/).map(function(p){
			return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>';
		}).join('');
	}

	/* ---- outbound attachments ------------------------------------------- */

	// Files are uploaded the moment they are picked, not held in the browser
	// until send. The reply then references ids, so a dropped connection at send
	// time costs you the click rather than the file.
	var attached = [];

	function renderChips(){
		var el = document.getElementById('atrow');
		if(!el) return;
		if(!attached.length){ el.innerHTML = ''; return; }
		el.innerHTML = attached.map(function(a, i){
			return '<span class="chip">' + esc(a.name) + ' <span class="muted">' + esc(a.human) + '</span>' +
				'<button type="button" data-i="' + i + '" title="Remove">&times;</button></span>';
		}).join('');
		Array.prototype.forEach.call(el.querySelectorAll('.chip button'), function(b){
			b.onclick = function(){
				attached.splice(parseInt(b.getAttribute('data-i'), 10), 1);
				renderChips();
			};
		});
	}

	function addAttachment(a){
		for(var i = 0; i < attached.length; i++){ if(attached[i].id === a.id){ return; } }
		attached.push(a);
		renderChips();
	}

	// Deliberately not routed through api(): that helper forces a JSON content
	// type, and multipart needs the browser to set its own boundary.
	function uploadFile(file, keep){
		var fd = new FormData();
		fd.append('file', file);
		if(keep){ fd.append('keep', '1'); }
		return fetch(API + '/attachments', {
			method: 'POST',
			headers: {'X-WP-Nonce': NONCE},
			credentials: 'same-origin',
			body: fd
		}).then(function(r){
			return r.json().then(function(b){
				if(!r.ok){ throw new Error((b && b.message) || ('Upload failed (' + r.status + ')')); }
				return b;
			});
		});
	}

	function loadLibrary(){
		var el = document.getElementById('atlib');
		if(!el) return;
		api('/attachments').then(function(rows){
			var html = '<h4>Shared library</h4>';
			if(!rows.length){
				html += '<p class="muted">Nothing saved yet. Tick the box above when you attach something and it will appear here for everyone.</p>';
			} else {
				html += rows.map(function(a){
					return '<div class="row"><span>' + esc(a.label || a.name) +
						' <span class="muted">' + esc(a.human) + '</span></span>' +
						'<button type="button" class="btn sec libpick" data-id="' + a.id +
						'" data-name="' + esc(a.name) + '" data-human="' + esc(a.human) + '">Attach</button></div>';
				}).join('');
			}
			el.innerHTML = html;
			Array.prototype.forEach.call(el.querySelectorAll('.libpick'), function(b){
				b.onclick = function(){
					addAttachment({
						id:    parseInt(b.getAttribute('data-id'), 10),
						name:  b.getAttribute('data-name'),
						human: b.getAttribute('data-human')
					});
				};
			});
		}).catch(function(e){
			el.innerHTML = '<h4>Shared library</h4><div class="note err">' + esc(e.message) + '</div>';
		});
	}

	// Places as a dropdown, matching what the submitter was offered.
	//
	// A value that is NOT one of our places — a sender typed somewhere we do not
	// have — selects "Somewhere else" and keeps their words in the box beside
	// it, rather than being silently dropped for not matching a term.
	function placeSelect(current){
		current = current || '';
		var known = false;
		var opts = '<option value=""' + (current ? '' : ' selected') + '>— not sure —</option>';

		PLACES.forEach(function(pl){
			if (pl.name === current) { known = true; }
			var pad = '';
			for (var i = 0; i < Math.min(2, pl.depth); i++) { pad += '   '; }
			opts += '<option value="' + esc(pl.name) + '"' + (pl.name === current ? ' selected' : '') + '>' +
				pad + esc(pl.label || pl.name) + '</option>';
		});

		var other = current && !known;
		opts += '<option value="__other"' + (other ? ' selected' : '') + '>Somewhere else…</option>';

		return '<select class="p-place">' + opts + '</select>' +
			'<input type="text" class="p-placeother" placeholder="Where was it?" value="' +
			(other ? esc(current) : '') + '"' + (other ? '' : ' hidden') + '>';
	}

	// What to send for "place": the typed box wins when it is in use.
	function placeValue(root){
		var sel = root.querySelector('.p-place');
		var oth = root.querySelector('.p-placeother');
		if (!sel) { return ''; }
		if ('__other' === sel.value) { return oth ? oth.value.trim() : ''; }
		return sel.value;
	}

	// Reveal the free-text box only while "Somewhere else" is chosen.
	function wirePlaceSelects(root){
		Array.prototype.forEach.call(root.querySelectorAll('.p-place'), function(sel){
			var box = sel.parentNode.querySelector('.p-placeother');
			sel.onchange = function(){
				if (!box) { return; }
				box.hidden = ('__other' !== sel.value);
				if (!box.hidden) { box.focus(); }
			};
		});
	}

	// One box per person, and a button to add another.
	//
	// This was a single comma-separated field, on the reasoning that a volunteer
	// is correcting a list rather than composing one. That reasoning was wrong in
	// the way that matters: the sender's form has "+ Add another person", so the
	// volunteer checking their answers had fewer ways to name people than the
	// stranger who sent the photos in. Nothing about the comma field said a
	// second name was even possible.
	function peopleField(names){
		var list = (names || []).filter(Boolean);
		if (!list.length) { list = ['']; }
		var s = '<div class="p-people">';
		list.forEach(function(n){ s += personBox(n); });
		return s + '</div><button type="button" class="addp">+ Add another person</button>';
	}

	function personBox(v){
		return '<input type="text" class="p-person" maxlength="80" value="' + esc(v || '') + '" placeholder="Name" autocomplete="off">';
	}

	// Every non-empty box, in the order they appear. Trimmed and de-duplicated,
	// because "Hans" typed twice is one person and the taxonomy would otherwise
	// be asked to hold him twice.
	function peopleValues(root){
		var out = [];
		Array.prototype.forEach.call(root.querySelectorAll('.p-person'), function(el){
			var v = el.value.trim();
			if (v && out.indexOf(v) === -1) { out.push(v); }
		});
		return out;
	}

	// Clones a box onto the end and puts the cursor in it, so adding three
	// people is three clicks and three names rather than a guess about commas.
	function wirePeople(root){
		Array.prototype.forEach.call(root.querySelectorAll('.addp'), function(b){
			b.onclick = function(){
				var box = b.previousElementSibling;
				if (!box || !box.classList.contains('p-people')) { return; }
				box.insertAdjacentHTML('beforeend', personBox(''));
				box.lastElementChild.focus();
			};
		});
	}

	// The labelling form: identical whether the sender filled it in or nobody
	// did. A volunteer working from scratch needs exactly the fields a
	// volunteer checking somebody's answers needs, so there is one of them.
	function photoForm(p, q){
		var s = '<div class="pf"><span>Who is in it</span>' + peopleField(q.people || []) + '</div>' +
			'<label class="pf"><span>What is happening</span>' +
			'<input type="text" class="p-caption" maxlength="150" value="' + esc(q.caption||'') + '"></label>' +
			'<div class="prow">' +
			'<label class="pf"><span>Where</span>' + placeSelect(q.place || p.guess || '') + '</label>' +
			'<label class="pf"><span>Occasion</span><input type="text" class="p-event" value="' + esc(q.event||'') + '"></label>' +
			'<label class="pf"><span>Date</span><input type="date" class="p-taken" value="' + esc(q.taken||p.taken||'') + '"></label>' +
			'</div>' +
			// What the club had on that day. Populated from the date field and
			// refreshed whenever it changes, because correcting the date is
			// exactly when the right occasion becomes knowable.
			'<div class="pev"><div class="pevlist muted">…</div>' +
			'<input type="text" class="p-evsearch" placeholder="…or search the calendar by name">' +
			'</div>' +
			// Carries through the event the submitter picked, so a volunteer who
			// changes nothing does not silently drop the link to it.
			'<input type="hidden" class="p-evid" value="' + esc(q.event_id || '') + '">';

		// The camera's own guess is shown next to what the sender typed, never
		// merged into it. They disagree often enough — GPS is wider than a tight
		// geofence — that quietly preferring one would be inventing a fact.
		if (p.guess) {
			s += '<p class="pgeo">Camera put this at <strong>' + esc(p.guess) + '</strong>' +
				(p.alts && p.alts.length ? ' (also inside ' + esc(p.alts.join(', ')) + ')' : '') + '.</p>';
		}
		// The revision the volunteer is actually looking at, sent back with the
		// decision so a stale screen is refused rather than obeyed.
		return s + '<input type="hidden" class="p-rev" value="' + esc(p.revision != null ? p.revision : '') + '">' +
			'<div class="actions"><button class="btn p-ok">Add these tags</button>' +
			'<span class="p-msg muted"></span></div>';
	}

	// Photos kept from this submission and where each one sits in the chase.
	// Purgatory shows NO form: the person who actually knows has been asked and
	// still has days to answer, and putting a blank form in front of a volunteer
	// meanwhile is asking two people the same question.
	function photoBlock(t){
		var ph = t.photos || [];

		// Nothing kept yet. If images came in, say what to do with them —
		// otherwise the only clue is a small button beside an attachment chip,
		// which never explains that keeping is what unlocks asking the sender
		// anything. Somebody sent five photos and waited for an email that was
		// never going to come, because this block rendered nothing at all.
		if (!ph.length) {
			var imgs = 0, who = '';
			(t.messages || []).forEach(function(m){
				if (m.direction === 'in' && !who) { who = m.from; }
				(m.attachments || []).forEach(function(a){ if (a.image) { imgs++; } });
			});
			if (!imgs) { return ''; }

			return '<div class="photos"><h3>Photos in this email (' + imgs + ')</h3>' +
				'<p class="muted">None kept yet. Press <strong>Keep photo</strong> beside the ones worth having, ' +
				'above &mdash; each goes into the club&rsquo;s Media Library. Once at least one is kept, ' +
				'a button appears here to ask ' + esc(who || 'the sender') + ' what they are.</p></div>';
		}

		var head = '<div class="photos"><h3>Photos kept from this email (' + ph.length + ')</h3>';

		var cards = ph.map(function(p){
			var s = '<div class="pcard" data-photo="' + p.id + '">' +
				'<a class="pthumb" href="' + esc(p.link) + '" target="_blank" rel="noopener">' +
				(p.thumb ? '<img src="' + esc(p.thumb) + '" alt="">' : '') + '</a>' +
				'<div class="pbody">';

			if (p.confirmed) {
				s += '<div class="pdone">✓ Tagged' + (p.people.length ? ' — ' + esc(p.people.join(', ')) : '') + '</div>' +
					(p.caption ? '<p class="muted">' + esc(p.caption) + '</p>' : '') +
					// Offered only once it is tagged: before that the name would
					// have nothing in it worth carrying.
					(p.dlname && p.url
						? '<a class="att" href="' + esc(p.url) + '" download="' + esc(p.dlname) + '">⬇ ' + esc(p.dlname) + '</a>'
						: '');

			} else if (p.state === 'waiting') {
				s += '<div class="muted">Waiting on the sender until <strong>' + esc(p.release) + '</strong>. ' +
					'They have been asked, and reminded once. If they never reply it becomes yours to label.' +
					(p.taken ? ' The camera said ' + esc(p.taken) + '.' : '') + '</div>' +
					// Never blocked, only un-nagged. Somebody who happens to know
					// should not have to wait five days to say so.
					'<div class="actions"><button class="btn sec p-early">I know what this is — label it now</button></div>' +
					'<div class="pedit" hidden>' + photoForm(p, p.saved || {}) + '</div>';

			} else if (p.pending) {
				s += '<div class="pfrom">The sender says:</div>' + photoForm(p, p.pending);

			} else if (p.state === 'released') {
				s += '<div class="pfrom">The sender never replied &mdash; label it from what you can see:</div>' +
					photoForm(p, p.saved || {});

			} else {
				s += '<div class="pfrom">Nobody has been asked about this one:</div>' + photoForm(p, p.saved || {});
			}

			return s + '</div></div>';
		}).join('');

		var ask = '<div class="actions" style="margin-top:12px">' +
			'<button class="btn sec" id="askdetails">Ask the sender what these are</button>' +
			'<span id="askmsg" class="muted"></span></div>' +
			'<p class="muted" style="margin:8px 0 0">Sends them a private link, good for 30 days, asking who is in the photos and when they were taken. Their answers come back here for you to approve — nothing they type becomes a tag on its own.</p>';

		return head + cards + ask + '</div>';
	}

	function wirePhotos(id, t){
		// "Keep photo" on an attachment chip.
		Array.prototype.forEach.call(pane.querySelectorAll('.keep'), function(b){
			b.onclick = function(){
				b.disabled = true; b.textContent = 'Keeping…';
				api('/photos/approve', { method:'POST', body: JSON.stringify({
					id: id, msg: b.dataset.msg, att: b.dataset.att
				})}).then(function(){
					b.textContent = '✓ Kept';
					open(id); // redraw so the photo appears in the block below
				}).catch(function(e){
					b.disabled = false; b.textContent = 'Keep photo';
					alert(e.message);
				});
			};
		});

		var ask = document.getElementById('askdetails'), askmsg = document.getElementById('askmsg');
		if (ask) {
			ask.onclick = function(){
				ask.disabled = true; askmsg.textContent = 'Sending…';
				api('/photos/invite', { method:'POST', body: JSON.stringify({ id: id }) })
					.then(function(r){
						askmsg.textContent = 'Asked ' + r.to + ' about ' + r.photos + ' photo(s).';
					})
					.catch(function(e){ ask.disabled = false; askmsg.textContent = e.message; });
			};
		}

		// "I know what this is" reveals the form during the grace period.
		Array.prototype.forEach.call(pane.querySelectorAll('.p-early'), function(b){
			b.onclick = function(){
				var box = b.closest('.pcard').querySelector('.pedit');
				if (box) { box.hidden = false; }
				b.remove();
			};
		});

		wireEventPickers(pane);
		wirePlaceSelects(pane);
		wirePeople(pane);

		Array.prototype.forEach.call(pane.querySelectorAll('.pcard'), function(card){
			var ok = card.querySelector('.p-ok');
			if (!ok) { return; }
			ok.onclick = function(){
				var msg = card.querySelector('.p-msg');
				ok.disabled = true; msg.textContent = 'Saving…';
				var v = function(sel){ var el = card.querySelector(sel); return el ? el.value : ''; };
				api('/photos/confirm', { method:'POST', body: JSON.stringify({
					id: id,
					photo: parseInt(card.dataset.photo, 10),
					people: peopleValues(card),
					place: placeValue(card), event: v('.p-event'),
					// Set only when the occasion was picked from the calendar, so
					// a hand-typed name never claims to be a specific event.
					event_id: parseInt(v('.p-evid'), 10) || 0,
					taken: v('.p-taken'), caption: v('.p-caption'),
					revision: v('.p-rev')
				})}).then(function(){ open(id); })
				  .catch(function(e){ ok.disabled = false; msg.textContent = e.message; });
			};
		});
	}

	// Calendar suggestions, shared by the thread cards and the Photos screen —
	// the same control doing the same job in two places, so it is written once.
	// root is whichever pane the form currently lives in.
	function wireEventPickers(root){
		Array.prototype.forEach.call(root.querySelectorAll('.pev'), function(box){
			// The form sits inside a .pcard on a thread and directly in the pane
			// on the Photos screen, so fall back to the root itself.
			var card   = box.closest('.pcard') || root;
			var list   = box.querySelector('.pevlist');
			var date   = card.querySelector('.p-taken');
			var name   = card.querySelector('.p-event');
			var evid   = card.querySelector('.p-evid');
			var search = box.querySelector('.p-evsearch');
			var seq    = 0;

			function paint(events, why){
				if (!events.length) { list.className = 'pevlist muted'; list.textContent = why; return; }
				list.className = 'pevlist';
				list.innerHTML = events.map(function(e){
					return '<button type="button" class="evpick" data-id="' + e.id + '" data-title="' +
						esc(e.title) + '">' + esc(e.title) + ' <em>' + esc(e.when) + '</em></button>';
				}).join('');
				Array.prototype.forEach.call(list.querySelectorAll('.evpick'), function(b){
					b.onclick = function(){
						name.value = b.dataset.title;
						evid.value = b.dataset.id;
						Array.prototype.forEach.call(list.querySelectorAll('.evpick'), function(x){ x.classList.remove('on'); });
						b.classList.add('on');
					};
				});
			}

			function load(q){
				var mine = ++seq;
				var qs = q ? '&q=' + encodeURIComponent(q) : '&date=' + encodeURIComponent(date ? date.value : '');
				if (!q && (!date || !date.value)) {
					paint([], 'Set a date and the club’s calendar for that day appears here.');
					return;
				}
				api('/photos/events?_=1' + qs).then(function(r){
					// Ignore a reply that arrived after a newer request: typing in
					// the search box fires several and they can land out of order.
					if (mine !== seq) { return; }
					if (!r.calendar) { list.remove(); if (search) { search.remove(); } return; }
					paint(r.events, q ? 'Nothing in the calendar matches that.' : 'Nothing was on at the club that day.');
				}).catch(function(){ if (mine === seq) { paint([], 'Could not reach the calendar.'); } });
			}

			// Typing a name by hand means it is not one of ours any more.
			if (name) { name.oninput = function(){ evid.value = ''; }; }
			if (date) { date.onchange = function(){ if (search) { search.value = ''; } load(''); }; }
			if (search) {
				var timer = null;
				search.oninput = function(){
					clearTimeout(timer);
					timer = setTimeout(function(){ load(search.value.trim()); }, 250);
				};
			}
			load('');
		});
	}

	function wire(id, tstatus){
		var out = document.getElementById('msg');
		var ta = document.getElementById('reply');
		if(ta){ setupEditor(ta); }
		var send = document.getElementById('send'), draft = document.getElementById('draft');
		var done = document.getElementById('done'), ignore = document.getElementById('ignore');
		var restore = document.getElementById('restore');
		var fwdopen = document.getElementById('fwdopen'), fwd = document.getElementById('fwd');
		var fwdsend = document.getElementById('fwdsend'), fwdcancel = document.getElementById('fwdcancel');
		var fwdboard = document.getElementById('fwdboard'), boardArm = null;
		var attopen = document.getElementById('attopen'), att = document.getElementById('att');
		var atupload = document.getElementById('atupload'), atclose = document.getElementById('atclose');
		var all = [send, draft, done, ignore, restore, fwdopen, fwdsend, fwdboard, attopen, atupload].filter(Boolean);

		function busy(b, el){ all.forEach(function(x){ x.disabled = b; }); if(el){ el.classList.toggle('spin', b); } }
		function fail(e, el){ out.innerHTML = '<div class="note err">' + esc(e.message) + '</div>'; busy(false, el); }
		function closed(word){ current = null; currentStamp = null;
			pane.innerHTML = '<p class="muted">' + word + '</p>'; loadList(); }

		if(draft){
			draft.onclick = function(){
				out.innerHTML = '<div class="note ok">Asking Claude…</div>';
				busy(true, draft);
				api('/threads/' + id + '/draft', {method:'POST'}).then(function(r){
					edSet(ta, r.draft);
					out.innerHTML = '<div class="note ok">Draft inserted — read it through before sending.</div>';
					busy(false, draft);
				}).catch(function(e){ fail(e, draft); });
			};
		}

		if(send){
			send.onclick = function(){
				if(!edText(ta)){ out.innerHTML = '<div class="note err">Write something first.</div>'; return; }
				busy(true, send);
				api('/threads/' + id + '/reply', {method:'POST', body: JSON.stringify({
					body: ta.innerHTML,
					attachments: attached.map(function(a){ return a.id; })
				})})
					.then(function(){ open(id); })
					.catch(function(e){ fail(e, send); });
			};
		}

		if(done){
			done.onclick = function(){
				busy(true, done);
				api('/threads/' + id + '/addressed', {method:'POST'})
					.then(function(){ closed('Marked answered.'); })
					.catch(function(e){ fail(e, done); });
			};
		}

		if(ignore){
			var ign = document.getElementById('ign');
			var ignOtherBox = document.getElementById('ignotherbox');

			function doIgnore(reason, btn){
				busy(true, btn);
				api('/threads/' + id + '/ignore', {method:'POST', body: JSON.stringify({reason: reason})})
					.then(function(){ closed('Ignored — ' + esc(reason) + '.'); })
					.catch(function(e){ fail(e, btn); });
			}

			ignore.onclick = function(){
				var open = ign.style.display !== 'none';
				ign.style.display = open ? 'none' : 'block';
				if(open){ ignOtherBox.style.display = 'none'; }
			};
			document.getElementById('igncancel').onclick = function(){
				ign.style.display = 'none';
				ignOtherBox.style.display = 'none';
			};

			// A quick pick is the second click, so it acts immediately.
			Array.prototype.forEach.call(ign.querySelectorAll('.ignpick'), function(b){
				b.onclick = function(){ doIgnore(b.getAttribute('data-r'), b); };
			});

			// "Other" needs typing, so it opens a field instead of firing.
			document.getElementById('ignother').onclick = function(){
				ignOtherBox.style.display = 'block';
				document.getElementById('ignreason').focus();
			};
			document.getElementById('ignsend').onclick = function(){
				var r = document.getElementById('ignreason').value.trim();
				if(!r){
					out.innerHTML = '<div class="note err">Type a short reason, or pick one of the buttons above.</div>';
					document.getElementById('ignreason').focus();
					return;
				}
				doIgnore(r, document.getElementById('ignsend'));
			};
			document.getElementById('ignreason').addEventListener('keydown', function(ev){
				if('Enter' === ev.key){ ev.preventDefault(); document.getElementById('ignsend').click(); }
			});
		}

		if(restore){
			restore.onclick = function(){
				busy(true, restore);
				api('/threads/' + id + '/restore', {method:'POST'})
					.then(function(){ closed('Put back in the Open list.'); })
					.catch(function(e){ fail(e, restore); });
			};
		}

		if(attopen){
			attopen.onclick = function(){
				att.style.display = att.style.display === 'none' ? 'block' : 'none';
				if(att.style.display === 'block'){ loadLibrary(); }
			};
			atclose.onclick = function(){ att.style.display = 'none'; };
			atupload.onclick = function(){
				var f = document.getElementById('atfile');
				if(!f.files || !f.files.length){
					out.innerHTML = '<div class="note err">Choose a file first.</div>';
					return;
				}
				var keep = document.getElementById('atkeep').checked;
				busy(true, atupload);
				uploadFile(f.files[0], keep).then(function(a){
					addAttachment(a);
					f.value = '';
					document.getElementById('atkeep').checked = false;
					out.innerHTML = '<div class="note ok">' + esc(a.name) +
						(keep ? ' attached, and saved to the shared library.' : ' attached.') + '</div>';
					if(keep){ loadLibrary(); }
					busy(false, atupload);
				}).catch(function(e){ fail(e, atupload); });
			};
		}

		if(fwdopen){
			fwdopen.onclick = function(){
				fwd.style.display = fwd.style.display === 'none' ? 'block' : 'none';
				if(fwd.style.display === 'block'){ document.getElementById('fwdto').focus(); }
			};
			// Two-step, not a confirm() dialog. A confirm gets dismissed
			// reflexively — people learn to click through them without reading.
			// A second click on a button that has visibly changed colour and
			// wording cannot be done by muscle memory, and it disarms itself
			// after six seconds so a half-pressed one does not lie in wait.
			var disarmBoard = function(){
				if(boardArm){ clearTimeout(boardArm); boardArm = null; }
				if(fwdboard){ fwdboard.className = 'btn sec'; fwdboard.textContent = 'Forward to Board'; }
			};

			fwdcancel.onclick = function(){ fwd.style.display = 'none'; disarmBoard(); };

			if(fwdboard){
				fwdboard.onclick = function(){
					if(!boardArm){
						fwdboard.className = 'btn warn';
						fwdboard.textContent = 'Click again to send to ' + BOARD;
						boardArm = setTimeout(disarmBoard, 6000);
						return;
					}
					disarmBoard();
					busy(true, fwdboard);
					api('/threads/' + id + '/forward', {method:'POST', body: JSON.stringify({
						to: BOARD, comment: document.getElementById('fwdnote').value
					})}).then(function(){
						loadContacts();
						closed('Sent to the Board — moved to Answered.');
					}).catch(function(e){ fail(e, fwdboard); });
				};
			}
			fwdsend.onclick = function(){
				var to = document.getElementById('fwdto').value.trim();
				if(!to){ out.innerHTML = '<div class="note err">Enter an address to forward to.</div>'; return; }
				busy(true, fwdsend);
				api('/threads/' + id + '/forward', {method:'POST', body: JSON.stringify({
					to: to, comment: document.getElementById('fwdnote').value
				})}).then(function(r){
					loadContacts();
					// Forwarding closes the thread now, so the view clears the same
					// way the other closing actions do rather than leaving a dead
					// compose box open over a conversation that has moved on.
					closed('Forwarded to ' + esc(r.to.join(', ')) + ' — moved to Answered.');
				}).catch(function(e){ fail(e, fwdsend); });
			};
		}
	}

	// Address book, for the forward field's autocomplete. Refreshed after each
	// forward so a newly-used address is offered next time without a reload.
	function loadContacts(){
		return api('/contacts').then(function(rows){
			document.getElementById('contacts').innerHTML = rows.map(function(c){
				return '<option value="' + esc(c.email) + '">' + esc(c.name || c.email) + '</option>';
			}).join('');
		}).catch(function(){});
	}

	/* ---------------------------------------------------------------
	 * The Photos screen.
	 *
	 * A photo admin is not a WordPress admin — these accounts have no role at
	 * all and cannot open wp-admin — so everything they need to do with a
	 * photo has to be here: see it, see who sent it, fix the tags, approve it,
	 * throw it out, download it.
	 * ------------------------------------------------------------- */
	var pgrid  = document.getElementById('pgrid');
	var ppane  = document.getElementById('ppane');
	var pstate = 'review', pcur = null;

	function showView(which){
		var mail = document.getElementById('mailview'), ph = document.getElementById('photoview');
		if (!ph) { return; }
		var toPhotos = (which === 'photos');
		mail.hidden = toPhotos;
		ph.hidden   = !toPhotos;
		var b = document.getElementById('toview');
		b.textContent = toPhotos ? 'Back to mail' : 'Photos';
		b.dataset.view = toPhotos ? 'mail' : 'photos';
		if (toPhotos) { loadPhotos(); }
		window.scrollTo(0, 0);
	}

	function loadPhotos(){
		if (!pgrid) { return; }
		return api('/photos/list?state=' + encodeURIComponent(pstate)).then(function(r){
			// Counts on the tabs, so "nothing to do" is visible without clicking
			// through all four.
			Array.prototype.forEach.call(document.querySelectorAll('.pstates button'), function(b){
				var k = b.dataset.pstate, n = r.counts[k === 'all' ? 'all' : k];
				b.textContent = b.textContent.replace(/\s*\(\d+\)$/, '') + (n ? ' (' + n + ')' : '');
			});

			if (!r.photos.length) {
				pgrid.innerHTML = '<div class="pane muted">' + (pstate === 'review'
					? 'Nothing needs you. Photos appear here once the sender has described them, or once they have had five days to and have not.'
					: 'Nothing here.') + '</div>';
				return;
			}
			pgrid.innerHTML = r.photos.map(function(p){
				return '<button class="pthumbcard' + (pcur === p.id ? ' on' : '') + '" data-photo="' + p.id + '">' +
					(p.thumb ? '<img src="' + esc(p.thumb) + '" alt="" loading="lazy">' : '') +
					'<span class="pmeta">' + esc(p.from) +
					(p.taken ? ' · ' + esc(p.taken) : '') +
					(p.bucket === 'review' && p.pending ? '<em>described</em>'
						: (p.bucket === 'review' ? '<em>no reply</em>' : '')) +
					'</span></button>';
			}).join('');
			Array.prototype.forEach.call(pgrid.querySelectorAll('.pthumbcard'), function(b){
				b.onclick = function(){ openPhoto(parseInt(b.dataset.photo, 10)); };
			});
		}).catch(function(e){ pgrid.innerHTML = '<div class="pane note err">' + esc(e.message) + '</div>'; });
	}

	function openPhoto(id){
		pcur = id;
		ppane.innerHTML = '<p class="muted">Loading…</p>';
		api('/photos/detail?photo=' + id).then(function(p){
			// The sender's answers if they gave any, otherwise whatever is already
			// ON the photo. Never {} — a blank form saved over a confirmed photo
			// erases every tag it had, and the button is labelled approve.
			var q = p.pending || p.saved || {};
			var h = p.missing
				// Named, not rendered as a broken image: "the file is gone" and
				// "the page is broken" look identical otherwise, and only one of
				// them is worth anybody's time.
				? '<div class="note err">The image file is missing from the server, though its record is still here. ' +
				  'Nothing can be done with it — reject it, and it can be taken in again from the original email.</div>'
				: '<a href="' + esc(p.url) + '" target="_blank" rel="noopener" class="pbig">' +
				  '<img src="' + esc(p.full || p.thumb) + '" alt=""></a>';

			h += '<p class="muted" style="margin:10px 0 4px">Sent by <strong>' + esc(p.from) + '</strong>' +
				(p.email ? ' &lt;' + esc(p.email) + '&gt;' : '') +
				(p.subject ? ' &middot; ' + esc(p.subject) : '') +
				// Not a verdict — most first-timers are exactly who they say
				// they are — but worth knowing before it joins the collection.
				(p.known ? '' : ' <span class="firsttime">first time we have heard from them</span>') + '</p>';

			if (p.state === 'waiting') {
				h += '<div class="note warn">Asked, and reminded once. They have until <strong>' + esc(p.release) +
					'</strong> to answer. You can label it yourself now if you know.</div>';
			} else if (p.state === 'released') {
				h += '<div class="note warn">The sender never answered. Label it from what you can see.</div>';
			} else if (p.pending) {
				h += '<div class="note ok">The sender described this. Check it and approve.</div>';
			} else if (p.state === 'confirmed') {
				h += '<div class="note ok">Approved and tagged.</div>';
			}

			h += photoForm(p, q);
			h += '<div class="actions" style="margin-top:6px">' +
				(p.dlname ? '<a class="btn sec" href="' + esc(p.url) + '" download="' + esc(p.dlname) + '">Download</a>' : '') +
				'<button class="btn warn" id="preject">Reject &amp; delete</button></div>';
			if (p.dlname) { h += '<p class="muted" style="margin:6px 0 0">Saves as <code>' + esc(p.dlname) + '</code></p>'; }

			ppane.innerHTML = h;
			wirePhotoPane(id, p);
		}).catch(function(e){ ppane.innerHTML = '<div class="note err">' + esc(e.message) + '</div>'; });
	}

	function wirePhotoPane(id, p){
		wireEventPickers(ppane);
		wirePlaceSelects(ppane);
		wirePeople(ppane);

		var ok = ppane.querySelector('.p-ok');
		if (ok) {
			ok.textContent = 'Approve with these tags';
			ok.onclick = function(){
				var msg = ppane.querySelector('.p-msg');
				ok.disabled = true; msg.textContent = 'Saving…';
				var v = function(s){ var el = ppane.querySelector(s); return el ? el.value : ''; };
				api('/photos/save', { method:'POST', body: JSON.stringify({
					photo: id,
					people: peopleValues(ppane),
					place: placeValue(ppane), event: v('.p-event'),
					event_id: parseInt(v('.p-evid'), 10) || 0,
					taken: v('.p-taken'), caption: v('.p-caption'),
					revision: v('.p-rev')
				})}).then(function(){ loadPhotos(); openPhoto(id); })
				  .catch(function(e){ ok.disabled = false; msg.textContent = e.message; });
			};
		}

		var rej = document.getElementById('preject');
		if (rej) {
			rej.onclick = function(){
				if (!confirm('Delete this photo for good?\n\nIt is removed from the club\'s collection and cannot be recovered. The email it came from is not touched, so it can be taken in again if that was a mistake.')) { return; }
				rej.disabled = true;
				// The revision this screen is showing. Deleting is the one action
				// with no way back, so it refuses on a stale screen exactly as
				// approving does.
				var rv = ppane.querySelector('.p-rev');
				api('/photos/reject', { method:'POST', body: JSON.stringify({
					photo: id,
					revision: rv ? rv.value : ''
				}) })
					.then(function(){
						pcur = null;
						ppane.innerHTML = '<p class="muted">Deleted. Pick another photo on the left.</p>';
						loadPhotos();
					}).catch(function(e){ rej.disabled = false; alert(e.message); });
			};
		}
	}

	Array.prototype.forEach.call(document.querySelectorAll('.pstates button'), function(b){
		b.onclick = function(){
			Array.prototype.forEach.call(document.querySelectorAll('.pstates button'), function(x){ x.classList.remove('on'); });
			b.classList.add('on');
			pstate = b.dataset.pstate;
			pcur = null;
			ppane.innerHTML = '<p class="muted">Pick a photo on the left.</p>';
			loadPhotos();
		};
	});

	var toview = document.getElementById('toview');
	if (toview) { toview.onclick = function(){ showView(toview.dataset.view); }; }

	// The "photos waiting" banner sits outside the pane, so its buttons need
	// wiring to the same open() the list rows use.
	Array.prototype.forEach.call(document.querySelectorAll('[data-openthread]'), function(b){
		b.onclick = function(e){
			e.preventDefault();
			open(parseInt(b.dataset.openthread, 10));
			window.scrollTo(0, 0);
		};
	});

	// Status tabs and stream tabs are independent rows, so each only clears the
	// selection within its own row.
	Array.prototype.forEach.call(document.querySelectorAll('.tabs:not(.streams) button'), function(b){
		b.onclick = function(){
			Array.prototype.forEach.call(document.querySelectorAll('.tabs:not(.streams) button'), function(x){ x.classList.remove('on'); });
			b.classList.add('on'); status = b.dataset.status; current = null; currentStamp = null;
			clearPane();
			loadList();
		};
	});
	Array.prototype.forEach.call(document.querySelectorAll('.tabs.streams button'), function(b){
		b.onclick = function(){
			Array.prototype.forEach.call(document.querySelectorAll('.tabs.streams button'), function(x){ x.classList.remove('on'); });
			b.classList.add('on'); stream = b.dataset.stream || ''; current = null; currentStamp = null;
			// Re-theme the page to the chosen mailbox and name it in the header.
			// '' (All) falls back to the club's own colours and no address, since
			// no single one describes a mixed list.
			document.body.setAttribute('data-stream', stream);
			var hb = document.getElementById('hbox'), box = '';
			for (var i = 0; i < STREAMS.length; i++) { if (STREAMS[i].key === stream) { box = STREAMS[i].mailbox; } }
			if (hb) { hb.textContent = box ? ' — ' + box : ''; }
			clearPane();
			loadList();
		};
	});

	// Release the lock when the tab closes, so an abandoned conversation frees
	// up immediately instead of waiting out the 15-minute expiry.
	window.addEventListener('pagehide', function(){
		if(!current) return;
		var url = API + '/threads/' + current + '/release';
		if(navigator.sendBeacon){
			// The payload exists to carry a Content-Type. sendBeacon with no data
			// sends a bodyless POST with no content type, which this host's WAF
			// rejects outright — and a beacon reports no errors, so the release
			// was failing silently and every abandoned thread sat locked for the
			// full 15 minutes instead of freeing up immediately.
			navigator.sendBeacon(
				url + '?_wpnonce=' + encodeURIComponent(NONCE),
				new Blob(['{}'], {type: 'application/json'})
			);
		}
	});

	// Refresh the list every minute, always. The open conversation is left
	// alone — reloading it would wipe a half-written reply — but a banner
	// appears if it has changed underneath.
	// Manual "go and look now", so nobody has to wait out the hourly collection
	// when they are expecting something. The button reports what it found rather
	// than silently refreshing — "nothing new" is a useful answer, and without it
	// people press it repeatedly wondering whether it did anything.
	var check = document.getElementById('checkmail');
	if(check){
		check.onclick = function(){
			check.disabled = true;
			check.textContent = 'Checking…';
			api('/sync', {method:'POST'}).then(function(r){
				if(r.throttled){
					check.textContent = 'Just checked';
				} else if(r.new){
					check.textContent = r.new + (r.new === 1 ? ' new message' : ' new messages');
				} else {
					check.textContent = 'Nothing new';
				}
				loadList();
			}).catch(function(e){
				// A failed check must look different from a quiet mailbox — a
				// broken connection reading as "nothing new" is the worst
				// outcome this button could have.
				check.textContent = 'Check failed';
				pane.innerHTML = '<div class="note err">Could not reach the mailbox: ' + esc(e.message) + '</div>';
			}).then(function(){
				setTimeout(function(){
					check.disabled = false;
					check.textContent = 'Check for new mail';
				}, 3000);
			});
		};
	}

	loadList();
	loadContacts();
	setInterval(loadList, 60000);
})();
</script>
	<?php
}
