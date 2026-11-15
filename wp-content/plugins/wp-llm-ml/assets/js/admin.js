/* LLM Multilingual — admin interactions (no dependencies). */
( function () {
	'use strict';
	var cfg = window.wpllmmlAdmin;
	if ( ! cfg ) {
		return;
	}

	// A gateway timeout answers with an HTML page: report its status instead of a parse error.
	function readJson( r ) {
		return r.json().catch( function () {
			throw new Error( 'HTTP ' + r.status + ( r.statusText ? ' ' + r.statusText : '' ) );
		} );
	}

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( '_ajax_nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( readJson );
	}

	function el( tag, text, cls ) {
		var node = document.createElement( tag );
		if ( text ) {
			node.textContent = text;
		}
		if ( cls ) {
			node.className = cls;
		}
		return node;
	}

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return template.replace( /%(\d+)\$[sd]/g, function ( m, n ) {
			return String( args[ n - 1 ] );
		} ).replace( /%[sd]/, function () {
			return String( args[ 0 ] );
		} );
	}

	// Long-running actions (AI translation, language packs): confirm where
	// needed, then show that something is happening and block double clicks.
	function showBusy( el ) {
		if ( el.getAttribute( 'data-wpllmml-busy' ) ) {
			return;
		}
		el.setAttribute( 'data-wpllmml-busy', '1' );
		el.setAttribute( 'aria-disabled', 'true' );
		el.classList.add( 'wpllmml-is-busy' );
		var note = el.ownerDocument.createElement( 'span' );
		note.className = 'wpllmml-busy-note';
		note.setAttribute( 'role', 'status' );
		var spinner = el.ownerDocument.createElement( 'span' );
		spinner.className = 'spinner is-active';
		note.appendChild( spinner );
		note.appendChild( document.createTextNode( cfg.i18n.busy ) );
		el.insertAdjacentElement( 'afterend', note );
	}
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest && e.target.closest( '.wpllmml-confirm, .wpllmml-busy' );
		if ( ! el ) {
			return;
		}
		if ( el.getAttribute( 'data-wpllmml-busy' ) ) {
			e.preventDefault(); // Already running.
			return;
		}
		if ( el.classList.contains( 'wpllmml-confirm' ) && ! window.confirm( cfg.i18n.confirmAi ) ) {
			e.preventDefault();
			return;
		}
		if ( el.classList.contains( 'wpllmml-busy' ) ) {
			if ( 'BUTTON' === el.tagName || 'INPUT' === el.tagName ) {
				// Let the form submit first, then mark the button.
				window.setTimeout( function () {
					showBusy( el );
				}, 0 );
			} else {
				showBusy( el );
			}
		}
	} );

	// Texts tab: fill empty fields with AI in small batches, with progress.
	var running = false;
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( running ) {
			e.preventDefault();
			e.returnValue = cfg.i18n.leave;
			return cfg.i18n.leave;
		}
	} );
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest && e.target.closest( '[data-wpllmml-ai-lang]' );
		if ( ! button ) {
			return;
		}
		e.preventDefault();
		if ( running ) {
			return;
		}
		var lang = button.getAttribute( 'data-wpllmml-ai-lang' );
		var name = button.getAttribute( 'data-wpllmml-lang-name' ) || lang;
		var box = document.querySelector( '.wpllmml-ai-progress' );
		var bar = box.querySelector( 'progress' );
		var status = box.querySelector( '.wpllmml-ai-status' );
		var spinner = box.querySelector( '.spinner' );
		var fields = Array.prototype.filter.call(
			document.querySelectorAll( 'textarea[data-lang="' + lang + '"]' ),
			function ( t ) {
				return '' === t.value.trim();
			}
		);
		box.hidden = false;
		if ( ! fields.length ) {
			bar.hidden = true;
			spinner.classList.remove( 'is-active' );
			status.textContent = format( cfg.i18n.nothing, name );
			return;
		}
		var byKey = {};
		var keys = fields.map( function ( t ) {
			byKey[ t.getAttribute( 'data-key' ) ] = t;
			return t.getAttribute( 'data-key' );
		} );
		var total = keys.length;
		var done = 0;
		var size = 8;
		var buttons = document.querySelectorAll( '[data-wpllmml-ai-lang]' );
		running = true;
		Array.prototype.forEach.call( buttons, function ( b ) {
			b.disabled = true;
		} );
		bar.hidden = false;
		bar.max = total;
		bar.value = 0;
		spinner.classList.add( 'is-active' );
		status.textContent = format( cfg.i18n.progress, 0, total, name );

		function finish( message ) {
			running = false;
			spinner.classList.remove( 'is-active' );
			Array.prototype.forEach.call( buttons, function ( b ) {
				b.disabled = false;
			} );
			status.textContent = message;
		}
		function next() {
			if ( ! keys.length ) {
				finish( format( cfg.i18n.done, done, name ) );
				return;
			}
			var batch = keys.splice( 0, size );
			var data = { lang: lang };
			var body = new FormData();
			body.append( 'action', 'wpllmml_ai_strings_batch' );
			body.append( '_ajax_nonce', cfg.nonce );
			body.append( 'lang', data.lang );
			batch.forEach( function ( k ) {
				body.append( 'keys[]', k );
			} );
			fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( readJson )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						throw new Error( res && res.data ? res.data : cfg.i18n.error );
					}
					Object.keys( res.data.translations ).forEach( function ( k ) {
						if ( byKey[ k ] ) {
							byKey[ k ].value = res.data.translations[ k ];
							byKey[ k ].classList.add( 'wpllmml-filled' );
						}
					} );
					done += batch.length;
					bar.value = done;
					status.textContent = format( cfg.i18n.progress, done, total, name );
					next();
				} )
				.catch( function ( err ) {
					finish( format( cfg.i18n.failed, err && err.message ? err.message : cfg.i18n.error, done ) );
				} );
		}
		next();
	} );

	// Page translation with AI: the server translates the whole page in the
	// background (one request to the AI, so it keeps the context); this page
	// polls every 2 seconds. The polls are the heartbeat: when they stop, or on
	// Cancel / leaving the page, the server stops too and saves nothing.
	// Several pages can be translated at once (one job per page).
	var activeJobs = {};
	function cancelJob( job, beacon ) {
		var data = new FormData();
		data.append( 'action', 'wpllmml_ai_post_cancel' );
		data.append( '_ajax_nonce', cfg.nonce );
		data.append( 'post', job.post );
		data.append( 'token', job.token );
		if ( beacon && navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.ajax, data );
		} else {
			fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: data, keepalive: true } );
		}
	}
	function jobsRunning() {
		return Object.keys( activeJobs ).length > 0;
	}
	window.addEventListener( 'pagehide', function () {
		Object.keys( activeJobs ).forEach( function ( token ) {
			cancelJob( activeJobs[ token ], true );
		} );
	} );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( jobsRunning() ) {
			e.preventDefault();
			e.returnValue = cfg.i18n.leave;
			return cfg.i18n.leave;
		}
	} );

	function clock( seconds ) {
		var m = Math.floor( seconds / 60 );
		var sec = seconds % 60;
		return m + ':' + ( sec < 10 ? '0' : '' ) + sec;
	}
	function link( href, text ) {
		var a = document.createElement( 'a' );
		a.href = href;
		a.textContent = text;
		return a;
	}

	// Close language menus when clicking elsewhere, one menu open at a time.
	document.addEventListener( 'click', function ( e ) {
		Array.prototype.forEach.call( document.querySelectorAll( 'details.wpllmml-lang-menu[open]' ), function ( d ) {
			if ( ! d.contains( e.target ) ) {
				d.open = false;
			}
		} );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			Array.prototype.forEach.call( document.querySelectorAll( 'details.wpllmml-lang-menu[open]' ), function ( d ) {
				d.open = false;
				d.querySelector( 'summary' ).focus();
			} );
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest && e.target.closest( '.wpllmml-ai-post' );
		if ( ! el || e.defaultPrevented ) {
			return;
		}
		e.preventDefault();
		runJob( el );
	} );

	// One page translation job, shown next to the button (or in its list row).
	// onEnd( 'done' | 'cancelled' | 'error' | 'busy' ) is called once.
	function runJob( el, onEnd ) {
		var ended = false;
		function end( outcome ) {
			if ( ! ended ) {
				ended = true;
				( onEnd || function () {} )( outcome );
			}
		}
		if ( el.getAttribute( 'data-wpllmml-running' ) ) {
			end( 'busy' );
			return;
		}
		var isList = 'list' === el.getAttribute( 'data-context' );
		var menu = el.closest( 'details.wpllmml-lang-menu' );
		var badge = menu ? menu.querySelector( 'summary' ) : null;
		if ( menu ) {
			menu.open = false;
		}
		var listHost = el.closest( '[data-wpllmml-job-host]' );
		var host = listHost || el.closest( 'li, p' ) || el;
		var lang = el.getAttribute( 'data-lang' ) || '';
		var old = host.querySelector( '.wpllmml-ai-job[data-lang="' + lang + '"]' ) || ( ! listHost && host.parentNode.querySelector( '.wpllmml-ai-job' ) );
		if ( old ) {
			old.parentNode.removeChild( old );
		}
		var box = document.createElement( 'div' );
		box.className = 'wpllmml-ai-job' + ( isList ? ' is-compact' : '' );
		box.setAttribute( 'data-lang', lang );
		box.innerHTML = '<progress></progress><div class="wpllmml-ai-job__line"><span class="spinner is-active"></span><span class="wpllmml-ai-status" role="status" aria-live="polite"></span></div><div class="wpllmml-ai-job__actions"><button type="button" class="button button-small wpllmml-ai-cancel"></button></div>';
		if ( listHost ) {
			listHost.appendChild( box );
		} else {
			host.insertAdjacentElement( 'afterend', box );
		}
		var spinner = box.querySelector( '.spinner' );
		var status = box.querySelector( '.wpllmml-ai-status' );
		var bar = box.querySelector( 'progress' );
		var cancelButton = box.querySelector( '.wpllmml-ai-cancel' );
		cancelButton.textContent = cfg.i18n.cancel;
		var job = null;
		var timer = null;
		var since = Date.now();
		var prefix = lang ? lang.toUpperCase() + ': ' : '';

		function busy( on ) {
			if ( on ) {
				el.setAttribute( 'data-wpllmml-running', '1' );
			} else {
				el.removeAttribute( 'data-wpllmml-running' );
			}
			el.classList.toggle( 'wpllmml-is-busy', on );
			el.setAttribute( 'aria-disabled', on ? 'true' : 'false' );
			if ( badge ) {
				badge.classList.toggle( 'is-working', on );
			}
			spinner.classList.toggle( 'is-active', on );
			cancelButton.hidden = ! on;
			bar.hidden = ! on;
		}
		function forget() {
			window.clearTimeout( timer );
			if ( job ) {
				delete activeJobs[ job.token ];
			}
		}
		function stop( message, canRetry ) {
			forget();
			busy( false );
			end( message === cfg.i18n.cancelled ? 'cancelled' : 'error' );
			status.textContent = prefix + message + ' ';
			if ( canRetry ) {
				var again = document.createElement( 'button' );
				again.type = 'button';
				again.className = 'button button-small';
				again.textContent = cfg.i18n.retry;
				again.addEventListener( 'click', function () {
					box.parentNode.removeChild( box );
					el.click();
				} );
				status.appendChild( again );
			}
		}
		function finishInList( d ) {
			forget();
			busy( false );
			end( 'done' );
			status.textContent = prefix + cfg.i18n.listDone + ' ';
			if ( d.editor && d.editor !== d.edit ) {
				status.appendChild( link( d.editor, cfg.i18n.editElementor ) );
				status.appendChild( document.createTextNode( ' · ' ) );
			}
			status.appendChild( link( d.edit, cfg.i18n.edit ) );
			if ( d.preview ) {
				status.appendChild( document.createTextNode( ' · ' ) );
				status.appendChild( link( d.preview, cfg.i18n.preview ) );
			}
			if ( badge && badge.classList.contains( 'is-missing' ) ) {
				// The "+NL" badge now stands for a draft translation.
				badge.classList.remove( 'is-missing' );
				badge.classList.add( 'is-draft' );
				badge.textContent = lang.toUpperCase();
				var items = menu.querySelector( '.wpllmml-lang-menu__items' );
				items.textContent = '';
				if ( d.editor && d.editor !== d.edit ) {
					items.appendChild( link( d.editor, cfg.i18n.editElementor ) );
				}
				items.appendChild( link( d.edit, cfg.i18n.edit ) );
				if ( d.preview ) {
					items.appendChild( link( d.preview, cfg.i18n.preview ) );
				}
			}
		}
		function call( action, data ) {
			return post( action, data ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					throw new Error( res && res.data ? res.data : cfg.i18n.error );
				}
				return res.data;
			} );
		}
		function poll() {
			call( 'wpllmml_ai_post_status', { post: job.post, token: job.token, context: isList ? 'list' : 'editor' } ).then( function ( d ) {
				if ( ! activeJobs[ job.token ] ) {
					return;
				}
				if ( 'done' === d.status ) {
					if ( isList ) {
						finishInList( d );
					} else {
						forget();
						end( 'done' );
						status.textContent = cfg.i18n.opening;
						window.location.href = d.redirect;
					}
					return;
				}
				if ( 'cancelled' === d.status ) {
					stop( cfg.i18n.cancelled, true );
					return;
				}
				if ( 'error' === d.status ) {
					stop( format( cfg.i18n.stopped, d.message || cfg.i18n.error ), true );
					return;
				}
				var elapsed = clock( Math.round( ( Date.now() - since ) / 1000 ) );
				if ( d.total > 1 && d.done < d.total ) {
					bar.max = d.total;
					bar.value = d.done;
					status.textContent = prefix + format( cfg.i18n.postProgressParts, elapsed, d.done, d.total );
				} else {
					status.textContent = prefix + format( isList ? cfg.i18n.listProgress : cfg.i18n.postProgress, elapsed );
				}
				timer = window.setTimeout( poll, 2000 );
			} ).catch( function () {
				// A failed poll (network hiccup) is retried; the server keeps going.
				if ( job && activeJobs[ job.token ] ) {
					timer = window.setTimeout( poll, 3000 );
				}
			} );
		}

		cancelButton.addEventListener( 'click', function () {
			if ( job ) {
				cancelJob( job, false );
			}
			stop( cfg.i18n.cancelled, true );
		} );
		el.wpllmmlCancel = function () {
			if ( ! ended ) {
				cancelButton.click();
			}
		};

		busy( true );
		status.textContent = prefix + cfg.i18n.preparing;
		var data = { mode: el.getAttribute( 'data-mode' ) };
		if ( 'create' === data.mode ) {
			data.source = el.getAttribute( 'data-source' );
			data.lang = lang;
		} else {
			data.post = el.getAttribute( 'data-post' );
		}
		call( 'wpllmml_ai_post_start', data ).then( function ( d ) {
			job = { post: d.post, token: d.token };
			activeJobs[ d.token ] = job;
			since = Date.now();
			// Start the work on the server; its answer is not needed (it may
			// even time out at a proxy while the server keeps translating).
			post( 'wpllmml_ai_post_run', { post: d.post, token: d.token } ).catch( function () {} );
			timer = window.setTimeout( poll, 1500 );
		} ).catch( function ( err ) {
			stop( format( cfg.i18n.stopped, err.message ), true );
		} );
	}

	// Bulk action "Translate into … with AI" in the post lists: the server
	// lists what is missing, then the pages are translated two at a time,
	// each with the same background job (and progress in its row) as one
	// started from the language menu. Copies are a normal bulk action.
	var BULK_PARALLEL = 2;
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest && e.target.closest( '#doaction, #doaction2' );
		if ( ! button ) {
			return;
		}
		var select = document.getElementById( 'doaction2' === button.id ? 'bulk-action-selector-bottom' : 'bulk-action-selector-top' );
		var match = select && /^wpllmml_ai_([a-z0-9_-]+)$/.exec( select.value );
		if ( ! match ) {
			return;
		}
		e.preventDefault();
		if ( document.querySelector( '.wpllmml-bulk.is-running' ) ) {
			return;
		}
		var ids = Array.prototype.map.call( document.querySelectorAll( '#the-list input[name="post[]"]:checked' ), function ( box ) {
			return box.value;
		} );
		if ( ! ids.length ) {
			window.alert( cfg.i18n.bulkNone ); // eslint-disable-line no-alert
			return;
		}
		var body = new FormData();
		body.append( 'action', 'wpllmml_bulk_plan' );
		body.append( '_ajax_nonce', cfg.nonce );
		body.append( 'target', match[ 1 ] );
		ids.forEach( function ( id ) {
			body.append( 'posts[]', id );
		} );
		button.disabled = true;
		fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( readJson ).then( function ( res ) {
			button.disabled = false;
			if ( ! res || ! res.success ) {
				throw new Error( res && res.data ? res.data : cfg.i18n.error );
			}
			if ( ! res.data.tasks.length ) {
				window.alert( format( cfg.i18n.bulkNothing, res.data.skipped ) ); // eslint-disable-line no-alert
				return;
			}
			if ( window.confirm( format( cfg.i18n.bulkConfirm, res.data.tasks.length, res.data.skipped ) ) ) { // eslint-disable-line no-alert
				runBulk( res.data );
			}
		} ).catch( function ( err ) {
			button.disabled = false;
			window.alert( format( cfg.i18n.stopped, err.message ) ); // eslint-disable-line no-alert
		} );
	} );

	function runBulk( plan ) {
		var form = document.getElementById( 'posts-filter' );
		var panel = el( 'div', '', 'notice notice-info wpllmml-bulk is-running' );
		var title = el( 'p', '', 'wpllmml-bulk__title' );
		var bar = document.createElement( 'progress' );
		var line = el( 'p', '', 'wpllmml-bulk__status' );
		var list = el( 'div', '', 'wpllmml-bulk__items' );
		var cancelAll = el( 'button', cfg.i18n.cancelAll, 'button button-small' );
		cancelAll.type = 'button';
		title.appendChild( el( 'strong', format( cfg.i18n.bulkTitle, plan.tasks.length ) ) );
		line.setAttribute( 'role', 'status' );
		bar.max = plan.tasks.length;
		bar.value = 0;
		panel.appendChild( title );
		panel.appendChild( bar );
		panel.appendChild( line );
		panel.appendChild( list );
		panel.appendChild( cancelAll );
		// Where WordPress puts its notices: below the heading, above the status links.
		var anchor = document.querySelector( '.wrap > .wp-header-end' );
		if ( anchor ) {
			anchor.insertAdjacentElement( 'afterend', panel );
		} else {
			form.parentNode.insertBefore( panel, form );
		}

		var queue = plan.tasks.slice();
		var running = [];
		var counts = { done: 0, error: 0, cancelled: 0 };
		var stopped = false;
		function update() {
			var finished = counts.done + counts.error + counts.cancelled;
			bar.value = finished;
			if ( running.length || ( queue.length && ! stopped ) ) {
				line.textContent = format( cfg.i18n.bulkProgress, finished, plan.tasks.length, running.map( function ( r ) {
					return r.title + ' → ' + r.language;
				} ).join( ', ' ) );
				return;
			}
			panel.classList.remove( 'is-running', 'notice-info' );
			panel.classList.add( counts.error ? 'notice-warning' : 'notice-success' );
			cancelAll.hidden = true;
			title.firstChild.textContent = cfg.i18n.bulkFinished;
			line.textContent = format( cfg.i18n.bulkDone, counts.done, counts.error, counts.cancelled + queue.length, plan.skipped );
		}
		function button( task ) {
			var found = document.querySelector( '#post-' + task.source + ' .wpllmml-ai-post[data-mode="create"][data-lang="' + task.lang + '"]' );
			if ( found ) {
				return found;
			}
			// The source is not on this screen: show the job in the panel.
			var host = el( 'div', '', 'wpllmml-bulk__item' );
			host.setAttribute( 'data-wpllmml-job-host', '1' );
			host.appendChild( el( 'strong', task.title + ' → ' + task.language ) );
			var hidden = el( 'button', '', 'wpllmml-ai-post' );
			hidden.type = 'button';
			hidden.hidden = true;
			hidden.setAttribute( 'data-mode', 'create' );
			hidden.setAttribute( 'data-source', task.source );
			hidden.setAttribute( 'data-lang', task.lang );
			hidden.setAttribute( 'data-context', 'list' );
			host.appendChild( hidden );
			list.appendChild( host );
			return hidden;
		}
		function next() {
			while ( ! stopped && queue.length && running.length < BULK_PARALLEL ) {
				( function ( task ) {
					task.el = button( task );
					running.push( task );
					runJob( task.el, function ( outcome ) {
						running.splice( running.indexOf( task ), 1 );
						counts[ 'done' === outcome ? 'done' : ( 'cancelled' === outcome ? 'cancelled' : 'error' ) ]++;
						next();
					} );
				}( queue.shift() ) );
			}
			update();
		}
		cancelAll.addEventListener( 'click', function () {
			stopped = true;
			running.slice().forEach( function ( task ) {
				task.el.wpllmmlCancel();
			} );
			update();
		} );
		next();
	}

	// Spell check: a dialog that shows every finding in its sentence. Opened
	// from the post lists (row action, language menus) and the edit screen.
	// The check runs on the server (answer at once, then polled), so a long
	// page cannot run into a gateway time-out.
	var spell = null;
	function spellCall( action, data ) {
		return post( action, data ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				throw new Error( res && res.data ? res.data : cfg.i18n.error );
			}
			return res.data;
		} );
	}
	function spellCancel( beacon ) {
		if ( ! spell || ! spell.token || 'running' !== spell.state ) {
			return;
		}
		spell.state = 'cancelled';
		var data = new FormData();
		data.append( 'action', 'wpllmml_spell_cancel' );
		data.append( '_ajax_nonce', cfg.nonce );
		data.append( 'post', spell.post );
		data.append( 'token', spell.token );
		if ( beacon && navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.ajax, data );
		} else {
			fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: data, keepalive: true } );
		}
	}
	window.addEventListener( 'pagehide', function () {
		spellCancel( true );
	} );

	function spellDialog() {
		var dialog = document.querySelector( 'dialog.wpllmml-spell' );
		if ( dialog ) {
			return dialog;
		}
		dialog = document.createElement( 'dialog' );
		dialog.className = 'wpllmml-spell';
		dialog.innerHTML = '<div class="wpllmml-spell__head"><h2 class="wpllmml-spell__title"></h2><span class="wpllmml-spell__lang"></span><button type="button" class="wpllmml-spell__x" aria-label=""><span aria-hidden="true">×</span></button></div>' +
			'<div class="wpllmml-spell__body"><p class="wpllmml-spell__note" hidden></p><div class="wpllmml-spell__status" role="status" aria-live="polite"></div><ol class="wpllmml-spell__list"></ol></div>' +
			'<div class="wpllmml-spell__foot"><span class="wpllmml-spell__links"></span><button type="button" class="button wpllmml-spell__all" hidden></button><button type="button" class="button button-primary wpllmml-spell__close"></button></div>';
		dialog.querySelector( '.wpllmml-spell__x' ).setAttribute( 'aria-label', cfg.i18n.close );
		dialog.querySelector( '.wpllmml-spell__close' ).textContent = cfg.i18n.close;
		dialog.querySelector( '.wpllmml-spell__all' ).textContent = cfg.i18n.applyAll;
		dialog.querySelector( '.wpllmml-spell__x' ).addEventListener( 'click', function () {
			dialog.close();
		} );
		dialog.querySelector( '.wpllmml-spell__close' ).addEventListener( 'click', function () {
			dialog.close();
		} );
		dialog.addEventListener( 'close', function () {
			spellCancel( false );
			if ( spell ) {
				window.clearTimeout( spell.timer );
				if ( spell.inEditor && spell.applied ) {
					window.location.reload();
				}
			}
			spell = null;
		} );
		// A click on the backdrop closes the dialog.
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) {
				dialog.close();
			}
		} );
		dialog.querySelector( '.wpllmml-spell__all' ).addEventListener( 'click', function () {
			var buttons = Array.prototype.slice.call( dialog.querySelectorAll( '.wpllmml-spell__item:not(.is-done) .wpllmml-spell__apply' ) );
			( function next() {
				var button = buttons.shift();
				if ( button ) {
					button.wpllmmlApply().then( next );
				}
			}() );
		} );
		document.body.appendChild( dialog );
		return dialog;
	}

	function spellItem( finding, postId ) {
		var item = el( 'li', '', 'wpllmml-spell__item' );
		item.appendChild( el( 'div', finding.where, 'wpllmml-spell__where' ) );
		var context = el( 'p', '', 'wpllmml-spell__context' );
		context.appendChild( document.createTextNode( finding.before ) );
		context.appendChild( el( 'del', finding.original ) );
		context.appendChild( el( 'ins', finding.suggestion ) );
		context.appendChild( document.createTextNode( finding.after ) );
		item.appendChild( context );
		if ( finding.explanation ) {
			item.appendChild( el( 'p', finding.explanation, 'wpllmml-spell__why' ) );
		}
		var actions = el( 'div', '', 'wpllmml-spell__actions' );
		var apply = el( 'button', cfg.i18n.apply, 'button button-small wpllmml-spell__apply' );
		var ignore = el( 'button', cfg.i18n.ignore, 'button button-small button-link' );
		var state = el( 'span', '', 'wpllmml-spell__state' );
		apply.type = 'button';
		ignore.type = 'button';
		actions.appendChild( apply );
		actions.appendChild( ignore );
		actions.appendChild( state );
		item.appendChild( actions );
		function done( cls, text ) {
			item.classList.add( 'is-done', cls );
			state.textContent = text;
			apply.remove();
			ignore.remove();
		}
		apply.wpllmmlApply = function () {
			apply.disabled = true;
			ignore.disabled = true;
			state.textContent = cfg.i18n.checking;
			return spellCall( 'wpllmml_apply_fix', { post: postId, original: finding.original, suggestion: finding.suggestion, field: finding.field, before: finding.before } ).then( function ( d ) {
				if ( d.applied ) {
					if ( spell ) {
						spell.applied++;
					}
					done( 'is-applied', '✓ ' + cfg.i18n.applied );
				} else {
					done( 'is-missing', cfg.i18n.notFound );
				}
			} ).catch( function ( err ) {
				apply.disabled = false;
				ignore.disabled = false;
				state.textContent = cfg.i18n.error + ': ' + err.message;
			} );
		};
		apply.addEventListener( 'click', apply.wpllmmlApply );
		ignore.addEventListener( 'click', function () {
			done( 'is-ignored', cfg.i18n.ignored );
		} );
		return item;
	}

	function openSpell( postId, inEditor ) {
		var dialog = spellDialog();
		var title = dialog.querySelector( '.wpllmml-spell__title' );
		var lang = dialog.querySelector( '.wpllmml-spell__lang' );
		var note = dialog.querySelector( '.wpllmml-spell__note' );
		var status = dialog.querySelector( '.wpllmml-spell__status' );
		var list = dialog.querySelector( '.wpllmml-spell__list' );
		var links = dialog.querySelector( '.wpllmml-spell__links' );
		var all = dialog.querySelector( '.wpllmml-spell__all' );
		title.textContent = cfg.i18n.checking;
		lang.textContent = '';
		list.textContent = '';
		links.textContent = '';
		all.hidden = true;
		note.hidden = ! inEditor;
		// Applying saves the stored page; the editor reloads when the dialog closes.
		note.textContent = inEditor ? cfg.i18n.saveFirst : '';
		status.className = 'wpllmml-spell__status is-busy';
		status.textContent = cfg.i18n.spellStart;
		var current = { post: postId, token: '', state: 'running', inEditor: inEditor, applied: 0, timer: null, since: Date.now() };
		spell = current;
		if ( ! dialog.open ) {
			dialog.showModal();
		}
		function fail( message ) {
			if ( spell !== current ) {
				return;
			}
			current.state = 'error';
			status.className = 'wpllmml-spell__status is-error';
			status.textContent = format( cfg.i18n.stopped, message ) + ' ';
			var again = el( 'button', cfg.i18n.retry, 'button button-small' );
			again.type = 'button';
			again.addEventListener( 'click', function () {
				openSpell( postId, inEditor );
			} );
			status.appendChild( again );
		}
		function poll() {
			spellCall( 'wpllmml_spell_status', { post: postId, token: current.token } ).then( function ( d ) {
				if ( spell !== current || 'running' !== current.state ) {
					return;
				}
				if ( 'running' === d.status ) {
					var time = clock( Math.round( ( Date.now() - current.since ) / 1000 ) );
					status.textContent = d.total > 1
						? format( cfg.i18n.spellParts, d.texts, time, Math.min( d.done + 1, d.total ), d.total )
						: format( cfg.i18n.spellRunning, d.texts || '…', time );
					current.timer = window.setTimeout( poll, 2000 );
					return;
				}
				if ( 'done' !== d.status ) {
					fail( d.message || d.status );
					return;
				}
				current.state = 'done';
				var findings = d.findings || [];
				status.className = 'wpllmml-spell__status ' + ( findings.length ? 'is-found' : 'is-clean' );
				status.textContent = findings.length
					? ( 1 === findings.length ? cfg.i18n.spellOne : format( cfg.i18n.spellFound, findings.length ) )
					: format( cfg.i18n.spellNone, d.texts );
				findings.forEach( function ( finding ) {
					list.appendChild( spellItem( finding, postId ) );
				} );
				all.hidden = findings.length < 2;
			} ).catch( function () {
				if ( spell === current && 'running' === current.state ) {
					current.timer = window.setTimeout( poll, 3000 );
				}
			} );
		}
		spellCall( 'wpllmml_spell_start', { post: postId } ).then( function ( d ) {
			if ( spell !== current ) {
				return;
			}
			current.token = d.token;
			title.textContent = format( cfg.i18n.spellTitle, d.title );
			lang.textContent = d.language;
			if ( ! inEditor ) {
				if ( d.editor ) {
					links.appendChild( link( d.editor, -1 !== d.editor.indexOf( 'action=elementor' ) ? cfg.i18n.editElementor : cfg.i18n.edit ) );
				}
				if ( d.view ) {
					links.appendChild( document.createTextNode( ' · ' ) );
					links.appendChild( link( d.view, cfg.i18n.view ) );
				}
			}
			current.timer = window.setTimeout( poll, 1500 );
		} ).catch( function ( err ) {
			fail( err.message );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest && e.target.closest( '.wpllmml-spell-open' );
		if ( ! button ) {
			return;
		}
		e.preventDefault();
		var menu = button.closest( 'details' );
		if ( menu ) {
			menu.open = false;
		}
		openSpell( button.getAttribute( 'data-post' ), !! button.getAttribute( 'data-editor' ) );
	} );

	// Forms tab: translate a Forminator form with AI. The server answers at
	// once and translates in the background; this page polls for the result.
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest && e.target.closest( '.wpllmml-form-ai' );
		if ( ! button ) {
			return;
		}
		e.preventDefault();
		if ( button.getAttribute( 'data-again' ) && ! window.confirm( cfg.i18n.formAgain ) ) { // eslint-disable-line no-alert
			return;
		}
		var cell = button.closest( '.wpllmml-forms__cell' );
		var data = { form: button.getAttribute( 'data-form' ), lang: button.getAttribute( 'data-lang' ) };
		var spinner = el( 'span', '', 'spinner is-active' );
		var status = el( 'span', cfg.i18n.formWorking, 'wpllmml-forms__status' );
		var since = Date.now();
		button.hidden = true;
		cell.appendChild( spinner );
		cell.appendChild( status );
		function fail( message ) {
			spinner.remove();
			status.textContent = format( cfg.i18n.stopped, message ) + ' ';
			button.hidden = false;
		}
		spellCall( 'wpllmml_form_start', data ).then( function ( d ) {
			data.token = d.token;
			( function poll() {
				window.setTimeout( function () {
					spellCall( 'wpllmml_form_status', data ).then( function ( s ) {
						if ( 'running' === s.status ) {
							status.textContent = format( cfg.i18n.formWorkingTime, clock( Math.round( ( Date.now() - since ) / 1000 ) ) );
							poll();
							return;
						}
						if ( 'done' !== s.status ) {
							fail( s.message || cfg.i18n.error );
							return;
						}
						cell.textContent = '';
						cell.appendChild( link( s.edit, s.title ) );
						cell.appendChild( el( 'span', ' ✓ ' + cfg.i18n.formDone, 'wpllmml-forms__done' ) );
					} ).catch( poll );
				}, 2000 );
			}() );
		} ).catch( function ( err ) {
			fail( err.message );
		} );
	} );

	// Layout sync (post lists): start an update that waits (WP-Cron does not
	// run on every host) and follow it until it is done.
	function watchSync( cell, source ) {
		if ( cell.wpllmmlSync ) {
			return;
		}
		cell.wpllmmlSync = true;
		post( 'wpllmml_sync_run', { source: source } ).catch( function () {} );
		var rounds = 0;
		function poll() {
			setTimeout( function () {
				post( 'wpllmml_editor_group', { post: source } ).then( function ( res ) {
					var busy = false;
					( res && res.success && res.data ? res.data.languages : [] ).forEach( function ( lang ) {
						var badge = cell.querySelector( 'details[data-lang="' + lang.code + '"] > summary' );
						if ( ! badge || ! lang.id ) {
							return;
						}
						var syncing = 'queued' === lang.sync || 'running' === lang.sync || 'stalled' === lang.sync;
						busy = busy || syncing;
						badge.classList.toggle( 'is-syncing', syncing );
						badge.classList.toggle( 'has-sync-error', 'error' === lang.sync );
						badge.classList.toggle( 'has-review', !! lang.review );
						if ( 'error' === lang.sync && lang.syncError ) {
							badge.title = lang.syncError;
						}
					} );
					if ( busy && ++rounds < 300 ) {
						poll();
					} else {
						cell.wpllmmlSync = false;
					}
				} ).catch( function () {
					cell.wpllmmlSync = false;
				} );
			}, 3000 );
		}
		poll();
	}
	document.querySelectorAll( '[data-wpllmml-sync-source]' ).forEach( function ( cell ) {
		watchSync( cell, cell.getAttribute( 'data-wpllmml-sync-source' ) );
	} );
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest ? e.target.closest( '.wpllmml-sync-now' ) : null;
		if ( ! button ) {
			return;
		}
		e.preventDefault();
		var menu = button.closest( 'details' );
		if ( menu ) {
			menu.open = false;
			menu.querySelector( 'summary' ).classList.add( 'is-syncing' );
		}
		watchSync( button.closest( '.wpllmml-langs-cell' ), button.getAttribute( 'data-source' ) );
	} );

	// Load models into the datalist on the AI tab.
	var load = document.getElementById( 'wpllmml-load-models' );
	if ( load ) {
		load.addEventListener( 'click', function () {
			var status = document.getElementById( 'wpllmml-models-status' );
			var list = document.getElementById( 'wpllmml-models' );
			status.textContent = cfg.i18n.loading;
			post( 'wpllmml_models', {} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					status.textContent = cfg.i18n.error + ': ' + ( res && res.data ? res.data : '' );
					return;
				}
				list.textContent = '';
				res.data.forEach( function ( id ) {
					var option = document.createElement( 'option' );
					option.value = id;
					list.appendChild( option );
				} );
				status.textContent = res.data.length + ' ✓';
			} );
		} );
	}
}() );
