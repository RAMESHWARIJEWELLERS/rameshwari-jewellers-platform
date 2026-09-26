<?php
/**
 * Module registry tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use Rameshwari\Core\ModuleRegistry;
use Rameshwari\Tests\Fixtures\Modules\BaseModule;
use Rameshwari\Tests\Fixtures\Modules\CycleAModule;
use Rameshwari\Tests\Fixtures\Modules\CycleBModule;
use Rameshwari\Tests\Fixtures\Modules\DependentModule;
use Rameshwari\Tests\Fixtures\Modules\DuplicateBaseModule;
use Rameshwari\Tests\Fixtures\Modules\OrphanModule;
use WP_UnitTestCase;

/**
 * Covers dependency ordering and every rejection path.
 */
final class ModuleRegistryTest extends WP_UnitTestCase {

	/**
	 * A dependency comes first even when added second.
	 */
	public function test_dependency_ordered_first(): void {
		$registry = new ModuleRegistry();
		$registry->add( DependentModule::class );
		$registry->add( BaseModule::class );

		$this->assertSame( array( BaseModule::class, DependentModule::class ), $registry->sorted() );
	}

	/**
	 * An empty registry sorts to an empty list. This is the Stage 1 state.
	 */
	public function test_empty_registry(): void {
		$this->assertSame( array(), ( new ModuleRegistry() )->sorted() );
	}

	/**
	 * A dependency that was never added is rejected.
	 */
	public function test_missing_dependency_throws(): void {
		$registry = new ModuleRegistry();
		$registry->add( OrphanModule::class );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'orphan -&gt; absent' );
		$registry->sorted();
	}

	/**
	 * A dependency cycle is rejected with the path in the message.
	 */
	public function test_cycle_throws(): void {
		$registry = new ModuleRegistry();
		$registry->add( CycleAModule::class );
		$registry->add( CycleBModule::class );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'cycle-a -&gt; cycle-b -&gt; cycle-a' );
		$registry->sorted();
	}

	/**
	 * Two classes claiming one id are rejected.
	 */
	public function test_duplicate_id_throws(): void {
		$registry = new ModuleRegistry();
		$registry->add( BaseModule::class );

		$this->expectException( \LogicException::class );
		$registry->add( DuplicateBaseModule::class );
	}

	/**
	 * A class that is not a module is rejected.
	 */
	public function test_non_module_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new ModuleRegistry() )->add( \stdClass::class );
	}
}
