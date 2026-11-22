/**
 * Server-side Translation Service
 */

import { apiRequest } from '../api/apiClient';
import { ENDPOINTS } from '../api/endpoints';

/**
 * Extract translatable strings from post
 * @param {number} postId
 * @param {string} [builder]
 * @param {string} [targetLang]
 * @returns {Promise<{ success: boolean, strings: Array }>}
 */
export async function extractStrings(postId, builder = '', targetLang = '', options = {}) {
  const {
    type = 'post',
    subtype = '',
    includeTaxonomies = true,
  } = options || {};

  return await apiRequest(ENDPOINTS.EXTRACT_STRINGS, {
    method: 'POST',
    data: {
      post_id: Number(postId),
      builder,
      target_lang: targetLang,
      type,
      subtype,
      include_taxonomies: Boolean(includeTaxonomies),
    },
  });
}

/**
 * Create or save translated post on server
 * @param {Object} payload
 * @returns {Promise<{ success: boolean, target_post_id: number, edit_url: string, translated_title: string }>}
 */
export async function translatePost(payload) {
  return await apiRequest(ENDPOINTS.TRANSLATE_POST, {
    method: 'POST',
    data: payload,
  });
}

