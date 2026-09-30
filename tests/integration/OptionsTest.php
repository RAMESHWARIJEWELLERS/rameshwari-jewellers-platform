<?php
/**
 * Settings registry tests against the locked §10.2 contract.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Tests\Integration;

use Rameshwari\Core\Data\Options;
use Rameshwari\Core\Data\Schema;
use WP_UnitTestCase;

/**
 * Development Blueprint §10 and §10.2 (Decision B) and regression test 1.
 */
final class OptionsTest extends WP_UnitTestCase {

	/**
	 * Exactly the ten groups, rj_chatbot tenth.
	 */
	public function test_ten_groups_chatbot_tenth(): void {
		$names = Options::names();

		$this->assertCount( 10, $names );
		$this->assertSame( 'rj_chatbot', $names[9] );
		$this->assertSame( array( 'rj_db_version', 'rj_install_state' ), Options::INTERNAL );
		$this->assertSame( array( 'rj_contact', 'rj_whatsapp', 'rj_display', 'rj_features', 'rj_auth_providers', 'rj_notifications', 'rj_seo', 'rj_chatbot' ), array_keys( Options::schemas() ) );
	}

	/**
	 * Every default equals the locked contract exactly.
	 */
	public function test_defaults_match_locked_contract(): void {
		$this->assertSame(
			array(
				'phones'           => array(),
				'emails'           => array(),
				'social'           => array(
					'instagram' => '',
					'facebook'  => '',
					'youtube'   => '',
					'maps'      => '',
				),
				'locality'         => '',
				'primary_showroom' => 0,
			),
			Options::defaults( 'rj_contact' )
		);
		$this->assertSame(
			array(
				'active_number'   => '',
				'fallback_number' => '',
				'templates'       => array(
					'product'    => 'नमस्ते, मुझे {name_hi} ({code}) के बारे में जानकारी चाहिए। {url}',
					'reel'       => 'नमस्ते, मुझे इस reel में दिखाई गई ज्वेलरी के बारे में जानकारी चाहिए। {url}',
					'collection' => 'नमस्ते, मुझे {name} कलेक्शन देखना है। {url}',
					'bridal'     => 'नमस्ते, मुझे ब्राइडल ज्वेलरी के बारे में बात करनी है। {url}',
					'showroom'   => 'नमस्ते, मुझे {branch} शोरूम आना है। समय बता दीजिए।',
				),
				'availability'    => array(
					'mode'          => 'showroom_hours',
					'outside_hours' => 'use_fallback',
				),
			),
			Options::defaults( 'rj_whatsapp' )
		);
		$this->assertSame(
			array(
				'language_lead'        => 'hi',
				'grid_density'         => 'comfortable',
				'items_per_page'       => 24,
				'reel_autoplay'        => false,
				'lazy_thresholds'      => array( 'near_viewport_px' => 300 ),
				'show_weight_publicly' => false,
			),
			Options::defaults( 'rj_display' )
		);
		$this->assertSame( array_fill_keys( array( 'accounts', 'google_login', 'notifications', 'visual_edit', 'enquiry_list', 'hero_slider', 'chatbot' ), false ), Options::defaults( 'rj_features' ) );
		$this->assertSame(
			array(
				'google' => array(
					'enabled'       => false,
					'client_id'     => '',
					'client_secret' => '',
				),
			),
			Options::defaults( 'rj_auth_providers' )
		);
		$this->assertSame(
			array(
				'channel_enablement' => array(
					'email'        => true,
					'admin_notice' => true,
					'webpush'      => false,
					'firebase'     => false,
					'onesignal'    => false,
					'whatsapp'     => false,
				),
				'driver_credentials' => array(),
				'throttles'          => array(
					'push_per_customer_per_day'  => 1,
					'email_per_customer_per_day' => 3,
				),
				'digest_schedule'    => array(
					'frequency' => 'daily',
					'time'      => '19:00',
					'timezone'  => 'site',
				),
				'quiet_hours'        => array(
					'start'    => '22:00',
					'end'      => '08:00',
					'timezone' => 'site',
				),
			),
			Options::defaults( 'rj_notifications' )
		);
		$this->assertSame(
			array(
				'title_patterns'  => array(
					'site'       => '{{site_name}}',
					'product'    => '{{name_en}} | {{site_name}}',
					'category'   => '{{name_en}} | {{site_name}}',
					'collection' => '{{name}} | {{site_name}}',
					'reel'       => '{{title}} | {{site_name}}',
					'showroom'   => '{{branch}} | {{site_name}}',
				),
				'structured_data' => array_fill_keys( array( 'organization', 'product', 'breadcrumb', 'showroom' ), true ),
				'open_graph'      => array(
					'enabled'             => true,
					'default_title'       => '',
					'default_description' => '',
					'default_image_id'    => 0,
				),
				'sitemap'         => array_fill_keys( array( 'products', 'categories', 'collections', 'reels', 'showrooms' ), true ),
			),
			Options::defaults( 'rj_seo' )
		);
		$this->assertSame( 1, Options::defaults( 'rj_db_version' ) );
		$this->assertSame(
			array(
				'install_timestamp'       => null,
				'completed_migration_ids' => array(),
				'last_rebuild_times'      => array(),
				'seeded'                  => false,
			),
			Options::defaults( 'rj_install_state' )
		);
		$this->assertSame(
			array(
				'welcome'           => '',
				'fallback'          => '',
				'human_support'     => '',
				'knowledge_sources' => array(
					'products'    => true,
					'categories'  => true,
					'collections' => true,
					'reels'       => true,
					'showrooms'   => true,
					'contact'     => true,
					'faq'         => true,
					'policies'    => false,
				),
				'capabilities'      => array_fill_keys( array( 'product_search', 'category_search', 'showroom_information', 'whatsapp_handoff' ), true ),
				'provider'          => '',
				'faq_entries'       => array(),
				'privacy'           => array(
					'retain_conversations' => false,
					'retention_days'       => 0,
				),
				'logging'           => array(
					'usage_counters'     => true,
					'debug_logging'      => false,
					'transcript_storage' => false,
				),
			),
			Options::defaults( 'rj_chatbot' )
		);
	}

