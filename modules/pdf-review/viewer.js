// PDF Review viewer — inlined as a module script into /review/<token>/ by
// modules/45-pdf-review.php. Config comes from #gasf-review-cfg (JSON).
//
// Pins are stored as fractions of the page (0..1), so a pin stays on the same
// spot at every zoom level and screen size. All user text is put in the DOM
// with textContent, never innerHTML.

const CFG = JSON.parse(document.getElementById('gasf-review-cfg').textContent);
const CSS_UNITS = 96 / 72;                 // PDF points → CSS px at "100%"
const MAX_CANVAS_PX = 16777216;            // iOS Safari's canvas area limit
const MIN_ZOOM = 0.25, MAX_ZOOM = 5, STEP = 1.25;
const POLL_MS = 15000;
const NAME_KEY = 'gasf_review_name';

const $ = (id) => document.getElementById(id);
const els = {
	app: $('app'), stage: $('stage'), sheet: $('sheet'), hint: $('hint'), list: $('list'), side: $('side'),
	prev: $('prev'), next: $('next'), pageInput: $('pageInput'), pageCount: $('pageCount'),
	zoomIn: $('zoomIn'), zoomOut: $('zoomOut'), zoomLabel: $('zoomLabel'),
	who: $('whoBtn'), sideToggle: $('sideToggle'), sideCount: $('sideCount'),
	showResolved: $('showResolved'), toast: $('toast'),
};

const store = {
	get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
	set(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* private mode: name just isn't remembered */ } },
};

const state = {
	writable: CFG.status === 'open',
	pdf: null, pageNum: 1, pageCount: 0,
	base: null,          // current page size at scale 1 (PDF points): {w, h}
	zoom: null,          // null = fit width, else 1 = 100% (actual size)
	comments: [],
	openId: null,        // thread shown in the popover
	draft: null,         // {page, x, y} while composing a new comment
	showResolved: false,
	name: store.get(NAME_KEY) || '',
	renderSeq: 0, renderTask: null, renderTimer: 0,
	polling: false,
	pendingThread: null, // a list click that came before the PDF finished loading
	writes: 0,           // bumped on every write, so a poll that started before one is discarded
};

/* ------------------------------------------------------------------ helpers */

function h(tag, attrs, ...kids) {
	const el = document.createElement(tag);
	for (const [k, v] of Object.entries(attrs || {})) {
		if (v === null || v === undefined || v === false) continue;
		if (k === 'class') el.className = v;
		else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
		else el.setAttribute(k, v === true ? '' : v);
	}
	for (const kid of kids.flat()) {
		if (kid === null || kid === undefined || kid === false) continue;
		el.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
	}
	return el;
}

const rtf = (typeof Intl !== 'undefined' && Intl.RelativeTimeFormat) ? new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }) : null;
function ago(iso) {
	const d = new Date(iso);
	const s = (d.getTime() - Date.now()) / 1000;
	const a = Math.abs(s);
	if (a < 45) return 'just now';
	if (rtf && a < 3600) return rtf.format(Math.round(s / 60), 'minute');
	if (rtf && a < 86400) return rtf.format(Math.round(s / 3600), 'hour');
	if (rtf && a < 7 * 86400) return rtf.format(Math.round(s / 86400), 'day');
	return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
}
function timeEl(iso) {
	const d = new Date(iso);
	return h('time', { datetime: iso, title: d.toLocaleString() }, ago(iso));
}

let toastTimer = 0;
function toast(msg, isError) {
	els.toast.textContent = msg;
	els.toast.className = 'toast show' + (isError ? ' error' : '');
	clearTimeout(toastTimer);
	toastTimer = setTimeout(() => { els.toast.className = 'toast'; }, isError ? 5000 : 2500);
}

