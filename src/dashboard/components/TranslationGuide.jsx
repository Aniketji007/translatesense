/**
 * TranslationGuide Component - Interactive Step-by-Step Translation Walkthrough
 */

import { useState } from '@wordpress/element';
import { GuideMethodSelector } from './GuideMethodSelector';
import { GuideStep } from './GuideStep';
import { __, sprintf } from '@wordpress/i18n';

export const GUIDES_DATA = {
  gutenberg: {
    id: 'gutenberg',
    title: __( 'Gutenberg Block Editor Translation', 'translatesense' ),
    description: __( 'Follow these simple steps to translate your Gutenberg posts and pages while preserving all blocks and formatting.', 'translatesense' ),
    importantNote: __( 'The generated translation is not applied to the page until you click Apply To Page.', 'translatesense' ),
    steps: [
      {
        number: 1,
        title: __( 'Open Language Creation', 'translatesense' ),
        description: __( 'Navigate to Pages → All Pages and click the + (Add Language) icon in the page row.', 'translatesense' ),
        screenshot: __( 'Pages list showing the + (Add Language) icon in Polylang columns', 'translatesense' ),
      },
      {
        number: 2,
        title: __( 'Automatic Translation Popup', 'translatesense' ),
        description: __( 'Continue using the Automatic Translation – Translate Page Content popup by clicking Continue to Translate.', 'translatesense' ),
        screenshot: __( 'Automatic Translation dialog with Continue to Translate button', 'translatesense' ),
      },
      {
        number: 3,
        title: __( 'Open Page in Gutenberg', 'translatesense' ),
        description: __( 'If the popup does not appear or was closed, open the translated page in Gutenberg.', 'translatesense' ),
      },
      {
        number: 4,
        title: sprintf( /* translators: %s: plugin name. */ __( 'Locate %s Metabox', 'translatesense' ), 'TranslateSense' ),
        description: sprintf( /* translators: %s: plugin name. */ __( 'Locate the %s metabox in the right sidebar, above the Polylang language metabox.', 'translatesense' ), 'TranslateSense' ),
        screenshot: sprintf( /* translators: %s: plugin name. */ __( 'Right editor sidebar with %s metabox above Polylang', 'translatesense' ), 'TranslateSense' ),
      },
      {
        number: 5,
        title: __( 'Click Auto Translate', 'translatesense' ),
        description: __( 'Click Auto Translate to initialize content block extraction.', 'translatesense' ),
      },
      {
        number: 6,
        title: __( 'Select AI Provider & Start', 'translatesense' ),
        description: __( 'Select an AI provider and click Start Translation.', 'translatesense' ),
      },
      {
        number: 7,
        title: __( 'Wait for Translation', 'translatesense' ),
        description: __( 'Wait for translation to finish. Translatable content is mapped and cached strings are reused.', 'translatesense' ),
      },
      {
        number: 8,
        title: __( 'Apply Generated Translation', 'translatesense' ),
        description: __( 'Click Apply To Page to apply the generated translation.', 'translatesense' ),
        note: __( 'The generated translation is not applied to the page until you click Apply To Page.', 'translatesense' ),
      },
    ],
  },
  classic: {
    id: 'classic',
    title: __( 'Classic Editor Translation', 'translatesense' ),
    description: __( 'Translate traditional WordPress content, WooCommerce descriptions, and custom post types using the Classic Editor.', 'translatesense' ),
    importantNote: __( 'Classic Editor support includes WooCommerce content and supported custom post types.', 'translatesense' ),
    steps: [
      {
        number: 1,
        title: __( 'Open Language Creation', 'translatesense' ),
        description: __( 'Navigate to the Pages, Products, or relevant custom post type listing where the content you want to translate is located, then click the + (Add Language) icon in the Polylang columns.', 'translatesense' ),
        screenshot: __( 'WordPress content list showing the + (Add Language) icon in Polylang columns', 'translatesense' ),
      },
      {
        number: 2,
        title: __( 'Automatic Translation Popup', 'translatesense' ),
        description: __( 'Continue using the Automatic Translation popup by clicking Continue to Translate.', 'translatesense' ),
        screenshot: __( 'Automatic Translation dialog with Continue to Translate button', 'translatesense' ),
      },
      {
        number: 3,
        title: __( 'Open Content in Classic Editor', 'translatesense' ),
        description: __( 'If the popup does not appear or was closed, open the translated content in the Classic Editor.', 'translatesense' ),
      },
      {
        number: 4,
        title: __( 'Locate Auto Translate Button', 'translatesense' ),
        description: sprintf( /* translators: %s: plugin name. */ __( 'Find the %s Auto Translate button above the Add Media button in the WYSIWYG editor.', 'translatesense' ), 'TranslateSense'),
        screenshot: __( 'Classic Editor showing the Auto Translate button above Add Media', 'translatesense' ),
      },
      {
        number: 5,
        title: __( 'Click Auto Translate', 'translatesense' ),
        description: __( 'Click Auto Translate to initialize content extraction.', 'translatesense' ),
      },
      {
        number: 6,
        title: __( 'Select AI Provider & Start', 'translatesense' ),
        description: __( 'Select an AI provider and click Start Translation.', 'translatesense' ),
      },
      {
        number: 7,
        title: __( 'Wait for Translation', 'translatesense' ),
        description: __( 'Wait for translation to finish. Translatable content is mapped and cached strings are reused.', 'translatesense' ),
      },
      {
        number: 8,
        title: __( 'Apply Generated Translation', 'translatesense' ),
        description: __( 'Click Apply To Page to apply the generated translation.', 'translatesense' ),
        note: __( 'The generated translation is not applied to the page until you click Apply To Page.', 'translatesense' ),
      },
    ],
  },
  elementor: {
    id: 'elementor',
    title: __( 'Elementor Page Builder Translation', 'translatesense' ),
    description: __( 'Translate visual Elementor layouts, custom widgets, and styled sections seamlessly without losing design hierarchy.', 'translatesense' ),
    importantNote: __( 'The generated translation is not applied to the page until you click Apply To Page.', 'translatesense' ),
    steps: [
      {
        number: 1,
        title: __( 'Navigate to Pages', 'translatesense' ),
        description: __( 'Navigate to Pages → All Pages and click + (Add Language).', 'translatesense' ),
      },
      {
        number: 2,
        title: __( 'Continue via Popup', 'translatesense' ),
        description: __( 'Continue using the initial automatic translation popup.', 'translatesense' ),
      },
      {
        number: 3,
        title: __( 'Open Page in Elementor', 'translatesense' ),
        description: __( 'If the popup does not appear or was closed, open page in Elementor Editor.', 'translatesense' ),
      },
      {
        number: 4,
        title: __( 'Locate Header Button', 'translatesense' ),
        description: __( 'Locate Auto Translate in the Elementor editor header.', 'translatesense' ),
        screenshot: sprintf( /* translators: %s: plugin name. */ __( 'Elementor header panel with %s Auto Translate button', 'translatesense' ), 'TranslateSense'),
      },
      {
        number: 5,
        title: __( 'Click Auto Translate', 'translatesense' ),
        description: __( 'Click Auto Translate.', 'translatesense' ),
      },
      {
        number: 6,
        title: __( 'Select AI Provider & Start', 'translatesense' ),
        description: __( 'Select an AI provider and click Start Translation.', 'translatesense' ),
      },
      {
        number: 7,
        title: __( 'Wait for Translation', 'translatesense' ),
        description: __( 'Wait for translation to finish.', 'translatesense' ),
      },
      {
        number: 8,
        title: __( 'Apply Generated Translation', 'translatesense' ),
        description: __( 'Click Apply To Page.', 'translatesense' ),
        note: __( 'The generated translation is not applied to the page until you click Apply To Page.', 'translatesense' ),
      },
    ],
  },
  bulk: {
    id: 'bulk',
    title: __( 'Bulk Translation (Batch Queue)', 'translatesense' ),
    description: __( 'Translate multiple posts and pages in a single batch with real-time background progress monitoring.', 'translatesense' ),
    importantNote: __( 'Translations are queued and processed safely in the background. You can monitor live progress and cancel or skip at any time.', 'translatesense' ),
    steps: [
      {
        number: 1,
        title: __( 'Navigate to Content Listing', 'translatesense' ),
        description: __( 'Navigate to Pages → All Pages or Posts → All Posts.', 'translatesense' ),
      },
      {
        number: 2,
        title: __( 'Select Multiple Items', 'translatesense' ),
        description: __( 'Select multiple pages or posts using the table checkboxes.', 'translatesense' ),
      },
      {
        number: 3,
        title: __( 'Click Bulk Translation Button', 'translatesense' ),
        description: __( 'Click Bulk Translation, available above and below the list table after the page filter options.', 'translatesense' ),
        screenshot: __( 'List table navigation showing the Translate button', 'translatesense' ),
      },
      {
        number: 4,
        title: __( 'Select Target Languages', 'translatesense' ),
        description: __( 'Select one or more target languages.', 'translatesense' ),
      },
      {
        number: 5,
        title: __( 'Choose Provider Step', 'translatesense' ),
        description: __( 'Click Next: Choose Provider.', 'translatesense' ),
      },
      {
        number: 6,
        title: __( 'Select AI Provider', 'translatesense' ),
        description: __( 'Select an AI provider in the provider modal.', 'translatesense' ),
      },
      {
        number: 7,
        title: __( 'Start Bulk Translation', 'translatesense' ),
        description: __( 'Click Start Bulk Translation.', 'translatesense' ),
      },
      {
        number: 8,
        title: __( 'Progress Modal Appears', 'translatesense' ),
        description: __( 'The bulk translation progress modal appears.', 'translatesense' ),
      },
      {
        number: 9,
        title: __( 'Monitor Live Progress', 'translatesense' ),
        description: __( 'Monitor live translation progress in the modal.', 'translatesense' ),
      },
      {
        number: 10,
        title: __( 'Review Summary Breakdown', 'translatesense' ),
        description: __( 'Review successful, failed and incomplete translations after completion.', 'translatesense' ),
        note: __( 'Translations are linked in Polylang automatically. Taxonomies, categories, and post meta are synchronized.', 'translatesense' ),
      },
    ],
  },
};

