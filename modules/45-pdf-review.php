<?php
/**
 * PDF Review — modules/45-pdf-review.php
 *
 * Upload one PDF in wp-admin, get one unguessable link, send it to reviewers.
 * They open it in a browser (no account), read the PDF at full resolution,
 * zoom, page through it, click anywhere on a page to pin a comment there, and
 * reply to each other's comments. Every comment is listed with its page.
 *
 *   Reviewer page   /review/<32-char token>/      (fallback: /?gasf_review=<token>)
 *   PDF bytes       /wp-json/gasf-util/v1/review/<token>/file
 *   Comments API    /wp-json/gasf-util/v1/review/<token>/comments[/<id>]
 *
 * DESIGN NOTES — why it is built the way it is:
 *
 * - The link token IS the credential (like the internal-calendar print link
 *   and the party photo doors). 32 alphanumerics from wp_generate_password(),
 *   which draws on random_int(): ~190 bits, not guessable. The site's
 *   Referrer-Policy (module 43) keeps it from leaking to other sites.
 *   Closing a review makes the link stop working for reviewers; nothing is
 *   deleted until you press Delete.
 *
 * - The PDF is NOT a Media Library attachment. Attachments get public
 *   attachment pages and show up in media searches, and the host's image
 *   optimizers hook uploads (see module 44). Files live in
 *   uploads/gasf-review/ under a random filename unrelated to the link token,
 *   behind a deny-all .htaccess, and are only ever served by the REST /file
 *   route after it checks the token and the open/closed state.
 *
 * - Comments are in two custom tables, not WP comments. WP comments would
 *   bring moderation queues, "new comment" emails, the Recent Comments
 *   widget and comment feeds along — each a way for a private document's
 *   comments to escape or get held. Two small tables do exactly this job.
 *
 * - The PDF is served by REST, not by a front-end URL, because the host's
 *   page cache stores front-end responses as static HTML files; a cached
 *   PDF would come back as text/html. The reviewer page itself is a shell
 *   with no comment data in it, so even if cached it leaks nothing and goes
 *   stale harmlessly — all data comes from the uncached REST routes.
 *
 * - Rendering is PDF.js (Mozilla, Apache-2.0), loaded from jsDelivr pinned
 *   to an exact version (GASF_REVIEW_PDFJS). The LEGACY build, because it
 *   runs on older iPads/Safari that the modern build refuses. Only the
 *   library comes from the CDN; the PDF itself goes nowhere but this site.
 *   The viewer's own JS/CSS live in modules/pdf-review/ and are inlined
 *   into the page, because on the main site this plugin loads from outside
 *   wp-content and has no public URL for assets.
 *
 * - Pins are stored as fractions of the page (x, y in 0..1), so they stay on
 *   the same spot at every zoom level and screen size.
 *
 * Abuse limits (the link can be forwarded): a hidden honeypot field, 60
 * posts per 10 minutes per IP per review, 2,000 comments per review,
 * 60-character names and 4,000-character comments.
 *
 * Gate: gasf_site_enable_pdfreview (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_pdfreview' ) ) {

	if ( ! defined( 'GASF_REVIEW_MAX_BYTES' ) ) { define( 'GASF_REVIEW_MAX_BYTES', 50 * 1024 * 1024 ); }
	if ( ! defined( 'GASF_REVIEW_PDFJS' ) ) { define( 'GASF_REVIEW_PDFJS', '6.4.299' ); }
	if ( ! defined( 'GASF_REVIEW_DB_VER' ) ) { define( 'GASF_REVIEW_DB_VER', '1' ); }
	if ( ! defined( 'GASF_REVIEW_MAX_COMMENTS' ) ) { define( 'GASF_REVIEW_MAX_COMMENTS', 2000 ); }
	if ( ! defined( 'GASF_REVIEW_RATE' ) ) { define( 'GASF_REVIEW_RATE', 60 ); } // posts per IP per review per 10 min

	/* =====================================================================
	 * Storage: tables + upload directory.
	 * =================================================================== */

	function gasf_review_tables() {
		global $wpdb;
		return array(
			'docs'     => $wpdb->prefix . 'gasf_review_docs',
			'comments' => $wpdb->prefix . 'gasf_review_comments',
		);
	}

	/**
	 * Create/upgrade the two tables. Cheap no-op once the stored schema
	 * version matches; only marks itself done when both tables really exist,
	 * so a failed CREATE is retried instead of being recorded as success.
	 */
	function gasf_review_install() {
		if ( get_option( 'gasf_review_db_ver' ) === GASF_REVIEW_DB_VER ) { return true; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t  = gasf_review_tables();
		$cs = $wpdb->get_charset_collate();
		// dbDelta is picky: one column per line, two spaces after PRIMARY KEY.
		dbDelta( "CREATE TABLE {$t['docs']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token char(32) NOT NULL,
  title varchar(200) NOT NULL DEFAULT '',
  orig_name varchar(255) NOT NULL DEFAULT '',
  file varchar(64) NOT NULL DEFAULT '',
  bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(10) NOT NULL DEFAULT 'open',
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token)
) $cs;" );
		dbDelta( "CREATE TABLE {$t['comments']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  doc_id bigint(20) unsigned NOT NULL,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  num int(10) unsigned NOT NULL DEFAULT 0,
  page smallint(5) unsigned NOT NULL DEFAULT 1,
  x decimal(7,6) NOT NULL DEFAULT 0,
  y decimal(7,6) NOT NULL DEFAULT 0,
  author varchar(60) NOT NULL DEFAULT '',
  body text NOT NULL,
  resolved tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY doc_id (doc_id,id)
) $cs;" );
		$ok = true;
		foreach ( $t as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) { $ok = false; }
		}
		if ( $ok ) {
			update_option( 'gasf_review_db_ver', GASF_REVIEW_DB_VER );
		} else {
			gasf_mec_log( 'pdf-review: table create failed: ' . $wpdb->last_error );
		}
		return $ok;
	}
	add_action( 'admin_init', 'gasf_review_install' );

	/** uploads/gasf-review/, created on demand with a deny-all .htaccess. */
	function gasf_review_dir() {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'gasf-review';
		if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "# GASF PDF Review: files are served only through the token-checked\n# /wp-json/gasf-util/v1/review/<token>/file route, never directly.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir;
	}

	/** Upload size cap: ours or PHP's, whichever is smaller. */
	function gasf_review_max_bytes() {
		$php = (int) wp_max_upload_size();
		return $php > 0 ? min( GASF_REVIEW_MAX_BYTES, $php ) : GASF_REVIEW_MAX_BYTES;
	}

	function gasf_review_doc_by_token( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) { return null; }
		global $wpdb;
		$t = gasf_review_tables();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['docs']} WHERE token = %s", $token ) );
	}

	function gasf_review_doc_by_id( $id ) {
		global $wpdb;
		$t = gasf_review_tables();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['docs']} WHERE id = %d", (int) $id ) );
	}

	/** The reviewer link: pretty when permalinks are on, ?gasf_review= otherwise. */
	function gasf_review_link( $doc ) {
		return get_option( 'permalink_structure' )
			? home_url( '/review/' . $doc->token . '/' )
			: add_query_arg( 'gasf_review', $doc->token, home_url( '/' ) );
	}

	/**
	 * Store an uploaded PDF and create its review. Returns the doc row or a
	 * WP_Error whose message is safe to show the admin.
	 */
	function gasf_review_create( array $f, $title ) {
		if ( ! gasf_review_install() ) { return new WP_Error( 'db', 'Could not create the review tables — see the plugin log.' ); }
		$max = gasf_review_max_bytes();
		$err = (int) ( $f['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_INI_SIZE === $err || UPLOAD_ERR_FORM_SIZE === $err ) {
			return new WP_Error( 'size', 'That file is larger than this server accepts (' . size_format( $max ) . ').' );
		}
		if ( UPLOAD_ERR_OK !== $err || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return new WP_Error( 'upload', 'Upload failed — pick a PDF and try again.' );
		}
		if ( (int) $f['size'] > $max ) {
			return new WP_Error( 'size', 'That file is larger than ' . size_format( $max ) . '.' );
		}
		// A PDF starts "%PDF-" within its first 1,024 bytes (ISO 32000-1 §7.5.2
		// lets junk precede it, and some generators do). That is the real test;
		// the extension and browser-sent MIME type are only claims.
		$head = (string) @file_get_contents( $f['tmp_name'], false, null, 0, 1024 );
		if ( false === strpos( $head, '%PDF-' ) ) {
			return new WP_Error( 'type', 'That file isn&rsquo;t a PDF.' );
		}

		$dir  = gasf_review_dir();
		$file = bin2hex( random_bytes( 16 ) ) . '.pdf';
		if ( ! @move_uploaded_file( $f['tmp_name'], $dir . '/' . $file ) ) {
			return new WP_Error( 'move', 'Could not save the file in ' . esc_html( $dir ) . ' — check that uploads/ is writable.' );
		}

		global $wpdb;
		$t     = gasf_review_tables();
		$orig  = sanitize_file_name( (string) ( $f['name'] ?? 'document.pdf' ) );
		$title = trim( sanitize_text_field( $title ) );
		if ( '' === $title ) { $title = preg_replace( '/\.pdf$/i', '', (string) ( $f['name'] ?? 'Document' ) ); $title = sanitize_text_field( $title ); }
		for ( $i = 0; $i < 5; $i++ ) {
			$token = wp_generate_password( 32, false, false );
			$ok    = $wpdb->insert( $t['docs'], array(
				'token'      => $token,
				'title'      => mb_substr( $title, 0, 200 ),
				'orig_name'  => mb_substr( $orig, 0, 255 ),
				'file'       => $file,
				'bytes'      => (int) $f['size'],
				'status'     => 'open',
				'created_at' => current_time( 'mysql', true ),
				'created_by' => get_current_user_id(),
			) );
			if ( $ok ) { return gasf_review_doc_by_id( $wpdb->insert_id ); }
		}
		@unlink( $dir . '/' . $file );
		return new WP_Error( 'db', 'Could not save the review: ' . esc_html( $wpdb->last_error ) );
	}

	/** Delete a review: its file, its comments, its row. */
	function gasf_review_delete( $doc ) {
		global $wpdb;
		$t = gasf_review_tables();
		if ( $doc->file && preg_match( '/^[a-f0-9]{32}\.pdf$/', $doc->file ) ) { @unlink( gasf_review_dir() . '/' . $doc->file ); }
		$wpdb->delete( $t['comments'], array( 'doc_id' => (int) $doc->id ), array( '%d' ) );
		$wpdb->delete( $t['docs'], array( 'id' => (int) $doc->id ), array( '%d' ) );
	}

	/** Delete one comment; deleting a thread's first comment deletes its replies. */
	function gasf_review_delete_comment( $doc_id, $id ) {
		global $wpdb;
		$t = gasf_review_tables();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['comments']} WHERE doc_id = %d AND ( id = %d OR parent_id = %d )", (int) $doc_id, (int) $id, (int) $id ) );
	}

	/** Every comment on a review, oldest first, shaped for the viewer. */
	function gasf_review_comments( $doc_id ) {
		global $wpdb;
		$t    = gasf_review_tables();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['comments']} WHERE doc_id = %d ORDER BY id ASC", (int) $doc_id ) );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'       => (int) $r->id,
				'parent'   => (int) $r->parent_id,
				'num'      => (int) $r->num,
				'page'     => (int) $r->page,
				'x'        => (float) $r->x,
				'y'        => (float) $r->y,
				'author'   => (string) $r->author,
				'body'     => (string) $r->body,
				'resolved' => (bool) $r->resolved,
				'created'  => mysql_to_rfc3339( $r->created_at ) . 'Z',
			);
		}
		return $out;
	}

	/* =====================================================================
	 * Reviewer page.
	 * =================================================================== */

	gasf_util_rewrite( '^review/([A-Za-z0-9]{32})/?$', 'index.php?gasf_review=$matches[1]' );
	add_filter( 'query_vars', function ( $v ) { $v[] = 'gasf_review'; return $v; } );

	add_action( 'template_redirect', 'gasf_review_maybe_render', 1 );
	function gasf_review_maybe_render() {
		$token = get_query_var( 'gasf_review' );
		if ( ! $token && isset( $_GET['gasf_review'] ) ) { $token = sanitize_text_field( wp_unslash( $_GET['gasf_review'] ) ); }
		if ( ! $token ) { return; }

		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );

		$doc   = gasf_review_doc_by_token( $token );
		$admin = current_user_can( 'manage_options' );
		if ( ! $doc ) {
			gasf_review_message_page( 404, 'Review not found', 'This review link isn&rsquo;t valid. Check that you copied the whole link.' );
		}
		if ( 'open' !== $doc->status && ! $admin ) {
			gasf_review_message_page( 410, 'Review closed', 'This review has been closed and the link no longer works. If you still need it, ask whoever sent it to you.' );
		}
		gasf_review_render_viewer( $doc, $admin );
		exit;
	}

	function gasf_review_message_page( $code, $title, $html ) {
		status_header( $code );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title>'
			. '<style>body{font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f3f4f6;color:#1f2937;margin:0;display:grid;place-items:center;min-height:100vh;padding:16px;box-sizing:border-box}main{background:#fff;border-radius:12px;padding:28px 32px;max-width:440px;box-shadow:0 1px 3px rgba(0,0,0,.08)}h1{font-size:20px;margin:0 0 8px}p{margin:0;color:#4b5563}</style>'
			. '</head><body><main><h1>' . esc_html( $title ) . '</h1><p>' . wp_kses_post( $html ) . '</p></main></body></html>';
		exit;
	}

	function gasf_review_render_viewer( $doc, $admin ) {
		$base = 'gasf-util/v1/review/' . $doc->token;
		$cfg  = array(
			'title'     => (string) $doc->title,
			'status'    => (string) $doc->status,
			'admin'     => (bool) $admin,
			'fileUrl'   => rest_url( $base . '/file' ),
			'listUrl'   => rest_url( $base . '/comments' ),
			'itemUrl'   => rest_url( $base . '/comments/' ) . '{id}',
			'nonce'     => $admin ? wp_create_nonce( 'wp_rest' ) : '',
			'pdfjs'     => 'https://cdn.jsdelivr.net/npm/pdfjs-dist@' . GASF_REVIEW_PDFJS,
			'maxBody'   => 4000,
			'maxAuthor' => 60,
		);
		$dir = __DIR__ . '/pdf-review/';
		$css = (string) @file_get_contents( $dir . 'viewer.css' );
		$js  = (string) @file_get_contents( $dir . 'viewer.js' );

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $doc->title . ' — Review' ); ?></title>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<style><?php echo $css; // phpcs:ignore -- our own file, not user input ?></style>
</head>
<body>
<div id="app" class="app">
	<header class="bar">
		<div class="bar-title" title="<?php echo esc_attr( $doc->title ); ?>"><?php echo esc_html( $doc->title ); ?></div>
		<div class="bar-group" role="group" aria-label="Pages">
			<button type="button" class="btn" id="prev" aria-label="Previous page" title="Previous page (←)">&#9664;</button>
			<span class="pager"><input id="pageInput" type="text" inputmode="numeric" value="1" aria-label="Page number"> <span class="of">/ <span id="pageCount">–</span></span></span>
			<button type="button" class="btn" id="next" aria-label="Next page" title="Next page (→)">&#9654;</button>
		</div>
		<div class="bar-group" role="group" aria-label="Zoom">
			<button type="button" class="btn" id="zoomOut" aria-label="Zoom out" title="Zoom out (−)">&minus;</button>
			<button type="button" class="btn btn-text" id="zoomLabel" title="Fit to width">100%</button>
			<button type="button" class="btn" id="zoomIn" aria-label="Zoom in" title="Zoom in (+)">+</button>
		</div>
		<button type="button" class="btn btn-text" id="whoBtn" title="Change the name shown on your comments"></button>
		<button type="button" class="btn btn-text btn-side" id="sideToggle" aria-controls="side" aria-expanded="false">Comments <span class="count" id="sideCount">0</span></button>
	</header>
	<?php if ( 'open' !== $doc->status ) : ?>
		<div class="banner">This review is <strong>closed</strong> — reviewers can&rsquo;t open this link. You&rsquo;re seeing it because you&rsquo;re signed in as an admin; reopen it in wp-admin to allow comments again.</div>
	<?php endif; ?>
	<div class="body">
		<main class="stage" id="stage">
			<div class="hint" id="hint">Loading the PDF…</div>
			<div class="sheet" id="sheet"></div>
		</main>
		<aside class="side" id="side" aria-label="All comments">
			<div class="side-head">
				<strong>All comments</strong>
				<label class="chk"><input type="checkbox" id="showResolved"> Show resolved</label>
			</div>
			<div class="side-list" id="list"></div>
		</aside>
	</div>
	<div class="toast" id="toast" role="status" aria-live="polite"></div>
