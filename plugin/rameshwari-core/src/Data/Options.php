<?php
/**
 * Settings registry.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Data;

use Rameshwari\Core\Container;
use Rameshwari\Core\Module;

/**
 * The ten settings groups of Development Blueprint §10, with the field
 * contract of §10.2 (Decision B, locked).
 *
 * Eight groups are written through save() or update_option(); every write
 * is normalised against the contract. Unknown keys, wrong types, bad enum
 * values and out-of-range values are rejected and the stored value is left
 * unchanged. Missing top-level and nested object fields keep their stored
 * or default value. save() reads the value back and reports a mismatch.
 *
 * rj_db_version and rj_install_state are written only by the migration
 * router; their contract defaults are exposed by defaults() but they are
 * not writable here.
 *
 * Schema defaults are never business data: phone numbers, emails and
 * links are configured separately.
 */
final class Options implements Module {

	public const NAMES = array( 'rj_contact', 'rj_whatsapp', 'rj_display', 'rj_features', 'rj_auth_providers', 'rj_notifications', 'rj_seo', 'rj_db_version', 'rj_install_state', 'rj_chatbot' );

	public const INTERNAL = array( 'rj_db_version', 'rj_install_state' );

	private const MASK = '********';

	/**
	 * Contract per writable group.
	 *
	 * @return array<string, array{autoload: bool, fields: array<string, array<string, mixed>>}>
	 */
	public static function schemas(): array {
		return array(
			'rj_contact'        => array(
				'autoload' => true,
				'fields'   => array(
					'phones'           => self::records(
						10,
						array(
							'label'    => self::text( 40, '' ),
							'number'   => self::e164( null ),
							'whatsapp' => self::boolean( false ),
						)
					),
					'emails'           => self::records(
						10,
						array(
							'label'   => self::text( 40, '' ),
							'address' => self::email(),
						)
					),
					'social'           => self::object(
						array(
							'instagram' => self::url( '' ),
							'facebook'  => self::url( '' ),
							'youtube'   => self::url( '' ),
							'maps'      => self::url( '' ),
						)
					),
					'locality'         => self::text( 191, '' ),
					'primary_showroom' => self::post_ref( 'rj_showroom' ),
				),
			),
			'rj_whatsapp'       => array(
				'autoload' => true,
				'fields'   => array(
					'active_number'   => self::wa_number(),
					'fallback_number' => self::wa_number(),
					'templates'       => self::object(
						array(
							'product'    => self::template( 900, array( 'code', 'name_hi', 'name_en', 'metal', 'purity', 'weight', 'url' ), 'नमस्ते, मुझे {name_hi} ({code}) के बारे में जानकारी चाहिए। {url}' ),
							'reel'       => self::template( 900, array( 'title', 'url', 'linked_codes' ), 'नमस्ते, मुझे इस reel में दिखाई गई ज्वेलरी के बारे में जानकारी चाहिए। {url}' ),
							'collection' => self::template( 900, array( 'name', 'url' ), 'नमस्ते, मुझे {name} कलेक्शन देखना है। {url}' ),
							'bridal'     => self::template( 900, array( 'url' ), 'नमस्ते, मुझे ब्राइडल ज्वेलरी के बारे में बात करनी है। {url}' ),
							'showroom'   => self::template( 900, array( 'branch', 'address', 'url' ), 'नमस्ते, मुझे {branch} शोरूम आना है। समय बता दीजिए।' ),
						)
					),
					'availability'    => self::object(
						array(
							'mode'          => self::choice( array( 'always_available', 'showroom_hours' ), 'showroom_hours' ),
							'outside_hours' => self::choice( array( 'keep_primary', 'use_fallback', 'call_us' ), 'use_fallback' ),
						)
					),
				),
			),
			'rj_display'        => array(
				'autoload' => true,
				'fields'   => array(
					'language_lead'        => self::choice( array( 'hi', 'en' ), 'hi' ),
					'grid_density'         => self::choice( array( 'comfortable', 'compact' ), 'comfortable' ),
					'items_per_page'       => self::integer( 12, 48, 24 ),
					'reel_autoplay'        => self::boolean( false ),
					'lazy_thresholds'      => self::object(
						array(
							'near_viewport_px' => self::integer( 0, 2000, 300 ),
						)
					),
					'show_weight_publicly' => self::boolean( false ),
				),
			),
			'rj_features'       => array(
				'autoload' => true,
				'fields'   => array(
					'accounts'      => self::boolean( false ),
					'google_login'  => self::boolean( false ),
					'notifications' => self::boolean( false ),
					'visual_edit'   => self::boolean( false ),
					'enquiry_list'  => self::boolean( false ),
					'hero_slider'   => self::boolean( false ),
					'chatbot'       => self::boolean( false ),
				),
			),
			'rj_auth_providers' => array(
				'autoload' => false,
				'fields'   => array(
					'google' => self::object(
						array(
							'enabled'       => self::boolean( false ),
							'client_id'     => self::text( 0, '' ),
							'client_secret' => self::text( 0, '' ),
						)
					),
				),
			),
			'rj_notifications'  => array(
				'autoload' => false,
				'fields'   => array(
					'channel_enablement' => self::object(
						array(
							'email'        => self::boolean( true ),
							'admin_notice' => self::boolean( true ),
							'webpush'      => self::boolean( false ),
							'firebase'     => self::boolean( false ),
							'onesignal'    => self::boolean( false ),
							'whatsapp'     => self::boolean( false ),
						)
					),
					'driver_credentials' => self::map( array( 'email', 'admin_notice', 'webpush', 'firebase', 'onesignal', 'whatsapp' ) ),
					'throttles'          => self::object(
						array(
							'push_per_customer_per_day'  => self::integer( 0, null, 1 ),
							'email_per_customer_per_day' => self::integer( 0, null, 3 ),
						)
					),
					'digest_schedule'    => self::object(
						array(
							'frequency' => self::choice( array( 'daily', 'weekly' ), 'daily' ),
							'time'      => self::clock( '19:00' ),
							'timezone'  => self::choice( array( 'site' ), 'site' ),
						)
					),
					'quiet_hours'        => self::object(
						array(
							'start'    => self::clock( '22:00' ),
							'end'      => self::clock( '08:00' ),
							'timezone' => self::choice( array( 'site' ), 'site' ),
						)
					),
				),
			),
			'rj_seo'            => array(
				'autoload' => false,
				'fields'   => array(
					'title_patterns'  => self::object(
						array(
							'site'       => self::text( 191, '{{site_name}}' ),
							'product'    => self::text( 191, '{{name_en}} | {{site_name}}' ),
							'category'   => self::text( 191, '{{name_en}} | {{site_name}}' ),
							'collection' => self::text( 191, '{{name}} | {{site_name}}' ),
							'reel'       => self::text( 191, '{{title}} | {{site_name}}' ),
							'showroom'   => self::text( 191, '{{branch}} | {{site_name}}' ),
						)
					),
					'structured_data' => self::object(
						array(
							'organization' => self::boolean( true ),
							'product'      => self::boolean( true ),
							'breadcrumb'   => self::boolean( true ),
							'showroom'     => self::boolean( true ),
						)
					),
					'open_graph'      => self::object(
						array(
							'enabled'             => self::boolean( true ),
							'default_title'       => self::text( 0, '' ),
							'default_description' => self::text( 0, '' ),
							'default_image_id'    => self::post_ref( 'attachment' ),
						)
					),
					'sitemap'         => self::object(
						array(
							'products'    => self::boolean( true ),
							'categories'  => self::boolean( true ),
							'collections' => self::boolean( true ),
							'reels'       => self::boolean( true ),
							'showrooms'   => self::boolean( true ),
						)
					),
				),
			),
			'rj_chatbot'        => array(
				'autoload' => false,
				'fields'   => array(
					'welcome'           => self::text( 500, '' ),
					'fallback'          => self::text( 500, '' ),
					'human_support'     => self::text( 500, '' ),
					'knowledge_sources' => self::object(
						array(
							'products'    => self::boolean( true ),
							'categories'  => self::boolean( true ),
							'collections' => self::boolean( true ),
							'reels'       => self::boolean( true ),
							'showrooms'   => self::boolean( true ),
							'contact'     => self::boolean( true ),
							'faq'         => self::boolean( true ),
							'policies'    => self::boolean( false ),
						)
					),
					'capabilities'      => self::object(
						array(
							'product_search'       => self::boolean( true ),
							'category_search'      => self::boolean( true ),
							'showroom_information' => self::boolean( true ),
							'whatsapp_handoff'     => self::boolean( true ),
						)
					),
					'provider'          => self::text( 64, '' ),
					'faq_entries'       => self::records(
						50,
						array(
							'question_hi' => self::text( 300, '' ),
							'question_en' => self::text( 300, '' ),
							'answer_hi'   => self::text( 1000, '' ),
							'answer_en'   => self::text( 1000, '' ),
						)
					),
					'privacy'           => self::object(
						array(
							'retain_conversations' => self::boolean( false ),
							'retention_days'       => self::integer( 0, 365, 0 ),
						)
					),
					'logging'           => self::object(
						array(
							'usage_counters'     => self::boolean( true ),
							'debug_logging'      => self::boolean( false ),
							'transcript_storage' => self::boolean( false ),
						)
					),
				),
			),
		);
	}

