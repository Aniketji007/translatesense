/**
 * UnifiedBulkSelection Component
 * Single unified modal combining Target Language Selection and AI Provider Selection.
 */

import { useState, useMemo } from '@wordpress/element';
import { Modal, ModalHeader } from '../../components/Modal';
import { getLanguageFlag } from '../../utils/language';
import { getBulkItemLabels } from '../utils/bulkFormatters';
import { __, sprintf } from '@wordpress/i18n';

function GlobeIcon({ size = 18, color = 'currentColor' }) {
  return (
    <svg width={size} height={size} fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle r="8" cy="10" cx="9" />
      <path d="M9 2a15.3 15 0 0 1 3 8 15 15 0 0 1-3 8 15 15 0 0 1-3-8 15 15 0 0 1 3-8z" />
      <path d="M2 10h14" />
    </svg>
  )
}

function SparklesIcon({ size = 18, color = 'currentColor' }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke={color}
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
      <path d="M20 3v4" />
      <path d="M22 5h-4" />
    </svg>
  );
}

function LayersIcon({ size = 18, color = '#2563eb' }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke={color}
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <polygon points="12 2 2 7 12 12 22 7 12 2" />
      <polyline points="2 17 12 22 22 17" />
      <polyline points="2 12 12 17 22 12" />
    </svg>
  );
}

function TranslateIcon({ size = 18, color = 'currentColor' }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke={color}
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="m5 8 6 6" />
      <path d="m4 14 6-6 2-3" />
      <path d="M2 5h12" />
      <path d="M7 2h1" />
      <path d="m22 22-5-10-5 10" />
      <path d="M14 18h6" />
    </svg>
  );
}

function SearchIcon({ size = 15, color = '#94a3b8' }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke={color}
      strokeWidth="2.2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <circle cx="11" cy="11" r="8" />
      <line x1="21" y1="21" x2="16.65" y2="16.65" />
    </svg>
  );
}

function CheckIcon({ size = 10, color = '#ffffff' }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 12 10"
      fill="none"
      stroke={color}
      strokeWidth="2.5"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <polyline points="1.5 5 4.5 8 10.5 1.5" />
    </svg>
  );
}

