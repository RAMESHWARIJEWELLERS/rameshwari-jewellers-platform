<?php
/**
 * Showroom open-now read service.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\Showroom;

/**
 * Answers whether a published showroom is open right now.
 *
 * Read only: it loads the showroom through the repository, takes the current time
 * in the site timezone and asks the model. Nothing is written or cached, and every
 * problem (not a showroom, not published, unreadable data, a failing clock) is
 * reported as UNAVAILABLE rather than raised to the visitor.
 */
final class ShowroomOpenNow {

	/**
	 * Showroom reader.
	 *
	 * @var ShowroomRepository
	 */
	private ShowroomRepository $repository;

	/**
	 * Clock returning the current time as a DateTimeImmutable.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Builds the service.
	 *
	 * @param ShowroomRepository|null $repository Showroom reader. Defaults to the real one.
	 * @param callable|null           $clock      Returns a DateTimeImmutable. Defaults to now in the site timezone.
	 */
	public function __construct( ?ShowroomRepository $repository = null, ?callable $clock = null ) {
		$this->repository = $repository ?? new ShowroomRepository();
		$this->clock      = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable( 'now', wp_timezone() );
	}

	/**
	 * Open-now state of a showroom: one of the Showroom STATE_ constants.
	 *
	 * @param int $id Showroom post ID.
	 * @return string
	 */
	public function state( int $id ): string {
		if ( $id < 1 ) {
			return Showroom::STATE_UNAVAILABLE;
		}

		try {
			$showroom = $this->repository->find( $id );
			$now      = ( $this->clock )();
		} catch ( \Throwable $failure ) {
			return Showroom::STATE_UNAVAILABLE;
		}

		if ( null === $showroom || 'publish' !== $showroom->status || ! $now instanceof \DateTimeImmutable ) {
			return Showroom::STATE_UNAVAILABLE;
		}

		return $showroom->state_at( $now );
	}

	/**
	 * Whether a showroom is open right now.
	 *
	 * @param int $id Showroom post ID.
	 * @return bool
	 */
	public function is_open( int $id ): bool {
		return Showroom::STATE_OPEN === $this->state( $id );
	}
}
