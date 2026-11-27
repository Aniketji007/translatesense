/**
 * BuilderConfigTab Component - Gutenberg Blocks & Elementor Widgets Manager
 */

import { useState, useMemo } from '@wordpress/element';
import { WidgetCard } from './WidgetCard';
import { WidgetTableRow } from './WidgetTableRow';
import { PaginationBar } from './PaginationBar';
import { DashboardIcons } from './DashboardIcons';
import { __, sprintf } from '@wordpress/i18n';

export function BuilderConfigTab({
  activeTab,
  widgets = [],
  onOpenXmlModal,
  onGenerateSandbox,
  isCreatingSandbox = false,
}) {
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState('all');
  const [selectedStatus, setSelectedStatus] = useState('all');
  const [sortBy, setSortBy] = useState('status');
  const [viewMode, setViewMode] = useState('grid');
  const [currentPage, setCurrentPage] = useState(1);
  const pageSize = 12;

  // Derive unique categories
  const availableCategories = useMemo(() => {
    const set = new Set();
    widgets.forEach((w) => {
      if (w.category && typeof w.category === 'string') {
        if (w.category.includes(',')) {
          const catArray = w.category.split(',');
          catArray.forEach((cat) => set.add(cat.trim()));
        } else {
          set.add(w.category);
        }
      }
    });
    return Array.from(set).sort();
  }, [widgets]);

  // Filter and sort items
  const filteredWidgets = useMemo(() => {
    let items = [...widgets];

    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase().trim();
      items = items.filter(
        (w) =>
          (w.name && w.name.toLowerCase().includes(q)) ||
          (w.id && w.id.toLowerCase().includes(q))
      );
    }

    if (selectedCategory !== 'all') {
      items = items.filter((w) => w.category === selectedCategory);
    }

    if (selectedStatus !== 'all') {
      items = items.filter((w) => {
        if (selectedStatus === 'supported') return w.status === 'supported' || w.status === 'custom';
        if (selectedStatus === 'custom') return w.status === 'custom';
        if (selectedStatus === 'unsupported') return w.status === 'unsupported';
        return true;
      });
    }

    items.sort((a, b) => {
      if (sortBy === 'name_asc') return (a.name || '').localeCompare(b.name || '');
      if (sortBy === 'name_desc') return (b.name || '').localeCompare(a.name || '');
      if (sortBy === 'fields_desc') return (b.field_count || 0) - (a.field_count || 0);
      if (sortBy === 'fields_asc') return (a.field_count || 0) - (b.field_count || 0);
      if (sortBy === 'status') {
        const order = { custom: 1, supported: 2, unsupported: 3 };
        return (order[a.status] || 99) - (order[b.status] || 99);
      }
      return 0;
    });

    return items;
  }, [widgets, searchQuery, selectedCategory, selectedStatus, sortBy]);

  // Pagination
  const totalItems = filteredWidgets.length;
  const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
  const validCurrentPage = Math.min(currentPage, totalPages);

  const paginatedWidgets = useMemo(() => {
    const start = (validCurrentPage - 1) * pageSize;
    return filteredWidgets.slice(start, start + pageSize);
  }, [filteredWidgets, validCurrentPage, pageSize]);

  const handleResetFilters = () => {
    setSearchQuery('');
    setSelectedCategory('all');
    setSelectedStatus('all');
    setSortBy('status');
    setCurrentPage(1);
  };

  const isFiltered =
    searchQuery.trim() !== '' ||
    selectedCategory !== 'all' ||
    selectedStatus !== 'all' ||
    sortBy !== 'status';

  const builderName = activeTab === 'gutenberg' ? 'Gutenberg' : 'Elementor';
  const unitName = activeTab === 'gutenberg' ? 'blocks' : 'widgets';

  return (
    <div>
      {/* Search & Filter Card */}
      <div className="ts-filter-card">
        {/* Row 1: Search + 3 Dropdowns */}
        <div className="ts-filter-row-top">
          <div className="ts-search-box">
            <span className="ts-search-icon">{DashboardIcons.search()}</span>
            <input
              type="text"
              className="ts-search-input"
              placeholder={sprintf( /* translators: %1$s: Builder name, %2$s: Unit name. */ __( 'Search %1$s %2$s...', 'translatesense' ), builderName, unitName )}
              value={searchQuery}
              onChange={(e) => {
                setSearchQuery(e.target.value);
                setCurrentPage(1);
              }}
            />
          </div>

          <select
            className="ts-filter-select"
            value={selectedCategory}
            onChange={(e) => {
              setSelectedCategory(e.target.value);
              setCurrentPage(1);
            }}
          >
            <option value="all">{ __( 'All Categories', 'translatesense' ) }</option>
            {availableCategories.map((cat) => (
              <option key={cat} value={cat}>
                {cat.charAt(0).toUpperCase() + cat.slice(1)}
              </option>
            ))}
          </select>

          <select
            className="ts-filter-select"
            value={selectedStatus}
            onChange={(e) => {
              setSelectedStatus(e.target.value);
              setCurrentPage(1);
            }}
          >
            <option value="all">{ __( 'All Statuses', 'translatesense' ) }</option>
            <option value="supported">{ __( 'Supported', 'translatesense' ) }</option>
            <option value="custom">{ __( 'Custom Config', 'translatesense' ) }</option>
            <option value="unsupported">{ __( 'Unsupported', 'translatesense' ) }</option>
          </select>

          <select
            className="ts-filter-select"
            value={sortBy}
            onChange={(e) => {
              setSortBy(e.target.value);
              setCurrentPage(1);
            }}
          >
            <option value="name_asc">{ __( 'Name (A to Z)', 'translatesense' ) }</option>
            <option value="name_desc">{ __( 'Name (Z to A)', 'translatesense' ) }</option>
            <option value="fields_desc">{ __( 'Fields (High to Low)', 'translatesense' ) }</option>
            <option value="fields_asc">{ __( 'Fields (Low to High)', 'translatesense' ) }</option>
            <option value="status">{ __( 'Status (Supported first)', 'translatesense' ) }</option>
          </select>
        </div>

        {/* Row 2: View Switcher (Left) + Actions (Right) */}
        <div className="ts-filter-row-bottom">
          <div className="ts-view-switcher-group">
            <div className="ts-view-switcher">
              <button
                type="button"
                className={`ts-view-btn ${viewMode === 'grid' ? 'active' : ''}`}
                onClick={() => setViewMode('grid')}
              >
                { __( 'Grid', 'translatesense' ) }
              </button>
              <button
                type="button"
                className={`ts-view-btn ${viewMode === 'list' ? 'active' : ''}`}
                onClick={() => setViewMode('list')}
              >
                { __( 'List', 'translatesense' ) }
              </button>
            </div>
            {isFiltered && (
              <button
                type="button"
                className="ts-reset-filter-btn"
                onClick={handleResetFilters}
              >
                { __( 'Reset', 'translatesense' ) }
              </button>
            )}
          </div>

          <div className="ts-filter-actions-right">
            <button
              type="button"
              className="ts-btn-action-primary"
              disabled={isCreatingSandbox}
              onClick={() => onGenerateSandbox && onGenerateSandbox(activeTab)}
            >
              {isCreatingSandbox ? __( 'Opening...', 'translatesense' ) : sprintf( /* translators: %s: Builder name. */ __( 'Generate %s Config', 'translatesense' ), builderName )}
            </button>
            <button
              type="button"
              className="ts-btn-action-outline"
              onClick={() => onOpenXmlModal && onOpenXmlModal(activeTab, null)}
            >
              <span style={{ display: 'inline-flex', marginRight: '6px' }}>
                {DashboardIcons.pencil({ size: 13 })}
              </span>
              {sprintf( /* translators: %s: Builder name. */ __( 'Edit %s Config', 'translatesense' ), builderName )}
            </button>
          </div>
        </div>
      </div>

      {/* Grid or Table List */}
      {viewMode === 'grid' ? (
        <div>
          <div className="ts-widget-grid">
            {paginatedWidgets.length > 0 ? (
              paginatedWidgets.map((widget) => (
                <WidgetCard
                  key={widget.id}
                  widget={widget}
                  activeTab={activeTab}
                  onEdit={(w) => onOpenXmlModal && onOpenXmlModal(activeTab, w)}
                  onGenerate={(w) => onGenerateSandbox && onGenerateSandbox(activeTab, w.id)}
                />
              ))
            ) : (
              <div className="ts-empty-state" style={{ gridColumn: '1 / -1', padding: '40px 20px', textAlign: 'center' }}>
                <p>{sprintf(
                  /* translators: %s: Name of the item type, such as widgets or blocks. */
                  __( 'No matching %s found for the selected filters.', 'translatesense' ),
                  unitName
                )}</p>
              </div>
            )}
          </div>
          <PaginationBar
            currentPage={validCurrentPage}
            totalPages={totalPages}
            totalItems={totalItems}
            pageSize={pageSize}
            activeTab={activeTab}
            onPageChange={setCurrentPage}
          />
        </div>
      ) : (
        <div className="ts-table-container">
          <table className="ts-list-table">
            <thead>
              <tr>
                <th style={{ width: '28%' }}>{builderName.toUpperCase()} {unitName.toUpperCase()}</th>
                <th style={{ width: '15%' }}>{ __( 'CATEGORY', 'translatesense' ) }</th>
                <th style={{ width: '28%' }}>{ __( 'MAPPED FIELDS', 'translatesense' ) }</th>
                <th style={{ width: '14%' }}>{ __( 'STATUS', 'translatesense' ) }</th>
                <th style={{ width: '15%', textAlign: 'right' }}>{ __( 'ACTIONS', 'translatesense' ) }</th>
              </tr>
            </thead>
            <tbody>
              {paginatedWidgets.length > 0 ? (
                paginatedWidgets.map((widget) => (
                  <WidgetTableRow
                    key={widget.id}
                    widget={widget}
                    onEdit={(w) => onOpenXmlModal && onOpenXmlModal(activeTab, w)}
                    onGenerate={(w) => onGenerateSandbox && onGenerateSandbox(activeTab, w.id)}
                  />
                ))
              ) : (
                <tr>
                  <td colSpan={5} style={{ textAlign: 'center', padding: '30px' }}>
                    {sprintf(
                      /* translators: %s: Name of the item type, such as widgets or blocks. */
                      __( 'No matching %s found.', 'translatesense' ),
                      unitName
                    )}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
          <PaginationBar
            currentPage={validCurrentPage}
            totalPages={totalPages}
            totalItems={totalItems}
            pageSize={pageSize}
            activeTab={activeTab}
            onPageChange={setCurrentPage}
          />
        </div>
      )}
    </div>
  );
}
