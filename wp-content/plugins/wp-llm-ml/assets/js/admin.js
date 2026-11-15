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

	// Confirm destructive AI re-translation.
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest && e.target.closest( '.wpllmml-confirm' );
		if ( link && ! window.confirm( cfg.i18n.confirmAi ) ) {
			e.preventDefault();
		}
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
