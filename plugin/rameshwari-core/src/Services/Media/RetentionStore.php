<?php
/**
 * Retention store.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Services\Media;

/**
 * Keeps replaced originals as plain files for a limited time (blueprint §11.4).
 *
 * Artifacts live in a plugin-owned folder under the upload base and are named
 * {attachment-id}-{timestamp}-{random-token}-{sanitised-basename}. They are
 * files only: no database row, option or meta records them, and they are never
 * a reference to anything. The attachment ID stays the single identity.
 *
 * This class knows nothing about products, categories or reels. It does not
 * lock; the Replacer that uses it does.
 */
final class RetentionStore {

	/**
	 * Folder name under the upload base.
	 */
	public const DIRECTORY = 'rj-retention';

	private const PATTERN = '~^(\d+)-(\d{1,12})-([a-f0-9]{16})-([^[:cntrl:]/\\\\]+)$~D';

	private const MAX_SCAN = 5000;

	private const DAY = 86400;

	private const BASENAME_LIMIT = 120;

	/**
	 * Source of "now" as a Unix timestamp.
	 *
	 * @var \Closure
	 */
	private \Closure $clock;

	/**
	 * Builds the store.
	 *
	 * @param callable(): int|null $clock Returns the current Unix time. Defaults to time().
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = null === $clock ? static fn (): int => time() : \Closure::fromCallable( $clock );
	}

	/**
	 * Copies the attachment's current file into the retention folder.
	 *
	 * @param int $id Attachment ID.
	 * @return string|null The artifact file name, or null when nothing could be kept.
	 */
	public function keep( int $id ): ?string {
		$source = $this->current_file( $id );
		$dir    = $this->directory( true );

		if ( null === $source || null === $dir ) {
			return null;
		}

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			try {
				$name = $id . '-' . (int) ( $this->clock )() . '-' . bin2hex( random_bytes( 8 ) ) . '-' . $this->safe_basename( $source );
			} catch ( \Exception $failure ) {
				return null;
			}

			$target = $dir . '/' . $name;

			if ( file_exists( $target ) ) {
				continue;
			}

			if ( ! copy( $source, $target ) ) {
				return null;
			}

			if ( filesize( $target ) !== filesize( $source ) ) {
				wp_delete_file( $target );

				return null;
			}

			return $name;
		}