	/**
	 * Regression 1: each writable group is written, read back and compared.
	 */
	public function test_each_group_round_trips(): void {
		foreach ( array_keys( Options::schemas() ) as $group ) {
			$value = Options::defaults( $group );

			$this->assertTrue( Options::save( $group, $value ), $group );
			$this->assertSame( $value, get_option( $group ), $group );
			$this->assertSame( $value, Options::get( $group ), $group );
		}
	}

	/**
	 * A partial save keeps every other field, including nested ones.
	 */
	public function test_partial_save_keeps_other_fields(): void {
		$this->assertTrue( Options::save( 'rj_display', array( 'items_per_page' => 12 ) ) );
		$this->assertTrue( Options::save( 'rj_contact', array( 'social' => array( 'instagram' => 'https://example.com/rj' ) ) ) );

		$display = Options::get( 'rj_display' );
		$contact = Options::get( 'rj_contact' );

		$this->assertSame( 12, $display['items_per_page'] );
		$this->assertSame( 'hi', $display['language_lead'] );
		$this->assertSame( 'https://example.com/rj', $contact['social']['instagram'] );
		$this->assertSame( '', $contact['social']['maps'] );
	}

	/**
	 * A value that does not read back unchanged fails the save.
	 */
	public function test_read_back_mismatch_is_an_error(): void {
		$tamper = static fn( mixed $value ): mixed => array_merge( (array) $value, array( 'items_per_page' => 12 ) );
		add_filter( 'option_rj_display', $tamper );

		$result = Options::save( 'rj_display', array( 'items_per_page' => 30 ) );

		remove_filter( 'option_rj_display', $tamper );

		$this->assertWPError( $result );
		$this->assertSame( 'rj_option_not_saved', $result->get_error_code() );
	}

