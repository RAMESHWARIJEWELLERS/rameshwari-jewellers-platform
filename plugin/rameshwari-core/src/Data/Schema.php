<?php
/**
 * Custom table definitions.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Data;

/**
 * The single place a custom-table column is declared: the eight tables of
 * Development Blueprint §5, in dbDelta format.
 *
 * Table names get the installation's prefix at runtime, and the charset and
 * collation come from the installation so four-byte Devanagari and emoji
 * store correctly. There are no foreign keys: posts and users are referenced
 * by id only, as §5.1 requires, so deleting a post is never blocked.
 */
final class Schema {

	/**
	 * Column and key definitions, keyed by unprefixed table name.
	 *
	 * Types are lowercase and integer widths explicit so that dbDelta's
	 * comparison with DESCRIBE finds nothing to change on a re-run.
	 */
	private const TABLES = array(
		'rj_leads'              => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_at datetime NOT NULL,
status varchar(20) NOT NULL DEFAULT 'new',
source varchar(32) NOT NULL,
object_id bigint(20) unsigned NULL DEFAULT NULL,
object_code varchar(64) NULL DEFAULT NULL,
customer_id bigint(20) unsigned NULL DEFAULT NULL,
name varchar(191) NULL DEFAULT NULL,
phone varchar(32) NULL DEFAULT NULL,
email varchar(191) NULL DEFAULT NULL,
message text NULL,
channel varchar(20) NOT NULL DEFAULT 'whatsapp',
page_url text NULL,
referrer text NULL,
ip_hash char(64) NULL DEFAULT NULL,
user_agent varchar(255) NULL DEFAULT NULL,
spam_score tinyint(4) NOT NULL DEFAULT 0,
notes text NULL,
handled_by bigint(20) unsigned NULL DEFAULT NULL,
handled_at datetime NULL DEFAULT NULL,
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY status_created (status,created_at),
KEY source_object (source,object_id),
KEY phone (phone),
KEY customer_id (customer_id)",
		'rj_activity_log'       => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_at datetime NOT NULL,
user_id bigint(20) unsigned NULL DEFAULT NULL,
user_login varchar(60) NULL DEFAULT NULL,
object_type varchar(32) NOT NULL,
object_id bigint(20) unsigned NULL DEFAULT NULL,
action varchar(32) NOT NULL,
summary varchar(255) NULL DEFAULT NULL,
changes longtext NULL,
ip_hash char(64) NULL DEFAULT NULL,
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY object (object_type,object_id),
KEY user_created (user_id,created_at)',
		'rj_notification_queue' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_at datetime NOT NULL,
scheduled_at datetime NOT NULL,
event varchar(64) NOT NULL,
channel varchar(32) NOT NULL,
recipient_id bigint(20) unsigned NULL DEFAULT NULL,
recipient_key varchar(191) NULL DEFAULT NULL,
dedupe_key varchar(191) NULL DEFAULT NULL,
payload longtext NULL,
status varchar(16) NOT NULL,
attempts tinyint(4) NOT NULL DEFAULT 0,
last_error varchar(255) NULL DEFAULT NULL,
sent_at datetime NULL DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY dedupe_key (dedupe_key),
KEY status_scheduled (status,scheduled_at),
KEY event_created (event,created_at),
KEY recipient_id (recipient_id)',
		'rj_wishlist'           => 'user_id bigint(20) unsigned NOT NULL,
post_id bigint(20) unsigned NOT NULL,
added_at datetime NOT NULL,
PRIMARY KEY  (user_id,post_id),
KEY post_id (post_id)',
		'rj_recent_views'       => 'user_id bigint(20) unsigned NOT NULL,
post_id bigint(20) unsigned NOT NULL,
viewed_at datetime NOT NULL,
PRIMARY KEY  (user_id,post_id),
KEY user_viewed (user_id,viewed_at)',
		'rj_login_log'          => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NULL DEFAULT NULL,
created_at datetime NOT NULL,
method varchar(20) NOT NULL,
result varchar(16) NOT NULL,
ip_hash char(64) NULL DEFAULT NULL,
PRIMARY KEY  (id),
KEY user_created (user_id,created_at),
KEY result_created (result,created_at)',
		'rj_product_index'      => 'post_id bigint(20) unsigned NOT NULL,
code varchar(64) NOT NULL,
name_hi varchar(191) NULL DEFAULT NULL,
name_en varchar(191) NULL DEFAULT NULL,
weight decimal(10,3) NULL DEFAULT NULL,
metal_id bigint(20) unsigned NULL DEFAULT NULL,
purity_id bigint(20) unsigned NULL DEFAULT NULL,
primary_term_id bigint(20) unsigned NULL DEFAULT NULL,
visibility varchar(16) NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (post_id),
UNIQUE KEY code (code),
KEY visibility_weight (visibility,weight),
KEY name_hi (name_hi),
KEY name_en (name_en),
KEY primary_term_id (primary_term_id)',
		'rj_import_ledger'      => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
token char(32) NOT NULL,
created_at datetime NOT NULL,
user_id bigint(20) unsigned NOT NULL,
import_type varchar(32) NOT NULL,
object_type varchar(32) NOT NULL,
object_id bigint(20) unsigned NOT NULL,
operation varchar(16) NOT NULL,
`before` longtext NULL,
rolled_back_at datetime NULL DEFAULT NULL,
PRIMARY KEY  (id),
KEY token (token),
KEY token_id (token,id),
KEY created_at (created_at)',
	);

	/**
	 * Unprefixed names of the eight tables.
	 *
	 * @return array<int, string>
	 */
	public static function names(): array {
		return array_keys( self::TABLES );
	}

	/**
	 * A table's full name with the installation prefix.
	 *
	 * @param string $name Unprefixed name.
	 * @return string
	 * @throws \InvalidArgumentException For a table this class does not define.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		if ( ! isset( self::TABLES[ $name ] ) ) {
			throw new \InvalidArgumentException( esc_html( 'Unknown table: ' . $name ) );
		}

		return $wpdb->prefix . $name;
	}

	/**
	 * Full names of all eight tables.
	 *
	 * @return array<int, string>
	 */
	public static function table_names(): array {
		return array_map( array( self::class, 'table' ), self::names() );
	}

	/**
	 * One CREATE TABLE statement per table, for dbDelta.
	 *
	 * @return array<int, string>
	 */
	public static function statements(): array {
		global $wpdb;

		$charset    = $wpdb->get_charset_collate();
		$statements = array();

		foreach ( self::TABLES as $name => $body ) {
			$statements[] = 'CREATE TABLE ' . self::table( $name ) . " (\n" . $body . "\n) " . $charset . ';';
		}

		return $statements;
	}
}
