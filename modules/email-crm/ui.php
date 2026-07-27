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

	$provider = sanitize_key( (string) get_query_var( 'gasf_crm_provider' ) );

	switch ( $route ) {
		case 'start':
			gasf_crm_auth_start( $provider );
			return;

		case 'callback':
			gasf_crm_auth_callback( $provider );
			return;

		case 'logout':
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

	$status = gasf_crm_user_status();

	echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<meta name="robots" content="noindex, nofollow">';
	echo '<title>Email — ' . esc_html( get_bloginfo( 'name' ) ) . '</title>';
	gasf_crm_styles();
	echo '</head><body>';

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
body{margin:0;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1d2327;background:#f0f0f1}
a{color:#2b5c9b}
.wrap{max-width:1200px;margin:0 auto;padding:0 16px}
header.bar{background:#1d2327;color:#fff;padding:12px 0}
header.bar .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
header.bar h1{font-size:16px;margin:0;font-weight:600}
header.bar a{color:#c3c4c7;text-decoration:none;font-size:13px}
header.bar .hbtn{background:#2271b1;color:#fff;border:0;padding:5px 12px;border-radius:4px;cursor:pointer;font:inherit;font-size:13px;margin-right:10px}
.center{max-width:420px;margin:12vh auto;background:#fff;padding:32px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.13);text-align:center}
.center h1{font-size:20px;margin:0 0 8px}
.center p{color:#50575e;margin:0 0 24px}
.btn{display:inline-block;padding:9px 16px;border:1px solid #2271b1;background:#2271b1;color:#fff;border-radius:4px;cursor:pointer;font-size:14px;text-decoration:none;font-family:inherit}
.btn:hover{background:#135e96}
.btn[disabled]{opacity:.5;cursor:default}
.btn.sec{background:#f6f7f7;color:#2271b1}
.btn.sec:hover{background:#f0f0f1}
.btn.warn{background:#fff;color:#b32d2e;border-color:#b32d2e}
.btn.warn:hover{background:#fcf0f1}
.btn.block{display:block;width:100%;margin:0 0 10px;padding:11px}
.layout{display:grid;grid-template-columns:340px 1fr;gap:16px;padding:16px 0;align-items:start}
@media(max-width:820px){.layout{grid-template-columns:1fr}}
.card{background:#fff;border:1px solid #dcdcde;border-radius:6px}
.list{max-height:78vh;overflow:auto}
.tabs{display:flex;border-bottom:1px solid #dcdcde}
.tabs button{flex:1;padding:10px 6px;border:0;background:none;cursor:pointer;font:inherit;font-size:13px;color:#50575e;border-bottom:2px solid transparent}
.tabs button.on{color:#2271b1;border-bottom-color:#2271b1;font-weight:600}
.item{padding:12px 14px;border-bottom:1px solid #f0f0f1;cursor:pointer}
.item:hover{background:#f6f7f7}
.item.on{background:#f0f6fc;border-left:3px solid #2271b1;padding-left:11px}
.item .who{font-weight:600;font-size:13px;display:flex;justify-content:space-between;gap:8px}
.item .subj{font-size:13px;margin:2px 0 0}
.item .meta{font-size:11px;color:#787c82;margin-top:4px}
.dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#d63638;margin-right:6px;vertical-align:middle}
.pane{padding:20px;min-height:300px}
.msg{border-bottom:1px solid #f0f0f1;padding:0 0 16px;margin:0 0 16px}
.msg:last-of-type{border-bottom:0}
.msg .hd{font-size:12px;color:#787c82;margin-bottom:8px}
.msg .hd b{color:#1d2327;font-size:13px}
/* Revealed on hover, but it is real selectable text the whole time — opacity
   keeps it in the layout so nothing shifts, and the reveal is triggered by the
   whole message block so it stays visible while you drag across it to select. */
.msg .addr{opacity:0;transition:opacity .12s;font-weight:400}
.msg:hover .addr,.msg .addr:focus-within{opacity:1}
.msg .addr code{background:#f0f0f1;padding:1px 5px;border-radius:3px;font-size:12px;user-select:all}
.copy{border:1px solid #dcdcde;background:#fff;border-radius:3px;cursor:pointer;font:inherit;font-size:11px;padding:1px 6px;margin-left:4px;color:#2271b1}
.copy:hover{background:#f0f6fc}
.copy.done{color:#2c7a3f;border-color:#2c7a3f}
/* Touch devices have no hover at all — never hide it there. */
@media(hover:none){.msg .addr{opacity:1}}
.msg.out{background:#f6faf6;border-left:3px solid #2c7a3f;padding:12px;border-radius:4px}
.msg .body{overflow-wrap:anywhere}
.msg .body table{max-width:100%;border-collapse:collapse}
.msg .body img{max-width:100%;height:auto}
textarea{width:100%;min-height:150px;padding:10px;border:1px solid #8c8f94;border-radius:4px;font:inherit;resize:vertical}
.ed{border:1px solid #8c8f94;border-radius:4px;overflow:hidden;background:#fff}
.edbar{display:flex;flex-wrap:wrap;align-items:center;gap:2px;padding:6px;border-bottom:1px solid #dcdcde;background:#f6f7f7}
.edbar button{min-width:32px;height:28px;padding:0 9px;border:1px solid transparent;background:none;border-radius:3px;cursor:pointer;font:inherit;font-size:13px;color:#1d2327;line-height:1}
.edbar button:hover{background:#fff;border-color:#dcdcde}
.edbar .sep{width:1px;height:18px;background:#dcdcde;margin:0 5px}
.edbody{min-height:170px;max-height:50vh;overflow:auto;padding:10px;outline:none;font:inherit;overflow-wrap:anywhere}
.edbody:empty::before{content:attr(data-ph);color:#8c8f94}
.edbody:focus{box-shadow:inset 0 0 0 1px #2271b1}
.edbody p{margin:0 0 10px}
.edbody ul,.edbody ol{margin:0 0 10px;padding-left:24px}
.actions{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.note{padding:10px 12px;border-radius:4px;font-size:13px;margin:12px 0}
.note.warn{background:#fcf9e8;border-left:4px solid #dba617}
.note.err{background:#fcf0f1;border-left:4px solid #d63638}
.note.ok{background:#f0f6fc;border-left:4px solid #72aee6}
.muted{color:#787c82;font-size:13px}
.att{display:inline-block;margin:4px 8px 0 0;padding:4px 10px;background:#f0f0f1;border-radius:3px;font-size:12px;text-decoration:none}
.spin{opacity:.6}
.hist{margin-top:28px;border-top:1px solid #dcdcde;padding-top:14px}
.hist h3{font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#787c82;margin:0 0 10px}
.hist ul{list-style:none;margin:0;padding:0}
.hist li{font-size:13px;padding:5px 0 5px 16px;border-left:2px solid #dcdcde;color:#50575e}
.hist li b{color:#1d2327}
.hist li .t{color:#787c82;font-size:12px}
.help{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px 24px;margin:16px 0}
.help h2{font-size:17px;margin:0 0 4px}
.help h3{font-size:14px;margin:18px 0 4px}
.help p,.help li{font-size:14px;color:#3c434a}
.help ul{margin:4px 0;padding-left:20px}
.help .close{float:right}
.fwd{border:1px solid #dcdcde;border-radius:6px;padding:14px;margin-top:12px;background:#fbfbfc}
.fwd label{display:block;font-size:13px;font-weight:600;margin-bottom:12px}
.fwd input[type=text]{width:100%;max-width:440px;padding:8px;border:1px solid #8c8f94;border-radius:4px;font:inherit;font-weight:400;margin-top:3px}
.fwd textarea{min-height:70px;font-weight:400;margin-top:3px}
.badge{display:inline-block;font-size:11px;padding:1px 7px;border-radius:9px;background:#f0f0f1;color:#50575e;vertical-align:middle}
.badge.ig{background:#fcf0f1;color:#b32d2e}
.badge.an{background:#edf7ed;color:#2c7a3f}
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
	foreach ( $providers as $key => $p ) {
		printf(
			'<a class="btn block" href="%s">Continue with %s</a>',
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
	?>
<div class="wrap"><div class="help" id="help" style="display:none">
	<button class="btn sec close" onclick="document.getElementById('help').style.display='none'">Close</button>
	<h2>What this page is</h2>
	<p>This is the club's shared mailbox. Anything sent to <strong>info@germantampabay.com</strong> — the address on our website — turns up here, and any of us can answer it. Replies go out from the club address with your name at the bottom, so the person who wrote in sees a reply from the club, not from your personal email.</p>

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
			<br>Changed your mind, or they need something from us after all? Find it in Answered, open it, and press <em>Put back in Open</em>.</li>
		<li><strong>Ignore</strong> is for spam, junk and mailing lists. Nothing is sent and the sender hears nothing back.</li>
		<li><strong>Mark answered</strong> is for when you handled it some other way — you rang them, or caught them at the club. Nothing is sent, it just clears it off the list.</li>
	</ul>

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
	<p>That gap is normal. If an email you are expecting has not appeared yet, it almost certainly just has not been collected yet — wait a bit and it will turn up on its own. There is no button to hurry it along, and nothing has gone wrong.</p>
</div></div>
	<?php
}

function gasf_crm_render_inbox() {
	$user = wp_get_current_user();
	?>
<header class="bar"><div class="wrap">
	<h1>Club inbox &mdash; info@germantampabay.com</h1>
	<div>
		<button class="hbtn" onclick="var h=document.getElementById('help');h.style.display=h.style.display==='none'?'block':'none';window.scrollTo(0,0)">Help</button>
		<?php echo esc_html( $user->display_name ); ?> &middot;
		<a href="<?php echo esc_url( home_url( '/email/logout' ) ); ?>">Sign out</a>
	</div>
</div></header>

<?php gasf_crm_render_help(); ?>

<div class="wrap"><div class="layout">
	<div class="card">
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
	function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
	function when(s){
		if(!s) return '';
		// Stored UTC — the trailing Z is what makes the browser render it in the
		// reader's own timezone instead of treating it as local and shifting it.
		var d = new Date(s.replace(' ','T') + 'Z');
		return isNaN(d) ? s : d.toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
	}

	function loadList(){
		return api('/threads?status=' + status).then(function(rows){
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
				return '<div class="item' + (current === t.id ? ' on' : '') + '" data-id="' + t.id + '">' +
					'<div class="who"><span>' + (t.status === 'new' ? '<span class="dot"></span>' : '') +
					esc(t.from) + '</span><span class="meta">' + esc(when(t.last)) + '</span></div>' +
					'<div class="subj">' + esc(t.subject || '(no subject)') + '</div>' + lock + '</div>';
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
				replied_outlook: 'replied from Outlook',
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
		pane.innerHTML = '<p class="muted">Loading…</p>';
		api('/threads/' + id).then(function(t){
			var badge = t.status === 'ignored' ? ' <span class="badge ig">Ignored</span>'
				: (t.status === 'addressed' ? ' <span class="badge an">Answered</span>' : '');
			var html = '<h2 style="margin:0 0 16px;font-size:18px">' + esc(t.subject || '(no subject)') + badge + '</h2>';

			if(!t.can_reply && t.locked_by){
				html += '<div class="note warn">' + esc(t.locked_by) + ' is replying to this. You can read it, but not send.</div>';
			}

			t.messages.forEach(function(m){
				var atts = (m.attachments||[]).map(function(a){
					return '<a class="att" href="' + esc(a.url) + '">📎 ' + esc(a.name) + '</a>';
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
					'<div class="actions">' +
					'<button class="btn" id="send">Send reply</button>' +
					'<button class="btn sec" id="draft">Draft with AI</button>' +
					'<button class="btn sec" id="fwdopen">Forward…</button>' +
					'<button class="btn sec" id="done">Mark answered</button>' +
					'<button class="btn warn" id="ignore">Ignore (spam)</button>' +
					'</div>' +
					'<div class="fwd" id="fwd" style="display:none">' +
						'<label>Send this on to<input type="text" id="fwdto" list="contacts" ' +
							'placeholder="name@example.com" autocomplete="off"></label>' +
						'<label>Add a note (optional)<textarea id="fwdnote" ' +
							'placeholder="e.g. Karl, can you take this one?"></textarea></label>' +
						'<div class="actions">' +
						'<button class="btn" id="fwdsend">Send forward</button>' +
						'<button class="btn sec" id="fwdcancel">Cancel</button></div>' +
					'</div>' +
					'<div id="msg"></div>';
			}

			html += history(t.events);
			pane.innerHTML = html;
			wire(id, t.status);
			wireCopy();

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

	function wire(id, tstatus){
		var out = document.getElementById('msg');
		var ta = document.getElementById('reply');
		if(ta){ setupEditor(ta); }
		var send = document.getElementById('send'), draft = document.getElementById('draft');
		var done = document.getElementById('done'), ignore = document.getElementById('ignore');
		var restore = document.getElementById('restore');
		var fwdopen = document.getElementById('fwdopen'), fwd = document.getElementById('fwd');
		var fwdsend = document.getElementById('fwdsend'), fwdcancel = document.getElementById('fwdcancel');
		var all = [send, draft, done, ignore, restore, fwdopen, fwdsend].filter(Boolean);

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
				api('/threads/' + id + '/reply', {method:'POST', body: JSON.stringify({body: ta.innerHTML})})
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
			ignore.onclick = function(){
				if(!confirm('Ignore this as spam or junk?\n\nNothing is sent, and it will not come back even if the sender emails again.')) return;
				busy(true, ignore);
				api('/threads/' + id + '/ignore', {method:'POST', body: JSON.stringify({reason:'Marked as spam or junk'})})
					.then(function(){ closed('Ignored.'); })
					.catch(function(e){ fail(e, ignore); });
			};
		}

		if(restore){
			restore.onclick = function(){
				busy(true, restore);
				api('/threads/' + id + '/restore', {method:'POST'})
					.then(function(){ closed('Put back in the Open list.'); })
					.catch(function(e){ fail(e, restore); });
			};
		}

		if(fwdopen){
			fwdopen.onclick = function(){
				fwd.style.display = fwd.style.display === 'none' ? 'block' : 'none';
				if(fwd.style.display === 'block'){ document.getElementById('fwdto').focus(); }
			};
			fwdcancel.onclick = function(){ fwd.style.display = 'none'; };
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

	Array.prototype.forEach.call(document.querySelectorAll('.tabs button'), function(b){
		b.onclick = function(){
			Array.prototype.forEach.call(document.querySelectorAll('.tabs button'), function(x){ x.classList.remove('on'); });
			b.classList.add('on'); status = b.dataset.status; current = null; currentStamp = null;
			pane.innerHTML = '<p class="muted">Select a message on the left.</p>';
			loadList();
		};
	});

	// Release the lock when the tab closes, so an abandoned conversation frees
	// up immediately instead of waiting out the 15-minute expiry.
	window.addEventListener('pagehide', function(){
		if(!current) return;
		var url = API + '/threads/' + current + '/release';
		if(navigator.sendBeacon){
			navigator.sendBeacon(url + '?_wpnonce=' + encodeURIComponent(NONCE));
		}
	});

	// Refresh the list every minute, always. The open conversation is left
	// alone — reloading it would wipe a half-written reply — but a banner
	// appears if it has changed underneath.
	loadList();
	loadContacts();
	setInterval(loadList, 60000);
})();
</script>
	<?php
}
