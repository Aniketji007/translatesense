<?php

declare(strict_types=1);

namespace TranslateSense\Includes\Content\Parser\Media;

use TranslateSense\Includes\Bulk\Bulk_Process;
use TranslateSense\Includes\Content\Parser\Custom_Fields\Custom_Field_Parser;
use TranslateSense\Includes\Content\Parser\Dom_Filter;
use TranslateSense\Includes\Content\Parser\Taxonomy\Taxonomy_Parser;
use TranslateSense\Includes\Database\Hashing_Db;
use TranslateSense\Includes\Integrations\Polylang\Polylang_Bridge;
use TranslateSense\Includes\Translation\Translate;
use TranslateSense\Includes\XML\Custom_Field_Rule_Resolver;
use TranslateSense\Includes\XML\Xml_Engine;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves attachment translation mappings and extracts runtime media strings
 * (alt text, caption, description, and configured metadata) without persisting package rows.
 */
class Media_Parser {

	const PROTECTED_META_KEYS = array(
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_image_alt',
		'_wp_attachment_backup_sizes',
		'_wp_old_slug',
		'_wp_old_date',
		'_edit_lock',
		'_edit_last',
	);

	/**
	 * Fetch a valid, non-trashed attachment post, or null.
	 */
	public static function get_attachment( int $attachment_id ): ?WP_Post {
		if ( $attachment_id <= 0 ) {
			return null;
		}
		$post = get_post( $attachment_id );
		return ( $post instanceof WP_Post && 'attachment' === $post->post_type && 'trash' !== $post->post_status ) ? $post : null;
	}

	/**
	 * Verify that an ID refers to a valid, non-trashed WordPress attachment.
	 */
	public static function is_valid_attachment( int $attachment_id ): bool {
		return null !== self::get_attachment( $attachment_id );
	}

	/**
	 * Get an attachment's Polylang language slug, or empty string when unassigned.
	 */
	public static function get_attachment_language( int $attachment_id ): string {
		if ( $attachment_id <= 0 || ! Polylang_Bridge::is_polylang_active() ) {
			return '';
		}
		$lang = Polylang_Bridge::get_post_language( $attachment_id );
		return is_string( $lang ) ? sanitize_key( $lang ) : '';
	}

	/**
	 * Resolve and validate the target-language attachment ID via Polylang's ID map.
	 */
	public static function get_translation_id( int $attachment_id, string $target_lang ): int {
		$target_lang = sanitize_key( $target_lang );
		if ( $attachment_id <= 0 || '' === $target_lang || ! Polylang_Bridge::is_media_translation_enabled() ) {
			return 0;
		}

		$translations  = (array) Polylang_Bridge::get_post_translations( $attachment_id );
		$translated_id = isset( $translations[ $target_lang ] ) ? absint( $translations[ $target_lang ] ) : 0;

		return ( $translated_id > 0 && self::is_valid_attachment( $translated_id ) && self::get_attachment_language( $translated_id ) === $target_lang )
			? $translated_id
			: 0;
	}

	/**
	 * Check whether an attachment meta key is operational/internal and excluded from translation.
	 */
	public static function is_protected_meta_key( string $meta_key ): bool {
		return '' === $meta_key
			|| false !== strpos( $meta_key, "\0" )
			|| in_array( $meta_key, self::PROTECTED_META_KEYS, true )
			|| 0 === strpos( $meta_key, '_translatesense_' )
			|| 0 === strpos( $meta_key, '_pll_' );
	}

	/**
	 * Get configured translatable attachment metadata keys via XML rules or filter.
	 *
	 * @return string[]
	 */
	public static function get_translatable_meta_keys( WP_Post $attachment ): array {
		$keys  = array();
		$rules = Xml_Engine::get_user_custom_field_rules( 'all' );

		if ( ! empty( $rules ) ) {
			foreach ( array_keys( (array) get_post_meta( (int) $attachment->ID ) ) as $meta_key ) {
				if ( ! is_string( $meta_key ) || self::is_protected_meta_key( $meta_key ) || ( '_' === $meta_key[0] && ! isset( $rules[ $meta_key ] ) ) ) {
					continue;
				}
				if ( Custom_Field_Rule_Resolver::ACTION_TRANSLATE === ( Custom_Field_Rule_Resolver::resolve_field_rule( $meta_key, $rules )['action'] ?? '' ) ) {
					$keys[] = $meta_key;
				}
			}
		}

		$keys = apply_filters( 'translatesense_media_translatable_meta_keys', $keys, $attachment );

		return array_values(
			array_unique(
				array_filter(
					(array) $keys,
					static function ( $key ) {
						return is_string( $key ) && '' !== trim( $key ) && ! self::is_protected_meta_key( $key );
					}
				)
			)
		);
	}

