<?php
/** Database schema, fenced leases and checkpoint storage. *
 *
 * @package MultiAISEOContentFactory
 */

namespace MASF;

defined( 'ABSPATH' ) || exit;

/** Store implementation for the factory workflow. */
final class Store {
	/**
	 * Table.
	 *
	 * @param string $name Name.
	 *
	 * @return mixed Operation result.
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'masf_' . $name;
	}
	/**
	 * Now.
	 *
	 * @return mixed Operation result.
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' ); }
	/**
	 * Install.
	 *
	 * @param bool $network_wide Network wide.
	 *
	 * @return mixed Operation result.
	 */
	public static function install( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			wp_die( esc_html( 'Bản đầu hỗ trợ kích hoạt từng website; chưa hỗ trợ network activation.' ) ); }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c   = $wpdb->get_charset_collate();
		$sql = array(
			'campaigns' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
			name varchar(200) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'PAUSED',
			config longtext NOT NULL,
			batch_remaining int NOT NULL DEFAULT 0,
			created_by bigint unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id)",
			'articles'  => "id bigint unsigned NOT NULL AUTO_INCREMENT,
			uuid varchar(36) NOT NULL,
			campaign_id bigint unsigned NOT NULL,
			title varchar(500) NOT NULL,
			title_hash char(64) NOT NULL,
			primary_keyword varchar(250) NOT NULL DEFAULT '',
			payload longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'PLANNED',
			phase varchar(20) NOT NULL DEFAULT 'write',
			provider varchar(20) NOT NULL DEFAULT '',
			model varchar(150) NOT NULL DEFAULT '',
			content longtext NULL,
			qa_report longtext NULL,
			qa_score int NOT NULL DEFAULT 0,
			post_id bigint unsigned NOT NULL DEFAULT 0,
			published_url text NULL,
			delivery_status varchar(20) NOT NULL DEFAULT '',
			retry_count int NOT NULL DEFAULT 0,
			repair_count int NOT NULL DEFAULT 0,
			lease_token varchar(36) NOT NULL DEFAULT '',
			lease_until datetime NULL,
			next_run datetime NOT NULL,
			completed_at datetime NULL,
			error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY title_hash (title_hash),
			KEY queue (campaign_id,status,next_run),
			KEY post_id (post_id)",
			'logs'      => 'id bigint unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint unsigned NOT NULL DEFAULT 0,
			article_id bigint unsigned NOT NULL DEFAULT 0,
			level varchar(20) NOT NULL,
			message text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY campaign_id (campaign_id)',
			'requests'  => "id bigint unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint unsigned NOT NULL DEFAULT 0,
			article_id bigint unsigned NOT NULL DEFAULT 0,
			provider varchar(20) NOT NULL,
			model varchar(150) NOT NULL,
			state varchar(20) NOT NULL DEFAULT 'reserved',
			input_tokens bigint unsigned NOT NULL DEFAULT 0,
			output_tokens bigint unsigned NOT NULL DEFAULT 0,
			reserved_tokens bigint unsigned NOT NULL DEFAULT 0,
			cost decimal(14,6) NOT NULL DEFAULT 0,
			reserved_cost decimal(14,6) NOT NULL DEFAULT 0,
			http_status int NOT NULL DEFAULT 0,
			request_id varchar(200) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY quota (provider,created_at),
			KEY campaign_id (campaign_id)",
			'locks'     => 'name varchar(100) NOT NULL,
			token varchar(36) NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (name)',
		);
		foreach ( $sql as $name => $fields ) {
			dbDelta( 'CREATE TABLE ' . self::table( $name ) . " (\n$fields\n) ENGINE=InnoDB $c;" );
		}
		update_option( 'masf_version', MASF_VERSION, false );
		update_option( 'masf_enabled', true, false );
	}
	/**
	 * Campaign.
	 *
	 * @param int $id Id.
	 *
	 * @return mixed Operation result.
	 */
	public static function campaign( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'campaigns' ) . ' WHERE id=%d', $id ), ARRAY_A );
		if ( $r ) {
			// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
			$r['config'] = json_decode( $r['config'], true ) ?: array(); }
		return $r;
	}
	/**
	 * Article.
	 *
	 * @param int $id Id.
	 *
	 * @return mixed Operation result.
	 */
	public static function article( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'articles' ) . ' WHERE id=%d', $id ), ARRAY_A );
		if ( $r ) {
			foreach ( array( 'payload', 'content', 'qa_report' ) as $field ) {
				// phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- Intentional empty-value fallback; plugin requires PHP 8.1+.
				$r[ $field ] = json_decode( $r[ $field ] ?? '', true ) ?: array(); }
		}
		return $r;
	}
	/**
	 * Checkpoint.
	 *
	 * @param int    $id Id.
	 * @param array  $data Data.
	 * @param string $token Token.
	 *
	 * @return mixed Operation result.
	 */
	public static function checkpoint( $id, $data, $token = null ) {
		global $wpdb;
		foreach ( array( 'payload', 'content', 'qa_report' ) as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$data[ $field ] = wp_json_encode( $data[ $field ], JSON_UNESCAPED_UNICODE ); }
		}
		$data['updated_at'] = self::now();
		$where              = array( 'id' => $id );
		if ( null !== $token ) {
			$where['lease_token'] = $token; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache.
		return $wpdb->update( self::table( 'articles' ), $data, $where );
	}
	/**
	 * Lease.
	 *
	 * @param string $name Name.
	 * @param int    $seconds Seconds.
	 *
	 * @return mixed Operation result.
	 */
	public static function lease( $name, $seconds = 180 ) {
		global $wpdb;
		$t     = self::table( 'locks' );
		$token = wp_generate_uuid4();
		$until = gmdate( 'Y-m-d H:i:s', time() + $seconds );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Table identifiers come only from Store::table() or wpdb; values use placeholders.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (name,token,expires_at) VALUES (%s,%s,%s)", $name, $token, $until ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Table identifiers come only from Store::table() or wpdb; values use placeholders.
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET token=%s,expires_at=%s WHERE name=%s AND expires_at < %s", $token, $until, $name, self::now() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Table identifiers come only from Store::table() or wpdb; values use placeholders.
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT token FROM $t WHERE name=%s", $name ) );
		return $owner === $token ? $token : false;
	}
	/**
	 * Release.
	 *
	 * @param string $name Name.
	 * @param string $token Token.
	 *
	 * @return mixed Operation result.
	 */
	public static function release( $name, $token ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache.
		$wpdb->delete(
			self::table( 'locks' ),
			array(
				'name'  => $name,
				'token' => $token,
			)
		);
	}
	/**
	 * Log.
	 *
	 * @param string $message Message.
	 * @param string $level Level.
	 * @param mixed  $campaign Campaign.
	 * @param int    $article Article.
	 *
	 * @return mixed Operation result.
	 */
	public static function log( $message, $level = 'info', $campaign = 0, $article = 0 ) {
		global $wpdb;
		// Never record remote response bodies, prompts or HTTP request headers.
		$message = preg_replace( '/(?:sk-[a-zA-Z0-9_-]{8,}|AIza[a-zA-Z0-9_-]{12,}|Bearer\s+\S+)/', '[REDACTED]', (string) $message );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Factory checkpoints require direct custom-table operations.
		$wpdb->insert(
			self::table( 'logs' ),
			array(
				'message'     => mb_substr( sanitize_textarea_field( $message ), 0, 2000 ),
				'level'       => sanitize_key( $level ),
				'campaign_id' => $campaign,
				'article_id'  => $article,
				'created_at'  => self::now(),
			)
		);
	}
	/**
	 * Norm.
	 *
	 * @param string $text Text.
	 *
	 * @return mixed Operation result.
	 */
	public static function norm( $text ) {
		return mb_strtolower( preg_replace( '/\s+/u', ' ', trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) ), 'UTF-8' );
	}
	/**
	 * Duplicate.
	 *
	 * @param string $title Title.
	 * @param int    $except Except.
	 *
	 * @return mixed Operation result.
	 */
	public static function duplicate( $title, $except = 0 ) {
		global $wpdb;
		$hash = hash( 'sha256', self::norm( $title ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'articles' ) . ' WHERE title_hash=%s AND id<>%d', $hash, $except ) );
		if ( $id ) {
			return 'Matrix #' . $id; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache.
		$post = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status NOT IN ('trash','auto-draft') AND post_title=%s AND ID<>%d LIMIT 1", $title, $except ? ( self::article( $except )['post_id'] ?? 0 ) : 0 ) );
		return $post ? 'WordPress #' . $post : false;
	}
	/**
	 * Finish batch.
	 *
	 * @param int $campaign_id Campaign id.
	 *
	 * @return mixed Operation result.
	 */
	public static function finish_batch( $campaign_id ) {
		global $wpdb;
		// MySQL evaluates UPDATE assignments left-to-right: inspect old count first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Factory checkpoints require direct custom-table operations. Fresh lease, quota and queue reads must not use stale cache. Schema/table names are trusted constants from Store::table(); all dynamic values are bound or handled by wpdb.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'campaigns' ) . " SET status=IF(batch_remaining<=1,'PAUSED','BATCH'),batch_remaining=GREATEST(0,batch_remaining-1),updated_at=%s WHERE id=%d AND status='BATCH'", self::now(), $campaign_id ) );
	}
}
