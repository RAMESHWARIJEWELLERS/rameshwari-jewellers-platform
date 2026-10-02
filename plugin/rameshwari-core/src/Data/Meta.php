<?php
/**
 * Meta key registry.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\Data;

use Rameshwari\Core\Container;
use Rameshwari\Core\Module;
use Rameshwari\Core\Support\Sanitizer;

/**
 * Registers every meta key of Development Blueprint §9 with a type, a REST
 * schema, a sanitiser and an explicit authorisation callback.
 *
 * Keys marked private in §9 are not shown in REST. The three derived
 * category caches have an authorisation callback that refuses everyone.
 * _rj_weight and _rj_weight_unit are removed from public product responses
 * while rj_display.show_weight_publicly is off (C-11).
 *
 * Cross-field and engine rules — WhatsApp token sets, provider host
 * allow-lists, primary-term assignment, start-before-end — belong to the
 * stages that own those engines. Here each value is sanitised on its own.
 */
final class Meta implements Module {

	public const FLAT_TERM_TAXONOMIES = array( 'rj_metal', 'rj_purity', 'rj_occasion', 'rj_audience' );

	/**
	 * Private, system-owned: the first time a product was published (Stage 6, Option A).
	 */
	public const PUBLICATION_MARKER = '_rj_first_published_at';

	private const DERIVED = array( '_rj_depth_cache', '_rj_path_cache', '_rj_count_deep' );

	private const CATEGORY_ONLY = array( '_rj_depth_cache', '_rj_path_cache', '_rj_count_deep', '_rj_menu_columns' );

	/**
	 * Post meta per post type: key => [ rule, default, public ].
	 *
	 * @return array<string, array<string, array{0: string, 1: mixed, 2: bool}>>
	 */
	public static function post_meta(): array {
		return array(
			'rj_product'      => array(
				'_rj_code'               => array( 'code', '', true ),
				'_rj_name_hi'            => array( 'text:191', '', true ),
				'_rj_name_en'            => array( 'text:191', '', true ),
				'_rj_weight'             => array( 'decimal:3:0:99999', 0, true ),
				'_rj_weight_unit'        => array( 'enum:g|tola|carat', 'g', true ),
				'_rj_gallery'            => array( 'attachments:12', array(), true ),
				'_rj_stone_details'      => array( 'textarea:500', '', true ),
				'_rj_making_note'        => array( 'textarea:500', '', false ),
				'_rj_whatsapp_message'   => array( 'textarea:0', '', false ),
				'_rj_visibility'         => array( 'enum:public|hidden|archived', 'public', true ),
				'_rj_featured'           => array( 'bool', false, true ),
				'_rj_primary_term'       => array( 'term:rj_category', 0, true ),
				'_rj_view_count'         => array( 'int:0:', 0, false ),
				'_rj_enquiry_count'      => array( 'int:0:', 0, false ),
				'_rj_first_published_at' => array( 'datetime', '', false ),
			),
			'rj_reel'         => array(
				'_rj_source_type'     => array( 'enum:upload|instagram|youtube|url', 'upload', true ),
				'_rj_source_url'      => array( 'url', '', true ),
				'_rj_video_id'        => array( 'alnum', '', true ),
				'_rj_attachment_id'   => array( 'video', 0, true ),
				'_rj_poster_id'       => array( 'attachment', 0, true ),
				'_rj_linked_products' => array( 'products:8', array(), true ),
				'_rj_caption'         => array( 'text:300', '', true ),
				'_rj_visibility'      => array( 'enum:public|hidden|archived', 'public', true ),
				'_rj_expires_at'      => array( 'datetime', '', true ),
			),
			'rj_collection'   => array(
				'_rj_cover_id'  => array( 'attachment', 0, true ),
				'_rj_badge'     => array( 'text:24', '', true ),
				'_rj_order'     => array( 'int:0:9999', 0, true ),
				'_rj_auto_rule' => array( 'object:3', array(), false ),
			),
			'rj_showroom'     => array(
				'_rj_address'  => array( 'textarea:300', '', true ),
				'_rj_city'     => array( 'text:0', '', true ),
				'_rj_state'    => array( 'text:0', '', true ),
				'_rj_pincode'  => array( 'pincode', '', true ),
				'_rj_phone'    => array( 'phone', '', true ),
				'_rj_whatsapp' => array( 'phone', '', true ),
				'_rj_timings'  => array( 'timings', array(), true ),
				'_rj_map_url'  => array( 'url', '', true ),
				'_rj_lat'      => array( 'decimal:7:-90:90', 0, true ),
				'_rj_lng'      => array( 'decimal:7:-180:180', 0, true ),
				'_rj_gallery'  => array( 'attachments:0', array(), true ),
			),
			'rj_testimonial'  => array(
				'_rj_author'   => array( 'text:120', '', true ),
				'_rj_rating'   => array( 'int:0:5', 0, true ),
				'_rj_photo_id' => array( 'attachment', 0, true ),
				'_rj_context'  => array( 'text:0', '', true ),
				'_rj_source'   => array( 'text:0', '', true ),
				'_rj_order'    => array( 'int:0:9999', 0, true ),
			),
			'rj_page_section' => array(
				'_rj_section_key'   => array( 'enum:hero|about|bridal_band|trust_strip|footer_note', 'hero', true ),
				'_rj_title_hi'      => array( 'text:120', '', true ),
				'_rj_title_en'      => array( 'text:120', '', true ),
				'_rj_description'   => array( 'textarea:300', '', true ),
				'_rj_cta_label'     => array( 'text:40', '', true ),
				'_rj_cta_url'       => array( 'cta', '', true ),
				'_rj_image_desktop' => array( 'image', 0, true ),
				'_rj_image_mobile'  => array( 'image', 0, true ),
				'_rj_active'        => array( 'bool', false, true ),
				'_rj_starts_at'     => array( 'datetime', '', true ),
				'_rj_ends_at'       => array( 'datetime', '', true ),
				'_rj_order'         => array( 'int:0:9999', 0, true ),
			),
		);
	}

