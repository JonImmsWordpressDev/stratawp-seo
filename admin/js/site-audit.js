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
}() );
