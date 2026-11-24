import { __ } from '@wordpress/i18n';
/**
 * TopHeader Component - Modern SaaS Header Bar
 */

export function TopHeader({
  docsUrl = 'https://docs.dragwyb.com/',
  supportUrl = 'https://dragwyb.com/support/',
  pluginUrl = '',
}) {
  const logoSrc = pluginUrl ? `${pluginUrl}assets/img/logo.png` : 'assets/img/logo.png';

  return (
    <header className="ts-top-header">
      <div className="ts-top-brand">
        <div className="ts-brand-logo-wrap">
          <img
            src={logoSrc}
            alt={ __( 'TranslateSense Logo', 'translatesense' ) }
            onError={(e) => {
              e.target.style.display = 'none';
            }}
          />
        </div>
        <h1 className="ts-brand-title">{ __( 'TranslateSense', 'translatesense' ) }</h1>
      </div>
      <div className="ts-top-actions">
        <a
          href={docsUrl}
          target="_blank"
          rel="noopener noreferrer"
          className="ts-header-action-btn"
        >
          <span className="ts-header-btn-text">{ __( 'Documentation', 'translatesense' ) }</span>
          <span className="ts-external-arrow">↗</span>
        </a>
        <a
          href={supportUrl}
          target="_blank"
          rel="noopener noreferrer"
          className="ts-header-action-btn"
        >
          <span className="ts-header-btn-text">{ __( 'Support', 'translatesense' ) }</span>
          <span className="ts-external-arrow">↗</span>
        </a>
      </div>
    </header>
  );
}

