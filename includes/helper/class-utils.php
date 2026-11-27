<?php
/**
 * Shared helper utilities.
 *
 * @package TranslateSense
 */

declare(strict_types=1);

namespace TranslateSense\Includes\Helper;

use TranslateSense\Includes\Integrations\Polylang\Polylang_Bridge;
use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared utility, validation, sanitization, and permission helper methods.
 */
class Utils {

	/**
	 * Browser-side AI provider identifiers.
	 *
	 * @var string[]
	 */
	const BROWSER_PROVIDERS = array( 'chrome_ai', 'chrome_local_ai', 'edge_ai' );

	/**
	 * Supported slug translation modes.
	 *
	 * @var string[]
	 */
	const SLUG_TRANSLATION_MODES = array( 'keep_orignal', 'title_based', 'slug_translate' );

	/**
	 * Default slug translation mode.
	 *
	 * @var string
	 */
	const DEFAULT_SLUG_TRANSLATION = 'slug_translate';

	/**
	 * Check whether a provider executes on the client/browser side.
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public static function is_browser_provider( string $provider ): bool {
		return in_array( sanitize_key( $provider ), self::BROWSER_PROVIDERS, true );
	}

	/**
	 * Resolve a sanitized provider slug, falling back to saved plugin settings and `openai`.
	 *
	 * @param string $provider Optional explicit provider slug.
	 * @return string
	 */
	public static function get_default_provider( string $provider = '' ): string {
		$provider = sanitize_key( $provider );
		if ( '' !== $provider ) {
			return $provider;
		}

		$settings = get_option( 'translatesense_settings', array() );
		$saved    = is_array( $settings ) && ! empty( $settings['provider'] )
			? sanitize_key( (string) $settings['provider'] )
			: '';

		return '' !== $saved ? $saved : 'openai';
	}

	/**
	 * Retrieve and validate the slug translation setting.
	 *
	 * @param array $settings Optional settings array.
	 * @return string Sanitized slug translation mode ('keep_orignal', 'title_based', or 'slug_translate').
	 */
	public static function get_slug_translation( array $settings = array() ): string {
		if ( empty( $settings ) ) {
			$saved    = get_option( 'translatesense_settings', array() );
			$settings = is_array( $saved ) ? $saved : array();
		}

		$raw = isset( $settings['slug_translation'] ) && ! empty( $settings['slug_translation'] )
			? sanitize_key( (string) $settings['slug_translation'] )
			: self::DEFAULT_SLUG_TRANSLATION;

		return in_array( $raw, self::SLUG_TRANSLATION_MODES, true ) ? $raw : self::DEFAULT_SLUG_TRANSLATION;
	}

