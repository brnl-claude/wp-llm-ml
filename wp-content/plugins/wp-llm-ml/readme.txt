=== LLM Multilingual ===
Contributors: wp-llm-ml
Tags: multilingual, translation, language switcher, hreflang, elementor
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight multilingual plugin without upsells, nags or tracking. Elementor and GeneratePress friendly, SEO and AI friendly, optional AI translation.

== Description ==

* One page per language, linked as translations; default-language URLs stay unchanged, other languages get a prefix such as /nl/.
* "Create translation" copies a page as a draft including its Elementor layout, page settings and Yoast SEO fields.
* Browser language detection in the browser (cache friendly, never redirects search engines): one-time redirect on the home page, a suggestion bar elsewhere; the visitor's choice is remembered.
* Language switcher in a theme menu location, as Elementor widget, classic widget or [wpllmml_switcher] shortcode.
* Menus, internal links, logo link, blog lists, archives, search, feeds and previous/next links follow the visitor's language.
* hreflang + x-default, per-language canonical, og:locale and alternates, Yoast schema inLanguage, Yoast sitemaps with xhtml:link alternates.
* Translatable site title, tagline, menu labels, term names, widget texts and GeneratePress copyright; per-language widget visibility.
* REST API field and ?lang= filter, /wp-json/wpllmml/v1/languages, WordPress Abilities for AI agents.
* Optional AI translation and spell checking through any OpenAI-compatible API (default: HostYourAI, EU hosted). Nothing is sent unless an editor clicks a button.

== Installation ==

1. Upload and activate the plugin.
2. Settings → Languages: add languages, install language packs, choose the menu location for the switcher.
3. In Pages, click "+NL" (etc.) in the Languages column to create a translation, translate it in Elementor and publish.
4. Optional: Settings → Languages → AI. Prefer defining the key in wp-config.php: define( 'WPLLMML_AI_API_KEY', '...' );

== Frequently Asked Questions ==

= Does it use cookies? =

One functional cookie, wpllmml_lang, stores the language a visitor chose so they are not redirected again. No tracking.

= Does it work with page caching? =

Yes. The language is part of the URL and browser detection runs in the browser.

== Changelog ==

= 0.1.0 =
* First release.
