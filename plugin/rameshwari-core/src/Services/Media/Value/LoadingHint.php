<?php
/**
 * Loading hint.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * How one image should load: the three HTML attributes the blueprint names.
 * The Loading service decides the values; this type only guarantees they are
 * valid.
 */
final class LoadingHint {

	private const LOADING = array( 'eager', 'lazy' );

	private const PRIORITY = array( 'high', 'low', 'auto' );

	private const DECODING = array( 'async', 'sync', 'auto' );

	/**
	 * Builds a hint.
	 *
	 * @param string $loading       eager or lazy.
	 * @param string $fetchpriority high, low or auto.
	 * @param string $decoding      async, sync or auto.
	 * @throws \InvalidArgumentException When a value is not an HTML attribute value.
	 */
	public function __construct( private readonly string $loading, private readonly string $fetchpriority, private readonly string $decoding ) {
		if ( ! in_array( $loading, self::LOADING, true ) ) {
			throw new \InvalidArgumentException( 'loading must be eager or lazy.' );
		}

		if ( ! in_array( $fetchpriority, self::PRIORITY, true ) ) {
			throw new \InvalidArgumentException( 'fetchpriority must be high, low or auto.' );
		}

		if ( ! in_array( $decoding, self::DECODING, true ) ) {
			throw new \InvalidArgumentException( 'decoding must be async, sync or auto.' );
		}
	}

	/**
	 * The loading attribute.
	 *
	 * @return string
	 */
	public function loading(): string {
		return $this->loading;
	}

	/**
	 * The fetchpriority attribute.
	 *
	 * @return string
	 */
	public function fetchpriority(): string {
		return $this->fetchpriority;
	}

	/**
	 * The decoding attribute.
	 *
	 * @return string
	 */
	public function decoding(): string {
		return $this->decoding;
	}

	/**
	 * Value equality.
	 *
	 * @param LoadingHint $other Other hint.
	 * @return bool
	 */
	public function equals( LoadingHint $other ): bool {
		return $this->loading === $other->loading && $this->fetchpriority === $other->fetchpriority && $this->decoding === $other->decoding;
	}
}