	/**
	 * Enum and range violations are rejected with the field path.
	 */
	public function test_enum_and_range_rejected(): void {
		$cases = array(
			array( 'rj_display', array( 'language_lead' => 'fr' ) ),
			array( 'rj_display', array( 'grid_density' => 'dense' ) ),
			array( 'rj_display', array( 'items_per_page' => 11 ) ),
			array( 'rj_display', array( 'items_per_page' => 49 ) ),
			array( 'rj_display', array( 'lazy_thresholds' => array( 'near_viewport_px' => 2001 ) ) ),
			array( 'rj_display', array( 'reel_autoplay' => 'yes' ) ),
			array( 'rj_whatsapp', array( 'availability' => array( 'mode' => 'weekends' ) ) ),
			array( 'rj_notifications', array( 'digest_schedule' => array( 'frequency' => 'monthly' ) ) ),
			array( 'rj_notifications', array( 'quiet_hours' => array( 'start' => '24:00' ) ) ),
			array( 'rj_chatbot', array( 'privacy' => array( 'retention_days' => 366 ) ) ),
			array( 'rj_chatbot', array( 'provider' => str_repeat( 'x', 65 ) ) ),
		);

		foreach ( $cases as list( $group, $value ) ) {
			$this->assertWPError( Options::save( $group, $value ), $group . ' ' . wp_json_encode( $value ) );
		}

		$this->assertStringStartsWith( 'rj_display.items_per_page', (string) Options::validate( 'rj_display', array( 'items_per_page' => 49 ) ) );
	}

	/**
	 * Rj_contact.emails holds {label, address} records only.
	 */
	public function test_contact_email_records(): void {
		$valid = array(
			'label'   => 'General',
			'address' => 'owner@example.com',
		);

		$this->assertTrue( Options::save( 'rj_contact', array( 'emails' => array( $valid ) ) ) );
		$this->assertSame( array( $valid ), Options::get( 'rj_contact' )['emails'] );

		$this->assertWPError( Options::save( 'rj_contact', array( 'emails' => array( 'owner@example.com' ) ) ) );
		$this->assertWPError(
			Options::save(
				'rj_contact',
				array(
					'emails' => array(
						array(
							'label'   => 'x',
							'address' => 'not-an-email',
						),
					),
				)
			)
		);
		$this->assertWPError(
			Options::save(
				'rj_contact',
				array(
					'emails' => array(
						array(
							'name'  => 'x',
							'email' => 'owner@example.com',
						),
					),
				)
			)
		);
		$this->assertWPError( Options::save( 'rj_contact', array( 'emails' => array( array_merge( $valid, array( 'extra' => 1 ) ) ) ) ) );
		$this->assertWPError( Options::save( 'rj_contact', array( 'emails' => array( array( 'address' => 'owner@example.com' ) ) ) ) );
		$this->assertWPError( Options::save( 'rj_contact', array( 'emails' => array( array_merge( $valid, array( 'label' => str_repeat( 'x', 41 ) ) ) ) ) ) );
		$this->assertWPError( Options::save( 'rj_contact', array( 'emails' => array_fill( 0, 11, $valid ) ) ) );
	}

	/**
	 * Phone records require an E.164 number and all three keys.
	 */
	public function test_contact_phone_records(): void {
		$phone = array(
			'label'    => 'Primary',
			'number'   => '+911234567890',
			'whatsapp' => true,
		);

		$this->assertTrue( Options::save( 'rj_contact', array( 'phones' => array( $phone ) ) ) );
		$this->assertWPError( Options::save( 'rj_contact', array( 'phones' => array( array_merge( $phone, array( 'number' => '1234567890' ) ) ) ) ) );
		$this->assertWPError( Options::save( 'rj_contact', array( 'phones' => array( array_diff_key( $phone, array( 'whatsapp' => 0 ) ) ) ) ) );
	}

	/**
	 * Unknown keys are rejected at every level, and update_option keeps the old value.
	 */
	public function test_unknown_keys_rejected(): void {
		$this->assertTrue( Options::save( 'rj_features', array( 'accounts' => true ) ) );

		$this->assertWPError( Options::save( 'rj_features', array( 'bogus' => true ) ) );
		$this->assertWPError( Options::save( 'rj_contact', array( 'social' => array( 'twitter' => 'https://example.com' ) ) ) );
		$this->assertWPError( Options::save( 'rj_notifications', array( 'driver_credentials' => array( 'sms' => 'x' ) ) ) );

		update_option( 'rj_features', array( 'chatbot' => 'yes' ) );

		$this->assertTrue( Options::get( 'rj_features' )['accounts'] );
		$this->assertFalse( Options::get( 'rj_features' )['chatbot'] );
	}