	/**
	 * Category term meta (§9.3). Flat taxonomies carry the same keys minus the four cache and menu fields.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: bool}>
	 */
	public static function term_meta(): array {
		return array(
			'_rj_order'        => array( 'int:0:99999', 0, true ),
			'_rj_visible'      => array( 'bool', true, true ),
			'_rj_featured'     => array( 'bool', false, true ),
			'_rj_name_hi'      => array( 'text:191', '', true ),
			'_rj_name_en'      => array( 'text:191', '', true ),
			'_rj_name_alt'     => array( 'text:191', '', true ),
			'_rj_image_id'     => array( 'attachment', 0, true ),
			'_rj_icon_id'      => array( 'attachment', 0, true ),
			'_rj_seo_title'    => array( 'text:70', '', true ),
			'_rj_seo_desc'     => array( 'text:160', '', true ),
			'_rj_seo_noindex'  => array( 'bool', false, true ),
			'_rj_menu_columns' => array( 'int:1:5', 4, true ),
			'_rj_depth_cache'  => array( 'int:0:20', 0, false ),
			'_rj_path_cache'   => array( 'path', '', false ),
			'_rj_count_deep'   => array( 'int:0:', 0, true ),
			'_rj_resolver'     => array( 'resolver', '', true ),
		);
	}

	/**
	 * Customer user meta (§9.5): key => [ rule, default, writable by the owner ].
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: bool}>
	 */
	public static function user_meta(): array {
		return array(
			'_rj_phone'           => array( 'phone', '', true ),
			'_rj_phone_verified'  => array( 'bool', false, false ),
			'_rj_signup_source'   => array( 'enum:email|google|otp|admin|import', 'email', false ),
			'_rj_signup_context'  => array( 'local_url', '', false ),
			'_rj_last_login'      => array( 'datetime', '', false ),
			'_rj_login_methods'   => array( 'methods', array(), false ),
			'_rj_identity_google' => array( 'text:191', '', false ),
			'_rj_fav_collections' => array( 'collections:0', array(), true ),
			'_rj_notify_prefs'    => array( 'object:3', array(), true ),
			'_rj_consent'         => array( 'object:3', array(), false ),
		);
	}

