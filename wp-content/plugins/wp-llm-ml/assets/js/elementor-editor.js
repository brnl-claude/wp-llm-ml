/**
 * LLM Multilingual — language switcher in the Elementor editor's top bar.
 *
 * Shows EN · NL · RO next to the device switch. Clicking a language opens
 * that version in the same editor (no reload; Elementor asks to save
 * unsaved changes first). A missing language offers "Create & translate
 * with AI" (runs on the server, polled here) or a plain copy.
 *
 * Built only on Elementor's editor packages (window.elementorV2). When they
 * are missing or changed, nothing is added and the "Translations" section in
 * Page Settings remains the way to switch.
 */
( function () {
	'use strict';

	var cfg = window.wpllmmlEditor;
	var v2 = window.elementorV2 || {};
	var React = window.React;
	var ReactDOM = window.ReactDOM;
	var appBar = v2.editorAppBar;
	var docs = v2.editorDocuments;
	var ui = v2.ui || {};

	function unavailable( why ) {
		window.wpllmmlEditorSwitcher = 'unavailable: ' + why;
		if ( window.console ) {
			window.console.info( '[LLM Multilingual] Language switcher not added to the Elementor top bar (' + why + '). Use Page Settings → Translations instead.' );
		}
	}
	if ( ! cfg || ! cfg.group ) {
		return;
	}
	if ( ! React || ! React.useState ) {
		return unavailable( 'React not found' );
	}
	if ( ! appBar || typeof appBar.injectIntoResponsive !== 'function' ) {
		return unavailable( 'top bar API changed' );
	}
	// Elementor marks some of these hooks as internal with a "__" prefix.
	var useActiveDocument = docs && ( docs.useActiveDocument || docs.__useActiveDocument );
	var useNavigateToDocument = docs && ( docs.useNavigateToDocument || docs.__useNavigateToDocument );
	if ( typeof useActiveDocument !== 'function' ) {
		return unavailable( 'documents API changed' );
	}
	if ( typeof useNavigateToDocument !== 'function' ) {
		// Same as Elementor's own page navigation does.
		useNavigateToDocument = function () {
			return function ( id ) {
				var run = typeof docs.switchToDocument === 'function'
					? docs.switchToDocument( id, { setAsInitial: true } )
					: window.$e.run( 'editor/documents/switch', { id: id, setAsInitial: true } );
				return Promise.resolve( run ).then( function () {
					window.history.replaceState( {}, '', 'post.php?post=' + id + '&action=elementor' );
				} );
			};
		};
	}

	var h = React.createElement;
	var t = cfg.i18n;
	var hasMenu = typeof ui.Menu === 'function' || ( ui.Menu && typeof ui.Menu === 'object' );
	var running = null; // The AI job in progress: { code, post, token, started }.
	var kicked = {}; // source id => when this screen last started its sync.

	function isSyncing( lang ) {
		return 'queued' === lang.sync || 'running' === lang.sync || 'stalled' === lang.sync;
	}

	// Run a waiting layout update in a request of our own: it answers at once
	// and continues on the server, so it does not depend on WP-Cron.
	function kick( source, force ) {
		if ( ! source || ( ! force && kicked[ source ] && Date.now() - kicked[ source ] < 30000 ) ) {
			return;
		}
		kicked[ source ] = Date.now();
		ajax( 'wpllmml_sync_run', { source: source } ).catch( function () {} );
	}

	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str ).replace( /%(\d+)\$[sd]|%[sd]/g, function ( m, pos ) {
			return String( pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ] );
		} );
	}

	function elapsed( since ) {
		var s = Math.max( 0, Math.floor( ( Date.now() - since ) / 1000 ) );
		return Math.floor( s / 60 ) + ':' + ( '0' + ( s % 60 ) ).slice( -2 );
	}

	function ajax( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( '_ajax_nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.text().then( function ( text ) {
					var json = null;
					try {
						json = JSON.parse( text );
					} catch ( e ) {
						throw new Error( t.error + ' ' + response.status );
					}
					if ( ! json || ! json.success ) {
						throw new Error( json && json.data ? ( typeof json.data === 'string' ? json.data : json.data.message || t.error ) : t.error );
					}
					return json.data;
				} );
			} );
	}

	function cancelRunning( beacon ) {
		if ( ! running || ! running.token ) {
			return;
		}
		var data = new FormData();
		data.append( 'action', 'wpllmml_ai_post_cancel' );
		data.append( '_ajax_nonce', cfg.nonce );
		data.append( 'post', running.post );
		data.append( 'token', running.token );
		if ( beacon && navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.ajax, data );
		} else {
			fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: data, keepalive: true } );
		}
	}

	// Leaving the editor stops a running translation on the server too.
	window.addEventListener( 'pagehide', function () {
		cancelRunning( true );
	} );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( running ) {
			e.preventDefault();
			e.returnValue = t.leave;
			return t.leave;
		}
	} );

	function byId( group, id ) {
		if ( ! group || ! id ) {
			return null;
		}
		for ( var i = 0; i < group.languages.length; i++ ) {
			if ( group.languages[ i ].id === id ) {
				return group.languages[ i ];
			}
		}
		return null;
	}

	function describe( lang, active ) {
		var lines = [];
		if ( ! lang.id ) {
			lines.push( fmt( t.missing, lang.name ) );
		} else {
			lines.push( fmt( t.version, lang.name, lang.statusLabel ) );
			lines.push( active ? t.thisPage : fmt( t.switchTo, lang.name ) );
			if ( ! lang.elementor ) {
				lines.push( t.notElementor );
			}
			if ( true === lang.follows ) {
				lines.push( t.follows );
			} else if ( false === lang.follows ) {
				lines.push( t.ownLayout );
			}
			if ( isSyncing( lang ) ) {
				lines.push( t.syncing );
			} else if ( 'error' === lang.sync ) {
				lines.push( fmt( t.syncFailed, lang.syncError ) );
			}
			if ( lang.review ) {
				lines.push( lang.review );
			}
		}
		return lines;
	}

	/**
	 * Small popup menu: Elementor's own Menu component when available,
	 * otherwise a fixed-position list.
	 */
	function Popup( props ) {
		var items = props.items.map( function ( item, i ) {
			if ( item.note ) {
				return hasMenu && ui.MenuItem
					? h( ui.MenuItem, { key: i, disabled: true, className: 'wpllmml-ebar-note', sx: { whiteSpace: 'normal', maxWidth: 340, fontSize: 12, lineHeight: 1.45, '&.Mui-disabled': { opacity: 0.85 } } }, item.note )
					: h( 'div', { key: i, className: 'wpllmml-ebar-note' }, item.note );
			}
			return hasMenu && ui.MenuItem
				? h( ui.MenuItem, { key: i, onClick: item.onClick, 'data-wpllmml-action': item.action, sx: { fontSize: 13 } }, item.label )
				: h( 'button', { key: i, type: 'button', className: 'wpllmml-ebar-item', onClick: item.onClick, 'data-wpllmml-action': item.action }, item.label );
		} );
		if ( hasMenu ) {
			return h(
				ui.Menu,
				{
					open: true,
					anchorEl: props.anchor,
					onClose: props.onClose,
					anchorOrigin: { vertical: 'bottom', horizontal: 'left' },
					transformOrigin: { vertical: 'top', horizontal: 'left' },
					MenuListProps: { dense: true, className: 'wpllmml-ebar-menu' },
				},
				items
			);
		}
		var rect = props.anchor.getBoundingClientRect();
		var box = h( 'div', { className: 'wpllmml-ebar-menu is-fallback', role: 'menu', style: { top: rect.bottom + 6, left: rect.left } }, items );
		var layer = h( 'div', { className: 'wpllmml-ebar-backdrop', onClick: props.onClose } );
		var content = h( React.Fragment, null, layer, box );
		return ReactDOM && ReactDOM.createPortal ? ReactDOM.createPortal( content, document.body ) : content;
	}

	function Switcher() {
		var doc = useActiveDocument();
		var navigate = useNavigateToDocument();
		var groupState = React.useState( cfg.group );
		var group = groupState[ 0 ];
		var setGroup = groupState[ 1 ];
		var menuState = React.useState( null );
		var menu = menuState[ 0 ];
		var setMenu = menuState[ 1 ];
		var jobState = React.useState( null );
		var job = jobState[ 0 ];
		var setJob = jobState[ 1 ];
		var tickState = React.useState( 0 );
		var setTick = tickState[ 1 ];
		var wasDirty = React.useRef( false );
		var id = doc && doc.id ? parseInt( doc.id, 10 ) : 0;
		var dirty = !! ( doc && doc.isDirty );

		function refresh( postId ) {
			return ajax( 'wpllmml_editor_group', { post: postId } ).then( function ( data ) {
				setGroup( data );
				return data;
			} ).catch( function () {} );
		}

		// Another document became active (our switch, or Elementor's own page navigation).
		React.useEffect( function () {
			if ( id && ! byId( group, id ) ) {
				refresh( id );
			}
		}, [ id ] );

		// After a save: the translations may now be updating in the background.
		React.useEffect( function () {
			if ( wasDirty.current && ! dirty && id ) {
				refresh( id );
			}
			wasDirty.current = dirty;
		}, [ dirty ] );

		// Poll while a translation is being updated from the source page, and
		// start the update when it waits.
		var syncing = !! ( group && group.languages.some( isSyncing ) );
		var waiting = !! ( group && group.languages.some( function ( l ) {
			return 'queued' === l.sync || 'stalled' === l.sync;
		} ) );
		React.useEffect( function () {
			if ( waiting && group ) {
				kick( group.source );
			}
			if ( ! syncing || ! id ) {
				return undefined;
			}
			var timer = setTimeout( function () {
				refresh( id );
			}, 3000 );
			return function () {
				clearTimeout( timer );
			};
		}, [ syncing, waiting, group, id ] );

		// Elapsed-time display while the AI works.
		React.useEffect( function () {
			if ( ! job ) {
				return undefined;
			}
			var timer = setInterval( function () {
				setTick( Date.now() );
			}, 1000 );
			return function () {
				clearInterval( timer );
			};
		}, [ job ] );

		var active = byId( group, id );
		if ( ! active ) {
			return null;
		}

		function open( lang ) {
			setMenu( null );
			if ( ! lang.elementor ) {
				window.location.href = lang.url;
				return;
			}
			navigate( lang.id ).catch( function () {} ); // Rejected when the editor chooses "Don't leave".
		}

		function poll( j ) {
			if ( running !== j ) {
				return;
			}
			ajax( 'wpllmml_ai_post_status', { post: j.post, token: j.token, context: 'list' } ).then( function ( s ) {
				if ( running !== j ) {
					return;
				}
				if ( 'done' === s.status ) {
					running = null;
					setJob( null );
					refresh( id ).then( function () {
						navigate( j.post ).catch( function () {} );
					} );
					return;
				}
				if ( 'queued' !== s.status && 'running' !== s.status ) {
					throw new Error( s.message || s.status );
				}
				setTimeout( function () {
					poll( j );
				}, 2000 );
			} ).catch( function ( err ) {
				if ( running === j ) {
					running = null;
					setJob( null );
					refresh( id );
					window.alert( fmt( t.failed, err.message ) ); // eslint-disable-line no-alert
				}
			} );
		}

		function createWithAi( lang ) {
			setMenu( null );
			if ( dirty && ! window.confirm( t.saveFirst ) ) { // eslint-disable-line no-alert
				return;
			}
			var j = { code: lang.code, started: Date.now() };
			running = j;
			setJob( j );
			ajax( 'wpllmml_ai_post_start', { mode: 'create', source: group.source, lang: lang.code } ).then( function ( d ) {
				j.post = d.post;
				j.token = d.token;
				// Answers at once; the translation continues on the server.
				ajax( 'wpllmml_ai_post_run', { post: d.post, token: d.token } ).catch( function () {} );
				poll( j );
			} ).catch( function ( err ) {
				running = null;
				setJob( null );
				window.alert( fmt( t.failed, err.message ) ); // eslint-disable-line no-alert
			} );
		}

		function cancel() {
			setMenu( null );
			cancelRunning( false );
			running = null;
			setJob( null );
		}

		function markReviewed( lang ) {
			setMenu( null );
			ajax( 'wpllmml_editor_reviewed', { post: lang.id } ).then( setGroup ).catch( function () {} );
		}

		function menuItems( lang ) {
			var items = [];
			if ( job && job.code === lang.code ) {
				items.push( { note: fmt( t.working, elapsed( job.started ) ) } );
				items.push( { label: t.cancel, action: 'cancel', onClick: cancel } );
				return items;
			}
			if ( ! lang.id ) {
				items.push( { note: fmt( t.missing, lang.name ) } );
				if ( cfg.ai && ! job ) {
					items.push( { label: t.createAi, action: 'create-ai', onClick: function () {
						createWithAi( lang );
					} } );
				}
				if ( lang.create ) {
					items.push( { label: t.createCopy, action: 'create-copy', onClick: function () {
						window.location.href = lang.create;
					} } );
				}
				return items;
			}
			items.push( { note: describe( lang, lang.id === id ).join( ' ' ) } );
			if ( lang.id !== id ) {
				items.push( { label: fmt( t.switchTo, lang.name ), action: 'switch', onClick: function () {
					open( lang );
				} } );
			}
			if ( lang.review && lang.canEdit ) {
				items.push( { label: t.markReviewed, action: 'reviewed', onClick: function () {
					markReviewed( lang );
				} } );
			}
			if ( 'error' === lang.sync || 'stalled' === lang.sync ) {
				items.push( { label: t.syncRetry, action: 'sync-retry', onClick: function () {
					setMenu( null );
					kick( group.source, true );
					setTimeout( function () {
						refresh( id );
					}, 1500 );
				} } );
			}
			return items;
		}

		function onClick( lang, event ) {
			var target = event.currentTarget;
			if ( lang.id && lang.id !== id && ! lang.review && 'error' !== lang.sync && ! ( job && job.code === lang.code ) ) {
				open( lang );
				return;
			}
			var items = menuItems( lang );
			if ( items.length > 1 || ( items.length && ! items[ 0 ].note ) ) {
				setMenu( { code: lang.code, anchor: target } );
			} else if ( lang.id && lang.id !== id ) {
				open( lang );
			} else {
				setMenu( { code: lang.code, anchor: target } );
			}
		}

		var buttons = group.languages.map( function ( lang ) {
			var isActive = lang.id === id;
			var working = job && job.code === lang.code;
			var classes = [ 'wpllmml-ebar__lang' ];
			if ( isActive ) {
				classes.push( 'is-active' );
			}
			if ( ! lang.id ) {
				classes.push( 'is-missing' );
			}
			if ( lang.review ) {
				classes.push( 'has-review' );
			}
			if ( isSyncing( lang ) || working ) {
				classes.push( 'is-busy' );
			}
			if ( 'error' === lang.sync ) {
				classes.push( 'has-error' );
			}
			var title = working ? fmt( t.working, elapsed( job.started ) ) : describe( lang, isActive ).join( '\n' );
			return h(
				'button',
				{
					key: lang.code,
					type: 'button',
					className: classes.join( ' ' ),
					title: title,
					'aria-label': title,
					'aria-current': isActive ? 'page' : undefined,
					'data-wpllmml-switch': lang.label,
					onClick: function ( e ) {
						onClick( lang, e );
					},
				},
				lang.id ? lang.label : '+' + lang.label
			);
		} );

		var open_menu = null;
		if ( menu ) {
			var lang = null;
			group.languages.forEach( function ( l ) {
				if ( l.code === menu.code ) {
					lang = l;
				}
			} );
			if ( lang ) {
				open_menu = h( Popup, {
					anchor: menu.anchor,
					items: menuItems( lang ),
					onClose: function () {
						setMenu( null );
					},
				} );
			}
		}

		return h( 'div', { className: 'wpllmml-ebar', role: 'group', 'aria-label': t.languages }, buttons, open_menu );
	}

	appBar.injectIntoResponsive( {
		id: 'wpllmml-language-switcher',
		component: Switcher,
		options: { priority: 10 }, // Before the device switch (20).
	} );
	window.wpllmmlEditorSwitcher = 'ready';
}() );
