/**
 * ProgressSummary Component
 * 4 metric summary cards displayed upon batch completion
 */

import { formatNumber } from '../../utils/formatting';
import { getBulkItemLabels } from '../utils/bulkFormatters';
import { __, sprintf } from '@wordpress/i18n';

export function ProgressSummary({
  completedCount = 0,
  skippedCount = 0,
  failedCount = 0,
  totalCharacters = 0,
  totalCount = 0,
  elapsedTime = '0s',
  itemMeta = {},
}) {
  const { pluralTitle } = getBulkItemLabels(itemMeta);

  return (
    <div className="ts-summary-stats-grid">
      {completedCount > 0 &&
        <div className="ts-stat-card green">
          <div className="ts-stat-icon-box green">
            <span className="dashicons dashicons-yes-alt" />
          </div>
          <div className="ts-stat-info">
            <div className="ts-stat-label">{sprintf(
              /* translators: %s: Item type in plural, such as posts. */
              __( 'Completed %s', 'translatesense' ),
              pluralTitle
            )}</div>
            <div className="ts-stat-val">
              {completedCount} / {totalCount}
            </div>
          </div>
        </div>
      }

      {skippedCount > 0 &&
        <div className="ts-stat-card gray">
          <div className="ts-stat-icon-box gray">
            <span className="dashicons dashicons-controls-skipforward" />
          </div>
          <div className="ts-stat-info">
            <div className="ts-stat-label">{sprintf(
              /* translators: %s: Item type in plural, such as posts. */
              __( 'Skipped %s', 'translatesense' ),
              pluralTitle
            )}</div>
            <div className="ts-stat-val">
              {skippedCount} / {totalCount}
            </div>
          </div>
        </div>
      }

      {failedCount > 0 &&
        <div className="ts-stat-card red">
          <div className="ts-stat-icon-box red">
            <span className="dashicons dashicons-dismiss" />
          </div>
          <div className="ts-stat-info">
            <div className="ts-stat-label">{sprintf(
              /* translators: %s: Item type in plural, such as posts. */
              __( 'Failed %s', 'translatesense' ),
              pluralTitle
            )}</div>
            <div className="ts-stat-val">
              {failedCount} / {totalCount}
            </div>
          </div>
        </div>
      }

      {totalCharacters > 0 &&
        <div className="ts-stat-card blue">
          <div className="ts-stat-icon-box blue">
            <span className="dashicons dashicons-editor-textcolor" />
          </div>
          <div className="ts-stat-info">
            <div className="ts-stat-label">{ __( 'Total Characters', 'translatesense' ) }</div>
            <div className="ts-stat-val" title={`${totalCharacters.toLocaleString()} characters`}>
              {formatNumber(totalCharacters)}
            </div>
          </div>
        </div>
      }

      <div className="ts-stat-card orange">
        <div className="ts-stat-icon-box orange">
          <span className="dashicons dashicons-clock" />
        </div>
        <div className="ts-stat-info">
          <div className="ts-stat-label">{ __( 'Elapsed Time', 'translatesense' ) }</div>
          <div className="ts-stat-val">{elapsedTime}</div>
        </div>
      </div>
    </div>
  );
}

