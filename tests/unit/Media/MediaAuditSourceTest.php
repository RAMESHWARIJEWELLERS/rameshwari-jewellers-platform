<?php
/**
 * Media audit source-contract tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Cron\MediaAudit;

/**
 * The audit stores nothing, schedules nothing, runs no program and deletes no attachment.
 */
final class MediaAuditSourceTest extends TestCase {

	/**
	 * The source of the class under test.
	 *
	 * @return string
	 */
	private function source(): string {
		$file = (string) ( new \ReflectionClass( MediaAudit::class ) )->getFileName();

		return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads this plugin's own source file.
	}

	/**
	 * No persistence of any kind.
	 *
	 * Each function is matched as a call, so a name that only starts the same way,
	 * such as the update_post_meta_cache query argument, is not mistaken for a write.
	 */
	public function test_no_persistence(): void {
		$calls = array( 'update_option', 'add_option', 'delete_option', 'set_transient', 'set_site_transient', 'update_post_meta', 'add_post_meta', 'delete_post_meta', 'update_term_meta', 'add_term_meta', 'delete_term_meta', 'register_post_meta', 'register_term_meta', 'dbDelta', 'wp_cache_set', 'wp_cache_add' );

		foreach ( $calls as $call ) {
			$this->assertSame( 0, preg_match( '/\\b' . $call . '\\s*\\(/', $this->source() ), $call );
		}

		$this->assertSame( 0, preg_match( '/\\$wpdb\\b|\\bglobal\\s+\\$wpdb/', $this->source() ), 'wpdb' );
	}

	/**
	 * The call matcher catches real writes and ignores the cache argument name.
	 */
	public function test_persistence_matcher_is_exact(): void {
		$pattern = '/\\bupdate_post_meta\\s*\\(/';

		$this->assertSame( 1, preg_match( $pattern, 'update_post_meta( $id, $k, $v );' ) );
		$this->assertSame( 1, preg_match( $pattern, 'update_post_meta($id,$k,$v);' ) );
		$this->assertSame( 0, preg_match( $pattern, "'update_post_meta_cache' => false," ) );
		$this->assertSame( 0, preg_match( $pattern, 'my_update_post_meta( $id );' ) );
	}

	/**
	 * No shell, no external program, no fixed path, no scheduling here.
	 */
	public function test_no_shell_path_or_scheduling(): void {
		foreach ( array( 'exec(', 'shell_exec', 'passthru', 'proc_open', 'popen', 'system(', 'C:\\', '/var/', '/home/', '/tmp/', 'wp_schedule_event', 'wp_next_scheduled' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $this->source(), $needle );
		}
	}

	/**
	 * Nothing here can delete media.
	 */
	public function test_no_deletion_calls(): void {
		foreach ( array( 'wp_delete_attachment', 'wp_delete_post', 'wp_trash_post', 'wp_delete_file', 'unlink(' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $this->source(), $needle );
		}
	}

	/**
	 * It does not import product or category classes.
	 */
	public function test_no_domain_imports(): void {
		$this->assertStringNotContainsString( 'Rameshwari\\Core\\Product', $this->source() );
		$this->assertStringNotContainsString( 'Rameshwari\\Core\\Category', $this->source() );
	}

	/**
	 * The public surface is small: run it, or run it with a log line.
	 */
	public function test_public_api(): void {
		$names = array_map( static fn ( \ReflectionMethod $m ): string => $m->getName(), ( new \ReflectionClass( MediaAudit::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) );

		sort( $names );

		$this->assertSame( array( '__construct', 'cron', 'run' ), $names );
	}
}
