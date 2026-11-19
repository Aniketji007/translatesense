<?php

declare(strict_types=1);

namespace TranslateSense\Includes\Translation\Media;

use TranslateSense\Includes\Bulk\Bulk_Process;
use TranslateSense\Includes\Content\Parser\Media\Media_Parser;
use TranslateSense\Includes\Database\Hashing_Db;
use TranslateSense\Includes\Helper\Utils;
use TranslateSense\Includes\Integrations\Polylang\Polylang_Bridge;
use TranslateSense\Includes\Translation\Statics;
use TranslateSense\Includes\Translation\Translate;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates, creates/links in Polylang, and synchronizes WordPress media attachments
 * for standalone Media Library bulk translation and featured-image post translation.
 */
class Media_Translator {

	/**
	 * Recursion guard to prevent nested attachment translation cycles.
	 *
	 * @var bool
	 */
	private static bool $translating = false;

	/**
	 * Check whether an existing translated attachment is missing configured translatable meta.
	 */
	private static function has_missing_translatable_meta( WP_Post $source_attachment, int $target_attachment_id ): bool {
		foreach ( Media_Parser::get_translatable_meta_keys( $source_attachment ) as $meta_key ) {
			if ( ! metadata_exists( 'post', $target_attachment_id, $meta_key ) && metadata_exists( 'post', (int) $source_attachment->ID, $meta_key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Inspect whether an attachment already has a completed translation or needs creation/update.
	 *
	 * @return array{ exists: bool, target_attachment_id: int, needs_update: bool, is_same_lang: bool }
	 */
	public static function attachment_has_completed_translation( int $attachment_id, string $target_lang ): array {
		$target_lang = sanitize_key( $target_lang );
		$result      = array(
			'exists'               => false,
			'target_attachment_id' => 0,
			'needs_update'         => false,
			'is_same_lang'         => false,
		);

		$attachment = ( '' !== $target_lang && Polylang_Bridge::is_media_translation_enabled() )
			? Media_Parser::get_attachment( $attachment_id )
			: null;
		if ( ! $attachment ) {
			return $result;
		}

		if ( Media_Parser::get_attachment_language( $attachment_id ) === $target_lang ) {
			$result['exists']               = true;
			$result['target_attachment_id'] = $attachment_id;
			$result['is_same_lang']         = true;
			return $result;
		}

		$target_id = Media_Parser::get_translation_id( $attachment_id, $target_lang );
		if ( $target_id > 0 ) {
			$result['exists']               = true;
			$result['target_attachment_id'] = $target_id;
			$result['needs_update']         = self::has_missing_translatable_meta( $attachment, $target_id );
		}

		return $result;
	}

	/**
	 * Validate environment, attachments, and capabilities in one pass.
	 *
	 * @return array<int, WP_Post>|WP_Error
	 */
	private static function authorize_attachments(
		array $attachment_ids,
		string $target_lang,
		?int $user_id,
		bool $enforce_permissions
	) {
		if ( ! Polylang_Bridge::is_media_translation_enabled() ) {
			return new WP_Error( 'translatesense_media_translation_disabled', __( 'Polylang Media translation is not enabled.', 'translatesense' ), array( 'status' => 400 ) );
		}

		$target_lang     = sanitize_key( $target_lang );
		$lang_user_valid = Utils::validate_language_and_user( $target_lang, $user_id, $enforce_permissions, 'media' );
		if ( is_wp_error( $lang_user_valid ) ) {
			return $lang_user_valid;
		}

		$authorized = array();
		foreach ( array_unique( array_map( 'absint', $attachment_ids ) ) as $attachment_id ) {
			$attachment = Media_Parser::get_attachment( $attachment_id );
			if ( ! $attachment ) {
				continue;
			}

			if ( $enforce_permissions ) {
				if ( ! Utils::user_has_cap( $user_id, 'edit_post', $attachment_id ) ) {
					return new WP_Error( 'translatesense_media_edit_forbidden', __( 'You are not allowed to translate one or more selected media attachments.', 'translatesense' ), array( 'status' => 403 ) );
				}

				if ( Media_Parser::get_attachment_language( $attachment_id ) !== $target_lang ) {
					$target_id = Media_Parser::get_translation_id( $attachment_id, $target_lang );
					if ( $target_id <= 0 && ! Utils::user_has_cap( $user_id, 'upload_files' ) ) {
						return new WP_Error( 'translatesense_media_create_forbidden', __( 'You are not allowed to create translated media attachments.', 'translatesense' ), array( 'status' => 403 ) );
					}
					if ( $target_id > 0 && self::has_missing_translatable_meta( $attachment, $target_id ) && ! Utils::user_has_cap( $user_id, 'edit_post', $target_id ) ) {
						return new WP_Error( 'translatesense_media_edit_forbidden', __( 'You are not allowed to update the translated media attachment.', 'translatesense' ), array( 'status' => 403 ) );
					}
				}
			}

			$authorized[ $attachment_id ] = $attachment;
		}

		return empty( $authorized )
			? new WP_Error( 'translatesense_invalid_media_attachments', __( 'No valid media attachments were provided for translation.', 'translatesense' ), array( 'status' => 400 ) )
			: $authorized;
	}

	/**
	 * Validate permissions required to translate/create/update explicit attachments.
	 *
	 * @return true|WP_Error
	 */
	public static function check_attachments_permissions( array $attachment_ids, string $target_lang, ?int $user_id = null ) {
		$authorized = self::authorize_attachments( $attachment_ids, $target_lang, $user_id, true );
		return is_wp_error( $authorized ) ? $authorized : true;
	}

	/**
	 * Ensure every extracted media string is present in `$in_memory_translations`
	 * or translated in a single server AI batch call. Never falls back to server AI
	 * when a browser provider is active and browser translations are missing.
	 *
	 * @return true|WP_Error
	 */
	private static function ensure_items_translated(
		array $items,
		array $authorized_attachments,
		int $job_id,
		int $attachment_id,
		string $source_lang,
		string $target_lang,
		string $provider,
		array &$in_memory_translations
	) {
		if ( empty( $items ) ) {
			return true;
		}

		$effective_source_lang = sanitize_key( $source_lang ) ?: sanitize_key( (string) Polylang_Bridge::get_default_language() );
		if ( '' !== $effective_source_lang && $effective_source_lang === $target_lang ) {
			return true;
		}

		$missing_texts = array();
		foreach ( $items as $item ) {
			$item_att_id = absint( $item['attachment_id'] ?? 0 );
			$hash        = (string) ( $item['hash'] ?? '' );
			$value       = (string) ( $item['value'] ?? '' );

			if ( ! isset( $authorized_attachments[ $item_att_id ] ) || '' === $hash || '' === trim( $value ) ) {
				continue;
			}
			if ( isset( $in_memory_translations[ $hash ] ) && is_string( $in_memory_translations[ $hash ] ) && '' !== trim( $in_memory_translations[ $hash ] ) ) {
				continue;
			}
			$missing_texts[ $hash ] = $value;
		}

		if ( empty( $missing_texts ) ) {
			return true;
		}

		if ( Utils::is_browser_provider( $provider ) ) {
			return new WP_Error(
				'translatesense_media_browser_translation_missing',
				__( 'The browser AI translation for one or more media strings is missing or incomplete. Please retry the translation.', 'translatesense' ),
				array( 'status' => 400 )
			);
		}

		$provider = Utils::get_default_provider( $provider );

		$batch_values = array_values( array_unique( $missing_texts ) );
		$start_time   = microtime( true );
		$batch_res    = Translate::translate_batch( $batch_values, $effective_source_lang, $target_lang, array( 'provider' => $provider ) );

		if ( is_wp_error( $batch_res ) ) {
			return $batch_res;
		}

		$translated_by_source = array();
		$receive_data         = array();
		$total_chars          = 0;

		foreach ( $batch_values as $index => $batch_value ) {
			$translated_val                       = isset( $batch_res[ $index ]['translated'] ) ? (string) $batch_res[ $index ]['translated'] : $batch_value;
			$translated_by_source[ $batch_value ] = $translated_val;
			$receive_data[]                       = $translated_val;
			$total_chars                         += Utils::strlen_utf8( $batch_value );

			if ( Translate::should_cache_provider( $provider ) && '' !== trim( $translated_val ) && $batch_value !== $translated_val ) {
				Hashing_Db::save_translation_result( md5( $batch_value ), $target_lang, $translated_val );
			}
		}

		foreach ( $missing_texts as $hash => $source_text ) {
			if ( isset( $translated_by_source[ $source_text ] ) ) {
				$in_memory_translations[ $hash ] = $translated_by_source[ $source_text ];
			}
		}

		if ( $job_id > 0 && $attachment_id > 0 ) {
			global $wpdb;
			$table = Bulk_Process::get_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$valid_job_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND post_id = %d AND item_type = 'media'", $job_id, $attachment_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $valid_job_id > 0 ) {
				$total_str = count( $batch_values );
				Bulk_Process::update_job(
					$valid_job_id,
					array(
						'total_strings'     => $total_str,
						'completed_strings' => $total_str,
						'progress_status'   => sprintf( 'Translating strings (%d found)...', $total_str ),
						'sent_data'         => wp_json_encode( $batch_values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						'receive_data'      => wp_json_encode( $receive_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
					)
				);

				Statics::update_translation_statistics(
					array(
						'status'           => 'success',
						'total_characters' => $total_chars,
						'total_strings'    => $total_str,
						'provider'         => $provider,
						'time_take'        => (int) round( ( microtime( true ) - $start_time ) * 1000 ),
						'request_id'       => 'bulk_media_job_' . $job_id,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Translate, create, update, and link explicit media attachments (Workflow A & shared core for Workflow B).
	 *
	 * @return array<int, int>|WP_Error
	 */
	public static function translate_attachments(
		array $attachment_ids,
		int $job_id,
		string $target_lang,
		string $provider = '',
		array $in_memory_translations = array(),
		array $options = array()
	) {
		$target_lang         = sanitize_key( $target_lang );
		$user_id             = ( array_key_exists( 'user_id', $options ) && null !== $options['user_id'] ) ? (int) $options['user_id'] : null;
		$enforce_permissions = array_key_exists( 'enforce_permissions', $options ) ? (bool) $options['enforce_permissions'] : true;

		$primary_id   = (int) ( ! empty( $attachment_ids ) ? reset( $attachment_ids ) : 0 );
		$valid_job_id = 0;
		if ( $job_id > 0 && $primary_id > 0 ) {
			global $wpdb;
			$table = Bulk_Process::get_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$valid_job_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND post_id = %d AND item_type = 'media'", $job_id, $primary_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$authorized = self::authorize_attachments( $attachment_ids, $target_lang, $user_id, $enforce_permissions );
		if ( is_wp_error( $authorized ) ) {
			return $valid_job_id > 0
				? Bulk_Process::fail_job( $valid_job_id, __( 'Error occurred during authorization check', 'translatesense' ), $authorized )
				: $authorized;
		}

		$source_lang = sanitize_key( (string) ( $options['source_lang'] ?? '' ) );
		if ( '' === $source_lang ) {
			foreach ( $authorized as $attachment ) {
				$slug = Media_Parser::get_attachment_language( (int) $attachment->ID );
				if ( '' !== $slug && $slug !== $target_lang ) {
					$source_lang = $slug;
					break;
				}
			}
		}

		$items = ( isset( $options['extracted_items'] ) && is_array( $options['extracted_items'] ) )
			? $options['extracted_items']
			: Media_Parser::extract_attachment_strings( array_keys( $authorized ), $target_lang, $user_id, $enforce_permissions );

		if ( empty( $items ) ) {
			$no_text_err = new WP_Error( 'no_text_found', __( 'No text found in the media files', 'translatesense' ), $items );
			return $valid_job_id > 0
				? Bulk_Process::fail_job( $valid_job_id, __( 'No text found in the media files', 'translatesense' ), $no_text_err )
				: $no_text_err;
		}

		$preloaded = self::ensure_items_translated( $items, $authorized, $valid_job_id, $primary_id, $source_lang, $target_lang, $provider, $in_memory_translations );

		if ( is_wp_error( $preloaded ) ) {
			return $valid_job_id > 0
				? Bulk_Process::fail_job( $valid_job_id, __( 'Error occurred during AI Translation', 'translatesense' ), $preloaded )
				: $preloaded;
		}

		$resolved_map = array();
		foreach ( $authorized as $source_att_id => $source_attachment ) {
			$resolved_id = self::resolve_attachment( $source_attachment, $target_lang, $provider, $in_memory_translations, $user_id, $enforce_permissions );
			if ( is_wp_error( $resolved_id ) ) {
				return $valid_job_id > 0
					? Bulk_Process::fail_job( $valid_job_id, __( 'Failed to save translated media', 'translatesense' ), $resolved_id )
					: $resolved_id;
			}
			$resolved_map[ (int) $source_att_id ] = absint( $resolved_id );
		}

		if ( $valid_job_id > 0 ) {
			Bulk_Process::update_job(
				$valid_job_id,
				array(
					'status'          => 'completed',
					'progress_status' => __( 'Media Translation completed', 'translatesense' ),
					'error_message'   => null,
					'target_post_id'  => ( $primary_id > 0 && isset( $resolved_map[ $primary_id ] ) ) ? absint( $resolved_map[ $primary_id ] ) : 0,
					'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		return $resolved_map;
	}

	/**
	 * Resolve a post's featured image translation before saving the target post (Workflow B).
	 * Reuses `translate_attachments()` and converts errors to a non-fatal warning.
	 *
	 * @return array{ has_thumbnail: bool, source_thumbnail_id: int, target_thumbnail_id: int, warning: string }
	 */
	public static function prepare_featured_image_for_post(
		int $source_post_id,
		string $target_lang,
		string $source_lang,
		string $provider = '',
		array $in_memory_translations = array(),
		array $options = array()
	): array {
		$result = array(
			'has_thumbnail'       => false,
			'source_thumbnail_id' => 0,
			'target_thumbnail_id' => 0,
			'warning'             => '',
		);

		$target_lang = sanitize_key( $target_lang );
		if ( $source_post_id <= 0 || '' === $target_lang || ! Polylang_Bridge::is_media_translation_enabled() ) {
			return $result;
		}

		$source_thumbnail_id = absint( get_post_thumbnail_id( $source_post_id ) );
		if ( ! Media_Parser::is_valid_attachment( $source_thumbnail_id ) ) {
			return $result;
		}

		$result['has_thumbnail']       = true;
		$result['source_thumbnail_id'] = $source_thumbnail_id;

		// Reuse existing translated attachment immediately without re-translating.
		$existing_id = Media_Parser::get_translation_id( $source_thumbnail_id, $target_lang );
		if ( $existing_id > 0 ) {
			$result['target_thumbnail_id'] = $existing_id;
			return $result;
		}

		$att_lang = Media_Parser::get_attachment_language( $source_thumbnail_id );
		if ( $att_lang === $target_lang ) {
			$result['target_thumbnail_id'] = $source_thumbnail_id;
			return $result;
		}

		$post_lang = sanitize_key( (string) Polylang_Bridge::get_post_language( $source_post_id ) ) ?: sanitize_key( $source_lang );
		if ( '' === $att_lang ) {
			$default_lang = sanitize_key( (string) Polylang_Bridge::get_default_language() );
			if ( '' !== $post_lang && $post_lang === $default_lang ) {
				Polylang_Bridge::set_post_language( $source_thumbnail_id, $default_lang );
				$att_lang = $default_lang;
			} else {
				/* translators: %d: attachment ID */
				$result['warning'] = sprintf( __( 'Featured image %d skipped: no language assigned in Polylang.', 'translatesense' ), $source_thumbnail_id );
				return $result;
			}
		}

		$options['source_lang'] = $att_lang;
		$translated             = self::translate_attachments( array( $source_thumbnail_id ), 0, $target_lang, $provider, $in_memory_translations, $options );
		if ( is_wp_error( $translated ) ) {
			$result['warning'] = $translated->get_error_message();
			return $result;
		}

		$result['target_thumbnail_id'] = absint( $translated[ $source_thumbnail_id ] ?? 0 );
		return $result;
	}

	/**
	 * Assign resolved translated featured image to the target post (Workflow B).
	 */
	public static function assign_featured_image_to_post( int $target_post_id, array $featured_result, bool $is_existing = false ): void {
		if ( $target_post_id <= 0 || ! Polylang_Bridge::is_media_translation_enabled() ) {
			return;
		}

		$source_thumbnail_id = absint( $featured_result['source_thumbnail_id'] ?? 0 );
		$target_thumbnail_id = absint( $featured_result['target_thumbnail_id'] ?? 0 );
		$current_target_id   = absint( get_post_thumbnail_id( $target_post_id ) );

		if ( empty( $featured_result['has_thumbnail'] ) || $source_thumbnail_id <= 0 ) {
			if ( $is_existing && $current_target_id > 0 ) {
				delete_post_thumbnail( $target_post_id );
			}
			return;
		}

		if ( $target_thumbnail_id > 0 ) {
			if ( $current_target_id !== $target_thumbnail_id ) {
				set_post_thumbnail( $target_post_id, $target_thumbnail_id );
			}
		} elseif ( $current_target_id > 0 && $current_target_id === $source_thumbnail_id ) {
			delete_post_thumbnail( $target_post_id );
		}
	}

	/**
	 * Resolve a product's gallery media translation before saving the target product (Workflow B / WC Integration).
	 * Reuses `translate_attachments()` and converts errors to non-fatal warnings.
	 *
	 * @param int    $source_post_id         Source product post ID.
	 * @param string $target_lang            Target language slug.
	 * @param string $source_lang            Source language slug.
	 * @param string $provider               AI Provider ID.
	 * @param array  $in_memory_translations In-memory translations indexed by hash.
	 * @param array  $options                Additional options (user_id, enforce_permissions, etc.).
	 * @return array{ has_gallery: bool, source_gallery_ids: int[], target_gallery_ids: int[], warnings: string[] }
	 */
	public static function prepare_product_gallery_for_post(
		int $source_post_id,
		string $target_lang,
		string $source_lang,
		string $provider = '',
		array $in_memory_translations = array(),
		array $options = array()
	): array {
		$result = array(
			'has_gallery'        => false,
			'source_gallery_ids' => array(),
			'target_gallery_ids' => array(),
			'warnings'           => array(),
		);

		$target_lang = sanitize_key( $target_lang );
		if ( $source_post_id <= 0 || '' === $target_lang || ! Polylang_Bridge::is_media_translation_enabled() ) {
			return $result;
		}

		$source_gallery_ids = Media_Parser::get_product_gallery_image_ids( $source_post_id );
		if ( empty( $source_gallery_ids ) ) {
			return $result;
		}

		$result['has_gallery']        = true;
		$result['source_gallery_ids'] = $source_gallery_ids;

		$post_lang    = sanitize_key( (string) Polylang_Bridge::get_post_language( $source_post_id ) ) ?: sanitize_key( $source_lang );
		$default_lang = sanitize_key( (string) Polylang_Bridge::get_default_language() );

		$resolved_map     = array();
		$to_translate_ids = array();
		$warnings         = array();

		foreach ( $source_gallery_ids as $attachment_id ) {
			if ( ! Media_Parser::is_valid_attachment( $attachment_id ) ) {
				continue;
			}

			// 1. Check if translated attachment already exists in Polylang
			$existing_id = Media_Parser::get_translation_id( $attachment_id, $target_lang );
			if ( $existing_id > 0 ) {
				$resolved_map[ $attachment_id ] = $existing_id;
				continue;
			}

			// 2. Check if attachment is already in the target language
			$att_lang = Media_Parser::get_attachment_language( $attachment_id );
			if ( $att_lang === $target_lang ) {
				$resolved_map[ $attachment_id ] = $attachment_id;
				continue;
			}

			// 3. Ensure language is assigned in Polylang
			if ( '' === $att_lang ) {
				if ( '' !== $post_lang && $post_lang === $default_lang ) {
					Polylang_Bridge::set_post_language( $attachment_id, $default_lang );
					$att_lang = $default_lang;
				} else {
					/* translators: %d: attachment ID */
					$warnings[] = sprintf( __( 'Gallery image %d skipped: no language assigned in Polylang.', 'translatesense' ), $attachment_id );
					continue;
				}
			}

			$to_translate_ids[] = $attachment_id;
		}

		if ( ! empty( $to_translate_ids ) ) {
			$translate_options                = $options;
			$translate_options['source_lang'] = $post_lang;

			$translated = self::translate_attachments(
				$to_translate_ids,
				0,
				$target_lang,
				$provider,
				$in_memory_translations,
				$translate_options
			);

			if ( is_wp_error( $translated ) ) {
				$warnings[] = $translated->get_error_message();
			} elseif ( is_array( $translated ) ) {
				foreach ( $translated as $src_id => $tgt_id ) {
					$tgt_id = absint( $tgt_id );
					if ( $tgt_id > 0 ) {
						$resolved_map[ (int) $src_id ] = $tgt_id;
					}
				}
			}
		}

		// Rebuild target gallery IDs preserving the source gallery order
		$target_gallery_ids = array();
		foreach ( $source_gallery_ids as $source_att_id ) {
			if ( isset( $resolved_map[ $source_att_id ] ) && $resolved_map[ $source_att_id ] > 0 ) {
				$target_gallery_ids[] = $resolved_map[ $source_att_id ];
			}
		}

		$result['target_gallery_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $target_gallery_ids ) ) ) );
		$result['warnings']           = $warnings;

		return $result;
	}

	/**
	 * Assign resolved translated gallery images to the target WooCommerce product (Workflow B / WC Integration).
	 *
	 * @param int   $target_post_id Target product post ID.
	 * @param array $gallery_result Result from prepare_product_gallery_for_post().
	 * @param bool  $is_existing    Whether this is an update to an existing translation.
	 */
	public static function assign_product_gallery_to_post( int $target_post_id, array $gallery_result, bool $is_existing = false ): void {
		if ( $target_post_id <= 0 || ! Polylang_Bridge::is_media_translation_enabled() ) {
			return;
		}

		$source_gallery_ids = (array) ( $gallery_result['source_gallery_ids'] ?? array() );
		$target_gallery_ids = (array) ( $gallery_result['target_gallery_ids'] ?? array() );
		$has_gallery        = ! empty( $gallery_result['has_gallery'] ) && ! empty( $source_gallery_ids );

		if ( ! $has_gallery ) {
			if ( $is_existing ) {
				if ( function_exists( 'wc_get_product' ) ) {
					$target_product = wc_get_product( $target_post_id );
					if ( $target_product instanceof \WC_Product ) {
						$target_product->set_gallery_image_ids( array() );
						$target_product->save();
					}
				}
				delete_post_meta( $target_post_id, '_product_image_gallery' );
			}
			return;
		}

		if ( ! empty( $target_gallery_ids ) ) {
			$gallery_string = implode( ',', $target_gallery_ids );

			if ( function_exists( 'wc_get_product' ) ) {
				$target_product = wc_get_product( $target_post_id );
				if ( $target_product instanceof \WC_Product ) {
					$target_product->set_gallery_image_ids( $target_gallery_ids );
					$target_product->save();
				}
			}

			update_post_meta( $target_post_id, '_product_image_gallery', $gallery_string );
		}
	}

	/**
	 * Synchronize a product's gallery media from source to target product.
	 *
	 * @param int    $source_post_id Source product post ID.
	 * @param int    $target_post_id Target product post ID.
	 * @param string $target_lang    Target language slug.
	 * @param array  $context        Execution context including provider, in_memory_translations.
	 * @param bool   $is_existing    Whether updating an existing target product.
	 * @return array Gallery result.
	 */
	public static function synchronize_product_gallery(
		int $source_post_id,
		int $target_post_id,
		string $target_lang,
		array $context = array(),
		bool $is_existing = false
	): array {
		if ( ! Polylang_Bridge::is_media_translation_enabled() ) {
			return array();
		}

		$source_lang = sanitize_key( (string) Polylang_Bridge::get_post_language( $source_post_id ) );
		if ( '' === $source_lang ) {
			$source_lang = sanitize_key( (string) Polylang_Bridge::get_default_language() );
		}

		$provider               = (string) ( $context['provider'] ?? '' );
		$in_memory_translations = is_array( $context['in_memory_translations'] ?? null ) ? $context['in_memory_translations'] : array();

		$gallery_result = self::prepare_product_gallery_for_post(
			$source_post_id,
			$target_lang,
			$source_lang,
			$provider,
			$in_memory_translations,
			$context
		);

		self::assign_product_gallery_to_post( $target_post_id, $gallery_result, $is_existing );

		return $gallery_result;
	}

	/**
	 * Resolve, update, or create one translated attachment in `$target_lang`.
	 *
	 * @return int|WP_Error
	 */
	private static function resolve_attachment(
		WP_Post $source_attachment,
		string $target_lang,
		string $provider,
		array $in_memory_translations,
		?int $user_id = null,
		bool $enforce_permissions = true
	) {
		$source_id    = (int) $source_attachment->ID;
		$att_language = Media_Parser::get_attachment_language( $source_id );

		if ( '' === $att_language ) {
			/* translators: %d: attachment ID */
			return new WP_Error( 'no_language', sprintf( __( 'Media attachment %d does not have a language assigned.', 'translatesense' ), $source_id ) );
		}

		if ( $att_language === $target_lang ) {
			return $source_id;
		}

		$existing_id = Media_Parser::get_translation_id( $source_id, $target_lang );
		if ( $existing_id > 0 ) {
			if ( ! $enforce_permissions || Utils::user_has_cap( $user_id, 'edit_post', $existing_id ) ) {
				self::translate_attachment_meta( $source_attachment, $existing_id, $att_language, $target_lang, $provider, $in_memory_translations, false );
			}
			return $existing_id;
		}

		return self::create_translated_attachment( $source_attachment, $att_language, $target_lang, $provider, $in_memory_translations );
	}

	/**
	 * Create a translated attachment via Polylang's media API under a concurrency lock
	 * and apply translated alt text, caption, description, and configured metadata.
	 *
	 * @return int|WP_Error
	 */
	private static function create_translated_attachment(
		WP_Post $source_attachment,
		string $actual_source_lang,
		string $target_lang,
		string $provider,
		array $in_memory_translations
	) {
		if ( self::$translating ) {
			return new WP_Error( 'translatesense_media_recursion', __( 'Recursive media translation prevented.', 'translatesense' ) );
		}

		$source_id = (int) $source_attachment->ID;
		$lock_key  = 'translatesense_media_lock_' . md5( $source_id . ':' . $target_lang );

		if ( ! Utils::acquire_lock( $lock_key ) ) {
			$existing_id = Media_Parser::get_translation_id( $source_id, $target_lang );
			return $existing_id > 0
				? $existing_id
				: new WP_Error( 'translatesense_media_translation_locked', __( 'This media attachment is already being translated. Please retry shortly.', 'translatesense' ) );
		}

		self::$translating = true;

		try {
			$existing_id = Media_Parser::get_translation_id( $source_id, $target_lang );
			if ( $existing_id > 0 ) {
				return $existing_id;
			}

			$polylang  = function_exists( 'PLL' ) ? PLL() : null;
			$target_id = 0;
			if ( is_object( $polylang ) && isset( $polylang->model->post ) && is_callable( array( $polylang->model->post, 'create_media_translation' ) ) ) {
				$target_id = absint( $polylang->model->post->create_media_translation( $source_id, $target_lang ) );
			} elseif ( is_object( $polylang ) && isset( $polylang->posts ) && is_callable( array( $polylang->posts, 'create_media_translation' ) ) ) {
				$target_id = absint( $polylang->posts->create_media_translation( $source_id, $target_lang ) );
			}

			if ( $target_id <= 0 || Media_Parser::get_translation_id( $source_id, $target_lang ) !== $target_id ) {
				return new WP_Error( 'translatesense_media_create_failed', __( 'The translated media attachment could not be created or linked in Polylang.', 'translatesense' ) );
			}

			$custom_opts        = array(
				'provider'               => $provider,
				'in_memory_translations' => $in_memory_translations,
			);
			$raw_alt            = get_post_meta( $source_id, '_wp_attachment_image_alt', true );
			$translated_alt     = self::translate_field( is_string( $raw_alt ) ? $raw_alt : '', $actual_source_lang, $target_lang, $custom_opts, true );
			$translated_caption = self::translate_field( (string) $source_attachment->post_excerpt, $actual_source_lang, $target_lang, $custom_opts );
			$translated_desc    = self::translate_field( (string) $source_attachment->post_content, $actual_source_lang, $target_lang, $custom_opts );
			$translated_title   = self::translate_field( (string) $source_attachment->post_title, $actual_source_lang, $target_lang, $custom_opts );
			$is_translatable    = ( $translated_alt !== $raw_alt || $translated_caption !== (string) $source_attachment->post_excerpt || $translated_desc !== (string) $source_attachment->post_content || $translated_title !== (string) $source_attachment->post_title );

			$post_data = array(
				'ID'           => $target_id,
				'post_excerpt' => $translated_caption,
				'post_content' => $translated_desc,
				'post_title'   => $translated_title,
			);

			$target_post = get_post( $target_id );

			$unique_slug = $target_post->post_name;

			$target_slug = sanitize_title( Translate::translate_slug( $source_attachment->post_name, $translated_title, $actual_source_lang, $target_lang, $custom_opts, 'media_attachment' ) );

			if ( ! empty( $target_slug ) ) {
				$post_data['post_name'] = $target_slug;
				$unique_slug            = $target_slug;
			}

			$slug_changed = ( $target_post instanceof WP_Post && $target_post->post_name !== $unique_slug );

			if ( $target_post instanceof WP_Post && ( $is_translatable || $slug_changed ) ) {
				add_filter( 'pll_enable_duplicate_media', '__return_false', 99 );
				try {
					wp_update_post( wp_slash( $post_data ) );
				} finally {
					remove_filter( 'pll_enable_duplicate_media', '__return_false', 99 );
				}
			}

			if ( '' !== trim( $translated_alt ) ) {
				update_post_meta( $target_id, '_wp_attachment_image_alt', wp_slash( $translated_alt ) );
			}

			self::copy_attachment_meta( $source_attachment, $target_id );
			self::translate_attachment_meta( $source_attachment, $target_id, $actual_source_lang, $target_lang, $provider, $in_memory_translations, true );

			return $target_id;
		} finally {
			self::$translating = false;
			Utils::release_lock( $lock_key );
		}
	}

	/**
	 * Translate and sanitize an attachment field (alt, caption, or description).
	 */
	private static function translate_field( string $source, string $source_lang, string $target_lang, array $custom_opts, bool $single_line = false ): string {
		if ( '' === trim( $source ) ) {
			return $source;
		}
		$translated = (string) ( Translate::translate_string( $source, $source_lang, $target_lang, 'media_attachment', $custom_opts )['translated'] ?? $source );

		return $single_line
			? sanitize_text_field( $translated )
			: Utils::sanitize_translated_text( $translated, true );
	}

	/**
	 * Copy non-translatable custom attachment metadata unchanged to the target attachment.
	 */
	private static function copy_attachment_meta( WP_Post $source_attachment, int $target_attachment_id ): void {
		$translate = array_flip( Media_Parser::get_translatable_meta_keys( $source_attachment ) );

		foreach ( (array) get_post_meta( (int) $source_attachment->ID ) as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) || Media_Parser::is_protected_meta_key( $meta_key ) || isset( $translate[ $meta_key ] ) || metadata_exists( 'post', $target_attachment_id, $meta_key ) ) {
				continue;
			}

			foreach ( (array) $values as $value ) {
				add_post_meta( $target_attachment_id, $meta_key, wp_slash( Utils::safe_unserialize( $value ) ), false );
			}
		}
	}

	/**
	 * Translate configured attachment metadata keys.
	 */
	private static function translate_attachment_meta(
		WP_Post $source_attachment,
		int $target_attachment_id,
		string $source_lang,
		string $target_lang,
		string $provider,
		array $in_memory_translations,
		bool $overwrite = false
	): void {
		$custom_opts = array(
			'provider'               => $provider,
			'in_memory_translations' => $in_memory_translations,
		);

		foreach ( Media_Parser::get_translatable_meta_keys( $source_attachment ) as $meta_key ) {
			$source_values = get_post_meta( (int) $source_attachment->ID, $meta_key, false );
			if ( empty( $source_values ) || ( ! $overwrite && metadata_exists( 'post', $target_attachment_id, $meta_key ) ) ) {
				continue;
			}

			delete_post_meta( $target_attachment_id, $meta_key );

			foreach ( (array) $source_values as $source_value ) {
				if ( ! is_string( $source_value ) || '' === trim( $source_value ) || Utils::is_only_numbers( $source_value ) ) {
					add_post_meta( $target_attachment_id, $meta_key, wp_slash( $source_value ), false );
					continue;
				}

				$translated_val = (string) ( Translate::translate_string( $source_value, $source_lang, $target_lang, 'media_attachment_meta', $custom_opts )['translated'] ?? $source_value );
				add_post_meta( $target_attachment_id, $meta_key, wp_slash( Utils::sanitize_translated_text( $translated_val ) ), false );
			}
		}
	}
}
