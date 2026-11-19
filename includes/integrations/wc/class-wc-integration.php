<?php
/**
 * WooCommerce translation integration.
 *
 * Creates a draft WooCommerce product from the source product before
 * TranslateSense saves the translated post fields.
 *
 * @package TranslateSense
 */

declare(strict_types=1);

namespace TranslateSense\Includes\Integrations\WC;

use TranslateSense\Includes\Integrations\Polylang\Polylang_Bridge;
use WC_Product;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers WooCommerce translation integrations.
 */
final class WC_Integration {

	/**
	 * The singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Products created during this request, keyed by source ID and language.
	 *
	 * This prevents duplicate creation if the before-save filter runs more than
	 * once before TranslateSense has saved the Polylang relationship.
	 *
	 * @var array<string, int>
	 */
	private static $created_targets = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function init(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action(
			'before_delete_post',
			array( Product_Relationship_Synchronizer::class, 'handle_before_delete_post' )
		);

		self::register_hooks();
	}

	/**
	 * Initialize WooCommerce translation integrations.
	 *
	 * @return void
	 */
	public static function translate_wc(): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		self::register_hooks();

		static $initialized = false;

		if ( $initialized ) {
			return;
		}

		new Product_Attribute_Translator();
		new Product_Variation_Synchronizer();
		new Product_Relationship_Synchronizer();

		$initialized = true;
	}

	/**
	 * Register translation hooks once.
	 *
	 * IMPORTANT:
	 * This implementation assumes translatesense_before_save_translated_post is
	 * a FILTER whose first argument is target_post_id, followed by source ID,
	 * language, existing flag, and post data. Keep this aligned with the
	 * actual apply_filters() call in TranslateSense core.
	 *
	 * @return void
	 */
	private static function register_hooks(): void {
		static $registered = false;

		if ( $registered ) {
			return;
		}

		add_filter(
			'translatesense_before_save_translated_post',
			array( self::class, 'maybe_create_translated_product' ),
			10,
			5
		);

		add_filter(
			'translatesense_after_save_translated_post',
			array( self::class, 'clear_created_target' ),
			99,
			3
		);

		$registered = true;
	}

	/**
	 * Create a target WooCommerce product when a translation does not exist.
	 *
	 * @param mixed $target_post_id Existing target ID, or 0 for a new translation.
	 * @param mixed $source_post_id Source post ID.
	 * @param mixed $target_lang    Target language slug.
	 * @param mixed $is_existing    Whether the target already exists.
	 * @param mixed $post_data      Prepared translated post data (not modified here).
	 * @return int Target post ID.
	 */
	public static function maybe_create_translated_product(
		$target_post_id,
		$source_post_id,
		$target_lang,
		$is_existing = false,
		$post_data = array()
	): int {
		$target_post_id = absint( $target_post_id );
		$source_post_id = absint( $source_post_id );
		$target_lang    = sanitize_key( (string) $target_lang );

		if ( $target_post_id > 0 || ! empty( $is_existing ) ) {
			return $target_post_id;
		}

		if (
			$source_post_id <= 0 ||
			'' === $target_lang ||
			! function_exists( 'wc_get_product' )
		) {
			return $target_post_id;
		}

		$source_post = get_post( $source_post_id );

		if ( ! $source_post instanceof WP_Post || 'product' !== $source_post->post_type ) {
			return $target_post_id;
		}

		// Reuse an existing Polylang translation if one has already been created.
		$existing_id = Polylang_Bridge::get_existing_translation_post_id(
			$source_post_id,
			$target_lang
		);

		if ( $existing_id > 0 ) {
			return $existing_id;
		}

		$cache_key = $source_post_id . ':' . $target_lang;

		if ( isset( self::$created_targets[ $cache_key ] ) ) {
			$cached_post = get_post( self::$created_targets[ $cache_key ] );

			if (
				$cached_post instanceof WP_Post &&
				'product' === $cached_post->post_type &&
				'trash' !== $cached_post->post_status
			) {
				return (int) $cached_post->ID;
			}

			unset( self::$created_targets[ $cache_key ] );
		}

		$source_product = wc_get_product( $source_post_id );

		if ( ! $source_product instanceof WC_Product ) {
			return $target_post_id;
		}

		$target_product = clone $source_product;

		// Make the cloned CRUD object a new product.
		$target_product->set_id( 0 );

		// Reset statistics that should not be inherited by a translation.
		$target_product->set_total_sales( 0 );
		$target_product->set_rating_counts( array() );
		$target_product->set_average_rating( '0' );
		$target_product->set_review_count( 0 );

		try {
			$new_product_id = absint( $target_product->save() );
		} catch ( \Throwable $exception ) {
			$new_product_id = 0;

			// Use the plugin's logger if available; avoid exposing product data.
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Unable to create translated product from source product ' . $source_post_id . ': ' . $exception->getMessage(),
					array( 'source' => 'translatesense' )
				);
			}
		}

		if ( $new_product_id <= 0 ) {
			return $target_post_id;
		}

		self::$created_targets[ $cache_key ] = $new_product_id;

		return $new_product_id;
	}

	/**
	 * Clear the request-level cache after the translation save completes.
	 *
	 * @param mixed $target_post_id Saved target post ID.
	 * @param mixed $source_post_id Source post ID.
	 * @param mixed $target_lang    Target language slug.
	 * @return int
	 */
	public static function clear_created_target( $target_post_id, $source_post_id, $target_lang ): int {
		unset(
			self::$created_targets[ absint( $source_post_id ) . ':' . sanitize_key( (string) $target_lang ) ]
		);

		return absint( $target_post_id );
	}
}
