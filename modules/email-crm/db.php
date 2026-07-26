<?php
/**
 * Email CRM — schema and queries (modules/email-crm/db.php)
 *
 * Three tables, all prefixed gasf_crm_. Threads are keyed on the Graph
 * conversationId, which is what actually defines "a thread" to Exchange —
 * subject matching would merge unrelated "Question" emails and split any
 * thread where someone edits the subject line.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function gasf_crm_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'gasf_crm_' . $name;
}

function gasf_crm_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	$threads  = gasf_crm_table( 'threads' );
	$messages = gasf_crm_table( 'messages' );

	// conversation_id is varchar(191): Graph conversationIds are base64-ish and
	// comfortably shorter, and 191 is the longest unique-indexable varchar under
	// utf8mb4 on MySQL 5.7 (767-byte index limit / 4 bytes per char).
	dbDelta( "CREATE TABLE {$threads} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		conversation_id VARCHAR(191) NOT NULL,
		subject TEXT NULL,
		last_from_name VARCHAR(191) NULL,
		last_from_addr VARCHAR(191) NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'new',
		locked_by BIGINT UNSIGNED NULL,
		locked_at DATETIME NULL,
		first_received_at DATETIME NULL,
		last_message_at DATETIME NULL,
		last_status_change_at DATETIME NULL,
		notified_at DATETIME NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY conversation_id (conversation_id),
		KEY status_last (status, last_message_at)
	) {$charset};" );

	dbDelta( "CREATE TABLE {$messages} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		thread_id BIGINT UNSIGNED NOT NULL,
		graph_message_id VARCHAR(191) NOT NULL,
		direction VARCHAR(4) NOT NULL DEFAULT 'in',
		from_name VARCHAR(191) NULL,
		from_addr VARCHAR(191) NULL,
		to_addrs TEXT NULL,
		sent_at DATETIME NULL,
		body_preview TEXT NULL,
		body_html LONGTEXT NULL,
		has_attachments TINYINT(1) NOT NULL DEFAULT 0,
		sent_by_user_id BIGINT UNSIGNED NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY graph_message_id (graph_message_id),
		KEY thread_sent (thread_id, sent_at)
	) {$charset};" );
}

/**
 * Upsert a thread by conversationId and return its row id.
 *
 * $reopen is the spec's reopen rule: an inbound message on an addressed thread
 * puts it back to 'new'. Deliberately does NOT reopen on outbound — our own
 * reply landing in Sent Items must not resurrect the thread it just closed.
 */
function gasf_crm_upsert_thread( $conversation_id, $subject, $from_name, $from_addr, $sent_at, $reopen ) {
	global $wpdb;
	$t   = gasf_crm_table( 'threads' );
	// GMT, matching the gmdate() stamps sync.php derives from Graph. Mixing
	// current_time('mysql') in here would offset every locally-written row by
	// the site's UTC offset, sorting our own replies before the mail they answer.
	$now = current_time( 'mysql', true );

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$t} WHERE conversation_id = %s", $conversation_id ), ARRAY_A );

	if ( ! $row ) {
		$wpdb->insert( $t, array(
			'conversation_id'       => $conversation_id,
			'subject'               => $subject,
			'last_from_name'        => $from_name,
			'last_from_addr'        => $from_addr,
			'status'                => 'new',
			'first_received_at'     => $sent_at,
			'last_message_at'       => $sent_at,
			'last_status_change_at' => $now,
		) );
		return array( 'id' => (int) $wpdb->insert_id, 'reopened' => false, 'created' => true );
	}

	$data = array( 'last_message_at' => $sent_at );
	if ( $from_addr ) {
		$data['last_from_name'] = $from_name;
		$data['last_from_addr'] = $from_addr;
	}

	$reopened = false;
	if ( $reopen && 'addressed' === $row['status'] ) {
		$data['status']                = 'new';
		$data['last_status_change_at'] = $now;
		$data['locked_by']             = null;
		$data['locked_at']             = null;
		$data['notified_at']           = null; // let it notify again
		$reopened                      = true;
	}

	$wpdb->update( $t, $data, array( 'id' => (int) $row['id'] ) );
	return array( 'id' => (int) $row['id'], 'reopened' => $reopened, 'created' => false );
}

