/**
 * HomeGuidePanel Component - Overview / Home Dashboard View
 */

import { TranslationGuide } from './TranslationGuide';
import { DashboardIcons } from './DashboardIcons';
import { __, sprintf } from '@wordpress/i18n';

export function HomeGuidePanel({
  onNavigateTab,
  activeBuilders = {},
  languages = {},
  polylangActive = true,
  bulkTranslateUrl = 'edit.php',
}) {

  const isWelcomeRedrect = new URLSearchParams(window.location.search).get('welcome')

  return (
    <div className="ts-guide-container">
      {/* Hero Welcome Card */}
      {isWelcomeRedrect && isWelcomeRedrect === '1' &&
        <div className="ts-guide-hero">
          <div className="ts-guide-hero-content">
            <div className="ts-guide-hero-badge-wrap">
              <span className="ts-guide-pill primary">{ __( 'Documentation & Workflow Guide', 'translatesense' ) }</span>
              <span className={`ts-guide-pill ${polylangActive ? 'success' : 'warning'}`}>
                {polylangActive
                  ? sprintf( /* translators: %s: Dynamic value. */ __( 'Polylang Connected (%s Languages)', 'translatesense' ), Object.keys(languages).length )
                  : __( 'Polylang Inactive', 'translatesense' )}
              </span>
            </div>

            <h2 className="ts-guide-hero-title">{ __( 'Welcome to TranslateSense 👋', 'translatesense' ) }</h2>
            <p className="ts-guide-hero-desc">
              { __( 'TranslateSense is a high-performance automated translation suite built for WordPress & Polylang. It preserves your exact layout and styling across Gutenberg, Classic Editor & Elementor while utilizing a 4-tier database cache to eliminate duplicate AI token costs.', 'translatesense' ) }
            </p>
          </div>
        </div>
      }

      {/* Interactive Step-by-Step Translation Guide */}
      <TranslationGuide />

      {/* Pro Tips & Architecture Card */}
      <div className="ts-guide-tips-card">
        <div className="ts-guide-tips-header">
          <span className="ts-tips-icon">{DashboardIcons.bolt()}</span>
          <h3 className="ts-tips-title">{ __( 'TranslateSense Architecture & Token Savings', 'translatesense' ) }</h3>
        </div>
        <div className="ts-guide-tips-grid">
          <div className="ts-tip-box">
            <h4>{ __( 'Database Translation Memory', 'translatesense' ) }</h4>
            <p>
              { __( 'Every unique sentence is hashed (MD5) and cached in your MySQL database. If the same paragraph appears across 50 posts, AI is called only once, saving thousands of tokens.', 'translatesense' ) }
            </p>
          </div>
          <div className="ts-tip-box">
            <h4>{ __( 'Structural Tag Protection', 'translatesense' ) }</h4>
            <p>
              { __( 'HTML tags, block comments, and shortcodes are shielded before translation, preventing AI from breaking your styling, links, or Elementor widgets.', 'translatesense' ) }
            </p>
          </div>
          <div className="ts-tip-box">
            <h4>{ __( 'Cache & Memory Management', 'translatesense' ) }</h4>
            <p>
              { __( 'Use the Cache tab to inspect and clean your translation memory. Note that removing translation cache means repeated text will be sent to AI again.', 'translatesense' ) }
            </p>
          </div>
        </div>
      </div>

      {/* Quick Action Navigation Buttons */}
      <div className="ts-guide-quick-actions">
        <button
          type="button"
          className="ts-btn ts-btn-secondary"
          onClick={() => onNavigateTab('settings')}
        >
          { __( 'Configure AI Settings', 'translatesense' ) }
        </button>

        {activeBuilders.gutenberg && (
          <button
            type="button"
            className="ts-btn ts-btn-secondary"
            onClick={() => onNavigateTab('gutenberg')}
          >
            Gutenberg Blocks Config
          </button>
        )}

        <button
          type="button"
          className="ts-btn ts-btn-secondary"
          onClick={() => onNavigateTab('cache')}
        >
          <span style={{ display: 'inline-flex', marginRight: '6px' }}>
            {DashboardIcons.database({ size: 14 })}
          </span>
          { __( 'Manage Cache & Memory', 'translatesense' ) }
        </button>

        <a href={bulkTranslateUrl} className="ts-btn ts-btn-secondary">
          { __( 'Go to Posts (Bulk Translate) ↗', 'translatesense' ) }
        </a>
      </div>
    </div>
  );
}

