/**
 * RightSidebar Component - Translation Overview & Dragwyb Ecosystem
 */

import { DashboardIcons } from './DashboardIcons';
import { formatMs } from '../../utils/formatting';
import { __ } from '@wordpress/i18n';

export function RightSidebar({ stats = {}, docsUrl = 'https://docs.dragwyb.com/' }) {
  const totalPosts = stats.successful_translations ? Number(stats.successful_translations).toLocaleString() : 0;
  const totalStrings = stats.total_strings
    ? (stats.total_strings >= 1000 ? (stats.total_strings / 1000).toFixed(1) + 'K' : stats.total_strings)
    : 0;
  const totalCharacters = stats.total_characters
    ? (stats.total_characters >= 1000 ? (stats.total_characters / 1000).toFixed(1) + 'K' : stats.total_characters)
    : 0;
  const timeTaken = formatMs(stats.time_take) || '';
  const cachedCount = stats.cached_results ? `${Number(stats.cached_results).toLocaleString()} Strings` : 0;
  const packagesCount = stats.total_packages ? Number(stats.total_packages).toLocaleString() : 0;
  const providers = stats.providers && Object.keys(stats.providers).length > 0 ? Object.keys(stats.providers) : [];

  const adminUrl =
    (typeof window !== 'undefined' && window.TranslateSenseData && window.TranslateSenseData.adminUrl) ||
    '';

  const getPluginUrl = (name) => {
    if (!adminUrl) return '#';
    return `${adminUrl}plugin-install.php?tab=plugin-information&plugin=${name}&TB_iframe=true&width=772&height=480`;
  };

  const providerBadge = (name) => {
    let badge = {};
    switch (name) {
      case 'chrome_ai':
        badge.name = 'Chrome Built-in';
        badge.class = 'red';
        break;
      case 'gemini':
        badge.name = 'Gemini';
        badge.class = 'blue';
        break;
      case 'openai':
        badge.name = 'OpenAI';
        badge.class = 'green';
        break;
    }

    return badge;
  }

  return (
    <aside className="ts-sidebar" aria-label={ __( 'Dashboard Sidebar', 'translatesense' ) }>
      {/* Card 1: Translation Overview */}
      {stats && Object.keys(stats).length > 0 &&
        <div className="ts-sidebar-card">
          <h3 className="ts-sidebar-title">
            <span className="ts-title-icon">{DashboardIcons.chart()}</span>
            { __( 'Translation Overview', 'translatesense' ) }
          </h3>

          <div className="ts-overview-grid">
            <div className="ts-overview-metric">
              <div className="ts-metric-icon-box">{DashboardIcons.document()}</div>
              <div className="ts-metric-details">
                <div className="ts-metric-num">{totalPosts}</div>
                <div className="ts-metric-sub">{ __( 'Total Posts', 'translatesense' ) }</div>
              </div>
            </div>

            <div className="ts-overview-metric">
              <div className="ts-metric-icon-box">&lt;/&gt;</div>
              <div className="ts-metric-details">
                <div className="ts-metric-num">{totalStrings}</div>
                <div className="ts-metric-sub">{ __( 'Strings', 'translatesense' ) }</div>
              </div>
            </div>

            <div className="ts-overview-metric">
              <div className="ts-metric-icon-box">A</div>
              <div className="ts-metric-details">
                <div className="ts-metric-num">{totalCharacters}</div>
                <div className="ts-metric-sub">{ __( 'Characters', 'translatesense' ) }</div>
              </div>
            </div>

            <div className="ts-overview-metric">
              <div className="ts-metric-icon-box">{DashboardIcons.clock()}</div>
              <div className="ts-metric-details">
                <div className="ts-metric-num">{timeTaken}</div>
                <div className="ts-metric-sub">{ __( 'Time Taken', 'translatesense' ) }</div>
              </div>
            </div>
          </div>

          <div className="ts-sidebar-divider" />

          <div className="ts-overview-subgrid">
            <div className="ts-subgrid-col">
              <div className="ts-subgrid-header">
                <span className="ts-subgrid-icon">{DashboardIcons.layers()}</span>
                <span>{cachedCount}</span>
              </div>
              <div className="ts-subgrid-lbl">{ __( 'Translation Cache', 'translatesense' ) }</div>
            </div>

            <div className="ts-subgrid-col">
              <div className="ts-subgrid-header">
                <span className="ts-subgrid-icon">{DashboardIcons.package()}</span>
                <span>{packagesCount}</span>
              </div>
              <div className="ts-subgrid-lbl">{ __( 'Active Packages', 'translatesense' ) }</div>
            </div>
          </div>

          <div className="ts-sidebar-divider" />
          {providers && providers.length > 0 &&
            <div className="ts-provider-pills">
              {providers.map(provider => {
                const providerData = providerBadge(provider);
                return (
                  <div className="ts-provider-pill">
                    <span className={`ts-dot ${providerData.class}`} />
                    {providerData.name}
                  </div>
                )
              })}
            </div>
          }
        </div>
      }

      {/* Card 2: More by Dragwyb */}
      <div className="ts-sidebar-card">
        <h3 className="ts-sidebar-title">
          <span className="ts-title-icon">{DashboardIcons.spark()}</span>
          { __( 'More by Dragwyb', 'translatesense' ) }
        </h3>

        <div className="ts-dragwyb-list">
          <a
            href={getPluginUrl('dragwyb-ai-agent-workflows')}
            target="_blank"
            rel="noopener noreferrer"
            className="ts-dragwyb-item thickbox open-plugin-details-modal"
            title={ __( 'Automate WordPress workflows with AI agents.', 'translatesense' ) }
          >
            <div className="ts-dragwyb-icon"><img src={docsUrl + 'wp-content/uploads/2026/10/ai-agent-workflow.png'} alt={ __( 'AI Agent Workflows', 'translatesense' ) } /></div>
            <div className="ts-dragwyb-info">
              <div className="ts-dragwyb-title">{ __( 'AI Agent Workflows', 'translatesense' ) }</div>
              <div className="ts-dragwyb-desc">{ __( 'Automate WordPress workflows with AI agents.', 'translatesense' ) }</div>
            </div>
            <span className="ts-dragwyb-arrow">›</span>
          </a>

          <a
            href={getPluginUrl('smart-form-builder-by-dragwyb')}
            target="_blank"
            rel="noopener noreferrer"
            className="ts-dragwyb-item thickbox open-plugin-details-modal"
            title={ __( 'Advanced contact forms and lead generation.', 'translatesense' ) }
          >
            <div className="ts-dragwyb-icon"><img src={docsUrl + 'wp-content/uploads/2026/10/form-builder.png'} alt={ __( 'Smart Form Builder', 'translatesense' ) } /></div>
            <div className="ts-dragwyb-info">
              <div className="ts-dragwyb-title">{ __( 'Smart Form Builder', 'translatesense' ) }</div>
              <div className="ts-dragwyb-desc">{ __( 'Advanced contact forms and lead generation.', 'translatesense' ) }</div>
            </div>
            <span className="ts-dragwyb-arrow">›</span>
          </a>

          <a
            href={getPluginUrl('dragwyb-click-to-chat')}
            target="_blank"
            rel="noopener noreferrer"
            className="ts-dragwyb-item thickbox open-plugin-details-modal"
            title={ __( 'AI chatbot and customer support.', 'translatesense' ) }
          >
            <div className="ts-dragwyb-icon"><img src={docsUrl + 'wp-content/uploads/2026/10/ai-chatbot.png'} alt={ __( 'AI Chatbot', 'translatesense' ) } /></div>
            <div className="ts-dragwyb-info">
              <div className="ts-dragwyb-title">{ __( 'Click to Chat', 'translatesense' ) }</div>
              <div className="ts-dragwyb-desc">{ __( 'AI chatbot and customer support.', 'translatesense' ) }</div>
            </div>
            <span className="ts-dragwyb-arrow">›</span>
          </a>
        </div>
      </div>
    </aside>
  );
}

