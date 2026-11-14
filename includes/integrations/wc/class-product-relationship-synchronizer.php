<?php

declare(strict_types=1);

namespace TranslateSense\Includes\Integrations\WC;

use TranslateSense\Includes\Integrations\Polylang\Polylang_Bridge;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes WooCommerce product relationships (upsells, cross-sells, grouped children).
 */
class Product_Relationship_Synchronizer {

	/**
	 * Flag to prevent recursive relationship synchronization.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'translatesense_after_save_translation', array( __CLASS__, 'synchronize_relationships' ), 20, 6 );
	}

	/**
	 * Synchronize linked product relationships when a product is translated.
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param int    $target_post_id Saved target post ID.
	 * @param string $target_lang    Target language slug.
	 * @param bool   $is_existing    Whether an existing translation was updated.
	 * @param array  $sync_result    Synchronization result and warnings.
	 * @param array  $context        Execution context.
	 */
	public static function synchronize_relationships( int $source_post_id, int $target_post_id, string $target_lang, bool $is_existing = false, array $sync_result = array(), array $context = array() ): void {
		if ( self::$syncing ) {
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

		self::$syncing = true;

		try {
			// A. Synchronize forward relationships from source product to translated target product.
			self::sync_target_product_relationships( $source_product, $target_product, $target_lang );

			// B. Reverse relationship synchronization: update existing translated products referencing this source product.
			self::sync_reverse_relationships( $source_post_id, $target_post_id, $target_lang );
		} finally {
			self::$syncing = false;
		}
	}

	/**
	 * Synchronize forward relationships for the newly translated target product.
	 *
	 * @param WC_Product $source_product Source WooCommerce product.
	 * @param WC_Product $target_product Target WooCommerce product.
	 * @param string     $target_lang    Target language slug.
	 */
	private static function sync_target_product_relationships( WC_Product $source_product, WC_Product $target_product, string $target_lang ): void {
		$target_post_id = (int) $target_product->get_id();
		$changed        = false;

		// 1. Upsells
		$source_upsells  = (array) $source_product->get_upsell_ids();
		$current_upsells = (array) $target_product->get_upsell_ids();
		$mapped_upsells  = self::map_linked_ids( $source_upsells, $target_lang, $target_post_id );

		if ( $mapped_upsells !== $current_upsells ) {
			$target_product->set_upsell_ids( $mapped_upsells );
			$changed = true;
		}

		// 2. Cross-sells
		$source_crosssells  = (array) $source_product->get_cross_sell_ids();
		$current_crosssells = (array) $target_product->get_cross_sell_ids();
		$mapped_crosssells  = self::map_linked_ids( $source_crosssells, $target_lang, $target_post_id );

		if ( $mapped_crosssells !== $current_crosssells ) {
			$target_product->set_cross_sell_ids( $mapped_crosssells );
			$changed = true;
		}

		// 3. Grouped product children
		if ( $source_product->is_type( 'grouped' ) || $target_product->is_type( 'grouped' ) ) {
			$source_children  = (array) $source_product->get_children();
			$current_children = (array) $target_product->get_children();
			$mapped_children  = self::map_linked_ids( $source_children, $target_lang, $target_post_id );

			if ( $mapped_children !== $current_children ) {
				$target_product->set_children( $mapped_children );
				$changed = true;
			}
		}

		if ( $changed ) {
			$target_product->save();
		}
	}

	/**
	 * Map a list of linked product IDs to their target-language translations.
	 * Retains original ID if translation does not exist.
	 * Preserves ordering and eliminates duplicates and self-references.
	 *
	 * @param int[]  $linked_ids     Source linked product IDs.
	 * @param string $target_lang    Target language slug.
	 * @param int    $target_post_id Target product ID to exclude self-reference.
	 * @return int[] Mapped linked product IDs.
	 */
	public static function map_linked_ids( array $linked_ids, string $target_lang, int $target_post_id = 0 ): array {
		if ( empty( $linked_ids ) || '' === $target_lang ) {
			return array();
		}

		$mapped = array();
		$seen   = array();

		foreach ( $linked_ids as $source_id ) {
			$source_id = absint( $source_id );
			if ( $source_id <= 0 ) {
				continue;
			}

			// Look up existing translation in target language using Polylang bridge
			$translated_id = Polylang_Bridge::get_existing_translation_post_id( $source_id, $target_lang );

			// Only replace with translated ID if it exists and is a valid product
			if ( $translated_id > 0 && $translated_id !== $source_id ) {
				$trans_post = get_post( $translated_id );
				$final_id   = ( $trans_post && 'product' === $trans_post->post_type ) ? $translated_id : $source_id;
			} else {
				$final_id = $source_id;
			}

			// Prevent self-reference, invalid IDs, and duplicates while preserving ordering
			if ( $final_id > 0 && $final_id !== $target_post_id && ! isset( $seen[ $final_id ] ) ) {
				$seen[ $final_id ] = true;
				$mapped[]          = $final_id;
			}
		}

		return $mapped;
	}

	/**
	 * Reverse relationship synchronization:
	 * When a product is translated, find other existing translated products in target language
	 * that reference the source product, and update them to reference the newly translated product.
	 *
	 * @param int    $source_post_id Source product ID.
	 * @param int    $target_post_id Target translated product ID.
	 * @param string $target_lang    Target language slug.
	 */
	private static function sync_reverse_relationships( int $source_post_id, int $target_post_id, string $target_lang ): void {
		// Find products referencing source_post_id in upsells, cross-sells, or grouped children
		$candidate_ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'lang'                   => '',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'     => '_upsell_ids',
						'value'   => ':' . $source_post_id . ';',
						'compare' => 'LIKE',
					),
					array(
						'key'     => '_crosssell_ids',
						'value'   => ':' . $source_post_id . ';',
						'compare' => 'LIKE',
					),
					array(
						'key'     => '_children',
						'value'   => ':' . $source_post_id . ';',
						'compare' => 'LIKE',
					),
					array(
						'key'     => '_upsell_ids',
						'value'   => '"' . $source_post_id . '"',
						'compare' => 'LIKE',
					),
					array(
						'key'     => '_crosssell_ids',
						'value'   => '"' . $source_post_id . '"',
						'compare' => 'LIKE',
					),
					array(
						'key'     => '_children',
						'value'   => '"' . $source_post_id . '"',
						'compare' => 'LIKE',
					),
				),
			)
		);

		if ( empty( $candidate_ids ) || ! is_array( $candidate_ids ) ) {
			return;
		}

		$processed_target_ids = array();

		foreach ( $candidate_ids as $ref_id ) {
			$ref_id = absint( $ref_id );
			if ( $ref_id <= 0 || $ref_id === $source_post_id || $ref_id === $target_post_id ) {
				continue;
			}

			$ref_lang = sanitize_key( (string) Polylang_Bridge::get_post_language( $ref_id ) );

			// Determine which target product to update:
			if ( $ref_lang === $target_lang ) {
				$target_to_update_id = $ref_id;
			} else {
				// Referencing product belongs to another language (e.g. source language).
				// Look up if its translation already exists in the target language:
				$trans_id = Polylang_Bridge::get_existing_translation_post_id( $ref_id, $target_lang );
				if ( $trans_id > 0 && $trans_id !== $ref_id ) {
					$target_to_update_id = $trans_id;
				} else {
					// Do not create missing linked product translations!
					continue;
				}
			}

			if ( isset( $processed_target_ids[ $target_to_update_id ] ) || $target_to_update_id === $target_post_id ) {
				continue;
			}
			$processed_target_ids[ $target_to_update_id ] = true;

			// Verify target is valid WooCommerce product
			$target_ref_product = wc_get_product( $target_to_update_id );
			if ( ! $target_ref_product instanceof WC_Product ) {
				continue;
			}

			// Confirm that the target product belongs to the target language
			if ( Polylang_Bridge::get_post_language( $target_to_update_id ) !== $target_lang ) {
				continue;
			}

			$changed = false;

			// Replace source_post_id with target_post_id in upsells
			$upsells = (array) $target_ref_product->get_upsell_ids();
			if ( in_array( $source_post_id, $upsells, true ) ) {
				$new_upsells = array();
				$seen        = array();
				foreach ( $upsells as $id ) {
					$final_id = ( (int) $id === $source_post_id ) ? $target_post_id : (int) $id;
					if ( $final_id !== $target_to_update_id && ! isset( $seen[ $final_id ] ) ) {
						$seen[ $final_id ] = true;
						$new_upsells[]     = $final_id;
					}
				}
				if ( $new_upsells !== $upsells ) {
					$target_ref_product->set_upsell_ids( $new_upsells );
					$changed = true;
				}
			}

			// Replace source_post_id with target_post_id in cross-sells
			$crosssells = (array) $target_ref_product->get_cross_sell_ids();
			if ( in_array( $source_post_id, $crosssells, true ) ) {
				$new_crosssells = array();
				$seen           = array();
				foreach ( $crosssells as $id ) {
					$final_id = ( (int) $id === $source_post_id ) ? $target_post_id : (int) $id;
					if ( $final_id !== $target_to_update_id && ! isset( $seen[ $final_id ] ) ) {
						$seen[ $final_id ] = true;
						$new_crosssells[]  = $final_id;
					}
				}
				if ( $new_crosssells !== $crosssells ) {
					$target_ref_product->set_cross_sell_ids( $new_crosssells );
					$changed = true;
				}
			}

			// Replace source_post_id with target_post_id in grouped children
			if ( $target_ref_product->is_type( 'grouped' ) ) {
				$children = (array) $target_ref_product->get_children();
				if ( in_array( $source_post_id, $children, true ) ) {
					$new_children = array();
					$seen         = array();
					foreach ( $children as $id ) {
						$final_id = ( (int) $id === $source_post_id ) ? $target_post_id : (int) $id;
						if ( $final_id !== $target_to_update_id && ! isset( $seen[ $final_id ] ) ) {
							$seen[ $final_id ] = true;
							$new_children[]    = $final_id;
						}
					}
					if ( $new_children !== $children ) {
						$target_ref_product->set_children( $new_children );
						$changed = true;
					}
				}
			}

			if ( $changed ) {
				$target_ref_product->save();
			}
		}
	}
}