function hint(msg, isError) {
	els.hint.textContent = msg;
	els.hint.className = 'hint' + (isError ? ' error' : '');
}
function idleHint() {
	const coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
	if (!state.writable) hint('This review is closed — comments are read-only.');
	else hint((coarse ? 'Tap' : 'Click') + ' anywhere on the page to leave a comment.');
}

async function api(method, url, body) {
	const headers = { Accept: 'application/json' };
	if (body) headers['Content-Type'] = 'application/json';
	if (CFG.nonce) headers['X-WP-Nonce'] = CFG.nonce;
	const r = await fetch(url, { method, headers, body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin', cache: 'no-store' });
	let data = null;
	try { data = await r.json(); } catch (e) { /* non-JSON error page */ }
	if (!r.ok) {
		const err = new Error((data && data.message) || ('Something went wrong (' + r.status + ').'));
		err.status = r.status;
		throw err;
	}
	return data;
}
const itemUrl = (id) => CFG.itemUrl.replace('{id}', String(id));

const roots = () => state.comments.filter((c) => !c.parent);
const repliesOf = (id) => state.comments.filter((c) => c.parent === id);
const byId = (id) => state.comments.find((c) => c.id === id);

/* --------------------------------------------------------------- rendering */

function fitScale() {
	if (!state.base) return 1;
	const avail = Math.max(200, els.stage.clientWidth - (window.innerWidth <= 600 ? 16 : 32));
	// Fit the width, but never blow a page up past ~1100px wide on a big monitor.
	return Math.min(avail, 1100) / state.base.w;
}
const scale = () => (state.zoom === null ? fitScale() : state.zoom * CSS_UNITS);

/** Size the sheet for the current scale (instant), then re-render (debounced). */
function layout(anchor) {
	if (!state.base) return;
	const s = scale();
	const before = anchor ? els.sheet.getBoundingClientRect() : null;
	els.sheet.style.width = Math.round(state.base.w * s) + 'px';
	els.sheet.style.height = Math.round(state.base.h * s) + 'px';
	if (anchor && before && before.width) {
		// Keep the point under the cursor (or the centre) where it was.
		const fx = (anchor.x - before.left) / before.width;
		const fy = (anchor.y - before.top) / before.height;
		const after = els.sheet.getBoundingClientRect();
		els.stage.scrollLeft += (after.left + fx * after.width) - anchor.x;
		els.stage.scrollTop += (after.top + fy * after.height) - anchor.y;
	}
	els.zoomLabel.textContent = Math.round((s / CSS_UNITS) * 100) + '%';
	els.zoomLabel.title = state.zoom === null ? 'Fitted to width' : 'Fit to width';
	els.zoomOut.disabled = s / CSS_UNITS <= MIN_ZOOM + 1e-6;
	els.zoomIn.disabled = s / CSS_UNITS >= MAX_ZOOM - 1e-6;
	positionPop();
	clearTimeout(state.renderTimer);
	state.renderTimer = setTimeout(draw, 120);
}

async function draw() {
	const seq = ++state.renderSeq;
	const page = await state.pdf.getPage(state.pageNum);
	if (seq !== state.renderSeq) return;
	const vp = page.getViewport({ scale: scale() });
	const dpr = window.devicePixelRatio || 1;
	let out = Math.min(dpr, 3);
	if (vp.width * vp.height * out * out > MAX_CANVAS_PX) out = Math.sqrt(MAX_CANVAS_PX / (vp.width * vp.height));

	const canvas = document.createElement('canvas');
	canvas.width = Math.floor(vp.width * out);
	canvas.height = Math.floor(vp.height * out);
	if (state.renderTask) { try { state.renderTask.cancel(); } catch (e) { /* already done */ } }
	const task = page.render({ canvas, viewport: vp, transform: out !== 1 ? [out, 0, 0, out, 0, 0] : undefined });
	state.renderTask = task;
	try {
		await task.promise;
	} catch (e) {
		if (e && e.name === 'RenderingCancelledException') return;
		hint('This page could not be drawn: ' + (e && e.message ? e.message : e), true);
		return;
	}
	if (seq !== state.renderSeq) return;
	const old = els.sheet.querySelector('canvas');
	if (old) old.replaceWith(canvas); else els.sheet.prepend(canvas);
}

async function showPage(n, opts) {
	if (!state.pdf) return false;      // still loading; keys/clicks before then do nothing
	n = Math.max(1, Math.min(state.pageCount, n | 0));
	const changed = n !== state.pageNum || !state.base;
	if (changed && !discardDraftOk()) return false;
	state.pageNum = n;
	els.pageInput.value = String(n);
	els.prev.disabled = n <= 1;
	els.next.disabled = n >= state.pageCount;
	try { history.replaceState(null, '', '#page=' + n); } catch (e) { /* ignore */ }
	if (changed) {
		closePop(true);
		// Invalidate any render still in flight for the old page, or it could
		// finish after this and drop the old page's canvas onto the new one.
		state.renderSeq++;
		clearTimeout(state.renderTimer);
		if (state.renderTask) { try { state.renderTask.cancel(); } catch (e) { /* done */ } }
		const page = await state.pdf.getPage(n);
		if (n !== state.pageNum) return false;   // superseded by a newer page change
		const v = page.getViewport({ scale: 1 });
		state.base = { w: v.width, h: v.height };
		const old = els.sheet.querySelector('canvas');
		if (old) old.remove();        // don't show the old page stretched to the new size
		layout();
		if (!(opts && opts.keepScroll)) els.stage.scrollTop = 0;
	}
	renderPins();
	renderList();
	return true;
}

function renderPins() {
	let layer = els.sheet.querySelector('.pins');
	if (!layer) { layer = h('div', { class: 'pins' }); els.sheet.append(layer); }
	layer.replaceChildren();
	for (const c of roots()) {
		if (c.page !== state.pageNum) continue;
		if (c.resolved && !state.showResolved && c.id !== state.openId) continue;
		layer.append(h('button', {
			type: 'button',
			class: 'pin' + (c.resolved ? ' resolved' : '') + (c.id === state.openId ? ' active' : ''),
			style: 'left:' + (c.x * 100) + '%;top:' + (c.y * 100) + '%',
			'data-id': c.id,
			'aria-label': 'Comment ' + c.num + ' by ' + c.author,
			title: c.author + ': ' + c.body.slice(0, 80),
			onclick: (e) => { e.stopPropagation(); openThread(c.id); },
		}, String(c.num)));
	}
	if (state.draft && state.draft.page === state.pageNum) {
		layer.append(h('div', { class: 'pin draft', style: 'left:' + (state.draft.x * 100) + '%;top:' + (state.draft.y * 100) + '%' }, '+'));
	}
}

function renderList() {
	const all = roots();
	const open = all.filter((c) => !c.resolved);
	els.sideCount.textContent = String(open.length);
	els.sideCount.className = 'count' + (open.length ? '' : ' zero');

	const shown = all.filter((c) => state.showResolved || !c.resolved)
		.sort((a, b) => a.page - b.page || a.y - b.y || a.num - b.num);
	els.list.replaceChildren();
	if (!shown.length) {
		const coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
		els.list.append(h('div', { class: 'empty' }, all.length
			? 'All ' + all.length + ' comment' + (all.length === 1 ? ' is' : 's are') + ' resolved. Tick "Show resolved" to see them.'
			: (state.writable ? 'No comments yet. ' + (coarse ? 'Tap' : 'Click') + ' anywhere on the page to add one.' : 'No comments.')));
		return;
	}
	let page = 0;
	for (const c of shown) {
		if (c.page !== page) {
			page = c.page;
			const n = shown.filter((x) => x.page === page).length;
			els.list.append(h('div', { class: 'grp' }, 'Page ' + page + ' · ' + n));
		}
		const reps = repliesOf(c.id).length;
		els.list.append(h('button', {
			type: 'button',
			class: 'card' + (c.resolved ? ' resolved' : '') + (c.id === state.openId ? ' active' : ''),
			onclick: () => openThread(c.id, true),
		},
			h('div', { class: 'card-h' }, h('span', { class: 'n' }, String(c.num)), h('strong', null, c.author), timeEl(c.created)),
			h('div', { class: 'card-b' }, c.body),
			(reps || c.resolved) ? h('div', { class: 'card-f' }, [reps ? reps + (reps === 1 ? ' reply' : ' replies') : '', c.resolved ? 'Resolved' : ''].filter(Boolean).join(' · ')) : null,
		));
	}
}

/* ---------------------------------------------------------------- popovers */

let pop = null;   // {el, kind: 'new'|'thread', x, y}

function closePop(force) {
	if (!pop) return true;
	if (!force && !discardDraftOk()) return false;
	pop.el.remove();
	pop = null;
	state.draft = null;
	state.openId = null;
	renderPins();
	renderList();
	return true;
}

/** True if there is no unsent text, or the reviewer agrees to lose it. */
function discardDraftOk() {
	if (!pop) return true;
	const ta = pop.el.querySelector('textarea');
	if (!ta || !ta.value.trim()) return true;
	return window.confirm('Discard the comment you were writing?');
}

function positionPop() {
	if (!pop || !state.base) return;
	if (window.innerWidth <= 600) { pop.el.style.left = ''; pop.el.style.top = ''; return; } // docked by CSS
	const W = els.sheet.clientWidth, H = els.sheet.clientHeight;
	const px = pop.x * W, py = pop.y * H;
	const pw = pop.el.offsetWidth || 320, ph = pop.el.offsetHeight || 200;
	// Keep the popover inside the part of the sheet that is actually on screen:
	// zoomed in, the sheet is wider/taller than the stage, and on narrow screens
	// the comment list overlays the stage's right edge. Otherwise it gets clipped.
	const sr = els.sheet.getBoundingClientRect(), st = els.stage.getBoundingClientRect();
	let right = st.left + els.stage.clientWidth;   // clientWidth/Height: inside the scrollbars
	if (els.app.dataset.side !== 'closed') right = Math.min(right, els.side.getBoundingClientRect().left);
	const x0 = Math.max(0, st.left - sr.left), x1 = Math.min(W, right - sr.left);
	const y0 = Math.max(0, st.top - sr.top), y1 = Math.min(H, st.top + els.stage.clientHeight - sr.top);
	let left = px + 22;
	if (left + pw > x1 && px - 22 - pw >= x0) left = px - 22 - pw;
	left = Math.max(x0 + 4, Math.min(left, Math.max(x0 + 4, x1 - pw - 4)));
	let top = py - 24;
	top = Math.max(y0 + 4, Math.min(top, Math.max(y0 + 4, y1 - ph - 4)));
	pop.el.style.left = left + 'px';
	pop.el.style.top = top + 'px';
}

function mountPop(el, kind, x, y) {
	if (pop) pop.el.remove();
	pop = { el, kind, x, y };
	el.addEventListener('click', (e) => e.stopPropagation());
	el.addEventListener('keydown', (e) => {
		if (e.key === 'Escape') { e.stopPropagation(); closePop(); }
		if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
			const btn = el.querySelector('.btn-primary');
			if (btn) { e.preventDefault(); btn.click(); }
		}
	});
	els.sheet.append(el);
	positionPop();
}

/* -------------------------------------------------------------- name dialog */

let nameDlg = null;   // the "Set your name" dialog while it is open

/**
 * Ask for the reviewer's name in a small dialog, then run `then` once it is
 * saved. Comments can't be written without a name, so the composer and the
 * reply box both go through here first.
 */
function askName(then) {
	if (nameDlg) { nameDlg.querySelector('input').focus(); return; }
	const input = h('input', { class: 'in', type: 'text', maxlength: CFG.maxAuthor, placeholder: 'Your name', autocomplete: 'name', 'aria-label': 'Your name' });
	input.value = state.name;
	const save = h('button', { type: 'button', class: 'btn btn-primary' }, 'Save');
	const close = () => { if (!nameDlg) return; nameDlg.remove(); nameDlg = null; };
	const card = h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'nameDlgTitle' },
		h('div', { class: 'pop-h' }, h('span', { id: 'nameDlgTitle' }, 'Set your name'),
			h('button', { type: 'button', class: 'x', 'aria-label': 'Close', onclick: close }, '×')),
		h('p', { class: 'modal-p' }, 'It’s shown next to your comments so everyone knows who said what.'),
		input,
		h('div', { class: 'pop-f' }, h('button', { type: 'button', class: 'btn', onclick: close }, 'Cancel'), save));
	const veil = h('div', { class: 'veil' }, card);
	save.addEventListener('click', () => {
		const v = input.value.trim().slice(0, CFG.maxAuthor);
		if (!v) { input.focus(); shake(card); return; }
		rememberName(v);
		close();
		if (then) then();
	});
	veil.addEventListener('click', (e) => { e.stopPropagation(); if (e.target === veil) close(); });
	veil.addEventListener('keydown', (e) => {
		e.stopPropagation();
		if (e.key === 'Escape') close();
		if (e.key === 'Enter') { e.preventDefault(); save.click(); }
	});
	nameDlg = veil;
	document.body.append(veil);
	input.focus();
	input.select();
}

