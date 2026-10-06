<?php
/**
 * Reel source resolver tests.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration\Domain;

use Rameshwari\Core\Data\Meta;
use Rameshwari\Core\Domain\Reel\ReelSource;
use Rameshwari\Core\Domain\Reel\ReelSourceResolver;
use Rameshwari\Core\Services\Media\UploadValidator;
use Rameshwari\Core\Support\Logger;

/**
 * Source resolution for the four reel families, with no real network call.
 */
final class ReelSourceResolverTest extends \WP_UnitTestCase {

	/**
	 * Lines the logger received.
	 *
	 * @var array<int,string>
	 */
	private array $log = array();

	/**
	 * Urls the probe was asked about.
	 *
	 * @var array<int,string>
	 */
	private array $probed = array();

	/**
	 * Clears the capture and the HTTP stub.
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * A resolver with injected fakes.
	 *
	 * @param array<int,string> $external     Allowed external hosts.
	 * @param bool              $reachability Whether to probe.
	 * @param int               $status       Status the fake probe returns.
	 * @param array<int,string> $addresses    Addresses the fake DNS returns.
	 * @return ReelSourceResolver
	 */
	private function resolver( array $external = array( 'cdn.example.test' ), bool $reachability = false, int $status = 200, array $addresses = array( '93.184.216.34' ) ): ReelSourceResolver {
		return new ReelSourceResolver(
			new UploadValidator(),
			new Logger(
				function ( string $line ): void {
					$this->log[] = $line;
				}
			),
			$external,
			$reachability,
			function ( string $url ) use ( $status ): int {
				$this->probed[] = $url;

				return $status;
			},
			static fn(): array => $addresses
		);
	}

	/**
	 * An attachment with given bytes.
	 *
	 * @param string $mime  MIME type.
	 * @param string $name  File name.
	 * @param string $bytes File bytes.
	 * @return int
	 */
	private function attachment( string $mime, string $name, string $bytes ): int {
		$upload = wp_upload_bits( wp_generate_uuid4() . '-' . $name, null, $bytes );

		$this->assertEmpty( $upload['error'], (string) $upload['error'] );

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'Fixture',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		$this->assertIsInt( $id );

		update_attached_file( $id, $upload['file'] );

		return $id;
	}

	/**
	 * Bytes of a minimal well-formed MP4: an ftyp box, a free box and an mdat box.
	 *
	 * @return string
	 */
	private function mp4(): string {
		return "\x00\x00\x00\x1cftypisom\x00\x00\x02\x00isomiso2mp41\x00\x00\x00\x08free\x00\x00\x00\x10mdat12345678";
	}

	/**
	 * A source must be invalid with one of the given codes.
	 *
	 * @param ReelSource        $source Result.
	 * @param array<int,string> $codes  Allowed codes.
	 * @return void
	 */
	private function assert_invalid( ReelSource $source, array $codes ): void {
		$this->assertTrue( $source->is_invalid(), 'Expected an invalid source.' );
		$this->assertContains( $source->code, $codes );
		$this->assertSame( '', $source->canonical_url );
	}

	/**
	 * A real MP4 attachment resolves and keeps its attachment ID.
	 */
	public function test_valid_upload(): void {
		$id = $this->attachment( 'video/mp4', 'clip.mp4', $this->mp4() );

		$source = $this->resolver()->resolve( 'upload', $id );

		$this->assertTrue( $source->is_resolved(), $source->code );
		$this->assertSame( array( 'upload', $id, '' ), array( $source->source_type, $source->attachment_id, $source->canonical_url ) );
		$this->assertTrue( $this->resolver()->resolve( 'upload', (string) $id )->is_resolved() );
	}

	/**
	 * An image, a fake MP4, a missing attachment and a bad ID are all refused.
	 */
	public function test_invalid_uploads(): void {
		$image = imagecreatetruecolor( 4, 4 );

		$this->assertNotFalse( $image );

		ob_start();
		imagepng( $image );

		$png  = $this->attachment( 'image/png', 'pic.png', (string) ob_get_clean() );
		$fake = $this->attachment( 'video/mp4', 'fake.mp4', 'this is plain text, not a video' );
		$text = $this->attachment( 'text/plain', 'note.txt', 'hello' );

		$this->assert_invalid( $this->resolver()->resolve( 'upload', $png ), array( 'not_a_video' ) );
		$this->assert_invalid( $this->resolver()->resolve( 'upload', $fake ), array( 'not_a_video' ) );
		$this->assertTrue( $this->resolver()->resolve( 'upload', $text )->is_invalid() );
		$this->assert_invalid( $this->resolver()->resolve( 'upload', 99999999 ), array( 'attachment_not_found' ) );
		$this->assert_invalid( $this->resolver()->resolve( 'upload', 'abc' ), array( 'attachment_not_found' ) );
		$this->assert_invalid( $this->resolver()->resolve( 'upload', 0 ), array( 'attachment_not_found' ) );
	}

