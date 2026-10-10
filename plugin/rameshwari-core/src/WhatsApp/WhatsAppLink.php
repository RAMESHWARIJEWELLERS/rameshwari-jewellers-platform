<?php
/**
 * A built enquiry link.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

/**
 * The outcome of the engine: a URL and the route that produced it, or a failure.
 */
final class WhatsAppLink {

	/**
	 * Builds the link.
	 *
	 * @param string $url     Link, empty on failure.
	 * @param string $route   Availability route.
	 * @param string $number  Number used.
	 * @param string $message Message text.
	 */
	public function __construct(
		private readonly string $url,
		private readonly string $route = '',
		private readonly string $number = '',
		private readonly string $message = ''
	) {
	}

	/**
	 * Whether a usable link exists.
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return '' !== $this->url;
	}

	/**
	 * The link.
	 *
	 * @return string
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * The route used.
	 *
	 * @return string
	 */
	public function route(): string {
		return $this->route;
	}

	/**
	 * The number used.
	 *
	 * @return string
	 */
	public function number(): string {
		return $this->number;
	}

	/**
	 * The message text.
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}
}