/** Read the comment text from a popover, or shake it and return null. */
function readForm(el) {
	const ta = el.querySelector('textarea');
	const body = ta.value.trim();
	if (!state.name) { askName(); return null; }
	if (!body) { ta.focus(); shake(el); return null; }
	return { name: state.name, body, website: (el.querySelector('input[name=website]') || {}).value || '' };
}
function shake(el) { el.classList.remove('shake'); void el.offsetWidth; el.classList.add('shake'); }

function rememberName(name) {
	if (name && name !== state.name) { state.name = name; store.set(NAME_KEY, name); updateWho(); }
}

function openComposer(spot) {
	state.openId = null;
	state.draft = spot;
	const ta = h('textarea', { rows: 3, maxlength: CFG.maxBody, placeholder: 'Write a comment…', 'aria-label': 'Comment' });
	const post = h('button', { type: 'button', class: 'btn btn-primary' }, 'Post');
	const el = h('div', { class: 'pop', role: 'dialog', 'aria-label': 'New comment' },
		h('div', { class: 'pop-h' }, 'New comment', h('span', { class: 'tag' }, 'Page ' + spot.page),
			h('button', { type: 'button', class: 'x', 'aria-label': 'Close', onclick: () => closePop() }, '×')),
		ta,
		h('input', { class: 'hp', type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }),
		h('div', { class: 'pop-f' }, h('button', { type: 'button', class: 'btn', onclick: () => closePop() }, 'Cancel'), post),
		h('div', { class: 'pop-note' }, 'Ctrl+Enter to post'));
	post.addEventListener('click', async () => {
		if (!state.name) { askName(() => post.click()); return; }
		const f = readForm(el);
		if (!f) return;
		post.disabled = true;
		try {
			const res = await api('POST', CFG.listUrl, { page: spot.page, x: spot.x, y: spot.y, author: f.name, body: f.body, website: f.website });
			rememberName(f.name);
			applyWrite(res);
			closePop(true);
			pulse(res.id);
			toast('Comment posted');
		} catch (e) {
			post.disabled = false;
			toast(e.message, true);
		}
	});
	mountPop(el, 'new', spot.x, spot.y);
	renderPins();
	ta.focus({ preventScroll: true });
}