export function TranslationGuide() {
  const [activeMethod, setActiveMethod] = useState('gutenberg');
  const guide = GUIDES_DATA[activeMethod] || GUIDES_DATA.gutenberg;

  return (
    <section className="translatesense-guide" aria-label={ __( 'Translation Workflow Guides', 'translatesense' ) }>
      <div className="ts-guide-section-header">
        <h3 className="ts-guide-main-heading">{ __( 'How to Translate', 'translatesense' ) }</h3>
        <p className="ts-guide-main-desc">
          { __( 'Choose a translation method to view its step-by-step guide.', 'translatesense' ) }
        </p>
      </div>

      <GuideMethodSelector activeTab={activeMethod} onChange={setActiveMethod} />

      <div
        id={`ts-guide-panel-${guide.id}`}
        role="region"
        aria-labelledby={`ts-method-card-${guide.id}`}
        className="ts-guide-panel"
        tabIndex={0}
      >
        <div className="ts-guide-panel-header">
          <h4 className="ts-guide-panel-title">{guide.title}</h4>
          <p className="ts-guide-panel-desc">{guide.description}</p>
        </div>

        <div className="ts-timeline-container">
          {guide.steps.map((step) => (
            <GuideStep
              key={step.number}
              number={step.number}
              title={step.title}
              description={step.description}
              screenshot={step.screenshot}
              note={step.note}
            />
          ))}
        </div>

        {guide.importantNote && (
          <div className="ts-guide-footer-note">
            <span className="ts-footer-note-badge">{ __( 'NOTE:', 'translatesense' ) } </span>
            <span className="ts-footer-note-text">{guide.importantNote}</span>
          </div>
        )}
      </div>
    </section>
  );
}

