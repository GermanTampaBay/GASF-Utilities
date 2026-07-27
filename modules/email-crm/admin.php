<?php
/**
 * Email CRM — admin tab (modules/email-crm/admin.php)
 *
 * Configuration, connection test, manual sync, and the account approval queue.
 *
 * Secrets are write-only in this form: they are never rendered back into the
 * page, only reported as set or not set. A blank field means "leave alone", so
 * saving the form to change the signature cannot silently wipe the Graph
 * client secret.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'admin_menu', function () {
	if ( function_exists( 'gasf_utilities_add_tab' ) ) {
		gasf_utilities_add_tab( 'emailcrm', 'Email CRM', 'gasf_crm_admin_tab', 60 );
	}
} );

function gasf_crm_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }

	$notice = '';
	$diag   = null;

	if ( isset( $_POST['gasf_crm_action'] ) && check_admin_referer( 'gasf_crm' ) ) {
		$act = sanitize_text_field( wp_unslash( $_POST['gasf_crm_action'] ) );

		if ( 'save' === $act ) {
			$cfg = gasf_crm_cfg();

			$cfg['mailbox']        = sanitize_email( wp_unslash( $_POST['mailbox'] ?? $cfg['mailbox'] ) );
			$cfg['tenant_id']      = sanitize_text_field( wp_unslash( $_POST['tenant_id'] ?? '' ) );
			$cfg['client_id']      = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
			$cfg['google_id']      = sanitize_text_field( wp_unslash( $_POST['google_id'] ?? '' ) );
			$cfg['ms_id']          = sanitize_text_field( wp_unslash( $_POST['ms_id'] ?? '' ) );
			$cfg['signature_org']  = sanitize_text_field( wp_unslash( $_POST['signature_org'] ?? '' ) );
			$cfg['secret_expiry']  = sanitize_text_field( wp_unslash( $_POST['secret_expiry'] ?? '' ) );
			$cfg['board_address']  = sanitize_email( wp_unslash( $_POST['board_address'] ?? '' ) );
			$cfg['notify_channel'] = sanitize_key( wp_unslash( $_POST['notify_channel'] ?? 'email' ) );
			$cfg['notify_extra']   = sanitize_text_field( wp_unslash( $_POST['notify_extra'] ?? '' ) );

			// Blank = keep. Secrets are not echoed back, so an empty box means the
			// admin didn't retype it, not that they want it cleared.
			foreach ( array( 'client_secret', 'google_secret', 'ms_secret' ) as $f ) {
				$v = trim( (string) wp_unslash( $_POST[ $f ] ?? '' ) );
				if ( '' !== $v ) { $cfg[ $f ] = $v; }
				if ( ! empty( $_POST[ $f . '_clear' ] ) ) { $cfg[ $f ] = ''; }
			}

			gasf_crm_save_cfg( $cfg );
			delete_transient( 'gasf_crm_graph_token' ); // credentials may have changed
			$notice = '<div class="notice notice-success"><p>Saved.</p></div>';

			// Catch the GUID-in-the-secret-field mistake at save time rather than
			// letting it surface later as an opaque AADSTS7000215 from Azure.
			foreach ( array(
				'client_secret' => 'Graph client secret',
				'google_secret' => 'Google secret',
				'ms_secret'     => 'Microsoft secret',
			) as $f => $label ) {
				if ( preg_match( '/^[0-9a-fA-F-]{36}$/', (string) $cfg[ $f ] ) ) {
					$notice .= '<div class="notice notice-error"><p><strong>' . esc_html( $label )
						. ':</strong> that is a GUID, so it is the Secret <em>ID</em> rather than the Secret <em>Value</em>. Authentication will fail with AADSTS7000215 until it is replaced.</p></div>';
				}
			}
		}

		if ( 'test' === $act ) {
			$diag = gasf_crm_graph_diagnostics();
		}

		if ( 'notify' === $act ) {
			$n      = gasf_crm_flush_notifications( true );
			$notice = $n
				? '<div class="notice notice-success"><p>' . esc_html( sprintf( 'Summary sent to %d recipient(s) covering %d thread(s).', count( gasf_crm_notify_recipients() ), $n ) ) . '</p></div>'
				: '<div class="notice notice-warning"><p>Nothing queued to announce. New unanswered mail queues automatically; this button skips the once-an-hour wait.</p></div>';
		}

		if ( 'sync' === $act ) {
			$r    = gasf_crm_sync();
			$body = sprintf( '%d new message(s), %d reopened, %d queued, %d announced.', $r['new'], $r['reopened'], $r['queued'], $r['notified'] );
			if ( $r['errors'] ) {
				$notice = '<div class="notice notice-error"><p>' . esc_html( $body . ' Errors: ' . implode( '; ', $r['errors'] ) ) . '</p></div>';
			} else {
				$notice = '<div class="notice notice-success"><p>' . esc_html( $body ) . '</p></div>';
			}
		}

		if ( 'corpus' === $act ) {
			$n = mb_strlen( gasf_crm_corpus( true ) );
			$notice = '<div class="notice notice-success"><p>' . esc_html( 'Corpus rebuilt — ' . number_format( $n ) . ' characters of site content.' ) . '</p></div>';
		}

		if ( 'attach_add' === $act ) {
			$row = isset( $_FILES['file'] )
				? gasf_crm_attach_store( $_FILES['file'], true, (string) wp_unslash( $_POST['label'] ?? '' ) )
				: new WP_Error( 'gasf_crm_nofile', 'No file was received.' );
			$notice = is_wp_error( $row )
				? '<div class="notice notice-error"><p>' . esc_html( $row->get_error_message() ) . '</p></div>'
				: '<div class="notice notice-success"><p>' . esc_html( $row['original_name'] . ' added to the library.' ) . '</p></div>';
		}

		if ( 'attach_delete' === $act ) {
			gasf_crm_attach_delete( (int) ( $_POST['attach_id'] ?? 0 ) );
			$notice = '<div class="notice notice-success"><p>Removed.</p></div>';
		}

		if ( 'user' === $act ) {
			$uid = (int) ( $_POST['user_id'] ?? 0 );
			$st  = sanitize_key( wp_unslash( $_POST['user_status'] ?? '' ) );
			if ( $uid && gasf_crm_set_user_status( $uid, $st ) ) {
				$notice = '<div class="notice notice-success"><p>Account updated.</p></div>';
			}
		}
	}

	$cfg = gasf_crm_cfg();
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput

	$secret = gasf_crm_secret_status();
	if ( in_array( $secret['state'], array( 'warn', 'expired', 'unknown' ), true ) ) {
		$class = ( 'unknown' === $secret['state'] ) ? 'notice-warning' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . '" style="padding:12px 14px"><h3 style="margin-top:0">';

		if ( 'expired' === $secret['state'] ) {
			echo 'The Graph client secret expired ' . esc_html( human_time_diff( strtotime( $secret['date'] ) ) ) . ' ago';
		} elseif ( 'warn' === $secret['state'] ) {
			echo 'The Graph client secret expires in ' . (int) $secret['days'] . ' days (' . esc_html( $secret['date'] ) . ')';
		} else {
			echo 'Nobody has recorded when the Graph client secret expires';
		}
		echo '</h3>';

		echo '<p>' . ( 'unknown' === $secret['state']
			? 'Set the expiry date below. Client secrets last 24 months and Entra gives no warning: when this one lapses, mail simply stops arriving and the inbox looks like a quiet week.'
			: 'When it lapses, mail stops arriving and the inbox looks like a quiet week. Renew it:' ) . '</p>';

		if ( 'unknown' !== $secret['state'] ) {
			echo gasf_crm_secret_renewal_steps(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}

	/**
	 * Secret status indicator.
	 *
	 * A plain "set" is not enough feedback: Azure's Certificates & secrets table
	 * puts the Secret ID next to the Value, and once you leave that page the
	 * Value is permanently masked while the Secret ID stays copyable — so the
	 * GUID is the one that ends up pasted here, and the only symptom is
	 * AADSTS7000215 from a completely different screen. Call it out at the point
	 * of entry instead. Every real secret contains a '~'; no GUID does.
	 */
	$set = function ( $v ) {
		if ( '' === $v ) { return '<span style="color:#d63638">not set</span>'; }
		if ( preg_match( '/^[0-9a-fA-F-]{36}$/', $v ) ) {
			return '<span style="color:#d63638">&#9888; this is a GUID &mdash; you pasted the <strong>Secret ID</strong>, not the <strong>Value</strong></span>';
		}
		return '<span style="color:#2c7a3f">&#10003; set (' . strlen( $v ) . ' chars)</span>';
	};
	?>
	<h2>Email CRM</h2>
	<p class="description">
		Shared inbox for <code><?php echo esc_html( $cfg['mailbox'] ); ?></code>, served at
		<a href="<?php echo esc_url( home_url( '/email' ) ); ?>"><?php echo esc_html( home_url( '/email' ) ); ?></a>
		(unlinked, <code>noindex</code>). Design contract: <code>docs/EMAIL-CRM-SPEC.md</code>.
	</p>

	<form method="post">
		<?php wp_nonce_field( 'gasf_crm' ); ?>
		<input type="hidden" name="gasf_crm_action" value="save">

		<h3>Mailbox &amp; Graph</h3>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Shared mailbox</th>
				<td><input type="email" class="regular-text" name="mailbox" value="<?php echo esc_attr( $cfg['mailbox'] ); ?>">
				<p class="description">Must be a shared mailbox, not an alias on a person's mailbox.</p></td></tr>
			<tr><th scope="row">Tenant ID</th>
				<td><input type="text" class="regular-text code" name="tenant_id" value="<?php echo esc_attr( $cfg['tenant_id'] ); ?>"></td></tr>
			<tr><th scope="row">Application (client) ID</th>
				<td><input type="text" class="regular-text code" name="client_id" value="<?php echo esc_attr( $cfg['client_id'] ); ?>">
					<p class="description">App registration &rarr; Overview &rarr; <strong>Application (client) ID</strong>.</p></td></tr>
			<tr><th scope="row">Client secret <em>Value</em></th>
				<td><input type="password" class="regular-text" name="client_secret" autocomplete="new-password" placeholder="leave blank to keep">
					<?php echo $set( $cfg['client_secret'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<label style="margin-left:12px"><input type="checkbox" name="client_secret_clear" value="1"> clear</label>
					<p class="description">Certificates &amp; secrets &rarr; the <strong>Value</strong> column, <em>not</em> Secret ID. Roughly 40 characters and always contains a <code>~</code>; a 36-character GUID is the wrong column. The Value is only readable at the moment you create the secret &mdash; if you have navigated away, make a new one.</p>
					<p class="description">Application permissions <code>Mail.ReadWrite</code> + <code>Mail.Send</code>, admin-consented, and restricted to this one mailbox by an Exchange Application Access Policy.</p></td></tr>
			<tr><th scope="row">Secret expires on</th>
				<td><input type="date" name="secret_expiry" value="<?php echo esc_attr( $cfg['secret_expiry'] ); ?>">
					<p class="description">The date the client secret above stops working &mdash; 24 months after you created it. Entra never warns you and will not show you this date afterwards, so it is recorded here by hand. A warning appears on every admin page from <?php echo (int) GASF_CRM_SECRET_WARN_DAYS; ?> days out. If this is blank, nothing will warn you and mail will simply stop arriving one day with no explanation.</p></td></tr>
		</table>

		<h3>Sign-in</h3>
		<p class="description">Redirect URIs:
			<code><?php echo esc_html( home_url( '/email/auth/google/callback' ) ); ?></code> and
			<code><?php echo esc_html( home_url( '/email/auth/microsoft/callback' ) ); ?></code>
		</p>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Google client ID</th>
				<td><input type="text" class="regular-text code" name="google_id" value="<?php echo esc_attr( $cfg['google_id'] ); ?>"></td></tr>
			<tr><th scope="row">Google secret</th>
				<td><input type="password" class="regular-text" name="google_secret" autocomplete="new-password" placeholder="leave blank to keep">
					<?php echo $set( $cfg['google_secret'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<label style="margin-left:12px"><input type="checkbox" name="google_secret_clear" value="1"> clear</label></td></tr>
			<tr><th scope="row">Microsoft client ID</th>
				<td><input type="text" class="regular-text code" name="ms_id" value="<?php echo esc_attr( $cfg['ms_id'] ); ?>">
					<p class="description">A separate registration from the Graph app above — this one is multi-tenant + personal accounts.</p></td></tr>
			<tr><th scope="row">Microsoft secret</th>
				<td><input type="password" class="regular-text" name="ms_secret" autocomplete="new-password" placeholder="leave blank to keep">
					<?php echo $set( $cfg['ms_secret'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<label style="margin-left:12px"><input type="checkbox" name="ms_secret_clear" value="1"> clear</label></td></tr>
		</table>

		<h3>Replies &amp; notifications</h3>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Signature organisation</th>
				<td><input type="text" class="large-text" name="signature_org" value="<?php echo esc_attr( $cfg['signature_org'] ); ?>">
					<p class="description">Appended under the replying volunteer's name. Replies always send as the shared mailbox.</p></td></tr>
			<tr><th scope="row">Board address</th>
				<td><input type="email" class="regular-text" name="board_address" value="<?php echo esc_attr( $cfg['board_address'] ); ?>">
					<p class="description">Destination for the one-click <em>Forward to Board</em> button on <code>/email</code>. It takes two deliberate clicks to fire. Leave blank to remove the button.</p></td></tr>
			<tr><th scope="row">Notify via</th>
				<td><select name="notify_channel">
					<?php foreach ( array( 'email' => 'Email', 'all' => 'Every registered channel' ) as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cfg['notify_channel'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">WhatsApp needs a WhatsApp Business Account, a dedicated number, Meta verification and an approved template. Register a channel on <code>gasf_crm_notify_channels</code> and it appears here.</p></td></tr>
			<tr><th scope="row">Also notify</th>
				<td><input type="text" class="large-text" name="notify_extra" value="<?php echo esc_attr( $cfg['notify_extra'] ); ?>" placeholder="you@germantampabay.com, someone-else@example.com">
					<p class="description">Comma-separated. Approved volunteers are notified automatically, but only once they have signed in at <code>/email</code> — an administrator is approved by default and never has to, so without an address here the person running this is the one person never told about new mail.</p></td></tr>
		</table>

		<?php submit_button( 'Save' ); ?>
	</form>

	<h3>Maintenance</h3>
	<p class="description">These act on <strong>saved</strong> settings &mdash; they are separate forms from the one above, so save any credential change before testing it.</p>
	<p>
		<?php
		foreach ( array(
			'test'   => 'Check Graph status',
			'sync'   => 'Sync now',
			'notify' => 'Send queued summary now',
			'corpus' => 'Rebuild AI corpus',
		) as $act => $label ) : ?>
		<form method="post" style="display:inline-block;margin-right:8px">
			<?php wp_nonce_field( 'gasf_crm' ); ?>
			<input type="hidden" name="gasf_crm_action" value="<?php echo esc_attr( $act ); ?>">
			<button class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php endforeach; ?>
	</p>
	<?php if ( $diag ) { gasf_crm_render_diagnostics( $diag ); } ?>
	<p class="description">
		Last sync: <?php echo $cfg['last_sync']
			? esc_html( human_time_diff( (int) $cfg['last_sync'] ) . ' ago' )
			: 'never'; ?>.
		Hourly WP-Cron is the fallback; a system cron running
		<code>wp gasf-crm sync</code> is the reliable path on a low-traffic site.
	</p>
	<?php
	$health = gasf_crm_health_state();
	printf(
		'<p class="description">Mailbox health: <strong style="color:%s">%s</strong>. %s</p>',
		esc_attr( 'ok' === $health['state'] ? '#2c7a3f' : '#d63638' ),
		esc_html( 'ok' === $health['state'] ? 'reachable' : strtoupper( $health['state'] ) ),
		'ok' === $health['state']
			? esc_html( $health['last_success']
				? 'Last reached ' . human_time_diff( (int) $health['last_success'] ) . ' ago.'
				: 'No sync has run yet.' )
			: esc_html( sprintf(
				'Failing for %s across %d attempt(s). After %d hours a banner appears on /email and the administrators are emailed. Last error: %s',
				human_time_diff( (int) $health['first_fail'] ),
				(int) $health['fail_count'],
				(int) round( GASF_CRM_HEALTH_ALERT_AFTER / HOUR_IN_SECONDS ),
				$health['last_error']
			) )
	);
	printf(
		'<p class="description">Outage alerts go to every WordPress administrator: <strong>%s</strong>. Sent through WordPress mail so an alarm about Graph does not travel over Graph &mdash; with a Graph copy alongside it, because WordPress mail from this server is currently quarantined by the domain\'s own SPF record. Install an SMTP plugin and the duplicate can go.</p>',
		esc_html( implode( ', ', gasf_crm_health_admins() ) )
	);

	$queued    = count( (array) get_option( 'gasf_crm_notify_queue', array() ) );
	$last_note = (int) get_option( 'gasf_crm_notify_last', 0 );
	?>
	<p class="description">
		Notifications: <strong><?php echo (int) $queued; ?></strong> thread(s) waiting to be announced;
		last summary <?php echo $last_note ? esc_html( human_time_diff( $last_note ) . ' ago' ) : 'never sent'; ?>.
		At most one summary per hour however much arrives, so a spam run produces one message rather than eighty.
		Delivered via Graph as <code><?php echo esc_html( $cfg['mailbox'] ); ?></code> &mdash; this domain's SPF record
		authorises Microsoft only, so anything sent by WordPress itself is quarantined before it arrives.
		Recipients: <?php
			$r = gasf_crm_notify_recipients();
			echo $r ? esc_html( implode( ', ', $r ) ) : '<strong>nobody configured</strong>';
		?>.
	</p>

	<h3>Attachment library</h3>
	<p class="description">Documents volunteers can attach to a reply without hunting for the file &mdash; the membership form being the obvious one. Anything uploaded from <code>/email</code> with &ldquo;keep this for future use&rdquo; ticked also lands here. Limit <?php echo esc_html( size_format( GASF_CRM_ATTACH_MAX ) ); ?> per file.</p>

	<form method="post" enctype="multipart/form-data" style="margin:10px 0">
		<?php wp_nonce_field( 'gasf_crm' ); ?>
		<input type="hidden" name="gasf_crm_action" value="attach_add">
		<input type="file" name="file" required>
		<input type="text" name="label" class="regular-text" placeholder="Optional label, e.g. 2026 Membership Form">
		<button class="button">Add to library</button>
	</form>

	<?php
	$library = gasf_crm_attach_library();
	if ( ! $library ) {
		echo '<p class="description">Empty.</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>File</th><th>Label</th><th>Size</th><th>Added</th><th></th></tr></thead><tbody>';
		foreach ( $library as $a ) {
			echo '<tr><td><code>' . esc_html( $a['original_name'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $a['label'] ) . '</td>';
			echo '<td>' . esc_html( size_format( (int) $a['size'] ) ) . '</td>';
			echo '<td>' . esc_html( human_time_diff( strtotime( $a['uploaded_at'] . ' UTC' ) ) . ' ago' ) . '</td><td>';
			echo '<form method="post" onsubmit="return confirm(\'Remove this document from the library?\')" style="display:inline">';
			wp_nonce_field( 'gasf_crm' );
			echo '<input type="hidden" name="gasf_crm_action" value="attach_delete">';
			echo '<input type="hidden" name="attach_id" value="' . (int) $a['id'] . '">';
			echo '<button class="button button-small">Remove</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}
	?>

	<h3>Address book</h3>
	<?php
	$contacts = gasf_crm_contacts( '', 50 );
	if ( ! $contacts ) {
		echo '<p class="description">Empty. It fills itself in as mail is received, replied to and forwarded — there is nothing to maintain by hand.</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>Email</th><th>Name</th><th>Sent to</th><th>Received from</th><th>Last seen</th></tr></thead><tbody>';
		foreach ( $contacts as $c ) {
			echo '<tr><td><code>' . esc_html( $c['email'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $c['name'] ) . '</td>';
			echo '<td>' . (int) $c['sent_count'] . '</td>';
			echo '<td>' . (int) $c['recv_count'] . '</td>';
			echo '<td>' . esc_html( $c['last_seen'] ? human_time_diff( strtotime( $c['last_seen'] . ' UTC' ) ) . ' ago' : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Newest 50. Every address the club has written to or heard from; the forward box on <code>/email</code> autocompletes from this list.</p>';
	}
	?>

	<h3>Accounts</h3>
	<?php
	$users = gasf_crm_all_users();
	if ( ! $users ) {
		echo '<p class="description">Nobody has signed in yet.</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Provider</th><th>Status</th><th>Action</th></tr></thead><tbody>';
		foreach ( $users as $u ) {
			$st = get_user_meta( $u->ID, 'gasf_crm_status', true );
			$colour = 'approved' === $st ? '#2c7a3f' : ( 'denied' === $st ? '#d63638' : '#dba617' );
			echo '<tr><td>' . esc_html( get_user_meta( $u->ID, 'gasf_crm_name', true ) ?: $u->display_name ) . '</td>';
			echo '<td>' . esc_html( get_user_meta( $u->ID, 'gasf_crm_email', true ) ) . '</td>';
			echo '<td>' . esc_html( get_user_meta( $u->ID, 'gasf_crm_provider', true ) ) . '</td>';
			echo '<td><strong style="color:' . esc_attr( $colour ) . '">' . esc_html( $st ?: 'pending' ) . '</strong></td><td>';
			foreach ( array( 'approved' => 'Approve', 'denied' => 'Deny' ) as $to => $label ) {
				if ( $st === $to ) { continue; }
				echo '<form method="post" style="display:inline-block;margin-right:6px">';
				wp_nonce_field( 'gasf_crm' );
				echo '<input type="hidden" name="gasf_crm_action" value="user">';
				echo '<input type="hidden" name="user_id" value="' . (int) $u->ID . '">';
				echo '<input type="hidden" name="user_status" value="' . esc_attr( $to ) . '">';
				echo '<button class="button button-small">' . esc_html( $label ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Accounts are identified by provider + subject claim, not email address — the same person signing in with Google and with Microsoft appears twice and needs approving twice.</p>';
	}
}

/**
 * Render the layered Graph status panel.
 *
 * Ordered by dependency: credentials, then consent, then mailbox reach. The
 * first red row is the one to fix — anything below it is untrustworthy, because
 * each layer needs the one above it. This ordering is the whole point of the
 * panel: Graph reports all three failures with overlapping 401/403 messages, so
 * without it you cannot tell a bad secret from missing consent from a mailbox
 * the access policy excludes.
 */
function gasf_crm_render_diagnostics( array $d ) {
	$links = gasf_crm_entra_links();
	$ok    = '<span style="color:#2c7a3f;font-weight:700">&#10003;</span> ';
	$bad   = '<span style="color:#d63638;font-weight:700">&#10007;</span> ';

	$row = function ( $label, $state, $detail, $fix = '' ) use ( $ok, $bad ) {
		echo '<tr><td style="width:170px"><strong>' . esc_html( $label ) . '</strong></td><td>'
			. ( $state ? $ok : $bad ) . $detail // phpcs:ignore WordPress.Security.EscapeOutput
			. ( $fix ? '<div class="description" style="margin-top:4px">' . $fix . '</div>' : '' ) // phpcs:ignore WordPress.Security.EscapeOutput
			. '</td></tr>';
	};

	echo '<table class="widefat striped" style="max-width:900px;margin:12px 0"><tbody>';

	if ( ! $d['configured'] ) {
		$row( 'Credentials', false, 'Tenant ID, client ID or client secret is missing.' );
		echo '</tbody></table>';
		return;
	}

	// 1 — credentials. A token proves the secret is the Value, not the Secret ID.
	if ( $d['token'] ) {
		$row( 'Credentials', true, 'Token issued'
			. ( null !== $d['expires_in'] ? ' — valid for ' . (int) round( $d['expires_in'] / 60 ) . ' more minutes' : '' ) . '.' );
	} else {
		$row( 'Credentials', false, esc_html( $d['token_error'] ),
			'<a href="' . esc_url( $links['secrets'] ) . '" target="_blank" rel="noopener">Certificates &amp; secrets</a> — copy the <strong>Value</strong> column, not Secret ID.' );
		echo '</tbody></table>';
		return; // nothing below this can be assessed without a token
	}

	// 2 — consent. Invisible in every API response: a role-less token is issued
	// without complaint and only the mail call fails, with wording that points
	// somewhere else entirely.
	$missing = array_diff( gasf_crm_required_roles(), $d['roles'] );
	if ( $missing ) {
		$row( 'Admin consent', false,
			'Token carries no application role for: <code>' . esc_html( implode( '</code>, <code>', $missing ) ) . '</code>'
			. ( $d['roles'] ? ' (present: <code>' . esc_html( implode( '</code>, <code>', $d['roles'] ) ) . '</code>)' : '' ),
			'<a href="' . esc_url( $links['permissions'] ) . '" target="_blank" rel="noopener">API permissions</a> &rarr; <strong>Grant admin consent</strong>. '
			. 'The <em>Status</em> column must read "Granted" — the "Admin consent required" column saying Yes is a different thing and does not mean it was granted.' );
	} else {
		$row( 'Admin consent', true, 'Granted: <code>' . esc_html( implode( '</code>, <code>', $d['roles'] ) ) . '</code>' );
	}

	// 3 — mailbox reach, which is where the Application Access Policy shows up.
	if ( true === $d['reach'] ) {
		$row( 'Mailbox access', true, '<code>' . esc_html( $d['mailbox'] ) . '</code> — Inbox holds '
			. (int) $d['counts']['total'] . ' message(s), ' . (int) $d['counts']['unread'] . ' unread.' );
	} elseif ( false === $d['reach'] ) {
		$row( 'Mailbox access', false, esc_html( $d['reach_error'] ),
			'If consent above is green, this is the Application Access Policy. Confirm the mailbox is a member of the scope group, then: '
			. '<code>Test-ApplicationAccessPolicy -Identity ' . esc_html( $d['mailbox'] ) . ' -AppId ' . esc_html( gasf_crm_cfg()['client_id'] ) . '</code>' );
	}

	echo '</tbody></table>';
}

/**
 * Site-wide warning that the client secret is running out.
 *
 * On every admin page, not just this tab. Someone who never opens the Email CRM
 * screen is precisely the person who will not find out until the mail has been
 * silently missing for a fortnight — and by then the club has ignored a stack
 * of enquiries without knowing it.
 */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) { return; }

	$s = gasf_crm_secret_status();
	if ( ! in_array( $s['state'], array( 'warn', 'expired' ), true ) ) { return; }

	// Suppressed on the CRM tab itself, where the full box with renewal steps is
	// already on screen and a second copy would only be noise.
	// phpcs:ignore WordPress.Security.NonceVerification -- read-only page check
	if ( isset( $_GET['tab'] ) && 'emailcrm' === $_GET['tab'] ) { return; }

	$what = ( 'expired' === $s['state'] )
		? 'the Microsoft client secret has expired'
		: sprintf( 'the Microsoft client secret expires in %d days (%s)', (int) $s['days'], $s['date'] );

	echo '<div class="notice notice-error"><p><strong>Email CRM:</strong> '
		. esc_html( $what ) . '. <a href="' . esc_url( admin_url( 'admin.php?page=gasf-utilities&tab=emailcrm' ) ) . '">Renew it</a> '
		. '&mdash; once it lapses, mail to ' . esc_html( gasf_crm_cfg()['mailbox'] )
		. ' silently stops reaching the club inbox.</p></div>';
} );