		return null;
	}

	/**
	 * Artifact names for one attachment, newest first. Ties are broken by name, descending.
	 *
	 * @param int $id Attachment ID.
	 * @return array<int,string>
	 */
	public function list( int $id ): array {
		$found = array_filter(
			$this->entries(),
			static fn ( array $entry ): bool => $entry['id'] === $id
		);

		usort(
			$found,
			static fn ( array $a, array $b ): int => array( $b['time'], $b['name'] ) <=> array( $a['time'], $a['name'] )
		);

		return array_values( array_map( static fn ( array $entry ): string => $entry['name'], $found ) );
	}

	/**
	 * Puts an artifact back as the attachment's current file.
	 *
	 * The file being replaced is kept first as a new artifact, so a restore can be
	 * undone. The chosen artifact stays until clean-up. Derived sizes are not
	 * rebuilt here.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $name Artifact file name from list() or keep().
	 * @return bool True when the file was restored.
	 */
	public function restore( int $id, string $name ): bool {
		$artifact = $this->artifact_path( $id, $name );
		$current  = $this->current_file( $id );

		if ( null === $artifact || null === $current ) {
			return false;
		}

		if ( null === $this->keep( $id ) ) {
			return false;
		}

		$temp = $current . '.rjtmp';

		if ( file_exists( $temp ) ) {
			wp_delete_file( $temp );
		}

		if ( ! copy( $artifact, $temp ) ) {
			return false;
		}

		if ( ! rename( $temp, $current ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-folder swap of a verified copy; the previous file is already kept.
			wp_delete_file( $temp );

			return false;
		}

		return true;
	}

	/**
	 * Removes artifacts strictly older than the given number of days, oldest first.
	 *
	 * @param int $days  Age threshold in days.
	 * @param int $limit Most artifacts to remove in this call.
	 * @return int Number removed.
	 * @throws \InvalidArgumentException When days or limit is negative.
	 */
	public function purge_older_than( int $days, int $limit ): int {
		if ( $days < 0 || $limit < 0 ) {
			throw new \InvalidArgumentException( 'Days and limit cannot be negative.' );
		}

		if ( 0 === $limit ) {
			return 0;
		}

		$cutoff = (int) ( $this->clock )() - $days * self::DAY;
		$old    = array_filter(
			$this->entries(),
			static fn ( array $entry ): bool => $entry['time'] < $cutoff
		);

		usort(
			$old,
			static fn ( array $a, array $b ): int => array( $a['time'], $a['name'] ) <=> array( $b['time'], $b['name'] )
		);

		$removed = 0;

		foreach ( array_slice( $old, 0, $limit ) as $entry ) {
			if ( $this->remove( $entry['name'] ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Removes every artifact that belongs to one attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return int Number removed.
	 */
	public function purge_for( int $id ): int {
		$removed = 0;

		foreach ( $this->list( $id ) as $name ) {
			if ( $this->remove( $name ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * The retention folder, optionally created with its guard files.
	 *
	 * @param bool $create Create it when missing, and make sure its guard files exist.
	 * @return string|null Path without trailing slash, or null when unavailable.
	 */
	private function directory( bool $create ): ?string {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || ! is_string( $uploads['basedir'] ) || '' === $uploads['basedir'] ) {
			return null;
		}

		$dir = rtrim( str_replace( '\\', '/', $uploads['basedir'] ), '/' ) . '/' . self::DIRECTORY;

		if ( $create ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return null;
			}

			$this->provision_guards( $dir );
		}

		return $dir;
	}

	/**
	 * Adds the empty index file and the deny rule when they are missing. Existing files are never overwritten.
	 *
	 * The deny rule is defence in depth only. Whether a web server honours it depends on the host, so
	 * nothing relies on it: every path is still validated and kept inside the folder.
	 *
	 * @param string $dir Retention folder.
	 * @return void
	 */
	private function provision_guards( string $dir ): void {
		$files = array(
			'index.php' => '',
			'.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
		);

		foreach ( $files as $file => $content ) {
			if ( ! file_exists( $dir . '/' . $file ) ) {
				file_put_contents( $dir . '/' . $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes the empty index file and the deny rule that guard the retention folder.
			}
		}
	}

	/**
	 * The attachment's current readable file, or null.
	 *
	 * @param int $id Attachment ID.
	 * @return string|null
	 */
	private function current_file( int $id ): ?string {
		if ( $id < 1 || 'attachment' !== get_post_type( $id ) ) {
			return null;
		}

		$file = get_attached_file( $id );

		if ( ! is_string( $file ) || '' === $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}

		return $file;
	}

	/**
	 * A safe single-component file name taken from a path.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function safe_basename( string $path ): string {
		$base = sanitize_file_name( basename( str_replace( '\\', '/', $path ) ) );
		$base = (string) preg_replace( '~[[:cntrl:]/\\\\]~', '', $base );
		$base = substr( $base, 0, self::BASENAME_LIMIT );

		return '' === trim( $base, '.' ) ? 'file' : $base;
	}

	/**
	 * Splits an artifact name into its parts.
	 *
	 * @param string $name File name.
	 * @return array{id:int,time:int,name:string}|null Null when the name does not follow the pattern.
	 */
	private function parse( string $name ): ?array {
		if ( 1 !== preg_match( self::PATTERN, $name, $parts ) ) {
			return null;
		}

		return array(
			'id'   => (int) $parts[1],
			'time' => (int) $parts[2],
			'name' => $name,
		);
	}

	/**
	 * Every well-formed artifact in the folder, up to a scan cap. Directories, links and strangers are skipped.
	 *
	 * @return array<int,array{id:int,time:int,name:string}>
	 */
	private function entries(): array {
		$dir = $this->directory( false );

		if ( null === $dir || ! is_dir( $dir ) ) {
			return array();
		}

		$found   = array();
		$scanned = 0;

		try {
			foreach ( new \DirectoryIterator( $dir ) as $item ) {
				if ( $item->isDot() ) {
					continue;
				}

				if ( ++$scanned > self::MAX_SCAN ) {
					break;
				}

				$parsed = $item->isFile() && ! $item->isLink() ? $this->parse( $item->getFilename() ) : null;

				if ( null !== $parsed ) {
					$found[] = $parsed;
				}
			}
		} catch ( \UnexpectedValueException $failure ) {
			return array();
		}

		return $found;
	}

	/**
	 * Path of an artifact that belongs to the attachment, or null.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $name Artifact file name.
	 * @return string|null
	 */
	private function artifact_path( int $id, string $name ): ?string {
		$dir    = $this->directory( false );
		$parsed = $this->parse( $name );

		if ( null === $dir || null === $parsed || $parsed['id'] !== $id ) {
			return null;
		}

		$path = $dir . '/' . $name;

		if ( ! is_file( $path ) || is_link( $path ) || ! $this->inside( $dir, $path ) ) {
			return null;
		}

		return $path;
	}

	/**
	 * Whether a path resolves to a place inside the folder.
	 *
	 * @param string $dir  Folder.
	 * @param string $path Path.
	 * @return bool
	 */
	private function inside( string $dir, string $path ): bool {
		$real_dir  = realpath( $dir );
		$real_path = realpath( $path );

		return is_string( $real_dir ) && is_string( $real_path ) && str_starts_with( $real_path, $real_dir . DIRECTORY_SEPARATOR );
	}

	/**
	 * Deletes one artifact by name. Anything that is not a verified artifact file is left alone.
	 *
	 * @param string $name Artifact file name.
	 * @return bool True when the file is gone.
	 */
	private function remove( string $name ): bool {
		$parsed = $this->parse( $name );
		$dir    = $this->directory( false );

		if ( null === $parsed || null === $dir ) {
			return false;
		}

		$path = $dir . '/' . $name;

		if ( ! is_file( $path ) || is_link( $path ) || ! $this->inside( $dir, $path ) ) {
			return false;
		}

		wp_delete_file( $path );

		return ! file_exists( $path );
	}
}
