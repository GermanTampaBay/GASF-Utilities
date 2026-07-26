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
} );

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
header.bar .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px}
header.bar h1{font-size:16px;margin:0;font-weight:600}
header.bar a{color:#c3c4c7;text-decoration:none;font-size:13px}
.center{max-width:420px;margin:12vh auto;background:#fff;padding:32px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.13);text-align:center}
.center h1{font-size:20px;margin:0 0 8px}
.center p{color:#50575e;margin:0 0 24px}
.btn{display:inline-block;padding:9px 16px;border:1px solid #2271b1;background:#2271b1;color:#fff;border-radius:4px;cursor:pointer;font-size:14px;text-decoration:none;font-family:inherit}
.btn:hover{background:#135e96}
.btn[disabled]{opacity:.5;cursor:default}
.btn.sec{background:#f6f7f7;color:#2271b1}
.btn.sec:hover{background:#f0f0f1}
.btn.block{display:block;width:100%;margin:0 0 10px;padding:11px}
.layout{display:grid;grid-template-columns:340px 1fr;gap:16px;padding:16px 0;align-items:start}
@media(max-width:820px){.layout{grid-template-columns:1fr}}
.card{background:#fff;border:1px solid #dcdcde;border-radius:6px}
.list{max-height:78vh;overflow:auto}
.tabs{display:flex;border-bottom:1px solid #dcdcde}
.tabs button{flex:1;padding:10px;border:0;background:none;cursor:pointer;font:inherit;font-size:13px;color:#50575e;border-bottom:2px solid transparent}
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
.msg.out{background:#f6faf6;border-left:3px solid #2c7a3f;padding:12px;border-radius:4px}
.msg .body{overflow-wrap:anywhere}
.msg .body table{max-width:100%;border-collapse:collapse}
.msg .body img{max-width:100%;height:auto}
textarea{width:100%;min-height:150px;padding:10px;border:1px solid #8c8f94;border-radius:4px;font:inherit;resize:vertical}
.actions{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.note{padding:10px 12px;border-radius:4px;font-size:13px;margin:12px 0}
.note.warn{background:#fcf9e8;border-left:4px solid #dba617}
.note.err{background:#fcf0f1;border-left:4px solid #d63638}
.note.ok{background:#f0f6fc;border-left:4px solid #72aee6}
.muted{color:#787c82;font-size:13px}
.att{display:inline-block;margin:4px 8px 0 0;padding:4px 10px;background:#f0f0f1;border-radius:3px;font-size:12px;text-decoration:none}
.spin{opacity:.6}
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

function gasf_crm_render_pending( $status ) {
	echo '<div class="center"><h1>Awaiting approval</h1>';
	if ( 'denied' === $status ) {
		echo '<p>This account does not have access to the club inbox.</p>';
	} else {
		echo '<p>Your account has been created and is waiting for an administrator to approve it. You will not be able to see the inbox until then.</p>';
	}
	echo '<a class="btn sec" href="' . esc_url( home_url( '/email/logout' ) ) . '">Sign out</a></div>';
}

function gasf_crm_render_inbox() {
	$user = wp_get_current_user();
	?>
<header class="bar"><div class="wrap">
	<h1>Club inbox &mdash; info@germantampabay.com</h1>
	<div><?php echo esc_html( $user->display_name ); ?> &middot;
		<a href="<?php echo esc_url( home_url( '/email/logout' ) ); ?>">Sign out</a></div>
</div></header>

<div class="wrap"><div class="layout">
	<div class="card">
		<div class="tabs">
			<button class="on" data-status="open">Open</button>
			<button data-status="addressed">Answered</button>
		</div>
		<div class="list" id="list"><div class="pane muted">Loading…</div></div>
	</div>
	<div class="card"><div class="pane" id="pane">
		<p class="muted">Select a message on the left.</p>
	</div></div>
</div></div>

<script>
(function(){
	var API   = <?php echo wp_json_encode( rest_url( 'gasf/v1/crm' ) ); ?>;
	var NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
	var list = document.getElementById('list'), pane = document.getElementById('pane');
	var status = 'open', current = null;

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
		api('/threads?status=' + status).then(function(rows){
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

	function open(id){
		current = id;
		pane.innerHTML = '<p class="muted">Loading…</p>';
		api('/threads/' + id).then(function(t){
			var html = '<h2 style="margin:0 0 16px;font-size:18px">' + esc(t.subject || '(no subject)') + '</h2>';

			if(!t.can_reply && t.locked_by){
				html += '<div class="note warn">' + esc(t.locked_by) + ' is replying to this thread. You can read it, but not send.</div>';
			}

			t.messages.forEach(function(m){
				var atts = (m.attachments||[]).map(function(a){
					return '<a class="att" href="' + esc(a.url) + '">📎 ' + esc(a.name) + '</a>';
				}).join('');
				html += '<div class="msg ' + (m.direction === 'out' ? 'out' : '') + '">' +
					'<div class="hd"><b>' + esc(m.from) + '</b> &middot; ' + esc(when(m.sent_at)) + '</div>' +
					'<div class="body">' + m.body + '</div>' + atts + '</div>';
			});

			if(t.can_reply){
				html += '<textarea id="reply" placeholder="Write your reply…"></textarea>' +
					'<div class="actions">' +
					'<button class="btn" id="send">Send reply</button>' +
					'<button class="btn sec" id="draft">Draft with AI</button>' +
					'<button class="btn sec" id="done">Mark answered</button>' +
					'</div><div id="msg"></div>';
			}
			pane.innerHTML = html;
			if(t.can_reply){ wire(id); }
			loadList();
		}).catch(function(e){ pane.innerHTML = '<div class="note err">' + esc(e.message) + '</div>'; });
	}

	function wire(id){
		var ta = document.getElementById('reply'), out = document.getElementById('msg');
		var send = document.getElementById('send'), draft = document.getElementById('draft'), done = document.getElementById('done');

		function busy(b, el){ [send, draft, done].forEach(function(x){ x.disabled = b; });
			if(el){ el.classList.toggle('spin', b); } }

		draft.onclick = function(){
			out.innerHTML = '<div class="note ok">Asking Claude…</div>';
			busy(true, draft);
			api('/threads/' + id + '/draft', {method:'POST'}).then(function(r){
				ta.value = r.draft; out.innerHTML = '<div class="note ok">Draft inserted — read it before sending.</div>';
			}).catch(function(e){ out.innerHTML = '<div class="note err">' + esc(e.message) + '</div>'; })
			.then(function(){ busy(false, draft); });
		};

		send.onclick = function(){
			if(!ta.value.trim()){ out.innerHTML = '<div class="note err">Write something first.</div>'; return; }
			busy(true, send);
			api('/threads/' + id + '/reply', {method:'POST', body: JSON.stringify({body: ta.value})})
				.then(function(){ out.innerHTML = '<div class="note ok">Sent.</div>'; open(id); })
				.catch(function(e){ out.innerHTML = '<div class="note err">' + esc(e.message) + '</div>'; busy(false, send); });
		};

		done.onclick = function(){
			busy(true, done);
			api('/threads/' + id + '/addressed', {method:'POST'})
				.then(function(){ current = null; pane.innerHTML = '<p class="muted">Marked answered.</p>'; loadList(); })
				.catch(function(e){ out.innerHTML = '<div class="note err">' + esc(e.message) + '</div>'; busy(false, done); });
		};
	}

	Array.prototype.forEach.call(document.querySelectorAll('.tabs button'), function(b){
		b.onclick = function(){
			document.querySelectorAll('.tabs button').forEach(function(x){ x.classList.remove('on'); });
			b.classList.add('on'); status = b.dataset.status; current = null;
			pane.innerHTML = '<p class="muted">Select a message on the left.</p>';
			loadList();
		};
	});

	// Release the lock when the tab closes, so an abandoned thread frees up
	// immediately instead of waiting out the 15-minute expiry.
	window.addEventListener('pagehide', function(){
		if(!current) return;
		var url = API + '/threads/' + current + '/release';
		if(navigator.sendBeacon){
			navigator.sendBeacon(url + '?_wpnonce=' + encodeURIComponent(NONCE));
		}
	});

	loadList();
	setInterval(function(){ if(!current) loadList(); }, 60000);
})();
</script>
	<?php
}