	/**
	 * Module id.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'meta';
	}

	/**
	 * Needs the post types, taxonomies and display options.
	 *
	 * @return array<int, string>
	 */
	public static function requires(): array {
		return array( PostTypes::id(), Taxonomies::id(), Options::id() );
	}

	/**
	 * Hooks registration to init and the weight filter to product responses.
	 *
	 * @param Container $container Shared container.
	 * @return void
	 */
	public function register( Container $container ): void {
		add_action( 'init', array( self::class, 'register_all' ), PostTypes::PRIORITY );
		add_filter( 'rest_prepare_rj_product', array( self::class, 'filter_weight' ), 10, 3 );
		add_filter( 'rest_pre_insert_rj_category', array( self::class, 'reject_resolver_rest_write' ), 10, 2 );
	}

	/**
	 * Registers every key.
	 *
	 * @return void
	 */
	public static function register_all(): void {
		foreach ( self::post_meta() as $post_type => $keys ) {
			foreach ( $keys as $key => $spec ) {
				if ( self::PUBLICATION_MARKER === $key ) {
					$auth = '__return_false';
				} elseif ( 'rj_page_section' === $post_type ) {
					$auth = static fn(): bool => current_user_can( 'rj_manage_settings' );
				} else {
					$auth = static fn( bool $allowed, string $meta_key, int $object_id ): bool => current_user_can( 'edit_post', $object_id );
				}

				// Product visibility passes through unsanitised so ProductGuard can refuse an invalid value instead of it being coerced to public.
				$pass_through = 'rj_product' === $post_type && '_rj_visibility' === $key;

				register_post_meta( $post_type, $key, self::args( $spec, $auth, $pass_through ) );
			}
		}

		foreach ( self::term_meta() as $key => $spec ) {
			if ( '_rj_resolver' === $key ) {
				register_term_meta( 'rj_category', $key, self::args( $spec, array( self::class, 'authorize_resolver' ) ) );
				continue;
			}

			$auth = in_array( $key, self::DERIVED, true )
				? '__return_false'
				: static fn(): bool => current_user_can( 'rj_manage_categories' );

			register_term_meta( 'rj_category', $key, self::args( $spec, $auth ) );

			if ( in_array( $key, self::CATEGORY_ONLY, true ) ) {
				continue;
			}

			foreach ( self::FLAT_TERM_TAXONOMIES as $taxonomy ) {
				register_term_meta( $taxonomy, $key, self::args( $spec, static fn(): bool => current_user_can( 'rj_manage_catalogue' ) ) );
			}

			foreach ( array( 'rj_tag' ) as $taxonomy ) {
				if ( in_array( $key, array( '_rj_name_hi', '_rj_name_en', '_rj_seo_title' ), true ) ) {
					register_term_meta( $taxonomy, $key, self::args( $spec, static fn(): bool => current_user_can( 'rj_manage_catalogue' ) ) );
				}
			}
		}

		foreach ( self::user_meta() as $key => $spec ) {
			$auth = $spec[2]
				? static fn( bool $allowed, string $meta_key, int $user_id ): bool => current_user_can( 'edit_user', $user_id )
				: '__return_false';

			register_meta( 'user', $key, self::args( array( $spec[0], $spec[1], false ), $auth ) );
		}
	}

	/**
	 * Resolver writes require the category capability and are never accepted over REST.
	 *
	 * @return bool
	 */
	public static function authorize_resolver(): bool {
		return current_user_can( 'rj_manage_categories' );
	}

	/**
	 * Resolver changes are structural and cannot be submitted through native REST.
	 *
	 * @param mixed            $prepared_term Prepared term or error.
	 * @param \WP_REST_Request $request       Request.
	 * @return mixed
	 */
	public static function reject_resolver_rest_write( $prepared_term, $request ) {
		$meta = $request->get_param( 'meta' );

		if ( is_array( $meta ) && array_key_exists( '_rj_resolver', $meta ) ) {
			return new \WP_Error( 'rj_resolver_read_only', 'Resolver mappings cannot be changed over REST.', array( 'status' => 403 ) );
		}

		return $prepared_term;
	}

