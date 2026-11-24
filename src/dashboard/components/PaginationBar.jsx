import { __, sprintf } from '@wordpress/i18n';
/**
 * PaginationBar Component - Controls for Gutenberg Blocks & Elementor Widgets
 */

export function PaginationBar({ currentPage, totalPages, totalItems, pageSize, activeTab, onPageChange }) {
  if (totalItems <= 0) return null;

  const startIdx = (currentPage - 1) * pageSize + 1;
  const endIdx = Math.min(currentPage * pageSize, totalItems);
  const unit = activeTab === 'gutenberg' ? 'blocks' : 'widgets';

  const getRange = () => {
    if (totalPages <= 7) {
      return Array.from({ length: totalPages }, (_, i) => i + 1);
    }
    if (currentPage <= 4) {
      return [1, 2, 3, 4, 5, '...', totalPages];
    }
    if (currentPage >= totalPages - 3) {
      return [1, '...', totalPages - 4, totalPages - 3, totalPages - 2, totalPages - 1, totalPages];
    }
    return [1, '...', currentPage - 1, currentPage, currentPage + 1, '...', totalPages];
  };

  const pages = getRange();

  return (
    <div className="ts-pagination-bar">
      <div className="ts-pagination-info">
        {sprintf( /* translators: %1$s: Start idx, %2$s: End idx, %3$s: Total items, %4$s: Unit. */ __( 'Showing %1$s–%2$s of %3$s %4$s', 'translatesense' ), startIdx, endIdx, totalItems, unit )}
      </div>
      <div className="ts-pagination-controls">
        <button
          type="button"
          className="ts-page-nav-btn"
          disabled={currentPage <= 1}
          onClick={() => onPageChange(currentPage - 1)}
        >
          { __( '< Prev', 'translatesense' ) }
        </button>
        {pages.map((p, idx) =>
          p === '...' ? (
            <span key={`dots-${idx}`} className="ts-page-dots">
              ...
            </span>
          ) : (
            <button
              key={p}
              type="button"
              className={`ts-page-num-btn ${currentPage === p ? 'active' : ''}`}
              onClick={() => onPageChange(p)}
            >
              {p}
            </button>
          )
        )}
        <button
          type="button"
          className="ts-page-nav-btn"
          disabled={currentPage >= totalPages}
          onClick={() => onPageChange(currentPage + 1)}
        >
          { __( 'Next >', 'translatesense' ) }
        </button>
      </div>
    </div>
  );
}