	/**
	 * Filter to valid attachments and deduplicate Polylang-linked translation groups.
	 *
	 * @return int[]
	 */
	public static function filter_linked_attachments( array $attachment_ids ): array {
		if ( ! Polylang_Bridge::is_media_translation_enabled() ) {
			return array();
		}

		$valid_ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ), array( __CLASS__, 'is_valid_attachment' ) ) ) );
		return empty( $valid_ids ) ? array() : Bulk_Process::filter_linked_posts( $valid_ids );
	}

	/**
	 * Normalize and format a media field or meta value into a runtime string item.
	 */
	private static function format_string_item(
		int $attachment_id,
		string $widget,
		string $key,
		$raw_value,
		string $media_field,
		string $target_lang,
		string $meta_key = ''
	): ?array {
		if ( ! is_string( $raw_value ) || '' === trim( $raw_value ) ) {
			return null;
		}

		$filtered = Dom_Filter::filter_dom_content( $raw_value );
		$value    = false !== $filtered ? (string) $filtered : '';
		if ( '' === trim( $value ) || Translate::is_email_or_url( $value ) || Custom_Field_Parser::is_only_numbers( $value ) ) {
			return null;
		}

		$item = array(
			'hash'          => Hashing_Db::generate_hash( $widget, $value ),
			'name'          => $widget,
			'key'           => $key,
			'source'        => $raw_value,
			'value'         => $value,
			'type'          => 'media',
			'attachment_id' => $attachment_id,
			'media_field'   => $media_field,
			'target_lang'   => $target_lang,
		);

		if ( '' !== $meta_key ) {
			$item['meta_key'] = $meta_key; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		return $item;
	}

	/**
	 * Extract runtime media strings for explicit attachment IDs (Workflow A).
	 */
	public static function extract_attachment_strings(
		array $attachment_ids,
		string $target_lang,
		?int $user_id = null,
		bool $enforce_permissions = true
	): array {
		$target_lang = sanitize_key( $target_lang );
		if ( '' === $target_lang || ! Polylang_Bridge::is_media_translation_enabled() ) {
			return array();
		}

		$languages = Polylang_Bridge::get_languages();
		if ( ! empty( $languages ) && ! isset( $languages[ $target_lang ] ) ) {
			return array();
		}

		$strings = array();
		foreach ( array_unique( array_map( 'absint', $attachment_ids ) ) as $attachment_id ) {
			$attachment = self::get_attachment( $attachment_id );
			if ( ! $attachment || self::get_attachment_language( $attachment_id ) === $target_lang ) {
				continue;
			}
			if ( $enforce_permissions && ! Taxonomy_Parser::user_has_cap( $user_id, 'edit_post', $attachment_id ) ) {
				continue;
			}

			$target_id = self::get_translation_id( $attachment_id, $target_lang );

			if ( $target_id <= 0 ) {
				if ( $enforce_permissions && ! Taxonomy_Parser::user_has_cap( $user_id, 'upload_files' ) ) {
					continue;
				}

				$raw_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
				$fields  = array(
					'alt'         => is_string( $raw_alt ) ? $raw_alt : '',
					'caption'     => (string) $attachment->post_excerpt,
					'description' => (string) $attachment->post_content,
				);

				foreach ( $fields as $field => $raw_val ) {
					$item = self::format_string_item( $attachment_id, 'media_attachment', 'media_' . $attachment_id . '_' . $field, $raw_val, $field, $target_lang );
					if ( null !== $item ) {
						$strings[] = $item;
					}
				}
			} elseif ( $enforce_permissions && ! Taxonomy_Parser::user_has_cap( $user_id, 'edit_post', $target_id ) ) {
				continue;
			}

			foreach ( self::get_translatable_meta_keys( $attachment ) as $meta_key ) {
				if ( $target_id > 0 && metadata_exists( 'post', $target_id, $meta_key ) ) {
					continue;
				}

				foreach ( (array) get_post_meta( $attachment_id, $meta_key, false ) as $raw_val ) {
					$item = self::format_string_item(
						$attachment_id,
						'media_attachment_meta',
						'media_' . $attachment_id . '_meta_' . substr( md5( $meta_key ), 0, 12 ),
						$raw_val,
						'meta',
						$target_lang,
						$meta_key
					);
					if ( null !== $item ) {
						$strings[] = $item;
					}
				}
			}
		}

		return $strings;
	}

	/**
	 * Extract runtime strings for a post's untranslated featured image (Workflow B).
	 */
	public static function extract_featured_image_strings(
		int $post_id,
		string $target_lang,
		?int $user_id = null,
		bool $enforce_permissions = true
	): array {
		$target_lang = sanitize_key( $target_lang );
		if ( $post_id <= 0 || '' === $target_lang || ! Polylang_Bridge::is_media_translation_enabled() ) {
			return array();
		}

		if ( $enforce_permissions && ! Taxonomy_Parser::user_has_cap( $user_id, 'edit_post', $post_id ) ) {
			return array();
		}

		$thumbnail_id = absint( get_post_thumbnail_id( $post_id ) );
		if ( ! self::is_valid_attachment( $thumbnail_id ) || self::get_translation_id( $thumbnail_id, $target_lang ) > 0 ) {
			return array();
		}

		return self::extract_attachment_strings( array( $thumbnail_id ), $target_lang, $user_id, $enforce_permissions );
	}
}