	/**
	 * Instagram forms normalise to one canonical URL and the ID is extracted.
	 */
	public function test_instagram_forms_normalise(): void {
		foreach ( array( 'https://www.instagram.com/reel/Cx12_-AbC/', 'https://instagram.com/reels/Cx12_-AbC', 'https://www.instagram.com/p/Cx12_-AbC/?igsh=zzz#frag', 'HTTPS://WWW.INSTAGRAM.COM/reel/Cx12_-AbC/' ) as $url ) {
			$source = $this->resolver()->resolve( 'instagram', $url );

			$this->assertTrue( $source->is_resolved(), $url );
			$this->assertSame( 'https://www.instagram.com/reel/Cx12_-AbC/', $source->canonical_url );
			$this->assertSame( 'Cx12_-AbC', $source->provider_id );
		}
	}

	/**
	 * Lookalike hosts and malformed paths are refused for Instagram.
	 */
	public function test_instagram_rejections(): void {
		$hosts = array( 'https://instagram.com.evil.test/reel/Cx12AbCde/', 'https://notinstagram.com/reel/Cx12AbCde/', 'https://evil.test/www.instagram.com/reel/Cx12AbCde/', 'https://m.instagram.com/reel/Cx12AbCde/' );
		$forms = array( 'https://www.instagram.com/', 'https://www.instagram.com/reel/', 'https://www.instagram.com/reel/ab/', 'https://www.instagram.com/reel/Cx12AbCde/extra', 'https://www.instagram.com/stories/Cx12AbCde/' );

		foreach ( $hosts as $url ) {
			$this->assert_invalid( $this->resolver()->resolve( 'instagram', $url ), array( 'host_not_allowed' ) );
		}

		foreach ( $forms as $url ) {
			$this->assert_invalid( $this->resolver()->resolve( 'instagram', $url ), array( 'malformed_provider_url' ) );
		}
	}

	/**
	 * A YouTube Shorts URL resolves to one canonical form and its video ID.
	 */
	public function test_youtube_shorts_resolve(): void {
		foreach ( array( 'https://www.youtube.com/shorts/aB3_-xYz012', 'https://youtube.com/shorts/aB3_-xYz012/', 'https://m.youtube.com/shorts/aB3_-xYz012?feature=share' ) as $url ) {
			$source = $this->resolver()->resolve( 'youtube', $url );

			$this->assertTrue( $source->is_resolved(), $url );
			$this->assertSame( 'https://www.youtube.com/shorts/aB3_-xYz012', $source->canonical_url );
			$this->assertSame( 'aB3_-xYz012', $source->provider_id );
		}
	}

	/**
	 * Malformed IDs, other paths and foreign hosts are refused for YouTube.
	 */
	public function test_youtube_rejections(): void {
		foreach ( array( 'https://www.youtube.com/shorts/short', 'https://www.youtube.com/shorts/aB3_-xYz0123', 'https://www.youtube.com/shorts/aB3_-xYz0!2', 'https://www.youtube.com/watch?v=aB3_-xYz012', 'https://www.youtube.com/shorts/' ) as $url ) {
			$this->assert_invalid( $this->resolver()->resolve( 'youtube', $url ), array( 'malformed_provider_url' ) );
		}

		foreach ( array( 'https://youtube.com.evil.test/shorts/aB3_-xYz012', 'https://www.youtu.be/shorts/aB3_-xYz012', 'https://evil.test/shorts/aB3_-xYz012' ) as $url ) {
			$this->assert_invalid( $this->resolver()->resolve( 'youtube', $url ), array( 'host_not_allowed' ) );
		}
	}

	/**
	 * An allow-listed external URL resolves and is rebuilt without its fragment.
	 */
	public function test_allowed_external_url(): void {
		$source = $this->resolver()->resolve( 'url', 'https://CDN.example.test/videos/a.mp4?t=1#x' );

		$this->assertTrue( $source->is_resolved(), $source->code );
		$this->assertSame( 'https://cdn.example.test/videos/a.mp4?t=1', $source->canonical_url );
	}