	/**
	 * Removes weight from a product response while the public switch is off,
	 * unless the reader may edit the product.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Product.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public static function filter_weight( $response, $post, $request ) {
		$display = Options::get( 'rj_display' );

		if ( ! empty( $display['show_weight_publicly'] ) || current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			unset( $data['meta']['_rj_weight'], $data['meta']['_rj_weight_unit'] );
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Sanitises one value by rule.
	 *
	 * @param string $rule  Rule.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	public static function sanitize( string $rule, mixed $value ): mixed {
		$parts = explode( ':', $rule );

		switch ( $parts[0] ) {
			case 'text':
				return Sanitizer::text( $value, (int) $parts[1] );
			case 'textarea':
				return Sanitizer::textarea( $value, (int) $parts[1] );
			case 'code':
				return substr( (string) preg_replace( '/[^A-Z0-9-]/', '', strtoupper( Sanitizer::text( $value ) ) ), 0, 64 );
			case 'alnum':
				return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', Sanitizer::text( $value ) );
			case 'int':
				return Sanitizer::int( $value, 0, (int) $parts[1], '' === $parts[2] ? null : (int) $parts[2] );
			case 'decimal':
				return Sanitizer::decimal( $value, (int) $parts[1], 0.0, (float) $parts[2], (float) $parts[3] );
			case 'bool':
				return Sanitizer::bool( $value );
			case 'enum':
				$allowed = explode( '|', $parts[1] );
				return Sanitizer::enum( $value, $allowed, $allowed[0] );
			case 'url':
				return Sanitizer::url( $value );
			case 'local_url':
				$url = Sanitizer::url( $value );
				return '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ? $url : '';
			case 'cta':
				return self::cta( $value );
			case 'datetime':
				return self::datetime( $value );
			case 'pincode':
				$pin = (string) preg_replace( '/\D/', '', Sanitizer::text( $value ) );
				return 6 === strlen( $pin ) ? $pin : '';
			case 'phone':
				$phone = Sanitizer::text( $value );

				if ( '' === trim( $phone ) ) {
					return '';
				}

				if ( 1 !== preg_match( '/^\+?[\d\s().-]+$/', $phone ) ) {
					return '';
				}

				$digits = (string) preg_replace( '/\D/', '', $phone );

				if ( 12 === strlen( $digits ) && str_starts_with( $digits, '09' ) ) {
					return '91' . substr( $digits, 2 );
				}

				if ( 10 === strlen( $digits ) ) {
					return '91' . $digits;
				}

				if ( str_starts_with( $digits, '0' ) ) {
					$digits = substr( $digits, 1 );

					if ( 10 === strlen( $digits ) ) {
						return '91' . $digits;
					}
				}

				return 12 === strlen( $digits ) && str_starts_with( $digits, '91' ) ? $digits : '';
			case 'path':
				return (string) preg_replace( '/[^\d\/]/', '', Sanitizer::text( $value ) );
			case 'attachment':
				return self::post_of( $value, 'attachment', '' );
			case 'image':
				return self::post_of( $value, 'attachment', 'image/' );
			case 'video':
				return self::post_of( $value, 'attachment', 'video/' );
			case 'term':
				$term = get_term( Sanitizer::int( $value, 0, 0 ), $parts[1] );
				return $term instanceof \WP_Term ? $term->term_id : 0;
			case 'attachments':
				return self::posts_of( $value, 'attachment', (int) $parts[1] );
			case 'products':
				return self::posts_of( $value, 'rj_product', (int) $parts[1] );
			case 'collections':
				return self::posts_of( $value, 'rj_collection', (int) $parts[1] );
			case 'methods':
				$allowed = array( 'password', 'google', 'otp', 'magic' );
				return array_values( array_intersect( $allowed, is_array( $value ) ? array_map( 'strval', $value ) : array() ) );
			case 'timings':
				return self::timings( $value );
			case 'object':
				return is_array( $value ) && self::depth( $value ) <= (int) $parts[1] ? $value : array();
			case 'resolver':
				return self::resolver( $value );
		}

		return null;
	}

	/**
	 * Register_meta arguments for one key.
	 *
	 * @param array{0: string, 1: mixed, 2: bool} $spec Rule, default, public.
	 * @param callable|string                     $auth Authorisation callback.
	 * @param bool                                $pass_through Keep the raw string so a guard can refuse invalid input.
	 * @return array<string, mixed>
	 */
	private static function args( array $spec, callable|string $auth, bool $pass_through = false ): array {
		list( $rule, $default, $public ) = $spec;

		$type = self::type( $rule );

		return array(
			'type'              => $type,
			'single'            => true,
			'default'           => $default,
			'sanitize_callback' => $pass_through
				? static fn( mixed $value ): mixed => is_scalar( $value ) ? (string) $value : ''
				: static fn( mixed $value ): mixed => self::sanitize( $rule, $value ),
			'auth_callback'     => $auth,
			'show_in_rest'      => $public ? array( 'schema' => self::schema( $rule, $type ) ) : false,
		);
	}

