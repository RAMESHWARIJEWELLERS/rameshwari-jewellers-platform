<?php
/**
 * Module registry.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core;

/**
 * Holds the module list and returns it in dependency order.
 */
final class ModuleRegistry {

	/**
	 * Module classes, by module id.
	 *
	 * @var array<string, class-string<Module>>
	 */
	private array $modules = array();

	/**
	 * Adds a module class.
	 *
	 * @param class-string<Module> $module_class Class implementing Module.
	 * @return void
	 * @throws \InvalidArgumentException When the class is not a Module.
	 * @throws \LogicException           When the id is already taken.
	 */
	public function add( string $module_class ): void {
		if ( ! is_subclass_of( $module_class, Module::class ) ) {
			throw new \InvalidArgumentException( esc_html( 'Not a module: ' . $module_class ) );
		}

		$id = $module_class::id();

		if ( isset( $this->modules[ $id ] ) ) {
			throw new \LogicException( esc_html( 'Duplicate module id: ' . $id ) );
		}

		$this->modules[ $id ] = $module_class;
	}

	/**
	 * Module classes ordered so every module follows its dependencies.
	 *
	 * @return array<int, class-string<Module>>
	 * @throws \LogicException On a missing dependency or a dependency cycle.
	 */
	public function sorted(): array {
		$ordered = array();
		$state   = array();

		foreach ( array_keys( $this->modules ) as $id ) {
			$this->visit( $id, $state, $ordered, array() );
		}

		return $ordered;
	}

	/**
	 * Depth-first visit.
	 *
	 * @param string                           $id      Module id.
	 * @param array<string, string>            $state   'visiting' or 'done', by id.
	 * @param array<int, class-string<Module>> $ordered Output list.
	 * @param array<int, string>               $path    Ids on the current path, for the error message.
	 * @return void
	 * @throws \LogicException On a missing dependency or a cycle.
	 */
	private function visit( string $id, array &$state, array &$ordered, array $path ): void {
		if ( 'done' === ( $state[ $id ] ?? '' ) ) {
			return;
		}

		$path[] = $id;

		if ( 'visiting' === ( $state[ $id ] ?? '' ) ) {
			throw new \LogicException( esc_html( 'Module dependency cycle: ' . implode( ' -> ', $path ) ) );
		}

		if ( ! isset( $this->modules[ $id ] ) ) {
			throw new \LogicException( esc_html( 'Missing module dependency: ' . implode( ' -> ', $path ) ) );
		}

		$state[ $id ] = 'visiting';

		foreach ( $this->modules[ $id ]::requires() as $dependency ) {
			$this->visit( $dependency, $state, $ordered, $path );
		}

		$state[ $id ] = 'done';
		$ordered[]    = $this->modules[ $id ];
	}
}
