<?php

declare(strict_types=1);

namespace TranslateSense\Includes\Integrations\WC;

use TranslateSense\Includes\Content\Parser\Custom_Fields\Custom_Field_Parser;
use TranslateSense\Includes\Content\Parser\Taxonomy\Taxonomy_Parser;
use TranslateSense\Includes\Database\Hashing_Db;
use TranslateSense\Includes\Integrations\Polylang\Polylang_Bridge;
use WC_Product;
use WC_Product_Attribute;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates WooCommerce product attributes (both global pa_* taxonomies and custom product attributes).
 */
class Product_Attribute_Translator {

	/**
	 * Browser-based AI providers that execute on the client device without WP-Cron.
	 */
	public const BROWSER_PROVIDERS = array( 'chrome_ai', 'chrome_local_ai', 'edge_ai' );

	/**
	 * Static context storage for execution metadata (provider, in_memory_translations).
	 *
	 * @var array
	 */
	private static array $current_context = array();

	/**
	 * Recursion guard.
	 *
	 * @var bool
	 */
	private static bool $translating = false;

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'translatesense_after_save_translation', array( __CLASS__, 'translate_attributes' ), 10, 6 );
	}

	/**
	 * Set active execution context.
	 *
	 * @param array $context Context array.
	 */
	public static function set_current_context( array $context ): void {
		self::$current_context = $context;
	}

	/**
	 * Get active execution context.
	 *
	 * @return array
	 */
	public static function get_current_context(): array {
		return self::$current_context;
	}

	/**
	 * Clear active execution context.
	 */
	public static function clear_current_context(): void {
		self::$current_context = array();
	}

	/**
	 * Extract translatable WooCommerce product strings and record them in Hashing_Db.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $lang    Source language slug.
	 * @param string $builder Builder slug.
	 * @return array Extracted package array items.
	 */
	public static function extract_translatable_product_strings( int $post_id, string $lang, string $builder ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product = wc_get_product( $post_id );
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$builder = sanitize_key( $builder );
		if ( empty( $builder ) && class_exists( 'TranslateSense\\Includes\\Admin\\Editor_Integration' ) ) {
			$builder = \TranslateSense\Includes\Admin\Editor_Integration::get_post_editor_type( $post_id );
		}
		if ( 'core' === $builder ) {
			$builder = 'gutenberg';
		}

		$extracted = array();

		// 1. Custom product attributes (labels and options)
		$attributes = (array) $product->get_attributes();
		foreach ( $attributes as $attr_key => $attr ) {
			if ( ! $attr instanceof WC_Product_Attribute || $attr->is_taxonomy() ) {
				continue;
			}

			// Attribute label
			$label = trim( (string) $attr->get_name() );
			if ( '' !== $label && ! Custom_Field_Parser::is_only_numbers( $label ) ) {
				$field_key   = 'wc_attr_label_' . $attr_key;
				$hash        = Hashing_Db::generate_hash( $field_key, $label );
				$string_hash = md5( $label );
				$string_id   = Hashing_Db::get_or_create_string_id( $label, $label );

				if ( $string_id > 0 ) {
					Hashing_Db::record_package(
						$post_id,
						$lang,
						$hash,
						$builder,
						$field_key,
						sprintf( 'Product Attribute: %s', $label ),
						$label,
						$label,
						$string_hash
					);
					$extracted[] = array(
						'hash'        => $hash,
						'name'        => $field_key,
						'key'         => sprintf( 'Product Attribute: %s', $label ),
						'source'      => $label,
						'value'       => $label,
						'string_hash' => $string_hash,
					);
				}
			}

			// Attribute options
			$raw_options = $attr->get_options();
			$options     = is_array( $raw_options ) ? $raw_options : array_filter( array_map( 'trim', explode( '|', (string) $raw_options ) ) );

			foreach ( $options as $opt_idx => $opt_val ) {
				$opt_val = trim( (string) $opt_val );
				if ( '' !== $opt_val && ! Custom_Field_Parser::is_only_numbers( $opt_val ) ) {
					$field_key   = 'wc_attr_opt_' . $attr_key . '_' . $opt_idx;
					$hash        = Hashing_Db::generate_hash( $field_key, $opt_val );
					$string_hash = md5( $opt_val );
					$string_id   = Hashing_Db::get_or_create_string_id( $opt_val, $opt_val );

					if ( $string_id > 0 ) {
						Hashing_Db::record_package(
							$post_id,
							$lang,
							$hash,
							$builder,
							$field_key,
							sprintf( 'Attribute %s Option: %s', $label, $opt_val ),
							$opt_val,
							$opt_val,
							$string_hash
						);
						$extracted[] = array(
							'hash'        => $hash,
							'name'        => $field_key,
							'key'         => sprintf( 'Attribute %s Option: %s', $label, $opt_val ),
							'source'      => $opt_val,
							'value'       => $opt_val,
							'string_hash' => $string_hash,
						);
					}
				}
			}
		}

		// 2. Variable product variations (descriptions and configured translatable metadata)
		if ( $product->is_type( 'variable' ) ) {
			$variation_ids = (array) $product->get_children();
			$user_rules    = class_exists( '\TranslateSense\Includes\XML\Xml_Engine' )
				? \TranslateSense\Includes\XML\Xml_Engine::get_user_custom_field_rules( 'all' )
				: array();

			foreach ( $variation_ids as $var_id ) {
				$var_id = absint( $var_id );
				if ( $var_id <= 0 ) {
					continue;
				}
				$var_obj = wc_get_product( $var_id );
				if ( ! $var_obj instanceof \WC_Product_Variation ) {
					continue;
				}

				// Variation description
				$desc = trim( (string) $var_obj->get_description() );
				if ( '' !== $desc && ! Custom_Field_Parser::is_only_numbers( $desc ) ) {
					$field_key   = 'wc_var_desc_' . $var_id;
					$hash        = Hashing_Db::generate_hash( $field_key, $desc );
					$string_hash = md5( $desc );
					$string_id   = Hashing_Db::get_or_create_string_id( $desc, $desc );

					if ( $string_id > 0 ) {
						Hashing_Db::record_package(
							$post_id,
							$lang,
							$hash,
							$builder,
							$field_key,
							sprintf( 'Variation #%d Description', $var_id ),
							$desc,
							$desc,
							$string_hash
						);
						$extracted[] = array(
							'hash'            => $hash,
							'name'            => $field_key,
							'key'             => sprintf( 'Variation #%d Description', $var_id ),
							'source'          => $desc,
							'value'           => $desc,
							'string_hash'     => $string_hash,
							'type'            => 'woocommerce',
							'wc_type'         => 'variation_description',
							'variation_id'    => $var_id,
							'variation_field' => 'description',
						);
					}
				}

				// Variation configured translatable custom fields
				if ( ! empty( $user_rules ) && class_exists( Custom_Field_Parser::class ) && class_exists( '\TranslateSense\Includes\XML\Custom_Field_Rule_Resolver' ) ) {
					$fields   = Custom_Field_Parser::get_post_fields( $var_id, 'all' );
					$resolved = \TranslateSense\Includes\XML\Custom_Field_Rule_Resolver::resolve_all_rules( $fields, $user_rules );
					$cf_items = Custom_Field_Parser::extract_strings( $var_id, $fields, $resolved );

					foreach ( $cf_items as $cf_item ) {
						$meta_key = (string) ( $cf_item['key'] ?? '' );
						$meta_val = (string) ( $cf_item['value'] ?? '' );
						if ( '' === $meta_key || '' === trim( $meta_val ) ) {
							continue;
						}
						$field_key = 'wc_var_meta_' . $var_id . '_' . $meta_key;
						$hash      = Hashing_Db::generate_hash( $field_key, $meta_val );

						Hashing_Db::record_package(
							$post_id,
							$lang,
							$hash,
							$builder,
							$field_key,
							sprintf( 'Variation #%1$d Meta: %2$s', $var_id, $meta_key ),
							$meta_val,
							$meta_val,
							md5( $meta_val )
						);

						$extracted[] = array(
							'hash'         => $hash,
							'name'         => $field_key,
							'key'          => sprintf( 'Variation #%1$d Meta: %2$s', $var_id, $meta_key ),
							'source'       => $meta_val,
							'value'        => $meta_val,
							'string_hash'  => md5( $meta_val ),
							'type'         => 'woocommerce',
							'wc_type'      => 'variation_meta',
							'variation_id' => $var_id,
							'meta_key'     => $meta_key,
						);
					}
				}
			}
		}

		return $extracted;
	}

	/**
	 * Alias for extract_translatable_product_strings().
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $lang    Source language slug.
	 * @param string $builder Builder slug.
	 * @return array Extracted package array items.
	 */
	public static function extract_product_strings( int $post_id, string $lang = '', string $builder = '' ): array {
		return self::extract_translatable_product_strings( $post_id, $lang, $builder );
	}

	/**
	 * Ensure WooCommerce product strings are extracted and persisted to the package table.
	 *
	 * @param int    $post_id     Source post ID.
	 * @param string $source_lang Source language.
	 * @param string $editor_type Editor type / builder.
	 */
	public static function ensure_product_package_prepared( int $post_id, string $source_lang, string $editor_type ): void {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		$editor_type = sanitize_key( $editor_type );
		if ( empty( $editor_type ) && class_exists( 'TranslateSense\\Includes\\Admin\\Editor_Integration' ) ) {
			$editor_type = \TranslateSense\Includes\Admin\Editor_Integration::get_post_editor_type( $post_id );
		}
		if ( 'core' === $editor_type ) {
			$editor_type = 'gutenberg';
		}
		self::extract_translatable_product_strings( $post_id, $source_lang, $editor_type );
	}

	/**
	 * Translate product attributes when a product is translated.
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param int    $target_post_id Saved target post ID.
	 * @param string $target_lang    Target language slug.
	 * @param bool   $is_existing    Whether an existing translation was updated.
	 * @param array  $sync_result    Synchronization result and warnings.
	 * @param array  $context        Execution context including provider and in_memory_translations.
	 */
	public static function translate_attributes( int $source_post_id, int $target_post_id, string $target_lang, bool $is_existing = false, array $sync_result = array(), array $context = array() ): void {
		if ( self::$translating ) {
			return;
		}

		$source_post = get_post( $source_post_id );
		if ( ! $source_post || 'product' !== $source_post->post_type ) {
			return;
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$source_product = wc_get_product( $source_post_id );
		$target_product = wc_get_product( $target_post_id );

		if ( ! $source_product instanceof WC_Product || ! $target_product instanceof WC_Product ) {
			return;
		}

		$target_lang = sanitize_key( $target_lang );
		if ( '' === $target_lang ) {
			return;
		}

		$source_lang = sanitize_key( (string) Polylang_Bridge::get_post_language( $source_post_id ) );
		if ( '' === $source_lang ) {
			$source_lang = sanitize_key( (string) Polylang_Bridge::get_default_language() );
		}

		// Fallback to static context if context parameter was not provided by caller
		if ( empty( $context ) ) {
			$context = self::get_current_context();
		}

		$provider               = sanitize_key( (string) ( $context['provider'] ?? '' ) );
		$in_memory_translations = is_array( $context['in_memory_translations'] ?? null ) ? $context['in_memory_translations'] : array();

		self::$translating = true;

		try {
			self::process_product_attributes( $source_product, $target_product, $source_lang, $target_lang, $provider, $in_memory_translations );
		} finally {
			self::$translating = false;
		}
	}

	/**
	 * Translate a list of strings using existing translation memory, in-memory translations,
	 * or the configured translation provider. Never makes server AI calls if provider is browser-based.
	 *
	 * @param array<string, string> $strings                Associative array of key => string.
	 * @param string                $source_lang            Source language slug.
	 * @param string                $target_lang            Target language slug.
	 * @param string                $provider               Provider slug (e.g. 'chrome_ai', 'gemini').
	 * @param array                 $in_memory_translations In-memory translations map.
	 * @param string                $prefix                 Optional prefix used in package keys.
	 * @return array<string, string> Translated strings keyed by same keys.
	 */
	public static function translate_strings(
		array $strings,
		string $source_lang,
		string $target_lang,
		string $provider = '',
		array $in_memory_translations = array(),
		string $prefix = ''
	): array {
		if ( empty( $strings ) || $source_lang === $target_lang ) {
			return $strings;
		}

		$results  = array();
		$uncached = array();

		// 1. Check in-memory translations and translation memory (DB cache)
		foreach ( $strings as $key => $str ) {
			$str     = (string) $str;
			$key_str = (string) $key;
			if ( '' === trim( $str ) || Custom_Field_Parser::is_only_numbers( $str ) ) {
				$results[ $key ] = $str;
				continue;
			}

			// Check in-memory translations first
			$matched = null;
			if ( ! empty( $in_memory_translations ) ) {
				if ( isset( $in_memory_translations[ $str ] ) && '' !== trim( (string) $in_memory_translations[ $str ] ) ) {
					$matched = (string) $in_memory_translations[ $str ];
				} elseif ( isset( $in_memory_translations[ md5( $str ) ] ) && '' !== trim( (string) $in_memory_translations[ md5( $str ) ] ) ) {
					$matched = (string) $in_memory_translations[ md5( $str ) ];
				} elseif ( '' !== $prefix && isset( $in_memory_translations[ $prefix . $key_str ] ) && '' !== trim( (string) $in_memory_translations[ $prefix . $key_str ] ) ) {
					$matched = (string) $in_memory_translations[ $prefix . $key_str ];
				} elseif ( isset( $in_memory_translations[ $key_str ] ) && '' !== trim( (string) $in_memory_translations[ $key_str ] ) ) {
					$matched = (string) $in_memory_translations[ $key_str ];
				} else {
					// Check by generated package hashes
					$candidate_keys = array( $key_str );
					if ( '' !== $prefix ) {
						$candidate_keys[] = $prefix . $key_str;
					}
					if ( 0 === strpos( $key_str, 'label_' ) || 0 === strpos( $key_str, 'opt_' ) ) {
						$candidate_keys[] = 'wc_attr_' . $key_str;
					}
					foreach ( $candidate_keys as $c_key ) {
						$c_hash = Hashing_Db::generate_hash( $c_key, $str );
						if ( isset( $in_memory_translations[ $c_hash ] ) && '' !== trim( (string) $in_memory_translations[ $c_hash ] ) ) {
							$matched = (string) $in_memory_translations[ $c_hash ];
							break;
						}
					}
				}
			}

			if ( null !== $matched ) {
				$results[ $key ] = $matched;
				continue;
			}

			// Check translation memory DB cache by string hash (md5)
			$cached = Hashing_Db::get_cached_translation( md5( $str ), $target_lang );
			if ( null !== $cached && '' !== trim( $cached ) ) {
				$results[ $key ] = $cached;
			} else {
				$uncached[ $key ] = $str;
			}
		}

		if ( empty( $uncached ) ) {
			return $results;
		}

		// Fallback: retain original source string without making any secondary AI requests.
		// All translatable WooCommerce strings are extracted and translated during the
		// normal post translation pipeline (WP-Cron backend AI or Browser AI).
		foreach ( $uncached as $key => $orig ) {
			$results[ $key ] = $orig;
		}

		return $results;
	}

	/**
	 * Process and translate attributes from source product to target product.
	 *
	 * @param WC_Product $source_product         Source WooCommerce product.
	 * @param WC_Product $target_product         Target WooCommerce product.
	 * @param string     $source_lang            Source language slug.
	 * @param string     $target_lang            Target language slug.
	 * @param string     $provider               Provider slug.
	 * @param array      $in_memory_translations In-memory translations map.
	 */
	public static function process_product_attributes( WC_Product $source_product, WC_Product $target_product, string $source_lang, string $target_lang, string $provider = '', array $in_memory_translations = array() ): void {
		$source_attributes = $source_product->get_attributes();
		if ( empty( $source_attributes ) ) {
			return;
		}

		$target_post_id = (int) $target_product->get_id();

		// 1. Collect all custom attribute labels and options for batch translation
		$custom_strings = array();
		foreach ( $source_attributes as $attr_key => $source_attr ) {
			if ( ! $source_attr instanceof WC_Product_Attribute || $source_attr->is_taxonomy() ) {
				continue;
			}

			$label = trim( (string) $source_attr->get_name() );
			if ( '' !== $label && ! Custom_Field_Parser::is_only_numbers( $label ) ) {
				$custom_strings[ 'label_' . $attr_key ] = $label;
			}

			$raw_options = $source_attr->get_options();
			$options     = is_array( $raw_options ) ? $raw_options : array_filter( array_map( 'trim', explode( '|', (string) $raw_options ) ) );

			foreach ( $options as $opt_idx => $opt_val ) {
				$opt_val = trim( (string) $opt_val );
				if ( '' !== $opt_val && ! Custom_Field_Parser::is_only_numbers( $opt_val ) ) {
					$custom_strings[ 'opt_' . $attr_key . '_' . $opt_idx ] = $opt_val;
				}
			}
		}

		$translated_custom = ! empty( $custom_strings )
			? self::translate_strings( $custom_strings, $source_lang, $target_lang, $provider, $in_memory_translations, 'wc_attr_' )
			: array();

		// 2. Build target product attributes
		$target_attributes = array();

		foreach ( $source_attributes as $attr_key => $source_attr ) {
			if ( ! $source_attr instanceof WC_Product_Attribute ) {
				continue;
			}

			$target_attr = new WC_Product_Attribute();
			$target_attr->set_position( (int) $source_attr->get_position() );
			$target_attr->set_visible( (bool) $source_attr->get_visible() );
			$target_attr->set_variation( (bool) $source_attr->get_variation() );

			$is_taxonomy = $source_attr->is_taxonomy() || ( 0 === strpos( (string) $source_attr->get_name(), 'pa_' ) && taxonomy_exists( (string) $source_attr->get_name() ) );

			if ( $is_taxonomy ) {
				// Global attribute (pa_* taxonomy)
				$taxonomy = sanitize_key( (string) $source_attr->get_name() );
				$attr_id  = (int) $source_attr->get_id();
				if ( $attr_id <= 0 && function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
					$attr_id = (int) wc_attribute_taxonomy_id_by_name( $taxonomy );
				}
				$target_attr->set_id( $attr_id );
				$target_attr->set_name( $taxonomy );

				$source_term_ids = (array) $source_attr->get_options();
				$target_term_ids = array();

				foreach ( $source_term_ids as $source_term_id ) {
					$source_term_id = absint( $source_term_id );
					if ( $source_term_id <= 0 ) {
						continue;
					}

					// Look up existing translation in target language
					$translated_term_id = Taxonomy_Parser::get_translation_id( $source_term_id, $target_lang );

					// Direct Polylang fallback
					if ( $translated_term_id <= 0 && function_exists( 'pll_get_term' ) ) {
						$translated_term_id = absint( pll_get_term( $source_term_id, $target_lang ) );
					}

					$final_term_id     = ( $translated_term_id > 0 ) ? $translated_term_id : $source_term_id;
					$target_term_ids[] = $final_term_id;
				}

				$target_term_ids = array_values( array_unique( array_filter( $target_term_ids ) ) );
				$target_attr->set_options( $target_term_ids );

				// Associate translated terms with target product post
				if ( taxonomy_exists( $taxonomy ) ) {
					wp_set_object_terms( $target_post_id, $target_term_ids, $taxonomy );
				}

				$target_attributes[ $attr_key ] = $target_attr;
			} else {
				// Custom attribute
				$target_attr->set_id( 0 );

				// Translated label
				$source_name     = (string) $source_attr->get_name();
				$translated_name = $translated_custom[ 'label_' . $attr_key ] ?? $source_name;
				$target_attr->set_name( $translated_name );

				// Translated options
				$raw_options    = $source_attr->get_options();
				$options        = is_array( $raw_options ) ? $raw_options : array_filter( array_map( 'trim', explode( '|', (string) $raw_options ) ) );
				$target_options = array();

				foreach ( $options as $opt_idx => $opt_val ) {
					$opt_val          = (string) $opt_val;
					$target_options[] = $translated_custom[ 'opt_' . $attr_key . '_' . $opt_idx ] ?? $opt_val;
				}

				$target_attr->set_options( $target_options );
				$target_attributes[ $attr_key ] = $target_attr;
			}
		}

		$target_product->set_attributes( $target_attributes );
		$target_product->save();
	}

	/**
	 * Build attribute value mapping between source product and target product.
	 * Used by Product_Variation_Synchronizer to map variation attributes.
	 *
	 * @param WC_Product $source_product Source product.
	 * @param WC_Product $target_product Target product.
	 * @param string     $target_lang    Target language slug.
	 * @return array Mapping information keyed by attribute slug/name.
	 */
	public static function build_attribute_map( WC_Product $source_product, WC_Product $target_product, string $target_lang ): array {
		$source_attributes = $source_product->get_attributes();
		$target_attributes = $target_product->get_attributes();
		$map               = array();

		foreach ( $source_attributes as $attr_key => $source_attr ) {
			if ( ! $source_attr instanceof WC_Product_Attribute ) {
				continue;
			}

			$is_taxonomy = $source_attr->is_taxonomy() || ( 0 === strpos( (string) $source_attr->get_name(), 'pa_' ) && taxonomy_exists( (string) $source_attr->get_name() ) );

			if ( $is_taxonomy ) {
				$taxonomy = sanitize_key( (string) $source_attr->get_name() );
				$term_map = array();

				$source_term_ids = (array) $source_attr->get_options();
				foreach ( $source_term_ids as $source_term_id ) {
					$source_term_id = absint( $source_term_id );
					if ( $source_term_id <= 0 ) {
						continue;
					}

					$source_term = get_term( $source_term_id, $taxonomy );
					if ( ! $source_term instanceof WP_Term ) {
						continue;
					}

					$trans_term_id = Taxonomy_Parser::get_translation_id( $source_term_id, $target_lang );
					if ( $trans_term_id <= 0 && function_exists( 'pll_get_term' ) ) {
						$trans_term_id = absint( pll_get_term( $source_term_id, $target_lang ) );
					}
					$target_term = ( $trans_term_id > 0 ) ? get_term( $trans_term_id, $taxonomy ) : $source_term;

					if ( $target_term instanceof WP_Term ) {
						$term_map[ $source_term->slug ] = $target_term->slug;
						$term_map[ $source_term->name ] = $target_term->name;
					}
				}

				$map[ $taxonomy ] = array(
					'is_taxonomy' => true,
					'target_key'  => $taxonomy,
					'terms'       => $term_map,
				);
			} else {
				// Custom attribute
				$val_map            = array();
				$raw_source_options = $source_attr->get_options();
				$source_options     = is_array( $raw_source_options ) ? array_values( $raw_source_options ) : array_filter( array_map( 'trim', explode( '|', (string) $raw_source_options ) ) );

				// Target attribute might be keyed by attr_key or by sanitize_title(target_name)
				$target_attr = $target_attributes[ $attr_key ] ?? null;
				if ( ! $target_attr instanceof WC_Product_Attribute ) {
					$sanitized_source_key = sanitize_title( (string) $source_attr->get_name() );
					$target_attr          = $target_attributes[ $sanitized_source_key ] ?? null;
				}
				if ( ! $target_attr instanceof WC_Product_Attribute ) {
					// Match by position rather than selecting an arbitrary custom attribute.
					$source_position = (int) $source_attr->get_position();
					foreach ( $target_attributes as $t_attr ) {
						if (
							$t_attr instanceof WC_Product_Attribute
							&& ! $t_attr->is_taxonomy()
							&& (int) $t_attr->get_position() === $source_position
						) {
							$target_attr = $t_attr;
							break;
						}
					}
				}

				$target_key = $attr_key;
				if ( $target_attr instanceof WC_Product_Attribute ) {
					$target_key         = sanitize_title( (string) $target_attr->get_name() );
					$raw_target_options = $target_attr->get_options();
					$target_options     = is_array( $raw_target_options ) ? array_values( $raw_target_options ) : array_filter( array_map( 'trim', explode( '|', (string) $raw_target_options ) ) );

					foreach ( $source_options as $idx => $source_val ) {
						$source_val                               = (string) $source_val;
						$target_val                               = isset( $target_options[ $idx ] ) ? (string) $target_options[ $idx ] : $source_val;
						$val_map[ $source_val ]                   = $target_val;
						$val_map[ sanitize_title( $source_val ) ] = sanitize_title( $target_val );
					}
				}

				$map_entry = array(
					'is_taxonomy' => false,
					'target_key'  => $target_key,
					'values'      => $val_map,
				);

				$map[ $attr_key ] = $map_entry;
				$map[ sanitize_title( (string) $source_attr->get_name() ) ] = $map_entry;
			}
		}

		return $map;
	}
}
