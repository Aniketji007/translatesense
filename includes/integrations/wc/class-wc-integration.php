<?php

declare(strict_types=1);

namespace TranslateSense\Includes\Integrations\WC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers WooCommerce translation integrations. */
final class WC_Integration {

	/**
	 * Whether the integrations have been initialized.
	 *
	 * @access private
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * The singleton instance.
	 *
	 * @access private
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
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
		add_action( 'before_delete_post', array( Product_Relationship_Synchronizer::class, 'handle_before_delete_post' ) );
	}

		/**
		 * Initialize the WooCommerce translation integrations.
		 *
		 * @access public
		 * @return void
		 */
	public static function translate_wc(): void {
		if ( self::$initialized || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		new Product_Attribute_Translator();
		new Product_Variation_Synchronizer();
		new Product_Relationship_Synchronizer();

		self::$initialized = true;
	}
}