	/**
	 * An unlisted host, any scheme but https, credentials, ports and malformed text are refused.
	 */
	public function test_external_rejections(): void {
		$cases = array(
			array( 'https://other.example.test/a.mp4', 'host_not_allowed' ),
			array( 'http://cdn.example.test/a.mp4', 'unsupported_scheme' ),
			array( 'ftp://cdn.example.test/a.mp4', 'unsupported_scheme' ),
			array( 'javascript:alert(1)', 'malformed_url' ),
			array( 'https://user:pw@cdn.example.test/a.mp4', 'credentials_or_port_not_allowed' ),
			array( 'https://cdn.example.test:8443/a.mp4', 'credentials_or_port_not_allowed' ),
			array( 'not a url', 'malformed_url' ),
			array( '', 'malformed_url' ),
			array( "https://cdn.example.test/a\n.mp4", 'malformed_url' ),
			array( 'https://cdn.example.test\\evil.test/a', 'malformed_url' ),
		);

		foreach ( $cases as $case ) {
			$this->assert_invalid( $this->resolver()->resolve( 'url', $case[0] ), array( $case[1] ) );
		}

		$this->assert_invalid( $this->resolver( array() )->resolve( 'url', 'https://cdn.example.test/a.mp4' ), array( 'host_not_allowed' ) );
	}

	/**
	 * Local names and private or reserved addresses are refused even when listed.
	 */
	public function test_private_and_local_targets_are_rejected(): void {
		$targets = array( 'localhost', '127.0.0.1', '10.0.0.5', '192.168.1.1', '172.16.0.9', '169.254.169.254', '[::1]', 'printer.local', 'db.internal', 'intranet' );

		foreach ( $targets as $host ) {
			$source = $this->resolver( array( $host ) )->resolve( 'url', 'https://' . $host . '/a.mp4' );

			$this->assert_invalid( $source, array( 'private_target' ) );
		}
	}

	/**
	 * A listed host that resolves to a private address is refused.
	 */
	public function test_dns_pointing_inside_is_rejected(): void {
		$this->assert_invalid( $this->resolver( array( 'cdn.example.test' ), false, 200, array( '10.1.2.3' ) )->resolve( 'url', 'https://cdn.example.test/a.mp4' ), array( 'private_target' ) );
		$this->assert_invalid( $this->resolver( array( 'cdn.example.test' ), false, 200, array( '93.184.216.34', '127.0.0.1' ) )->resolve( 'url', 'https://cdn.example.test/a.mp4' ), array( 'private_target' ) );
	}

	/**
	 * The same input always gives the same result.
	 */
	public function test_resolution_is_deterministic(): void {
		$one = $this->resolver()->resolve( 'youtube', 'https://www.youtube.com/shorts/aB3_-xYz012' );
		$two = $this->resolver()->resolve( 'youtube', 'https://www.youtube.com/shorts/aB3_-xYz012' );

		$this->assertEquals( $one, $two );
		$this->assert_invalid( $this->resolver()->resolve( 'tiktok', 'https://tiktok.example/x' ), array( 'unsupported_source_type' ) );
	}

	/**
	 * An unreachable provider degrades safely, logs one warning and keeps the URL.
	 */
	public function test_unreachable_provider_degrades_with_a_warning(): void {
		$source = $this->resolver( array(), true, 0 )->resolve( 'youtube', 'https://www.youtube.com/shorts/aB3_-xYz012?token=SECRET' );

		$this->assertTrue( $source->is_degraded() );
		$this->assertSame( 'provider_unreachable', $source->code );
		$this->assertSame( 'https://www.youtube.com/shorts/aB3_-xYz012', $source->canonical_url );
		$this->assertCount( 1, $this->log );
		$this->assertStringContainsString( 'warning', strtolower( $this->log[0] ) );
		$this->assertStringContainsString( 'www.youtube.com', $this->log[0] );
		$this->assertStringNotContainsString( 'SECRET', $this->log[0] );
		$this->assertStringNotContainsString( 'aB3_-xYz012', $this->log[0] );
	}

	/**
	 * A reachable provider resolves, and the probe sees only the canonical URL.
	 */
	public function test_reachable_provider_is_probed_by_canonical_url(): void {
		$source = $this->resolver( array(), true, 200 )->resolve( 'instagram', 'https://instagram.com/reel/Cx12AbCde/?utm=1' );

		$this->assertTrue( $source->is_resolved() );
		$this->assertSame( array( 'https://www.instagram.com/reel/Cx12AbCde/' ), $this->probed );
		$this->assertSame( array(), $this->log );
	}

