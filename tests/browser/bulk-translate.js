/**
 * Browser test: bulk actions "Translate into … with AI" and "Create …
 * translation (copy)" in the Pages list.
 *
 *   node tests/browser/bulk-translate.js <site-url> [screenshot-dir]
 *
 * Needs the site from tests/run.sh with the mock AI configured, and
 * Playwright (npm install playwright).
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
	const dialogs = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	page.on( 'dialog', ( d ) => {
		dialogs.push( d.message() );
		d.accept();
	} );
	const LIST = BASE + '/wp-admin/edit.php?post_type=page&wpllmml_lang=en';
	const row = ( title ) => page.locator( 'tr', { has: page.locator( 'a.row-title', { hasText: new RegExp( '^' + title + '$' ) } ) } );
	const badge = ( title, lang ) => row( title ).locator( 'details[data-lang="' + lang + '"] > summary' );
	const apply = async ( action, titles ) => {
		for ( const title of titles ) {
			await row( title ).locator( 'input[name="post[]"]' ).check();
		}
		await page.selectOption( '#bulk-action-selector-top', action );
		await page.click( '#doaction' );
	};

	try {
		await page.goto( BASE + '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

		section( 'The bulk actions are offered' );
		await page.goto( LIST );
		const options = await page.$$eval( '#bulk-action-selector-top option', ( list ) => list.map( ( o ) => o.value ) );
		check( 'AI: per language and all', [ 'wpllmml_ai_nl', 'wpllmml_ai_ro', 'wpllmml_ai_all' ].every( ( v ) => options.includes( v ) ), options );
		check( 'copies: per language and all', [ 'wpllmml_copy_nl', 'wpllmml_copy_ro', 'wpllmml_copy_all' ].every( ( v ) => options.includes( v ) ) );

		section( 'Translate two pages into all languages with AI' );
		await apply( 'wpllmml_ai_all', [ 'About me', 'Terms & Conditions' ] );
		await page.locator( '.wpllmml-bulk' ).waitFor( { timeout: 15000 } );
		check( 'asked for confirmation with the count', dialogs.length > 0 && /4 translations/.test( dialogs[ dialogs.length - 1 ] ), dialogs );
		if ( SHOTS ) {
			await page.waitForTimeout( 700 );
			await page.screenshot( { path: SHOTS + '/bulk-running.png', clip: { x: 160, y: 0, width: 1240, height: 560 } } );
		}
		await page.waitForFunction( () => {
			const panel = document.querySelector( '.wpllmml-bulk' );
			return panel && ! panel.classList.contains( 'is-running' );
		}, null, { timeout: 180000 } );
		const summary = await page.locator( '.wpllmml-bulk__status' ).innerText();
		check( 'all four translated', /4 translated/.test( summary ) && /0 failed/.test( summary ), summary );
		check( 'progress shown in the rows', 2 === await row( 'About me' ).locator( '.wpllmml-ai-job' ).count() );
		if ( SHOTS ) {
			await page.screenshot( { path: SHOTS + '/bulk-done.png', clip: { x: 160, y: 0, width: 1240, height: 560 } } );
		}
		await page.goto( LIST );
		for ( const title of [ 'About me', 'Terms & Conditions' ] ) {
			for ( const lang of [ 'nl', 'ro' ] ) {
				const cls = await badge( title, lang ).getAttribute( 'class' );
				check( title + ' ' + lang.toUpperCase() + ' is now a draft translation', /is-draft/.test( cls ), cls );
			}
		}
		await badge( 'About me', 'nl' ).click();
		const preview = await row( 'About me' ).locator( 'details[data-lang="nl"] a', { hasText: 'Preview' } ).getAttribute( 'href' );
		await page.goto( preview );
		check( 'translated by the AI', /NL:About me/.test( await page.title() ), await page.title() );

		section( 'Nothing missing: nothing to do' );
		await page.goto( LIST );
		dialogs.length = 0;
		await apply( 'wpllmml_ai_nl', [ 'About me' ] );
		await page.waitForTimeout( 1500 );
		check( 'says there is nothing to translate', /Nothing to translate/.test( dialogs.join( ' ' ) ), dialogs );
		check( 'no bulk panel', 0 === await page.locator( '.wpllmml-bulk' ).count() );

		section( 'Create copies in bulk (no AI)' );
		await page.goto( LIST );
		await apply( 'wpllmml_copy_ro', [ 'Privacy Policy' ] );
		await page.waitForLoadState( 'load' );
		const notice = await page.locator( '.notice-success' ).first().innerText().catch( () => '' );
		check( 'result notice', /1 draft translations created/.test( notice ), notice );
		check( 'RO draft exists', /is-draft/.test( await badge( 'Privacy Policy', 'ro' ).getAttribute( 'class' ) ) );

		check( 'no JavaScript errors', 0 === errors.length, errors.slice( 0, 3 ) );
	} catch ( e ) {
		check( 'browser flow', false, e.message );
		if ( SHOTS ) {
			await page.screenshot( { path: SHOTS + '/bulk-failure.png' } );
		}
	}
	await browser.close();
	console.log( '\n' + passed + ' passed, ' + failed + ' failed' );
	process.exit( failed ? 1 : 0 );
} )();
