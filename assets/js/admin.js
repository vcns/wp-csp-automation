/**
 * CSP Automation Manager admin JavaScript.
 * Handles AJAX interactions on the CSP Manager admin pages.
 */
/* global wpCspAdmin, jQuery */
( function ( $ ) {
	'use strict';

	$( '#wp-csp-manual-scan' ).on( 'click', function () {
		const $btn    = $( this );
		const $status = $( '#wp-csp-scan-status' );

		$btn.prop( 'disabled', true );
		$status.text( wpCspAdmin.i18n.scanning ).show();

		$.post( wpCspAdmin.ajaxUrl, {
			action: 'wp_csp_manual_scan',
			nonce:  wpCspAdmin.nonce,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$status.text( wpCspAdmin.i18n.scanDone );
				setTimeout( function () { location.reload(); }, 1500 );
			} else {
				$status.text( res.data.message || wpCspAdmin.i18n.scanError );
			}
		} )
		.fail( function () {
			$status.text( wpCspAdmin.i18n.scanError );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	$( '.wp-csp-toggle-mode' ).on( 'click', function () {
		const $btn    = $( this );
		const surface = $btn.data( 'surface' );
		const mode    = $btn.data( 'mode' );

		$btn.prop( 'disabled', true );

		$.post( wpCspAdmin.ajaxUrl, {
			action:  'wp_csp_toggle_mode',
			nonce:   wpCspAdmin.nonce,
			surface: surface,
			mode:    mode,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				location.reload();
			} else {
				// eslint-disable-next-line no-alert
				alert( res.data.message || 'Failed to switch mode.' );
				$btn.prop( 'disabled', false );
			}
		} )
		.fail( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	$( document ).on( 'click', '.wp-csp-approve-source', function () {
		const $btn   = $( this );
		const id     = $btn.data( 'id' );
		const reason = window.prompt( 'Optional approval reason:', '' ) || '';
		$btn.prop( 'disabled', true );

		$.post( wpCspAdmin.ajaxUrl, {
			action:    'wp_csp_approve_source',
			nonce:     wpCspAdmin.nonce,
			source_id: id,
			reason:    reason,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$btn.closest( 'tr' ).find( '.wp-csp-state-badge' )
					.removeClass( 'state-pending state-denied' )
					.addClass( 'state-approved' )
					.text( 'Approved' );
				$btn.remove();
			}
		} )
		.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	$( document ).on( 'click', '.wp-csp-deny-source', function () {
		const $btn   = $( this );
		const id     = $btn.data( 'id' );
		const reason = window.prompt( 'Why should this source be rejected and suppressed?', '' ) || '';
		$btn.prop( 'disabled', true );

		$.post( wpCspAdmin.ajaxUrl, {
			action:    'wp_csp_deny_source',
			nonce:     wpCspAdmin.nonce,
			source_id: id,
			reason:    reason,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$btn.closest( 'tr' ).find( '.wp-csp-state-badge' )
					.removeClass( 'state-pending state-approved' )
					.addClass( 'state-denied' )
					.text( 'Denied' );
				$btn.remove();
			}
		} )
		.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	$( document ).on( 'click', '.wp-csp-revert-source', function () {
		const $btn   = $( this );
		const id     = $btn.data( 'id' );
		const reason = window.prompt( 'Why should this approved source be reverted and suppressed?', '' ) || '';
		$btn.prop( 'disabled', true );

		$.post( wpCspAdmin.ajaxUrl, {
			action:    'wp_csp_revert_source',
			nonce:     wpCspAdmin.nonce,
			source_id: id,
			reason:    reason,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$btn.closest( 'tr' ).find( '.wp-csp-state-badge' )
					.removeClass( 'state-pending state-approved' )
					.addClass( 'state-denied' )
					.text( 'Denied' );
				$btn.remove();
			}
		} )
		.always( function () { $btn.prop( 'disabled', false ); } );
	} );
} )( jQuery );
