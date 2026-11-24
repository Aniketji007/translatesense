/**
 * WidgetTableRow Component - List View for Blocks / Widgets
 */

import { DashboardIcons } from './DashboardIcons';
import { __, _n, sprintf } from '@wordpress/i18n';

export function WidgetTableRow({ widget, onEdit, onGenerate }) {
  const isSupported = widget.status !== 'unsupported';

  return (
    <tr>
      {/* Column 1: BLOCK / WIDGET NAME */}
      <td>
        <div className="ts-table-name-col">
          <span className="ts-table-widget-title">{widget.name}</span>
          <span className="ts-table-widget-slug">{widget.id}</span>
        </div>
      </td>

      {/* Column 2: CATEGORY */}
      <td>
        <span className="ts-category-text">{widget.category || 'standard'}</span>
      </td>

      {/* Column 3: MAPPED FIELDS */}
      <td>
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' }}>
          <span className={`ts-badge ts-badge-translate ${widget.field_count > 0 ? '' : 'empty'}`}>
            {sprintf(
              /* translators: %s: Number of fields to translate. */
              _n( '%s Translate field', '%s Translate fields', widget.field_count || 0, 'translatesense' ),
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
      </td>

      {/* Column 4: STATUS */}
      <td>
        {widget.status === 'supported' ? (
          <span className="ts-table-status supported">{ __( 'Supported', 'translatesense' ) }</span>
        ) : widget.status === 'custom' ? (
          <span className="ts-table-status custom">{ __( 'Supported', 'translatesense' ) }</span>
        ) : (
          <span className="ts-table-status unsupported">{ __( 'Unsupported', 'translatesense' ) }</span>
        )}
      </td>

      {/* Column 5: ACTIONS */}
      <td style={{ textAlign: 'right' }}>
        {isSupported ? (
          <button
            type="button"
            className="ts-table-btn edit"
            onClick={() => onEdit(widget)}
            title={ __( 'Edit XML rule directly', 'translatesense' ) }
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
            onClick={() => onGenerate(widget)}
            title={ __( 'Generate XML configuration rule', 'translatesense' ) }
          >
            { __( 'Generate', 'translatesense' ) }
          </button>
        )}
      </td>
    </tr>
  );
}

