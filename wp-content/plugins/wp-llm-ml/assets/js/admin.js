/* LLM Multilingual — admin interactions (no dependencies). */
( function () {
	'use strict';
	var cfg = window.wpllmmlAdmin;
	if ( ! cfg ) {
		return;
	}

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( '_ajax_nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
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
				.then( function ( r ) {
					return r.json();
				} )
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