	/**
	 * A resolver built with no reachability argument, with only the probe stubbed.
	 *
	 * @param int $status Status the fake probe returns.
	 * @return ReelSourceResolver
	 */
	private function default_resolver( int $status ): ReelSourceResolver {
		return new ReelSourceResolver(
			validator: new UploadValidator(),
			logger: new Logger(
				function ( string $line ): void {
					$this->log[] = $line;
				}
			),
			probe: function ( string $url ) use ( $status ): int {
				$this->probed[] = $url;

				return $status;
			}
		);
	}

	/**
	 * By default a provider source is probed, with no option or flag needed.
	 */
	public function test_default_resolver_probes_the_provider(): void {
		$source = $this->default_resolver( 200 )->resolve( 'youtube', 'https://www.youtube.com/shorts/aB3_-xYz012' );

		$this->assertTrue( $source->is_resolved() );
		$this->assertSame( array( 'https://www.youtube.com/shorts/aB3_-xYz012' ), $this->probed );
	}

	/**
	 * By default an unreachable provider degrades with exactly one warning.
	 */
	public function test_default_resolver_degrades_an_unreachable_provider(): void {
		$source = $this->default_resolver( 0 )->resolve( 'instagram', 'https://instagram.com/reel/Cx12AbCde/?utm=1' );

		$this->assertTrue( $source->is_degraded() );
		$this->assertSame( 'provider_unreachable', $source->code );
		$this->assertCount( 1, $this->log );
		$this->assertStringNotContainsString( 'Cx12AbCde', $this->log[0] );
		$this->assertStringNotContainsString( 'utm', $this->log[0] );
	}

	/**
	 * By default an invalid provider URL is never probed.
	 */
	public function test_default_resolver_does_not_probe_invalid_urls(): void {
		$source = $this->default_resolver( 200 )->resolve( 'youtube', 'https://evil.test/shorts/aB3_-xYz012' );

		$this->assertTrue( $source->is_invalid() );
		$this->assertSame( array(), $this->probed );
		$this->assertSame( array(), $this->log );
	}

	/**
	 * By default an upload is resolved from the file alone and nothing remote is probed.
	 */
	public function test_default_resolver_does_not_probe_uploads(): void {
		$id     = $this->attachment( 'video/mp4', 'clip.mp4', $this->mp4() );
		$source = $this->default_resolver( 0 )->resolve( 'upload', $id );

		$this->assertTrue( $source->is_resolved(), $source->code );
		$this->assertSame( array(), $this->probed );
		$this->assertSame( array(), $this->log );
	}

	/**
	 * A probe that throws is a degraded result, never an exception.
	 */
	public function test_throwing_probe_never_escapes(): void {
		$resolver = new ReelSourceResolver(
			new UploadValidator(),
			new Logger( static function (): void {} ),
			array(),
			true,
			static function (): int {
				throw new \RuntimeException( 'remote exploded: secret detail' );
			}
		);

		$source = $resolver->resolve( 'youtube', 'https://www.youtube.com/shorts/aB3_-xYz012' );

		$this->assertTrue( $source->is_degraded() );
		$this->assertStringNotContainsString( 'secret', $source->code );
	}

	/**
	 * Invalid sources are never probed.
	 */
	public function test_invalid_sources_are_not_probed(): void {
		$this->resolver( array(), true, 200 )->resolve( 'youtube', 'https://evil.test/shorts/aB3_-xYz012' );
		$this->resolver( array( 'localhost' ), true, 200 )->resolve( 'url', 'https://localhost/a' );

		$this->assertSame( array(), $this->probed );
	}

	/**
	 * The default probe uses the WordPress HTTP API without following redirects.
	 */
	public function test_default_probe_uses_wordpress_http_without_redirects(): void {
		$args = array();

		add_filter(
			'pre_http_request',
			static function ( mixed $pre, array $request ) use ( &$args ): array {
				$args = $request;

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			2
		);

		$resolver = new ReelSourceResolver( new UploadValidator(), new Logger( static function (): void {} ), array(), true );
		$source   = $resolver->resolve( 'youtube', 'https://www.youtube.com/shorts/aB3_-xYz012' );

		$this->assertTrue( $source->is_resolved() );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 'HEAD', $args['method'] );
	}

	/**
	 * A WordPress HTTP error from the default probe degrades the result.
	 */
	public function test_default_probe_error_degrades(): void {
		add_filter( 'pre_http_request', static fn(): \WP_Error => new \WP_Error( 'http_request_failed', 'no route' ) );

		$resolver = new ReelSourceResolver( new UploadValidator(), new Logger( static function (): void {} ), array(), true );

		$this->assertTrue( $resolver->resolve( 'instagram', 'https://www.instagram.com/reel/Cx12AbCde/' )->is_degraded() );
	}

