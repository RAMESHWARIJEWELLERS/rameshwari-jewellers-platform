<?php
/**
 * Container tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\Container;
use WP_UnitTestCase;

/**
 * Covers laziness, sharing, missing ids and cycles.
 */
final class ContainerTest extends WP_UnitTestCase {

	/**
	 * A factory does not run until the service is requested.
	 */
	public function test_factory_is_lazy(): void {
		$calls     = 0;
		$container = new Container();
		$container->set(
			'svc',
			static function () use ( &$calls ): \stdClass {
				++$calls;
				return new \stdClass();
			}
		);

		$this->assertSame( 0, $calls );
		$container->get( 'svc' );
		$this->assertSame( 1, $calls );
	}

	/**
	 * The same instance is returned on every call.
	 */
	public function test_instance_is_shared(): void {
		$container = new Container();
		$container->set( 'svc', static fn(): \stdClass => new \stdClass() );

		$this->assertSame( $container->get( 'svc' ), $container->get( 'svc' ) );
	}

	/**
	 * An unregistered id throws.
	 */
	public function test_unknown_id_throws(): void {
		$this->expectException( \OutOfBoundsException::class );
		( new Container() )->get( 'absent' );
	}

	/**
	 * Services that depend on each other throw instead of recursing forever.
	 */
	public function test_cycle_throws(): void {
		$container = new Container();
		$container->set( 'a', static fn( Container $c ): mixed => $c->get( 'b' ) );
		$container->set( 'b', static fn( Container $c ): mixed => $c->get( 'a' ) );

		$this->expectException( \LogicException::class );
		$container->get( 'a' );
	}

	/**
	 * A built service cannot be redefined.
	 */
	public function test_redefine_after_build_throws(): void {
		$container = new Container();
		$container->set( 'svc', static fn(): int => 1 );
		$container->get( 'svc' );

		$this->expectException( \LogicException::class );
		$container->set( 'svc', static fn(): int => 2 );
	}
}