	/**
	 * WhatsApp numbers are stored in one canonical form: 91 prefix, digits only
	 * (Platform Architecture §4.7).
	 */
	public function test_whatsapp_numbers_normalised(): void {
		foreach ( array( '+91 12345 67890', '91-1234567890', '(012) 3456-7890', '1234567890' ) as $input ) {
			$this->assertTrue( Options::save( 'rj_whatsapp', array( 'active_number' => $input ) ), $input );
			$this->assertSame( '911234567890', get_option( 'rj_whatsapp' )['active_number'], $input );
			$this->assertSame( '911234567890', Options::get( 'rj_whatsapp' )['active_number'], $input );
		}

		update_option( 'rj_whatsapp', array_merge( Options::get( 'rj_whatsapp' ), array( 'fallback_number' => '+91 98765 43210' ) ) );
		$this->assertSame( '919876543210', Options::get( 'rj_whatsapp' )['fallback_number'] );

		foreach ( array( '12345', '+44 20 7946 0958', '0000000000', 'call me', '91 12345 6789x', 919876543210 ) as $input ) {
			$this->assertWPError( Options::save( 'rj_whatsapp', array( 'active_number' => $input ) ), (string) $input );
		}

		$this->assertTrue( Options::save( 'rj_whatsapp', array( 'active_number' => '' ) ) );
		$this->assertSame( '', Options::get( 'rj_whatsapp' )['active_number'] );
	}

	/**
	 * Primary_showroom is 0 or the ID of an existing rj_showroom post.
	 */
	public function test_primary_showroom_must_be_a_showroom(): void {
		$showroom = self::factory()->post->create( array( 'post_type' => 'rj_showroom' ) );
		$product  = self::factory()->post->create( array( 'post_type' => 'rj_product' ) );

		$this->assertTrue( Options::save( 'rj_contact', array( 'primary_showroom' => 0 ) ) );
		$this->assertTrue( Options::save( 'rj_contact', array( 'primary_showroom' => $showroom ) ) );
		$this->assertSame( $showroom, Options::get( 'rj_contact' )['primary_showroom'] );

		$this->assertWPError( Options::save( 'rj_contact', array( 'primary_showroom' => $product ) ) );
		$this->assertWPError( Options::save( 'rj_contact', array( 'primary_showroom' => $showroom + 100000 ) ) );
		$this->assertWPError( Options::save( 'rj_contact', array( 'primary_showroom' => (string) $showroom ) ) );
		$this->assertSame( $showroom, Options::get( 'rj_contact' )['primary_showroom'] );
	}

	/**
	 * Open_graph.default_image_id is 0 or the ID of an existing attachment.
	 */
	public function test_open_graph_image_must_be_an_attachment(): void {
		$attachment = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		$post       = self::factory()->post->create();

		$this->assertTrue( Options::save( 'rj_seo', array( 'open_graph' => array( 'default_image_id' => 0 ) ) ) );
		$this->assertTrue( Options::save( 'rj_seo', array( 'open_graph' => array( 'default_image_id' => $attachment ) ) ) );
		$this->assertSame( $attachment, Options::get( 'rj_seo' )['open_graph']['default_image_id'] );

		$this->assertWPError( Options::save( 'rj_seo', array( 'open_graph' => array( 'default_image_id' => $post ) ) ) );
		$this->assertWPError( Options::save( 'rj_seo', array( 'open_graph' => array( 'default_image_id' => $attachment + 100000 ) ) ) );
		$this->assertSame( $attachment, Options::get( 'rj_seo' )['open_graph']['default_image_id'] );
	}

	/**
	 * WhatsApp templates accept only their §16 tokens and at most 900 characters.
	 */
	public function test_whatsapp_template_tokens(): void {
		$this->assertTrue( Options::save( 'rj_whatsapp', array( 'templates' => array( 'bridal' => 'Bridal {url}' ) ) ) );
		$this->assertWPError( Options::save( 'rj_whatsapp', array( 'templates' => array( 'bridal' => 'Bridal {code}' ) ) ) );
		$this->assertWPError( Options::save( 'rj_whatsapp', array( 'templates' => array( 'reel' => str_repeat( 'x', 901 ) ) ) ) );
	}

	/**
	 * A corrupt stored value falls back to defaults, never null.
	 */
	public function test_corrupt_value_falls_back_to_defaults(): void {
		delete_option( 'rj_display' );
		add_option( 'rj_display', 'garbage' );

		$this->assertSame( Options::defaults( 'rj_display' ), Options::get( 'rj_display' ) );
	}

