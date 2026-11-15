# Changelog

Nieuwste eerst. Elke release op GitHub bevat wat hier staat tot en met zijn datum; de
release-notes tonen de regels die sinds de vorige release zijn toegevoegd.

## 15 november 2026

- Vertalen kan nu rechtstreeks vanuit het overzicht van Pagina's en Berichten. Klik op `+NL` of `+RO` en kies "Create translation (copy) and open Elementor" of "Create & translate with AI". De AI-vertaling loopt in de rij zelf, ook voor meerdere pagina's tegelijk, en eindigt met een link naar Elementor.
- Een bestaande vertaling (`NL`, `RO`) heeft een menu met Edit with Elementor, Edit, View en "Translate again from English with AI".
- Na een update laat het dashboard eenmalig zien wat er nieuw is. De melding verdwijnt zodra je hem wegklikt.
- Een pagina vertalen met AI geeft geen "504 Gateway Time-out" meer: de vertaling loopt op de server op de achtergrond en het scherm toont de verstreken tijd. De hele pagina gaat in één keer naar de AI, zodat de vertaling de samenhang van de tekst behoudt.
- Annuleren kan met de knop Cancel. Sluit of verlaat je de pagina tijdens het vertalen, dan stopt de server ook, zelfs midden in het AI-verzoek. Er wordt dan niets opgeslagen en de bestaande tekst blijft staan. Per pagina loopt er hooguit één vertaling tegelijk.
- De spinner naast "Working…" staat niet meer over de tekst heen.
- "Fill empty … fields with AI" (Instellingen → Languages → Texts) laat nu een voortgangsbalk zien en vult de velden per groepje in; elk groepje wordt meteen opgeslagen. Een fout, zoals een verkeerde API-sleutel, staat er direct bij.
- De andere AI-knoppen (vertalen met AI) en "Install language packs" tonen een melding met spinner zolang ze bezig zijn, en reageren niet op een tweede klik.
- Eerste versie. Talen met eigen URL's (`/nl/…`, `/ro/…`); de Engelse URL's blijven zoals ze zijn.
- "+NL" maakt een conceptvertaling van een pagina, met Elementor-layout, GeneratePress-instellingen en Yoast-velden, die je in Elementor vertaalt.
- Bezoekers met een Nederlandse of Roemeense browser gaan op de homepage één keer naar hun taal; op andere pagina's zien ze een balk met een link. Hun keuze wordt onthouden, zoekmachines worden nooit doorgestuurd.
- Taalwisselaar in het GeneratePress-menu, als Elementor-widget, als widget en als shortcode.
- Menu's, links in pagina's, het logo, de blog, archieven, zoeken en RSS blijven in de taal van de bezoeker.
- Voor zoekmachines en AI: hreflang, `<html lang>`, canonical en `og:locale` per taal, Yoast-sitemaps met alle talen, en de taal en vertalingen van elke pagina in de REST API.
- Sitetitel, tagline, menulabels, categorienamen, widgetteksten en de copyrightregel zijn te vertalen onder Instellingen → Languages → Texts; widgets kunnen per taal getoond worden.
- Optioneel: vertalen en spelling controleren met AI via HostYourAI of een andere OpenAI-compatibele dienst, alleen als je op de knop klikt.
- Nieuwe versies verschijnen onder Dashboard → Updates.
- Per taal kies je de variant, bijvoorbeeld English (UK) in plaats van English (US): spelling, datumnotatie, `<html lang="en-GB">` en het WordPress-taalpakket volgen die keuze, de URL's blijven gelijk. Kies je onder Instellingen → Algemeen een andere sitetaal, dan schakelt de plugin mee.
