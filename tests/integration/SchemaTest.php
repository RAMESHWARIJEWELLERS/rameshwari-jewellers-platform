<?php
/**
 * Schema tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Schema;

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * The eight tables against Development Blueprint §5.
 */
final class SchemaTest extends DatabaseTestCase {

	/**
	 * Columns per table from §5: name, type (integer widths removed), nullable.
	 */
	private const COLUMNS = array(
		'rj_leads'              => 'id bigint unsigned NO|created_at datetime NO|status varchar(20) NO|source varchar(32) NO|object_id bigint unsigned YES|object_code varchar(64) YES|customer_id bigint unsigned YES|name varchar(191) YES|phone varchar(32) YES|email varchar(191) YES|message text YES|channel varchar(20) NO|page_url text YES|referrer text YES|ip_hash char(64) YES|user_agent varchar(255) YES|spam_score tinyint NO|notes text YES|handled_by bigint unsigned YES|handled_at datetime YES',
		'rj_activity_log'       => 'id bigint unsigned NO|created_at datetime NO|user_id bigint unsigned YES|user_login varchar(60) YES|object_type varchar(32) NO|object_id bigint unsigned YES|action varchar(32) NO|summary varchar(255) YES|changes longtext YES|ip_hash char(64) YES',
		'rj_notification_queue' => 'id bigint unsigned NO|created_at datetime NO|scheduled_at datetime NO|event varchar(64) NO|channel varchar(32) NO|recipient_id bigint unsigned YES|recipient_key varchar(191) YES|dedupe_key varchar(191) YES|payload longtext YES|status varchar(16) NO|attempts tinyint NO|last_error varchar(255) YES|sent_at datetime YES',
		'rj_wishlist'           => 'user_id bigint unsigned NO|post_id bigint unsigned NO|added_at datetime NO',
		'rj_recent_views'       => 'user_id bigint unsigned NO|post_id bigint unsigned NO|viewed_at datetime NO',
		'rj_login_log'          => 'id bigint unsigned NO|user_id bigint unsigned YES|created_at datetime NO|method varchar(20) NO|result varchar(16) NO|ip_hash char(64) YES',
		'rj_product_index'      => 'post_id bigint unsigned NO|code varchar(64) NO|name_hi varchar(191) YES|name_en varchar(191) YES|weight decimal(10,3) YES|metal_id bigint unsigned YES|purity_id bigint unsigned YES|primary_term_id bigint unsigned YES|visibility varchar(16) NO|updated_at datetime NO',
		'rj_import_ledger'      => 'id bigint unsigned NO|token char(32) NO|created_at datetime NO|user_id bigint unsigned NO|import_type varchar(32) NO|object_type varchar(32) NO|object_id bigint unsigned NO|operation varchar(16) NO|before longtext YES|rolled_back_at datetime YES',
	);

	/**
	 * Defaults §5 states.
	 */
	private const DEFAULTS = array(
		'rj_leads'              => array(
			'status'     => 'new',
			'channel'    => 'whatsapp',
			'spam_score' => '0',
		),
		'rj_notification_queue' => array( 'attempts' => '0' ),
	);

	/**
	 * Keys per table from §5: name => columns, with ! marking unique.
	 */
	private const KEYS = array(
		'rj_leads'              => 'PRIMARY!id|created_at created_at|status_created status,created_at|source_object source,object_id|phone phone|customer_id customer_id',
		'rj_activity_log'       => 'PRIMARY!id|created_at created_at|object object_type,object_id|user_created user_id,created_at',
		'rj_notification_queue' => 'PRIMARY!id|dedupe_key!dedupe_key|status_scheduled status,scheduled_at|event_created event,created_at|recipient_id recipient_id',
		'rj_wishlist'           => 'PRIMARY!user_id,post_id|post_id post_id',
		'rj_recent_views'       => 'PRIMARY!user_id,post_id|user_viewed user_id,viewed_at',
		'rj_login_log'          => 'PRIMARY!id|user_created user_id,created_at|result_created result,created_at',
		'rj_product_index'      => 'PRIMARY!post_id|code!code|visibility_weight visibility,weight|name_hi name_hi|name_en name_en|primary_term_id primary_term_id',
		'rj_import_ledger'      => 'PRIMARY!id|token token|token_id token,id|created_at created_at',
	);

	/**
	 * Exactly the eight approved tables are defined.
	 */
	public function test_defines_exactly_eight_tables(): void {
		$this->assertSame( array_keys( self::COLUMNS ), Schema::names() );
	}

	/**
	 * Names carry the installation prefix, never a literal one.
	 */
	public function test_names_use_runtime_prefix(): void {
		global $wpdb;

		foreach ( Schema::table_names() as $table ) {
			$this->assertStringStartsWith( $wpdb->prefix . 'rj_', $table );
		}
	}

	/**
	 * Statements use the installation charset, which supports four-byte characters.
	 */
	public function test_statements_use_installation_charset(): void {
		global $wpdb;

		$this->assertStringStartsWith( 'utf8mb4', (string) $wpdb->charset );

		foreach ( Schema::statements() as $statement ) {
			$this->assertStringEndsWith( $wpdb->get_charset_collate() . ';', $statement );
		}
	}