	/**
	 * Invalid initial writes to writable groups are rejected.
	 */
	public function test_invalid_initial_add_option_writes_are_rejected(): void {
		delete_option( 'rj_display' );
		$this->assertTrue( add_option( 'rj_display', array( 'items_per_page' => 99 ) ) );
		$this->assertSame( Options::defaults( 'rj_display' ), get_option( 'rj_display' ) );

		delete_option( 'rj_features' );
		$this->assertTrue( add_option( 'rj_features', array( 'unknown' => true ) ) );
		$this->assertSame( Options::defaults( 'rj_features' ), get_option( 'rj_features' ) );
	}

	/**
	 * Every feature flag is off by default, and rj_features.chatbot is the only chatbot switch.
	 */
	public function test_single_chatbot_gate(): void {
		$this->assertNotContains( true, Options::defaults( 'rj_features' ) );

		$switches = array();

		foreach ( Options::schemas() as $group => $schema ) {
			foreach ( array_keys( $schema['fields'] ) as $field ) {
				if ( str_contains( $field, 'chatbot' ) || ( 'rj_chatbot' === $group && str_contains( $field, 'enable' ) ) ) {
					$switches[] = $group . '.' . $field;
				}
			}
		}

		$this->assertSame( array( 'rj_features.chatbot' ), $switches );
	}

	/**
	 * Public weight is off by default (C-11).
	 */
	public function test_weight_default_false(): void {
		$this->assertFalse( Options::defaults( 'rj_display' )['show_weight_publicly'] );
		$this->assertFalse( Options::get( 'rj_display' )['show_weight_publicly'] );
	}

	/**
	 * Sensitive values are masked and never reach core settings REST.
	 */
	public function test_sensitive_values_protected(): void {
		$auth = Options::defaults( 'rj_auth_providers' );

		$auth['google']['client_secret'] = 'secret-value';

		$notify                       = Options::defaults( 'rj_notifications' );
		$notify['driver_credentials'] = array( 'webpush' => 'private-key' );

		$this->assertSame( '********', Options::redacted( 'rj_auth_providers', $auth )['google']['client_secret'] );
		$this->assertSame( array( 'webpush' => '********' ), Options::redacted( 'rj_notifications', $notify )['driver_credentials'] );

		$this->assertWPError( Options::save( 'rj_auth_providers', $auth ) );
		$this->assertStringNotContainsString( 'secret-value', (string) wp_json_encode( get_option( 'rj_auth_providers' ) ) );

		$this->assertWPError( Options::save( 'rj_notifications', $notify ) );
		$this->assertStringNotContainsString( 'private-key', (string) wp_json_encode( get_option( 'rj_notifications' ) ) );

		$this->assertTrue(
			Options::save(
				'rj_auth_providers',
				array(
					'google' => array(
						'enabled'   => true,
						'client_id' => 'public-client-id',
					),
				)
			)
		);
		$this->assertSame( 'public-client-id', get_option( 'rj_auth_providers' )['google']['client_id'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$settings = (array) rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/settings' ) )->get_data();

		foreach ( Options::names() as $group ) {
			$this->assertArrayNotHasKey( $group, $settings, $group );
		}

		$this->assertStringNotContainsString( 'secret-value', (string) wp_json_encode( $settings ) );
	}

	/**
	 * The migration-owned groups cannot be written through save().
	 */
	public function test_internal_groups_not_writable(): void {
		foreach ( Options::INTERNAL as $group ) {
			$this->assertWPError( Options::save( $group, array() ), $group );
		}
	}

	/**
	 * No chatbot table exists: still exactly the eight Stage 3 tables.
	 */
	public function test_no_chatbot_table(): void {
		$this->assertCount( 8, Schema::names() );

		foreach ( Schema::names() as $table ) {
			$this->assertStringNotContainsString( 'chat', $table );
			$this->assertStringNotContainsString( 'transcript', $table );
		}
	}

	/**
	 * Only rj_contact, rj_whatsapp, rj_display and rj_features autoload.
	 */
	public function test_autoload(): void {
		global $wpdb;

		foreach ( Options::schemas() as $group => $schema ) {
			Options::save( $group, Options::defaults( $group ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test inspection.
			$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $group ) );

			$this->assertSame( $schema['autoload'], in_array( $autoload, array( 'yes', 'on', 'auto-on' ), true ), $group );
		}
	}
}