function pulse(id) {
	const pin = els.sheet.querySelector('.pin[data-id="' + id + '"]');
	if (pin) pin.classList.add('pulse');
}

async function openThread(id, fromList) {
	const c = byId(id);
	if (!c) return;
	if (!state.pdf) { state.pendingThread = id; return; }   // opened once the PDF is ready
	if (pop && pop.kind === 'new' && !closePop()) return;
	if (c.page !== state.pageNum) {
		if (!(await showPage(c.page))) return;
	}
	if (fromList && window.innerWidth <= 860) setSide(false);
	state.draft = null;
	state.openId = id;
	const el = h('div', { class: 'pop', role: 'dialog', 'aria-label': 'Comment ' + c.num });
	mountPop(el, 'thread', c.x, c.y);
	fillThread();
	renderPins();
	renderList();
	const pin = els.sheet.querySelector('.pin[data-id="' + id + '"]');
	if (pin && pin.scrollIntoView) pin.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' });
}

/** (Re)build the open thread's contents, keeping any half-typed reply. */
function fillThread() {
	if (!pop || pop.kind !== 'thread') return;
	const c = byId(state.openId);
	if (!c) { closePop(true); toast('That comment was deleted.'); return; }
	const el = pop.el;
	const oldTa = el.querySelector('textarea');
	const keep = oldTa ? oldTa.value : '';
	const hadFocus = oldTa && document.activeElement === oldTa;

	const msg = (m) => h('div', { class: 'msg' },
		h('div', { class: 'msg-h' }, h('strong', null, m.author), timeEl(m.created),
			CFG.admin ? h('button', { type: 'button', class: 'del', onclick: () => del(m) }, 'Delete') : null),
		h('div', { class: 'msg-b' }, m.body));

	const kids = [
		h('div', { class: 'pop-h' }, '#' + c.num, h('span', { class: 'tag' }, 'Page ' + c.page), c.resolved ? h('span', { class: 'tag' }, 'Resolved') : null,
			h('button', { type: 'button', class: 'x', 'aria-label': 'Close', onclick: () => closePop() }, '×')),
		h('div', { class: 'msgs' }, msg(c), repliesOf(c.id).map(msg)),
	];
	if (state.writable) {
		const ta = h('textarea', { rows: 2, maxlength: CFG.maxBody, placeholder: 'Reply…', 'aria-label': 'Reply' });
		ta.value = keep;
		const reply = h('button', { type: 'button', class: 'btn btn-primary' }, 'Reply');
		const resolve = h('button', { type: 'button', class: 'btn left' }, c.resolved ? 'Reopen' : 'Resolve');
		kids.push(ta,
			h('input', { class: 'hp', type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }),
			h('div', { class: 'pop-f' }, resolve, reply));
		reply.addEventListener('click', async () => {
			if (!state.name) { askName(() => reply.click()); return; }
			const f = readForm(el);
			if (!f) return;
			reply.disabled = true;
			try {
				const res = await api('POST', CFG.listUrl, { parent: c.id, author: f.name, body: f.body, website: f.website });
				rememberName(f.name);
				applyWrite(res);
				el.querySelector('textarea').value = '';
				refreshAll();
				const box = el.querySelector('.msgs');
				if (box) box.scrollTop = box.scrollHeight;
			} catch (e) {
				reply.disabled = false;
				toast(e.message, true);
			}
		});
		resolve.addEventListener('click', async () => {
			resolve.disabled = true;
			try {
				const res = await api('POST', itemUrl(c.id), { resolved: !c.resolved });
				applyWrite(res);
				const nowResolved = !c.resolved;
				if (nowResolved && !state.showResolved) {
					closePop(true);
					toast('Resolved — tick "Show resolved" to see it again.');
				} else {
					refreshAll();
				}
			} catch (e) {
				resolve.disabled = false;
				toast(e.message, true);
			}
		});
	}
	el.replaceChildren(...kids.filter(Boolean));
	if (hadFocus) { const ta = el.querySelector('textarea'); if (ta) ta.focus({ preventScroll: true }); }
	positionPop();
}

