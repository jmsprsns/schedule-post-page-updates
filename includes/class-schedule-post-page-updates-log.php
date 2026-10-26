<?php
/**
 * History: one row per post a scheduled change touched, with a backup of
 * what it replaced so it can be undone.
 *
 * @package Schedule_Post_Page_Updates
 */

defined( 'ABSPATH' ) || exit;

/**
 * History table access.
 */
final class Schedule_Post_Page_Updates_Log {

	/**
	 * Row kinds.
	 */
	const KIND_UPDATE  = 'update';
	const KIND_REPLACE = 'replace';

	/**
	 * Row statuses.
	 */
	const APPLIED = 'applied';
	const UNDONE  = 'undone';
	const FAILED  = 'failed';

	/**
	 * Add a row.
	 *
	 * @param array<string,mixed> $row Column values. "backup" may be an array.
	 * @return int Row ID, 0 on failure.
	 */
	public static function add( array $row ) {
		global $wpdb;

		$row = wp_parse_args(
			$row,
			array(
				'kind'       => self::KIND_UPDATE,
				'post_id'    => 0,
				'rule_id'    => 0,
				'run'        => 0,
				'user_id'    => 0,
				'status'     => self::APPLIED,
				'post_title' => '',
				'summary'    => '',
				'message'    => '',
				'backup'     => null,
			)
		);

		$data = array(
			'kind'       => (string) $row['kind'],
			'post_id'    => absint( $row['post_id'] ),
			'rule_id'    => absint( $row['rule_id'] ),
			'run'        => absint( $row['run'] ),
			'user_id'    => absint( $row['user_id'] ),
			'status'     => (string) $row['status'],
			'post_title' => (string) $row['post_title'],
			'summary'    => (string) $row['summary'],
			'message'    => (string) $row['message'],
			'backup'     => null === $row['backup'] ? null : wp_json_encode( $row['backup'] ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		$ok = $wpdb->insert( Schedule_Post_Page_Updates_Install::table( 'log' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Our own table.

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One row, with its backup decoded.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = Schedule_Post_Page_Updates_Install::table( 'log' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.

		if ( $row ) {
			$row->backup = self::decode( $row->backup );
		}

		return $row;
	}

	/**
	 * A page of rows, newest first.
	 *
	 * @param array<string,mixed> $args status, kind, rule_id, run, page, per_page.
	 * @return array{rows: object[], total: int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'kind'     => '',
				'rule_id'  => 0,
				'run'      => 0,
				'page'     => 1,
				'per_page' => 30,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( '' !== $args['kind'] ) {
			$where[]  = 'kind = %s';
			$params[] = (string) $args['kind'];
		}
		if ( $args['rule_id'] ) {
			$where[]  = 'rule_id = %d';
			$params[] = absint( $args['rule_id'] );
		}
		if ( $args['run'] ) {
			$where[]  = 'run = %d';
			$params[] = absint( $args['run'] );
		}

		$table    = Schedule_Post_Page_Updates_Install::table( 'log' );
		$where    = implode( ' AND ', $where );
		$per_page = max( 1, min( 200, absint( $args['per_page'] ) ) );
		$offset   = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Our own table; $where only holds placeholders.
		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$rows_sql  = "SELECT id, kind, post_id, rule_id, run, user_id, status, post_title, summary, message, created_at, undone_at, undone_by, backup IS NOT NULL AS has_backup FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";

		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Count rows of a status created in the last few days.
	 *
	 * @param string $status Row status.
	 * @param int    $days   How far back.
	 * @return int
	 */
	public static function count_recent( $status, $days ) {
		global $wpdb;

		$table  = Schedule_Post_Page_Updates_Install::table( 'log' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - absint( $days ) * DAY_IN_SECONDS );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND created_at >= %s", $status, $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
	}

	/**
	 * IDs of the applied rows from one run of a rule, oldest first.
	 *
	 * @param int $rule_id Rule ID.
	 * @param int $run     Run number.
	 * @param int $limit   How many.
	 * @return int[]
	 */
	public static function applied_in_run( $rule_id, $run, $limit = 25 ) {
		global $wpdb;

		$table = Schedule_Post_Page_Updates_Install::table( 'log' );

		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE rule_id = %d AND run = %d AND status = %s AND backup IS NOT NULL ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
					absint( $rule_id ),
					absint( $run ),
					self::APPLIED,
					absint( $limit )
				)
			)
		);
	}

	/**
	 * Mark a row as undone and drop its backup, which no longer applies.
	 *
	 * @param int    $id      Row ID.
	 * @param int    $user_id Who undid it.
	 * @param string $message Note, e.g. parts that were left alone.
	 * @return void
	 */
	public static function mark_undone( $id, $user_id, $message = '' ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
			Schedule_Post_Page_Updates_Install::table( 'log' ),
			array(
				'status'    => self::UNDONE,
				'undone_at' => gmdate( 'Y-m-d H:i:s' ),
				'undone_by' => absint( $user_id ),
				'message'   => (string) $message,
				'backup'    => null,
			),
			array( 'id' => absint( $id ) )
		);
	}

	/**
	 * Delete rows older than the retention period.
	 *
	 * @param int $days Days to keep; 0 keeps everything.
	 * @return void
	 */
	public static function prune( $days ) {
		global $wpdb;

		$days = absint( $days );
		if ( $days < 1 ) {
			return;
		}

		$table  = Schedule_Post_Page_Updates_Install::table( 'log' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
	}

	/**
	 * Decode a stored backup.
	 *
	 * @param string|null $json Stored JSON.
	 * @return array|null
	 */
	private static function decode( $json ) {
		if ( null === $json || '' === $json ) {
			return null;
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}
}
