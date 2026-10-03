<?php
/**
 * Image loading hints.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Services\Media\Value\LoadingHint;

/**
 * Decides how an image loads and which dimensions it declares (blueprint §8).
 *
 * The caller says whether the image is above the fold. This class never counts
 * rows or estimates a viewport position. It builds no markup.
 */
final class Loading {

	/**
	 * Builds the service.
	 *
	 * @param Sizes $sizes The registered size contract.
	 */
	public function __construct( private readonly Sizes $sizes ) {
	}

	/**
	 * Loading attributes for one image.
	 *
	 * Hero and above-the-fold images load eagerly and are prioritised. Everything
	 * else is lazy and decoded asynchronously, with no priority claim.
	 *
	 * @param bool $above_fold Whether the caller places the image above the fold.
	 * @param bool $is_hero    Whether the image is a hero image.
	 * @return LoadingHint
	 */
	public function hint( bool $above_fold, bool $is_hero ): LoadingHint {
		if ( $above_fold || $is_hero ) {
			return new LoadingHint( 'eager', 'high', 'auto' );
		}

		return new LoadingHint( 'lazy', 'auto', 'async' );
	}

	/**
	 * The width and height a renderer declares, taken from the registered size.
	 *
	 * @param string $contract_name Registered size contract name.
	 * @return array{width:int,height:int}
	 * @throws \InvalidArgumentException When the contract name is not registered.
	 */
	public function dimensions( string $contract_name ): array {
		if ( ! $this->sizes->exists( $contract_name ) ) {
			throw new \InvalidArgumentException( 'Unknown media size contract name.' );
		}

		$definition = $this->sizes->all()[ $contract_name ];

		return array(
			'width'  => $definition['width'],
			'height' => $definition['height'],
		);
	}
}