async function del(m) {
	if (!window.confirm(m.parent ? 'Delete this reply?' : 'Delete this comment and all its replies?')) return;
	try {
		await api('DELETE', itemUrl(m.id));
		applyWrite(null);
		state.comments = state.comments.filter((x) => x.id !== m.id && x.parent !== m.id);
		refreshAll();
		toast('Deleted');
	} catch (e) {
		toast(e.message, true);
	}
}

function refreshAll() {
	renderPins();
	renderList();
	fillThread();
}

/* -------------------------------------------------------------- data + poll */

/** Replace the comment list with a write's response (the server's full list). */
function applyWrite(res) {
	state.writes++;
	if (res && res.comments) state.comments = res.comments;
}

async function loadComments() {
	if (state.polling) return;
	state.polling = true;
	const writes = state.writes;
	try {
		const res = await api('GET', CFG.listUrl);
		if (writes !== state.writes) return;   // a post/resolve/delete landed meanwhile; its data is newer
		state.comments = res.comments || [];
		if (res.status !== 'open' && state.writable) {
			state.writable = false;
			els.sheet.classList.remove('writable');
			if (pop && pop.kind === 'new') closePop(true);
			idleHint();
			toast('This review was just closed — comments are now read-only.');
		}
		refreshAll();
	} catch (e) {
		if (e.status === 404 || e.status === 410) {
			state.writable = false;
			els.sheet.classList.remove('writable');
			closePop(true);
			hint(e.status === 410 ? 'This review has been closed.' : 'This review no longer exists.', true);
		}
		// Other failures (network blips): keep what we have, try again next poll.
	} finally {
		state.polling = false;
	}
}

