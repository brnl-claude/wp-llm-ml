/**
 * Browser test: the spell check dialog in the Pages list.
 *
 *   node tests/browser/spellcheck.js <site-url> [screenshot-dir]
 *
 * Needs the site from tests/run.sh with the mock AI configured and
 * tests/fixtures/spell-typo.php applied, and Playwright.
 */
'use strict';

const { chromium } = require( 'playwright' );

const BASE = process.argv[ 2 ] || 'http://localhost:8890';
const SHOTS = process.argv[ 3 ] || '';

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
	const page = await ( await browser.newContext( { viewport: { width: 1400, height: 900 } } ) ).newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	const LIST = BASE + '/wp-admin/edit.php?post_type=page&wpllmml_lang=en';
	const row = ( title ) => page.locator( 'tr', { has: page.locator( 'a.row-title', { hasText: new RegExp( '^' + title + '$' ) } ) } );
	const dialog = page.locator( 'dialog.wpllmml-spell' );
	const finished = () => page.waitForFunction( () => {
		const status = document.querySelector( 'dialog.wpllmml-spell .wpllmml-spell__status' );
		return status && ! status.classList.contains( 'is-busy' );
	}, null, { timeout: 60000 } );

	try {
		await page.goto( BASE + '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

		section( 'Row action "Check spelling" opens the dialog' );
		await page.goto( LIST );
		await row( 'Coaching' ).hover();
		await row( 'Coaching' ).locator( '.row-actions .wpllmml-spell-open' ).click();
		await dialog.waitFor( { state: 'visible', timeout: 10000 } );
		await finished();
		check( 'dialog open with the page and language', /Coaching/.test( await dialog.locator( '.wpllmml-spell__title' ).innerText() ) && 'English' === ( await dialog.locator( '.wpllmml-spell__lang' ).innerText() ).trim() );
		const item = dialog.locator( '.wpllmml-spell__item' ).first();
		check( 'one suggestion', 1 === await dialog.locator( '.wpllmml-spell__item' ).count() );
		check( 'where it is', 'TEXT EDITOR' === ( await item.locator( '.wpllmml-spell__where' ).innerText() ).trim().toUpperCase() );
		const context = await item.locator( '.wpllmml-spell__context' ).innerText();
		check( 'shown in its sentence', /Welcom to\s*Welcome to "Coaching"\. I offer sessions/.test( context ), context );
		check( 'mistake and correction marked', 'Welcom to' === await item.locator( 'del' ).innerText() && 'Welcome to' === await item.locator( 'ins' ).innerText() );
		check( 'explanation', /Spelling/.test( await item.locator( '.wpllmml-spell__why' ).innerText() ) );
		const box = await dialog.boundingBox();
		check( 'roomy dialog', box && box.width >= 800, box );
		if ( SHOTS ) {
			await page.screenshot( { path: SHOTS + '/spell-dialog.png' } );
		}

		section( 'Apply' );
		await item.locator( '.wpllmml-spell__apply' ).click();
		await page.waitForFunction( () => document.querySelector( 'dialog.wpllmml-spell .wpllmml-spell__item.is-applied' ), null, { timeout: 15000 } );
		check( 'marked as applied', /Applied/.test( await item.locator( '.wpllmml-spell__state' ).innerText() ) );
		if ( SHOTS ) {
			await page.screenshot( { path: SHOTS + '/spell-applied.png' } );
		}
		await dialog.locator( '.wpllmml-spell__close' ).click();
		check( 'closed', ! await dialog.isVisible() );
		check( 'list not reloaded', page.url() === LIST );
		const front = await ( await page.request.get( BASE + '/coaching/' ) ).text();
		check( 'corrected on the site', front.includes( 'Welcome to “Coaching”' ) || front.includes( 'Welcome to &#8220;Coaching&#8221;' ) || front.includes( 'Welcome to "Coaching"' ) );

		section( 'Nothing left' );
		await row( 'Coaching' ).hover();
		await row( 'Coaching' ).locator( '.row-actions .wpllmml-spell-open' ).click();
		await finished();
		check( 'no errors found', /No spelling or grammar errors found in \d+ texts/.test( await dialog.locator( '.wpllmml-spell__status' ).innerText() ) );
		await page.keyboard.press( 'Escape' );
		check( 'Escape closes', ! await dialog.isVisible() );

		section( 'From a language menu: check the translation' );
		await row( 'Gestalt Therapy' ).locator( 'details[data-lang="nl"] > summary' ).click();
		await row( 'Gestalt Therapy' ).locator( 'details[data-lang="nl"] .wpllmml-spell-open' ).click();
		await dialog.waitFor( { state: 'visible' } );
		await finished();
		check( 'the Dutch version is checked', 'Nederlands' === ( await dialog.locator( '.wpllmml-spell__lang' ).innerText() ).trim() );
		await dialog.locator( '.wpllmml-spell__x' ).click();

		check( 'no JavaScript errors', 0 === errors.length, errors.slice( 0, 3 ) );
	} catch ( e ) {
		check( 'browser flow', false, e.message );
		if ( SHOTS ) {
			await page.screenshot( { path: SHOTS + '/spell-failure.png' } );
		}
	}
	await browser.close();
	console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
	process.exit( failed ? 1 : 0 );
} )();