</div>
<script type="application/json" id="gasf-review-cfg"><?php echo wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?></script>
<script type="module"><?php echo $js; // phpcs:ignore -- our own file, not user input ?></script>
</body>
</html>
		<?php
	}

	/* =====================================================================
	 * REST API.
	 * =================================================================== */

	/**
	 * Resolve the token to a review the caller may use. Reads: open reviews,
	 * or any review for an admin. Writes: open reviews only.
	 */
	function gasf_review_rest_doc( WP_REST_Request $req, $write = false ) {
		$doc = gasf_review_doc_by_token( (string) $req['token'] );
		if ( ! $doc ) { return new WP_Error( 'gasf_review_not_found', 'This review link isn’t valid.', array( 'status' => 404 ) ); }
		if ( 'open' !== $doc->status && ( $write || ! current_user_can( 'manage_options' ) ) ) {
			return new WP_Error( 'gasf_review_closed', 'This review is closed.', array( 'status' => 410 ) );
		}
		return $doc;
	}

	function gasf_review_no_store( $data, $status = 200 ) {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store, private' );
		$r->header( 'X-Robots-Tag', 'noindex, nofollow' );
		return $r;
	}

	/** Client IP, for the rate limit only — hashed, never stored with a comment. */
	function gasf_review_ip_key( $doc_id ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return 'gasf_rv_rl_' . (int) $doc_id . '_' . substr( wp_hash( $ip, 'nonce' ), 0, 16 );
	}

	add_action( 'rest_api_init', function () {
		$tok = '(?P<token>[A-Za-z0-9]{32})';

		register_rest_route( 'gasf-util/v1', '/review/' . $tok . '/file', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'gasf_review_rest_file',
		) );

		register_rest_route( 'gasf-util/v1', '/review/' . $tok . '/comments', array(
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $req ) {
					$doc = gasf_review_rest_doc( $req );
					if ( is_wp_error( $doc ) ) { return $doc; }
					return gasf_review_no_store( array(
						'status'   => (string) $doc->status,
						'comments' => gasf_review_comments( $doc->id ),
					) );
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'gasf_review_rest_create',
			),
		) );

		register_rest_route( 'gasf-util/v1', '/review/' . $tok . '/comments/(?P<id>\d+)', array(
			array(
				// Resolve / reopen a thread — anyone with the link.
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'gasf_review_rest_resolve',
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => function () { return current_user_can( 'manage_options' ); },
				'callback'            => function ( WP_REST_Request $req ) {
					$doc = gasf_review_doc_by_token( (string) $req['token'] );
					if ( ! $doc ) { return new WP_Error( 'gasf_review_not_found', 'Not found.', array( 'status' => 404 ) ); }
					gasf_review_delete_comment( $doc->id, (int) $req['id'] );
					return gasf_review_no_store( array( 'deleted' => (int) $req['id'] ) );
				},
			),
		) );
	} );

	/** Stream the PDF. Exits: a REST response would JSON-encode it. */
	function gasf_review_rest_file( WP_REST_Request $req ) {
		$doc = gasf_review_rest_doc( $req );
		if ( is_wp_error( $doc ) ) { return $doc; }
		$path = gasf_review_dir() . '/' . $doc->file;
		if ( ! preg_match( '/^[a-f0-9]{32}\.pdf$/', (string) $doc->file ) || ! is_readable( $path ) ) {
			return new WP_Error( 'gasf_review_file', 'The PDF for this review is missing.', array( 'status' => 404 ) );
		}
		while ( ob_get_level() ) { ob_end_clean(); }
		$name = preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $doc->orig_name );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . ( $name ? $name : 'review.pdf' ) . '"' );
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		readfile( $path );
		exit;
	}

	/**
	 * Plain text in, plain text stored. Deliberately NOT sanitize_text_field():
	 * that strips tags and turns a lone "<" into "&lt;", so a reviewer typing
	 * "make the logo < 2 inches" would see "&lt;" in their own comment. Every
	 * output path escapes (textContent in the viewer, esc_html in wp-admin),
	 * so input only needs to be valid UTF-8 without control characters.
	 */
	function gasf_review_clean_text( $s, $multiline ) {
		$s = wp_check_invalid_utf8( is_scalar( $s ) ? (string) $s : '', true );
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s );
		$s = $multiline ? preg_replace( "/\n{4,}/", "\n\n\n", $s ) : preg_replace( '/\s+/u', ' ', $s );
		return trim( (string) $s );
	}

	function gasf_review_rest_create( WP_REST_Request $req ) {
		$doc = gasf_review_rest_doc( $req, true );
		if ( is_wp_error( $doc ) ) { return $doc; }
		$p = (array) $req->get_json_params();

		// Honeypot: a field no person can see. Pretend success, store nothing.
		if ( ! empty( $p['website'] ) ) { return gasf_review_no_store( array( 'ok' => true ), 201 ); }

		$author = gasf_review_clean_text( $p['author'] ?? '', false );
		$body   = gasf_review_clean_text( $p['body'] ?? '', true );
		if ( '' === $author ) { return new WP_Error( 'gasf_review_author', 'Please enter your name.', array( 'status' => 400 ) ); }
		if ( '' === $body ) { return new WP_Error( 'gasf_review_body', 'The comment is empty.', array( 'status' => 400 ) ); }
		if ( mb_strlen( $author ) > 60 ) { return new WP_Error( 'gasf_review_author', 'Names can be up to 60 characters.', array( 'status' => 400 ) ); }
		if ( mb_strlen( $body ) > 4000 ) { return new WP_Error( 'gasf_review_body', 'Comments can be up to 4,000 characters.', array( 'status' => 400 ) ); }

		global $wpdb;
		$t = gasf_review_tables();

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['comments']} WHERE doc_id = %d", $doc->id ) );
		if ( $count >= GASF_REVIEW_MAX_COMMENTS ) {
			return new WP_Error( 'gasf_review_full', 'This review has reached its comment limit.', array( 'status' => 429 ) );
		}
		$rl   = gasf_review_ip_key( $doc->id );
		$hits = (int) get_transient( $rl );
		if ( $hits >= GASF_REVIEW_RATE ) {
			return new WP_Error( 'gasf_review_rate', 'Too many comments in a short time — wait a few minutes and try again.', array( 'status' => 429 ) );
		}

		$parent = (int) ( $p['parent'] ?? 0 );
		$now    = current_time( 'mysql', true );
		if ( $parent ) {
			// Replies attach to the thread's first comment, carrying its spot.
			$root = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['comments']} WHERE id = %d AND doc_id = %d", $parent, $doc->id ) );
			if ( ! $root ) { return new WP_Error( 'gasf_review_parent', 'That comment was deleted.', array( 'status' => 404 ) ); }
			if ( (int) $root->parent_id ) { $root = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['comments']} WHERE id = %d AND doc_id = %d", (int) $root->parent_id, $doc->id ) ); }
			if ( ! $root ) { return new WP_Error( 'gasf_review_parent', 'That comment was deleted.', array( 'status' => 404 ) ); }
			$ok = $wpdb->insert( $t['comments'], array(
				'doc_id' => (int) $doc->id, 'parent_id' => (int) $root->id, 'num' => 0,
				'page' => (int) $root->page, 'x' => $root->x, 'y' => $root->y,
				'author' => $author, 'body' => $body, 'resolved' => 0, 'created_at' => $now,
			), array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ) );
		} else {
			$page = (int) ( $p['page'] ?? 0 );
			$x    = (float) ( $p['x'] ?? -1 );
			$y    = (float) ( $p['y'] ?? -1 );
			if ( $page < 1 || $page > 65535 || $x < 0 || $x > 1 || $y < 0 || $y > 1 ) {
				return new WP_Error( 'gasf_review_spot', 'Click on the page where the comment belongs.', array( 'status' => 400 ) );
			}
			// Thread numbers are permanent (a deleted #3 leaves a gap rather
			// than renumbering everyone's "see #4"), so take MAX+1 in the
			// INSERT itself instead of reading it first and racing.
			$ok = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$t['comments']} (doc_id, parent_id, num, page, x, y, author, body, resolved, created_at)
				 SELECT %d, 0, COALESCE(MAX(num), 0) + 1, %d, %s, %s, %s, %s, 0, %s FROM {$t['comments']} WHERE doc_id = %d",
				$doc->id, $page, sprintf( '%.6F', $x ), sprintf( '%.6F', $y ), $author, $body, $now, $doc->id
			) );
		}
		if ( ! $ok ) {
			gasf_mec_log( 'pdf-review: insert failed: ' . $wpdb->last_error );
			return new WP_Error( 'gasf_review_db', 'Could not save the comment. Please try again.', array( 'status' => 500 ) );
		}
		set_transient( $rl, $hits + 1, 10 * MINUTE_IN_SECONDS );
		return gasf_review_no_store( array( 'id' => (int) $wpdb->insert_id, 'comments' => gasf_review_comments( $doc->id ) ), 201 );
	}

	function gasf_review_rest_resolve( WP_REST_Request $req ) {
		$doc = gasf_review_rest_doc( $req, true );
		if ( is_wp_error( $doc ) ) { return $doc; }
		$p = (array) $req->get_json_params();
		global $wpdb;
		$t = gasf_review_tables();
		$n = $wpdb->update(
			$t['comments'],
			array( 'resolved' => empty( $p['resolved'] ) ? 0 : 1 ),
			array( 'id' => (int) $req['id'], 'doc_id' => (int) $doc->id, 'parent_id' => 0 ),
			array( '%d' ), array( '%d', '%d', '%d' )
		);
		if ( false === $n ) { return new WP_Error( 'gasf_review_db', 'Could not update the comment.', array( 'status' => 500 ) ); }
		return gasf_review_no_store( array( 'comments' => gasf_review_comments( $doc->id ) ) );
	}

	/* =====================================================================
	 * Admin tab.
	 * =================================================================== */

	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) { gasf_utilities_add_tab( 'pdfreview', 'PDF Review', 'gasf_review_admin', 72 ); }
	} );

	function gasf_review_admin_url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=gasf-utilities&tab=pdfreview' ) );
	}

	function gasf_review_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		if ( ! gasf_review_install() ) {
			echo '<div class="notice notice-error"><p>The PDF Review tables could not be created. Details are in the plugin log.</p></div>';
			return;
		}
		global $wpdb;
		$t = gasf_review_tables();

		/* ---- actions ---- */
		$new = null;
		if ( isset( $_POST['gasf_review_action'] ) && check_admin_referer( 'gasf_review' ) ) {
			$act = sanitize_key( wp_unslash( $_POST['gasf_review_action'] ) );
			$doc = isset( $_POST['doc'] ) ? gasf_review_doc_by_id( (int) $_POST['doc'] ) : null;

			if ( 'upload' === $act ) {
				$r = gasf_review_create( (array) ( $_FILES['gasf_review_pdf'] ?? array() ), (string) wp_unslash( $_POST['title'] ?? '' ) );
				if ( is_wp_error( $r ) ) {
					echo '<div class="notice notice-error"><p>' . wp_kses_post( $r->get_error_message() ) . '</p></div>';
				} else {
					$new = $r;
				}
			} elseif ( $doc && ( 'close' === $act || 'open' === $act ) ) {
				$wpdb->update( $t['docs'], array( 'status' => $act ), array( 'id' => (int) $doc->id ), array( '%s' ), array( '%d' ) );
				echo '<div class="notice notice-success is-dismissible"><p>&ldquo;' . esc_html( $doc->title ) . '&rdquo; is now ' . ( 'open' === $act ? '<strong>open</strong> — the link works again.' : '<strong>closed</strong> — the link no longer works for reviewers. Comments are kept.' ) . '</p></div>';
			} elseif ( $doc && 'delete' === $act ) {
				gasf_review_delete( $doc );
				echo '<div class="notice notice-success is-dismissible"><p>Deleted &ldquo;' . esc_html( $doc->title ) . '&rdquo;, its PDF and all its comments.</p></div>';
			} elseif ( $doc && 'delete_comment' === $act ) {
				gasf_review_delete_comment( $doc->id, (int) ( $_POST['comment'] ?? 0 ) );
				echo '<div class="notice notice-success is-dismissible"><p>Comment deleted.</p></div>';
			}
		}

		gasf_utilities_doc_panel( array(
			'what'   => 'Share a PDF for review. Upload it here and you get one link; anyone you send it to can open it in a browser — no account — read it at full resolution, zoom, page through it, and <strong>click anywhere on a page to pin a comment there</strong>. Everyone with the link sees and can reply to everyone&rsquo;s comments, and a side panel lists every comment with its page number. The PDF stays on this server; it is not added to the Media Library and is not public without the link.',
			'needs'  => array( 'A PDF up to ' . size_format( gasf_review_max_bytes() ) . ' (the smaller of this tool&rsquo;s 50 MB cap and the server&rsquo;s upload limit).', 'Reviewers need a current browser. The PDF renderer (PDF.js) loads from cdn.jsdelivr.net.' ),
			'fields' => array(
				'PDF / Title'    => 'The title is what reviewers see at the top of the page; leave it blank to use the file name.',
				'Link'           => 'The only way in. It is long and random, so it can&rsquo;t be guessed — but anyone you forward it to can comment. <strong>Copy</strong> puts it on your clipboard.',
				'Close / Reopen' => '<strong>Close</strong> makes the link stop working for reviewers (they see &ldquo;This review has been closed&rdquo;). Nothing is deleted; you can still open it yourself while signed in, and <strong>Reopen</strong> brings the link back.',
				'Comments'       => 'Every comment and reply with its page number. Reviewers can mark a thread <em>resolved</em> (it hides behind &ldquo;Show resolved&rdquo;); only you can delete — here, or with the Delete button that admins see in the review page itself.',
				'Delete'         => 'Removes the PDF, the link and every comment, permanently.',
			),
			'notes'  => 'Reviewers type their name once; their browser remembers it. Names are not verified — anyone with the link can type any name. Limits against a forwarded link being abused: 60 comments per 10 minutes from one address, 2,000 per review.',
		) );

		if ( $new ) {
			$link = gasf_review_link( $new );
			echo '<div class="notice notice-success"><p><strong>Review created.</strong> Send this link to your reviewers:</p>'
				. '<p><input type="text" readonly class="large-text code" value="' . esc_attr( $link ) . '" onclick="this.select()" style="max-width:720px"> '
				. '<button type="button" class="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent=\'Copied\'">Copy</button> '
				. '<a class="button" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">Open</a></p></div>';
		}

		/* ---- single review: comments ---- */
		$view = isset( $_GET['view'] ) ? gasf_review_doc_by_id( (int) $_GET['view'] ) : null;
		if ( $view ) {
			gasf_review_admin_comments( $view );
			return;
		}

		/* ---- upload form ---- */
		?>
		<h2>PDF Review</h2>
		<form method="post" enctype="multipart/form-data" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:4px 16px 12px;max-width:960px">
			<?php wp_nonce_field( 'gasf_review' ); ?>
			<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo (int) gasf_review_max_bytes(); ?>">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="gasf_review_pdf">PDF</label></th>
					<td><input type="file" name="gasf_review_pdf" id="gasf_review_pdf" accept="application/pdf,.pdf" required>
					<p class="description">Up to <?php echo esc_html( size_format( gasf_review_max_bytes() ) ); ?>.</p></td></tr>
				<tr><th scope="row"><label for="gasf_review_title">Title</label></th>
					<td><input type="text" name="title" id="gasf_review_title" class="regular-text" maxlength="200" placeholder="Defaults to the file name"></td></tr>
			</table>
			<p style="margin:0"><button name="gasf_review_action" value="upload" class="button button-primary">Upload &amp; create link</button></p>
		</form>
		<?php

		/* ---- list ---- */
		$docs = $wpdb->get_results(
			"SELECT d.*,
				( SELECT COUNT(*) FROM {$t['comments']} c WHERE c.doc_id = d.id ) AS n_all,
				( SELECT COUNT(*) FROM {$t['comments']} c WHERE c.doc_id = d.id AND c.parent_id = 0 AND c.resolved = 0 ) AS n_open
			 FROM {$t['docs']} d ORDER BY d.id DESC"
		);
		echo '<h3 class="title">Reviews</h3>';
		if ( ! $docs ) {
			echo '<p>No reviews yet — upload a PDF above.</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:1200px"><thead><tr><th>Title</th><th>Link</th><th style="width:150px">Comments</th><th style="width:90px">Status</th><th style="width:120px">Created</th><th style="width:220px">Actions</th></tr></thead><tbody>';
		foreach ( $docs as $d ) {
			$link = gasf_review_link( $d );
			$open = 'open' === $d->status;
			echo '<tr>';
			echo '<td><strong>' . esc_html( $d->title ) . '</strong><br><span style="color:#646970;font-size:12px">' . esc_html( $d->orig_name ) . ' · ' . esc_html( size_format( (int) $d->bytes ) ) . '</span></td>';
			echo '<td><input type="text" readonly value="' . esc_attr( $link ) . '" onclick="this.select()" style="width:100%;font-size:11px" class="code"><br>'
				. '<button type="button" class="button button-small" onclick="navigator.clipboard.writeText(this.parentNode.querySelector(\'input\').value);this.textContent=\'Copied\'">Copy</button> '
				. '<a class="button button-small" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">Open</a></td>';
			echo '<td><a href="' . esc_url( gasf_review_admin_url( array( 'view' => (int) $d->id ) ) ) . '">' . (int) $d->n_all . ' total</a><br><span style="color:#646970;font-size:12px">' . (int) $d->n_open . ' open thread' . ( 1 === (int) $d->n_open ? '' : 's' ) . '</span></td>';
			echo '<td>' . ( $open ? '<span style="color:#1a7f37;font-weight:600">Open</span>' : '<span style="color:#b32d2e;font-weight:600">Closed</span>' ) . '</td>';
			echo '<td>' . esc_html( get_date_from_gmt( $d->created_at, 'M j, Y' ) ) . '</td>';
			echo '<td><form method="post" style="display:inline">';
			wp_nonce_field( 'gasf_review' );
			echo '<input type="hidden" name="doc" value="' . (int) $d->id . '">'
				. '<button class="button button-small" name="gasf_review_action" value="' . ( $open ? 'close' : 'open' ) . '">' . ( $open ? 'Close' : 'Reopen' ) . '</button> '
				. '<a class="button button-small" href="' . esc_url( gasf_review_admin_url( array( 'view' => (int) $d->id ) ) ) . '">Comments</a> '
				. '<button class="button button-small button-link-delete" name="gasf_review_action" value="delete" onclick="return confirm(\'Delete this review, its PDF and all ' . (int) $d->n_all . ' comments? This cannot be undone.\')">Delete</button>'
				. '</form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/** Every comment on one review, threads in page order, replies under them. */
	function gasf_review_admin_comments( $doc ) {
		$all   = gasf_review_comments( $doc->id );
		$roots = array_values( array_filter( $all, function ( $c ) { return 0 === $c['parent']; } ) );
		usort( $roots, function ( $a, $b ) { return array( $a['page'], $a['y'], $a['num'] ) <=> array( $b['page'], $b['y'], $b['num'] ); } );
		$kids = array();
		foreach ( $all as $c ) { if ( $c['parent'] ) { $kids[ $c['parent'] ][] = $c; } }
		$when = function ( $iso ) { return esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $iso ) ), 'M j, g:i a' ) ); };
		$del  = function ( $c ) use ( $doc ) {
			ob_start();
			echo '<form method="post" style="display:inline">';
			wp_nonce_field( 'gasf_review' );
			echo '<input type="hidden" name="doc" value="' . (int) $doc->id . '"><input type="hidden" name="comment" value="' . (int) $c['id'] . '">'
				. '<button class="button-link button-link-delete" name="gasf_review_action" value="delete_comment" onclick="return confirm(\'' . ( $c['parent'] ? 'Delete this reply?' : 'Delete this comment and its replies?' ) . '\')">Delete</button></form>';
			return ob_get_clean();
		};

		echo '<p><a href="' . esc_url( gasf_review_admin_url() ) . '">&larr; All reviews</a></p>';
		echo '<h2>' . esc_html( $doc->title ) . ' <span style="font-weight:400;color:#646970;font-size:14px">— ' . count( $roots ) . ' thread' . ( 1 === count( $roots ) ? '' : 's' ) . ', ' . count( $all ) . ' comments · ' . ( 'open' === $doc->status ? 'open' : 'closed' ) . '</span></h2>';
		echo '<p><a class="button" href="' . esc_url( gasf_review_link( $doc ) ) . '" target="_blank" rel="noopener">Open the review page</a></p>';
		if ( ! $roots ) { echo '<p>No comments yet.</p>'; return; }

		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:50px">#</th><th style="width:60px">Page</th><th style="width:150px">Who</th><th>Comment</th><th style="width:120px">When</th><th style="width:80px">Status</th><th style="width:60px"></th></tr></thead><tbody>';
		foreach ( $roots as $r ) {
			echo '<tr><td><strong>' . (int) $r['num'] . '</strong></td><td>' . (int) $r['page'] . '</td><td>' . esc_html( $r['author'] ) . '</td>'
				. '<td style="white-space:pre-wrap">' . esc_html( $r['body'] ) . '</td><td>' . $when( $r['created'] ) . '</td>'
				. '<td>' . ( $r['resolved'] ? '<span style="color:#646970">Resolved</span>' : 'Open' ) . '</td><td>' . $del( $r ) . '</td></tr>';
			foreach ( $kids[ $r['id'] ] ?? array() as $k ) {
				echo '<tr><td></td><td></td><td style="padding-left:20px">&#8627; ' . esc_html( $k['author'] ) . '</td>'
					. '<td style="white-space:pre-wrap">' . esc_html( $k['body'] ) . '</td><td>' . $when( $k['created'] ) . '</td><td></td><td>' . $del( $k ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';
	}
}
