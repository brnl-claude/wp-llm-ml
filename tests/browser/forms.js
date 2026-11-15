/**
 * Browser test: Settings → Languages → Forms (Forminator forms per language).
 *
 *   node tests/browser/forms.js <site-url> [screenshot-dir]
 *
 * Needs the site from tests/run.sh with the mock AI configured and
 * tests/fixtures/forms-page.php applied, and Playwright.
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
	const page = await ( await browser.newContext( { viewport: { width: 1300, height: 800 } } ) ).newPage();
	const errors = [];
	const dialogs = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	page.on( 'dialog', ( d ) => {
		dialogs.push( d.message() );
		d.accept();
	} );
	const TAB = BASE + '/wp-admin/options-general.php?page=wpllmml&tab=forms';
	const row = () => page.locator( '.wpllmml-forms__table tr', { has: page.locator( 'a', { hasText: /^Contact form test$/ } ) } );
	const cell = ( lang ) => row().locator( '.wpllmml-forms__cell[data-lang="' + lang + '"]' );

	try {
		await page.goto( BASE + '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

		section( 'The Forms tab lists the Forminator forms' );
		await page.goto( TAB );
		check( 'tab present', 1 === await page.locator( '.nav-tab-active', { hasText: 'Forms' } ).count() );
		check( 'form listed with its shortcode', 1 === await row().count() && /\[forminator_form id="\d+"\]/.test( await row().innerText() ) );
		check( 'a column per other language', 1 === await cell( 'nl' ).count() && 1 === await cell( 'ro' ).count() );

		section( 'Create & translate with AI' );
		await cell( 'nl' ).locator( '.wpllmml-form-ai' ).click();
		await page.waitForFunction( () => {
			const td = document.querySelector( '.wpllmml-forms__cell[data-lang="nl"]' );
			return td && td.querySelector( '.wpllmml-forms__done' );
		}, null, { timeout: 60000 } );
		check( 'link to the Dutch copy in Forminator', /Contact form test \(NL\)/.test( await cell( 'nl' ).innerText() ) && /forminator-cform-wizard&id=\d+/.test( await cell( 'nl' ).locator( 'a' ).getAttribute( 'href' ) ) );
		if ( SHOTS ) {
			await page.screenshot( { path: SHOTS + '/forms-tab.png' } );
		}

		section( 'The Dutch page shows the Dutch form' );
		const nl = await ( await page.request.get( BASE + '/nl/contact-form-test/' ) ).text();
		const en = await ( await page.request.get( BASE + '/contact-form-test/' ) ).text();
		check( 'Dutch labels and button', nl.includes( 'NL:First Name' ) && nl.includes( 'NL:Send' ) );
		check( 'English page unchanged', en.includes( 'First Name' ) && ! en.includes( 'NL:First Name' ) );

		section( 'Translate again' );
		await page.goto( TAB );
		await cell( 'nl' ).locator( '.wpllmml-form-ai[data-again]' ).click();
		check( 'asks before overwriting', dialogs.some( ( m ) => /overwritten/.test( m ) ) );
		await page.waitForFunction( () => document.querySelector( '.wpllmml-forms__cell[data-lang="nl"] .wpllmml-forms__done' ), null, { timeout: 60000 } );
		check( 'done again, same copy', /Contact form test \(NL\)/.test( await cell( 'nl' ).innerText() ) );
		check( 'Romanian not touched', 1 === await cell( 'ro' ).locator( '.wpllmml-form-ai' ).count() );

		check( 'no JavaScript errors', 0 === errors.length, errors.slice( 0, 3 ) );
	} catch ( e ) {
		check( 'browser flow', false, e.message );
		if ( SHOTS ) {
			await page.screenshot( { path: SHOTS + '/forms-failure.png' } );
		}
	}
	await browser.close();
	console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
	process.exit( failed ? 1 : 0 );
} )();
