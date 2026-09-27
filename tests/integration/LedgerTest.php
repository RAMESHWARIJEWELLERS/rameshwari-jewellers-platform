<?php
/**
 * Import ledger tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Schema;

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * The rollback fields of Development Blueprint §5.7.
 */
final class LedgerTest extends DatabaseTestCase {

	private const TOKEN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

	/**
	 * Writes one ledger row.
	 *
	 * @param string      $operation created or updated.
	 * @param string|null $before    Prior values as JSON.
	 * @param int         $object_id Object id.
	 * @return void
	 */
	private function row( string $operation, ?string $before, int $object_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Integration test writes a fixture row directly.
		$wpdb->insert(
			Schema::table( 'rj_import_ledger' ),
			array(
				'token'       => self::TOKEN,
				'created_at'  => '2026-09-27 10:00:00',
				'user_id'     => 1,
				'import_type' => 'products',
				'object_type' => 'product',
				'object_id'   => $object_id,
				'operation'   => $operation,
				'before'      => $before,
			)
		);
	}

	/**
	 * Rows for one token, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function reversal_order(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test inspection.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE token = %s ORDER BY id DESC', Schema::table( 'rj_import_ledger' ), self::TOKEN ), ARRAY_A );
	}

	/**
	 * A creation stores no prior value, an update stores it, and neither is rolled back yet.
	 */
	public function test_rollback_fields_persist(): void {
		$this->install();
		$this->row( 'created', null, 10 );
		$this->row( 'updated', '{"_rj_weight":"12.500"}', 11 );

		$rows = $this->reversal_order();

		$this->assertNull( $rows[1]['before'] );
		$this->assertSame( array( '_rj_weight' => '12.500' ), json_decode( (string) $rows[0]['before'], true ) );
		$this->assertNull( $rows[0]['rolled_back_at'] );
		$this->assertNull( $rows[1]['rolled_back_at'] );
	}

	/**
	 * Reversal reads a token's rows newest first.
	 */
	public function test_ordered_reversal(): void {
		$this->install();

		foreach ( array( 20, 21, 22 ) as $id ) {
			$this->row( 'created', null, $id );
		}

		$this->assertSame( array( '22', '21', '20' ), array_column( $this->reversal_order(), 'object_id' ) );
	}
}