/* -------------------------------------------------------------------- misc */

function updateWho() {
	els.who.textContent = state.name ? 'You: ' + state.name : 'Set your name';
	els.who.hidden = !state.writable && !state.name;
}

function setSide(open) {
	els.app.dataset.side = open ? 'open' : 'closed';
	els.sideToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function zoomBy(factor, anchor) {
	const cur = scale() / CSS_UNITS;
	const next = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, cur * factor));
	if (Math.abs(next - cur) < 1e-6) return;
	state.zoom = next;
	layout(anchor || stageCentre());
}
function stageCentre() {
	const r = els.stage.getBoundingClientRect();
	return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
}

function bind() {
	els.prev.addEventListener('click', () => showPage(state.pageNum - 1));
	els.next.addEventListener('click', () => showPage(state.pageNum + 1));
	els.pageInput.addEventListener('change', () => {
		const n = parseInt(els.pageInput.value, 10);
		if (n) showPage(n); else els.pageInput.value = String(state.pageNum);
	});
	els.pageInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') els.pageInput.blur(); });
	els.pageInput.addEventListener('focus', () => els.pageInput.select());

	els.zoomIn.addEventListener('click', () => zoomBy(STEP));
	els.zoomOut.addEventListener('click', () => zoomBy(1 / STEP));
	els.zoomLabel.addEventListener('click', () => { state.zoom = null; layout(); });

	// Ctrl/⌘ + wheel (and trackpad pinch, which browsers report the same way).
	els.stage.addEventListener('wheel', (e) => {
		if (!e.ctrlKey && !e.metaKey) return;
		e.preventDefault();
		zoomBy(Math.exp(-e.deltaY * 0.01), { x: e.clientX, y: e.clientY });
	}, { passive: false });

	els.sheet.addEventListener('click', (e) => {
		if (e.target.closest('.pin, .pop')) return;
		if (pop) { closePop(); return; }              // first click just dismisses
		if (!state.writable) return;
		const r = els.sheet.getBoundingClientRect();
		const x = (e.clientX - r.left) / r.width, y = (e.clientY - r.top) / r.height;
		if (x < 0 || x > 1 || y < 0 || y > 1) return;
		const spot = { page: state.pageNum, x: Math.round(x * 1e6) / 1e6, y: Math.round(y * 1e6) / 1e6 };
		if (!state.name) { askName(() => openComposer(spot)); return; }   // name first, then the comment
		openComposer(spot);
	});

	els.who.addEventListener('click', () => askName());
	els.sideToggle.addEventListener('click', () => setSide(els.app.dataset.side !== 'open'));
	els.showResolved.addEventListener('change', () => { state.showResolved = els.showResolved.checked; refreshAll(); });

	document.addEventListener('keydown', (e) => {
		const t = e.target;
		if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
		if (e.ctrlKey || e.metaKey || e.altKey) return;
		if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); showPage(state.pageNum - 1); }
		else if (e.key === 'ArrowRight' || e.key === 'PageDown') { e.preventDefault(); showPage(state.pageNum + 1); }
		else if (e.key === '+' || e.key === '=') zoomBy(STEP);
		else if (e.key === '-' || e.key === '_') zoomBy(1 / STEP);
		else if (e.key === '0') { state.zoom = null; layout(); }
		else if (e.key === 'Escape') { if (pop) closePop(); else if (window.innerWidth <= 860) setSide(false); }
	});

	// Re-fit when the stage changes width (window resize, list opened/closed).
	if (window.ResizeObserver) {
		let w = 0;
		new ResizeObserver(() => {
			const nw = els.stage.clientWidth;
			if (nw !== w) { w = nw; if (state.zoom === null) layout(); else positionPop(); }
		}).observe(els.stage);
	} else {
		window.addEventListener('resize', () => { if (state.zoom === null) layout(); });
	}

	document.addEventListener('visibilitychange', () => { if (!document.hidden) loadComments(); });
	setInterval(() => { if (!document.hidden) loadComments(); }, POLL_MS);
}

