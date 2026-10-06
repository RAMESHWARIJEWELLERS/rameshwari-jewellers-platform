<?php
/**
 * Reel source resolver.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Domain\Reel;

use Rameshwari\Core\Services\Media\UploadValidator;
use Rameshwari\Core\Support\Logger;

/**
 * Resolves a reel source for the four supported families and fails the same
 * way every time. It validates and normalises; it never downloads, stores,
 * follows redirects or runs a program.
 *
 * - Upload: the value is an attachment ID. Stage 7's UploadValidator is asked
 *   first, with the reel slot. It defers real video checking to this stage and
 *   reports that by code, which proves nothing about video content. So the file is
 *   then verified here by content: detected type video/mp4, a well-formed ftyp
 *   box and a second box. Extension and the attachment's stored MIME are never
 *   trusted, and a missing detector fails closed.
 * - Instagram and YouTube Shorts: fixed host allow-lists and strict forms; the
 *   provider ID is extracted by pattern and the URL is rebuilt, never echoed.
 * - External URL: https only, host must be on the allow-list given to the
 *   constructor. Production callers must pass the approved hosts; the default is
 *   empty and refuses every external URL. It is never read from an option.
 *   Further rules: no credentials, no port, no private or
 *   local target.
 *
 * Reachability is checked by default for every provider and external source,
 * with a head request that follows no redirects. Uploads are never probed. A failure
 * returns a degraded result and one warning that holds the provider type and
 * host only. Nothing remote ever reaches the result.
 */
final class ReelSourceResolver {

	private const INSTAGRAM_HOSTS = array( 'instagram.com', 'www.instagram.com' );

	private const YOUTUBE_HOSTS = array( 'youtube.com', 'www.youtube.com', 'm.youtube.com' );

	/**
	 * Sends the reachability probe; returns the HTTP status, or 0 on failure.
	 *
	 * @var callable(string):int
	 */
	private $probe;

	/**
	 * Resolves a host name to its IP addresses.
	 *
	 * @var callable(string):array<int,string>
	 */
	private $resolver;

	/**
	 * Builds the resolver.
	 *
	 * @param UploadValidator   $validator    Stage 7 upload validation.
	 * @param Logger            $logger       Warning log.
	 * @param array<int,string> $external     Allowed hosts for the url source type.
	 * @param bool              $reachability Whether to probe providers; on by default, tests may turn it off.
	 * @param callable|null     $probe        Probe override for tests.
	 * @param callable|null     $resolver     DNS override for tests.
	 */
	public function __construct(
		private readonly UploadValidator $validator,
		private readonly Logger $logger,
		private readonly array $external = array(),
		private readonly bool $reachability = true,
		?callable $probe = null,
		?callable $resolver = null
	) {
		$this->probe    = $probe ?? static function ( string $url ): int {
			$response = wp_safe_remote_head(
				$url,
				array(
					'timeout'     => 3,
					'redirection' => 0,
				)
			);

			return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		};
		$this->resolver = $resolver ?? static function ( string $host ): array {
			$list = gethostbynamel( $host );

			return false === $list ? array() : $list;
		};
	}

	/**
	 * Resolves one source.
	 *
	 * @param string     $source_type upload, instagram, youtube or url.
	 * @param string|int $source      Attachment ID for upload, otherwise a URL.
	 * @return ReelSource
	 */
	public function resolve( string $source_type, string|int $source ): ReelSource {
		return match ( $source_type ) {
			'upload'    => $this->upload( $source ),
			'instagram' => $this->instagram( (string) $source ),
			'youtube'   => $this->youtube( (string) $source ),
			'url'       => $this->external_url( (string) $source ),
			default     => ReelSource::invalid( $source_type, 'unsupported_source_type' ),
		};
	}

	/**
	 * Confirms an attachment is a real MP4 video.
	 *
	 * @param string|int $source Attachment ID.
	 * @return ReelSource
	 */
	private function upload( string|int $source ): ReelSource {
		$id = is_int( $source ) ? $source : ( ctype_digit( $source ) ? (int) $source : 0 );

		if ( $id < 1 || 'attachment' !== get_post_type( $id ) ) {
			return ReelSource::invalid( 'upload', 'attachment_not_found' );
		}

		$path = get_attached_file( $id );

		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return ReelSource::invalid( 'upload', 'attachment_file_missing' );
		}

