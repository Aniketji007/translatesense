/**
 * CacheTab Component - Cache & Memory Management Panel
 */

import { useState } from '@wordpress/element';
import { CacheConfirmModal } from './CacheConfirmModal';
import { DashboardIcons } from './DashboardIcons';
import { __ } from '@wordpress/i18n';

export function CacheTab({
  cacheCounts = {},
  onClearCache,
}) {
  const [modal, setModal] = useState({
    isOpen: false,
    type: 'all',
    title: '',
    warning: '',
    count: 0,
  });
  const [isClearing, setIsClearing] = useState(false);

  const totalCache =
    (cacheCounts.strings || 0) +
    (cacheCounts.translations || 0) +
    (cacheCounts.packages || 0) +
    (cacheCounts.logs || 0);

  const handleOpenModal = (type) => {
    let title = __( 'Clear Cache', 'translatesense' );
    let warning = __( 'Are you sure you want to proceed?', 'translatesense' );
    let count = 0;

    if (type === 'translations') {
      title = __( 'Clear Translations Cache (Memory)', 'translatesense' );
      warning = __( 'Removing this cache means repeated texts and previously translated sentences will call AI again, incurring additional token usage.', 'translatesense' );
      count = cacheCounts.translations || 0;
    } else if (type === 'strings') {
      title = __( 'Clear Central Source Strings Cache', 'translatesense' );
      warning = __( 'Removes extracted source strings from local memory index. Strings will be re-extracted when translating posts.', 'translatesense' );
      count = cacheCounts.strings || 0;
    } else if (type === 'logs') {
      title = __( 'Clear Translation Logs & Batches', 'translatesense' );
      warning = __( 'Permanently clears historical background batch logs, error reports, and translation execution time records.', 'translatesense' );
      count = cacheCounts.logs || 0;
    } else if (type === 'packages') {
      title = __( 'Clear Block & Widget Packages', 'translatesense' );
      warning = __( 'Post-level widget/block mappings will be cleared and regenerated automatically during the next translation..', 'translatesense' );
      count = cacheCounts.packages || 0;
    } else if (type === 'all') {
      title = __( 'Clear Entire TranslateSense Cache', 'translatesense' );
      warning = __( 'Permanently purges ALL translated sentences, extracted source strings, widget mapping packages, and bulk batch logs.', 'translatesense' );
      count = totalCache;
    }

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
    <div className="ts-cache-container">
      {/* Summary Card */}
      <div className="ts-cache-summary-card">
        <div className="ts-cache-summary-main">
          <div className="ts-cache-summary-label">{ __( 'TOTAL CACHED DATABASE RECORDS', 'translatesense' ) }</div>
          <div className="ts-cache-summary-number">{totalCache.toLocaleString()}</div>
          <div className="ts-cache-summary-sub">
            { __( 'Across Translation Memory, Source Strings, Bulk Logs & Widget Packages', 'translatesense' ) }
          </div>
        </div>
        <div className="ts-cache-summary-breakdown">
          <div className="ts-summary-pill">
            <span className="ts-pill-dot green" />
            <span className="ts-pill-lbl">{ __( 'Translations:', 'translatesense' ) }</span>
            <strong>{(cacheCounts.translations || 0).toLocaleString()}</strong>
          </div>
          <div className="ts-summary-pill">
            <span className="ts-pill-dot blue" />
            <span className="ts-pill-lbl">{ __( 'Strings:', 'translatesense' ) }</span>
            <strong>{(cacheCounts.strings || 0).toLocaleString()}</strong>
          </div>
          <div className="ts-summary-pill">
            <span className="ts-pill-dot purple" />
            <span className="ts-pill-lbl">{ __( 'Packages:', 'translatesense' ) }</span>
            <strong>{(cacheCounts.packages || 0).toLocaleString()}</strong>
          </div>
          <div className="ts-summary-pill">
            <span className="ts-pill-dot orange" />
            <span className="ts-pill-lbl">{ __( 'Logs:', 'translatesense' ) }</span>
            <strong>{(cacheCounts.logs || 0).toLocaleString()}</strong>
          </div>
        </div>
      </div>

      {/* 4 Options Grid */}
      <div className="ts-cache-grid">
        {/* Option 1: Translations Cache (AI Translation Memory) */}
        <div className="ts-cache-card highlight-token">
          <div className="ts-cache-card-top">
            <div className="ts-cache-card-info">
              <div className="ts-cache-card-icon green">{DashboardIcons.globe({ size: 20 })}</div>
              <div>
                <h3 className="ts-cache-card-title">{ __( 'Translations Cache', 'translatesense' ) }</h3>
                <span className="ts-cache-card-sub">{ __( 'AI Translation Memory', 'translatesense' ) }</span>
              </div>
            </div>
            <span className={`ts-cache-status-badge ${cacheCounts.translations > 0 ? 'active' : 'empty'}`}>
              {cacheCounts.translations > 0 ? __( 'Active & Caching', 'translatesense' ) : __( 'Empty', 'translatesense' )}
            </span>
          </div>
          <div className="ts-cache-card-stat">
            <span className="ts-cache-big-num">{(cacheCounts.translations || 0).toLocaleString()}</span>
            <span className="ts-cache-unit">{ __( 'translated string pairs', 'translatesense' ) }</span>
          </div>
          <p className="ts-cache-card-desc">
            { __( 'Stores previously translated sentences mapped to target languages and MD5 hashes. Eliminates duplicate AI queries when retranslating pages or encountering repeated texts.', 'translatesense' ) }
          </p>
          <div className="ts-cache-card-actions">
            <button
              type="button"
              className="ts-btn-cache-danger"
              disabled={!cacheCounts.translations}
              onClick={() => handleOpenModal('translations')}
            >
              <span style={{ display: 'inline-flex', marginRight: '6px' }}>
                {DashboardIcons.trash({ size: 13 })}
              </span>
              { __( 'Clear Translations Cache', 'translatesense' ) }
            </button>
          </div>
        </div>

        {/* Option 2: Strings Cache (Central Source Memory) */}
        <div className="ts-cache-card">
          <div className="ts-cache-card-top">
            <div className="ts-cache-card-info">
              <div className="ts-cache-card-icon blue">{DashboardIcons.document({ size: 20 })}</div>
              <div>
                <h3 className="ts-cache-card-title">{ __( 'Strings Cache', 'translatesense' ) }</h3>
                <span className="ts-cache-card-sub">{ __( 'Central Translation Memory', 'translatesense' ) }</span>
              </div>
            </div>
            <span className={`ts-cache-status-badge ${cacheCounts.strings > 0 ? 'active' : 'empty'}`}>
              {cacheCounts.strings > 0 ? __( 'Active & Indexed', 'translatesense' ) : __( 'Empty', 'translatesense' )}
            </span>
          </div>
          <div className="ts-cache-card-stat">
            <span className="ts-cache-big-num">{(cacheCounts.strings || 0).toLocaleString()}</span>
            <span className="ts-cache-unit">{ __( 'unique source strings', 'translatesense' ) }</span>
          </div>
          <p className="ts-cache-card-desc">
            { __( 'Stores normalized source strings extracted from your blocks and widgets with their respective MD5 hashes as central Translation Memory index references.', 'translatesense' ) }
          </p>
          <div className="ts-cache-card-actions">
            <button
              type="button"
              className="ts-btn-cache-danger"
              disabled={!cacheCounts.strings}
              onClick={() => handleOpenModal('strings')}
            >
              <span style={{ display: 'inline-flex', marginRight: '6px' }}>
                {DashboardIcons.trash({ size: 13 })}
              </span>
              { __( 'Clear Strings Cache', 'translatesense' ) }
            </button>
          </div>
        </div>

        {/* Option 3: Translation Logs & Queue */}
        <div className="ts-cache-card">
          <div className="ts-cache-card-top">
            <div className="ts-cache-card-info">
              <div className="ts-cache-card-icon orange">{DashboardIcons.clock({ size: 20 })}</div>
              <div>
                <h3 className="ts-cache-card-title">{ __( 'Translation Logs & Queue', 'translatesense' ) }</h3>
                <span className="ts-cache-card-sub">{ __( 'Bulk History & Batches', 'translatesense' ) }</span>
              </div>
            </div>
            <span className={`ts-cache-status-badge ${cacheCounts.logs > 0 ? 'active' : 'empty'}`}>
              {cacheCounts.logs > 0 ? __( 'Logged', 'translatesense' ) : __( 'No Logs', 'translatesense' )}
            </span>
          </div>
          <div className="ts-cache-card-stat">
            <span className="ts-cache-big-num">{(cacheCounts.logs || 0).toLocaleString()}</span>
            <span className="ts-cache-unit">{ __( 'queue & history records', 'translatesense' ) }</span>
          </div>
          <p className="ts-cache-card-desc">
            { __( 'Stores bulk translation batch entries, execution time statistics, completed/failed statuses, and background queue records for historical auditing.', 'translatesense' ) }
          </p>
          <div className="ts-cache-card-actions">
            <button
              type="button"
              className="ts-btn-cache-danger"
              disabled={!cacheCounts.logs}
              onClick={() => handleOpenModal('logs')}
            >
              <span style={{ display: 'inline-flex', marginRight: '6px' }}>
                {DashboardIcons.trash({ size: 13 })}
              </span>
              { __( 'Clear Translation Logs', 'translatesense' ) }
            </button>
          </div>
        </div>

        {/* Option 4: Packages Cache (Widget & Block Mappings) */}
        <div className="ts-cache-card">
          <div className="ts-cache-card-top">
            <div className="ts-cache-card-info">
              <div className="ts-cache-card-icon purple">{DashboardIcons.package({ size: 20 })}</div>
              <div>
                <h3 className="ts-cache-card-title">{ __( 'Packages Cache', 'translatesense' ) }</h3>
                <span className="ts-cache-card-sub">{ __( 'Block & Widget Mapping Context', 'translatesense' ) }</span>
              </div>
            </div>
            <span className={`ts-cache-status-badge ${cacheCounts.packages > 0 ? 'active' : 'empty'}`}>
              {cacheCounts.packages > 0 ? __( 'Active & Linked', 'translatesense' ) : __( 'Empty', 'translatesense' )}
            </span>
          </div>
          <div className="ts-cache-card-stat">
            <span className="ts-cache-big-num">{(cacheCounts.packages || 0).toLocaleString()}</span>
            <span className="ts-cache-unit">{ __( 'mapped field packages', 'translatesense' ) }</span>
          </div>
          <p className="ts-cache-card-desc">
            { __( 'Maintains associations between specific post IDs, editor types, widget keys, and string hashes. Allows instant reconstruction of translated layouts without reparsing.', 'translatesense' ) }
          </p>
          <div className="ts-cache-card-actions">
            <button
              type="button"
              className="ts-btn-cache-danger"
              disabled={!cacheCounts.packages}
              onClick={() => handleOpenModal('packages')}
            >
              <span style={{ display: 'inline-flex', marginRight: '6px' }}>
                {DashboardIcons.trash({ size: 13 })}
              </span>
              { __( 'Clear Packages Cache', 'translatesense' ) }
            </button>
          </div>
        </div>
      </div>

      {/* Confirmation Modal */}
      <CacheConfirmModal
        modal={modal}
        isClearing={isClearing}
        onClose={handleCloseModal}
        onConfirm={handleConfirmClear}
      />
    </div>
  );
}
