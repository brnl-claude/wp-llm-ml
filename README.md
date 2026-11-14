# LLM Multilingual (wp-llm-ml)

Een lichte, volledig gratis meertaligheidsplugin voor WordPress, gebouwd voor
[thelibrarywithin.com](https://thelibrarywithin.com/) (GeneratePress + GP Premium,
Elementor free, Yoast SEO, MailPoet, Forminator), maar bruikbaar op elke site.

**Geen premiumversie, geen upsells, geen nag-schermen, geen tracking, geen
externe verbindingen.** De enige uitzondering is optionele AI-vertaling, en die
gebruikt alleen de AI-dienst die je zelf instelt, en alleen wanneer je op een knop klikt.

## Wat het doet

| Onderdeel | Hoe |
|---|---|
| **Talen & URL's** | Standaardtaal houdt de huidige URL's (`/gestalt-therapy/`); andere talen krijgen een prefix (`/nl/…`, `/ro/…`). Vertaalde slugs (`/nl/gestalt-therapie/`) of dezelfde slug (`/nl/reiki/`) — beide kunnen. |
| **Inhoud** | Eén pagina per taal, gekoppeld als vertaling. "Vertaling maken" kopieert de pagina als concept, inclusief Elementor-layout, pagina-instellingen, GeneratePress-instellingen en Yoast-velden. Je vertaalt daarna gewoon in Elementor. |
| **Browserdetectie** | Bezoeker met NL-browser die op de Engelse homepage binnenkomt → één keer naar `/nl/`. Op andere pagina's een kleine balk "Deze pagina is ook beschikbaar in het Nederlands". Gekozen taal wordt onthouden (functionele cookie `wpllmml_lang`). Draait in de browser, dus werkt met page-caching; zoekmachines worden nooit doorgestuurd. |
| **Taalwisselaar** | Automatisch in het GeneratePress-menu (dropdown of naast elkaar), als Elementor-widget, klassieke widget of shortcode `[wpllmml_switcher]`. |
| **Menu's** | Eén menu voor alle talen: items wijzen automatisch naar de vertaling en tonen de vertaalde titel; eigen labels zijn vertaalbaar. |
| **Links in content** | Interne links (Elementor-knoppen, tekst, footer-widgets, copyright) wijzen automatisch naar de vertaalde pagina als die bestaat. |
| **Overige teksten** | Sitetitel, tagline, menulabels, categorienamen, widgetteksten, GeneratePress-copyright: tab *Teksten*. Thema-/plugin-teksten komen uit de WordPress-taalpakketten (één klik om te installeren). |
| **Widgets per taal** | Bijv. een Engels en een Nederlands MailPoet-formulier: tab *Widgets*. |
| **SEO** | `<html lang>`, `hreflang` + `x-default`, canonical per taal, `og:locale` (+alternates), Yoast schema `inLanguage`, Yoast XML-sitemaps met alle talen én `xhtml:link`-alternates, RSS per taal. Blog, archieven, zoeken en vorige/volgende-links blijven binnen de taal. |
| **AI-vriendelijk** | Volledig server-gerenderde vertaalde HTML (geen JavaScript-vertaling), REST API met `wpllmml`-veld en `?lang=nl`, `/wp-json/wpllmml/v1/languages`, en WordPress *Abilities* (`wp-llm-ml/list-languages`, `get-translations`, `create-translation`, `translate-text`) voor AI-agents/MCP. |
| **AI-vertaling (optioneel)** | Via elke OpenAI-compatibele API, standaard [HostYourAI](https://hostyourai.com) (EU). Vertaalt titel, Elementor-teksten (ook repeaters zoals toggles/icon-lists), blokinhoud en Yoast-velden; HTML, links, iconen en namen blijven intact. "Maken & vertalen met AI" of "Vertalen vanuit English met AI". |
| **Spellingscontrole (optioneel)** | "Spelling controleren met AI" in de editor: lijst met fouten + uitleg, per suggestie "Toepassen". |

## Installeren

1. Bouw de zip: `bin/build-zip.sh` → `dist/wp-llm-ml.zip`
   (of zip de map `wp-content/plugins/wp-llm-ml`).
2. WordPress → Plugins → Nieuwe plugin → Plugin uploaden → activeren.
3. **Instellingen → Languages**:
   - Voeg *Nederlands* en *Română* toe (Engels blijft standaard).
   - Klik **Install language packs** (vertaalt knoppen, datums, formulier-meldingen).
   - *Switcher in menu*: **Primary Menu** (GeneratePress). Tip: "Language code (NL)" is compacter in het menu.
4. Pagina's → kolom **Languages** → klik `+NL` bij een pagina → vertaal in Elementor → publiceer.
5. Optioneel AI: tab **AI** → model kiezen. Zet de sleutel bij voorkeur in `wp-config.php`:
   ```php
   define( 'WPLLMML_AI_API_KEY', 'hyai-rt-…' );
   ```
   Endpoint HostYourAI: `https://hostyourai.com/api/v1` (OpenAI-compatibel, Bearer-key).
   Zet bij *Instructions for translations* je huisstijl, bijv. "Spreek de lezer aan met je/jij; laat Gestalt en Reiki onvertaald; toon warm en rustig".

## Vertaalworkflow met Elementor

- In Elementor: **Pagina-instellingen → Translations** toont alle taalversies met "Edit with Elementor" of "Create translation".
- Op de site (ingelogd): de adminbalk toont de taal van de pagina met links naar de andere versies.
- Formulieren (Forminator/MailPoet): maak per taal een formulier en plaats dat in de vertaalde pagina, of zet widgets per taal.

## Bewust níet / bekende beperkingen

- Elementor **Theme Builder** (Pro) en **GP Premium Elements** met weergaveregels op specifieke pagina-ID's: regels gelden niet automatisch voor de vertaling (voeg de vertaalde pagina toe aan de regel).
- Categorieën/tags worden gedeeld tussen talen; alleen hun namen zijn vertaalbaar (geen aparte slugs per taal).
- Media (afbeeldingen, alt-teksten in de mediabibliotheek) worden gedeeld; alt-teksten ín Elementor-widgets worden per pagina vertaald.
- Vereist "mooie" permalinks.
- WooCommerce, multisite en WPML-import worden niet ondersteund (niet nodig voor deze site).

## Ontwikkelen & testen

```bash
tests/run.sh            # bouwt een wegwerp-WordPress (SQLite) met GeneratePress, Elementor en Yoast
```

Drie suites (135 controles): model (vertalingen, Elementor-data byte-voor-byte, slugs, hiërarchie,
queries), HTTP (routing, redirects, hreflang, menu's, switcher, links, lijsten, REST, sitemaps) en
AI (tegen een nep-OpenAI-server: Elementor-teksten, repeaters, Yoast-velden, spellingscontrole, Abilities).
Testversies: WordPress 7.1.4, Elementor 4.2.2, Yoast SEO 28.2, GeneratePress 3.6.1, PHP 8.3.

### Opbouw

```
wp-content/plugins/wp-llm-ml/
  wp-llm-ml.php                 bootstrap + autoloader
  includes/class-router.php     taal uit URL, locale, slugs per taal, front/blog-pagina per taal, redirects
  includes/class-translations.php  taal + vertaalgroep per post (post meta)
  includes/class-duplicator.php vertaling maken (Elementor-veilig)
  includes/class-links.php      permalinks + interne links per taal
  includes/class-query.php      lijsten/zoeken/feeds per taal
  includes/class-menus.php      menu's + taalwisselaar in menu
  includes/class-switcher.php   taalwisselaar (shortcode/widget)
  includes/class-detect.php     browserdetectie (client-side)
  includes/class-seo.php        hreflang/og:locale
  includes/class-strings.php    vertaalbare losse teksten
  includes/class-rest.php, class-abilities.php   REST + Abilities API
  includes/integrations/        Elementor, GeneratePress, Yoast
  includes/ai/                  OpenAI-compatibele client, vertaler, spellingscontrole
  includes/admin/               instellingen, kolommen, metabox, adminbalk
```

Licentie: GPL-2.0-or-later.
