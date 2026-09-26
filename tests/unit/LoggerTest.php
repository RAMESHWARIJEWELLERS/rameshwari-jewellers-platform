<?php
/**
 * Logger tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Support\Logger;
use WP_UnitTestCase;

/**
 * Captures lines through an injected writer instead of the PHP error log.
 */
final class LoggerTest extends WP_UnitTestCase {

	/**
	 * Captured lines.
	 *
	 * @var array<int, string>
	 */
	private array $lines = array();

	/**
	 * Builds a logger that writes into $this->lines.
	 *
	 * @param bool $debug Debug mode.
	 * @return Logger
	 */
	private function logger( bool $debug ): Logger {
		$this->lines = array();
		return new Logger(
			function ( string $line ): void {
				$this->lines[] = $line;
			},
			$debug
		);
	}

	/**
	 * Outside debug mode, debug lines are dropped and warnings are kept.
	 */
	public function test_threshold_outside_debug(): void {
		$logger = $this->logger( false );
		$logger->debug( 'hidden' );
		$logger->warning( 'shown' );

		$this->assertSame( array( '[rameshwari] WARNING: shown' ), $this->lines );
	}

	/**
	 * In debug mode, debug lines are written.
	 */
	public function test_debug_mode_writes_debug(): void {
		$logger = $this->logger( true );
		$logger->debug( 'visible' );

		$this->assertSame( array( '[rameshwari] DEBUG: visible' ), $this->lines );
	}

	/**
	 * Sensitive keys are redacted at any depth; other values survive.
	 */
	public function test_redacts_sensitive_keys(): void {
		$logger = $this->logger( false );
		$logger->error(
			'failed',
			array(
				'provider' => 'google',
				'api_key'  => 'sk-live-123',
				'request'  => array(
					'password' => 'hunter2',
					'code'     => 'P-1',
				),
			)
		);

		$this->assertStringNotContainsString( 'sk-live-123', $this->lines[0] );
		$this->assertStringNotContainsString( 'hunter2', $this->lines[0] );
		$this->assertStringContainsString( '"provider":"google"', $this->lines[0] );
		$this->assertStringContainsString( '"code":"P-1"', $this->lines[0] );
	}

	/**
	 * An unknown level is a programming error.
	 */
	public function test_unknown_level_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->logger( true )->log( 'verbose', 'x' );
	}
}
