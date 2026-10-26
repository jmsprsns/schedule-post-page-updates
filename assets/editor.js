/**
 * Block editor panel for Schedule Post and Page Updates.
 *
 * On a live post: its scheduled updates and a "Schedule an update" button.
 * On an update: when it goes live, with Schedule, Unschedule and Apply now.
 * window.sppuEditor is passed in from PHP (see
 * Schedule_Post_Page_Updates_Editor::panel_data()).
 */

( function ( wp ) {
	'use strict';

	var data = window.sppuEditor;

	if ( ! data || ! wp || ! wp.plugins || ! wp.element || ! wp.components || ! wp.data ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var C = wp.components;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var StatusInfo = ( wp.editor && wp.editor.PluginPostStatusInfo ) || ( wp.editPost && wp.editPost.PluginPostStatusInfo );

	if ( ! Panel ) {
		return;
	}

	// Lucide "calendar-clock", the plugin's icon.
	var icon = el(
		'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', width: 20, height: 20, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true },
		el( 'path', { d: 'M16 14v2.2l1.6 1' } ),
		el( 'path', { d: 'M16 2v4' } ),
		el( 'path', { d: 'M21 7.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3.5' } ),
		el( 'path', { d: 'M3 10h5' } ),
		el( 'path', { d: 'M8 2v4' } ),
		el( 'circle', { cx: 16, cy: 16, r: 6 } )
	);

	function notify( type, text, options ) {
		wp.data.dispatch( 'core/notices' ).createNotice( type, text, Object.assign( { isDismissible: true }, options || {} ) );
	}

	function button( variant, props, label ) {
		// "variant" replaced isPrimary/isSecondary/isLink; older versions only know the latter.
		var legacy = { primary: 'isPrimary', secondary: 'isSecondary', link: 'isLink', tertiary: 'isTertiary' };
		var extra = { variant: variant };

		if ( data.legacyButtons && legacy[ variant ] ) {
			extra[ legacy[ variant ] ] = true;
		}

		return el( C.Button, Object.assign( extra, props ), label );
	}

	function request( fields ) {
		var body = new window.FormData();

		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return window.fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.catch( function () {
				return { success: false, data: { message: __( 'The request failed. Check your connection and try again.', 'schedule-post-page-updates' ) } };
			} );
	}

	// Save the post if it has unsaved changes. Resolves to false if that failed.
	function saveFirst() {
		var select = wp.data.select( 'core/editor' );

		if ( ! select.isEditedPostDirty() ) {
			return Promise.resolve( true );
		}

		return Promise.resolve( wp.data.dispatch( 'core/editor' ).savePost() ).then( function () {
			return ! select.didPostSaveRequestFail();
		} );
	}

	function useDirty() {
		return wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).isEditedPostDirty();
		}, [] );
	}

	// Default pick: tomorrow at midnight, or the next January 1st when it's
	// late in the year (the "2026 to 2027" case).
	function defaultLocal() {
		var now = new Date( data.now );
		var next = new Date( now.getFullYear(), now.getMonth(), now.getDate() + 1 );

		if ( now.getMonth() >= 9 ) {
			next = new Date( now.getFullYear() + 1, 0, 1 );
		}

		function pad( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		}

		return next.getFullYear() + '-' + pad( next.getMonth() + 1 ) + '-' + pad( next.getDate() ) + 'T00:00:00';
	}

	function formatPicked( local ) {
		var date = new Date( local );

		if ( isNaN( date.getTime() ) ) {
			return local;
		}

		return date.toLocaleString( undefined, {
			year: 'numeric',
			month: 'long',
			day: 'numeric',
			hour: 'numeric',
			minute: '2-digit',
			hour12: !! data.is12Hour,
		} );
	}

	/* Update ------------------------------------------------------------------ */

	function UpdatePanel() {
		var scheduleState = useState( {
			time: data.time,
			local: data.local,
			label: data.label,
			relative: data.relative,
			error: data.error,
		} );
		var schedule = scheduleState[ 0 ];
		var setSchedule = scheduleState[ 1 ];
		var pickedState = useState( data.local || defaultLocal() );
		var picked = pickedState[ 0 ];
		var setPicked = pickedState[ 1 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		var dirty = useDirty();
		var today = new Date( data.now );
		var unchanged = !! schedule.time && !! schedule.local && schedule.local.slice( 0, 16 ) === picked.slice( 0, 16 );

		today.setHours( 0, 0, 0, 0 );

		function send( when, success ) {
			setBusy( true );

			saveFirst().then( function ( saved ) {
				if ( ! saved ) {
					setBusy( false );
					notify( 'error', __( 'Your changes could not be saved, so the schedule wasn\'t changed.', 'schedule-post-page-updates' ), { id: 'sppu-msg' } );
					return;
				}

				return request( { action: 'sppu_schedule', nonce: data.nonce, post: data.postId, when: when } ).then( function ( res ) {
					setBusy( false );

					if ( ! res || ! res.success ) {
						notify( 'error', res && res.data && res.data.message ? res.data.message : __( 'Something went wrong.', 'schedule-post-page-updates' ), { id: 'sppu-msg' } );
						return;
					}

					setSchedule( res.data );
					notify( 'success', success( res.data ), { id: 'sppu-msg', type: 'snackbar' } );
				} );
			} );
		}

		function onSchedule() {
			send( picked, function ( result ) {
				return sprintf(
					/* translators: %s: date and time. */
					__( 'Update scheduled for %s.', 'schedule-post-page-updates' ),
					result.label
				);
			} );
		}

		function onUnschedule() {
			send( '', function () {
				return __( 'Update unscheduled. It\'s kept as a draft.', 'schedule-post-page-updates' );
			} );
		}

		function onApply() {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Replace the live post with this update now?', 'schedule-post-page-updates' ) ) ) {
				return;
			}

			setBusy( true );
			saveFirst().then( function ( saved ) {
				if ( saved ) {
					window.location.href = data.applyUrl;
				} else {
					setBusy( false );
				}
			} );
		}

		var children = [];

		children.push(
			el(
				'p',
				{ key: 'for', className: 'sppu-for' },
				__( 'Update for', 'schedule-post-page-updates' ) + ' ',
				data.originalView
					? el( 'a', { href: data.originalView, target: '_blank', rel: 'noopener noreferrer' }, data.originalTitle )
					: el( 'strong', null, __( 'a deleted post', 'schedule-post-page-updates' ) )
			)
		);

		if ( schedule.error ) {
			children.push( el( C.Notice, { key: 'error', status: 'error', isDismissible: false, className: 'sppu-inline-notice' }, schedule.error ) );
		}
		if ( data.conflict ) {
			children.push( el( C.Notice, { key: 'conflict', status: 'warning', isDismissible: false, className: 'sppu-inline-notice' }, data.conflict ) );
		}

		if ( schedule.time ) {
			children.push(
				el(
					'div',
					{ key: 'when', className: 'sppu-when is-scheduled' },
					el( 'span', { className: 'sppu-when-label' }, __( 'Goes live', 'schedule-post-page-updates' ) ),
					el( 'strong', null, schedule.label ),
					el( 'span', { className: 'sppu-when-relative' }, schedule.relative )
				)
			);
		} else {
			children.push(
				el(
					'div',
					{ key: 'when', className: 'sppu-when' },
					el( 'span', { className: 'sppu-when-label' }, __( 'Not scheduled yet', 'schedule-post-page-updates' ) ),
					el( 'span', { className: 'sppu-when-relative' }, __( 'Pick when these changes replace the live post.', 'schedule-post-page-updates' ) )
				)
			);
		}

		children.push(
			el(
				'div',
				{ key: 'pick', className: 'sppu-pick' },
				el( C.Dropdown, {
					className: 'sppu-pick-dropdown',
					contentClassName: 'sppu-pick-popover',
					position: 'bottom left',
					popoverProps: { placement: 'left-start' },
					renderToggle: function ( args ) {
						return button(
							'secondary',
							{ onClick: args.onToggle, 'aria-expanded': args.isOpen, className: 'sppu-pick-toggle' },
							formatPicked( picked )
						);
					},
					renderContent: function () {
						return el(
							'div',
							{ className: 'sppu-picker' },
							el( C.DateTimePicker, {
								currentDate: picked,
								onChange: function ( value ) {
									if ( value ) {
										setPicked( value );
									}
								},
								is12Hour: !! data.is12Hour,
								isInvalidDate: function ( date ) {
									return date < today;
								},
								__nextRemoveHelpButton: true,
								__nextRemoveResetButton: true,
							} )
						);
					},
				} ),
				el( 'span', { className: 'sppu-timezone' }, data.timezone )
			)
		);

		children.push(
			el(
				'div',
				{ key: 'actions', className: 'sppu-actions' },
				button(
					'primary',
					{ onClick: onSchedule, disabled: busy || ( unchanged && ! dirty ), isBusy: busy, className: 'sppu-schedule-button' },
					unchanged
						? ( dirty ? __( 'Save Update', 'schedule-post-page-updates' ) : __( 'Scheduled', 'schedule-post-page-updates' ) )
						: ( schedule.time ? __( 'Change Schedule', 'schedule-post-page-updates' ) : __( 'Schedule Update', 'schedule-post-page-updates' ) )
				),
				schedule.time ? button( 'tertiary', { onClick: onUnschedule, disabled: busy }, __( 'Unschedule', 'schedule-post-page-updates' ) ) : null
			)
		);

		if ( data.applyUrl ) {
			children.push(
				el(
					'div',
					{ key: 'apply', className: 'sppu-apply' },
					button( 'link', { onClick: onApply, disabled: busy, isDestructive: true }, __( 'Apply now instead', 'schedule-post-page-updates' ) )
				)
			);
		}

		children.push(
			el(
				'div',
				{ key: 'help', className: 'sppu-help' },
				__( 'Only what you change here replaces the live post. Its address, author and comments stay the same.', 'schedule-post-page-updates' )
			)
		);

		return el( 'div', { className: 'sppu-panel-body' }, children );
	}

	/* Live post --------------------------------------------------------------- */

	function OriginalPanel() {
		var dirty = useDirty();

		function onCreate() {
			if (
				dirty &&
				// eslint-disable-next-line no-alert
				! window.confirm( __( 'You have unsaved changes on this post. The scheduled update starts from the last saved version, without them. Continue?', 'schedule-post-page-updates' ) )
			) {
				return;
			}

			window.location.href = data.createUrl;
		}

		var list = data.updates.length
			? el(
				'ul',
				{ className: 'sppu-update-list' },
				data.updates.map( function ( update ) {
					return el(
						'li',
						{ key: update.id, className: update.time ? 'is-scheduled' : 'is-draft' },
						el( 'a', { href: update.edit }, update.label )
					);
				} )
			)
			: el( 'p', { className: 'sppu-help' }, __( 'Edit a private copy of this post and pick when it replaces the live version, like changing 2026 to 2027 on January 1st.', 'schedule-post-page-updates' ) );

		return el(
			'div',
			{ className: 'sppu-panel-body' },
			list,
			data.createUrl
				? el(
					'div',
					{ className: 'sppu-actions' },
					button( 'secondary', { onClick: onCreate }, data.updates.length ? __( 'Schedule Another Update', 'schedule-post-page-updates' ) : __( 'Schedule an Update', 'schedule-post-page-updates' ) )
				)
				: null
		);
	}

	/* Register ---------------------------------------------------------------- */

	// Panels remember being open or closed in the user's editor preferences.
	// Open ours on live posts the first time only, so closing it sticks; on
	// an update it's always open when it's used at all.
	function openPanel() {
		var name = 'schedule-post-page-updates/sppu-panel';
		var store = wp.data.select( 'core/editor' ).isEditorPanelOpened ? 'core/editor' : 'core/edit-post';
		var key = 'sppu-panel-shown';
		var seen = false;

		try {
			seen = !! window.localStorage.getItem( key );
			window.localStorage.setItem( key, '1' );
		} catch ( e ) {}

		if ( ( 'update' === data.mode || ! seen ) && ! wp.data.select( store ).isEditorPanelOpened( name ) ) {
			wp.data.dispatch( store ).toggleEditorPanelOpened( name );
		}
	}

	function Root() {
		var inSummary = 'update' === data.mode && !! StatusInfo;

		useEffect( function () {
			if ( ! inSummary ) {
				openPanel();
			}

			if ( data.message ) {
				notify( data.message.type, data.message.text, { id: 'sppu-msg' } );
			}

			if ( 'update' === data.mode && data.originalTitle && ( ! data.message || 'info' !== data.message.type ) ) {
				notify(
					'info',
					sprintf(
						/* translators: %s: post title. */
						__( 'You\'re editing a scheduled update of “%s”. Visitors keep seeing the live version until it goes live.', 'schedule-post-page-updates' ),
						data.originalTitle
					),
					{
						id: 'sppu-update',
						isDismissible: false,
						actions: data.originalView ? [ { label: __( 'View live post', 'schedule-post-page-updates' ), url: data.originalView } ] : [],
					}
				);
			}
		}, [] );

		// On an update, the schedule sits in the post summary, where the
		// publish date normally is (those rows are hidden by editor.css).
		if ( inSummary ) {
			return el(
				StatusInfo,
				{ className: 'sppu-status-info' },
				el(
					'div',
					{ className: 'sppu-status-info-inner sppu-panel' },
					el( 'h3', { className: 'sppu-status-info-title' }, icon, __( 'Scheduled Update', 'schedule-post-page-updates' ) ),
					el( UpdatePanel )
				)
			);
		}

		var title = 'update' === data.mode
			? __( 'Scheduled Update', 'schedule-post-page-updates' )
			: ( data.updates.length
				? sprintf(
					/* translators: %d: number of scheduled updates. */
					__( 'Scheduled Updates (%d)', 'schedule-post-page-updates' ),
					data.updates.length
				)
				: __( 'Scheduled Updates', 'schedule-post-page-updates' ) );

		return el(
			Panel,
			{
				name: 'sppu-panel',
				title: title,
				className: 'sppu-panel',
				icon: icon,
			},
			'update' === data.mode ? el( UpdatePanel ) : el( OriginalPanel )
		);
	}

	wp.plugins.registerPlugin( 'schedule-post-page-updates', { render: Root, icon: icon } );
}( window.wp ) );
