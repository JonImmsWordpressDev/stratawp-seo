/**
 * StrataWP SEO — Keyword Cannibalization page (v4.19).
 *
 * Handles Consolidate modal, Undo, and Dismiss AJAX actions.
 */
/* global swpsCannibalization, jQuery */
(function ( $ ) {
	'use strict';

	var cfg = swpsCannibalization || {};

	// =========================================================================
	// Helpers
	// =========================================================================

	function ajaxPost( action, data, success, error ) {
		$.post(
			cfg.ajaxUrl,
			$.extend( { action: action, nonce: cfg.nonce }, data ),
			function ( resp ) {
				if ( resp.success ) {
					success( resp.data );
				} else {
					var msg = ( resp.data && resp.data.message ) ? resp.data.message : cfg.i18n.error;
					error( msg );
				}
			}
		).fail( function () {
			error( cfg.i18n.error );
		} );
	}

	function findingRow( id ) {
		return $( '#swps-finding-' + id );
	}

	// =========================================================================
	// Consolidate
	// =========================================================================

	$( document ).on( 'click', '.swps-cannibal-consolidate', function () {
		var $btn   = $( this );
		var id     = $btn.data( 'id' );
		var winner = $btn.data( 'winner' );
		var loser  = $btn.data( 'loser' );

		if ( ! confirm( cfg.i18n.confirm_consolidate ) ) {
			return;
		}

		var draftLoser = confirm(
			'Also draft the loser post (recommended — prevents duplicate content)?'
		) ? 1 : 0;

		$btn.prop( 'disabled', true ).text( cfg.i18n.processing );

		ajaxPost(
			'swps_cannibal_consolidate',
			{
				finding_id:  id,
				winner_url:  winner,
				loser_url:   loser,
				draft_loser: draftLoser
			},
			function ( data ) {
				// Replace action buttons with Undo button.
				var $row = findingRow( id );
				$row.find( '.swps-cannibal-actions' ).html(
					'<button type="button" class="button button-secondary swps-cannibal-undo"' +
					' data-id="' + id + '">' +
					'&#8629; Undo Consolidation</button>' +
					'<button type="button" class="button swps-cannibal-dismiss"' +
					' data-id="' + id + '"' +
					' style="margin-left:auto;color:var(--swps-text-faint)">Dismiss</button>'
				);
			},
			function ( msg ) {
				$btn.prop( 'disabled', false ).text( 'Consolidate (301 redirect)' );
				alert( msg );
			}
		);
	} );

	// =========================================================================
	// Undo
	// =========================================================================

	$( document ).on( 'click', '.swps-cannibal-undo', function () {
		var $btn = $( this );
		var id   = $btn.data( 'id' );

		if ( ! confirm( cfg.i18n.confirm_undo ) ) {
			return;
		}

		$btn.prop( 'disabled', true ).text( cfg.i18n.processing );

		ajaxPost(
			'swps_cannibal_undo',
			{ finding_id: id },
			function () {
				// Reload the page to show the re-opened finding.
				window.location.reload();
			},
			function ( msg ) {
				$btn.prop( 'disabled', false ).text( '↩ Undo Consolidation' );
				alert( msg );
			}
		);
	} );

	// =========================================================================
	// Dismiss
	// =========================================================================

	$( document ).on( 'click', '.swps-cannibal-dismiss', function () {
		var $btn = $( this );
		var id   = $btn.data( 'id' );

		$btn.prop( 'disabled', true );

		ajaxPost(
			'swps_cannibal_dismiss',
			{ finding_id: id },
			function () {
				findingRow( id ).fadeOut( 300, function () {
					$( this ).remove();
					// Show empty state if no findings remain.
					if ( $( '.swps-cannibal-finding' ).length === 0 ) {
						$( '.swps-cannibalization-wrap' ).append(
							'<div class="swps-empty-state" style="text-align:center;padding:40px 0;">' +
							'<span style="font-size:48px;">✓</span>' +
							'<h2>No cannibalization detected</h2></div>'
						);
					}
				} );
			},
			function ( msg ) {
				$btn.prop( 'disabled', false );
				alert( msg );
			}
		);
	} );

}( jQuery ) );
