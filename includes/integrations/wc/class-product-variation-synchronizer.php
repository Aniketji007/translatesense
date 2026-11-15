<?php

declare(strict_types=1);

namespace TranslateSense\Includes\Integrations\WC;

use TranslateSense\Includes\Content\Parser\Taxonomy\Taxonomy_Parser;
use TranslateSense\Includes\Integrations\Polylang\Polylang_Bridge;
use TranslateSense\Includes\Translation\Custom_Fields\Custom_Field_Translator;
use WC_Product;
use WC_Product_Variation;
use WC_Product_Variable;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes WooCommerce variable product variations to translated products.
 */
class Product_Variation_Synchronizer {

	/**
	 * Recursion guard.
	 *
	 * @var bool
	 */
	private static bool $synchronizing = false;

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'translatesense_after_save_translation', array( __CLASS__, 'synchronize_variations' ), 15, 6 );
	}

	/**
	 * Synchronize variations when a variable product is translated.
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param int    $target_post_id Saved target post ID.
	 * @param string $target_lang    Target language slug.
	 * @param bool   $is_existing    Whether an existing translation was updated.
	 * @param array  $sync_result    Synchronization result and warnings.
	 * @param array  $context        Execution context including provider and in_memory_translations.
	 */
	public static function synchronize_variations( int $source_post_id, int $target_post_id, string $target_lang, bool $is_existing = false, array $sync_result = array(), array $context = array() ): void {
		if ( self::$synchronizing ) {
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

		if ( ! $source_product->is_type( 'variable' ) ) {
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
		if ( empty( $context ) && class_exists( Product_Attribute_Translator::class ) ) {
			$context = Product_Attribute_Translator::get_current_context();
		}

		$context             = is_array( $context ?? null ) ? $context : array();
		self::$synchronizing = true;

		try {
			self::process_variations( $source_product, $target_product, $source_lang, $target_lang, $context );
		} finally {
			self::$synchronizing = false;
		}
	}

	/**
	 * Process and copy/update variations from source product to target product.
	 *
	 * @param WC_Product $source_product         Source WooCommerce variable product.
	 * @param WC_Product $target_product         Target WooCommerce product.
	 * @param string     $source_lang            Source language slug.
	 * @param string     $target_lang            Target language slug.
	 * @param array      $context                Context.
	 */
	public static function process_variations( WC_Product $source_product, WC_Product $target_product, string $source_lang, string $target_lang, array $context = array() ): void {
		$source_variation_ids = $source_product->get_children();

		if ( empty( $source_variation_ids ) || ! is_array( $source_variation_ids ) ) {
			return;
		}

		$target_post_id = (int) $target_product->get_id();

		// Ensure target product has variable product type
		if ( ! $target_product->is_type( 'variable' ) ) {
			wp_set_object_terms( $target_post_id, 'variable', 'product_type' );
			clean_post_cache( $target_post_id );
			wp_cache_delete( 'product-' . $target_post_id, 'products' );
			$target_product = new \WC_Product_Variable( $target_post_id );
		}

		// Build attribute mapping using translated attributes from target product
		$attribute_map = Product_Attribute_Translator::build_attribute_map( $source_product, $target_product, $target_lang );

		// Map existing variations on target product by _translatesense_source_id to prevent duplicates
		$existing_target_variation_ids = $target_product->get_children();
		$existing_by_source_id         = array();

		foreach ( (array) $existing_target_variation_ids as $existing_target_var_id ) {
			$existing_target_var_id = absint( $existing_target_var_id );
			if ( $existing_target_var_id <= 0 ) {
				continue;
			}
			$src_id = absint( get_post_meta( $existing_target_var_id, '_translatesense_source_id', true ) );

			if ( $src_id > 0 ) {
				$existing_by_source_id[ $src_id ] = $existing_target_var_id;
			}
		}

		// Load each source variation once and reuse it for description extraction and syncing.
		$source_variations         = array();
		$descriptions_to_translate = array();

		foreach ( $source_variation_ids as $source_var_id ) {
			$source_var_id = absint( $source_var_id );
			if ( $source_var_id <= 0 ) {
				continue;
			}

			$source_var = wc_get_product( $source_var_id );

			if ( ! $source_var instanceof WC_Product_Variation ) {
				continue;
			}

			$source_variations[ $source_var_id ] = $source_var;
			$description                         = trim( (string) $source_var->get_description() );

			if ( '' !== $description ) {
				$descriptions_to_translate[ (string) $source_var_id ] = $description;
			}
		}

		$translated_descriptions = ! empty( $descriptions_to_translate )
			? Product_Attribute_Translator::translate_strings(
				$descriptions_to_translate,
				$source_lang,
				$target_lang,
				$context,
				'wc_var_desc_'
			)
			: array();

		// Temporarily allow translated variations to share source SKU across translations
		add_filter( 'wc_product_pre_has_unique_sku', '__return_true' );
		add_filter( 'wc_product_has_unique_sku', '__return_false' );

		add_filter(
			'woocommerce_delete_variations_on_product_type_change',
			function ( $delete, $product, $from, $to ) use ( $target_post_id ) {

				if ( $product instanceof WC_Product && $product->get_id() === $target_post_id ) {
					return false;
				}

				return $delete;
			},
			10,
			4
		);

		try {
			// Process each source variation in order, preserving variation ordering
			foreach ( $source_variation_ids as $source_var_id ) {
				$source_var_id = absint( $source_var_id );
				if ( $source_var_id <= 0 ) {
					continue;
				}

				$source_var = $source_variations[ $source_var_id ] ?? null;
				if ( ! $source_var instanceof WC_Product_Variation ) {
					continue;
				}

				// Reuse existing translated variation or create missing one
				$target_var_id = $existing_by_source_id[ $source_var_id ] ?? 0;
				$target_var    = ( $target_var_id > 0 ) ? wc_get_product( $target_var_id ) : null;
				$is_existing   = ( $target_var instanceof WC_Product_Variation );

				if ( ! $is_existing ) {
					$target_var = new WC_Product_Variation();
					$target_var->set_parent_id( $target_post_id );
				}

				// Map variation attributes to translated attribute values or terms
				$source_var_attributes = (array) $source_var->get_attributes();
				$target_var_attributes = array();

				foreach ( $source_var_attributes as $attr_name => $attr_val ) {
					$attr_val = (string) $attr_val;
					if ( '' === $attr_val ) {
						$target_var_attributes[ $attr_name ] = '';
						continue;
					}

					if ( isset( $attribute_map[ $attr_name ] ) ) {
						$entry      = $attribute_map[ $attr_name ];
						$target_key = $entry['target_key'] ?? $attr_name;
						if ( ! empty( $entry['is_taxonomy'] ) ) {
							$term_map                             = (array) ( $entry['terms'] ?? array() );
							$target_var_attributes[ $target_key ] = $term_map[ $attr_val ] ?? $attr_val;
						} else {
							$val_map                              = (array) ( $entry['values'] ?? array() );
							$target_var_attributes[ $target_key ] = $val_map[ $attr_val ] ?? $attr_val;
						}
					} else {
						// Fallback for global pa_* attribute taxonomy terms
						if ( 0 === strpos( $attr_name, 'pa_' ) && taxonomy_exists( $attr_name ) ) {
							$source_term = get_term_by( 'slug', $attr_val, $attr_name );
							if ( $source_term instanceof WP_Term ) {
								$trans_id = Taxonomy_Parser::get_translation_id( (int) $source_term->term_id, $target_lang );
								if ( $trans_id <= 0 && function_exists( 'pll_get_term' ) ) {
									$trans_id = absint( pll_get_term( (int) $source_term->term_id, $target_lang ) );
								}
								if ( $trans_id > 0 ) {
									$trans_term = get_term( $trans_id, $attr_name );
									if ( $trans_term instanceof WP_Term ) {
										$target_var_attributes[ $attr_name ] = $trans_term->slug;
										continue;
									}
								}
							}
						}
						$target_var_attributes[ $attr_name ] = $attr_val;
					}
				}

				$target_var->set_attributes( $target_var_attributes );

				// Copy required WooCommerce variation settings and data using CRUD APIs
				// Pricing:
				$target_var->set_regular_price( $source_var->get_regular_price() );
				$target_var->set_sale_price( $source_var->get_sale_price() );
				$target_var->set_price( $source_var->get_price() );
				$target_var->set_date_on_sale_from( $source_var->get_date_on_sale_from() );
				$target_var->set_date_on_sale_to( $source_var->get_date_on_sale_to() );

				// SKU:
				try {
					$target_var->set_sku( $source_var->get_sku() );
				} catch ( \Throwable $sku_error ) {
					// Fallback if WooCommerce rejects duplicate SKU
				}

				// Stock settings:
				$target_var->set_manage_stock( $source_var->get_manage_stock() );
				$target_var->set_stock_quantity( $source_var->get_stock_quantity() );
				$target_var->set_stock_status( $source_var->get_stock_status() );
				$target_var->set_backorders( $source_var->get_backorders() );

				// Dimensions and weight:
				$target_var->set_weight( $source_var->get_weight() );
				$target_var->set_length( $source_var->get_length() );
				$target_var->set_width( $source_var->get_width() );
				$target_var->set_height( $source_var->get_height() );

				// Shipping class:
				$source_shipping_id = $source_var->get_shipping_class_id();
				if ( $source_shipping_id > 0 ) {
					$target_shipping_id = Taxonomy_Parser::get_translation_id( $source_shipping_id, $target_lang );
					$target_var->set_shipping_class_id( $target_shipping_id > 0 ? $target_shipping_id : $source_shipping_id );
				} else {
					$target_var->set_shipping_class_id( 0 );
				}

				// Downloadable and virtual settings:
				$target_var->set_virtual( $source_var->get_virtual() );
				$target_var->set_downloadable( $source_var->get_downloadable() );
				$target_var->set_downloads( $source_var->get_downloads() );
				$target_var->set_download_limit( $source_var->get_download_limit() );
				$target_var->set_download_expiry( $source_var->get_download_expiry() );

				// Tax settings:
				$target_var->set_tax_status( $source_var->get_tax_status() );
				$target_var->set_tax_class( $source_var->get_tax_class() );

				// Preserve variation ordering and status:
				$target_var->set_menu_order( (int) $source_var->get_menu_order() );
				$target_var->set_status( $source_var->get_status() );

				// Thumbnail / image:
				$source_img_id = (int) $source_var->get_image_id();
				if ( $source_img_id > 0 ) {
					$target_img_id = Polylang_Bridge::get_existing_translation_post_id( $source_img_id, $target_lang );
					$target_var->set_image_id( $target_img_id > 0 ? $target_img_id : $source_img_id );
				} else {
					$target_var->set_image_id( 0 );
				}

				// Translated description:
				$source_desc = trim( (string) $source_var->get_description() );
				$target_var->set_description( $translated_descriptions[ (string) $source_var_id ] ?? $source_desc );

				// Save variation via WooCommerce CRUD
				$saved_target_var_id = $target_var->save();

				if ( $saved_target_var_id > 0 ) {
					update_post_meta( $saved_target_var_id, '_translatesense_source_id', $source_var_id );

					Custom_Field_Translator::apply_target_metadata(
						$source_var_id,
						$saved_target_var_id,
						$source_lang,
						$target_lang,
						$is_existing,
						$context
					);
				}
			}
		} finally {
			remove_filter( 'wc_product_pre_has_unique_sku', '__return_true' );
			remove_filter( 'wc_product_has_unique_sku', '__return_false' );
		}

		// Preserve default attributes on target product
		$source_default_attributes = (array) $source_product->get_default_attributes();
		$target_default_attributes = array();

		foreach ( $source_default_attributes as $attr_name => $attr_val ) {
			$attr_val = (string) $attr_val;
			if ( '' === $attr_val ) {
				$target_default_attributes[ $attr_name ] = '';
				continue;
			}

			if ( isset( $attribute_map[ $attr_name ] ) ) {
				$entry      = $attribute_map[ $attr_name ];
				$target_key = $entry['target_key'] ?? $attr_name;
				if ( ! empty( $entry['is_taxonomy'] ) ) {
					$term_map                                 = (array) ( $entry['terms'] ?? array() );
					$target_default_attributes[ $target_key ] = $term_map[ $attr_val ] ?? $attr_val;
				} else {
					$val_map                                  = (array) ( $entry['values'] ?? array() );
					$target_default_attributes[ $target_key ] = $val_map[ $attr_val ] ?? $attr_val;
				}
			} else {
				$target_default_attributes[ $attr_name ] = $attr_val;
			}
		}

		$target_product->set_default_attributes( $target_default_attributes );

		// Update translated parent product
		$target_product->save();

		if ( class_exists( WC_Product_Variable::class ) ) {
			WC_Product_Variable::sync( $target_post_id );
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $target_post_id );
		}
	}
}
