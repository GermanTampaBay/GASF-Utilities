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
			$cfg['notify_channel'] = sanitize_key( wp_unslash( $_POST['notify_channel'] ?? 'email' ) );

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
			$r = gasf_crm_graph_test();
			$notice = is_wp_error( $r )
				? '<div class="notice notice-error"><p>' . esc_html( $r->get_error_message() ) . '</p></div>'
				: '<div class="notice notice-success"><p>Connected to <strong>'
					. esc_html( $r['displayName'] ?? '?' ) . '</strong> (' . esc_html( $r['mail'] ?? '?' ) . ').</p></div>';
		}

		if ( 'sync' === $act ) {
			$r    = gasf_crm_sync();
			$body = sprintf( '%d new message(s), %d thread(s) reopened, %d notified.', $r['new'], $r['reopened'], $r['notified'] );
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
			<tr><th scope="row">Notify via</th>
				<td><select name="notify_channel">
					<?php foreach ( array( 'email' => 'Email', 'all' => 'Every registered channel' ) as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cfg['notify_channel'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">WhatsApp needs a WhatsApp Business Account, a dedicated number, Meta verification and an approved template. Register a channel on <code>gasf_crm_notify_channels</code> and it appears here.</p></td></tr>
		</table>

		<?php submit_button( 'Save' ); ?>
	</form>

	<h3>Maintenance</h3>
	<p class="description">These act on <strong>saved</strong> settings &mdash; they are separate forms from the one above, so save any credential change before testing it.</p>
	<p>
		<?php
		foreach ( array(
			'test'   => 'Test Graph connection',
			'sync'   => 'Sync now',
			'corpus' => 'Rebuild AI corpus',
		) as $act => $label ) : ?>
		<form method="post" style="display:inline-block;margin-right:8px">
			<?php wp_nonce_field( 'gasf_crm' ); ?>
			<input type="hidden" name="gasf_crm_action" value="<?php echo esc_attr( $act ); ?>">
			<button class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php endforeach; ?>
	</p>
	<p class="description">
		Last sync: <?php echo $cfg['last_sync']
			? esc_html( human_time_diff( (int) $cfg['last_sync'] ) . ' ago' )
			: 'never'; ?>.
		Hourly WP-Cron is the fallback; a system cron running
		<code>wp gasf-crm sync</code> is the reliable path on a low-traffic site.
	</p>

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