	/**
	 * References to posts and users are never enforced by a foreign key.
	 */
	public function test_no_foreign_keys(): void {
		foreach ( Schema::statements() as $statement ) {
			$this->assertStringNotContainsStringIgnoringCase( 'FOREIGN KEY', $statement );
			$this->assertStringNotContainsStringIgnoringCase( 'REFERENCES', $statement );
		}
	}

	/**
	 * A fresh install creates all eight tables.
	 */
	public function test_fresh_install_creates_all_tables(): void {
		$this->assertTrue( $this->install() );

		foreach ( Schema::table_names() as $table ) {
			$this->assertTrue( $this->table_exists( $table ), $table );
		}
	}

	/**
	 * Every column's name, type, nullability and stated default match §5.
	 */
	public function test_columns_match_specification(): void {
		global $wpdb;

		$this->install();

		foreach ( self::COLUMNS as $name => $spec ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test inspection.
			$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', Schema::table( $name ) ), ARRAY_A );
			$actual = array();

			foreach ( $rows as $row ) {
				$type     = (string) preg_replace( '/(int)\(\d+\)/', '$1', strtolower( (string) $row['Type'] ) );
				$actual[] = $row['Field'] . ' ' . $type . ' ' . $row['Null'];

				if ( isset( self::DEFAULTS[ $name ][ $row['Field'] ] ) ) {
					$this->assertSame( self::DEFAULTS[ $name ][ $row['Field'] ], (string) $row['Default'], $name . '.' . $row['Field'] );
				}
			}

			$this->assertSame( explode( '|', $spec ), $actual, $name );
		}
	}

	/**
	 * Every primary key, unique key and index matches §5.
	 */
	public function test_keys_match_specification(): void {
		global $wpdb;

		$this->install();

		foreach ( self::KEYS as $name => $spec ) {
			$expected = array();

			foreach ( explode( '|', $spec ) as $key ) {
				$unique                = str_contains( $key, '!' );
				$parts                 = preg_split( '/[ !]/', $key );
				$expected[ $parts[0] ] = ( $unique ? 'unique ' : '' ) . $parts[1];
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test inspection.
			$rows    = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', Schema::table( $name ) ), ARRAY_A );
			$columns = array();
			$unique  = array();

			foreach ( $rows as $row ) {
				$columns[ $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = $row['Column_name'];

				$unique[ $row['Key_name'] ] = '0' === (string) $row['Non_unique'];
			}

			$actual = array();

			foreach ( $columns as $key => $cols ) {
				ksort( $cols );
				$actual[ $key ] = ( $unique[ $key ] ? 'unique ' : '' ) . implode( ',', $cols );
			}

			ksort( $expected );
			ksort( $actual );

			$this->assertSame( $expected, $actual, $name );
		}
	}

	/**
	 * Devanagari and a four-byte emoji survive every text column byte for byte.
	 */
	public function test_devanagari_round_trip_every_text_column(): void {
		global $wpdb;

		$this->install();

		$sample = 'बोरला💍';

		foreach ( Schema::table_names() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test inspection.
			$fields = (array) $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table ), ARRAY_A );
			$row    = array();
			$text   = array();

			foreach ( $fields as $field ) {
				$type = strtolower( (string) $field['Type'] );

				if ( str_contains( (string) $field['Extra'], 'auto_increment' ) ) {
					continue;
				}

				if ( 1 === preg_match( '/char|text/', $type ) ) {
					$row[ $field['Field'] ] = $sample;
					$text[]                 = $field['Field'];
				} elseif ( 'datetime' === $type ) {
					$row[ $field['Field'] ] = '2026-09-27 10:00:00';
				} else {
					$row[ $field['Field'] ] = '1';
				}
			}

			if ( array() === $text ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Integration test writes a fixture row directly.
			$this->assertSame( 1, $wpdb->insert( $table, $row ), $table );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test inspection.
			$stored = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i LIMIT 1', $table ), ARRAY_A );

			foreach ( $text as $column ) {
				$this->assertSame( $sample, $stored[ $column ], $table . '.' . $column );
			}
		}
	}

	/**
	 * Product codes are unique at the database.
	 */
	public function test_product_code_unique(): void {
		global $wpdb;

		$this->install();

		$table = Schema::table( 'rj_product_index' );
		$row   = array(
			'post_id'    => 1,
			'code'       => 'RJ-001',
			'visibility' => 'public',
			'updated_at' => '2026-09-27 10:00:00',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Integration test writes a fixture row directly.
		$this->assertSame( 1, $wpdb->insert( $table, $row ) );

		$row['post_id'] = 2;
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Integration test writes a fixture row directly.
		$second = $wpdb->insert( $table, $row );
		$wpdb->suppress_errors( false );

		$this->assertFalse( $second );
	}

	/**
	 * The wishlist's composite key refuses a second identical row.
	 */
	public function test_wishlist_key_rejects_duplicate(): void {
		global $wpdb;

		$this->install();

		$table = Schema::table( 'rj_wishlist' );
		$row   = array(
			'user_id'  => 5,
			'post_id'  => 9,
			'added_at' => '2026-09-27 10:00:00',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Integration test writes a fixture row directly.
		$this->assertSame( 1, $wpdb->insert( $table, $row ) );

		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Integration test writes a fixture row directly.
		$second = $wpdb->insert( $table, $row );
		$wpdb->suppress_errors( false );

		$this->assertFalse( $second );
	}
}