	/**
	 * Stage 7 validation is reused, not re-implemented.
	 */
	public function test_stage_7_validation_is_reused(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/plugin/rameshwari-core/src/Domain/Reel/ReelSourceResolver.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file; no remote URL is involved.

		$this->assertIsString( $source );
		$this->assertStringContainsString( 'UploadValidator::SLOT_REEL', $source );

		foreach ( array( 'imagecreate', 'getimagesize', 'wp_handle_upload', 'shell_exec', 'exec(', 'ffmpeg', 'curl_', 'file_get_contents' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $source, $needle );
		}
	}

	/**
	 * No reel type taxonomy or meta key exists.
	 */
	public function test_no_reel_type_is_introduced(): void {
		$this->assertFalse( taxonomy_exists( 'rj_reel_type' ) );
		$this->assertSame( array(), preg_grep( '/reel_type/', array_keys( Meta::post_meta()['rj_reel'] ) ) );
		$this->assertContains( 'rj_tag', get_object_taxonomies( 'rj_reel' ) );
	}

	/**
	 * Spoofed names and stored types do not make a file a video.
	 */
	public function test_extension_and_stored_mime_spoofing_is_rejected(): void {
		$text = $this->attachment( 'video/mp4', 'movie.mp4', 'plain words, nothing more' );
		$png  = imagecreatetruecolor( 4, 4 );

		$this->assertNotFalse( $png );

		ob_start();
		imagepng( $png );

		$image = $this->attachment( 'video/mp4', 'clip.mp4', (string) ob_get_clean() );
		$php   = $this->attachment( 'video/mp4', 'shell.mp4', '<?php echo 1;' );

		foreach ( array( $text, $image, $php ) as $id ) {
			$this->assertTrue( $this->resolver()->resolve( 'upload', $id )->is_invalid(), (string) $id );
		}
	}

	/**
	 * A text file that merely contains the bytes ftyp is not a video.
	 */
	public function test_text_containing_ftyp_is_rejected(): void {
		$id = $this->attachment( 'video/mp4', 'fake.mp4', "\x00\x00\x00\x18ftypisom this is only text pretending to be a video, with ftyp inside" );

		$source = $this->resolver()->resolve( 'upload', $id );

		$this->assertTrue( $source->is_invalid() );
		$this->assertContains( $source->code, array( 'not_a_video', 'invalid_container' ) );
	}

	/**
	 * An MP4-like header without a complete container is rejected.
	 */
	public function test_incomplete_container_is_rejected(): void {
		$cases = array(
			'header only'      => "\x00\x00\x00\x1cftypisom\x00\x00\x02\x00isomiso2mp41",
			'next box tiny'    => "\x00\x00\x00\x1cftypisom\x00\x00\x02\x00isomiso2mp41\x00\x00\x00\x04free",
			'next box huge'    => "\x00\x00\x00\x1cftypisom\x00\x00\x02\x00isomiso2mp41\x00\x00\x10\x00free",
			'box too large'    => "\x00\x00\x10\x00ftypisom\x00\x00\x02\x00isomiso2mp41\x00\x00\x00\x08free",
			'tiny box'         => "\x00\x00\x00\x08ftypisom\x00\x00\x00\x08free",
			'garbage next box' => "\x00\x00\x00\x1cftypisom\x00\x00\x02\x00isomiso2mp41\xff\xff\xff\xff\x00\x00\x00\x00",
		);

		foreach ( $cases as $label => $bytes ) {
			$id = $this->attachment( 'video/mp4', 'cut.mp4', $bytes );

			$this->assertTrue( $this->resolver()->resolve( 'upload', $id )->is_invalid(), $label );
		}
	}

	/**
	 * Resolver source holds no shell, remote download or detector-free shortcut.
	 */
	public function test_upload_check_is_content_based_and_fails_closed(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/plugin/rameshwari-core/src/Domain/Reel/ReelSourceResolver.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file; no remote URL is involved.

		$this->assertIsString( $source );

		foreach ( array( 'finfo', 'FILEINFO_MIME_TYPE', 'content_check_unavailable', 'invalid_container', 'class_exists' ) as $needle ) {
			$this->assertStringContainsString( $needle, $source, $needle );
		}

		$this->assertStringNotContainsString( 'wp_check_filetype', $source );
		$this->assertStringNotContainsString( 'post_mime_type', $source );
	}
}
