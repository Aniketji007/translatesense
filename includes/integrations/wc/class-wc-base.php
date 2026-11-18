<?php

declare(strict_types=1);

namespace TranslateSense\Includes\Integrations\WC;

use TranslateSense\Includes\Helper\Utils;
use TranslateSense\Includes\Translation\Translate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared functionality for WooCommerce translation integrations. */
abstract class WC_Base {

	public function __construct( int $priority = 10 ) {
		add_action( 'translatesense_after_save_translation', array( $this, 'translate' ), $priority, 6 );
	}

	abstract public function translate(
		int $source_post_id,
		int $target_post_id,
		string $target_lang,
		bool $is_existing = false,
		array $sync_result = array(),
		array $context = array()
	): void;

	protected static function translate_strings(
		array $strings,
		string $source_lang,
		string $target_lang,
		array $context = array(),
		string $prefix = ''
	): array {
		if ( empty( $strings ) || $source_lang === $target_lang ) {
			return $strings;
		}

		$results = array();

		// Resolve translated strings from in-memory translations without secondary AI calls.
		foreach ( $strings as $key => $str ) {
			$str     = (string) $str;
			$key_str = (string) $key;
			if ( '' === trim( $str ) || Utils::is_only_numbers( $str ) ) {
				$results[ $key ] = $str;
				continue;
			}

			$matched = null;
			if ( ! empty( $context ) ) {
				$candidate_keys = array( $key_str );
				if ( '' !== $prefix ) {
					$candidate_keys[] = $prefix . $key_str;
				}
				if ( 0 === strpos( $key_str, 'label_' ) || 0 === strpos( $key_str, 'opt_' ) ) {
					$candidate_keys[] = 'wc_attr_' . $key_str;
				}
				foreach ( $candidate_keys as $c_key ) {
					$res = Translate::translate_string( $str, $source_lang, $target_lang, $c_key, $context );
					if ( isset( $res['translated'] ) && ! empty( $res['translated'] ) && $str !== $res['translated'] ) {
						$matched = (string) $res['translated'];
						break;
					}
				}
			}

			if ( null !== $matched ) {
				$results[ $key ] = $matched;
			}
		}

		return $results;
	}
}
