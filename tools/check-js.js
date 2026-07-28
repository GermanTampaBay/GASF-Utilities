#!/usr/bin/env node
/**
 * Parse-check the JavaScript that our PHP echoes into pages.
 *
 * PHP lint validates the PHP and stops there. The inline <script> blocks are
 * just strings as far as it is concerned, so a broken one sails through lint,
 * returns HTTP 200, logs no error — and takes out every event handler on the
 * page, because one unterminated literal is a parse error for the whole script.
 *
 * That has now happened twice (a real newline inside a confirm() string; a
 * function declaration shadowing window.history). Both were invisible until
 * someone opened the page. This catches them in a second.
 *
 * Usage: node tools/check-js.js
 * Exits non-zero on the first failure, so it works as a pre-commit hook.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..');

/* Files whose <script> blocks we parse. */
const TARGETS = [
	'modules/email-crm/ui.php',
	'modules/email-crm/photos-page.php',
	'modules/43-photo-catalog.php',
];

/*
 * PHP inside a script block is a value we cannot evaluate, so substitute a
 * placeholder. `null` is a valid expression in every position PHP is used in
 * here (object literals, arguments, initialisers), which keeps the surrounding
 * JS parseable without pretending we checked the PHP's output.
 */
function stripPhp(js) {
	return js
		.replace(/<\?php.*?\?>/gs, 'null')
		.replace(/<\?=.*?\?>/gs, 'null');
}

/*
 * printf placeholders are not JS. They only appear where a PHP sprintf() will
 * substitute a real value, so blank them the same way.
 */
function stripPrintf(js) {
	return js.replace(/%[sd]/g, 'null').replace(/%%/g, '%');
}

let checked = 0;
let failed = 0;

for (const rel of TARGETS) {
	const abs = path.join(ROOT, rel);
	if (!fs.existsSync(abs)) {
		console.error(`  ? ${rel} — not found, skipping`);
		continue;
	}

	const src = fs.readFileSync(abs, 'utf8');
	const blocks = src.match(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g) || [];

	blocks.forEach((raw, i) => {
		/* Skip type="application/json" and friends — not scripts to parse. */
		if (/<script[^>]*\btype\s*=\s*["'](?!text\/javascript)/i.test(raw)) { return; }

		const body = raw.replace(/^<script(?:\s[^>]*)?>/i, '').replace(/<\/script>$/i, '');
		const js = stripPrintf(stripPhp(body));
		if (!js.trim()) { return; }

		checked++;
		try {
			/* Parse only. Nothing is executed: compiling is enough to find syntax errors. */
			new vm.Script(js, { filename: `${rel} [script ${i + 1}]` });
		} catch (e) {
			failed++;
			console.error(`\n  ✗ ${rel} — script block ${i + 1} (${js.length} bytes)`);
			console.error(`    ${e.message}`);

			/* Point at the line. A syntax error's stack carries the location. */
			const at = (e.stack || '').split('\n').slice(0, 4).join('\n    ');
			console.error(`    ${at}`);
		}
	});
}

if (failed) {
	console.error(`\n${failed} of ${checked} script block(s) failed to parse.\n`);
	process.exit(1);
}

console.log(`  ✓ ${checked} inline script block(s) parse cleanly`);
