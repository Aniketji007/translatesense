/**
 * PostResultItem Component
 * Single post group card with accordion translation rows
 */

import { useState } from '@wordpress/element';
import { getLanguageFlag } from '../../utils/language';
import { getBulkItemLabels, getDisplayTitle } from '../utils/bulkFormatters';
import { __, sprintf } from '@wordpress/i18n';

export function PostResultItem({
  postId,
  postData,
  allLanguages = [],
  isBackgroundMode = false,
  canSkip = false,
  activeBatchId = null,
  onSkipPost,
  onErrorDetail,
  itemMeta = {},
}) {
  const [expanded, setExpanded] = useState(true);
  const { isTaxonomy, isMedia, singular: itemSingular, singularTitle: itemSingularTitle } = getBulkItemLabels(itemMeta);

  const {
    postTitle = `${itemSingularTitle} #${postId}`,
    translations = {},
    provider = '',
  } = postData;
  const transKeys = Object.keys(translations);

  let compCount = 0;
  let failCount = 0;
  let runCount = 0;
  let pendCount = 0;
  let skipCount = 0;

  transKeys.forEach((langSlug) => {
    const t = translations[langSlug];
    if (t.status === 'completed' || t.status === 'already_exists') {
      compCount++;
    } else if (t.status === 'failed') {
      failCount++;
    } else if (t.status === 'skipped') {
      skipCount++;
    } else if (t.status === 'running' || t.status === 'processing' || t.status === 'cancelling') {
      runCount++;
    } else {
      pendCount++;
    }
  });

  // Skip button strictly visible in background monitor mode for OpenAI/Gemini with pending/running items
  // AND only when progress session context allows skip (existing_queue sessions)
  const isEligibleProvider = provider === 'openai' || provider === 'gemini';
  const hasActiveJobs = runCount > 0 || pendCount > 0;
  const showSkipBtn = Boolean(canSkip) && isBackgroundMode && isEligibleProvider && hasActiveJobs;

  const handleSkipClick = (e) => {
    e.stopPropagation();
    if (onSkipPost) {
      onSkipPost(postId, activeBatchId);
    }
  };

  return (
    <div className={`ts-post-group-card ${expanded ? 'expanded extended' : ''}`}>
      <div
        className="ts-post-group-header"
        onClick={() => setExpanded((prev) => !prev)}
      >
        <div className="ts-post-source-info">
          <div className="ts-post-icon-box">
            <span className={`dashicons ${isTaxonomy ? 'dashicons-tag' : isMedia ? 'dashicons-format-image' : 'dashicons-media-text'}`} />
          </div>
          <span className="ts-source-title-text">{postTitle}</span>
          <span className="ts-post-id-tag">#{postId}</span>
        </div>

        <div className="ts-post-header-right">
          {showSkipBtn && (
            <button
              type="button"
              className="ts-post-skip-btn button button-small"
              onClick={handleSkipClick}
              title={sprintf( /* translators: %s: Item singular. */ __( 'Skip remaining translations for this %s', 'translatesense' ), itemSingular )}
            >
              { __( 'Skip Translations', 'translatesense' ) }
            </button>
          )}

          {failCount > 0 && (
            <span className="ts-post-status-pill failed">
              {sprintf(
                /* translators: %s: Number of failed translations. */
                __( 'Failed (%s)', 'translatesense' ),
                failCount
              )}
            </span>
          )}

          {skipCount > 0 && skipCount + compCount === transKeys.length && compCount === 0 && (
            <span
              className={`ts-post-status-pill ${transKeys.every(
                (k) =>
                  translations[k]?.error === 'no_language' ||
                  translations[k]?.progressStatus === 'No Language'
              )
                ? 'no-language'
                : 'skipped'
                }`}
            >
              {transKeys.every(
                (k) =>
                  translations[k]?.error === 'no_language' ||
                  translations[k]?.progressStatus === 'No Language'
              )
                ? __( 'No Language', 'translatesense' )
                : __( 'Skipped', 'translatesense' )}
            </span>
          )}

          <span
            className={`ts-post-chevron dashicons ${expanded ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2'
              }`}
          />
        </div>
      </div>

      {expanded && (
        <div className="ts-post-translations-list ts-translations-accordion-body">
          {transKeys.map((langSlug) => {
            const trans = translations[langSlug];
            const flagInfo = getLanguageFlag(langSlug, allLanguages);
            const displayTitle = getDisplayTitle(trans, postTitle, postId, itemSingularTitle);
            const isDone =
              trans.status === 'completed' ||
              (trans.status === 'already_exists' && !trans.error);
            const isFail = trans.status === 'failed';
            const isSkipped = trans.status === 'skipped';
            const isCancelling = trans.status === 'cancelling';
            const isRunning = trans.status === 'running' || trans.status === 'processing';

            const editUrl = trans.editUrl && '' !== trans.editUrl ? trans.editUrl : '';
            const viewUrl = trans.viewUrl && '' !== trans.viewUrl ? trans.viewUrl : ''

            const isUnsupported =
              trans.error &&
              trans.error.toLowerCase().includes('not supported');
            const failText = isUnsupported ? __( 'Language not supported', 'translatesense' ) : __( 'Failed', 'translatesense' );

            return (
              <div key={langSlug} className={`ts-translation-item ${trans.status}`}>
                <div className="ts-trans-lang-col">
                  {flagInfo.hasFlag ? (
                    <img src={flagInfo.flagUrl} alt="" className="ts-lang-flag-img" />
                  ) : null}
                  <span className="ts-trans-lang-code">{flagInfo.code}</span>
                </div>

                <div className="ts-trans-title-col">
                  <span className="ts-trans-title-text" title={displayTitle}>
                    {displayTitle}
                  </span>
                </div>

                <div className="ts-trans-status-col">
                  {trans.status === 'already_exists' ? (
                    trans.error ? (
                      <button
                        type="button"
                        className="ts-row-status already-exists ts-error-detail-btn"
                        onClick={() =>
                          onErrorDetail &&
                          onErrorDetail({
                            title: sprintf( /* translators: %1$s: Name, %2$s: Code. */ __( '%1$s (%2$s) — Already Exists', 'translatesense' ), flagInfo.name, flagInfo.code ),
                            message: trans.error,
                            icon: flagInfo.hasFlag ? (
                              <img src={flagInfo.flagUrl} alt="" className="ts-lang-flag-img" />
                            ) : null
                          })
                        }
                        title={ __( 'Click to view details', 'translatesense' ) }
                      >
                        <span className="dashicons dashicons-warning" /> { __( 'Already exist In Trash', 'translatesense' ) }
                      </button>
                    ) : (
                      <span className="ts-row-status already-exists">
                        <span className="dashicons dashicons-yes-alt" /> { __( 'Already exist', 'translatesense' ) }
                      </span>
                    )
                  ) : trans.status === 'completed' ? (
                    <span className="ts-row-status completed">
                      <span className="dashicons dashicons-yes-alt" /> { __( 'Completed', 'translatesense' ) }
                    </span>
                  ) : isSkipped ? (
                    trans.error === 'no_language' || trans.progressStatus === 'No Language' ? (
                      <button
                        type="button"
                        className="ts-row-status failed ts-error-detail-btn"
                        onClick={() => {
                          const targetLabel = (itemSingular || 'item').toLowerCase();
                          const capitalizedTarget = itemSingularTitle || (targetLabel.charAt(0).toUpperCase() + targetLabel.slice(1));
                          const sourceUrl = trans.sourceEditUrl || postData.sourceEditUrl || editUrl || '';
                          if (onErrorDetail) {
                            onErrorDetail({
                              type: 'no_language',
                              title: __( 'Language Not Assigned', 'translatesense' ),
                              subtitle: `${flagInfo.name} (${flagInfo.code}) — ${displayTitle || postTitle}`,
                              heading: sprintf( /* translators: %s: Target label. */ __( 'This %s doesn\'t have a language assigned in Polylang.', 'translatesense' ), targetLabel ),
                              message: __( 'Assign a language before translating it. Open the editor to select the default language or choose another language.', 'translatesense' ),
                              stepTip: __( 'After assigning a language, click Translate Again in the progress modal to translate only failed items.', 'translatesense' ),
                              actionUrl: sourceUrl,
                              actionLabel: sprintf( /* translators: %s: Capitalized target. */ __( 'Edit %s', 'translatesense' ), capitalizedTarget ),
                              icon: 'dashicons-warning',
                            });
                          }
                        }}
                        title={ __( 'Click to view details and assign a language', 'translatesense' ) }
                      >
                        <span className="dashicons dashicons-warning" /> { __( 'No Language', 'translatesense' ) }
                      </button>
                    ) : (
                      <span className="ts-row-status skipped">
                        { __( 'Skipped', 'translatesense' ) }
                      </span>
                    )
                  ) : isCancelling ? (
                    <span className="ts-row-status cancelling">
                      <span className="ts-spinner-circle" /> { __( 'Cancelling...', 'translatesense' ) }
                    </span>
                  ) : isFail ? (
                    <button
                      type="button"
                      className="ts-row-status failed ts-error-detail-btn"
                      onClick={() =>
                        onErrorDetail &&
                        onErrorDetail({
                          title: sprintf( /* translators: %1$s: Name, %2$s: Code. */ __( '%1$s (%2$s) Error', 'translatesense' ), flagInfo.name, flagInfo.code ),
                          message: trans.error || __( 'Translation failed.', 'translatesense' ),
                          errorObj: trans.errorObj,
                        })
                      }
                      title={ __( 'Click to view error details', 'translatesense' ) }
                    >
                      <span className="dashicons dashicons-dismiss" /> {failText}
                    </button>
                  ) : isRunning ? (
                    <span className="ts-row-status translating">
                      <span className="ts-spinner-circle" />
                      <span className="ts-translating-label">{ __( 'Translating', 'translatesense' ) }</span>
                      <span className="ts-ellipsis-dots">
                        <span className="ts-dot" />
                        <span className="ts-dot" />
                        <span className="ts-dot" />
                      </span>
                    </span>
                  ) : (
                    <span className="ts-row-status pending">
                      <span className="dashicons dashicons-clock" /> { __( 'Pending...', 'translatesense' ) }
                    </span>
                  )}
                </div>

                <div className="ts-trans-actions-col">
                  {isDone ? (
                    <>
                      {viewUrl && viewUrl !== '#' && (
                        <a
                          href={viewUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="ts-row-action-btn view"
                          title={sprintf( /* translators: %s: Item singular. */ __( 'View translated %s on site', 'translatesense' ), itemSingular )}
                        >
                          <span className="dashicons dashicons-visibility" /> { __( 'View', 'translatesense' ) }
                        </a>
                      )}
                      {editUrl && editUrl !== '#' && (
                        <a
                          href={editUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="ts-row-action-btn edit"
                          title={sprintf( /* translators: %s: Item singular. */ __( 'Edit translated %s', 'translatesense' ), itemSingular )}
                        >
                          <span className="dashicons dashicons-edit" /> { __( 'Edit', 'translatesense' ) }
                        </a>
                      )}
                    </>
                  ) : isSkipped && (trans.error === 'no_language' || trans.progressStatus === 'No Language') ? (
                    trans.sourceEditUrl || postData.sourceEditUrl ? (
                      <a
                        href={trans.sourceEditUrl || postData.sourceEditUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="ts-row-action-btn edit"
                        title={sprintf( /* translators: %s: Item singular. */ __( 'Edit source %s to assign language', 'translatesense' ), itemSingular )}
                      >
                        <span className="dashicons dashicons-edit" /> { __( 'Edit', 'translatesense' ) }
                      </a>
                    ) : null
                  ) : !isFail && !isSkipped ? (
                    <>
                      <span className="ts-row-action-btn view disabled">
                        <span className="dashicons dashicons-visibility" /> { __( 'View', 'translatesense' ) }
                      </span>
                      <span className="ts-row-action-btn edit disabled">
                        <span className="dashicons dashicons-edit" /> { __( 'Edit', 'translatesense' ) }
                      </span>
                    </>
                  ) : null}
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

