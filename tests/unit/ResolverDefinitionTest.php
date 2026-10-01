<?php
/**
 * Resolver definition tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rameshwari\Core\Category\ResolverDefinition;

/**
 * Shape A and Shape B validation.
 */
final class ResolverDefinitionTest extends TestCase {

	private const GOLD = 'jewellery/gold-jewellery/mahila-ke-abhushan/sir-ke-abhushan/tika';

	private const SILVER = 'jewellery/silver-jewellery/mahila-ke-abhushan/sir-ke-abhushan/tika';

	/**
	 * Shape A parses to a facet definition.
	 */
	public function test_shape_a_is_a_facet(): void {
		$definition = ResolverDefinition::parse(
			array(
				'tax'  => 'rj_metal',
				'slug' => 'gold',
			)
		);

		$this->assertSame( ResolverDefinition::FACET, $definition->kind() );
		$this->assertSame( 'rj_metal', $definition->tax() );
		$this->assertSame( 'gold', $definition->slug() );
		$this->assertSame(
			array(
				'tax'  => 'rj_metal',
				'slug' => 'gold',
			),
			$definition->to_array()
		);
	}

	/**
	 * Shape B keeps its portable paths and may carry no IDs yet.
	 */
	public function test_shape_b_is_a_union_of_paths(): void {
		$definition = ResolverDefinition::parse(
			array(
				'terms' => array(),
				'paths' => array( self::GOLD, self::SILVER ),
			)
		);

		$this->assertSame( ResolverDefinition::UNION, $definition->kind() );
		$this->assertSame( array( self::GOLD, self::SILVER ), $definition->paths() );
		$this->assertSame( array(), $definition->terms() );
		$this->assertSame( array( 7, 9 ), $definition->with_terms( array( 7, 9 ) )->terms() );
	}

	/**
	 * Every malformed value is rejected, never repaired.
	 */
	public function test_malformed_values_are_rejected(): void {
		$bad = array(
			'empty string'      => '',
			'scalar'            => 'gold',
			'empty array'       => array(),
			'unknown taxonomy'  => array(
				'tax'  => 'rj_category',
				'slug' => 'gold',
			),
			'unapproved facet'  => array(
				'tax'  => 'rj_tag',
				'slug' => 'gold',
			),
			'uppercase slug'    => array(
				'tax'  => 'rj_metal',
				'slug' => 'Gold Bar',
			),
			'missing slug'      => array( 'tax' => 'rj_metal' ),
			'extra key'         => array(
				'tax'   => 'rj_metal',
				'slug'  => 'gold',
				'extra' => 1,
			),
			'one path'          => array(
				'terms' => array(),
				'paths' => array( self::GOLD ),
			),
			'duplicate paths'   => array(
				'terms' => array(),
				'paths' => array( self::GOLD, self::GOLD ),
			),
			'malformed path'    => array(
				'terms' => array(),
				'paths' => array( self::GOLD, 'Jewellery/Gold' ),
			),
			'non-string path'   => array(
				'terms' => array(),
				'paths' => array( self::GOLD, 5 ),
			),
			'string term id'    => array(
				'terms' => array( '1', '2' ),
				'paths' => array( self::GOLD, self::SILVER ),
			),
			'zero term id'      => array(
				'terms' => array( 0, 2 ),
				'paths' => array( self::GOLD, self::SILVER ),
			),
			'terms length diff' => array(
				'terms' => array( 1 ),
				'paths' => array( self::GOLD, self::SILVER ),
			),
		);

		foreach ( $bad as $label => $value ) {
			try {
				ResolverDefinition::parse( $value );
				$this->fail( "Accepted: {$label}" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage(), $label );
			}
		}
	}
}
