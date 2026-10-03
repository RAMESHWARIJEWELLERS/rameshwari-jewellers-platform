<?php
/**
 * Source quality check tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Media;

use Rameshwari\Core\Services\Media\Sizes;
use Rameshwari\Core\Services\Media\SourceQuality;
use Rameshwari\Core\Services\Media\UploadValidator;
use Rameshwari\Core\Services\Media\Value\SourceQualityResult;

/**
 * Advisory source-size check against the registered sizes. Fixtures are headers only.
 */
final class SourceQualityTest extends \WP_UnitTestCase {

	/**
	 * Temp files to remove after each test.
	 *
	 * @var array<int,string>
	 */
	private array $files = array();

	/**
	 * Removes temp files.
	 */
	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		parent::tear_down();
	}

	/**
	 * Writes a PNG header that declares the given size and returns its path.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return string Path.
	 */
	private function png( int $width, int $height ): string {
		$chunk = static function ( string $type, string $data ): string {
			return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		};

		$path = wp_tempnam( 'rjquality' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local test fixture in the system temp directory.
		file_put_contents( $path, "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 ) ) . $chunk( 'IEND', '' ) );

		$this->files[] = $path;

		return $path;
	}

	/**
	 * A source at least as large as the requested size on both axes is adequate.
	 */
	public function test_source_adequate_for_requested_output(): void {
		$result = ( new SourceQuality( new Sizes() ) )->check( $this->png( 1200, 1500 ), array( 'detail', 'card', 'thumb' ) );

		$this->assertTrue( $result->is_adequate() );
		$this->assertFalse( $result->requires_confirmation() );
	}

	/**
	 * Exactly the registered size is adequate; one pixel short on one axis is not.
	 */
	public function test_boundary_is_the_registered_size_exactly(): void {
		$check = new SourceQuality( new Sizes() );

		$this->assertTrue( $check->check( $this->png( 600, 600 ), array( 'card' ) )->is_adequate() );
		$this->assertTrue( $check->check( $this->png( 600, 599 ), array( 'card' ) )->requires_confirmation() );
		$this->assertTrue( $check->check( $this->png( 599, 600 ), array( 'card' ) )->requires_confirmation() );
	}

	/**
	 * A source smaller than a requested output produces a warning naming it.
	 */
	public function test_smaller_source_produces_warning_naming_the_size(): void {
		$result = ( new SourceQuality( new Sizes() ) )->check( $this->png( 800, 800 ), array( 'card', 'hero', 'detail' ) );

		$this->assertTrue( $result->requires_confirmation() );
		$this->assertSame( array( 'hero', 'detail' ), $result->sizes() );
	}

	/**
	 * Only the requested sizes are judged.
	 */
	public function test_only_requested_sizes_are_considered(): void {
		$result = ( new SourceQuality( new Sizes() ) )->check( $this->png( 400, 400 ), array( 'thumb' ) );

		$this->assertTrue( $result->is_adequate() );
	}

	/**
	 * A too-small source still passes hard validation.
	 */
	public function test_warning_is_not_a_hard_validation_failure(): void {
		$path = $this->png( 100, 100 );

		$this->assertTrue( ( new UploadValidator() )->validate( $path, 'tiny.png', 'product_primary' )->is_valid() );
		$this->assertTrue( ( new SourceQuality( new Sizes() ) )->check( $path, array( 'hero' ) )->requires_confirmation() );
	}

	/**
	 * Thresholds come from Sizes, not from numbers held here.
	 */
	public function test_thresholds_come_from_the_registered_sizes(): void {
		$check = new SourceQuality( new Sizes() );

		foreach ( ( new Sizes() )->all() as $name => $definition ) {
			$this->assertTrue( $check->check( $this->png( $definition['width'], $definition['height'] ), array( $name ) )->is_adequate(), $name );
			$this->assertTrue( $check->check( $this->png( $definition['width'] - 1, $definition['height'] ), array( $name ) )->requires_confirmation(), $name );
		}

		$properties = ( new \ReflectionClass( SourceQuality::class ) )->getProperties();

		$this->assertSame( array( 'sizes' ), array_map( static fn ( \ReflectionProperty $p ): string => $p->getName(), $properties ) );
		$this->assertSame( array(), ( new \ReflectionClass( SourceQuality::class ) )->getConstants() );
	}

	/**
	 * An unknown size contract fails explicitly.
	 */
	public function test_unknown_size_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new SourceQuality( new Sizes() ) )->check( $this->png( 5000, 5000 ), array( 'card', 'rj_card' ) );
	}

	/**
	 * Asking about no size is refused, not treated as adequate.
	 */
	public function test_empty_size_list_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new SourceQuality( new Sizes() ) )->check( $this->png( 5000, 5000 ), array() );
	}

	/**
	 * A file that cannot be read as an image is an error, never a silent pass.
	 */
	public function test_unreadable_source_is_an_error_not_adequate(): void {
		$this->expectException( \RuntimeException::class );

		( new SourceQuality( new Sizes() ) )->check( '/nonexistent/none.png', array( 'card' ) );
	}

	/**
	 * Nothing is stored and no focal point exists.
	 */
	public function test_nothing_is_persisted(): void {
		$options = wp_load_alloptions( true );
		$before  = $options;

		( new SourceQuality( new Sizes() ) )->check( $this->png( 100, 100 ), array( 'hero', 'card' ) );

		$this->assertSame( $before, wp_load_alloptions( true ) );

		$methods = array_map(
			static fn ( \ReflectionMethod $m ): string => $m->getName(),
			( new \ReflectionClass( SourceQuality::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC )
		);

		$this->assertSame( array( '__construct', 'check' ), $methods );
	}

	/**
	 * A warning is never reported as adequate.
	 */
	public function test_no_silent_success_when_warning_applies(): void {
		$result = ( new SourceQuality( new Sizes() ) )->check( $this->png( 10, 10 ), array( 'thumb' ) );

		$this->assertInstanceOf( SourceQualityResult::class, $result );
		$this->assertFalse( $result->is_adequate() );
		$this->assertTrue( $result->requires_confirmation() );
		$this->assertSame( array( 'thumb' ), $result->sizes() );
	}
}
