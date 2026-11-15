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
	var activeJob = null;
	function cancelActiveJob( beacon ) {
		if ( ! activeJob ) {
			return;
		}
		var data = new FormData();
		data.append( 'action', 'wpllmml_ai_post_cancel' );
		data.append( '_ajax_nonce', cfg.nonce );
		data.append( 'post', activeJob.post );
		data.append( 'token', activeJob.token );
		if ( beacon && navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.ajax, data );
		} else {
			fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: data, keepalive: true } );
		}
	}
	window.addEventListener( 'pagehide', function () {
		cancelActiveJob( true );
	} );

	function clock( seconds ) {
		var m = Math.floor( seconds / 60 );
		var sec = seconds % 60;
		return m + ':' + ( sec < 10 ? '0' : '' ) + sec;
	}

	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest && e.target.closest( '.wpllmml-ai-post' );
		if ( ! el || e.defaultPrevented ) {
			return;
		}
		e.preventDefault();
		if ( running ) {
			return;
		}
		var host = el.closest( 'li, p' ) || el;
		var box = host.parentNode.querySelector( '.wpllmml-ai-job' );
		if ( box ) {
			box.parentNode.removeChild( box );
		}
		box = document.createElement( 'div' );
		box.className = 'wpllmml-ai-job';
		box.innerHTML = '<progress></progress><div class="wpllmml-ai-job__line"><span class="spinner is-active"></span><span class="wpllmml-ai-status" role="status" aria-live="polite"></span></div><div class="wpllmml-ai-job__actions"><button type="button" class="button button-small wpllmml-ai-cancel"></button></div>';
		host.insertAdjacentElement( 'afterend', box );
		var spinner = box.querySelector( '.spinner' );
		var status = box.querySelector( '.wpllmml-ai-status' );
		var bar = box.querySelector( 'progress' );
		var cancelButton = box.querySelector( '.wpllmml-ai-cancel' );
		cancelButton.textContent = cfg.i18n.cancel;
		var timer = null;
		var since = Date.now();

		function busy( on ) {
			running = on;
			el.classList.toggle( 'wpllmml-is-busy', on );
			el.setAttribute( 'aria-disabled', on ? 'true' : 'false' );
			spinner.classList.toggle( 'is-active', on );
			cancelButton.hidden = ! on;
			bar.hidden = ! on;
		}
		function stop( message, canRetry ) {
			window.clearTimeout( timer );
			activeJob = null;
			busy( false );
			status.textContent = message + ' ';
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
		function call( action, data ) {
			return post( action, data ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					throw new Error( res && res.data ? res.data : cfg.i18n.error );
				}
				return res.data;
			} );
		}
		function poll() {
			call( 'wpllmml_ai_post_status', { post: activeJob.post, token: activeJob.token } ).then( function ( d ) {
				if ( ! activeJob ) {
					return;
				}
				if ( 'done' === d.status ) {
					activeJob = null;
					running = false;
					status.textContent = cfg.i18n.opening;
					window.location.href = d.redirect;
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
					status.textContent = format( cfg.i18n.postProgressParts, elapsed, d.done, d.total );
				} else {
					status.textContent = format( cfg.i18n.postProgress, elapsed );
				}
				timer = window.setTimeout( poll, 2000 );
			} ).catch( function () {
				// A failed poll (network hiccup) is retried; the server keeps going.
				if ( activeJob ) {
					timer = window.setTimeout( poll, 3000 );
				}
			} );
		}

		cancelButton.addEventListener( 'click', function () {
			cancelActiveJob( false );
			stop( cfg.i18n.cancelled, true );
		} );

		busy( true );
		status.textContent = cfg.i18n.preparing;
		var data = { mode: el.getAttribute( 'data-mode' ) };
		if ( 'create' === data.mode ) {
			data.source = el.getAttribute( 'data-source' );
			data.lang = el.getAttribute( 'data-lang' );
		} else {
			data.post = el.getAttribute( 'data-post' );
		}
		call( 'wpllmml_ai_post_start', data ).then( function ( d ) {
			activeJob = { post: d.post, token: d.token };
			since = Date.now();
			// Start the work on the server; its answer is not needed (it may
			// even time out at a proxy while the server keeps translating).
			post( 'wpllmml_ai_post_run', { post: d.post, token: d.token } ).catch( function () {} );
			timer = window.setTimeout( poll, 1500 );
		} ).catch( function ( err ) {
			stop( format( cfg.i18n.stopped, err.message ), true );
		} );
	} );

	// Spell check.
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest && e.target.closest( '.wpllmml-spellcheck' );
		if ( ! button ) {
			return;
		}
		e.preventDefault();
		var postId = button.getAttribute( 'data-post' );
		var out = button.parentNode.nextElementSibling;
		out.textContent = cfg.i18n.checking;
		button.disabled = true;
		post( 'wpllmml_spellcheck', { post: postId } ).then( function ( res ) {
			button.disabled = false;
			out.textContent = '';
			if ( ! res || ! res.success ) {
				out.textContent = cfg.i18n.error + ': ' + ( res && res.data ? res.data : '' );
				return;
			}
			if ( ! res.data.length ) {
				out.textContent = cfg.i18n.noErrors;
				return;
			}
			out.appendChild( el( 'p', cfg.i18n.saveFirst, 'description' ) );
			var list = el( 'ul' );
			res.data.forEach( function ( item ) {
				var li = el( 'li' );
				li.appendChild( el( 'del', item.original ) );
				li.appendChild( document.createTextNode( ' → ' ) );
				li.appendChild( el( 'ins', item.suggestion ) );
				if ( item.explanation ) {
					li.appendChild( el( 'span', item.explanation, 'description' ) );
				}
				var apply = el( 'button', cfg.i18n.apply, 'button button-small' );
				apply.type = 'button';
				apply.addEventListener( 'click', function () {
					apply.disabled = true;
					post( 'wpllmml_apply_fix', { post: postId, original: item.original, suggestion: item.suggestion } ).then( function ( r ) {
						apply.textContent = r && r.success && r.data.applied ? cfg.i18n.applied : cfg.i18n.notFound;
					} );
				} );
				li.appendChild( apply );
				list.appendChild( li );
			} );
			out.appendChild( list );
		} ).catch( function () {
			button.disabled = false;
			out.textContent = cfg.i18n.error;
		} );
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
