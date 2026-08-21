// admin/js/site-audit.js
( function () {
	'use strict';

	var poll = document.querySelector( '[data-swps-audit-progress]' );
	if ( poll ) {
		var tick = window.setInterval( function () {
			window.fetch( window.ajaxurl + '?action=swps_site_audit_progress&_wpnonce=' + poll.dataset.nonce )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( ! data || ! data.success ) { return; }
					var p = data.data;
					poll.querySelector( '.swps-audit-bar' ).style.width = p.percent + '%';
					poll.querySelector( '.swps-audit-count' ).textContent = p.done + ' / ' + p.total;
					if ( p.finished ) {
						window.clearInterval( tick );
						window.location.reload();
					}
				} );
		}, 3000 );
	}

	document.querySelectorAll( '[data-swps-copy-urls]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			navigator.clipboard.writeText( btn.dataset.swpsCopyUrls.split( '|' ).join( '\n' ) ).then( function () {
				btn.textContent = btn.dataset.copied || 'Copied!';
			} );
		} );
	} );

	// ---- Fix-It ----

	function post( action, data ) {
		var body = new window.URLSearchParams();
		body.set( 'action', action );
		Object.keys( data ).forEach( function ( k ) {
			if ( Array.isArray( data[ k ] ) ) {
				data[ k ].forEach( function ( v ) { body.append( k + '[]', v ); } );
			} else {
				body.set( k, data[ k ] );
			}
		} );
		return window.fetch( window.ajaxurl, { method: 'POST', body: body } )
			.then( function ( r ) { return r.json(); } );
	}

	// Draft loop (✨ Fix with AI) and mechanical apply loop (Fix now).
	document.querySelectorAll( '[data-swps-fixit]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			var check = btn.dataset.check;
			var base  = { nonce: btn.dataset.nonce, run_id: btn.dataset.run, check_id: check };

			if ( 'draft' === btn.dataset.kind ) {
				var offset = 0;
				var drafted = 0;

				// Counts down a rate-limit cooldown in the button label, then
				// retries the SAME chunk (offset unchanged) — a per-chunk
				// lock would otherwise strand chunk 2+ with a dead 429.
				var countdown = function ( seconds ) {
					btn.textContent = 'Rate limited — retrying in ' + seconds + 's';
					window.setTimeout( function () {
						if ( seconds > 1 ) {
							countdown( seconds - 1 );
						} else {
							chunk();
						}
					}, 1000 );
				};

				var chunk = function () {
					btn.textContent = 'Drafting… (' + drafted + ')';
					post( 'swps_fixit_draft_chunk', Object.assign( { offset: offset }, base ) )
						.then( function ( res ) {
							if ( ! res.success ) {
								if ( res.data && 'number' === typeof res.data.retry_after && res.data.retry_after > 0 ) {
									countdown( res.data.retry_after );
									return;
								}
								btn.disabled = false;
								btn.textContent = res.data && res.data.message ? res.data.message : 'Error';
								return;
							}
							drafted += res.data.drafted.length;
							offset  += res.data.drafted.length + res.data.errors.length;
							if ( res.data.remaining > 0 ) {
								chunk();
							} else {
								window.location.reload();
							}
						} );
				};
				chunk();
				return;
			}

			// Mechanical: apply all fixable ids in chunks.
			var ids = JSON.parse( btn.dataset.ids || '[]' );
			( function applyChunk( remaining ) {
				if ( ! remaining.length ) {
					window.location.reload();
					return;
				}
				btn.textContent = 'Fixing… (' + remaining.length + ' left)';
				post( 'swps_fixit_apply', Object.assign( { issue_ids: remaining }, base ) )
					.then( function ( res ) {
						if ( ! res.success ) {
							btn.textContent = res.data && res.data.message ? res.data.message : 'Error';
							return;
						}
						applyChunk( res.data.remaining_ids );
					} );
			}( ids ) );
		} );
	} );

	// Review-table actions.
	document.querySelectorAll( '[data-swps-review]' ).forEach( function ( panel ) {
		var base = {
			nonce: panel.dataset.nonce,
			run_id: panel.dataset.run,
			check_id: panel.dataset.swpsReview
		};
		var status = panel.querySelector( '.swps-audit-review-status' );

		var all = panel.querySelector( '[data-swps-review-all]' );
		if ( all ) {
			all.addEventListener( 'change', function () {
				panel.querySelectorAll( '[data-swps-review-row]' ).forEach( function ( cb ) {
					cb.checked = all.checked;
				} );
			} );
		}

		panel.querySelector( '[data-swps-review-apply]' ).addEventListener( 'click', function () {
			var ids = Array.prototype.filter.call(
				panel.querySelectorAll( '[data-swps-review-row]' ),
				function ( cb ) { return cb.checked; }
			).map( function ( cb ) { return cb.value; } );
			if ( ! ids.length ) { return; }

			( function applyChunk( remaining ) {
				if ( ! remaining.length ) {
					window.location.reload();
					return;
				}
				status.textContent = 'Applying… (' + remaining.length + ' left)';
				post( 'swps_fixit_apply', Object.assign( { issue_ids: remaining }, base ) )
					.then( function ( res ) {
						if ( ! res.success ) {
							status.textContent = res.data && res.data.message ? res.data.message : 'Error';
							return;
						}
						applyChunk( res.data.remaining_ids );
					} );
			}( ids ) );
		} );

		panel.querySelector( '[data-swps-review-dismiss]' ).addEventListener( 'click', function () {
			var ids = Array.prototype.map.call(
				panel.querySelectorAll( '[data-swps-review-row]' ),
				function ( cb ) { return cb.value; }
			);
			( function dismissNext( remaining ) {
				if ( ! remaining.length ) {
					window.location.reload();
					return;
				}
				post( 'swps_fixit_dismiss', Object.assign( { issue_id: remaining[ 0 ] }, base ) )
					.then( function () { dismissNext( remaining.slice( 1 ) ); } );
			}( ids ) );
		} );
	} );

	// Undo one applied fix.
	document.querySelectorAll( '[data-swps-fixit-undo]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			post( 'swps_fixit_undo', {
				nonce: btn.dataset.nonce,
				run_id: btn.dataset.run,
				issue_id: btn.dataset.swpsFixitUndo
			} ).then( function ( res ) {
				if ( ! res.success ) {
					btn.disabled = false;
					btn.textContent = res.data && res.data.message ? res.data.message : 'Error';
					return;
				}
				window.location.reload();
			} );
		} );
	} );
}() );
