/**
 * WidgetCard Component - Grid View for Blocks / Widgets
 */

import { DashboardIcons } from './DashboardIcons';
import { __, _n, sprintf } from '@wordpress/i18n';

export function WidgetCard({ widget, activeTab, onEdit, onGenerate }) {
  const isSupported = widget.status !== 'unsupported';

  return (
    <div className="ts-widget-card">
      <div className="ts-card-top">
        <div>
          <h3 className="ts-card-title">{widget.name}</h3>
          <div className="ts-card-slug">{widget.id}</div>
        </div>
        <span className={`ts-table-status ${widget.status}`}>
          {widget.status === 'supported' ? __( 'Supported', 'translatesense' ) : widget.status === 'custom' ? __( 'Custom', 'translatesense' ) : __( 'Unsupported', 'translatesense' )}
        </span>
      </div>

      <div className="ts-card-meta">
        <span>{sprintf(
          /* translators: %s: Widget category name. */
          __( 'Category: %s', 'translatesense' ),
          widget.category || __( 'Standard', 'translatesense' )
        )}</span>
        <span>•</span>
        <span className={`ts-badge ts-badge-translate ${widget.field_count > 0 ? '' : 'empty'}`}>
          {sprintf(
            /* translators: %s: Number of fields to translate. */
            _n( '%s Translate Field', '%s Translate Fields', widget.field_count || 0, 'translatesense' ),
            widget.field_count || 0
          )}
        </span>
        {widget.no_translate_count > 0 && (
          <span className="ts-badge ts-badge-no-translate">
            {sprintf( /* translators: %s: Number of fields that are not translated. */ __( '%s No Translate', 'translatesense' ), widget.no_translate_count )}
          </span>
        )}
        {((widget.xpaths && widget.xpaths.length > 0) || widget.xpath) && (
          <span
            className="ts-badge ts-badge-xpath"
            title={
              widget.xpaths && widget.xpaths.length > 0
                ? widget.xpaths.map((x) => (x.type ? `[${x.type}] ${x.value}` : x.value)).join('\n')
                : sprintf( /* translators: %s: XPath expression. */ __( 'XPath: %s', 'translatesense' ), widget.xpath )
            }
          >
            {widget.xpaths && widget.xpaths.length > 0 && sprintf( /* translators: %s: Number of items. */ __( '%s XPaths', 'translatesense' ), widget.xpaths.length )}
          </span>
        )}
      </div>

      <div className="ts-card-actions">
        {isSupported ? (
          <button
            type="button"
            className="ts-table-btn edit"
            style={{ width: '100%' }}
            onClick={() => onEdit(widget)}
          >
            <span style={{ display: 'inline-flex', marginRight: '5px' }}>
              {DashboardIcons.pencil({ size: 12 })}
            </span>
            { __( 'Edit Config', 'translatesense' ) }
          </button>
        ) : (
          <button
            type="button"
            className="ts-table-btn generate"
            style={{ width: '100%' }}
            onClick={() => onGenerate(widget)}
          >
            { __( 'Generate', 'translatesense' ) }
          </button>
        )}
      </div>
    </div>
  );
}

