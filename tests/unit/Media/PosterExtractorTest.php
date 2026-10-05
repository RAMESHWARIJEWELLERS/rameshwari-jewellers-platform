<?php
/**
 * Poster extractor source-contract tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Services\Media\PosterExtractor;

/**
 * The service runs no program, stores nothing and binds nothing.
 */
final class PosterExtractorTest extends TestCase {

	/**
	 * The source of the class under test.
	 *
	 * @return string
	 */
	private function source(): string {
		$file = (string) ( new \ReflectionClass( PosterExtractor::class ) )->getFileName();

		return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads this plugin's own source file.
	}

	/**
	 * No shell, no external program, no process.
	 */
	public function test_no_shell_or_external_binary(): void {
		foreach ( array( 'exec(', 'shell_exec', 'passthru', 'proc_open', 'popen', 'system(', 'ffmpeg', 'ffprobe' ) as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $this->source(), $needle );
		}
	}

	/**
	 * No fixed path.
	 */
	public function test_no_hard_coded_path(): void {
		foreach ( array( 'C:\\', '/var/', '/home/', '/tmp/', '/usr/' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $this->source(), $needle );
		}
	}

	/**
	 * No reel field, no meta write, no stored state.
	 */
	public function test_no_binding_or_persistence(): void {
		foreach ( array( '_rj_poster_id', '_rj_', 'update_post_meta', 'add_post_meta', 'update_option', 'add_option', 'set_transient', 'wpdb', 'wp_cache_set' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $this->source(), $needle );
		}
	}

	/**
	 * The public surface is small: a contract and a way to receive an image.
	 */
	public function test_public_api(): void {
		$names = array_map( static fn ( \ReflectionMethod $m ): string => $m->getName(), ( new \ReflectionClass( PosterExtractor::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) );

		sort( $names );

		$this->assertSame( array( '__construct', 'accept', 'contract' ), $names );
	}
}
