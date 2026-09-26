<?php
/**
 * Error and debug logging.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Support;

/**
 * Writes developer diagnostics to the PHP error log. This is not the
 * activity log, which is a business record owned by a later stage.
 */
final class Logger {

	public const DEBUG   = 'debug';
	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	private const LEVELS = array(
		self::DEBUG   => 0,
		self::INFO    => 1,
		self::WARNING => 2,
		self::ERROR   => 3,
	);

	private const SENSITIVE_KEY = '/pass|secret|token|key|auth|cookie|nonce/i';

	/**
	 * Receives each finished log line.
	 *
	 * @var callable(string): void
	 */
	private $writer;

	/**
	 * Lowest level that is written.
	 *
	 * @var int
	 */
	private int $threshold;

	/**
	 * Sets up the logger.
	 *
	 * @param callable(string): void|null $writer Line writer. Defaults to error_log().
	 * @param bool                        $debug  Write debug and info lines too.
	 */
	public function __construct( ?callable $writer = null, bool $debug = false ) {
		$this->writer    = $writer ?? static function ( string $line ): void {
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- This class is the logging channel.
		};
		$this->threshold = $debug ? self::LEVELS[ self::DEBUG ] : self::LEVELS[ self::WARNING ];
	}

	/**
	 * Writes one line if the level meets the threshold.
	 *
	 * @param string               $level   One of the level constants.
	 * @param string               $message Plain-text message.
	 * @param array<string, mixed> $context Extra data. Sensitive keys are redacted.
	 * @return void
	 * @throws \InvalidArgumentException For an unknown level.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			throw new \InvalidArgumentException( esc_html( 'Unknown log level: ' . $level ) );
		}

		if ( self::LEVELS[ $level ] < $this->threshold ) {
			return;
		}

		$line = sprintf( '[rameshwari] %s: %s', strtoupper( $level ), $message );

		if ( array() !== $context ) {
			$line .= ' ' . wp_json_encode( $this->redact( $context ) );
		}

		( $this->writer )( $line );
	}

	/**
	 * Writes a debug line.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( self::DEBUG, $message, $context );
	}

	/**
	 * Writes a warning line.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( self::WARNING, $message, $context );
	}

	/**
	 * Writes an error line.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( self::ERROR, $message, $context );
	}

	/**
	 * Replaces the value of any sensitive key, at any depth.
	 *
	 * @param array<mixed> $context Context.
	 * @return array<mixed>
	 */
	private function redact( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && 1 === preg_match( self::SENSITIVE_KEY, $key ) ) {
				$context[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = $this->redact( $value );
			}
		}

		return $context;
	}
}
