/**
 * Takes the screenshots of the guide (Settings → Languages → How it works).
 * Run by bin/help-screenshots.sh, which prepares the site.
 *
 *   node bin/help-screenshots.js <site-url> <out-dir> <ids.json> <repo> <site-dir>
 */
'use strict';

const { chromium } = require( 'playwright' );
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

const [ BASE, OUT, IDS, REPO, SITE ] = process.argv.slice( 2 );
const ids = require( path.resolve( IDS ) );
const php = ( fixture ) => execFileSync( 'php', [ path.join( REPO, 'tests/bin/wp-eval.php' ), SITE, path.join( REPO, fixture ) ], { stdio: 'inherit' } );

// Only what the guide is about: no notices of other plugins, no test-only admin bar items.
const QUIET = `
	.notice:not(.wpllmml-bulk), .e-notice, .elementor-message, #wp-admin-bar-sqlite-db-integration, #wpfooter, .update-nag { display: none !important; }
`;

( async () => {
	const browser = await chromium.launch();
	const context = await browser.newContext( { viewport: { width: 1280, height: 860 } } );
	const page = await context.newPage();
	page.on( 'dialog', ( d ) => d.accept() );
	const quiet = () => page.addStyleTag( { content: QUIET } );
	const shot = async ( name, clip ) => {
		await page.waitForTimeout( 400 );
		await page.screenshot( { path: path.join( OUT, name + '.png' ), clip } );
		console.log( 'saved ' + name );
	};
	// A clip around elements, with a margin, inside the viewport.
	const around = async ( selectors, margin = 16, extra = {} ) => {
		const boxes = [];
		for ( const selector of selectors ) {
			const box = await page.locator( selector ).first().boundingBox();
			if ( box ) {
				boxes.push( box );
			}
		}
		const x = Math.max( 0, Math.min( ...boxes.map( ( b ) => b.x ) ) - margin );
		const y = Math.max( 0, Math.min( ...boxes.map( ( b ) => b.y ) ) - margin );
		const right = Math.max( ...boxes.map( ( b ) => b.x + b.width ) ) + margin;
		const bottom = Math.max( ...boxes.map( ( b ) => b.y + b.height ) ) + margin;
		const view = page.viewportSize();
		return {
			x,
			y,
			width: Math.min( view.width, extra.width || right ) - x,
			height: Math.min( view.height, extra.height || bottom ) - y,
		};
	};
	const row = ( title ) => page.locator( 'tr', { has: page.locator( 'a.row-title', { hasText: new RegExp( '^' + title + '$' ) } ) } );

	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

	// Settings → Languages.
	await page.goto( BASE + '/wp-admin/options-general.php?page=wpllmml&tab=general' );
	await quiet();
	await shot( 'languages', await around( [ '.wpllmml-wrap h1', '.wpllmml-wrap form' ], 16, { height: 860 } ) );

	// Pages: the menu of a translation that was updated after a layout change.
	await page.setViewportSize( { width: 1280, height: 1500 } );
	await page.goto( BASE + '/wp-admin/edit.php?post_type=page&wpllmml_lang=en' );
	await quiet();
	await row( 'Gestalt Therapy' ).locator( 'details[data-lang="nl"] > summary' ).click();
	await shot( 'pages', await around( [ 'table.wp-list-table thead', 'tr:has(a.row-title:text-is("Gestalt Therapy")) .wpllmml-lang-menu__items' ], 12, { width: 900 } ) );
	await page.setViewportSize( { width: 1280, height: 860 } );

	// Bulk: two pages into all languages (the list searched down to those two).
	await page.goto( BASE + '/wp-admin/edit.php?post_type=page&wpllmml_lang=en&s=' + encodeURIComponent( '"Dutch and Romanian"' ) );
	await quiet();
	for ( const title of [ 'Workshops', 'Retreats' ] ) {
		await row( title ).locator( 'input[name="post[]"]' ).check();
	}
	await page.selectOption( '#bulk-action-selector-top', 'wpllmml_ai_all' );
	await page.click( '#doaction' );
	await page.waitForFunction( () => {
		const panel = document.querySelector( '.wpllmml-bulk' );
		return panel && ! panel.classList.contains( 'is-running' );
	}, null, { timeout: 120000 } );
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await shot( 'bulk', await around( [ '.wpllmml-wrap h1, .wrap h1', '.wpllmml-bulk', 'tr:has(a.row-title:text-is("Workshops"))', 'tr:has(a.row-title:text-is("Retreats"))' ], 12, { width: 960 } ) );

	// Spelling dialog.
	await page.goto( BASE + '/wp-admin/edit.php?post_type=page&wpllmml_lang=en' );
	await quiet();
	await row( 'Coaching' ).hover();
	await row( 'Coaching' ).locator( '.row-actions .wpllmml-spell-open' ).click();
	await page.waitForFunction( () => {
		const status = document.querySelector( 'dialog.wpllmml-spell .wpllmml-spell__status' );
		return status && ! status.classList.contains( 'is-busy' ) && document.querySelector( '.wpllmml-spell__item' );
	}, null, { timeout: 60000 } );
	await shot( 'spelling', await around( [ 'dialog.wpllmml-spell' ], 24 ) );
	await page.keyboard.press( 'Escape' );

	// Forms.
	await page.goto( BASE + '/wp-admin/options-general.php?page=wpllmml&tab=forms' );
	await quiet();
	await shot( 'forms', await around( [ '.wpllmml-wrap h1', '.wpllmml-forms__table' ], 16 ) );

	// Texts.
	await page.goto( BASE + '/wp-admin/options-general.php?page=wpllmml&tab=strings' );
	await quiet();
	await shot( 'texts', await around( [ '.wpllmml-wrap h1', '.wpllmml-strings tbody tr:nth-child(3)' ], 16 ) );

	// Elementor: the top bar with the menu of a missing language.
	await page.goto( BASE + '/wp-admin/post.php?post=' + ids.reiki + '&action=elementor', { waitUntil: 'load' } );
	await page.waitForFunction( () => 'ready' === window.wpllmmlEditorSwitcher && document.querySelector( '[data-wpllmml-switch="RO"]' ), null, { timeout: 90000 } );
	await page.waitForTimeout( 2500 );
	// Other plugins' tips in the editor (Yoast) are closed first.
	for ( const button of await page.locator( 'button:has-text("Got it")' ).all() ) {
		await button.click().catch( () => {} );
	}
	await page.addStyleTag( { content: '.yoast-elementor-introduction, [class*="introduction"] { display: none !important; }' } );
	await page.click( '[data-wpllmml-switch="RO"]' );
	await page.locator( '[data-wpllmml-action="create-ai"]' ).waitFor();
	await shot( 'elementor', { x: 0, y: 0, width: 1280, height: 300 } );

	// Front end, as a visitor (not logged in): the switcher in the menu.
	const guest = await ( await browser.newContext( { viewport: { width: 1280, height: 860 } } ) ).newPage();
	await guest.goto( BASE + '/' );
	await guest.locator( '.main-navigation li.wpllmml-menu-item' ).first().hover();
	await guest.waitForTimeout( 600 );
	await guest.screenshot( { path: path.join( OUT, 'switcher.png' ), clip: { x: 0, y: 0, width: 1280, height: 270 } } );
	console.log( 'saved switcher' );
	// The bar is never shown to bots and automated browsers: look like a person.
	const dutch = await browser.newContext( {
		viewport: { width: 1280, height: 440 },
		locale: 'nl-NL',
		userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
		extraHTTPHeaders: { 'Accept-Language': 'nl-NL,nl;q=0.9' },
	} );
	await dutch.addInitScript( () => Object.defineProperty( navigator, 'webdriver', { get: () => false } ) );
	const visitor = await dutch.newPage();
	await visitor.goto( BASE + '/reiki/' );
	await visitor.locator( '.wpllmml-suggest' ).waitFor( { timeout: 15000 } );
	await visitor.waitForTimeout( 500 );
	await visitor.screenshot( { path: path.join( OUT, 'suggest.png' ) } );
	console.log( 'saved suggest' );

	// AI settings, shown with a real provider.
	php( 'tests/fixtures/help/ai-settings.php' );
	await page.goto( BASE + '/wp-admin/options-general.php?page=wpllmml&tab=ai' );
	await quiet();
	await shot( 'ai', await around( [ '.wpllmml-wrap h1', '.wpllmml-wrap form' ], 16 ) );

	await browser.close();
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
