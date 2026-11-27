import { __, sprintf } from '@wordpress/i18n';
/**
 * BulkProgress Component (Step 3 Progress Bar Card)
 */
export function BulkProgress({
  statusText = __( 'Translating content...', 'translatesense' ),
  percent = 0,
  completedPosts = 0,
  totalPosts = 0,
  timerText = '00:00 elapsed',
  isChromeAi = false,
  activeBatches = [],
  currentBatchId = null,
  onBatchChange,
  isTranslating,
  itemPlural = 'posts',
}) {
  return (
    <div className="ts-bulk-progress-section">
      {/* Batch switcher if multiple batches active */}
      {activeBatches && activeBatches.length > 1 && (
        <div className="ts-batch-switcher-container">
          <label htmlFor="ts-batch-select" className="ts-batch-select-label">
            { __( 'Background Batch:', 'translatesense' ) }
          </label>
          <select
            id="ts-batch-select"
            className="ts-batch-select"
            value={currentBatchId || ''}
            onChange={(e) => onBatchChange && onBatchChange(e.target.value)}
          >
            {activeBatches.map((b) => {
              const done = (b.completed_jobs || 0) + (b.already_exists_jobs || 0) + (b.skipped_jobs || 0);
              return (
                <option key={b.batch_id} value={b.batch_id}>
                  {sprintf(
                    /* translators: 1: Short batch ID, 2: Finished jobs, 3: Total jobs, 4: Batch status. */
                    __( 'Batch %1$s... (%2$s/%3$s done, %4$s)', 'translatesense' ),
                    b.batch_id.substring(0, 8),
                    done,
                    b.total_jobs,
                    b.overall_status
                  )}
                </option>
              );
            })}
          </select>
        </div>
      )}

      {/* Mode notice banner */}
      {isTranslating &&
        <div className="ts-modal-notice-banner">
          <span className="ts-notice-icon" aria-hidden="true">
            {isChromeAi ? '⚠️' : '⚡'}
          </span>
          <div className="ts-notice-text">
            <strong className="ts-notice-title">
              {isChromeAi ? __( 'Keep Window Open ', 'translatesense' ) : __( 'Server-Side Translation Active ', 'translatesense' )}
            </strong>
            <span className="ts-notice-desc">
              {isChromeAi
                ? __( 'Translation is running in your browser session. Navigating away will cancel remaining jobs.', 'translatesense' )
                : __( 'Your translations are processing in the background on the server. You can safely close this window or navigate away at any time.', 'translatesense' )}
            </span>
          </div>
        </div>
      }

      {/* Progress card */}
      <div className="ts-progress-card">
        <div className="ts-progress-header">
          <span className="ts-progress-status">{statusText}</span>
          <div className="ts-progress-meta">
            <span className="ts-progress-posts-count">
              {completedPosts} / {totalPosts} {itemPlural} ({Math.round(percent)}%)
            </span>
            <span className="ts-progress-timer">{timerText}</span>
          </div>
        </div>
        <div className="ts-progress-bar-bg">
          <div
            className="ts-progress-bar-fill"
            style={{ width: `${Math.min(100, Math.max(0, Math.round(percent)))}%` }}
          />
        </div>
      </div>
    </div>
  );
}

