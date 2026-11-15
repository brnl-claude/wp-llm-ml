<?php
/**
 * Settings → Languages → How it works: the user guide.
 *
 * Screenshots live in assets/help/ and are made by bin/help-screenshots.sh.
 *
 * @package WPLLMML
 */

namespace WPLLMML\Admin;

use WPLLMML\Languages;

defined( 'ABSPATH' ) || exit;

$wpllmml_allowed = array(
	'strong' => array(),
	'em'     => array(),
	'code'   => array(),
	'kbd'    => array(),
	'br'     => array(),
	'a'      => array(
		'href'   => array(),
		'target' => array(),
	),
	'span'   => array(
		'class' => array(),
	),
);

/**
 * Print a paragraph (translatable text with a little markup).
 *
 * @param string $html Text.
 */
$wpllmml_p = static function ( $html ) use ( $wpllmml_allowed ) {
	echo '<p>' . wp_kses( $html, $wpllmml_allowed ) . '</p>';
};

/**
 * Print a list.
 *
 * @param string[] $items Items.
 * @param string   $tag   ul or ol.
 */
$wpllmml_list = static function ( array $items, $tag = 'ul' ) use ( $wpllmml_allowed ) {
	echo '<' . $tag . ( 'ol' === $tag ? ' class="wpllmml-help__steps"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
	foreach ( $items as $item ) {
		echo '<li>' . wp_kses( $item, $wpllmml_allowed ) . '</li>';
	}
	echo '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
};

/**
 * Print a screenshot.
 *
 * @param string $file    File in assets/help/.
 * @param string $caption Caption (also the alt text).
 */
$wpllmml_figure = static function ( $file, $caption ) {
	$path = WPLLMML_DIR . 'assets/help/' . $file;
	if ( ! is_file( $path ) ) {
		return;
	}
	$size = wp_getimagesize( $path );
	$url  = WPLLMML_URL . 'assets/help/' . $file . '?ver=' . rawurlencode( WPLLMML_VERSION );
	printf(
		'<figure class="wpllmml-help__figure"><a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="%2$s" loading="lazy" decoding="async"%3$s></a><figcaption>%2$s</figcaption></figure>',
		esc_url( $url ),
		esc_attr( $caption ),
		$size ? sprintf( ' width="%d" height="%d"', (int) $size[0], (int) $size[1] ) : '' // phpcs:ignore WordPress.Security.EscapeOutput
	);
};

/**
 * Print a tip or warning box.
 *
 * @param string $html Text.
 * @param string $kind tip or warning.
 */
$wpllmml_note = static function ( $html, $kind = 'tip' ) use ( $wpllmml_allowed ) {
	printf( '<div class="wpllmml-help__note is-%1$s">%2$s</div>', esc_attr( $kind ), wp_kses( $html, $wpllmml_allowed ) );
};

$wpllmml_default = Languages::get( Languages::default_code() );
$wpllmml_default = $wpllmml_default ? $wpllmml_default['name'] : 'English';

$wpllmml_sections = array(
	'overview' => __( 'In one minute', 'wp-llm-ml' ),
	'setup'    => __( 'Setting up', 'wp-llm-ml' ),
	'translate' => __( 'Translating a page', 'wp-llm-ml' ),
	'bulk'     => __( 'Many pages at once', 'wp-llm-ml' ),
	'elementor' => __( 'Working in Elementor', 'wp-llm-ml' ),
	'layout'   => __( 'Changing the layout', 'wp-llm-ml' ),
	'spelling' => __( 'Checking spelling', 'wp-llm-ml' ),
	'forms'    => __( 'Forms (Forminator)', 'wp-llm-ml' ),
	'texts'    => __( 'Texts outside pages', 'wp-llm-ml' ),
	'switcher' => __( 'Menus and the switcher', 'wp-llm-ml' ),
	'visitors' => __( 'Visitors and search engines', 'wp-llm-ml' ),
	'ai'       => __( 'AI settings', 'wp-llm-ml' ),
	'updates'  => __( 'Updates', 'wp-llm-ml' ),
	'faq'      => __( 'Troubleshooting', 'wp-llm-ml' ),
);
?>
<div class="wpllmml-help">
	<nav class="wpllmml-help__toc" aria-label="<?php esc_attr_e( 'Contents', 'wp-llm-ml' ); ?>">
		<strong><?php esc_html_e( 'Contents', 'wp-llm-ml' ); ?></strong>
		<ol>
			<?php foreach ( $wpllmml_sections as $wpllmml_id => $wpllmml_title ) : ?>
				<li><a href="#wpllmml-<?php echo esc_attr( $wpllmml_id ); ?>"><?php echo esc_html( $wpllmml_title ); ?></a></li>
			<?php endforeach; ?>
		</ol>
	</nav>
	<div class="wpllmml-help__body">

	<section id="wpllmml-overview" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['overview'] ); ?></h2>
		<?php
		$wpllmml_list(
			array(
				/* translators: %s: default language name */
				sprintf( __( '<strong>Every language is a page of its own.</strong> The %s page is the original; the Dutch and Romanian versions are separate pages, linked to it as translations. You edit them in Elementor like any other page.', 'wp-llm-ml' ), esc_html( $wpllmml_default ) ),
				__( '<strong>Each language has its own address:</strong> <code>/gestalt-therapy/</code>, <code>/nl/gestalt-therapie/</code>, <code>/ro/terapie-gestalt/</code>. The addresses of the original pages do not change.', 'wp-llm-ml' ),
				__( '<strong>Visitors choose their language</strong> with the switcher in the menu. On their first visit they are taken to, or offered, the language of their browser.', 'wp-llm-ml' ),
				/* translators: %s: default language name */
				sprintf( __( '<strong>The %s page leads the layout.</strong> Change the layout there once; the translations follow automatically and keep their own texts.', 'wp-llm-ml' ), esc_html( $wpllmml_default ) ),
				__( '<strong>AI is optional and only runs when you ask</strong> (or when a layout update has new texts): translating pages, forms and texts, and checking spelling.', 'wp-llm-ml' ),
			)
		);
		?>
		<div class="wpllmml-help__legend">
			<strong><?php esc_html_e( 'The language badges in the page lists', 'wp-llm-ml' ); ?></strong>
			<dl>
				<dt><span class="wpllmml-lang is-own">EN</span></dt><dd><?php esc_html_e( 'The language of this page.', 'wp-llm-ml' ); ?></dd>
				<dt><span class="wpllmml-lang is-publish">NL</span></dt><dd><?php esc_html_e( 'Translation, published.', 'wp-llm-ml' ); ?></dd>
				<dt><span class="wpllmml-lang is-draft">NL</span></dt><dd><?php esc_html_e( 'Translation, draft (not visible to visitors yet).', 'wp-llm-ml' ); ?></dd>
				<dt><span class="wpllmml-lang is-missing">+RO</span></dt><dd><?php esc_html_e( 'No translation yet: click to create one.', 'wp-llm-ml' ); ?></dd>
				<dt><span class="wpllmml-lang is-publish has-review">NL</span></dt><dd><?php esc_html_e( 'Texts were translated automatically after a layout change: please read them.', 'wp-llm-ml' ); ?></dd>
				<dt><span class="wpllmml-lang is-publish is-syncing">NL</span></dt><dd><?php esc_html_e( 'Being updated from the original page right now.', 'wp-llm-ml' ); ?></dd>
				<dt><span class="wpllmml-lang is-publish has-sync-error">NL</span></dt><dd><?php esc_html_e( 'The last update failed: open the menu to see why and to try again.', 'wp-llm-ml' ); ?></dd>
			</dl>
			<?php $wpllmml_p( __( 'Click a badge to open its menu.', 'wp-llm-ml' ) ); ?>
		</div>
	</section>

	<section id="wpllmml-setup" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['setup'] ); ?></h2>
		<?php
		$wpllmml_list(
			array(
				__( 'Go to <strong>Settings → Languages</strong>. The first language is the default language: the one your existing pages are in.', 'wp-llm-ml' ),
				__( 'Choose a <strong>variant</strong> per language, for example <em>English (UK)</em> instead of English (US). The variant sets spelling, date formats and <code>&lt;html lang="en-GB"&gt;</code>; the addresses stay the same.', 'wp-llm-ml' ),
				__( 'Add the other languages with <strong>Add language</strong>. The code becomes part of the address (<code>/nl/</code>); the name is what visitors see in the switcher.', 'wp-llm-ml' ),
				__( 'Click <strong>Install language packs</strong> when the screen asks for it. Buttons, dates and form messages of WordPress, the theme and plugins then appear in each language.', 'wp-llm-ml' ),
				__( 'Under <strong>Visitors</strong>, choose what happens on a first visit, where the switcher appears in the menu and what it shows. Save.', 'wp-llm-ml' ),
			),
			'ol'
		);
		$wpllmml_figure( 'languages.webp', __( 'Settings → Languages: the languages of the site and what visitors get.', 'wp-llm-ml' ) );
		?>
	</section>

	<section id="wpllmml-translate" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['translate'] ); ?></h2>
		<?php
		$wpllmml_list(
			array(
				__( 'Open <strong>Pages</strong> (or Posts). The <strong>Languages</strong> column shows a badge per language.', 'wp-llm-ml' ),
				__( 'Click the <span class="wpllmml-lang is-missing">+NL</span> badge and choose <strong>Create & translate with AI</strong>, or <strong>Create translation (copy) and open Elementor</strong> to translate it yourself.', 'wp-llm-ml' ),
				__( 'With AI, the whole page is translated in the background in one go (so the translation keeps the context). The row shows the progress; you can translate several pages at the same time. Keep the screen open: leaving it stops the translation and nothing is saved.', 'wp-llm-ml' ),
				__( 'The translation is saved as a <strong>draft</strong>. Open it with <strong>Edit with Elementor</strong>, read the texts, check the page address (slug) and the Yoast SEO title and description, and publish.', 'wp-llm-ml' ),
			),
			'ol'
		);
		$wpllmml_figure( 'pages.webp', __( 'Pages: the Languages column and the menu of a translation.', 'wp-llm-ml' ) );
		$wpllmml_note( __( '<strong>Translate again</strong>: the menu of an existing translation has "Translate again from English with AI". It replaces the texts of that translation with a new translation of the original page, so your own changes in it are overwritten.', 'wp-llm-ml' ) );
		?>
	</section>

	<section id="wpllmml-bulk" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['bulk'] ); ?></h2>
		<?php
		$wpllmml_list(
			array(
				__( 'Tick the pages in the list.', 'wp-llm-ml' ),
				__( 'Under <strong>Bulk actions</strong>, choose <strong>Translate into Nederlands with AI</strong> (or another language, or <strong>all languages</strong>) and click <strong>Apply</strong>.', 'wp-llm-ml' ),
				__( 'Two translations run at a time. The panel at the top shows the progress and has <strong>Cancel all</strong>; each row shows its own progress. Keep the screen open until it says "Bulk translation finished".', 'wp-llm-ml' ),
			),
			'ol'
		);
		$wpllmml_figure( 'bulk.webp', __( 'Translating two pages into all languages at once.', 'wp-llm-ml' ) );
		$wpllmml_list(
			array(
				__( 'Languages that already have a translation are skipped: nothing is overwritten.', 'wp-llm-ml' ),
				/* translators: %s: default language name */
				sprintf( __( 'The translation is always made from the %s version, also when you tick a Dutch page.', 'wp-llm-ml' ), esc_html( $wpllmml_default ) ),
				__( '<strong>Create … translation (copy)</strong> makes untranslated draft copies, for translating yourself.', 'wp-llm-ml' ),
			)
		);
		?>
	</section>

	<section id="wpllmml-elementor" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['elementor'] ); ?></h2>
		<?php
		$wpllmml_p( __( 'In the Elementor editor, the top bar shows the languages of the page next to the desktop/tablet/mobile switch.', 'wp-llm-ml' ) );
		$wpllmml_list(
			array(
				__( 'Click <strong>NL</strong> to edit the Dutch version in the same editor, without reloading. With unsaved changes, Elementor first asks to save them.', 'wp-llm-ml' ),
				__( 'Click <strong>+RO</strong> to create a missing translation: with AI (the new version opens when it is ready) or as a copy.', 'wp-llm-ml' ),
				__( 'A dot on a language means its texts were updated automatically after a layout change; its menu has <strong>Mark as reviewed</strong>. Hover a language for its status.', 'wp-llm-ml' ),
			)
		);
		$wpllmml_figure( 'elementor.webp', __( 'Elementor: switch language or create a translation from the top bar.', 'wp-llm-ml' ) );
		$wpllmml_note( __( 'The same links are in <strong>Page settings → Translations</strong> in Elementor, in case a future Elementor version changes the top bar.', 'wp-llm-ml' ) );
		?>
	</section>

	<section id="wpllmml-layout" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['layout'] ); ?></h2>
		<?php
		/* translators: %s: default language name */
		$wpllmml_p( sprintf( __( '<strong>Change the layout once, on the %s page.</strong> When you save it in Elementor, the Dutch and Romanian versions follow within seconds:', 'wp-llm-ml' ), esc_html( $wpllmml_default ) ) );
		$wpllmml_list(
			array(
				__( '<strong>Follows the original:</strong> sections, columns and widgets (added, removed, moved), styles, spacing, colours and page settings.', 'wp-llm-ml' ),
				__( '<strong>Stays as it is:</strong> texts that are already translated.', 'wp-llm-ml' ),
				__( '<strong>Translated with AI and published right away:</strong> new texts and texts you changed in the original. The translation then gets a dot: read those texts and click <strong>Mark as reviewed</strong> (or save the page in Elementor).', 'wp-llm-ml' ),
				__( '<strong>Kept per language:</strong> things you deliberately set differently in a translation, such as another form, image or link, a style you changed only there, and blocks that exist only in the translation.', 'wp-llm-ml' ),
			)
		);
		$wpllmml_note( __( 'Should a translation stay completely separate (its own layout)? Choose <strong>Keep its own layout</strong> in its menu in the page list. <strong>Follow the English layout again</strong> switches it back.', 'wp-llm-ml' ) );
		$wpllmml_note( __( 'Every automatic update is a normal revision: <strong>Elementor → History → Revisions</strong> takes a translation back. For translations made with a plugin version before November 2026, the first update follows the original completely: blocks that existed only in the translation are removed then (undo with Revisions).', 'wp-llm-ml' ), 'warning' );
		?>
	</section>

	<section id="wpllmml-spelling" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['spelling'] ); ?></h2>
		<?php
		$wpllmml_list(
			array(
				__( 'In the page list, hover a page and click <strong>Check spelling</strong>. For a translation, use <strong>Check spelling with AI</strong> in its language menu. On the edit screen there is a button in the Language box.', 'wp-llm-ml' ),
				__( 'Every finding is shown in its sentence: the mistake struck through, the correction next to it, where it is (Heading, Text Editor, Title, Meta description…) and a short explanation.', 'wp-llm-ml' ),
				__( 'Click <strong>Apply</strong> or <strong>Ignore</strong> per finding, or <strong>Apply all</strong>. Nothing changes until you click Apply; the correction is made in exactly that place and formatting stays.', 'wp-llm-ml' ),
			),
			'ol'
		);
		$wpllmml_figure( 'spelling.webp', __( 'Spelling and grammar: each finding in its sentence.', 'wp-llm-ml' ) );
		?>
	</section>

	<section id="wpllmml-forms" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['forms'] ); ?></h2>
		<?php
		$wpllmml_list(
			array(
				__( 'Go to <strong>Settings → Languages → Forms</strong>. Every Forminator form has a column per language.', 'wp-llm-ml' ),
				__( 'Click <strong>Create & translate with AI</strong>. A translated copy appears, for example "Book consult (NL)": labels, choices, button, messages and e-mails, also the confirmation to the visitor.', 'wp-llm-ml' ),
				__( 'That is all: pages keep the original form, and on <code>/nl/</code> the Dutch copy is shown automatically (also in widgets and the footer).', 'wp-llm-ml' ),
			),
			'ol'
		);
		$wpllmml_figure( 'forms.webp', __( 'Forms: a translated copy per language.', 'wp-llm-ml' ) );
		$wpllmml_list(
			array(
				__( 'The copy is a normal Forminator form: adjust its texts in Forminator. Its submissions and e-mails are its own, so you can see which language a visitor used.', 'wp-llm-ml' ),
				__( 'Changed the fields of the original form? Click <strong>Translate again</strong> so the copy gets the same fields (this overwrites changes in the copy).', 'wp-llm-ml' ),
				__( 'A copy that is a draft or deleted is not used: the page shows the original form.', 'wp-llm-ml' ),
			)
		);
		?>
	</section>

	<section id="wpllmml-texts" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['texts'] ); ?></h2>
		<?php
		$wpllmml_p( __( 'Some texts are not part of a page: the site title and tagline, custom menu labels, category names, widget texts and the copyright line. Translate them in <strong>Settings → Languages → Texts</strong>. <strong>Fill empty Nederlands fields with AI</strong> does the empty ones in one go; check them and save. An empty field shows the original.', 'wp-llm-ml' ) );
		$wpllmml_figure( 'texts.webp', __( 'Texts: everything that is not part of a page.', 'wp-llm-ml' ) );
		$wpllmml_p( __( '<strong>Widgets</strong>: show a widget in one language only, for example an English and a Dutch newsletter form in the footer.', 'wp-llm-ml' ) );
		?>
	</section>

	<section id="wpllmml-switcher" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['switcher'] ); ?></h2>
		<?php
		$wpllmml_list(
			array(
				__( '<strong>One menu for all languages.</strong> Menu items point to the translation of their page automatically and show its title. Items without a translation link to the original, or are hidden (setting on the Languages tab).', 'wp-llm-ml' ),
				__( '<strong>The language switcher</strong> is added to the menu you choose on the Languages tab, as a dropdown or with all languages side by side. It is also available as an Elementor widget ("Language switcher"), a classic widget and the shortcode <code>[wpllmml_switcher]</code>.', 'wp-llm-ml' ),
				__( 'Links inside pages (buttons, text links) automatically point to the translated page when there is one.', 'wp-llm-ml' ),
			)
		);
		$wpllmml_figure( 'switcher.webp', __( 'The language switcher in the menu.', 'wp-llm-ml' ) );
		?>
	</section>

	<section id="wpllmml-visitors" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['visitors'] ); ?></h2>
		<?php
		$wpllmml_list(
			array(
				__( '<strong>Browser language:</strong> a visitor with a Dutch browser who arrives on the English home page is taken to <code>/nl/</code> once. On other pages a small bar offers the Dutch version. The choice is remembered (a functional cookie, no tracking). Search engines are never redirected.', 'wp-llm-ml' ),
				__( '<strong>Search engines:</strong> each language has its own URLs with <code>hreflang</code> links between them, the right <code>&lt;html lang&gt;</code>, canonical URLs and <code>og:locale</code>; the Yoast sitemaps contain all languages.', 'wp-llm-ml' ),
				__( '<strong>AI and other services:</strong> pages are complete, translated HTML (no translation in the browser). The REST API shows the language and translations of each page; AI agents can use the "Multilingual" abilities.', 'wp-llm-ml' ),
			)
		);
		$wpllmml_figure( 'suggest.webp', __( 'A visitor with a Dutch browser on an English page.', 'wp-llm-ml' ) );
		?>
	</section>

	<section id="wpllmml-ai" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['ai'] ); ?></h2>
		<?php
		$wpllmml_list(
			array(
				__( 'Any OpenAI-compatible service works; the default is HostYourAI (EU): <code>https://hostyourai.com/api/v1</code>.', 'wp-llm-ml' ),
				__( 'Preferably put the key in <code>wp-config.php</code>: <code>define( \'WPLLMML_AI_API_KEY\', \'…\' );</code>. It is then not stored in the database.', 'wp-llm-ml' ),
				__( 'Choose a capable multilingual model with <strong>Load available models</strong>. Larger models translate Dutch and Romanian noticeably better.', 'wp-llm-ml' ),
				__( '<strong>Instructions for translations</strong> are your house style, used for every translation. For example: "Address the reader informally (je/jij). Keep Gestalt and Reiki untranslated. Warm, calm tone. In Romanian use ș and ț with a comma."', 'wp-llm-ml' ),
			)
		);
		$wpllmml_figure( 'ai.webp', __( 'AI settings.', 'wp-llm-ml' ) );
		$wpllmml_note( __( 'Nothing is sent to the AI service unless you click an AI button, or a layout update finds new texts to translate.', 'wp-llm-ml' ) );
		?>
	</section>

	<section id="wpllmml-updates" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['updates'] ); ?></h2>
		<?php
		$wpllmml_p( __( 'New versions appear under <strong>Dashboard → Updates</strong>, like any plugin. After an update the dashboard shows once what is new. Try a new version on the test site first.', 'wp-llm-ml' ) );
		?>
	</section>

	<section id="wpllmml-faq" class="wpllmml-help__section">
		<h2><?php echo esc_html( $wpllmml_sections['faq'] ); ?></h2>
		<dl class="wpllmml-help__faq">
			<dt><?php esc_html_e( 'A language keeps pulsing after I saved the English page.', 'wp-llm-ml' ); ?></dt>
			<dd><?php echo wp_kses( __( 'The update is running or waiting. It starts as soon as Elementor or the page list is open. If it fails, the language turns red: open its menu to read why and click <strong>Update now</strong>.', 'wp-llm-ml' ), $wpllmml_allowed ); ?></dd>
			<dt><?php esc_html_e( 'Visitors see English on the Dutch page.', 'wp-llm-ml' ); ?></dt>
			<dd><?php echo wp_kses( __( 'The translation is still a draft (yellow badge), or that text has not been translated yet. Texts outside the page (menu labels, footer) are on the Texts tab.', 'wp-llm-ml' ), $wpllmml_allowed ); ?></dd>
			<dt><?php esc_html_e( 'Buttons, dates or form messages are in English.', 'wp-llm-ml' ); ?></dt>
			<dd><?php echo wp_kses( __( 'Install the language packs (button on the Languages tab).', 'wp-llm-ml' ), $wpllmml_allowed ); ?></dd>
			<dt><?php esc_html_e( 'The Dutch page shows the English form.', 'wp-llm-ml' ); ?></dt>
			<dd><?php echo wp_kses( __( 'That form has no published Dutch copy yet: see Settings → Languages → Forms.', 'wp-llm-ml' ), $wpllmml_allowed ); ?></dd>
			<dt><?php esc_html_e( 'I changed the layout of a translation and it was changed back.', 'wp-llm-ml' ); ?></dt>
			<dd><?php echo wp_kses( __( 'A setting you change only in the translation stays, until you change that same setting on the English page. For a fully separate layout choose <strong>Keep its own layout</strong>. Elementor → History → Revisions brings back the earlier version.', 'wp-llm-ml' ), $wpllmml_allowed ); ?></dd>
			<dt><?php esc_html_e( '"Text not found" when applying a spelling correction.', 'wp-llm-ml' ); ?></dt>
			<dd><?php echo wp_kses( __( 'The text was changed in the meantime. Run the check again.', 'wp-llm-ml' ), $wpllmml_allowed ); ?></dd>
			<dt><?php esc_html_e( 'An AI translation stops with an error.', 'wp-llm-ml' ); ?></dt>
			<dd><?php echo wp_kses( __( 'The message says why (for example a wrong API key, or the service did not answer). Click <strong>Try again</strong>; nothing was saved.', 'wp-llm-ml' ), $wpllmml_allowed ); ?></dd>
		</dl>
	</section>

	</div>
</div>
