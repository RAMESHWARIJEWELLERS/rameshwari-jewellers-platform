<?php
/**
 * Registered media sizes.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

/**
 * The single source of truth for the five registered image sizes.
 *
 * Consumers use the contract name ("card"), never the WordPress key or a pixel
 * size. Registration is plugin-owned so a theme change cannot orphan the sizes.
 */
final class Sizes {

	/**
	 * Size definitions. "hard" crops to the exact box; "soft" also produces the
	 * exact box but is the position-preserving mode (CL-3). No focal point is stored.
	 *
	 * @var array<string,array{width:int,height:int,crop:string,key:string}>
	 */
	private const DEFINITIONS = array(
		'thumb'       => array(
			'width'  => 300,
			'height' => 300,
			'crop'   => 'hard',
			'key'    => 'rj_thumb',
		),
		'card'        => array(
			'width'  => 600,
			'height' => 600,
			'crop'   => 'hard',
			'key'    => 'rj_card',
		),
		'detail'      => array(
			'width'  => 1200,
			'height' => 1500,
			'crop'   => 'soft',
			'key'    => 'rj_detail',
		),
		'hero'        => array(
			'width'  => 1920,
			'height' => 1200,
			'crop'   => 'soft',
			'key'    => 'rj_hero',
		),
		'reel poster' => array(
			'width'  => 720,
			'height' => 1280,
			'crop'   => 'hard',
			'key'    => 'rj_reel_poster',
		),
	);

	/**
	 * Every definition, keyed by contract name.
	 *
	 * @return array<string,array{width:int,height:int,crop:string,key:string}>
	 */
	public function all(): array {
		return self::DEFINITIONS;
	}

	/**
	 * Whether a contract name is registered.
	 *
	 * @param string $contract_name Contract name.
	 * @return bool
	 */
	public function exists( string $contract_name ): bool {
		return isset( self::DEFINITIONS[ $contract_name ] );
	}

	/**
	 * The WordPress size key for a contract name.
	 *
	 * @param string $contract_name Contract name.
	 * @return string
	 * @throws \InvalidArgumentException When the name is not a registered size.
	 */
	public function key( string $contract_name ): string {
		if ( ! isset( self::DEFINITIONS[ $contract_name ] ) ) {
			throw new \InvalidArgumentException( 'Unknown media size contract name.' );
		}

		return self::DEFINITIONS[ $contract_name ]['key'];
	}

	/**
	 * Registers the five sizes with WordPress. Safe to call more than once.
	 *
	 * Every size is registered in cropping mode so each output has its exact
	 * dimensions. Callable on its own; nothing wires it to a hook yet.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::DEFINITIONS as $definition ) {
			add_image_size(
				$definition['key'],
				$definition['width'],
				$definition['height'],
				'hard' === $definition['crop'] ? true : array( 'center', 'center' )
			);
		}
	}
}
