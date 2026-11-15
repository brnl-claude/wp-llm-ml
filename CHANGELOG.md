# Changelog

Nieuwste eerst. Elke release op GitHub bevat wat hier staat tot en met zijn datum; de
release-notes tonen de regels die sinds de vorige release zijn toegevoegd.

## 15 november 2026

- "How it works" (Instellingen → Languages) is nu een volledige handleiding met inhoudsopgave en screenshots: instellen, pagina's vertalen (ook in bulk), werken in Elementor, de layout die het Engels volgt, spellingscontrole, Forminator-formulieren, teksten buiten pagina's, de taalwisselaar, wat bezoekers en zoekmachines zien, AI-instellingen, updates en oplossingen voor veelvoorkomende problemen. Er staat ook een uitleg bij van de taalknoppen in het paginaoverzicht.
- Forminator-formulieren in elke taal: in Instellingen → Languages → Forms maak je per formulier en per taal een vertaalde kopie met "Create & translate with AI". Vertaald worden labels, placeholders, beschrijvingen, keuzes, de knoptekst, meldingen en e-mails, ook de bevestiging aan de bezoeker. Codes zoals `{name-1}` en `{all_fields}` blijven staan, net als de opmaak en de instellingen.
- Pagina's houden gewoon het Engelse formulier (`[forminator_form id="…"]`). Op /nl/ en /ro/ toont de plugin vanzelf de kopie in die taal, ook in widgets en de footer. De kopie is een gewoon Forminator-formulier, bijvoorbeeld "Book consult (NL)": je past de teksten aan in Forminator, en inzendingen en e-mails komen per taal binnen, zodat je ziet in welke taal iemand schreef.
- Pas je de velden van het Engelse formulier aan, gebruik dan "Translate again". De kopie krijgt dan dezelfde velden, opnieuw vertaald. Een kopie die op concept staat of verwijderd is, wordt niet getoond; de pagina valt dan terug op het Engelse formulier.
- Opgelost: "Apply" in het spellingsvenster gaf soms "Text not found", bijvoorbeeld bij `…sum of its parts” I` op de About-pagina. In de pagina stond daar opmaak tussen (`parts</em>”`), of een aanhalingsteken of harde spatie als HTML-code. De correctie wordt nu op de juiste plek in de HTML gezet, en alleen het stukje dat verandert (hier de komma), zodat de opmaak blijft staan. Komt dezelfde fout twee keer in een tekst voor, dan wordt de juiste plek gekozen aan de hand van de zin ervoor.
- De doorhaling van een spellingsfout in het controlevenster is dunner, zodat de oorspronkelijke tekst goed leesbaar blijft.
- Spellingscontrole vanuit het overzicht van Pagina's en Berichten: "Check spelling" onder de titel, of "Check spelling with AI" in het menu van een vertaling (`NL`, `RO`). Er opent een ruim venster met per fout de zin eromheen: de fout doorgestreept, de verbetering ernaast, waar het staat (bijvoorbeeld "Text Editor", "Title" of "Meta description") en een korte uitleg. Per fout kies je "Apply" of "Ignore", of in één keer "Apply all". Er verandert niets tot je op Apply klikt.
- De knop "Check spelling with AI" op de bewerkpagina gebruikt hetzelfde venster. Na een correctie herlaadt de editor zodra je het venster sluit.
- De controle loopt op de achtergrond en toont de voortgang, zodat ook een lange pagina geen "504 Gateway Time-out" meer geeft. Sluit je het venster, dan stopt de controle.
- Een correctie wordt toegepast in het veld waar de fout gevonden is, ook als dezelfde tekst elders op de pagina staat.
- Meerdere pagina's tegelijk vertalen: vink ze aan in het overzicht van Pagina's of Berichten en kies bij Bulkacties bijvoorbeeld "Translate into Nederlands with AI" of "Translate into all languages with AI". Er lopen twee vertalingen tegelijk, met de voortgang in elke rij en bovenaan de pagina. De vertalingen worden als concept opgeslagen. Talen die al een vertaling hebben, worden overgeslagen. "Cancel all" stopt alles; houd het scherm open tot het klaar is.
- Met "Create … translation (copy)" maak je in één keer conceptkopieën om zelf te vertalen.
- Opgelost: na het opslaan van de Engelse pagina bleven NL en RO knipperen en werden ze niet bijgewerkt. Dat gebeurde op servers waar de achtergrondtaken van WordPress (WP-Cron) niet vanzelf draaien. Elementor en het overzicht van Pagina's starten de update nu zelf, en een update die al vastzat gaat verder zodra je een van beide opent.
- Mislukt een update toch, dan krijgt de taal een rode markering met de reden en een knop "Update now". Hij blijft dan niet eindeloos knipperen.
- Een update waarbij niets verandert, maakt geen nieuwe revisie meer aan.
- Een layoutwijziging doe je nog maar één keer. Sla je de Engelse pagina op in Elementor, dan nemen de Nederlandse en Roemeense versie de nieuwe opbouw, volgorde en stijl binnen enkele seconden vanzelf over. Vertaalde teksten blijven staan. Nieuwe of gewijzigde Engelse teksten worden met AI vertaald en staan meteen live.
- Zo'n vertaling krijgt een stip, in het overzicht van Pagina's en in Elementor. Opslaan in Elementor of "Mark as reviewed" haalt de stip weg. Zonder AI blijven nieuwe teksten Engels; zodra AI weer beschikbaar is, worden ze bij de volgende keer alsnog vertaald.
- Wat je per taal bewust anders hebt, blijft staan: een ander formulier, plaatje of link, of een blok dat alleen op de Nederlandse pagina staat. Moet een vertaling helemaal los blijven van het Engels, kies dan "Keep its own layout" in het taalmenu. Een vertaling die niet als kopie van de Engelse pagina is gemaakt, wordt nooit overschreven. Elke automatische wijziging is terug te draaien via Elementor → Geschiedenis → Revisies.
- In de bovenbalk van Elementor staat nu EN · NL · RO, naast desktop/tablet/mobiel. Eén klik opent dezelfde pagina in een andere taal, zonder herladen. Met `+RO` maak je een ontbrekende vertaling aan, met AI of als kopie, en die gaat direct open.
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
