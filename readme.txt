=== TranslateSense – AI Translation for Polylang ===
Contributors: dragwyb, alkesh7
Tags: translation, polylang, multilingual, ai translation, automatic translation
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI translation for Polylang : bulk translate WordPress content, WooCommerce products, media, taxonomies, and custom fields.

== Description ==

TranslateSense is an **AI translation plugin for Polylang**. It helps you translate multilingual website content using AI and works with polylang by translating WordPress posts, pages, custom post types, WooCommerce products, taxonomy terms, media, and custom fields into multiple languages with.

Use TranslateSense with OpenAI, Google Gemini, or supported browser-based AI. Select one item for editor-based translation or use **Bulk Translation** to translate multiple posts, pages, products, and taxonomy terms into one or more languages.

Translate individual content directly in **Gutenberg, Elementor, or Classic Editor**. Review, search, edit, reset, and retry translated strings before applying them to your content.

TranslateSense also provides **WooCommerce product translation** for slug, title, description, excerpt, custom fields, attributes, variations, purchase notes, downloadable file names, featured images, and product galleries. Synchronize supported upsells, cross-sells, and grouped product relationships when translating products.

== Resources ==
📖 [Documentation](https://docs.dragwyb.com/ai-automatic-translation-addon-for-polylang/?utm_source=wpplugin&utm_medium=readme&utm_campaign=translatesense) | ✨ [TranslateSense](https://dragwyb.com/product/ai-automatic-translation-addon-for-polylang/?utm_source=wpplugin&utm_medium=readme&utm_campaign=translatesense)

== Key features ==

* **Polylang Integration:** Manage multilingual content with Polylang and connect translated posts, pages, products, and taxonomy terms.
* **AI Translation:** Translate WordPress content using OpenAI, Google Gemini, Built-in Browser AI, or supported WordPress AI Connectors.
* **Bulk Translation:** Translate multiple WordPress posts, pages, custom post types, WooCommerce products, and taxonomy terms into one or more languages.
* **Background Translation:** Process supported OpenAI and Gemini post translation batches through WP-Cron, monitor progress, and retry failed items.
* **WooCommerce Product Translation:** Translate supported product titles, slugs, descriptions, short descriptions, global and product attributes, variations, purchase notes, and downloadable file names.
* **WooCommerce Image Translation:** Translate supported product featured images and gallery images as part of product translation workflows.
* **Product Relationship Synchronization:** Keep supported upsells, cross-sells, and grouped product relationships connected in translated products.
* **Media Library Translation:** Select and bulk translate supported media items into target languages.
* **Automatic Media Translation:** Translate supported media during applicable post and WooCommerce product translation workflows.
* **Automatic Taxonomy Translation:** Translate categories, tags, and supported custom taxonomy terms, including terms linked to translated posts and products.
* **Gutenberg, Elementor, and Classic Editor:** Translate supported blocks, widgets, titles, slugs, excerpts, and content while preserving supported content structures.
* **ACF and Custom Fields:** Translate supported Advanced Custom Fields, including Repeater, Flexible Content, Group, and supported relationship and media fields.
* **SEO Translation:** Translate supported SEO titles, meta descriptions, and metadata from Yoast SEO, Rank Math, and SEOPress.
* **Internal Link Translation:** Update supported internal links and media references to their available translated versions.
* **Translation Memory:** Reuse saved translations to reduce repeated AI requests and help maintain translation consistency.
* **XML Configuration:** Customize translation rules for supported Gutenberg blocks, Elementor widgets, and custom fields.

TranslateSense works with Polylang for language management and translated content relationships. The availability of individual fields and integrations depends on the active plugins, configured fields, and supported translation mappings.

== AI Translation Providers ==

TranslateSense supports the following AI translation options:

* **OpenAI**
* **Google Gemini**
* **Built-in Browser AI** (supported Google Chrome and Microsoft Edge browsers)
* **WordPress AI Connectors** (WordPress 7.0+)

== Supported Editors and Integrations ==

= Gutenberg =

* Supports WordPress Core blocks and supported third-party blocks.
* Includes preconfigured rules for supported blocks and integrations, with fallback extraction for supported unmapped block content.
* Supports nested block content and relevant block attributes.
* Includes block recovery tools for supported missing or default block attributes.
* Supports custom extraction rules through `wpml-config.xml`.

= Elementor =

* Supports Elementor core widgets and supported widget content.
* Supports nested repeater content and supported Elementor controls.
* Includes support for Elementor v4 atomic controls and page settings where supported.
* Provides schema discovery for unmapped widgets and custom XML rules.
* Includes cross-editor protection to help avoid unintended changes to content from other editors.

= Classic Editor =

* Supports post titles, slugs, excerpts, and content.
* Extracts supported HTML content and accessibility or interface attributes while preserving markup.

= Advanced Custom Fields (ACF) =

Supports common ACF field types, including Text, Textarea, WYSIWYG, Select, Checkbox, Radio, Button Group, Link titles, Repeater, Flexible Content, and Group. Supported relationship and media fields include Image, File, Gallery, Relationship, Post Object, Page Link, and Taxonomy, with translated item mapping where supported.

= SEO Plugins =

Translate supported SEO metadata from:

* Yoast SEO
* Rank Math
* SEOPress

= WooCommerce =

Translate supported WooCommerce product data, including:

* Product titles, slugs, descriptions, and short descriptions.
* Product attributes, including global product attributes.
* Product variations and supported variation content.
* Purchase notes and downloadable file names.
* Featured images and product gallery images.
* Upsells, cross-sells, and grouped product relationships.

Media and product relationship synchronization depends on the related content being available and supported by the connected translation setup.

== Translation Tools ==

= Bulk Translation =

* Translate posts, pages, custom post types, WooCommerce products, and taxonomy terms.
* Select multiple target languages and process content in the background.
* View translation progress and individual item statuses.
* Retry failed translations.
* Skip source-language content and handle existing translations, including supported trashed or draft translation checks.
* Preserve the configured publishing workflow; source posts that are drafts remain drafts.

= Page Editor Translation =

* Review and edit extracted strings from supported editors.
* Search translated strings and update individual translations.
* Retry or reset translations where supported.
* Apply translated strings to the page when ready. Applying translations does not automatically save the post; save the post in the editor.

= Taxonomy Translation =

* Translate categories, tags, and supported custom taxonomy terms.
* Use the bulk translation workflow on supported taxonomy screens.
* Automatically translate linked taxonomy terms during supported post or product translation when configured and applicable.

= Media Translation =

* Select multiple supported media items in the WordPress Media Library and translate them into target languages.
* Automatically translate supported featured and gallery media during applicable post or product translation workflows.

= Translation Memory =

Reuse stored translations to improve consistency and avoid translating matching strings repeatedly.

= XML Configuration =

Use `wpml-config.xml` to define custom fields, attributes, and extraction rules for supported content structures.

== Plugin Settings ==

Configure the AI provider, API credentials, translation preferences, and supported automatic translation behavior from the TranslateSense settings.

OpenAI and Gemini require your own API credentials. TranslateSense does not include provider API usage in the plugin; charges, quotas, and rate limits are determined by the provider.

== No Paid Upgrade Required ==

The features described in this readme are available without a paid TranslateSense upgrade. TranslateSense does not impose an artificial translation quota. OpenAI and Gemini usage may incur charges from the respective providers, and browser-based translation depends on browser and device support.

== Screenshots ==

1. Bulk Translate Pages into Multiple Languages
2. Monitor Translation Progress in Real Time
3. Automatic AI Translation for WordPress Pages
4. Choose Your Preferred AI Translation Provider
5. Configure AI Providers and Translation Models
6. Customize AI Performance and Translation Prompts
7. Translate Elementor Pages Without Losing Design

== External Services ==

TranslateSense can connect to external services depending on the translation provider and features you enable.

= OpenAI API =

When OpenAI is selected, content submitted for translation is sent to OpenAI's API. This may include post content, titles, excerpts, supported custom fields, and other selected strings. [Terms](https://openai.com/policies/terms-of-use/) | [Privacy](https://openai.com/policies/privacy-policy/)

= Google Gemini API =

When Google Gemini is selected, content submitted for translation is sent to Google's Gemini API. This may include post content, titles, excerpts, supported custom fields, and other selected strings. [Terms](https://ai.google.dev/gemini-api/terms) | [Privacy](https://policies.google.com/privacy)

= Browser AI =

When browser-based AI is selected, translation is performed using the supported browser's built-in translation capability. Text is processed locally by the browser feature and is not sent to the OpenAI or Gemini APIs by TranslateSense for that browser-based translation.

= Dragwyb Feedback Service =

If you choose to submit feedback through the plugin, the feedback information you submit is sent to Dragwyb's feedback service. (https://feedback.dragwyb.com) [Privacy Policy](https://dragwyb.com/privacy-policy/?utm_source=wpplugin&utm_medium=readme&utm_campaign=translatesense) | [Terms](https://dragwyb.com/terms/?utm_source=wpplugin&utm_medium=readme&utm_campaign=translatesense)

== Installation ==

1. Upload the `translatesense` folder to the `/wp-content/plugins/` directory, or install TranslateSense through the WordPress Plugins screen.
2. Activate TranslateSense through the **Plugins** screen in WordPress.
3. Install and activate Polylang.
4. Open TranslateSense settings and choose your preferred translation provider.
5. Add and validate your provider API key if you are using OpenAI or Google Gemini.
6. Configure your translation preferences and start translating supported content.

== Frequently Asked Questions ==

= Are translations free? =
TranslateSense does not impose an artificial translation quota or require a paid upgrade for the features described here. OpenAI and Gemini may charge for API usage, and their own quotas and rate limits apply. Browser-based translation depends on browser and device support.

= Does TranslateSense require Polylang? =
Yes. TranslateSense is an addon for Polylang and requires Polylang to manage languages and translated content.

= Do I need an OpenAI or Gemini API key? =
You need your own API key when using OpenAI or Google Gemini. You can also use supported browser-based AI or WordPress AI Connectors where available.

= How can I bulk translate WordPress posts with Polylang? =
Open a Polylang supported Posts, Pages, Custom Post Type, WooCommerce Product, Media or Taxonomy list, select the items you want to translate, and click **Bulk Translate**. Choose one or more target languages and an AI provider to start translating multiple posts in one workflow.

= Can I translate multiple posts into different languages at once? =
Yes. Bulk Translation lets you select multiple posts, pages, or supported custom post types and choose one or more target languages.

= Does browser-based AI work when I close the tab? =
No. Browser-based translation requires the relevant browser tab to remain open while the translation is running.

= Which AI providers does TranslateSense support? =
TranslateSense supports OpenAI, Google Gemini, and Built-in Browser AI in compatible Google Chrome and Microsoft Edge browsers. WordPress 7.0+ users can also connect supported AI providers through WordPress AI Connectors.

= Which editors are supported? =
TranslateSense supports Gutenberg, Elementor, and the Classic Editor, along with the integrations described in this readme.

= Does TranslateSense translate WooCommerce products? =
Yes. It supports the product slug, title, content, excerpt, custom fields, attributes, variations, media, purchase notes and Downloadable file name Translation With linked upsells/cross-sells/related product relationships listed in the WooCommerce section.

= Does TranslateSense support Advanced Custom Fields (ACF)? =
Yes. TranslateSense supports configured ACF fields, including Repeater, Flexible Content, and Group fields, along with supported relationship and media ID mapping.

= What is Page Editor Translation? =
Page Editor Translation lets you translate individual posts directly inside Gutenberg, Elementor, or Classic Editor. You can review, search, and edit translated strings before applying them to your content.

= Can I review translations before publishing? =
Yes. Page Editor Translation applies the translated content to your editor only after you click **Apply to Page**. You can review and save it yourself. Bulk Translation creates translated posts as Draft by default, unless you choose Publish in the plugin settings.

= Can I translate custom fields? =
Yes. TranslateSense supports the listed ACF field types and custom extraction rules through `wpml-config.xml`.

= How does Translation Memory work? =
TranslateSense saves translated strings and package mappings in your WordPress database. When the same source text is encountered again, it can reuse a saved translation instead of sending another request to the AI provider.

== Special Thanks ==

Special thanks to the team behind [Polylang](https://wordpress.org/plugins/polylang/) for creating and maintaining a great multilingual foundation for WordPress.

== Source Code and Contributions ==

TranslateSense is open source under the GPLv2 or later license. Explore the source code, report issues, or contribute on GitHub:
[GitHub Repository](https://github.com/Dragwyb/translatesense)


== Changelog ==

= 1.0.0 =
* Expanded WooCommerce integration with product attributes, variations, purchase notes, and downloadable file names.
* Added support for global WooCommerce product attributes.
* Added featured image and product gallery image translation for supported products.
* Added synchronization for upsells, cross-sells, and grouped product relationships.
* Added bulk media translation from the WordPress Media Library.
* Added automatic supported media translation during post and product translation.
* Added source-language post skipping with progress reporting.
* Fixed an issue when source posts in draft but translated posts published when the bulk status was set to Publish.
* Fixed Bulk Translate button showing in Trashed posts page.
* Fixed static page not found issue after translation when using elementor editor on static pages.
* Fixed custom fields update issue in page editor specially for elementor editor.
* Improved bulk translation error handling and reporting.
* Improved Gemini SEO-friendly slug generation prompts.
* Improved the bulk translation modal interface.

= 0.2.0 =
* Added a Bulk Translate button to Polylang supported taxonomy pages so you can translate taxonomy terms directly.
* Added automatic translation of taxonomy terms linked to posts during posts bulk translation.
* Added Failed, Completed, and Skipped filters to the Bulk Translation progress modal.
* Added a Translate Again button to retry posts that failed during bulk translation.
* Added review notices to the TranslateSense dashboard, Bulk Translation progress modal, and Editor String Translation modal.
* Fixed an issue that created duplicate translated posts when an existing translation was in the trash.
* Fixed an issue that prevented Elementor pages from translating correctly during bulk translation.
* Fixed an issue where posts without an assigned language were translated but not linked to their translations. Added a notice with a direct Edit link so you can assign a language to the post or term.
* Improved error handling and messages to make bulk translation issues easier to understand.

= 0.1.0 =
* Added default ACF field translation support in Bulk Translation and the Page Editor.
* Added SEO metadata translation support for Yoast SEO, Rank Math SEO, and SEOPress.
* Added support for custom fields configured through plugin `wpml-config.xml` files.
* Improved post translation completion checks.
* Improved API key validation in the TranslateSense dashboard.

= 0.0.9 =
* Initial public release.
* Added OpenAI, Google Gemini, and browser-based AI translation.
* Added Gutenberg, Elementor, and Classic Editor support.
* Added bulk translation and background processing.
* Added slug and internal link translation.
* Added taxonomy, custom field, and media reference synchronization.
* Added ACF repeater and flexible content support.
* Added SEO metadata support.
* Added XML Configuration and Translation Memory.