export function UnifiedBulkSelection({
  isOpen = true,
  onClose,
  postIds = [],
  allLanguages = [],
  selectedLanguages = [],
  onLanguagesChange,
  providers = [],
  selectedProvider = '',
  onSelectProvider,
  onStartTranslation,
  onViewError,
  activeBatches = [],
  onShowRunning,
  isTranslating = false,
  itemMeta = {},
}) {
  const [searchQuery, setSearchQuery] = useState('');
  const { plural: itemPlural, pluralTitle: itemPluralTitle } = getBulkItemLabels(itemMeta);

  const localizedData =
    (typeof window !== 'undefined' &&
      (window.TranslateSenseBulkData ||
        window.TranslateSenseEditorData ||
        window.TranslateSenseData)) ||
    {};
  const defaultLanguage = localizedData.defaultLanguage || '';

  const isSourceLang = (lang) => {
    if (!lang) return false;
    if (lang.is_default) return true;
    if (defaultLanguage && lang.slug && lang.slug.toLowerCase() === defaultLanguage.toLowerCase()) {
      return true;
    }
    return false;
  };

  // Filtered languages for display
  const filteredLanguages = useMemo(() => {
    if (!searchQuery.trim()) return allLanguages;
    const q = searchQuery.toLowerCase().trim();
    return allLanguages.filter((l) => {
      const name = (l.name || '').toLowerCase();
      const code = (l.code || '').toLowerCase();
      const slug = (l.slug || '').toLowerCase();
      return name.includes(q) || code.includes(q) || slug.includes(q);
    });
  }, [allLanguages, searchQuery]);

  // Target non-source languages
  const selectableTargetLanguages = useMemo(() => {
    return allLanguages.filter((l) => !isSourceLang(l));
  }, [allLanguages, defaultLanguage]);

  // Handle individual language toggle
  const handleToggleLanguage = (slug) => {
    if (selectedLanguages.includes(slug)) {
      onLanguagesChange(selectedLanguages.filter((s) => s !== slug));
    } else {
      onLanguagesChange([...selectedLanguages, slug]);
    }
  };

  // Select all non-source languages
  const handleSelectAll = () => {
    const targets = selectableTargetLanguages.map((l) => l.slug);
    onLanguagesChange(targets);
  };

  // Clear all selected languages
  const handleClearAll = () => {
    onLanguagesChange([]);
  };

  // Provider selection handler
  const handleProviderClick = (p, e) => {
    const hasError =
      p.badgeType === 'error' ||
      (p.isSupported && p.isSupported.available === false);

    if (hasError && (p.id === 'chrome_ai' || p.id === 'chrome_local_ai')) {
      e.stopPropagation();
      if (onViewError) onViewError(p);
      return;
    }

    if (!p.isAvailable) return;

    if (onSelectProvider) {
      onSelectProvider(p.id);
    }
  };

  // Render provider status badge based on Section 3 rules
  const renderProviderBadge = (p, isSelected) => {
    const hasError =
      p.badgeType === 'error' ||
      (p.isSupported && p.isSupported.available === false);

    // 1. If unsupported or unavailable, show existing error control
    if (hasError) {
      return (
        <button
          type="button"
          className="ts-provider-badge error-badge ts-provider-error-btn"
          title={ __( 'View Error Details', 'translatesense' ) }
          onClick={(e) => {
            e.stopPropagation();
            if (onViewError) onViewError(p);
          }}
        >
          { __( '⚠️ View Error', 'translatesense' ) }
        </button>
      );
    }

    if (p.actionUrl) {
      return (
        <a
          href={p.actionUrl}
          target="_blank"
          rel="noopener noreferrer"
          className="ts-provider-badge error-badge api-required"
          onClick={(e) => e.stopPropagation()}
          title={p.badgeText || __( 'Configuration required', 'translatesense' )}
        >
          {p.badgeText || __( 'API key required', 'translatesense' )}
        </a>
      );
    }

    // 3. If Chrome Built-in AI is supported and available: display On Device badge
    if (p.id === 'chrome_ai' || p.id === 'chrome_local_ai') {
      return (
        <div className="ts-badge-with-info">
          <span className="ts-provider-badge on-device-badge">{ __( 'On Device', 'translatesense' ) }</span>
          <span
            className="ts-info-icon-small"
            title={ __( 'Runs on-device via Chrome Built-in AI', 'translatesense' ) }
          >
            ⓘ
          </span>
        </div>
      );
    }

    // 4. If OpenAI or Gemini is supported and available: display Available badge
    return <span className="ts-provider-badge available-badge">{ __( 'Available', 'translatesense' ) }</span>;
  };

  // Calculations for summary bar
  const postsCount = postIds.length;
  const targetLangsCount = selectedLanguages.length;
  const totalJobs = postsCount * targetLangsCount;

  // Selected provider validity
  const selectedProviderObj = providers.find((p) => p.id === selectedProvider);
  const isProviderAvailable = Boolean(selectedProviderObj?.isAvailable);
  const isStartDisabled =
    targetLangsCount === 0 ||
    !selectedProvider ||
    !isProviderAvailable ||
    isTranslating;

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      className="ts-bulk-wizard-modal ts-bulk-unified-modal"
      maxWidth="920px"
      dataStep={1}
      header={
        <ModalHeader
          title={ __( 'Bulk Translation', 'translatesense' ) }
          subtitle={sprintf( /* translators: %s: Item plural. */ __( 'Translate multiple %s into one or more languages using AI.', 'translatesense' ), itemPlural )}
          onClose={onClose}
          closeClass="ts-bulk-close"
        />
      }
      footer={
        <div className="ts-bulk-footer">
          <button
            type="button"
            className="ts-btn ts-btn-secondary button-secondary"
            onClick={onClose}
          >
            { __( 'Cancel', 'translatesense' ) }
          </button>
          <button
            type="button"
            className="ts-btn ts-btn-primary button-primary"
            disabled={isStartDisabled}
            onClick={onStartTranslation}
          >
            <span className="ts-start-icon">
              <TranslateIcon size={16} />
            </span>
            <span>{ __( 'Start Bulk Translation', 'translatesense' ) }</span>
            <span className="ts-start-arrow">&rarr;</span>
          </button>
        </div>
      }
    >
      <div className="ts-bulk-unified-body">
        {/* Background active running translations notice */}
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

        {/* Two-Column Selection Layout */}
        <div className="ts-bulk-columns">
          {/* Left Column: Target Languages */}
          <div className="ts-bulk-panel ts-bulk-languages-panel">
            <div className="ts-bulk-panel-header">
              <div className="ts-panel-title-group">
                <div className="ts-panel-icon-circle">
                  <GlobeIcon size={19} color="#2563eb" />
                </div>
                <div>
                  <h3 className="ts-panel-title">{ __( 'Target Languages', 'translatesense' ) }</h3>
                  <p className="ts-panel-subtitle">{ __( 'Choose the languages to translate into.', 'translatesense' ) }</p>
                </div>
              </div>
              <div className="ts-bulk-count-pill">
                {sprintf(
                  /* translators: 1: Number of selected languages, 2: Total number of languages. */
                  __( '%1$s of %2$s selected', 'translatesense' ),
                  selectedLanguages.length,
                  allLanguages.length
                )}
              </div>
            </div>

            {/* Search and Action Buttons Row */}
            <div className="ts-bulk-filter-row">
              {allLanguages.length > 6 &&
                <div className="ts-bulk-search-box">
                  <span className="ts-bulk-search-icon">
                    <SearchIcon size={14} color="#94a3b8" />
                  </span>
                  <input
                    type="text"
                    className="ts-bulk-search-input"
                    placeholder={ __( 'Search language...', 'translatesense' ) }
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                  />
                </div>
              }
              <div className="ts-bulk-btn-group">
                <button
                  type="button"
                  className="ts-bulk-action-btn"
                  onClick={handleSelectAll}
                >
                  { __( 'Select All', 'translatesense' ) }
                </button>
                <button
                  type="button"
                  className="ts-bulk-action-btn"
                  onClick={handleClearAll}
                >
                  { __( 'Clear All', 'translatesense' ) }
                </button>
              </div>
            </div>

            {/* 2-Column Languages Grid */}
            <div className="ts-bulk-lang-grid">
              {filteredLanguages.length === 0 ? (
                <div className="ts-bulk-empty-langs">{ __( 'No languages found.', 'translatesense' ) }</div>
              ) : (
                filteredLanguages.map((lang) => {
                  const isSource = isSourceLang(lang);
                  const isSelected = selectedLanguages.includes(lang.slug);
                  const flagInfo = getLanguageFlag(lang, allLanguages);
                  const displayCode = (lang.code || lang.slug || '').toUpperCase();

                  return (
                    <div
                      key={lang.slug}
                      className={`ts-bulk-lang-card ${isSelected ? 'selected' : ''}`}
                      onClick={() => handleToggleLanguage(lang.slug)}
                      dir={lang.rtl ? 'rtl' : 'ltr'}
                      title={
                        isSource
                          ? sprintf( /* translators: %s: Name. */ __( '%s is the default language', 'translatesense' ), lang.name )
                          : lang.name
                      }
                    >
                      <div
                        className={`ts-bulk-checkbox ${isSelected ? 'checked' : ''}`}
                      >
                        {isSelected && <CheckIcon size={9} color="#ffffff" />}
                      </div>

                      {flagInfo.hasFlag && (
                        <img
                          src={flagInfo.flagUrl}
                          alt=""
                          className="ts-bulk-lang-flag"
                        />
                      )}

                      <span className="ts-bulk-lang-name">{lang.name}</span>

                      <span className="ts-bulk-lang-code">{displayCode}</span>

                      {isSource && (
                        <span className="ts-bulk-source-badge">{ __( 'Source', 'translatesense' ) }</span>
                      )}
                    </div>
                  );
                })
              )}
            </div>
          </div>

          {/* Right Column: AI Provider */}
          <div className="ts-bulk-panel ts-bulk-provider-panel">
            <div className="ts-bulk-panel-header">
              <div className="ts-panel-title-group">
                <div className="ts-panel-icon-circle">
                  <SparklesIcon size={18} color="#2563eb" />
                </div>
                <div>
                  <h3 className="ts-panel-title">{ __( 'AI Provider', 'translatesense' ) }</h3>
                  <p className="ts-panel-subtitle">{ __( 'Choose a translation provider for this job.', 'translatesense' ) }</p>
                </div>
              </div>
            </div>

            {/* Provider Cards List */}
            <div className="ts-bulk-provider-list">
              {providers.map((p) => {
                const isSelected = selectedProvider === p.id;
                const isDisabled = !p.isAvailable;

                return (
                  <div
                    key={p.id}
                    className={`ts-bulk-provider-card ${isSelected ? 'selected' : ''} ${isDisabled ? 'disabled' : ''
                      }`}
                    onClick={(e) => handleProviderClick(p, e)}
                  >
                    <div
                      className={`ts-bulk-radio ${isSelected ? 'selected' : ''} ${isDisabled ? 'disabled' : ''
                        }`}
                    >
                      {isSelected && <span className="ts-bulk-radio-dot" />}
                    </div>

                    {p.logo && (
                      <img
                        src={p.logo}
                        alt={p.title}
                        className="ts-bulk-provider-logo"
                      />
                    )}

                    <span className="ts-bulk-provider-title">{p.title}</span>

                    <div className="ts-bulk-provider-badge-wrap">
                      {renderProviderBadge(p, isSelected)}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>

        {/* Translation Summary Section */}
        <div className="ts-bulk-summary-bar">
          <div className="ts-summary-item">
            <span className="ts-summary-icon">
              <LayersIcon size={18} color="#2563eb" />
            </span>
            <div className="ts-summary-text">
              <span className="ts-summary-num">{postsCount}</span>
              <span className="ts-summary-lbl">{itemPluralTitle}</span>
            </div>
          </div>

          <div className="ts-summary-divider" />

          <div className="ts-summary-item">
            <span className="ts-summary-icon">
              <GlobeIcon size={19} color="#2563eb" />
            </span>
            <div className="ts-summary-text">
              <span className="ts-summary-num">{targetLangsCount}</span>
              <span className="ts-summary-lbl">{ __( 'Target Languages', 'translatesense' ) }</span>
            </div>
          </div>

          <div className="ts-summary-divider" />

          <div className="ts-summary-item">
            <span className="ts-summary-icon">
              <TranslateIcon size={18} color="#2563eb" />
            </span>
            <div className="ts-summary-text">
              <span className="ts-summary-num">{totalJobs}</span>
              <span className="ts-summary-lbl">{ __( 'Translation Jobs', 'translatesense' ) }</span>
              <span
                className="ts-summary-info-tooltip"
                title={sprintf( /* translators: %1$s: Posts count, %2$s: Item plural, %3$s: Target langs count, %4$s: Total jobs. */ __( '%1$s %2$s × %3$s target languages = %4$s jobs', 'translatesense' ), postsCount, itemPlural, targetLangsCount, totalJobs )}
              >
                ⓘ
              </span>
            </div>
          </div>
        </div>
      </div>
    </Modal>
  );
}

