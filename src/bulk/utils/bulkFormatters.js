import { __ } from '@wordpress/i18n';
/**
 * Get status badge CSS class and dashicon for a translation status
 * @param {string} status
 * @returns {{ className: string, icon: string, label: string }}
 */
export function getStatusBadgeInfo(status) {
  const s = String(status || 'pending').toLowerCase();
  switch (s) {
    case 'completed':
      return { className: 'completed', icon: 'dashicons-yes-alt', label: __( 'Completed', 'translatesense' ) };
    case 'already_exists':
      return { className: 'completed already-exists', icon: 'dashicons-yes-alt', label: __( 'Already exists', 'translatesense' ) };
    case 'skipped':
      return { className: 'skipped', icon: 'dashicons-controls-skipforward', label: __( 'Skipped', 'translatesense' ) };
    case 'cancelling':
      return { className: 'cancelling', icon: 'ts-spinner-circle', label: __( 'Cancelling...', 'translatesense' ) };
    case 'failed':
      return { className: 'failed', icon: 'dashicons-dismiss', label: __( 'Failed', 'translatesense' ) };
    case 'running':
    case 'processing':
    case 'translating':
      return { className: 'translating', icon: 'ts-spinner-circle', label: __( 'Translating', 'translatesense' ) };
    case 'pending':
    default:
      return { className: 'pending', icon: 'dashicons-clock', label: __( 'Pending...', 'translatesense' ) };
  }
}

/**
 * Resolve item labels (posts vs taxonomy terms) from explicit metadata or localized config.
 */
export function getBulkItemLabels(itemMeta = {}) {
  const bulkData =
    (typeof window !== 'undefined' && window.TranslateSenseBulkData) || {};
  const subtype = itemMeta.subtype !== undefined ? itemMeta.subtype : (bulkData.subtype || '');
  const itemType = itemMeta.type || bulkData.type || 'post';
  const isTaxonomy =
    typeof itemMeta.isTaxonomy === 'boolean'
      ? itemMeta.isTaxonomy
      : itemType === 'term';
  const singular = itemMeta.itemSingular || bulkData.itemSingular || (isTaxonomy ? 'term' : 'post');
  const plural = itemMeta.itemPlural || bulkData.itemPlural || (isTaxonomy ? 'terms' : 'posts');
  const capitalize = (str) => (str ? str.charAt(0).toUpperCase() + str.slice(1) : '');
  const itemLabel = itemMeta.itemLabel || bulkData.itemLabel || capitalize(plural);

  return {
    type: isTaxonomy ? 'term' : 'post',
    isTaxonomy,
    subtype,
    itemLabel,
    singular,
    plural,
    singularTitle: capitalize(singular),
    pluralTitle: itemLabel,
  };
}

/**
 * Safe title display for a translation
 */
export function getDisplayTitle(trans, postTitle, postId, isTaxonomyOrPrefix = false) {
  const prefix =
    typeof isTaxonomyOrPrefix === 'string' && isTaxonomyOrPrefix
      ? isTaxonomyOrPrefix
      : (isTaxonomyOrPrefix ? __( 'Term', 'translatesense' ) : __( 'Post', 'translatesense' ));
  if (trans.targetTitle) return trans.targetTitle;
  if (trans.status === 'already_exists' || trans.status === 'completed') {
    return postTitle || `${prefix} #${postId}`;
  }
  if (trans.status === 'failed') return `${prefix} #${postId}`;
  if (trans.status === 'running' || trans.status === 'processing') return __( 'Translating content...', 'translatesense' );
  return __( 'Waiting in queue', 'translatesense' );
}