/* -------------------------------------------------------------------- boot */

async function start() {
	setSide(window.innerWidth > 860);
	updateWho();
	bind();
	loadComments();

	let pdfjs;
	try {
		pdfjs = await import(CFG.pdfjs + '/legacy/build/pdf.min.mjs');
	} catch (e) {
		hint('The PDF viewer could not load. Check your connection and reload the page.', true);
		return;
	}
	pdfjs.GlobalWorkerOptions.workerSrc = CFG.pdfjs + '/legacy/build/pdf.worker.min.mjs';
	const task = pdfjs.getDocument({
		url: CFG.fileUrl,
		httpHeaders: CFG.nonce ? { 'X-WP-Nonce': CFG.nonce } : undefined,
		cMapUrl: CFG.pdfjs + '/cmaps/',
		standardFontDataUrl: CFG.pdfjs + '/standard_fonts/',
		wasmUrl: CFG.pdfjs + '/wasm/',
		iccUrl: CFG.pdfjs + '/iccs/',
	});
	task.onProgress = (p) => { if (p && p.total) hint('Loading the PDF… ' + Math.min(100, Math.round((p.loaded / p.total) * 100)) + '%'); };
	try {
		state.pdf = await task.promise;
	} catch (e) {
		const st = e && e.status;
		hint(st === 410 ? 'This review has been closed.' : st === 404 ? 'This review, or its PDF, no longer exists.' : 'The PDF could not be opened' + (e && e.message ? ': ' + e.message : '.'), true);
		return;
	}
	state.pageCount = state.pdf.numPages;
	els.pageCount.textContent = String(state.pageCount);
	if (state.writable) els.sheet.classList.add('writable');
	idleHint();
	const m = /page=(\d+)/.exec(location.hash);
	await showPage(m ? parseInt(m[1], 10) : 1);
	if (state.pendingThread) openThread(state.pendingThread);
}

start();
