/**
 * LanguageSelection Component (Bulk Step 1 Modal)
 */

import { Modal, ModalHeader, ModalFooter } from '../../components/Modal';
import { Button } from '../../components/Button';
import { LanguageSelector } from '../../components/LanguageSelector';
import { getBulkItemLabels } from '../utils/bulkFormatters';
import { __, sprintf } from '@wordpress/i18n';

import { createInterpolateElement } from '@wordpress/element';
export function LanguageSelection({
  isOpen = true,
  onClose,
  postIds = [],
  allLanguages = [],
  selectedLanguages = [],
  onLanguagesChange,
  onNext,
  activeBatches = [],
  onShowRunning,
  itemMeta = {},
}) {
  const postsCount = postIds.length;
  const { singular: itemSingular, plural: itemPlural } = getBulkItemLabels(itemMeta);

  const localizedData =
    (typeof window !== 'undefined' &&
      (window.TranslateSenseBulkData ||
        window.TranslateSenseEditorData ||
        window.TranslateSenseData)) ||
    {};

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      className="ts-bulk-wizard-modal"
      dataStep={1}
      header={
        <ModalHeader
          title={ __( 'Bulk Translation', 'translatesense' ) }
          subtitle={ __( 'Fast & Accurate Multi-Language Content Localizer', 'translatesense' ) }
          onClose={onClose}
          closeClass="ts-bulk-close"
        />
      }
      footer={
        <ModalFooter align="right">
          <Button
            variant="primary"
            disabled={selectedLanguages.length === 0}
            title="Select at least one target language"
            onClick={onNext}
          >
            { __( 'Next: Choose Provider →', 'translatesense' ) }
          </Button>
        </ModalFooter>
      }
    >
      {activeBatches && activeBatches.length > 0 && (
        <div className="ts-previous-active-notice" id="ts-previous-active-notice">
          <div className="ts-notice-inner">
            <span className="ts-notice-icon" aria-hidden="true">
              ⚡
            </span>
            <div className="ts-notice-text">
              <strong className="ts-notice-title">{ __( 'Background translations are running', 'translatesense' ) }</strong>
              <span className="ts-notice-desc">
                {activeBatches.length === 1
                  ? __( 'There is 1 active translation batch in progress.', 'translatesense' )
                  : sprintf( /* translators: %s: Number of items. */ __( 'There are %s active translation batches in progress.', 'translatesense' ), activeBatches.length )}
              </span>
            </div>
          </div>
          {onShowRunning && (
            <button
              type="button"
              className="button button-secondary ts-show-running-btn"
              onClick={onShowRunning}
            >
              { __( 'View Progress', 'translatesense' ) }
            </button>
          )}
        </div>
      )}

      {/* Polylang Custom Fields Synchronization Notice */}
      {localizedData.isCustomFieldsSyncEnabled && (
        <div
          className="ts-bulk-sync-notice"
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            padding: '8px 14px',
            background: '#eff6ff',
            border: '1px solid #bfdbfe',
            borderRadius: '6px',
            color: '#1e40af',
            fontSize: '12.5px',
            marginBottom: '14px',
            lineHeight: 1.4,
          }}
        >
          <span style={{ fontSize: '14px', flexShrink: 0 }}>ℹ️</span>
          <span>
            <strong>{ __( 'Polylang Synchronization active:', 'translatesense' ) }</strong> { __( 'Custom fields are synced automatically and will not be translated.', 'translatesense' ) }
          </span>
        </div>
      )}

      <div className="ts-lang-info-banner">
        <span className="ts-info-icon" aria-hidden="true">ℹ️</span>
        <span>
          {createInterpolateElement(
            sprintf(
              /* translators: %s: Number of items followed by the item type. */
              __( 'Targeting <strong>%s</strong>. Choose target language(s) to translate into:', 'translatesense' ),
              `${postsCount} ${postsCount === 1 ? itemSingular : itemPlural}`
            ),
            { strong: <strong className="ts-highlight-tag" /> }
          )}
        </span>
      </div>

      <LanguageSelector
        languages={allLanguages}
        selectedLanguages={selectedLanguages}
        onChange={onLanguagesChange}
      />
    </Modal>
  );
}

