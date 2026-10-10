<?php
/**
 * WhatsApp and lead module.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

use Rameshwari\Core\Container;
use Rameshwari\Core\Data\Options;
use Rameshwari\Core\Module;
use Rameshwari\Core\Product\ProductModule;

/**
 * Registers the one engine, lead capture, the public admin-post action and the rj_whatsapp_url helper.
 */
final class WhatsAppModule implements Module {

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'whatsapp';
	}

	/**
	 * Modules that must register first.
	 *
	 * @return array<int,string>
	 */
	public static function requires(): array {
		return array( Options::id(), ProductModule::id() );
	}

	/**
	 * Adds the services and the two admin-post hooks.
	 *
	 * @param Container $container Container.
	 * @return void
	 */
	public function register( Container $container ): void {
		require_once RJ_PATH . 'src/WhatsApp/functions.php';

		$container->set( 'whatsapp.engine', static fn(): WhatsAppEngine => new WhatsAppEngine() );
		$container->set( 'whatsapp.capture', static fn(): LeadCapture => new LeadCapture() );
		$container->set(
			'whatsapp.handler',
			static function ( Container $c ): LeadHandler {
				$engine  = $c->get( 'whatsapp.engine' );
				$capture = $c->get( 'whatsapp.capture' );

				return new LeadHandler( $engine instanceof WhatsAppEngine ? $engine : new WhatsAppEngine(), $capture instanceof LeadCapture ? $capture : new LeadCapture() );
			}
		);

		$handle = static function () use ( $container ): void {
			$handler = $container->get( 'whatsapp.handler' );

			if ( $handler instanceof LeadHandler ) {
				$handler->handle();
			}
		};

		add_action( 'admin_post_nopriv_' . LeadHandler::ACTION, $handle );
		add_action( 'admin_post_' . LeadHandler::ACTION, $handle );
	}
}
