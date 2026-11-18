<?php

declare(strict_types=1);

namespace TranslateSense\Includes\Integrations\WC;

use TranslateSense\Includes\Content\Parser\Custom_Fields\Custom_Field_Parser;
use TranslateSense\Includes\Content\Parser\Taxonomy\Taxonomy_Parser;
use TranslateSense\Includes\Database\Hashing_Db;
use TranslateSense\Includes\Integrations\Polylang\Polylang_Bridge;
use TranslateSense\Includes\Translation\Media\Media_Translator;
use TranslateSense\Includes\Translation\Taxonomy\Taxonomy_Translator;

use WC_Product;
use WC_Product_Attribute;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates WooCommerce product attributes (both global pa_* taxonomies and custom product attributes).
 */
class Product_Attribute_Translator extends WC_Base {
	/**
	 * Static context storage for execution metadata (provider, in_memory_translations).
	 *
	 * @var array
	 */
	private static array $current_context = array();

	/**
	 * Recursion guard for attributes translation.
	 *
	 * @var bool
	 */
	private static bool $translating = false;

	/**
	 * Recursion guard for downloadable-name and purchase-note translation.
	 *
	 * @var bool
	 */
	private static bool $syncing_downloads_and_note = false;

	/** Register the common translation hook and this class's additional hook. */
	public function __construct() {
		parent::__construct( 10 );
	}