		$validation = $this->validator->validate( $path, basename( $path ), UploadValidator::SLOT_REEL );

		if ( $validation->is_valid() ) {
			return ReelSource::invalid( 'upload', 'not_a_video' );
		}

		if ( 'video_validation_deferred' !== $validation->code() ) {
			return ReelSource::invalid( 'upload', $validation->code() );
		}

		$failure = $this->verify_mp4( $path );

		if ( '' !== $failure ) {
			return ReelSource::invalid( 'upload', $failure );
		}

		return ReelSource::resolved( 'upload', '', '', $id );
	}

	/**
	 * Resolves an Instagram reel or post URL.
	 *
	 * @param string $url Raw URL.
	 * @return ReelSource
	 */
	private function instagram( string $url ): ReelSource {
		$parts = $this->parse( $url );

		if ( is_string( $parts ) ) {
			return ReelSource::invalid( 'instagram', $parts );
		}

		if ( ! in_array( $parts['host'], self::INSTAGRAM_HOSTS, true ) ) {
			return ReelSource::invalid( 'instagram', 'host_not_allowed' );
		}

		if ( 1 !== preg_match( '#^/(?:reel|reels|p)/([A-Za-z0-9_-]{5,40})/?$#', $parts['path'], $found ) ) {
			return ReelSource::invalid( 'instagram', 'malformed_provider_url' );
		}

		return $this->finish( 'instagram', 'https://www.instagram.com/reel/' . $found[1] . '/', $found[1], 'www.instagram.com' );
	}

	/**
	 * Resolves a YouTube Shorts URL.
	 *
	 * @param string $url Raw URL.
	 * @return ReelSource
	 */
	private function youtube( string $url ): ReelSource {
		$parts = $this->parse( $url );

		if ( is_string( $parts ) ) {
			return ReelSource::invalid( 'youtube', $parts );
		}

		if ( ! in_array( $parts['host'], self::YOUTUBE_HOSTS, true ) ) {
			return ReelSource::invalid( 'youtube', 'host_not_allowed' );
		}

		if ( 1 !== preg_match( '#^/shorts/([A-Za-z0-9_-]{11})/?$#', $parts['path'], $found ) ) {
			return ReelSource::invalid( 'youtube', 'malformed_provider_url' );
		}

		return $this->finish( 'youtube', 'https://www.youtube.com/shorts/' . $found[1], $found[1], 'www.youtube.com' );
	}

	/**
	 * Resolves an external https URL on the allow-list.
	 *
	 * @param string $url Raw URL.
	 * @return ReelSource
	 */
	private function external_url( string $url ): ReelSource {
		$parts = $this->parse( $url );

		if ( is_string( $parts ) ) {
			return ReelSource::invalid( 'url', $parts );
		}

		$host = $parts['host'];

		if ( $this->is_local_name( $host ) || $this->is_private_ip( $host ) ) {
			return ReelSource::invalid( 'url', 'private_target' );
		}

		if ( ! in_array( $host, array_map( 'strtolower', $this->external ), true ) ) {
			return ReelSource::invalid( 'url', 'host_not_allowed' );
		}

		foreach ( ( $this->resolver )( $host ) as $address ) {
			if ( $this->is_private_ip( $address ) ) {
				return ReelSource::invalid( 'url', 'private_target' );
			}
		}

		$canonical = 'https://' . $host . ( '' === $parts['path'] ? '/' : $parts['path'] ) . ( '' === $parts['query'] ? '' : '?' . $parts['query'] );

		return $this->finish( 'url', $canonical, '', $host );
	}

	/**
	 * Probes the provider when asked to, and builds the final result.
	 *
	 * @param string $type      Source type.
	 * @param string $canonical Canonical URL.
	 * @param string $id        Provider ID, or empty.
	 * @param string $host      Host for the log line.
	 * @return ReelSource
	 */
	private function finish( string $type, string $canonical, string $id, string $host ): ReelSource {
		if ( ! $this->reachability ) {
			return ReelSource::resolved( $type, $canonical, $id, 0 );
		}

		try {
			$status = ( $this->probe )( $canonical );
		} catch ( \Throwable $failure ) {
			$status = 0;
		}

		if ( $status >= 200 && $status < 400 ) {
			return ReelSource::resolved( $type, $canonical, $id, 0 );
		}

		$this->logger->warning(
			'Reel provider unreachable.',
			array(
				'source_type' => $type,
				'host'        => $host,
			)
		);

		return ReelSource::degraded( $type, 'provider_unreachable', $canonical, $id );
	}

	/**
	 * Splits a URL into a lower-case host, path and query, or returns a failure code.
	 *
	 * @param string $url Raw URL.
	 * @return array{host:string,path:string,query:string}|string
	 */
	private function parse( string $url ): array|string {
		if ( '' === $url || strlen( $url ) > 2048 || 1 === preg_match( '~[[:cntrl:][:space:]\\\\]~', $url ) ) {
			return 'malformed_url';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return 'malformed_url';
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return 'unsupported_scheme';
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return 'credentials_or_port_not_allowed';
		}

		return array(
			'host'  => rtrim( strtolower( $parts['host'] ), '.' ),
			'path'  => $parts['path'] ?? '',
			'query' => $parts['query'] ?? '',
		);
	}

	/**
	 * Whether a name points at the local machine or an internal network.
	 *
	 * @param string $host Lower-case host.
	 * @return bool
	 */
	private function is_local_name( string $host ): bool {
		return 'localhost' === $host || 1 === preg_match( '/\.(local|localhost|internal|lan|home|corp)$/', $host ) || ( false === strpos( $host, '.' ) && false === filter_var( $host, FILTER_VALIDATE_IP ) );
	}

	/**
	 * Whether a literal IP address is private, reserved or loopback.
	 *
	 * @param string $address Host or address.
	 * @return bool
	 */
	private function is_private_ip( string $address ): bool {
		$bare = trim( $address, '[]' );

		if ( false === filter_var( $bare, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		return false === filter_var( $bare, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Verifies by content that a file is an MP4. Fails closed.
	 *
	 * Detected type must be video/mp4, and the file must start with a well-formed
	 * ISO base media ftyp box that fits inside the file and is followed by a second
	 * box. A text file that merely contains the word ftyp fails the type check, and
	 * a truncated or header-only file fails the box check. If the type detector is
	 * missing the answer is no.
	 *
	 * @param string $path Local attachment file.
	 * @return string Empty when verified, otherwise a failure code.
	 */
	private function verify_mp4( string $path ): string {
		if ( ! class_exists( \finfo::class ) ) {
			return 'content_check_unavailable';
		}

		$mime = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $path );

		if ( 'video/mp4' !== $mime ) {
			return 'not_a_video';
		}

		$size   = (int) filesize( $path );
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reads container headers of a local attachment.

		if ( false === $handle ) {
			return 'content_check_unavailable';
		}

		$first = $this->read_at( $handle, 0, 16 );
		$box   = unpack( 'Nlength', substr( $first, 0, 4 ) );
		$ftyp  = is_array( $box ) ? (int) $box['length'] : 0;
		$next  = $ftyp >= 16 ? $this->read_at( $handle, $ftyp, 8 ) : '';
		$more  = 8 === strlen( $next ) ? unpack( 'Nlength', substr( $next, 0, 4 ) ) : false;
		$rest  = is_array( $more ) ? (int) $more['length'] : 0;

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local attachment header.

		$well_formed = 16 === strlen( $first ) && 'ftyp' === substr( $first, 4, 4 ) && $ftyp >= 16 && $ftyp <= 4096 && $ftyp + 8 <= $size
			&& 1 === preg_match( '/^[A-Za-z0-9 ]{4}$/', substr( $first, 8, 4 ) )
			&& 8 === strlen( $next ) && 1 === preg_match( '/^[A-Za-z0-9 ]{4}$/', substr( $next, 4, 4 ) )
			&& $rest >= 8 && $rest <= $size - $ftyp;

		return $well_formed ? '' : 'invalid_container';
	}

	/**
	 * Reads bytes at an offset of an open local file.
	 *
	 * @param resource $handle Open file.
	 * @param int      $offset Start byte.
	 * @param int      $length Bytes to read.
	 * @return string
	 */
	private function read_at( $handle, int $offset, int $length ): string {
		if ( 0 !== fseek( $handle, $offset ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Local attachment header.
			return '';
		}

		return (string) fread( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Local attachment header.
	}
}