	/**
	 * Check whether a string is an email address, URL, URI scheme, or asset path.
	 *
	 * @param string $str Candidate string.
	 * @return bool
	 */
	public static function is_email_or_url( string $str ): bool {
		$trimmed = trim( wp_strip_all_tags( html_entity_decode( $str, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		if ( '' === $trimmed ) {
			return false;
		}

		if ( filter_var( $trimmed, FILTER_VALIDATE_EMAIL ) || 0 === stripos( $trimmed, 'mailto:' ) ) {
			return true;
		}

		if ( preg_match( '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $trimmed ) ) {
			return true;
		}

		if ( filter_var( $trimmed, FILTER_VALIDATE_URL ) ) {
			return true;
		}

		if ( preg_match( '/^(https?:\/\/|ftp:\/\/|\/\/|www\.|tel:|sms:|javascript:|callto:|whatsapp:)/i', $trimmed ) ) {
			return true;
		}

		if ( preg_match( '/^(\/|\.\/|\.\.\/|#)?[^\s]*\.(png|jpe?g|gif|svg|webp|avif|pdf|mp4|webm|zip|tar|gz|css|js|woff2?|ttf|eot)(\?.*)?$/i', $trimmed ) ) {
			return true;
		}

		if ( preg_match( '/^[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+\/[^\s]*$/i', $trimmed ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether a value contains only numbers (ignoring surrounding HTML tags).
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_only_numbers( $value ): bool {
		if ( is_numeric( $value ) ) {
			return true;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		$plain = trim( wp_strip_all_tags( $value ) );

		return '' !== $plain && is_numeric( $plain );
	}

	/**
	 * Count UTF-8 characters safely when `mbstring` may not be loaded.
	 *
	 * @param string $text Input text.
	 * @return int
	 */
	public static function strlen_utf8( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Sanitize translated text while preserving safe HTML when markup is present.
	 *
	 * @param string $text      Translated text.
	 * @param bool   $multiline Whether plain text should preserve line breaks.
	 * @return string
	 */
	public static function sanitize_translated_text( string $text, bool $multiline = false ): string {
		if ( false !== strpos( $text, '<' ) ) {
			return wp_kses_post( $text );
		}

		return $multiline ? sanitize_textarea_field( $text ) : sanitize_text_field( $text );
	}

	/**
	 * Safely decode serialized metadata without instantiating PHP objects.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return mixed
	 */
	public static function safe_unserialize( $value ) {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}

		$trimmed = trim( $value );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Safely decode serialized metadata without instantiating PHP objects.
		$unserialized = @unserialize(
			$trimmed,
			array(
				'allowed_classes' => false,
			)
		);

		return ( false !== $unserialized || 'b:0;' === $trimmed ) ? $unserialized : $value;
	}

	/**
	 * Evaluate a WordPress capability against a WP_User, explicit user ID, or current user.
	 * Rejects invalid user types, non-positive IDs, and non-existent user objects.
	 *
	 * @param WP_User|int|null $user       WP_User, explicit user ID, or null for current user.
	 * @param string           $capability Capability name.
	 * @param mixed            ...$args    Optional object ID or context arguments.
	 * @return bool
	 */
	public static function user_has_cap( $user, string $capability, ...$args ): bool {
		if ( '' === $capability ) {
			return false;
		}

		if ( null === $user ) {
			return current_user_can( $capability, ...$args );
		}

		if ( is_int( $user ) ) {
			return $user > 0 && user_can( $user, $capability, ...$args );
		}

		if ( $user instanceof WP_User ) {
			return $user->exists() && user_can( $user, $capability, ...$args );
		}

		return false;
	}

	/**
	 * Validate that a target language is configured in Polylang and, when permissions are
	 * enforced for an explicit user ID, that the user account exists.
	 *
	 * @param string   $target_lang         Target language slug.
	 * @param int|null $user_id             Optional user ID (`null` uses current user).
	 * @param bool     $enforce_permissions Whether user verification applies.
	 * @param string   $context             Domain context for error codes (`taxonomy`, `media`, etc.).
	 * @return true|WP_Error
	 */
	public static function validate_language_and_user(
		string $target_lang,
		?int $user_id = null,
		bool $enforce_permissions = true,
		string $context = 'translation'
	) {
		$context = sanitize_key( $context );
		if ( '' === $context ) {
			$context = 'translation';
		}
		$target_lang = sanitize_key( $target_lang );
		$languages   = Polylang_Bridge::get_languages();

		if ( '' === $target_lang || ! isset( $languages[ $target_lang ] ) ) {
			return new WP_Error(
				'translatesense_invalid_' . $context . '_language',
				__( 'The selected target language is not configured in Polylang.', 'translatesense' ),
				array( 'status' => 400 )
			);
		}

		if ( $enforce_permissions && null !== $user_id ) {
			$user_obj = $user_id > 0 ? get_userdata( $user_id ) : null;
			if ( ! $user_obj instanceof WP_User || ! $user_obj->exists() ) {
				return new WP_Error(
					'translatesense_' . $context . '_invalid_user',
					__( 'The user account for translation could not be verified.', 'translatesense' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Acquire an atomic WordPress option-backed concurrency lock with stale TTL eviction.
	 *
	 * @param string $lock_key Unique option name for the lock.
	 * @param int    $ttl      Stale lock timeout in seconds.
	 * @return bool True when lock was acquired, false if already held.
	 */
	public static function acquire_lock( string $lock_key, int $ttl = 300 ): bool {
		$lock_key = sanitize_key( $lock_key );
		if ( '' === $lock_key ) {
			return false;
		}

		$lock = get_option( $lock_key, 0 );
		if ( $lock && $ttl > 0 && ( time() - absint( $lock ) ) > $ttl ) {
			delete_option( $lock_key );
		}

		return (bool) add_option( $lock_key, time(), '', false );
	}

	/**
	 * Release a previously acquired option-backed concurrency lock.
	 *
	 * @param string $lock_key Unique option name for the lock.
	 * @return void
	 */
	public static function release_lock( string $lock_key ): void {
		$lock_key = sanitize_key( $lock_key );
		if ( '' !== $lock_key ) {
			delete_option( $lock_key );
		}
	}

	/**
	 * Return a safe public URL for a WordPress attachment without throwing errors.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function get_safe_attachment_url( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$link = get_attachment_link( $attachment_id );
		if ( is_string( $link ) && '' !== $link ) {
			return $link;
		}

		$url = wp_get_attachment_url( $attachment_id );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Collect non-empty string values and total UTF-8 character count from extracted items in one pass.
	 *
	 * @param array $items Extracted runtime string items.
	 * @return array{ values: string[], total_chars: int }
	 */
	public static function summarize_extracted_strings( array $items ): array {
		$values      = array();
		$total_chars = 0;

		foreach ( $items as $item ) {
			$val = isset( $item['value'] ) ? (string) $item['value'] : '';
			if ( '' !== $val ) {
				$values[]     = $val;
				$total_chars += self::strlen_utf8( $val );
			}
		}

		return array(
			'values'      => $values,
			'total_chars' => $total_chars,
		);
	}
}
