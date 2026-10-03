<?php
/**
 * Source quality check.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

use Rameshwari\Core\Services\Media\Value\SourceQualityResult;

/**
 * Advisory check of a source image against registered output sizes (CL-8).
 *
 * Run it after hard validation. It never rejects, resizes, upscales or stores
 * anything. The only thresholds are the dimensions held by Sizes; this class
 * adds no minimum of its own.
 */
final class SourceQuality {

	/**
	 * Builds the check.
	 *
	 * @param Sizes $sizes The registered size contract.
	 */
	public function __construct( private readonly Sizes $sizes ) {
	}

	/**
	 * Judges a source against the requested registered sizes.
	 *
	 * A size is flagged when the source is smaller than it on either axis.
	 *
	 * @param string            $source_path Path of a file that already passed hard validation.
	 * @param array<int,string> $contract_names Registered size contract names to judge against.
	 * @return SourceQualityResult
	 * @throws \InvalidArgumentException When no size is requested or a name is not registered.
	 * @throws \RuntimeException When the source dimensions cannot be read.
	 */
	public function check( string $source_path, array $contract_names ): SourceQualityResult {
		if ( array() === $contract_names ) {
			throw new \InvalidArgumentException( 'At least one size must be requested.' );
		}

		$definitions = $this->sizes->all();

		foreach ( $contract_names as $name ) {
			if ( ! $this->sizes->exists( $name ) ) {
				throw new \InvalidArgumentException( 'Unknown media size contract name.' );
			}
		}

		$dimensions = is_file( $source_path ) ? wp_getimagesize( $source_path ) : false;

		if ( ! is_array( $dimensions ) || $dimensions[0] < 1 || $dimensions[1] < 1 ) {
			throw new \RuntimeException( 'The source dimensions could not be read.' );
		}

		$short = array();

		foreach ( $contract_names as $name ) {
			if ( $dimensions[0] < $definitions[ $name ]['width'] || $dimensions[1] < $definitions[ $name ]['height'] ) {
				$short[] = $name;
			}
		}

		return array() === $short ? SourceQualityResult::adequate() : SourceQualityResult::warning( $short );
	}
}
