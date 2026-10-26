/**
 * Content Powered > Schedule Post and Page Updates page behaviour. Only loaded on that page.
 *
 * Confirm prompts, the post searches (to schedule an update, or to pick
 * posts for a find and replace), the find and replace form (which posts,
 * repeat warning, preview) and "Undo last run". schedule_post_page_updates_admin
 * .ajaxUrl, .nonce and .i18n are passed in from PHP (see
 * Schedule_Post_Page_Updates_Admin::enqueue_assets). Text from the server is
 * only ever set with textContent.
 */

/* global schedule_post_page_updates_admin */
( function () {
	'use strict';

	var data = window.schedule_post_page_updates_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		initConfirm();
		document.querySelectorAll( '[data-sppu-search]' ).forEach( initSearch );
		initRuleForm( document.getElementById( 'sppu-rule-form' ) );
		document.querySelectorAll( '.sppu-undo-run' ).forEach( initUndoRun );
	} );

	// Helpers ------------------------------------------------------------------

	function make( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text && null !== text ) {
			node.textContent = String( text );
		}

		return node;
	}

	function format( template, value ) {
		return String( template ).replace( '%d', value ).replace( '%s', value );
	}

	function get( action, params ) {
		var query = new URLSearchParams( params );

		query.set( 'action', action );
		query.set( 'nonce', data.nonce );

		return window.fetch( data.ajaxUrl + '?' + query.toString(), { credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} );
	}

	function post( action, body ) {
		body.append( 'action', action );
		body.append( 'nonce', data.nonce );

		return window.fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
	}

	// Confirm prompts ------------------------------------------------------------

	function initConfirm() {
		document.addEventListener( 'click', function ( event ) {
			var target = event.target.closest( '.sppu-confirm, .sppu-confirm-submit' );

			// eslint-disable-next-line no-alert
			if ( target && ! window.confirm( target.getAttribute( 'data-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	}

	// Post search ----------------------------------------------------------------

	function initSearch( box ) {
		var mode = box.getAttribute( 'data-sppu-search' );
		var input = box.querySelector( 'input[type="search"]' );
		var list = box.querySelector( '.sppu-search-results' );
		var timer = null;
		var last = null;

		function message( text ) {
			list.textContent = '';
			list.appendChild( make( 'li', 'is-message', text ) );
		}

		function render( results ) {
			list.textContent = '';

			if ( ! results.length ) {
				message( data.i18n.noResults );
				return;
			}

			results.forEach( function ( item ) {
				var li = make( 'li' );
				var text = make( 'span', 'sppu-result-text' );

				text.appendChild( make( 'span', 'sppu-result-title', item.title ) );
				text.appendChild( make( 'span', 'sppu-sub', item.type + ' · ' + item.status + ' · ' + item.date ) );
				li.appendChild( text );

				if ( 'schedule' === mode ) {
					if ( item.url ) {
						var link = make( 'a', 'button button-primary button-small', data.i18n.schedule );
						link.href = item.url;
						li.appendChild( link );
					} else {
						li.appendChild( make( 'span', 'sppu-muted', data.i18n.notEligible ) );
					}
				} else {
					var add = make( 'button', 'button button-small', isPicked( item.id ) ? data.i18n.added : data.i18n.add );
					add.type = 'button';
					add.disabled = isPicked( item.id );
					add.addEventListener( 'click', function () {
						pick( item.id, item.title );
						add.textContent = data.i18n.added;
						add.disabled = true;
					} );
					li.appendChild( add );
				}

				list.appendChild( li );
			} );
		}

		function search() {
			var term = input.value.trim();

			if ( term === last ) {
				return;
			}

			last = term;
			message( data.i18n.searching );

			get( 'sppu_search_posts', { q: term, mode: mode } )
				.then( function ( res ) {
					if ( term !== last ) {
						return;
					}
					if ( res && res.success ) {
						render( res.data );
					} else {
						message( res && res.data && res.data.message ? res.data.message : data.i18n.error );
					}
				} )
				.catch( function () {
					message( data.i18n.error );
				} );
		}

		input.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( search, 250 );
		} );

		input.addEventListener( 'focus', function () {
			if ( null === last ) {
				search();
			}
		} );

		// Enter searches instead of submitting the rule form.
		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				window.clearTimeout( timer );
				search();
			}
		} );
	}

	// Picked posts ---------------------------------------------------------------

	function pickedList() {
		return document.querySelector( '.sppu-picked' );
	}

	function isPicked( id ) {
		var list = pickedList();

		return !! ( list && list.querySelector( 'li[data-id="' + parseInt( id, 10 ) + '"]' ) );
	}

	function pick( id, title ) {
		var list = pickedList();

		if ( ! list || isPicked( id ) ) {
			return;
		}

		var li = make( 'li' );
		var hidden = make( 'input' );
		var remove = make( 'button', 'sppu-picked-remove' );

		li.setAttribute( 'data-id', String( parseInt( id, 10 ) ) );
		hidden.type = 'hidden';
		hidden.name = list.getAttribute( 'data-name' );
		hidden.value = String( parseInt( id, 10 ) );
		remove.type = 'button';
		remove.setAttribute( 'aria-label', data.i18n.remove );
		remove.textContent = '×';

		li.appendChild( hidden );
		li.appendChild( make( 'span', 'sppu-picked-title', title ) );
		li.appendChild( remove );
		list.appendChild( li );
	}

	// Find and replace form ------------------------------------------------------

	function initRuleForm( form ) {
		if ( ! form ) {
			return;
		}

		var find = form.querySelector( '#sppu-find' );
		var warning = form.querySelector( '.sppu-inline-warning' );
		var previewButton = document.querySelector( '.sppu-preview-button' );
		var preview = document.querySelector( '.sppu-preview' );

		// Show the panel under the chosen "Which posts" option.
		function syncScope() {
			var checked = form.querySelector( 'input[name="rule[scope]"]:checked' );
			var scope = checked ? checked.value : 'posts';

			form.querySelectorAll( '[data-sppu-scope]' ).forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-sppu-scope' ) !== scope;
			} );
		}

		// A yearly repeat needs a year to count up.
		function syncRepeat() {
			var repeat = form.querySelector( 'input[name="rule[repeat]"]:checked' );
			var yearly = repeat && '1' === repeat.value;

			warning.hidden = ! yearly || /(^|[^0-9])(1[89][0-9]{2}|2[0-9]{3})([^0-9]|$)/.test( find.value );
			warning.textContent = data.i18n.noYear;
		}

		form.addEventListener( 'change', function () {
			syncScope();
			syncRepeat();
		} );
		find.addEventListener( 'input', syncRepeat );

		form.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest( '.sppu-picked-remove' );

			if ( remove ) {
				remove.closest( 'li' ).remove();
			}
		} );

		syncScope();
		syncRepeat();

		if ( previewButton && preview ) {
			previewButton.addEventListener( 'click', function () {
				runPreview( form, preview, previewButton );
			} );
		}
	}

	function runPreview( form, box, button ) {
		var body = new window.FormData( form );

		// Only the rule's own fields; the form's nonce is for saving.
		[ 'action', '_wpnonce', '_wp_http_referer', 'rule_id' ].forEach( function ( key ) {
			body.delete( key );
		} );

		button.disabled = true;
		box.textContent = '';
		box.appendChild( make( 'p', 'sppu-muted', data.i18n.previewing ) );

		post( 'sppu_preview', body )
			.then( function ( res ) {
				box.textContent = '';

				if ( ! res || ! res.success ) {
					box.appendChild( make( 'div', 'notice notice-error inline' ) ).appendChild( make( 'p', '', res && res.data && res.data.message ? res.data.message : data.i18n.error ) );
					return;
				}

				renderPreview( box, res.data );
			} )
			.catch( function () {
				box.textContent = '';
				box.appendChild( make( 'p', 'sppu-error-text', data.i18n.error ) );
			} )
			.then( function () {
				button.disabled = false;
			} );
	}

	function renderPreview( box, result ) {
		var posts = result.posts || [];

		if ( ! posts.length ) {
			box.appendChild( make( 'p', 'sppu-preview-summary', data.i18n.previewNone ) );
			return;
		}

		box.appendChild( make( 'p', 'sppu-preview-summary', format( 1 === posts.length ? data.i18n.previewOne : data.i18n.previewMany, posts.length ) ) );

		var list = make( 'ul', 'sppu-preview-list' );

		posts.forEach( function ( item ) {
			var li = make( 'li', 'sppu-preview-item' );
			var head = make( 'div', 'sppu-preview-head' );
			var counts = Object.keys( item.counts ).map( function ( field ) {
				return item.counts[ field ] + ' × ' + ( data.i18n.fields[ field ] || field );
			} );

			head.appendChild( make( 'strong', '', item.title ) );
			head.appendChild( make( 'span', 'sppu-sub', item.type + ' · ' + item.status + ' · ' + counts.join( ', ' ) ) );

			if ( item.edit ) {
				var edit = make( 'a', '', data.i18n.edit );
				edit.href = item.edit;
				edit.target = '_blank';
				edit.rel = 'noopener';
				head.appendChild( edit );
			}

			li.appendChild( head );

			( item.snippets || [] ).forEach( function ( snippet ) {
				var line = make( 'p', 'sppu-snippet' );

				line.appendChild( make( 'span', 'sppu-snippet-field', snippet.field ) );
				line.appendChild( document.createTextNode( snippet.before ) );
				line.appendChild( make( 'del', '', snippet.match ) );
				if ( '' !== snippet.replace ) {
					line.appendChild( make( 'ins', '', snippet.replace ) );
				}
				line.appendChild( document.createTextNode( snippet.after ) );
				li.appendChild( line );
			} );

			list.appendChild( li );
		} );

		box.appendChild( list );

		if ( result.more ) {
			box.appendChild( make( 'p', 'sppu-muted', data.i18n.previewMore ) );
		}
	}

	// Undo a run -----------------------------------------------------------------

	function initUndoRun( button ) {
		button.addEventListener( 'click', function () {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( data.i18n.undoConfirm ) ) {
				return;
			}

			var total = 0;

			button.disabled = true;
			button.textContent = data.i18n.undoing;

			function step() {
				var body = new window.FormData();

				body.append( 'rule', button.getAttribute( 'data-rule' ) );
				body.append( 'run', button.getAttribute( 'data-run' ) );

				post( 'sppu_undo_run', body )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							button.textContent = data.i18n.error;
							return;
						}

						total += res.data.undone;

						// Keep going while each batch makes progress.
						if ( res.data.remaining > 0 && res.data.undone > 0 ) {
							step();
							return;
						}

						button.textContent = format( data.i18n.undone, total );
						window.setTimeout( function () {
							window.location.reload();
						}, 900 );
					} )
					.catch( function () {
						button.textContent = data.i18n.error;
					} );
			}

			step();
		} );
	}
}() );