	public function translate( int $source_post_id, int $target_post_id, string $target_lang, bool $is_existing = false, array $sync_result = array(), array $context = array() ): void {
		self::translate_attributes( $source_post_id, $target_post_id, $target_lang, $is_existing, $sync_result, $context );
		self::synchronize_downloads_and_note( $source_post_id, $target_post_id, $target_lang, $context );
		Media_Translator::synchronize_product_gallery( $source_post_id, $target_post_id, $target_lang, $context, $is_existing );
		self::clear_current_context();
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

		// 1. Custom product attributes. Global pa_* terms are handled by Taxonomy_Translator.
		$attributes = (array) $product->get_attributes();
		foreach ( $attributes as $attr_key => $attr ) {
			if ( ! $attr instanceof WC_Product_Attribute ) {
				continue;
			}

			$taxonomy = sanitize_key( (string) $attr->get_name() );
			if ( $attr->is_taxonomy() || ( 0 === strpos( $taxonomy, 'pa_' ) && taxonomy_exists( $taxonomy ) ) ) {
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

		// 3. WooCommerce downloadable file names
		$download_strings = self::extract_downloadable_file_strings( $product, $post_id, $lang, $builder );
		if ( ! empty( $download_strings ) ) {
			$extracted = array_merge( $extracted, $download_strings );
		}

		// 4. WooCommerce purchase note
		$note_strings = self::extract_purchase_note_string( $product, $post_id, $lang, $builder );
		if ( ! empty( $note_strings ) ) {
			$extracted = array_merge( $extracted, $note_strings );
		}

		return $extracted;
	}

	/**
	 * Extract translatable downloadable file names from a product and record them in Hashing_Db.
	 *
	 * @param WC_Product $product Source product.
	 * @param int        $post_id Source post ID.
	 * @param string     $lang    Source language slug.
	 * @param string     $builder Builder slug.
	 * @return array Extracted package array items.
	 */
	public static function extract_downloadable_file_strings( WC_Product $product, int $post_id, string $lang, string $builder ): array {
		$extracted = array();
		$downloads = $product->get_downloads();

		// Fallback to post meta if get_downloads() returned empty but _downloadable_files exists
		if ( empty( $downloads ) ) {
			$raw_meta = get_post_meta( $post_id, '_downloadable_files', true );
			if ( is_array( $raw_meta ) ) {
				$downloads = $raw_meta;
			}
		}

		if ( empty( $downloads ) || ! is_array( $downloads ) ) {
			return $extracted;
		}

		foreach ( $downloads as $key => $download ) {
			$file_id = '';
			$name    = '';

			if ( is_a( $download, 'WC_Product_Download' ) ) {
				$file_id = (string) $download->get_id();
				$name    = trim( (string) $download->get_name() );
			} elseif ( is_array( $download ) ) {
				$file_id = (string) ( $download['id'] ?? ( $download['download_id'] ?? $key ) );
				$name    = trim( (string) ( $download['name'] ?? '' ) );
			}

			if ( '' === $file_id || '' === $name || Custom_Field_Parser::is_only_numbers( $name ) ) {
				continue;
			}

			$field_key   = 'wc_download_name_' . $file_id;
			$hash        = Hashing_Db::generate_hash( $field_key, $name );
			$string_hash = md5( $name );
			$string_id   = Hashing_Db::get_or_create_string_id( $name, $name );

			if ( $string_id > 0 ) {
				Hashing_Db::record_package(
					$post_id,
					$lang,
					$hash,
					$builder,
					$field_key,
					sprintf( 'Downloadable File: %s', $name ),
					$name,
					$name,
					$string_hash
				);
				$extracted[] = array(
					'hash'        => $hash,
					'name'        => $field_key,
					'key'         => sprintf( 'Downloadable File: %s', $name ),
					'source'      => $name,
					'value'       => $name,
					'string_hash' => $string_hash,
					'type'        => 'woocommerce',
					'wc_type'     => 'downloadable_file_name',
					'file_id'     => $file_id,
				);
			}
		}

		return $extracted;
	}

	/**
	 * Extract translatable purchase note from a product and record it in Hashing_Db.
	 *
	 * @param WC_Product $product Source product.
	 * @param int        $post_id Source post ID.
	 * @param string     $lang    Source language slug.
	 * @param string     $builder Builder slug.
	 * @return array Extracted package array items.
	 */
	public static function extract_purchase_note_string( WC_Product $product, int $post_id, string $lang, string $builder ): array {
		$extracted = array();
		$note      = trim( (string) $product->get_purchase_note() );

		// Fallback to post meta if empty
		if ( '' === $note ) {
			$raw_meta = get_post_meta( $post_id, '_purchase_note', true );
			if ( is_string( $raw_meta ) ) {
				$note = trim( $raw_meta );
			}
		}

		if ( '' === $note || Custom_Field_Parser::is_only_numbers( $note ) ) {
			return $extracted;
		}

		$field_key   = 'wc_purchase_note';
		$hash        = Hashing_Db::generate_hash( $field_key, $note );
		$string_hash = md5( $note );
		$string_id   = Hashing_Db::get_or_create_string_id( $note, $note );

		if ( $string_id > 0 ) {
			Hashing_Db::record_package(
				$post_id,
				$lang,
				$hash,
				$builder,
				$field_key,
				'Product Purchase Note',
				$note,
				$note,
				$string_hash
			);
			$extracted[] = array(
				'hash'        => $hash,
				'name'        => $field_key,
				'key'         => 'Product Purchase Note',
				'source'      => $note,
				'value'       => $note,
				'string_hash' => $string_hash,
				'type'        => 'woocommerce',
				'wc_type'     => 'purchase_note',
			);
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

		$context = is_array( $context ?? null ) ? $context : array();

		self::$translating = true;

		try {
			self::process_product_attributes( $source_product, $target_product, $source_lang, $target_lang, $context );
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
	 * @param array                 $context In-memory translations map.
	 * @param string                $prefix                 Optional prefix used in package keys.
	 * @return array<string, string> Translated strings keyed by same keys.
	 */


	/**
	 * Process and translate attributes from source product to target product.
	 *
	 * @param WC_Product $source_product         Source WooCommerce product.
	 * @param WC_Product $target_product         Target WooCommerce product.
	 * @param string     $source_lang            Source language slug.
	 * @param string     $target_lang            Target language slug.
	 * @param array      $context                Context.
	 */
	public static function process_product_attributes( WC_Product $source_product, WC_Product $target_product, string $source_lang, string $target_lang, array $context = array() ): void {
		$source_attributes = $source_product->get_attributes();
		if ( empty( $source_attributes ) ) {
			return;
		}

		// Translate only custom attribute labels and values through the product string pipeline.
		$custom_strings = array();
		foreach ( $source_attributes as $attr_key => $source_attr ) {
			if ( ! $source_attr instanceof WC_Product_Attribute ) {
				continue;
			}

			$taxonomy = sanitize_key( (string) $source_attr->get_name() );
			if ( $source_attr->is_taxonomy() || ( 0 === strpos( $taxonomy, 'pa_' ) && taxonomy_exists( $taxonomy ) ) ) {
				continue;
			}

			$label = trim( (string) $source_attr->get_name() );
			if ( '' !== $label && ! Custom_Field_Parser::is_only_numbers( $label ) ) {
				$custom_strings[ 'label_' . $attr_key ] = $label;
			}

			$options = (array) $source_attr->get_options();
			foreach ( $options as $opt_idx => $opt_val ) {
				$opt_val = trim( (string) $opt_val );
				if ( '' !== $opt_val && ! Custom_Field_Parser::is_only_numbers( $opt_val ) ) {
					$custom_strings[ 'opt_' . $attr_key . '_' . $opt_idx ] = $opt_val;
				}
			}
		}

		$translated_custom = ! empty( $custom_strings )
			? self::translate_strings( $custom_strings, $source_lang, $target_lang, $context, 'wc_attr_' )
			: array();

		$target_attributes = array();

		foreach ( $source_attributes as $attr_key => $source_attr ) {
			if ( ! $source_attr instanceof WC_Product_Attribute ) {
				continue;
			}

			$target_attr = new WC_Product_Attribute();
			$target_attr->set_position( (int) $source_attr->get_position() );
			$target_attr->set_visible( (bool) $source_attr->get_visible() );
			$target_attr->set_variation( (bool) $source_attr->get_variation() );

			$taxonomy    = sanitize_key( (string) $source_attr->get_name() );
			$is_taxonomy = $source_attr->is_taxonomy() || ( 0 === strpos( $taxonomy, 'pa_' ) && taxonomy_exists( $taxonomy ) );

			if ( $is_taxonomy ) {
				// Global attribute: keep the WooCommerce attribute taxonomy and resolve its terms.
				$attribute_id = (int) $source_attr->get_id();
				if ( $attribute_id <= 0 && function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
					$attribute_id = (int) wc_attribute_taxonomy_id_by_name( $taxonomy );
				}

				$target_attr->set_id( $attribute_id );
				$target_attr->set_name( $taxonomy );

				$source_term_ids = array_values(
					array_unique(
						array_filter( array_map( 'absint', (array) $source_attr->get_options() ) )
					)
				);

				if ( ! Taxonomy_Parser::is_translatable_taxonomy( $taxonomy ) ) {
					// Non-translatable attribute taxonomies retain their original terms.
					$target_attr->set_options( $source_term_ids );
				} else {
					$resolved_terms = array();
					$missing_ids    = array();

					// Resolve existing translations and validate taxonomy membership.
					foreach ( $source_term_ids as $source_term_id ) {
						$translated_term_id = absint( Taxonomy_Parser::get_translation_id( $source_term_id, $target_lang ) );

						if ( $translated_term_id <= 0 && function_exists( 'pll_get_term' ) ) {
							$translated_term_id = absint( pll_get_term( $source_term_id, $target_lang ) );
						}

						$translated_term = $translated_term_id > 0
							? get_term( $translated_term_id, $taxonomy )
							: null;

						if ( $translated_term instanceof WP_Term ) {
							$resolved_terms[ $source_term_id ] = $translated_term_id;
						} else {
							$missing_ids[] = $source_term_id;
						}
					}

					// Create all missing translated terms in one operation.
					if ( ! empty( $missing_ids ) ) {
						$translated_map = Taxonomy_Translator::translate_terms(
							$missing_ids,
							$taxonomy,
							0,
							$target_lang,
							(string) ( $context['provider'] ?? '' ),
							is_array( $context['in_memory_translations'] ?? null ) ? $context['in_memory_translations'] : array(),
							array(
								'source_lang'         => $source_lang,
								'user_id'             => array_key_exists( 'user_id', $context ) ? $context['user_id'] : null,
								'enforce_permissions' => array_key_exists( 'enforce_permissions', $context ) ? (bool) $context['enforce_permissions'] : true,
								'skip_unauthorized'   => true,
							)
						);

						if ( is_array( $translated_map ) ) {
							foreach ( $translated_map as $source_id => $target_id ) {
								$source_id = absint( $source_id );
								$target_id = absint( $target_id );

								if ( $source_id <= 0 || $target_id <= 0 || ! in_array( $source_id, $missing_ids, true ) ) {
									continue;
								}

								$translated_term = get_term( $target_id, $taxonomy );
								if ( $translated_term instanceof WP_Term ) {
									$resolved_terms[ $source_id ] = $target_id;
								}
							}
						}
					}

					// Never attach source-language terms when a target translation is missing.
					$target_term_ids = array();
					foreach ( $source_term_ids as $source_term_id ) {
						if ( isset( $resolved_terms[ $source_term_id ] ) ) {
							$target_term_ids[] = $resolved_terms[ $source_term_id ];
						}
					}

					$target_attr->set_options( array_values( array_unique( $target_term_ids ) ) );
				}

				$target_attributes[ $attr_key ] = $target_attr;
				continue;
			}

			// Custom product attribute.
			$target_attr->set_id( 0 );
			$source_name = (string) $source_attr->get_name();
			$target_attr->set_name( $translated_custom[ 'label_' . $attr_key ] ?? $source_name );

			$target_options = array();
			foreach ( (array) $source_attr->get_options() as $opt_idx => $opt_val ) {
				$target_options[] = $translated_custom[ 'opt_' . $attr_key . '_' . $opt_idx ] ?? $opt_val;
			}

			$target_attr->set_options( $target_options );
			$target_attributes[ $attr_key ] = $target_attr;
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

			$taxonomy    = sanitize_key( (string) $source_attr->get_name() );
			$is_taxonomy = $source_attr->is_taxonomy() || ( 0 === strpos( $taxonomy, 'pa_' ) && taxonomy_exists( $taxonomy ) );

			if ( $is_taxonomy ) {
				$term_map        = array();
				$is_translatable = Taxonomy_Parser::is_translatable_taxonomy( $taxonomy );

				foreach ( (array) $source_attr->get_options() as $source_term_id ) {
					$source_term_id = absint( $source_term_id );
					if ( $source_term_id <= 0 ) {
						continue;
					}

					$source_term = get_term( $source_term_id, $taxonomy );
					if ( ! $source_term instanceof WP_Term ) {
						continue;
					}

					$target_term = $source_term;
					if ( $is_translatable ) {
						$translated_id = absint( Taxonomy_Parser::get_translation_id( $source_term_id, $target_lang ) );
						if ( $translated_id <= 0 && function_exists( 'pll_get_term' ) ) {
							$translated_id = absint( pll_get_term( $source_term_id, $target_lang ) );
						}

						$target_term = $translated_id > 0 ? get_term( $translated_id, $taxonomy ) : null;
						if ( ! $target_term instanceof WP_Term ) {
							continue;
						}
					}

					$term_map[ $source_term->slug ] = $target_term->slug;
					$term_map[ $source_term->name ] = $target_term->name;
				}

				$map[ $taxonomy ] = array(
					'is_taxonomy' => true,
					'target_key'  => $taxonomy,
					'terms'       => $term_map,
				);
				continue;
			}

			// Custom attribute mapping is positional because translated labels can change the key.
			$source_options = array_values( (array) $source_attr->get_options() );
			$target_attr    = $target_attributes[ $attr_key ] ?? null;

			if ( ! $target_attr instanceof WC_Product_Attribute ) {
				$source_key  = sanitize_title( (string) $source_attr->get_name() );
				$target_attr = $target_attributes[ $source_key ] ?? null;
			}

			if ( ! $target_attr instanceof WC_Product_Attribute ) {
				$source_position = (int) $source_attr->get_position();
				foreach ( $target_attributes as $candidate ) {
					if ( $candidate instanceof WC_Product_Attribute && ! $candidate->is_taxonomy() && (int) $candidate->get_position() === $source_position ) {
						$target_attr = $candidate;
						break;
					}
				}
			}

			$target_key = $attr_key;
			$val_map    = array();
			if ( $target_attr instanceof WC_Product_Attribute ) {
				$target_key     = sanitize_title( (string) $target_attr->get_name() );
				$target_options = array_values( (array) $target_attr->get_options() );

				foreach ( $source_options as $index => $source_value ) {
					$source_value                               = (string) $source_value;
					$target_value                               = isset( $target_options[ $index ] ) ? (string) $target_options[ $index ] : $source_value;
					$val_map[ $source_value ]                   = $target_value;
					$val_map[ sanitize_title( $source_value ) ] = sanitize_title( $target_value );
				}
			}

			$entry = array(
				'is_taxonomy' => false,
				'target_key'  => $target_key,
				'values'      => $val_map,
			);

			$map[ $attr_key ] = $entry;
			$map[ sanitize_title( (string) $source_attr->get_name() ) ] = $entry;
		}

		return $map;
	}

	/**
	 * Translate downloadable file names and the purchase note on the target product.
	 *
	 * The target product's downloadable configuration is expected to have been
	 * copied by the configured Polylang custom-field synchronization. This method
	 * deliberately does not copy source URLs, IDs, enabled flags, limits, or expiry.
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param int    $target_post_id Saved target post ID.
	 * @param string $target_lang    Target language slug.
	 * @param array  $context        Execution context including provider and in-memory translations.
	 */
	public static function synchronize_downloads_and_note( int $source_post_id, int $target_post_id, string $target_lang, array $context = array() ): void {
		if ( self::$syncing_downloads_and_note ) {
			return;
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$source_product = wc_get_product( $source_post_id );
		$target_product = wc_get_product( $target_post_id );
		$target_lang    = sanitize_key( $target_lang );

		if ( ! $source_product instanceof WC_Product || ! $target_product instanceof WC_Product || '' === $target_lang ) {
			return;
		}

		$source_lang = sanitize_key( (string) Polylang_Bridge::get_post_language( $source_post_id ) );
		if ( '' === $source_lang ) {
			$source_lang = sanitize_key( (string) Polylang_Bridge::get_default_language() );
		}

		if ( '' === $source_lang || $source_lang === $target_lang ) {
			return;
		}

		if ( empty( $context ) ) {
			$context = self::get_current_context();
		}
		$context = is_array( $context ) ? $context : array();

		self::$syncing_downloads_and_note = true;

		try {
			$downloads_changed = self::translate_downloadable_file_names(
				$source_product,
				$target_product,
				$source_lang,
				$target_lang,
				$context
			);
			$note_changed      = self::synchronize_purchase_note(
				$source_product,
				$target_product,
				$source_lang,
				$target_lang,
				$context
			);

			// Persist all changed WooCommerce properties in one CRUD save.
			if ( $downloads_changed || $note_changed ) {
				$target_product->save();
			}
		} finally {
			self::$syncing_downloads_and_note = false;
		}
	}

	/**
	 * Translate only downloadable file names on the target product.
	 *
	 * Matching is performed by WooCommerce download ID. The target download list
	 * is never rebuilt from the source, so target URLs, IDs, enabled state, ordering,
	 * and any other target-side download data remain untouched.
	 *
	 * @param WC_Product $source_product Source WooCommerce product.
	 * @param WC_Product $target_product Target WooCommerce product.
	 * @param string     $source_lang    Source language slug.
	 * @param string     $target_lang    Target language slug.
	 * @param array      $context        Translation execution context.
	 * @return bool True when at least one target download name changed.
	 */
	public static function translate_downloadable_file_names(
		WC_Product $source_product,
		WC_Product $target_product,
		string $source_lang,
		string $target_lang,
		array $context = array()
	): bool {
		if ( $source_lang === $target_lang ) {
			return false;
		}

		// Read source downloads. WooCommerce normally returns WC_Product_Download
		// objects, but retain a metadata fallback for older/custom integrations.
		$source_downloads = $source_product->get_downloads( 'edit' );
		if ( empty( $source_downloads ) ) {
			$raw_source_downloads = get_post_meta( $source_product->get_id(), '_downloadable_files', true );
			if ( is_array( $raw_source_downloads ) ) {
				$source_downloads = $raw_source_downloads;
			}
		}

		if ( empty( $source_downloads ) || ! is_array( $source_downloads ) ) {
			return false;
		}

		$target_downloads = $target_product->get_downloads( 'edit' );
		if ( ! is_array( $target_downloads ) ) {
			$target_downloads = array();
		}

		// Index existing target downloads once. If Polylang has not synchronized
		// _downloadable_files, source downloads will be used to initialize the
		// target configuration instead of silently skipping all file names.
		$target_by_id = array();
		foreach ( $target_downloads as $target_download ) {
			if ( $target_download instanceof \WC_Product_Download ) {
				$target_id = (string) $target_download->get_id();
				if ( '' !== $target_id ) {
					$target_by_id[ $target_id ] = $target_download;
				}
			}
		}

		$source_names = array();
		$source_items = array();

		foreach ( $source_downloads as $key => $source_download ) {
			$file_id = '';
			$name    = '';

			if ( $source_download instanceof \WC_Product_Download ) {
				$file_id = (string) $source_download->get_id();
				$name    = trim( (string) $source_download->get_name() );
			} elseif ( is_array( $source_download ) ) {
				$file_id = (string) ( $source_download['id'] ?? ( $source_download['download_id'] ?? $key ) );
				$name    = trim( (string) ( $source_download['name'] ?? '' ) );
			}

			if ( '' === $file_id ) {
				$file_id = (string) $key;
			}

			if ( '' === $file_id ) {
				continue;
			}

			$source_items[ $file_id ] = $source_download;

			if ( '' !== $name && ! Custom_Field_Parser::is_only_numbers( $name ) ) {
				$source_names[ $file_id ] = $name;
			}
		}

		if ( empty( $source_items ) ) {
			return false;
		}

		$translated_names = ! empty( $source_names )
			? self::translate_strings(
				$source_names,
				$source_lang,
				$target_lang,
				$context,
				'wc_download_name_'
			)
			: array();

		$downloads_to_save = array();
		$changed           = false;

		foreach ( $source_items as $file_id => $source_download ) {
			// Prefer the existing target object to retain any target-side values.
			// If it does not exist, clone/build it from the source so the target
			// still receives a usable download entry.
			if ( isset( $target_by_id[ $file_id ] ) ) {
				$download = clone $target_by_id[ $file_id ];
			} elseif ( $source_download instanceof \WC_Product_Download ) {
				$download = clone $source_download;
				$changed  = true;
			} elseif ( is_array( $source_download ) && class_exists( '\\WC_Product_Download' ) ) {
				$download = new \WC_Product_Download();
				$download->set_id( $file_id );
				$download->set_name( (string) ( $source_download['name'] ?? '' ) );
				$download->set_file( (string) ( $source_download['file'] ?? '' ) );
				$download->set_enabled( isset( $source_download['enabled'] ) ? (bool) $source_download['enabled'] : true );

				if ( isset( $source_download['previous_hash'] ) ) {
					$download->set_previous_hash( (string) $source_download['previous_hash'] );
				}
				$changed = true;
			} else {
				continue;
			}

			$translated_name = trim( (string) ( $translated_names[ $file_id ] ?? '' ) );

			// Do not overwrite an existing target translation with source text
			// when no translated value was found in the execution context/cache.
			if (
				'' !== $translated_name &&
				isset( $source_names[ $file_id ] ) &&
				$translated_name !== $source_names[ $file_id ] &&
				$download->get_name() !== $translated_name
			) {
				$download->set_name( $translated_name );
				$changed = true;
			}

			$downloads_to_save[ $file_id ] = $download;
		}

		if ( ! $changed ) {
			return false;
		}

		$target_product->set_downloads( $downloads_to_save );

		return true;
	}

	/**
	 * Backward-compatible method name retained for existing internal callers.
	 * This method now translates names only; it does not synchronize configuration.
	 *
	 * @param WC_Product $source_product Source WooCommerce product.
	 * @param WC_Product $target_product Target WooCommerce product.
	 * @param string     $source_lang    Source language slug.
	 * @param string     $target_lang    Target language slug.
	 * @param array      $context        Translation execution context.
	 * @return bool True when at least one target download name changed.
	 */
	public static function synchronize_downloadable_files(
		WC_Product $source_product,
		WC_Product $target_product,
		string $source_lang,
		string $target_lang,
		array $context = array()
	): bool {
		return self::translate_downloadable_file_names(
			$source_product,
			$target_product,
			$source_lang,
			$target_lang,
			$context
		);
	}

	/**
	 * Translate the WooCommerce purchase note on the target product.
	 *
	 * @param WC_Product $source_product Source WooCommerce product.
	 * @param WC_Product $target_product Target WooCommerce product.
	 * @param string     $source_lang    Source language slug.
	 * @param string     $target_lang    Target language slug.
	 * @param array      $context        Translation execution context.
	 * @return bool True when the target purchase note changed.
	 */
	public static function synchronize_purchase_note(
		WC_Product $source_product,
		WC_Product $target_product,
		string $source_lang,
		string $target_lang,
		array $context = array()
	): bool {
		if ( $source_lang === $target_lang ) {
			return false;
		}

		$source_note = trim( (string) $source_product->get_purchase_note( 'edit' ) );
		if ( '' === $source_note ) {
			// Keep the target consistent when the source purchase note was removed.
			if ( '' !== trim( (string) $target_product->get_purchase_note( 'edit' ) ) ) {
				$target_product->set_purchase_note( '' );
				return true;
			}
			return false;
		}

		$translated_notes = self::translate_strings(
			array( 'wc_purchase_note' => $source_note ),
			$source_lang,
			$target_lang,
			$context
		);
		$translated_note  = trim( (string) ( $translated_notes['wc_purchase_note'] ?? '' ) );

		// Do not replace an existing target translation with the source text when
		// the translation is missing from memory/context.
		if ( '' === $translated_note || $translated_note === $source_note ) {
			return false;
		}

		if ( $target_product->get_purchase_note( 'edit' ) === $translated_note ) {
			return false;
		}

		$target_product->set_purchase_note( $translated_note );
		return true;
	}
}
