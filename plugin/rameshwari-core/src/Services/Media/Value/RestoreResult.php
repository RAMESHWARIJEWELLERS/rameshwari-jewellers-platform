<?php
/**
 * Outcome of a restore.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media\Value;

/**
 * Either a restored version with its new dimensions and the artifact that undoes it, or a failure code.
 */
final class RestoreResult {

	/**
	 * Builds the result.
	 *
	 * @param bool   $success       Whether the restore finished.
	 * @param string $code          Failure code, empty on success.
	 * @param int    $width         Width of the restored image, 0 on failure.
	 * @param int    $height        Height of the restored image, 0 on failure.
	 * @param string $restored      Artifact that was restored, empty on failure.
	 * @param string $undo_artifact Artifact holding the file that was replaced, empty on failure.
	 * @throws \InvalidArgumentException When the fields do not fit a success or a failure.
	 */
	private function __construct( private readonly bool $success, private readonly string $code, private readonly int $width, private readonly int $height, private readonly string $restored, private readonly string $undo_artifact ) {
		if ( $success && ( '' !== $code || $width < 1 || $height < 1 || '' === trim( $restored ) || '' === trim( $undo_artifact ) ) ) {
			throw new \InvalidArgumentException( 'A successful restore needs dimensions and both artifact names, and no code.' );
		}

		if ( ! $success && ( '' === trim( $code ) || 0 !== $width || 0 !== $height || '' !== $restored || '' !== $undo_artifact ) ) {
			throw new \InvalidArgumentException( 'A failed restore needs a code and nothing else.' );
		}
	}

	/**
	 * A finished restore.
	 *
	 * @param int    $width         Restored width.
	 * @param int    $height        Restored height.
	 * @param string $restored      Artifact that was restored.
	 * @param string $undo_artifact Artifact that holds the replaced file.
	 * @return self
	 */
	public static function succeeded( int $width, int $height, string $restored, string $undo_artifact ): self {
		return new self( true, '', $width, $height, $restored, $undo_artifact );
	}

	/**
	 * A restore that changed nothing.
	 *
	 * @param string $code Failure code.
	 * @return self
	 */
	public static function failed( string $code ): self {
		return new self( false, $code, 0, 0, '', '' );
	}

	/**
	 * Whether the restore finished.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Failure code, empty on success.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Restored width, 0 on failure.
	 *
	 * @return int
	 */
	public function width(): int {
		return $this->width;
	}

	/**
	 * Restored height, 0 on failure.
	 *
	 * @return int
	 */
	public function height(): int {
		return $this->height;
	}

	/**
	 * The artifact that was restored, empty on failure.
	 *
	 * @return string
	 */
	public function restored(): string {
		return $this->restored;
	}

	/**
	 * The artifact that holds the file the restore replaced, empty on failure.
	 *
	 * @return string
	 */
	public function undo_artifact(): string {
		return $this->undo_artifact;
	}
}
