/**
 * Browser test: the language switcher in the Elementor editor's top bar and
 * the layout sync behind it.
 *
 *   node tests/browser/elementor-switcher.js <site-url> <ids.json> [screenshot-dir]
 *
 * Needs the site from tests/run.sh (model tests done, so ids.json exists),
 * the mock AI configured, and Playwright (npm install playwright).
 */
'use strict';

const { chromium } = require( 'playwright' );

const BASE = process.argv[ 2 ] || 'http://localhost:8890';
const ids = require( require( 'path' ).resolve( process.argv[ 3 ] ) );
const SHOTS = process.argv[ 4 ] || '';

let passed = 0;
let failed = 0;
function check( name, ok, detail ) {
	if ( ok ) {
		passed++;
		console.log( '  ok   ' + name );
	} else {
		failed++;
		console.log( '  FAIL ' + name + ( undefined !== detail ? ' — ' + JSON.stringify( detail ) : '' ) );
	}
}
function section( name ) {
	console.log( '\n## ' + name );
}

( async () => {
	const browser = await chromium.launch();
	const page = await ( await browser.newContext( { viewport: { width: 1400, height: 850 } } ) ).newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	page.on( 'console', ( m ) => {
		if ( 'error' === m.type() && /wpllmml|LLM Multilingual|elementor-editor\.js/i.test( m.text() ) ) {
			errors.push( m.text() );
		}
	} );
	page.on( 'dialog', ( d ) => d.accept() );

	const docId = () => page.evaluate( () => ( window.elementor && elementor.documents.getCurrent() ? elementor.documents.getCurrent().id : 0 ) );
	const waitDoc = ( id, timeout ) => page.waitForFunction( ( want ) => window.elementor && elementor.documents.getCurrent() && elementor.documents.getCurrent().id === want, id, { timeout: timeout || 30000 } );
	const button = ( label ) => page.locator( '[data-wpllmml-switch="' + label + '"]' );
	const previewLang = () => page.frameLocator( '#elementor-preview-iframe' ).locator( 'html' ).getAttribute( 'lang' );
	const shot = async ( name ) => {
		if ( SHOTS ) {
			await page.screenshot( { path: SHOTS + '/' + name + '.png', clip: { x: 0, y: 0, width: 1400, height: 330 } } );
		}
	};

	try {
		await page.goto( BASE + '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

		section( 'The switcher sits in the Elementor top bar' );
		await page.goto( BASE + '/wp-admin/post.php?post=' + ids.reiki + '&action=elementor', { waitUntil: 'load' } );
		await page.waitForFunction( () => window.elementor && elementor.documents && elementor.documents.getCurrent() && window.wpllmmlEditorSwitcher, null, { timeout: 90000 } );
		const state = await page.evaluate( () => window.wpllmmlEditorSwitcher );
		check( 'switcher registered', 'ready' === state, state );
		await button( 'EN' ).waitFor( { timeout: 30000 } );
		check( 'EN active', 'page' === await button( 'EN' ).getAttribute( 'aria-current' ) );
		check( 'NL available', 1 === await button( 'NL' ).count() && null === await button( 'NL' ).getAttribute( 'aria-current' ) );
		check( 'RO offered as missing', '+RO' === ( await button( 'RO' ).innerText() ).trim() );
		const inBar = await page.evaluate( () => {
			const el = document.querySelector( '[data-wpllmml-switch="EN"]' );
			return !! el && el.getBoundingClientRect().top < 60;
		} );
		check( 'shown in the top bar', inBar );
		await shot( 'editor-switcher-en' );

		section( 'Switching opens the other language in the same editor' );
		const t0 = Date.now();
		await button( 'NL' ).click();
		await waitDoc( ids.nl_reiki );
		await page.waitForTimeout( 1500 );
		check( 'NL document open (' + ( Date.now() - t0 ) + ' ms)', ids.nl_reiki === await docId() );
		check( 'address bar follows', page.url().includes( 'post=' + ids.nl_reiki ) );
		check( 'preview is Dutch', /^nl/.test( ( await previewLang() ) || '' ), await previewLang() );
		check( 'NL now active', 'page' === await button( 'NL' ).getAttribute( 'aria-current' ) );
		await shot( 'editor-switcher-nl' );
		await button( 'EN' ).click();
		await waitDoc( ids.reiki );
		check( 'back to EN', ids.reiki === await docId() );

		section( 'Saving the English page updates the Dutch layout in the background' );
		await page.waitForTimeout( 1000 );
		const headingId = await page.frameLocator( '#elementor-preview-iframe' ).locator( '.elementor-widget-heading' ).first().getAttribute( 'data-id' );
		await page.evaluate( ( id ) => {
			const container = elementor.getContainer( id );
			$e.run( 'document/elements/settings', { container, settings: { title: 'A heading added in the editor', header_size: 'h3' }, options: { external: true } } );
			return $e.run( 'document/save/update' );
		}, headingId );
		await page.waitForFunction( () => window.elementor && ! elementor.documents.getCurrent().editor.isChanged, null, { timeout: 30000 } );
		await page.waitForFunction( () => {
			const nl = document.querySelector( '[data-wpllmml-switch="NL"]' );
			return nl && nl.classList.contains( 'has-review' ) && ! nl.classList.contains( 'is-busy' );
		}, null, { timeout: 90000 } ).catch( () => {} );
		const nlClass = await button( 'NL' ).getAttribute( 'class' );
		check( 'NL marked for review after the sync', /has-review/.test( nlClass ) && ! /is-busy/.test( nlClass ), nlClass );
		check( 'tooltip explains', /translated with AI/.test( ( await button( 'NL' ).getAttribute( 'title' ) ) || '' ) );
		await shot( 'editor-switcher-review' );
		await button( 'NL' ).click();
		await page.waitForTimeout( 500 );
		check( 'review menu shown', 1 === await page.locator( '[data-wpllmml-action="switch"]' ).count() );
		await page.locator( '[data-wpllmml-action="switch"]' ).click();
		await waitDoc( ids.nl_reiki );
		await page.waitForTimeout( 1500 );
		const nlHeading = page.frameLocator( '#elementor-preview-iframe' ).locator( '.elementor-element-' + headingId + ' .elementor-heading-title' );
		check( 'new text translated', 'NL:A heading added in the editor' === ( await nlHeading.innerText() ).trim(), await nlHeading.innerText() );
		check( 'layout change applied', 'h3' === await nlHeading.evaluate( ( el ) => el.tagName.toLowerCase() ) );
		await button( 'NL' ).click();
		await page.locator( '[data-wpllmml-action="reviewed"]' ).click();
		await page.waitForFunction( () => ! document.querySelector( '[data-wpllmml-switch="NL"]' ).classList.contains( 'has-review' ), null, { timeout: 15000 } );
		check( 'marked as reviewed', ! /has-review/.test( await button( 'NL' ).getAttribute( 'class' ) ) );

		section( 'A missing language: create & translate with AI from the top bar' );
		await button( 'RO' ).click();
		await page.locator( '[data-wpllmml-action="create-ai"]' ).waitFor( { timeout: 10000 } );
		check( 'menu offers AI and copy', 1 === await page.locator( '[data-wpllmml-action="create-copy"]' ).count() );
		await page.waitForTimeout( 600 );
		await shot( 'editor-switcher-menu' );
		await page.locator( '[data-wpllmml-action="create-ai"]' ).click();
		await page.waitForFunction( ( known ) => {
			const doc = window.elementor && elementor.documents.getCurrent();
			return doc && known.indexOf( doc.id ) === -1;
		}, [ ids.reiki, ids.nl_reiki ], { timeout: 90000 } );
		await page.waitForTimeout( 1500 );
		check( 'Romanian version created and opened', 'page' === await button( 'RO' ).getAttribute( 'aria-current' ) && 'RO' === ( await button( 'RO' ).innerText() ).trim() );
		check( 'preview is Romanian', /^ro/.test( ( await previewLang() ) || '' ), await previewLang() );
		const roText = await page.frameLocator( '#elementor-preview-iframe' ).locator( '.elementor-element-' + headingId + ' .elementor-heading-title' ).innerText();
		check( 'translated', 0 === roText.indexOf( 'RO:' ), roText );

		section( 'Pages outside a translation group show no switcher' );
		check( 'no JavaScript errors', 0 === errors.length, errors.slice( 0, 3 ) );
	} catch ( e ) {
		check( 'browser flow', false, e.message );
		await shot( 'editor-switcher-failure' );
	}
	await browser.close();
	console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
	process.exit( failed ? 1 : 0 );
} )();
