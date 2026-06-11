/* global scoltaAmazee, jQuery, wp */
/**
 * Amazee.ai multi-step admin connection UI.
 *
 * Drives step transitions via AJAX without full page reloads.
 * Falls back gracefully when JS is disabled (server renders the active step).
 *
 * All user-facing strings go through wp.i18n with the same source strings
 * as the PHP step renderers in class-scolta-amazee-admin-page.php, so the
 * two renderings share one set of translations.
 */
( function ( $ ) {
	'use strict';

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	var app = null;
	var selectedRegionId = null;

	function escText( value ) {
		return $( '<span>' ).text( value ).html();
	}

	function post( action, data, done, fail ) {
		$.post(
			scoltaAmazee.ajaxUrl,
			Object.assign( { action: action, nonce: scoltaAmazee.nonce }, data ),
			function ( res ) {
				if ( res.success ) {
					done( res.data );
				} else {
					fail( res.data && res.data.message ? res.data.message : __( 'An error occurred.', 'scolta-ai-search' ) );
				}
			},
			'json'
		).fail( function () {
			fail( __( 'Network error. Please try again.', 'scolta-ai-search' ) );
		} );
	}

	function showError( msg ) {
		var el = $( '#scolta-amazee-error' );
		if ( ! el.length ) {
			el = $( '<p id="scolta-amazee-error" class="notice notice-error scolta-amazee-error"></p>' );
			app.prepend( el );
		}
		el.text( msg ).show();
	}

	function clearError() {
		$( '#scolta-amazee-error' ).hide();
	}

	function renderConnected( region ) {
		clearError();
		app.html(
			'<p>' +
			sprintf(
				/* translators: %s: Amazee.ai region name */
				escText( __( 'Connected to Amazee.ai (region: %s).', 'scolta-ai-search' ) ),
				'<strong>' + escText( region ) + '</strong>'
			) +
			'</p>' +
			'<button type="button" id="scolta-amazee-disconnect" class="button button-secondary">' + escText( __( 'Disconnect', 'scolta-ai-search' ) ) + '</button>'
		);
		bindDisconnect();
	}

	function renderStart() {
		clearError();
		app.html(
			'<p>' + escText( __( 'Connect Scolta to Amazee.ai for privacy-respecting, budget-aware AI search.', 'scolta-ai-search' ) ) + '</p>' +
			'<label for="scolta-amazee-email">' + escText( __( 'Email address', 'scolta-ai-search' ) ) + '</label>' +
			'<input type="email" id="scolta-amazee-email" class="regular-text" />' +
			'<p>' +
			'<button type="button" id="scolta-amazee-trial" class="button button-primary">' + escText( __( 'Start free trial', 'scolta-ai-search' ) ) + '</button>' +
			' <button type="button" id="scolta-amazee-signin" class="button button-secondary">' + escText( __( 'Sign in to existing account', 'scolta-ai-search' ) ) + '</button>' +
			'</p>'
		);
		bindStart();
	}

	function renderVerification( email ) {
		clearError();
		app.html(
			'<p>' +
			sprintf(
				/* translators: %s: email address */
				escText( __( 'A verification code has been sent to %s. Enter it below.', 'scolta-ai-search' ) ),
				'<strong>' + escText( email ) + '</strong>'
			) +
			'</p>' +
			'<label for="scolta-amazee-code">' + escText( __( 'Verification code', 'scolta-ai-search' ) ) + '</label>' +
			'<input type="text" id="scolta-amazee-code" class="regular-text" autocomplete="one-time-code" />' +
			'<p>' +
			'<button type="button" id="scolta-amazee-verify" class="button button-primary">' + escText( __( 'Verify code', 'scolta-ai-search' ) ) + '</button>' +
			' <button type="button" id="scolta-amazee-back" class="button button-secondary">' + escText( __( 'Back', 'scolta-ai-search' ) ) + '</button>' +
			'</p>'
		);
		bindVerification();
	}

	function renderRegions( regions ) {
		clearError();
		selectedRegionId = null;
		var html = '<fieldset>';
		regions.forEach( function ( r ) {
			html += '<label><input type="radio" name="scolta_region" value="' + escText( r.id ) + '" /> ' + escText( r.name ) + '</label><br/>';
		} );
		html += '</fieldset>';
		$( '#scolta-amazee-regions-loading' ).hide();
		var list = $( '#scolta-amazee-regions-list' );
		list.html( html ).show();
		list.find( 'input[type=radio]' ).on( 'change', function () {
			selectedRegionId = $( this ).val();
			$( '#scolta-amazee-connect' ).show();
		} );
		$( '#scolta-amazee-connect' ).show();
	}

	function bindDisconnect() {
		$( '#scolta-amazee-disconnect' ).on( 'click', function () {
			$( this ).prop( 'disabled', true );
			post( 'scolta_amazee_disconnect', {}, function () {
				renderStart();
			}, showError );
		} );
	}

	function bindStart() {
		$( '#scolta-amazee-trial' ).on( 'click', function () {
			var email = $( '#scolta-amazee-email' ).val().trim();
			$( this ).prop( 'disabled', true );
			post( 'scolta_amazee_start_trial', { email: email }, function ( data ) {
				if ( data.step === 'connected' ) {
					location.reload();
				}
			}, function ( msg ) {
				$( '#scolta-amazee-trial' ).prop( 'disabled', false );
				showError( msg );
			} );
		} );

		$( '#scolta-amazee-signin' ).on( 'click', function () {
			var email = $( '#scolta-amazee-email' ).val().trim();
			$( this ).prop( 'disabled', true );
			post( 'scolta_amazee_request_code', { email: email }, function ( data ) {
				renderVerification( data.email );
			}, function ( msg ) {
				$( '#scolta-amazee-signin' ).prop( 'disabled', false );
				showError( msg );
			} );
		} );
	}

	function bindVerification() {
		$( '#scolta-amazee-verify' ).on( 'click', function () {
			var code = $( '#scolta-amazee-code' ).val().trim();
			$( this ).prop( 'disabled', true );
			post( 'scolta_amazee_verify_code', { code: code }, function () {
				loadRegions();
			}, function ( msg ) {
				$( '#scolta-amazee-verify' ).prop( 'disabled', false );
				showError( msg );
			} );
		} );

		$( '#scolta-amazee-back' ).on( 'click', function () {
			renderStart();
		} );
	}

	function loadRegions() {
		app.html(
			'<p>' + escText( __( 'Select the region where your AI requests will be processed.', 'scolta-ai-search' ) ) + '</p>' +
			'<p id="scolta-amazee-regions-loading">' + __( 'Loading regions&hellip;', 'scolta-ai-search' ) + '</p>' +
			'<div id="scolta-amazee-regions-list" style="display:none;"></div>' +
			'<p>' +
			'<button type="button" id="scolta-amazee-connect" class="button button-primary" style="display:none;">' + escText( __( 'Connect', 'scolta-ai-search' ) ) + '</button>' +
			' <button type="button" id="scolta-amazee-back" class="button button-secondary">' + escText( __( 'Back', 'scolta-ai-search' ) ) + '</button>' +
			'</p>'
		);

		$( '#scolta-amazee-back' ).on( 'click', function () {
			renderStart();
		} );

		$( '#scolta-amazee-connect' ).on( 'click', function () {
			if ( ! selectedRegionId ) { return; }
			$( this ).prop( 'disabled', true );
			post( 'scolta_amazee_connect', { region_id: selectedRegionId }, function () {
				location.reload();
			}, function ( msg ) {
				$( '#scolta-amazee-connect' ).prop( 'disabled', false );
				showError( msg );
			} );
		} );

		post( 'scolta_amazee_list_regions', {}, function ( data ) {
			renderRegions( data.regions );
		}, showError );
	}

	$( function () {
		app = $( '#scolta-amazee-app' );
		if ( ! app.length ) { return; }

		var step = app.data( 'step' );

		if ( step === 'connected' ) {
			bindDisconnect();
		} else if ( step === 'verification' ) {
			bindVerification();
		} else if ( step === 'region' ) {
			loadRegions();
		} else {
			bindStart();
		}
	} );
}( jQuery ) );
