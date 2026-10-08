<?php
/**
 * Page section source guards.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;

/**
 * Stage 9 adds no schedule, SQL, global flush, device detection or registration.
 */
final class PageSectionSourceTest extends TestCase {

	private const FILES = array( 'HeroReader.php', 'HeroSelection.php', 'PageSection.php', 'PageSectionModule.php', 'PageSectionRepository.php' );

	/**
	 * The source of one Stage 9 file.
	 *
	 * @param string $file File name.
	 * @return string
	 */
	private function source( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source file.
		return (string) file_get_contents( __DIR__ . '/../../../plugin/rameshwari-core/src/Domain/PageSection/' . $file );
	}

	/**
	 * Exactly the five approved files exist.
	 */
	public function test_the_directory_holds_the_five_files(): void {
		$found = array_map( 'basename', (array) glob( __DIR__ . '/../../../plugin/rameshwari-core/src/Domain/PageSection/*.php' ) );

		sort( $found );

		$this->assertSame( self::FILES, $found );
	}

	/**
	 * None of the files uses a forbidden API.
	 */
	public function test_no_forbidden_apis(): void {
		$banned = array( 'wp_schedule_event(', 'wp_schedule_single_event(', 'wp_cache_flush(', '$wpdb', 'wp_is_mobile', 'HTTP_USER_AGENT', 'update_option(', 'add_option(', 'set_transient(', 'register_post_type(', 'register_taxonomy(', 'register_meta(', 'register_post_meta(', 'register_rest_route(', 'add_menu_page(', 'dbDelta(', 'wp_remote_', 'update_post_meta(' );

		foreach ( self::FILES as $file ) {
			foreach ( $banned as $token ) {
				$this->assertStringNotContainsString( $token, $this->source( $file ), $file . ' uses ' . $token );
			}
		}
	}

	/**
	 * The model and the result never read the clock, the timezone setting or the request.
	 */
	public function test_model_does_not_read_time_or_request(): void {
		foreach ( array( 'PageSection.php', 'HeroSelection.php' ) as $file ) {
			$this->assertSame( 0, preg_match( '/\btime\(|current_time\(|wp_timezone\(|\$_(SERVER|GET|POST|COOKIE)|\'now\'/', $this->source( $file ) ), $file );
		}
	}

	/**
	 * The cache holds no resolved URL and the reader renders nothing.
	 */
	public function test_reader_resolves_no_urls_and_renders_nothing(): void {
		foreach ( array( 'HeroReader.php', 'PageSection.php', 'PageSectionRepository.php' ) as $file ) {
			$this->assertSame( 0, preg_match( '/wp_get_attachment|get_permalink|home_url\(|esc_url|echo |printf\(/', $this->source( $file ) ), $file );
		}
	}

	/**
	 * Ttl_for bounds the lifetime to one second at least and 3600 at most.
	 */
	public function test_ttl_bounds(): void {
		$cases = array(
			array( null, 100, 3600 ),
			array( 101, 100, 1 ),
			array( 100, 100, 1 ),
			array( 90, 100, 1 ),
			array( 1900, 100, 1800 ),
			array( 3700, 100, 3600 ),
			array( 3701, 100, 3600 ),
			array( 999999, 100, 3600 ),
		);

		foreach ( $cases as list( $boundary, $now, $expected ) ) {
			$this->assertSame( $expected, \Rameshwari\Core\Domain\PageSection\HeroReader::ttl_for( $boundary, $now ), gettype( $boundary ) );
		}
	}
}