	/**
	 * WordPress meta type for a rule.
	 *
	 * @param string $rule Rule.
	 * @return string
	 */
	private static function type( string $rule ): string {
		$name = strtok( $rule, ':' );

		return match ( $name ) {
			'int', 'attachment', 'image', 'video', 'term' => 'integer',
			'decimal' => 'number',
			'bool' => 'boolean',
			'attachments', 'products', 'collections', 'methods' => 'array',
			'timings', 'object', 'resolver' => 'object',
			default => 'string',
		};
	}

	/**
	 * REST schema for a rule.
	 *
	 * @param string $rule Rule.
	 * @param string $type Meta type.
	 * @return array<string, mixed>
	 */
	private static function schema( string $rule, string $type ): array {
		$parts  = explode( ':', $rule );
		$schema = array( 'type' => $type );

		if ( 'enum' === $parts[0] ) {
			$schema['enum'] = explode( '|', $parts[1] );
		} elseif ( 'int' === $parts[0] ) {
			$schema['minimum'] = (int) $parts[1];
			if ( '' !== $parts[2] ) {
				$schema['maximum'] = (int) $parts[2];
			}
		} elseif ( 'decimal' === $parts[0] ) {
			$schema['minimum'] = (float) $parts[2];
			$schema['maximum'] = (float) $parts[3];
		} elseif ( in_array( $parts[0], array( 'text', 'textarea' ), true ) && (int) $parts[1] > 0 ) {
			$schema['maxLength'] = (int) $parts[1];
		} elseif ( 'array' === $type ) {
			$schema['items'] = array( 'type' => 'methods' === $parts[0] ? 'string' : 'integer' );
			if ( isset( $parts[1] ) && (int) $parts[1] > 0 ) {
				$schema['maxItems'] = (int) $parts[1];
			}
		} elseif ( 'timings' === $parts[0] ) {
			$day                            = array(
				'type'       => 'object',
				'properties' => array(
					'open'   => array( 'type' => 'string' ),
					'close'  => array( 'type' => 'string' ),
					'closed' => array( 'type' => 'boolean' ),
				),
			);
			$schema['properties']           = array_fill_keys( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ), $day );
			$schema['additionalProperties'] = false;
		} elseif ( 'resolver' === $parts[0] ) {
			$schema['properties']           = array(
				'tax'   => array(
					'type' => 'string',
					'enum' => self::FLAT_TERM_TAXONOMIES,
				),
				'slug'  => array( 'type' => 'string' ),
				'terms' => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
					'minItems' => 2,
					'maxItems' => 12,
				),
				'paths' => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'string' ),
					'minItems' => 2,
					'maxItems' => 12,
				),
			);
			$schema['additionalProperties'] = false;
		}

		return $schema;
	}

	/**
	 * Validates the locked resolver object without seeding or resolving mappings.
	 *
	 * @param mixed $value Resolver value.
	 * @return array<string, mixed>|string
	 */
	private static function resolver( mixed $value ): array|string {
		if ( '' === $value ) {
			return '';
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		if ( 2 === count( $value ) && isset( $value['tax'], $value['slug'] ) && in_array( $value['tax'], self::FLAT_TERM_TAXONOMIES, true ) && is_string( $value['slug'] ) && '' !== sanitize_title( $value['slug'] ) ) {
			return array(
				'tax'  => $value['tax'],
				'slug' => sanitize_title( $value['slug'] ),
			);
		}

		if ( 2 !== count( $value ) || ! isset( $value['terms'], $value['paths'] ) || ! is_array( $value['terms'] ) || ! is_array( $value['paths'] ) || count( $value['terms'] ) < 2 || count( $value['terms'] ) > 12 || count( $value['terms'] ) !== count( $value['paths'] ) ) {
			return '';
		}

		$terms = array_values( array_filter( $value['terms'], static fn( mixed $id ): bool => is_int( $id ) && $id > 0 ) );
		$paths = array_values( array_filter( $value['paths'], static fn( mixed $path ): bool => is_string( $path ) && '' !== $path ) );

		return count( $terms ) === count( $value['terms'] ) && count( $paths ) === count( $value['paths'] )
			? array(
				'terms' => $terms,
				'paths' => $paths,
			)
			: '';
	}

	/**
	 * An id if it names a post of the given type and MIME prefix, else 0.
	 *
	 * @param mixed  $value     Raw id.
	 * @param string $post_type Required post type.
	 * @param string $mime      Required MIME prefix, or empty.
	 * @return int
	 */
	private static function post_of( mixed $value, string $post_type, string $mime ): int {
		$id = Sanitizer::int( $value, 0, 0 );

		if ( 0 === $id || get_post_type( $id ) !== $post_type ) {
			return 0;
		}

		return '' === $mime || str_starts_with( (string) get_post_mime_type( $id ), $mime ) ? $id : 0;
	}

	/**
	 * Ids that name posts of the given type, in order, capped.
	 *
	 * @param mixed  $value     Raw list.
	 * @param string $post_type Required post type.
	 * @param int    $max       Maximum items, 0 for none.
	 * @return array<int, int>
	 */
	private static function posts_of( mixed $value, string $post_type, int $max ): array {
		$ids = array_values( array_filter( Sanitizer::id_list( $value ), static fn( int $id ): bool => get_post_type( $id ) === $post_type ) );

		return $max > 0 ? array_slice( $ids, 0, $max ) : $ids;
	}

	/**
	 * A site-local path, or a WhatsApp template key.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function cta( mixed $value ): string {
		$value = Sanitizer::text( $value );

		if ( 1 === preg_match( '/^whatsapp:(product|reel|collection|bridal|showroom)$/', $value ) ) {
			return $value;
		}

		if ( str_starts_with( $value, '/' ) && ! str_starts_with( $value, '//' ) ) {
			return esc_url_raw( $value );
		}

		$url = Sanitizer::url( $value );

		return '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ? $url : '';
	}

	/**
	 * A Y-m-d H:i:s datetime in the site timezone, or empty.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function datetime( mixed $value ): string {
		$value = Sanitizer::text( $value );

		if ( '' === $value ) {
			return '';
		}

		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, wp_timezone() );

		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value ? $value : '';
	}

	/**
	 * Seven days, each open and close times or closed.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, array<string, mixed>>
	 */
	private static function timings( mixed $value ): array {
		$clean = array();

		foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) as $day ) {
			$raw = is_array( $value ) && isset( $value[ $day ] ) && is_array( $value[ $day ] ) ? $value[ $day ] : array();

			if ( ! empty( $raw['closed'] ) ) {
				$clean[ $day ] = array( 'closed' => true );
				continue;
			}

			$open  = isset( $raw['open'] ) ? Sanitizer::text( $raw['open'] ) : '';
			$close = isset( $raw['close'] ) ? Sanitizer::text( $raw['close'] ) : '';

			if ( 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $open ) && 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $close ) ) {
				$clean[ $day ] = array(
					'open'  => $open,
					'close' => $close,
				);
			}
		}

		return $clean;
	}

	/**
	 * Nesting depth of an array.
	 *
	 * @param array<mixed> $value Array.
	 * @return int
	 */
	private static function depth( array $value ): int {
		$max = 1;

		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$max = max( $max, 1 + self::depth( $item ) );
			}
		}

		return $max;
	}
}
