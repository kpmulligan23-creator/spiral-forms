/* Spiral Forms — front end: prevent double submits, focus messages after reload. */
( function () {
	'use strict';
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form.classList || ! form.classList.contains( 'sf-form' ) ) {
			return;
		}
		if ( form.dataset.sfSubmitting ) {
			e.preventDefault();
			return;
		}
		form.dataset.sfSubmitting = '1';
		var btn = form.querySelector( '.sf-submit' );
		if ( btn ) {
			btn.disabled = true;
		}
	} );

	// Reset if the page is restored from bfcache (back button).
	window.addEventListener( 'pageshow', function () {
		document.querySelectorAll( '.sf-form' ).forEach( function ( form ) {
			delete form.dataset.sfSubmitting;
			var btn = form.querySelector( '.sf-submit' );
			if ( btn ) {
				btn.disabled = false;
			}
		} );
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		var target = document.querySelector( '.sf-confirmation, .sf-error-summary' );
		if ( ! target ) {
			return;
		}
		var firstError = document.querySelector( '.sf-has-error input, .sf-has-error select, .sf-has-error textarea' );
		if ( firstError ) {
			firstError.focus();
		} else {
			target.setAttribute( 'tabindex', '-1' );
			target.focus();
		}
	} );
} )();
