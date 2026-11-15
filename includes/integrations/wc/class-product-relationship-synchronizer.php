<?php

declare(strict_types=1);

namespace TranslateSense\Includes\Integrations\WC;

use TranslateSense\Includes\Integrations\Polylang\Polylang_Bridge;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes WooCommerce product relationships (upsells, cross-sells, grouped children)
 * and manages relationship recovery metadata when translated products are deleted.
 */
class Product_Relationship_Synchronizer {

	/**
	 * Recovery post meta keys.
	 */
	public const META_UPSELL_RECOVERY    = '_translatesense_upsell_recovery';
	public const META_CROSSSELL_RECOVERY = '_translatesense_cross_sell_recovery';
	public const META_GROUPED_RECOVERY   = '_translatesense_grouped_product_recovery';

	/**
	 * Relationship configurations mapping type to meta key, getter, and setter.
	 */
	private const RELATIONSHIP_CONFIGS = array(
		'upsell'    => array(
			'meta_key' => self::META_UPSELL_RECOVERY,
			'getter'   => 'get_upsell_ids',
			'setter'   => 'set_upsell_ids',
		),
		'crosssell' => array(
			'meta_key' => self::META_CROSSSELL_RECOVERY,
			'getter'   => 'get_cross_sell_ids',
			'setter'   => 'set_cross_sell_ids',
		),
		'grouped'   => array(
			'meta_key' => self::META_GROUPED_RECOVERY,
			'getter'   => 'get_children',
			'setter'   => 'set_children',
		),
	);

