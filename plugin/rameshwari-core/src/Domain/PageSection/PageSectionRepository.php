<?php
/**
 * Page section persistence.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\PageSection;

use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Support\Logger;
use Rameshwari\Core\Support\Sanitizer;

/**
 * Persistence only. Loads published page sections from their posts and
 * registered meta. It decides nothing about eligibility, order or caching and
 * it adds no key, post type or table.
 */
final class PageSectionRepository {

	public const TYPE = 'rj_page_section';

	/**
	 * Hard cap on the sections read at once. The approved scale is 200 records.
	 */
	public const CAP = 200;

	/**
	 * Builds the repository.
	 *
	 * @param Logger $logger Diagnostics.
	 * @param int    $cap    Most sections read at once.
	 * @throws \InvalidArgumentException When the cap is below one.
	 */
	public function __construct( private readonly Logger $logger, private readonly int $cap = self::CAP ) {
		if ( $cap < 1 ) {
			throw new \InvalidArgumentException( 'The candidate cap must be at least one.' );
		}
	}

	/**
	 * Every published page section, oldest ID first.
	 *
	 * @return array<int,PageSection>
	 * @throws \OverflowException When there are more sections than the cap, so no partial list is returned.
	 */
	public function candidates(): array {
		$posts = get_posts(
			array(
				'post_type'              => self::TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => $this->cap + 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		if ( count( $posts ) > $this->cap ) {
			$this->logger->error( 'Page section candidate cap exceeded.', array( 'cap' => $this->cap ) );

			throw new \OverflowException( 'More page sections than the candidate cap.' );
		}

		$sections = array();

		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$sections[] = $this->hydrate( $post );
			}
		}

		return $sections;
	}

	/**
	 * Builds a section from a post, tolerating missing and legacy meta.
	 *
	 * @param \WP_Post $post Page section post.
	 * @return PageSection
	 */
	private function hydrate( \WP_Post $post ): PageSection {
		$id = $post->ID;

		return new PageSection(
			$id,
			$post->post_status,
			$this->text( $id, '_rj_section_key' ),
			$this->text( $id, '_rj_title_hi' ),
			$this->text( $id, '_rj_title_en' ),
			$this->text( $id, '_rj_description' ),
			$this->text( $id, '_rj_cta_label' ),
			$this->text( $id, '_rj_cta_url' ),
			Sanitizer::int( $this->stored( $id, '_rj_image_desktop' ), 0, 0 ),
			Sanitizer::int( $this->stored( $id, '_rj_image_mobile' ), 0, 0 ),
			Sanitizer::bool( $this->stored( $id, '_rj_active' ) ),
			$this->text( $id, '_rj_starts_at' ),
			$this->text( $id, '_rj_ends_at' ),
			PageSection::order_from( $this->stored( $id, '_rj_order' ) )
		);
	}

	/**
	 * One meta value as a string.
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Meta key.
	 * @return string
	 */
	private function text( int $id, string $key ): string {
		$value = $this->stored( $id, $key );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A stored meta value, or the registered default when the key was never written.
	 *
	 * A value that exists is returned as it is, so an invalid legacy value stays
	 * visible to the caller. The default comes from the Stage 4 meta registry.
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Meta key.
	 * @return mixed
	 */
	private function stored( int $id, string $key ): mixed {
		if ( metadata_exists( 'post', $id, $key ) ) {
			return get_post_meta( $id, $key, true );
		}

		$spec = Meta::post_meta()[ self::TYPE ][ $key ] ?? null;

		return null === $spec ? '' : $spec[1];
	}
}
