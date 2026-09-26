<?php
/**
 * Service container.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core;

/**
 * Minimal service locator: lazy factories, one instance per id, no
 * reflection and no third-party dependency.
 */
final class Container {

	/**
	 * Registered factories, by id.
	 *
	 * @var array<string, callable(Container): mixed>
	 */
	private array $factories = array();

	/**
	 * Built instances, by id.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Ids being built right now, to detect cycles.
	 *
	 * @var array<string, true>
	 */
	private array $resolving = array();

	/**
	 * Registers a factory. The factory runs on first get(), not here.
	 *
	 * @param string                    $id      Service id.
	 * @param callable(Container): mixed $factory Builds the service.
	 * @return void
	 * @throws \LogicException When the service was already built.
	 */
	public function set( string $id, callable $factory ): void {
		if ( array_key_exists( $id, $this->instances ) ) {
			throw new \LogicException( esc_html( 'Service already built, cannot redefine: ' . $id ) );
		}

		$this->factories[ $id ] = $factory;
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Returns the service, building it on first use.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 * @throws \OutOfBoundsException When no factory is registered.
	 * @throws \LogicException       When services depend on each other in a cycle.
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \OutOfBoundsException( esc_html( 'No service registered: ' . $id ) );
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new \LogicException( esc_html( 'Circular service dependency at: ' . $id ) );
		}

		$this->resolving[ $id ] = true;

		try {
			$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		return $this->instances[ $id ];
	}
}