	/**
	 * Flag to prevent recursive relationship synchronization.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * Cache of processed product deletions in the current request to avoid duplicate recovery runs.
	 *
	 * @var array<int, bool>
	 */
	private static array $recovered_deletions = array();

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'translatesense_after_save_translation', array( __CLASS__, 'synchronize_relationships' ), 20, 6 );
		add_action( 'before_delete_post', array( __CLASS__, 'handle_product_deletion' ), 10, 1 );
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
		$target_post_id   = (int) $target_product->get_id();
		$changed          = false;
		$pending_recovery = array();

		// 1. Upsells
		$upsell_result = self::sync_target_relationship_field(
			$source_product,
			$target_product,
			'get_upsell_ids',
			'set_upsell_ids',
			$target_lang
		);
		if ( $upsell_result['changed'] ) {
			$changed = true;
		}
		if ( ! empty( $upsell_result['replacements'] ) ) {
			$pending_recovery[ self::META_UPSELL_RECOVERY ] = $upsell_result['replacements'];
		}

		// 2. Cross-sells
		$crosssell_result = self::sync_target_relationship_field(
			$source_product,
			$target_product,
			'get_cross_sell_ids',
			'set_cross_sell_ids',
			$target_lang
		);
		if ( $crosssell_result['changed'] ) {
			$changed = true;
		}
		if ( ! empty( $crosssell_result['replacements'] ) ) {
			$pending_recovery[ self::META_CROSSSELL_RECOVERY ] = $crosssell_result['replacements'];
		}

		// 3. Grouped product children
		if ( $source_product->is_type( 'grouped' ) || $target_product->is_type( 'grouped' ) ) {
			$grouped_result = self::sync_target_relationship_field(
				$source_product,
				$target_product,
				'get_children',
				'set_children',
				$target_lang
			);
			if ( $grouped_result['changed'] ) {
				$changed = true;
			}
			if ( ! empty( $grouped_result['replacements'] ) ) {
				$pending_recovery[ self::META_GROUPED_RECOVERY ] = $grouped_result['replacements'];
			}
		}

		if ( $changed ) {
			$target_product->save();
		}

		// Update recovery metadata only after the relationship replacement is successfully applied
		foreach ( $pending_recovery as $meta_key => $mappings ) {
			self::add_recovery_mappings( $target_post_id, $meta_key, $mappings );
		}
	}

	/**
	 * Synchronize a specific relationship field on the target product.
	 *
	 * @param WC_Product $source_product Source WooCommerce product.
	 * @param WC_Product $target_product Target WooCommerce product.
	 * @param string     $getter         Getter method on WC_Product.
	 * @param string     $setter         Setter method on WC_Product.
	 * @param string     $target_lang    Target language slug.
	 * @return array{changed: bool, replacements: array<int, int>} Result and recorded replacements.
	 */
	private static function sync_target_relationship_field( WC_Product $source_product, WC_Product $target_product, string $getter, string $setter, string $target_lang ): array {
		$target_post_id = (int) $target_product->get_id();
		$current_ids    = (array) $target_product->$getter();
		$source_ids     = (array) $source_product->$getter();

		$replacements = array();
		$new_ids      = array();
		$seen         = array();

		if ( empty( $current_ids ) ) {
			// Newly created target product: map relationships from source product
			foreach ( $source_ids as $source_id ) {
				$source_id = absint( $source_id );
				if ( $source_id <= 0 || $source_id === $target_post_id ) {
					continue;
				}

				$trans_id = Polylang_Bridge::get_existing_translation_post_id( $source_id, $target_lang );
				if ( $trans_id > 0 && $trans_id !== $source_id ) {
					$trans_post = get_post( $trans_id );
					$final_id   = ( $trans_post && 'product' === $trans_post->post_type ) ? $trans_id : $source_id;
				} else {
					$final_id = $source_id;
				}

				if ( $final_id > 0 && $final_id !== $target_post_id && ! isset( $seen[ $final_id ] ) ) {
					$seen[ $final_id ] = true;
					$new_ids[]         = $final_id;
					if ( $final_id !== $source_id ) {
						$replacements[ $final_id ] = $source_id;
					}
				}
			}
		} else {
			// Existing target product: preserve target's current list and independent customizations,
			// only replacing fallback source IDs when their translations become available
			foreach ( $current_ids as $id ) {
				$id = absint( $id );
				if ( $id <= 0 || $id === $target_post_id ) {
					continue;
				}

				$trans_id = Polylang_Bridge::get_existing_translation_post_id( $id, $target_lang );
				if ( $trans_id > 0 && $trans_id !== $id ) {
					$trans_post = get_post( $trans_id );
					$final_id   = ( $trans_post && 'product' === $trans_post->post_type ) ? $trans_id : $id;
				} else {
					$final_id = $id;
				}

				if ( $final_id > 0 && $final_id !== $target_post_id && ! isset( $seen[ $final_id ] ) ) {
					$seen[ $final_id ] = true;
					$new_ids[]         = $final_id;
					if ( $final_id !== $id ) {
						$replacements[ $final_id ] = $id;
					}
				}
			}
		}

		$changed = false;
		if ( $new_ids !== $current_ids ) {
			$target_product->$setter( $new_ids );
			$changed = true;
		}

		return array(
			'changed'      => $changed,
			'replacements' => $replacements,
		);
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
					// Do not create missing linked product translations
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

			$changed          = false;
			$pending_recovery = array();

			// 1. Replace source_post_id with target_post_id in upsells
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
					$pending_recovery[ self::META_UPSELL_RECOVERY ][ $target_post_id ] = $source_post_id;
				}
			}

			// 2. Replace source_post_id with target_post_id in cross-sells
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
					$pending_recovery[ self::META_CROSSSELL_RECOVERY ][ $target_post_id ] = $source_post_id;
				}
			}

			// 3. Replace source_post_id with target_post_id in grouped children
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
						$pending_recovery[ self::META_GROUPED_RECOVERY ][ $target_post_id ] = $source_post_id;
					}
				}
			}

			if ( $changed ) {
				$target_ref_product->save();
			}

			// Record recovery mappings after relationship update is successfully applied
			foreach ( $pending_recovery as $meta_key => $mappings ) {
				self::add_recovery_mappings( $target_to_update_id, $meta_key, $mappings );
			}
		}
	}

	/**
	 * Get recovery mappings from post meta.
	 *
	 * @param int    $product_id Product post ID.
	 * @param string $meta_key   Recovery post meta key.
	 * @return array<int, int> Associative array of [ translated_id => source_id ].
	 */
	public static function get_recovery_mappings( int $product_id, string $meta_key ): array {
		if ( $product_id <= 0 || '' === $meta_key ) {
			return array();
		}

		$raw_meta = get_post_meta( $product_id, $meta_key, true );
		if ( ! is_array( $raw_meta ) || empty( $raw_meta ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $raw_meta as $trans_id => $source_id ) {
			$trans_id  = absint( $trans_id );
			$source_id = absint( $source_id );
			if ( $trans_id > 0 && $source_id > 0 && $trans_id !== $source_id ) {
				$sanitized[ $trans_id ] = $source_id;
			}
		}

		return $sanitized;
	}

	/**
	 * Add or merge recovery mappings into post meta.
	 *
	 * @param int             $product_id   Product post ID.
	 * @param string          $meta_key     Recovery post meta key.
	 * @param array<int, int> $new_mappings New mappings [ translated_id => source_id ].
	 */
	public static function add_recovery_mappings( int $product_id, string $meta_key, array $new_mappings ): void {
		if ( $product_id <= 0 || '' === $meta_key || empty( $new_mappings ) ) {
			return;
		}

		$existing = self::get_recovery_mappings( $product_id, $meta_key );

		foreach ( $new_mappings as $trans_id => $source_id ) {
			$trans_id  = absint( $trans_id );
			$source_id = absint( $source_id );
			if ( $trans_id > 0 && $source_id > 0 && $trans_id !== $source_id ) {
				$existing[ $trans_id ] = $source_id;
			}
		}

		if ( ! empty( $existing ) ) {
			update_post_meta( $product_id, $meta_key, $existing );
		}
	}

	/**
	 * Remove a specific deleted translated product ID from recovery mappings.
	 * Deletes the post meta key completely if the resulting mapping array is empty.
	 *
	 * @param int    $product_id       Product post ID.
	 * @param string $meta_key         Recovery post meta key.
	 * @param int    $deleted_trans_id Deleted translated product ID.
	 */
	public static function remove_recovery_mapping( int $product_id, string $meta_key, int $deleted_trans_id ): void {
		if ( $product_id <= 0 || '' === $meta_key || $deleted_trans_id <= 0 ) {
			return;
		}

		$existing = self::get_recovery_mappings( $product_id, $meta_key );
		if ( ! isset( $existing[ $deleted_trans_id ] ) ) {
			return;
		}

		unset( $existing[ $deleted_trans_id ] );

		if ( empty( $existing ) ) {
			delete_post_meta( $product_id, $meta_key );
		} else {
			update_post_meta( $product_id, $meta_key, $existing );
		}
	}

	/**
	 * Handle product deletion or trashing to recover linked product relationships.
	 *
	 * @param int $post_id Post ID being deleted or trashed.
	 */
	public static function handle_product_deletion( int $post_id ): void {
		if ( self::$syncing ) {
			return;
		}

		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return;
		}

		// Avoid duplicate recovery execution for the same post in the same request
		if ( isset( self::$recovered_deletions[ $post_id ] ) ) {
			return;
		}

		// Check if this post is a WooCommerce product
		$post = get_post( $post_id );
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		self::$syncing = true;
		try {
			self::$recovered_deletions[ $post_id ] = true;
			self::recover_relationships_for_deleted_product( $post_id );
		} finally {
			self::$syncing = false;
		}
	}

	/**
	 * Recover relationships across dependent products for a deleted translated product.
	 *
	 * @param int $deleted_product_id Deleted translated product ID.
	 */
	public static function recover_relationships_for_deleted_product( int $deleted_product_id ): void {
		foreach ( self::RELATIONSHIP_CONFIGS as $type => $config ) {
			$meta_key = $config['meta_key'];
			$getter   = $config['getter'];
			$setter   = $config['setter'];

			$candidate_ids = self::find_dependent_products_for_recovery( $meta_key, $deleted_product_id );
			if ( empty( $candidate_ids ) ) {
				continue;
			}

			foreach ( $candidate_ids as $dep_id ) {
				$dep_id = absint( $dep_id );
				if ( $dep_id <= 0 || $dep_id === $deleted_product_id ) {
					continue;
				}

				$recovery_mappings = self::get_recovery_mappings( $dep_id, $meta_key );
				if ( ! isset( $recovery_mappings[ $deleted_product_id ] ) ) {
					continue;
				}

				$source_id = absint( $recovery_mappings[ $deleted_product_id ] );
				if ( $source_id <= 0 || $source_id === $dep_id ) {
					self::remove_recovery_mapping( $dep_id, $meta_key, $deleted_product_id );
					continue;
				}

				$dep_product = wc_get_product( $dep_id );
				if ( ! $dep_product instanceof WC_Product ) {
					continue;
				}

				$current_ids = (array) $dep_product->$getter();
				$new_ids     = array();
				$replaced    = false;
				$seen        = array();

				foreach ( $current_ids as $id ) {
					$id = absint( $id );
					if ( $id <= 0 || $id === $dep_id ) {
						continue;
					}

					if ( $id === $deleted_product_id ) {
						// Replace deleted translated product ID with original source product ID
						if ( ! isset( $seen[ $source_id ] ) ) {
							$seen[ $source_id ] = true;
							$new_ids[]          = $source_id;
						}
						$replaced = true;
					} elseif ( ! isset( $seen[ $id ] ) ) {
							$seen[ $id ] = true;
							$new_ids[]   = $id;
					}
				}

				// If deleted ID was already stripped by another process/hook, restore source fallback
				if ( ! $replaced && ! in_array( $source_id, $new_ids, true ) ) {
					if ( ! isset( $seen[ $source_id ] ) ) {
						$seen[ $source_id ] = true;
						$new_ids[]          = $source_id;
						$replaced           = true;
					}
				}

				if ( $new_ids !== $current_ids ) {
					$dep_product->$setter( $new_ids );
					$dep_product->save();
				}

				// Remove only the recovered deleted ID from recovery metadata
				self::remove_recovery_mapping( $dep_id, $meta_key, $deleted_product_id );
			}
		}
	}

	/**
	 * Find candidate product IDs that have recovery metadata for a relationship type.
	 * Filters by meta_key and includes the deleted product ID in meta_value when provided.
	 *
	 * @param string $meta_key           Recovery metadata key.
	 * @param int    $deleted_product_id Optional deleted product ID to filter candidate meta values.
	 * @return int[] Candidate product IDs.
	 */
	public static function find_dependent_products_for_recovery( string $meta_key, int $deleted_product_id = 0 ): array {
		if ( '' === $meta_key ) {
			return array();
		}

		$deleted_product_id = absint( $deleted_product_id );

		if ( $deleted_product_id > 0 ) {
			$meta_query = array(
				'relation' => 'OR',
				array(
					'key'     => $meta_key,
					'value'   => ':' . $deleted_product_id . ';',
					'compare' => 'LIKE',
				),
				array(
					'key'     => $meta_key,
					'value'   => '"' . $deleted_product_id . '"',
					'compare' => 'LIKE',
				),
			);
		} else {
			$meta_query = array(
				array(
					'key'     => $meta_key,
					'compare' => 'EXISTS',
				),
			);
		}

		$ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'lang'                   => '',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => $meta_query,
			)
		);

		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}
}
