/**
 * SkipConfirmation Component
 */

import { ConfirmDialog } from '../../components/ConfirmDialog';
import { getBulkItemLabels } from '../utils/bulkFormatters';
import { __, sprintf } from '@wordpress/i18n';

export function SkipConfirmation({
  isOpen,
  onClose,
  onConfirm,
  loading = false,
  itemMeta = {},
}) {
  const { singular: itemSingular } = getBulkItemLabels(itemMeta);

  return (
    <ConfirmDialog
      isOpen={isOpen}
      onClose={onClose}
      onConfirm={onConfirm}
      title={ __( 'Skip remaining translations?', 'translatesense' ) }
      message={sprintf( /* translators: %s: Item singular. */ __( 'This will skip the remaining pending translations for this %s in the selected batch. Completed translations will not be affected.', 'translatesense' ), itemSingular )}
      confirmLabel={ __( 'Skip Remaining Translations', 'translatesense' ) }
      cancelLabel={ __( 'Keep Translating', 'translatesense' ) }
      isDanger={true}
      loading={loading}
    />
  );
}

