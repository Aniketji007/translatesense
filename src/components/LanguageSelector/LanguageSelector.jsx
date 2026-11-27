/**
 * LanguageSelector Component
 */

import { getLanguageDisplay, getLanguageFlag } from '../../utils/language';
import { __, sprintf } from '@wordpress/i18n';

export function LanguageSelector({
  languages = [],
  selectedLanguages = [],
  onChange,
  className = '',
}) {
  const handleToggle = (slug) => {
    if (!onChange) return;
    const isSelected = selectedLanguages.includes(slug);
    if (isSelected) {
      onChange(selectedLanguages.filter((s) => s !== slug));
    } else {
      onChange([...selectedLanguages, slug]);
    }
  };

  const handleSelectAll = () => {
    if (!onChange) return;
    const defaultLang =
      (typeof window !== 'undefined' &&
        (window.TranslateSenseBulkData?.defaultLanguage ||
          window.TranslateSenseEditorData?.defaultLanguage)) ||
      '';
    const nonDefaultLangs = languages.filter(
      (l) => !l.is_default && l.slug !== defaultLang
    );
    onChange(
      nonDefaultLangs.length > 0
        ? nonDefaultLangs.map((l) => l.slug)
        : languages.map((l) => l.slug)
    );
  };

  const handleUnselectAll = () => {
    if (!onChange) return;
    onChange([]);
  };

  const isGridCols3 = languages.length > 6;

  return (
    <>
      <div className="ts-lang-actions">
        <div className="ts-lang-btn-group">
          <button
            type="button"
            className="ts-lang-action-btn"
            onClick={handleSelectAll}
          >
            { __( 'Select All', 'translatesense' ) }
          </button>
          <button
            type="button"
            className="ts-lang-action-btn"
            onClick={handleUnselectAll}
          >
            { __( 'Unselect All', 'translatesense' ) }
          </button>
        </div>
        <div className="ts-lang-counter">
          {sprintf(
                  /* translators: 1: Number of selected languages, 2: Total number of languages. */
                  __( '%1$s of %2$s selected', 'translatesense' ),
                  selectedLanguages.length,
                  languages.length
                )}
        </div>
      </div>

      <div className={`ts-lang-grid ${isGridCols3 ? 'cols-3' : 'cols-2'}`}>
        {languages.map((lang) => {
          const isChecked = selectedLanguages.includes(lang.slug);
          const flagInfo = getLanguageFlag(lang, languages);

          return (
            <div
              key={lang.slug}
              className={`ts-lang-card ${isChecked ? 'selected' : ''}`}
              onClick={() => handleToggle(lang.slug)}
            >
              <input
                type="checkbox"
                id={`ts-lang-${lang.slug}`}
                name="ts_target_langs[]"
                className="ts-lang-checkbox"
                value={lang.slug}
                checked={isChecked}
                onChange={() => { }} // Controlled via card click
              />
              {flagInfo.hasFlag ? (
                <img src={flagInfo.flagUrl} alt="" className="ts-lang-flag-img" />
              ) : null}
              <span className="ts-lang-name" title={lang.name}>
                {lang.name}
              </span>
              <span className="ts-lang-code">{getLanguageDisplay(lang, languages)}</span>
            </div>
          );
        })}
      </div>
    </>
  );
}

