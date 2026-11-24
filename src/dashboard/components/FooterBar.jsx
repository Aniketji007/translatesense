/**
 * FooterBar Component - Fixed/Sticky Footer Status & Actions
 */

import { useState } from 'react';
import { DashboardIcons } from './DashboardIcons';
import { CacheConfirmModal } from './CacheConfirmModal';
import { __ } from '@wordpress/i18n';

import { createInterpolateElement } from '@wordpress/element';
export function FooterBar({
  activeTab,
  onSaveSettings,
  isSaving,
  cacheCounts,
  onRefreshCache,
  isRefreshing = false,
  onClearCache,
  pluginUrl = ''
}) {
  const logoSrc = pluginUrl ? `${pluginUrl}assets/img/dragwyb.png` : 'assets/img/dragwyb.png';

  const [modal, setModal] = useState({
    isOpen: false,
    type: 'all',
    title: '',
    warning: '',
    count: 0,
  });
  const [isClearing, setIsClearing] = useState(false);

  const handleOpenModal = (type) => {
    const title = __( 'Clear Entire TranslateSense Cache', 'translatesense' );
    const warning = __( 'Permanently purges ALL translated sentences, extracted source strings, widget mapping packages, and bulk batch logs.', 'translatesense' );
    const count = (cacheCounts.strings || 0) +
      (cacheCounts.translations || 0) +
      (cacheCounts.packages || 0) +
      (cacheCounts.logs || 0);;

    setModal({
      isOpen: true,
      type,
      title,
      warning,
      count,
    });
  };

  const handleCloseModal = () => {
    setModal({ isOpen: false, type: 'all', title: '', warning: '', count: 0 });
  };

  const handleConfirmClear = async () => {
    setIsClearing(true);
    try {
      if (onClearCache) {
        await onClearCache(modal.type);
      }
    } finally {
      setIsClearing(false);
      handleCloseModal();
    }
  };

  return (
    <>
      <footer className={`ts-footer-bar ${['settings', 'cache'].includes(activeTab) ? 'ts-footer-sticky' : ''}`}>
        <div className="ts-footer-left">
          <span className="ts-footer-logo">
            <img
              className="ts-footer-logo-img"
              src={logoSrc}
              alt={ __( 'Dragwyb Logo', 'translatesense' ) }
              onError={(e) => {
                e.target.style.display = 'none';
              }}
            />
            { __( 'Dragwyb', 'translatesense' ) }
          </span>
          <span>{ __( 'Built with ❤️ by Dragwyb', 'translatesense' ) }</span>
        </div>

        {activeTab === 'cache' &&
          <div className="ts-footer-right">
            <button
              type="button"
              className="ts-btn ts-btn-secondary ts-btn-md"
              disabled={isRefreshing}
              onClick={onRefreshCache}
              title={ __( 'Refresh cache counts from database', 'translatesense' ) }
            >
              <span
                className={`ts-refresh-icon ${isRefreshing ? 'spinning' : ''}`}
                style={{ display: 'inline-flex', marginRight: '5px' }}
              >
                {DashboardIcons.refresh({ size: 14 })}
              </span>
              {isRefreshing ? __( 'Refreshing...', 'translatesense' ) : __( 'Refresh Counts', 'translatesense' )}
            </button>
            <button
              type="button"
              className="ts-btn ts-btn-danger-outline ts-btn-md"
              onClick={() => handleOpenModal('all')}
              title={ __( 'Clear all stored strings, translations, packages, and logs', 'translatesense' ) }
            >
              <span style={{ display: 'inline-flex', marginRight: '5px' }}>
                {DashboardIcons.trash({ size: 13 })}
              </span>
              { __( 'Clear All Caches', 'translatesense' ) }
            </button>
          </div>
        }

        {activeTab === 'settings' && (
          <div className="ts-footer-right">
            <div className="ts-save-status">
              {createInterpolateElement(
                /* translators: %s: Keyboard shortcut, wrapped in the kbd tag. */
                __( 'Tip: Press <kbd>Ctrl + S</kbd>', 'translatesense' ),
                { kbd: <kbd className="ts-kbd-shortcut" /> }
              )}
            </div>
            <button
              type="button"
              disabled={isSaving}
              onClick={onSaveSettings}
              className="ts-btn ts-btn-primary button-primary"
            >
              {isSaving ? __( 'Saving...', 'translatesense' ) : __( 'Save Settings', 'translatesense' )}
            </button>
          </div>
        )}
      </footer>

      {/* Confirmation Modal */}
      <CacheConfirmModal
        modal={modal}
        isClearing={isClearing}
        onClose={handleCloseModal}
        onConfirm={handleConfirmClear}
      />
    </>
  );
}