/** Insert a message, ignoring duplicates. Returns true if a row was written. */
function gasf_crm_insert_message( array $m ) {
	global $wpdb;
	$sql = $wpdb->prepare(
		'INSERT IGNORE INTO ' . gasf_crm_table( 'messages' ) .
		' (thread_id, graph_message_id, direction, from_name, from_addr, to_addrs, sent_at, body_preview, body_html, has_attachments, sent_by_user_id)
		  VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d)',
		$m['thread_id'], $m['graph_message_id'], $m['direction'], $m['from_name'], $m['from_addr'],
		$m['to_addrs'], $m['sent_at'], $m['body_preview'], $m['body_html'],
		$m['has_attachments'] ? 1 : 0, (int) $m['sent_by_user_id']
	);
	return (bool) $wpdb->query( $sql );
}

function gasf_crm_set_status( $thread_id, $status ) {
	global $wpdb;
	$wpdb->update( gasf_crm_table( 'threads' ), array(
		'status'                => $status,
		'last_status_change_at' => current_time( 'mysql', true ),
		'locked_by'             => null,
		'locked_at'             => null,
	), array( 'id' => (int) $thread_id ) );
}

function gasf_crm_get_thread( $thread_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ' . gasf_crm_table( 'threads' ) . ' WHERE id = %d', (int) $thread_id
	), ARRAY_A );
}

function gasf_crm_thread_messages( $thread_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . gasf_crm_table( 'messages' ) . ' WHERE thread_id = %d ORDER BY sent_at ASC', (int) $thread_id
	), ARRAY_A );
}

/**
 * Thread list for the inbox. Open threads (new/claimed) first, newest first
 * within each group — an addressed thread is history, an open one is work.
 */
function gasf_crm_list_threads( $status = 'open', $limit = 100 ) {
	global $wpdb;
	$t = gasf_crm_table( 'threads' );

	if ( 'all' === $status ) {
		$where = '1=1';
	} elseif ( 'addressed' === $status ) {
		$where = "status = 'addressed'";
	} else {
		$where = "status IN ('new','claimed')";
	}

	return $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$t} WHERE {$where}
		 ORDER BY (status = 'addressed') ASC, last_message_at DESC
		 LIMIT %d", (int) $limit
	), ARRAY_A );
}

/**
 * Claim a thread for a user. Returns true if the lock is now ours.
 *
 * The UPDATE is the whole mechanism: a single conditional statement, so two
 * volunteers opening the same thread in the same second cannot both win —
 * MySQL serialises the row write and the loser's affected-rows comes back 0.
 * A read-then-write would race.
 */
function gasf_crm_claim_thread( $thread_id, $user_id, $lock_minutes = 15 ) {
	global $wpdb;
	$t      = gasf_crm_table( 'threads' );
	$now    = current_time( 'mysql' );
	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - ( $lock_minutes * 60 ) );

	$rows = $wpdb->query( $wpdb->prepare(
		"UPDATE {$t}
		    SET locked_by = %d, locked_at = %s, status = 'claimed',
		        last_status_change_at = CASE WHEN status = 'new' THEN %s ELSE last_status_change_at END
		  WHERE id = %d
		    AND status <> 'addressed'
		    AND (locked_by IS NULL OR locked_by = %d OR locked_at < %s)",
		$user_id, $now, $now, $thread_id, $user_id, $cutoff
	) );

	// affected_rows is 0 both when the WHERE missed and when the row already
	// held identical values (same user re-opening within the lock window), so
	// confirm by reading the holder back rather than trusting the count.
	if ( ! $rows ) {
		$holder = (int) $wpdb->get_var( $wpdb->prepare( "SELECT locked_by FROM {$t} WHERE id = %d", $thread_id ) );
		return $holder === (int) $user_id;
	}
	return true;
}

function gasf_crm_release_thread( $thread_id, $user_id ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare(
		'UPDATE ' . gasf_crm_table( 'threads' ) . "
		    SET locked_by = NULL, locked_at = NULL,
		        status = CASE WHEN status = 'claimed' THEN 'new' ELSE status END
		  WHERE id = %d AND locked_by = %d",
		$thread_id, $user_id
	) );
}

/** Drop locks past their window so an abandoned tab doesn't hold a thread forever. */
function gasf_crm_expire_locks( $lock_minutes = 15 ) {
	global $wpdb;
	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) ) - ( $lock_minutes * 60 ) );
	return (int) $wpdb->query( $wpdb->prepare(
		'UPDATE ' . gasf_crm_table( 'threads' ) . "
		    SET locked_by = NULL, locked_at = NULL,
		        status = CASE WHEN status = 'claimed' THEN 'new' ELSE status END
		  WHERE locked_at IS NOT NULL AND locked_at < %s", $cutoff
	) );
}
