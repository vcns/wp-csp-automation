/**
 * WP CSP Automation – Admin JavaScript
 * Handles AJAX interactions on the CSP Manager admin pages.
 */
/* global wpCspAdmin, jQuery */
( function ( $ ) {
	'use strict';

	// ── Manual scan ──────────────────────────────────────────────────────────
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

	// ── Refresh remote config ─────────────────────────────────────────────────
	$( '#wp-csp-refresh-config' ).on( 'click', function ( e ) {
		e.preventDefault();
		const $btn = $( this );
		$btn.prop( 'disabled', true ).text( '…' );

		$.post( wpCspAdmin.ajaxUrl, {
			action: 'wp_csp_refresh_config',
			nonce:  wpCspAdmin.nonce,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$btn.text( 'Done (' + res.data.version + ')' );
			} else {
				$btn.text( 'Failed' );
			}
		} )
		.fail( function () {
			$btn.text( 'Error' );
		} )
		.always( function () {
			setTimeout( function () { $btn.prop( 'disabled', false ).text( 'Refresh Now' ); }, 3000 );
		} );
	} );

	// ── Toggle profile mode ───────────────────────────────────────────────────
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

	// ── Approve source ────────────────────────────────────────────────────────
	$( document ).on( 'click', '.wp-csp-approve-source', function () {
		const $btn = $( this );
		const id   = $btn.data( 'id' );
		$btn.prop( 'disabled', true );

		$.post( wpCspAdmin.ajaxUrl, {
			action:    'wp_csp_approve_source',
			nonce:     wpCspAdmin.nonce,
			source_id: id,
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

	// ── Deny source ───────────────────────────────────────────────────────────
	$( document ).on( 'click', '.wp-csp-deny-source', function () {
		const $btn = $( this );
		const id   = $btn.data( 'id' );
		$btn.prop( 'disabled', true );

		$.post( wpCspAdmin.ajaxUrl, {
			action:    'wp_csp_deny_source',
			nonce:     wpCspAdmin.nonce,
			source_id: id,
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

	// ── Buy Now (checkout redirect) ───────────────────────────────────────────
	$( document ).on( 'click', '.wp-csp-buy-btn', function () {
		const $btn       = $( this );
		const productKey = $btn.data( 'product-key' );
		$btn.prop( 'disabled', true ).text( '…' );

		$.post( wpCspAdmin.ajaxUrl, {
			action:      'wp_csp_create_checkout',
			nonce:       wpCspAdmin.nonce,
			product_key: productKey,
		} )
		.done( function ( res ) {
			if ( res.success && res.data.checkout_url ) {
				window.location.href = res.data.checkout_url;
			} else {
				// eslint-disable-next-line no-alert
				alert( res.data.message || 'Could not create checkout session.' );
				$btn.prop( 'disabled', false ).text( 'Buy Now' );
			}
		} )
		.fail( function () {
			// eslint-disable-next-line no-alert
			alert( 'Request failed. Please try again.' );
			$btn.prop( 'disabled', false ).text( 'Buy Now' );
		} );
	} );

} )( jQuery );
