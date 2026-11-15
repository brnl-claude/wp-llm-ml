# LLM Multilingual (wp-llm-ml)

Een lichte, volledig gratis meertaligheidsplugin voor WordPress, gebouwd voor
[thelibrarywithin.com](https://thelibrarywithin.com/) (GeneratePress + GP Premium,
Elementor free, Yoast SEO, MailPoet, Forminator), maar bruikbaar op elke site.

**Geen premiumversie, geen upsells, geen nag-schermen, geen tracking.** Externe
verbindingen zijn er maar twee: een anonieme update-check bij GitHub (max. twee keer
per dag, uit te zetten) en — alleen als je op een AI-knop klikt — de AI-dienst die je
zelf instelt.

## Wat het doet

| Onderdeel | Hoe |
|---|---|
| **Talen & URL's** | Standaardtaal houdt de huidige URL's (`/gestalt-therapy/`); andere talen krijgen een prefix (`/nl/…`, `/ro/…`). Vertaalde slugs (`/nl/gestalt-therapie/`) of dezelfde slug (`/nl/reiki/`) — beide kunnen. |
| **Inhoud** | Eén pagina per taal, gekoppeld als vertaling. "Vertaling maken" kopieert de pagina als concept, inclusief Elementor-layout, pagina-instellingen, GeneratePress-instellingen en Yoast-velden. Je vertaalt daarna gewoon in Elementor. |
| **Layout volgt Engels** | Sla je de Engelse Elementor-pagina op, dan nemen de vertalingen op de achtergrond (gestart door de editor zelf, WP-Cron als reserve) de opbouw, volgorde en stijl over (elementen gekoppeld via hun Elementor-id, drieweg-vergelijking met de vorige stand). Vertaalde teksten blijven; nieuwe/gewijzigde teksten gaan in één AI-verzoek en staan direct live, met een review-stip. Bewuste verschillen per taal (formulier-shortcode, afbeelding, link, blokken alleen in de vertaling) blijven. Per vertaling uit te zetten ("Keep its own layout"). |
| **Elementor-bovenbalk** | EN · NL · RO naast desktop/tablet/mobiel: wisselen zonder herladen, `+RO` maakt een vertaling (AI of kopie). Gebouwd op Elementors editor-pakketten; ontbreken die in een toekomstige versie, dan blijft *Pagina-instellingen → Translations* werken. Een wekelijkse CI-run test tegen de nieuwste Elementor. |
| **Browserdetectie** | Bezoeker met NL-browser die op de Engelse homepage binnenkomt → één keer naar `/nl/`. Op andere pagina's een kleine balk "Deze pagina is ook beschikbaar in het Nederlands". Gekozen taal wordt onthouden (functionele cookie `wpllmml_lang`). Draait in de browser, dus werkt met page-caching; zoekmachines worden nooit doorgestuurd. |
| **Taalwisselaar** | Automatisch in het GeneratePress-menu (dropdown of naast elkaar), als Elementor-widget, klassieke widget of shortcode `[wpllmml_switcher]`. |
| **Menu's** | Eén menu voor alle talen: items wijzen automatisch naar de vertaling en tonen de vertaalde titel; eigen labels zijn vertaalbaar. |
| **Links in content** | Interne links (Elementor-knoppen, tekst, footer-widgets, copyright) wijzen automatisch naar de vertaalde pagina als die bestaat. |
| **Overige teksten** | Sitetitel, tagline, menulabels, categorienamen, widgetteksten, GeneratePress-copyright: tab *Teksten*. Thema-/plugin-teksten komen uit de WordPress-taalpakketten (één klik om te installeren). |
| **Widgets per taal** | Bijv. een Engels en een Nederlands MailPoet-formulier: tab *Widgets*. |
| **SEO** | `<html lang>`, `hreflang` + `x-default`, canonical per taal, `og:locale` (+alternates), Yoast schema `inLanguage`, Yoast XML-sitemaps met alle talen én `xhtml:link`-alternates, RSS per taal. Blog, archieven, zoeken en vorige/volgende-links blijven binnen de taal. |
| **AI-vriendelijk** | Volledig server-gerenderde vertaalde HTML (geen JavaScript-vertaling), REST API met `wpllmml`-veld en `?lang=nl`, `/wp-json/wpllmml/v1/languages`, en WordPress *Abilities* (`wp-llm-ml/list-languages`, `get-translations`, `create-translation`, `translate-text`) voor AI-agents/MCP. |
| **AI-vertaling (optioneel)** | Via elke OpenAI-compatibele API, standaard [HostYourAI](https://hostyourai.com) (EU). Vertaalt titel, Elementor-teksten (ook repeaters zoals toggles/icon-lists), blokinhoud en Yoast-velden; HTML, links, iconen en namen blijven intact. "Create & translate with AI" of "Translate from English with AI". De hele pagina gaat in één verzoek naar de AI (context blijft behouden) en draait op de server op de achtergrond, dus geen gateway-timeouts; het scherm vraagt elke 2 s de status op. Stopt dat (annuleren, pagina verlaten), dan stopt de server ook en wordt er niets opgeslagen. Eén vertaling per pagina tegelijk. |
| **Spellingscontrole (optioneel)** | "Spelling controleren met AI" in de editor: lijst met fouten + uitleg, per suggestie "Toepassen". |

## Installeren

1. Download de nieuwste versie:
   [releases/latest/download/wp-llm-ml.zip](https://github.com/brnl-claude/wp-llm-ml/releases/latest/download/wp-llm-ml.zip)
   (niet uitpakken).
2. WordPress → **Plugins → Nieuwe plugin → Plugin uploaden** → zip kiezen → **Nu installeren** → **Activeren**.
   Doe dit eerst op een testsite/staging.
3. **Instellingen → Languages**:
   - Voeg *Nederlands* en *Română* toe (Engels blijft standaard) en kies bij Engels de variant
     **English (UK) — en_GB** (de live site draait nu op en_US; de variant bepaalt spelling,
     datumnotatie en `<html lang>`, niet de URL's).
   - Klik **Install language packs** (vertaalt knoppen, datums, formulier-meldingen).
   - *Switcher in menu*: **Primary Menu** (GeneratePress). Tip: "Language code (NL)" is compacter in het menu.
4. Pagina's → kolom **Languages** → klik `+NL` bij een pagina en kies "Create translation (copy) and open
   Elementor" of "Create & translate with AI" → controleer in Elementor → publiceer.
5. Optioneel AI: tab **AI** → model kiezen. Zet de sleutel bij voorkeur in `wp-config.php`:
   ```php
   define( 'WPLLMML_AI_API_KEY', 'hyai-rt-…' );
   ```
   Endpoint HostYourAI: `https://hostyourai.com/api/v1` (OpenAI-compatibel, Bearer-key).
   Zet bij *Instructions for translations* je huisstijl, bijv. "Spreek de lezer aan met je/jij; laat Gestalt en Reiki onvertaald; toon warm en rustig; gebruik in het Roemeens ș en ț (met komma)".

### Updates

Nieuwe releases verschijnen vanzelf onder **Dashboard → Updates** (de plugin vraagt via
WordPress' eigen `Update URI`-mechanisme de laatste GitHub-release op). Handmatig kan ook:
nieuwe zip uploaden en "Huidige vervangen" kiezen. Update-check uitzetten:
`define( 'WPLLMML_DISABLE_UPDATES', true );` in `wp-config.php`.

## Vertaalworkflow met Elementor

- In Elementor: **bovenbalk EN · NL · RO** (naast desktop/tablet/mobiel) om te wisselen of een vertaling te maken; een stip betekent "automatisch bijgewerkt, even nalezen". Ook **Pagina-instellingen → Translations** toont alle taalversies.
- Layout aanpassen doe je op de Engelse pagina; na opslaan volgen de vertalingen vanzelf. Teksten pas je per taal aan.
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
tests/package.sh        # bouwt de zip, installeert hem in een verse site en test een update via een (nep)release
bin/build-zip.sh        # alleen de zip bouwen → dist/wp-llm-ml.zip
```

Zes suites (278 controles): model (vertalingen, Elementor-data byte-voor-byte, slugs, hiërarchie,
queries), HTTP (routing, redirects, hreflang, menu's, switcher, links, lijsten, REST, sitemaps),
AI (tegen een nep-OpenAI-server: Elementor-teksten, repeaters, Yoast-velden, spellingscontrole, Abilities),
sync (layout volgt Engels: nieuw/gewijzigd/verwijderd/verplaatst, lokale verschillen, zonder AI, zonder historie),
browser (Playwright in de echte Elementor-editor: bovenbalk, wisselen, sync, `+RO` met AI; draait als
Playwright er is, `WPLLMML_BROWSER=1` maakt het verplicht) en package (zip installeren in een verse site,
activeren, updaten via de WordPress-upgrader).
Testversies: WordPress 7.1.4, Elementor 4.2.2, Yoast SEO 28.2, GeneratePress 3.6.1, PHP 8.3. Met
`WP_VERSION=latest ELEMENTOR_VERSION=latest YOAST_VERSION=latest tests/run.sh` tegen de nieuwste releases;
dat doet [`compat.yml`](.github/workflows/compat.yml) elke maandag (en op verzoek).

### Releases

Zoals bij imgcraft: elke push naar `main` wordt getest, gebouwd en als GitHub Release gepubliceerd
(workflow [`plugin.yml`](.github/workflows/plugin.yml)); pull requests worden alleen getest.

- **Versie** `0.<minor>.<patch>`: *minor* staat in [`version.properties`](version.properties) (verhoog die met
  de hand voor een grotere stap), *patch* telt de commits op `main` sinds die regel veranderde, dus +1 per
  release. `bin/version.sh` toont de versie die een build krijgt; `bin/build-zip.sh` zet die in de zip
  (de bronbestanden houden `0.0.0-dev`).
- **Release-notes**: de regels die sinds de vorige release in [`CHANGELOG.md`](CHANGELOG.md) zijn
  bijgekomen (`## datum`, `- regel`). Zet daar dus bij elke wijziging een regel bij.
- **Updates**: geïnstalleerde sites zien elke nieuwe release onder Dashboard → Updates.

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
  includes/class-sync.php       layout van de standaardtaal → vertalingen (Elementor)
  includes/integrations/        Elementor (+ bovenbalk-wisselaar), GeneratePress, Yoast
  includes/ai/                  OpenAI-compatibele client, vertaler, spellingscontrole
  includes/admin/               instellingen, kolommen, metabox, adminbalk
  includes/class-updater.php    updates via GitHub Releases
.github/workflows/plugin.yml    tests bij elke PR, release bij elke push naar main
.github/workflows/compat.yml    wekelijks: tests tegen de nieuwste WordPress/Elementor/Yoast
bin/                            build-zip, version
version.properties, CHANGELOG.md  versie (minor) en release-notes
```

Licentie: GPL-2.0-or-later.
