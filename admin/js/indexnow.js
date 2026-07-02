/* global jQuery, swpsAdmin */
( function ( $ ) {
	'use strict';

	function post( action, extra ) {
		return $.post( swpsAdmin.ajax_url, Object.assign( { action: action, nonce: swpsAdmin.nonce }, extra || {} ) );
	}

	function renderLog( log ) {
		var $body = $( '#swps-indexnow-log tbody' ).empty();
		if ( ! log || ! log.length ) {
			$body.append( '<tr><td colspan="4">No activity yet.</td></tr>' );
			return;
		}
		log.forEach( function ( e ) {
			var when = e.time ? new Date( e.time * 1000 ).toLocaleString() : '';
			$body.append(
				'<tr><td>' + when + '</td><td>' + ( e.trigger || '' ) + '</td><td>' +
				( e.count || 0 ) + '</td><td>' + ( e.result || '' ) + '</td></tr>'
			);
		} );
	}

	function loadLog() {
		post( 'swps_indexnow_get_log' ).done( function ( r ) {
			if ( r && r.success ) {
				renderLog( r.data.log );
			}
		} );
	}

	/**
	 * Summarize a resubmit response for the status line. `submitted` is the
	 * pre-filter URL count, not proof anything was actually sent — when no key
	 * is configured, `batches` is empty even though `submitted` is nonzero, so
	 * always reflect the batch results rather than the raw count alone.
	 */
	function describeResubmit( data ) {
		var submitted = data && data.submitted ? data.submitted : 0;
		var batches = ( data && data.batches ) || [];

		if ( ! batches.length ) {
			return 'No URLs submitted (found ' + submitted + '; check that an API key is generated).';
		}

		var ok = 0;
		batches.forEach( function ( b ) {
			if ( b && ( 'ok' === b.result || 'pending' === b.result ) ) {
				ok++;
			}
		} );

		return 'Submitted ' + submitted + ' URLs in ' + batches.length + ' batch(es), ' + ok + ' succeeded.';
	}

	$( function () {
		if ( ! $( '#swps-indexnow-log' ).length ) {
			return;
		}
		loadLog();

		$( '#swps-indexnow-generate' ).on( 'click', function () {
			var $b = $( this ).prop( 'disabled', true );
			post( 'swps_indexnow_generate_key' ).done( function ( r ) {
				if ( r && r.success ) {
					$( '#swps-indexnow-key' ).text( r.data.key );
					$( '#swps-indexnow-key-url' ).text( r.data.key_file_url ).attr( 'href', r.data.key_file_url );
				} else {
					window.alert( r && r.data ? r.data.message : 'Error' );
				}
			} ).always( function () {
				$b.prop( 'disabled', false );
			} );
		} );

		$( '#swps-indexnow-resubmit' ).on( 'click', function () {
			var $b = $( this ).prop( 'disabled', true );
			$( '#swps-indexnow-resubmit-status' ).text( 'Submitting…' );
			post( 'swps_indexnow_resubmit_all' ).done( function ( r ) {
				$( '#swps-indexnow-resubmit-status' ).text(
					r && r.success ? describeResubmit( r.data ) : ( r && r.data ? r.data.message : 'Error' )
				);
				loadLog();
			} ).always( function () {
				$b.prop( 'disabled', false );
			} );
		} );
	} );
}( jQuery ) );