	/**
	 * All ten group names, rj_chatbot tenth.
	 *
	 * @return array<int, string>
	 */
	public static function names(): array {
		return self::NAMES;
	}

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'options';
	}

	/**
	 * No dependencies.
	 *
	 * @return array<int, string>
	 */
	public static function requires(): array {
		return array();
	}

	/**
	 * Guards every write to a writable group.
	 *
	 * @param Container $container Shared container.
	 * @return void
	 */
	public function register( Container $container ): void {
		foreach ( array_keys( self::schemas() ) as $group ) {
			add_filter( 'pre_update_option_' . $group, array( self::class, 'guard' ), 10, 3 );
			add_filter( 'sanitize_option_' . $group, array( self::class, 'sanitize' ), 10, 2 );
		}
	}

	/**
	 * Contract default for any of the ten groups.
	 *
	 * @param string $group Group name.
	 * @return mixed
	 * @throws \InvalidArgumentException For an unknown group.
	 */
	public static function defaults( string $group ): mixed {
		if ( 'rj_db_version' === $group ) {
			return 1;
		}

		if ( 'rj_install_state' === $group ) {
			return array(
				'install_timestamp'       => null,
				'completed_migration_ids' => array(),
				'last_rebuild_times'      => array(),
				'seeded'                  => false,
			);
		}

		return self::default_of( self::spec( $group ) );
	}

	/**
	 * A writable group, complete; defaults for anything missing or corrupt.
	 *
	 * @param string $group Group name.
	 * @return array<string, mixed>
	 */
	public static function get( string $group ): array {
		$defaults = (array) self::defaults( $group );
		$stored   = get_option( $group, array() );
		$result   = is_array( $stored ) ? self::normalize( self::spec( $group ), $stored, $defaults, $group ) : array( false, null, '' );

		return $result[0] ? $result[1] : $defaults;
	}

	/**
	 * Normalises, writes, reads back and compares.
	 *
	 * @param string               $group Group name.
	 * @param array<string, mixed> $value Complete or partial group.
	 * @return true|\WP_Error
	 */
	public static function save( string $group, array $value ) {
		if ( ! isset( self::schemas()[ $group ] ) ) {
			return new \WP_Error( 'rj_invalid_option', 'Not a writable settings group.', array( 'group' => $group ) );
		}

		$result = self::normalize( self::spec( $group ), $value, self::get( $group ), $group );

		if ( ! $result[0] ) {
			return new \WP_Error( 'rj_invalid_option', $result[2], array( 'group' => $group ) );
		}

		update_option( $group, $result[1], self::schemas()[ $group ]['autoload'] );

		if ( get_option( $group ) !== $result[1] ) {
			return new \WP_Error( 'rj_option_not_saved', 'Saved value did not read back unchanged.', array( 'group' => $group ) );
		}

		return true;
	}

	/**
	 * Field error for a value, or null when it satisfies the contract.
	 *
	 * @param string       $group Group name.
	 * @param array<mixed> $value Value.
	 * @return string|null
	 */
	public static function validate( string $group, array $value ): ?string {
		if ( ! isset( self::schemas()[ $group ] ) ) {
			return 'Not a writable settings group.';
		}

		$result = self::normalize( self::spec( $group ), $value, self::get( $group ), $group );

		return $result[0] ? null : $result[2];
	}

	/**
	 * Pre_update_option filter: a value that fails the contract keeps the old one.
	 *
	 * @param mixed  $value     New value.
	 * @param mixed  $old_value Old value.
	 * @param string $option    Group name.
	 * @return mixed
	 */
	public static function guard( mixed $value, mixed $old_value, string $option ): mixed {
		if ( ! is_array( $value ) ) {
			return $old_value;
		}

		$result = self::normalize( self::spec( $option ), $value, self::get( $option ), $option );

		return $result[0] ? $result[1] : $old_value;
	}

	/**
	 * Sanitizes option creation, which does not pass through pre_update_option.
	 * Invalid input falls back to the current complete value (defaults when new).
	 *
	 * @param mixed  $value  New option value.
	 * @param string $option Group name.
	 * @return mixed
	 */
	public static function sanitize( mixed $value, string $option ): mixed {
		if ( ! is_array( $value ) ) {
			return self::get( $option );
		}

		$result = self::normalize( self::spec( $option ), $value, self::get( $option ), $option );

		return $result[0] ? $result[1] : self::get( $option );
	}

	/**
	 * A group value with sensitive fields masked, for admin display, logs and exports.
	 *
	 * @param string               $group Group name.
	 * @param array<string, mixed> $value Value.
	 * @return array<string, mixed>
	 */
	public static function redacted( string $group, array $value ): array {
		if ( 'rj_auth_providers' === $group && isset( $value['google']['client_secret'] ) && '' !== $value['google']['client_secret'] ) {
			$value['google']['client_secret'] = self::MASK;
		}

		if ( 'rj_notifications' === $group && isset( $value['driver_credentials'] ) && is_array( $value['driver_credentials'] ) ) {
			$value['driver_credentials'] = array_map( static fn(): string => self::MASK, $value['driver_credentials'] );
		}

		return $value;
	}

	/**
	 * The object spec of a writable group.
	 *
	 * @param string $group Group name.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException For a group that is not writable.
	 */
	private static function spec( string $group ): array {
		$schemas = self::schemas();

		if ( ! isset( $schemas[ $group ] ) ) {
			throw new \InvalidArgumentException( esc_html( 'No writable schema for option group: ' . $group ) );
		}

		return self::object( $schemas[ $group ]['fields'] );
	}

	/**
	 * Default of a spec.
	 *
	 * @param array<string, mixed> $spec Spec.
	 * @return mixed
	 */
	private static function default_of( array $spec ): mixed {
		if ( 'object' === $spec['type'] ) {
			return array_map( array( self::class, 'default_of' ), $spec['fields'] );
		}

		return in_array( $spec['type'], array( 'records', 'map', 'int_list' ), true ) ? array() : $spec['default'];
	}

	/**
	 * Normalises a value against a spec without coercion.
	 *
	 * @param array<string, mixed> $spec  Spec.
	 * @param mixed                $value Value.
	 * @param mixed                $base  Stored or default value that fills missing object keys, or null.
	 * @param string               $path  Field path for messages.
	 * @return array{0: bool, 1: mixed, 2: string}
	 */
	private static function normalize( array $spec, mixed $value, mixed $base, string $path ): array {
		switch ( $spec['type'] ) {
			case 'object':
				if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
					return self::fail( $path, 'must be an object' );
				}

				foreach ( array_keys( $value ) as $key ) {
					if ( ! isset( $spec['fields'][ $key ] ) ) {
						return self::fail( $path . '.' . $key, 'unknown key' );
					}
				}

				$out = array();

				foreach ( $spec['fields'] as $key => $child ) {
					if ( ! array_key_exists( $key, $value ) ) {
						if ( ! is_array( $base ) || ! array_key_exists( $key, $base ) ) {
							return self::fail( $path . '.' . $key, 'is required' );
						}

						$value[ $key ] = $base[ $key ];
					}

					$child_base = is_array( $base ) && array_key_exists( $key, $base ) ? $base[ $key ] : null;
					$result     = self::normalize( $child, $value[ $key ], $child_base, $path . '.' . $key );

					if ( ! $result[0] ) {
						return $result;
					}

					$out[ $key ] = $result[1];
				}

				if ( 'rj_auth_providers' === $path && '' !== $out['google']['client_secret'] ) {
					return self::fail( 'rj_auth_providers.google.client_secret', 'must be supplied through server-side configuration' );
				}

				if ( 'rj_notifications' === $path && array_filter( $out['driver_credentials'], static fn( mixed $credential ): bool => '' !== $credential && null !== $credential && array() !== $credential ) ) {
					return self::fail( 'rj_notifications.driver_credentials', 'must be supplied through server-side configuration' );
				}

				return array( true, $out, '' );

			case 'records':
				if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $spec['max'] ) {
					return self::fail( $path, 'must be a list of at most ' . $spec['max'] . ' items' );
				}

				$out = array();

				foreach ( $value as $index => $item ) {
					$result = self::normalize( self::object( $spec['fields'] ), $item, null, $path . '[' . $index . ']' );

					if ( ! $result[0] ) {
						return $result;
					}

					$out[] = $result[1];
				}

				return array( true, $out, '' );

			case 'map':
				if ( ! is_array( $value ) ) {
					return self::fail( $path, 'must be an object' );
				}

				foreach ( $value as $key => $item ) {
					if ( null !== $spec['keys'] && ! in_array( $key, $spec['keys'], true ) ) {
						return self::fail( $path . '.' . $key, 'unknown key' );
					}

					if ( ! is_string( $item ) && ! is_int( $item ) && ! is_array( $item ) ) {
						return self::fail( $path . '.' . $key, 'invalid value' );
					}
				}

				return array( true, $value, '' );

			case 'wa_number':
				$number = self::wa_number_of( $value );

				return null === $number ? self::fail( $path, 'cannot be normalised to a 91-prefixed number' ) : array( true, $number, '' );

			case 'int_list':
				$ok = is_array( $value ) && array_is_list( $value ) && array() === array_filter( $value, static fn( mixed $item ): bool => ! is_int( $item ) );

				return $ok ? array( true, $value, '' ) : self::fail( $path, 'must be a list of integers' );
		}

		return self::scalar( $spec, $value ) ? array( true, $value, '' ) : self::fail( $path, 'invalid value' );
	}

	/**
	 * Whether a scalar value satisfies its spec.
	 *
	 * @param array<string, mixed> $spec  Spec.
	 * @param mixed                $value Value.
	 * @return bool
	 */
	private static function scalar( array $spec, mixed $value ): bool {
		switch ( $spec['type'] ) {
			case 'bool':
				return is_bool( $value );
			case 'text':
				return is_string( $value ) && ( 0 === $spec['max'] || mb_strlen( $value ) <= $spec['max'] );
			case 'integer':
				return is_int( $value ) && $value >= $spec['min'] && ( null === $spec['max'] || $value <= $spec['max'] );
			case 'choice':
				return in_array( $value, $spec['values'], true );
			case 'email':
				return is_string( $value ) && false !== is_email( $value );
			case 'url':
				return is_string( $value ) && ( '' === $value || ( false !== filter_var( $value, FILTER_VALIDATE_URL ) && 1 === preg_match( '#^https?://#i', $value ) ) );
			case 'e164':
				return is_string( $value ) && ( ( '' === $value && '' === $spec['default'] ) || 1 === preg_match( '/^\+[1-9]\d{1,14}$/', $value ) );
			case 'clock':
				return is_string( $value ) && 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value );
			case 'template':
				if ( ! is_string( $value ) || mb_strlen( $value ) > $spec['max'] ) {
					return false;
				}

				preg_match_all( '/\{([a-z_]+)\}/', $value, $tokens );

				return array() === array_diff( $tokens[1], $spec['tokens'] );
			case 'post_ref':
				return is_int( $value ) && ( 0 === $value || get_post_type( $value ) === $spec['post_type'] );
			case 'datetime_or_null':
				if ( null === $value ) {
					return true;
				}

				$date = is_string( $value ) ? \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value ) : false;

				return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
		}

		return false;
	}

	/**
	 * A WhatsApp number in its canonical form, or null when it cannot be normalised.
	 *
	 * Platform Architecture §4.7: numbers are stored in E.164 form with the 91
	 * prefix, digits only. Spaces, hyphens, dots, brackets and a leading + are
	 * dropped; a ten-digit national number, with or without a leading 0, gets
	 * the 91 prefix; a twelve-digit number already starting 91 is kept. The
	 * empty string stays empty (the schema default).
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function wa_number_of( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		if ( '' === $value ) {
			return '';
		}

		$digits = (string) preg_replace( '/[\s().-]/', '', $value );
		$digits = str_starts_with( $digits, '+' ) ? substr( $digits, 1 ) : $digits;

		if ( 1 !== preg_match( '/^\d+$/', $digits ) ) {
			return null;
		}

		if ( 11 === strlen( $digits ) && str_starts_with( $digits, '0' ) ) {
			$digits = substr( $digits, 1 );
		}

		if ( 10 === strlen( $digits ) && ! str_starts_with( $digits, '0' ) ) {
			return '91' . $digits;
		}

		return 12 === strlen( $digits ) && str_starts_with( $digits, '91' ) && '0' !== $digits[2] ? $digits : null;
	}

	/**
	 * A failed normalisation.
	 *
	 * @param string $path    Field path.
	 * @param string $message Problem.
	 * @return array{0: false, 1: null, 2: string}
	 */
	private static function fail( string $path, string $message ): array {
		return array( false, null, $path . ': ' . $message );
	}

	/**
	 * Boolean field.
	 *
	 * @param bool $default_value Default.
	 * @return array<string, mixed>
	 */
	private static function boolean( bool $default_value ): array {
		return array(
			'type'    => 'bool',
			'default' => $default_value,
		);
	}

	/**
	 * String field; max 0 means no limit.
	 *
	 * @param int    $max     Maximum length.
	 * @param string $default_value Default.
	 * @return array<string, mixed>
	 */
	private static function text( int $max, string $default_value ): array {
		return array(
			'type'    => 'text',
			'max'     => $max,
			'default' => $default_value,
		);
	}

	/**
	 * Integer field.
	 *
	 * @param int      $min     Minimum.
	 * @param int|null $max     Maximum, or null.
	 * @param int      $default_value Default.
	 * @return array<string, mixed>
	 */
	private static function integer( int $min, ?int $max, int $default_value ): array {
		return array(
			'type'    => 'integer',
			'min'     => $min,
			'max'     => $max,
			'default' => $default_value,
		);
	}

	/**
	 * Enum field.
	 *
	 * @param array<int, string> $values  Allowed values.
	 * @param string             $default_value Default.
	 * @return array<string, mixed>
	 */
	private static function choice( array $values, string $default_value ): array {
		return array(
			'type'    => 'choice',
			'values'  => $values,
			'default' => $default_value,
		);
	}

	/**
	 * Email field inside a record; no default.
	 *
	 * @return array<string, mixed>
	 */
	private static function email(): array {
		return array(
			'type'    => 'email',
			'default' => null,
		);
	}

	/**
	 * URL field; empty allowed.
	 *
	 * @param string $default_value Default.
	 * @return array<string, mixed>
	 */
	private static function url( string $default_value ): array {
		return array(
			'type'    => 'url',
			'default' => $default_value,
		);
	}

	/**
	 * E.164 phone; empty allowed only when the default is empty.
	 *
	 * @param string|null $default_value Default, or null for a record field.
	 * @return array<string, mixed>
	 */
	private static function e164( ?string $default_value ): array {
		return array(
			'type'    => 'e164',
			'default' => $default_value,
		);
	}

	/**
	 * WhatsApp number, normalised on save; empty allowed (the default).
	 *
	 * @return array<string, mixed>
	 */
	private static function wa_number(): array {
		return array(
			'type'    => 'wa_number',
			'default' => '',
		);
	}

	/**
	 * ID of an existing post of one type; 0 means unset.
	 *
	 * @param string $post_type Required post type.
	 * @return array<string, mixed>
	 */
	private static function post_ref( string $post_type ): array {
		return array(
			'type'      => 'post_ref',
			'post_type' => $post_type,
			'default'   => 0,
		);
	}

	/**
	 * HH:MM field.
	 *
	 * @param string $default_value Default.
	 * @return array<string, mixed>
	 */
	private static function clock( string $default_value ): array {
		return array(
			'type'    => 'clock',
			'default' => $default_value,
		);
	}

	/**
	 * WhatsApp template with its permitted tokens (Development Blueprint §16).
	 *
	 * @param int                $max     Maximum length.
	 * @param array<int, string> $tokens  Permitted tokens.
	 * @param string             $default_value Default.
	 * @return array<string, mixed>
	 */
	private static function template( int $max, array $tokens, string $default_value ): array {
		return array(
			'type'    => 'template',
			'max'     => $max,
			'tokens'  => $tokens,
			'default' => $default_value,
		);
	}

	/**
	 * Object keyed by string, keys optionally limited.
	 *
	 * @param array<int, string>|null $keys Allowed keys, or null for any.
	 * @return array<string, mixed>
	 */
	private static function map( ?array $keys ): array {
		return array(
			'type' => 'map',
			'keys' => $keys,
		);
	}

	/**
	 * Object with exactly these fields.
	 *
	 * @param array<string, array<string, mixed>> $fields Fields.
	 * @return array<string, mixed>
	 */
	private static function object( array $fields ): array {
		return array(
			'type'   => 'object',
			'fields' => $fields,
		);
	}

	/**
	 * List of records, each an object with exactly these fields.
	 *
	 * @param int                                 $max    Maximum items.
	 * @param array<string, array<string, mixed>> $fields Record fields.
	 * @return array<string, mixed>
	 */
	private static function records( int $max, array $fields ): array {
		return array(
			'type'   => 'records',
			'max'    => $max,
			'fields' => $fields,
		);
	}
}
