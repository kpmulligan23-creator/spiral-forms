/* Spiral Forms — front end: show/hide rules, AJAX submit, reCAPTCHA v3, double-submit guard. */
( function () {
	'use strict';

	var cfg = window.SpiralForms || { ajaxUrl: '', i18n: {} };

	/* ---------- Show/hide rules (mirrors Spiral_Forms_Forms::is_visible) ---------- */

	function fieldValue( form, id ) {
		var wrap = form.querySelector( '[data-sf-field="' + id + '"]' );
		if ( ! wrap || wrap.hidden ) {
			return [];
		}
		var out = [];
		wrap.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
			if ( ( el.type === 'checkbox' || el.type === 'radio' ) && ! el.checked ) {
				return;
			}
			if ( el.value !== '' ) {
				out.push( el.value );
			}
		} );
		// Composite fields (name/address) compare as one string, like the server.
		if ( wrap.querySelector( '.sf-parts' ) ) {
			return out.length ? [ out.join( ' ' ) ] : [];
		}
		return out;
	}

	function evaluate( form, cond ) {
		var results = cond.rules.map( function ( r ) {
			var list = fieldValue( form, r.field );
			switch ( r.operator ) {
				case 'empty': return ! list.length;
				case 'not_empty': return !! list.length;
				case 'is_not': return list.indexOf( r.value ) === -1;
				case 'contains': return list.join( ' ' ).toLowerCase().indexOf( String( r.value ).toLowerCase() ) !== -1;
				default: return list.indexOf( r.value ) !== -1;
			}
		} );
		var match = cond.match === 'any' ? results.indexOf( true ) !== -1 : results.indexOf( false ) === -1;
		return cond.action === 'show' ? match : ! match;
	}

	function applyConditions( form ) {
		// Loop until stable so chains (A shows B, B shows C) resolve.
		for ( var pass = 0; pass < 5; pass++ ) {
			var changed = false;
			form.querySelectorAll( '[data-sf-cond]' ).forEach( function ( wrap ) {
				var cond;
				try {
					cond = JSON.parse( wrap.getAttribute( 'data-sf-cond' ) );
				} catch ( e ) {
					return;
				}
				var show = evaluate( form, cond );
				if ( wrap.hidden === show ) {
					wrap.hidden = ! show;
					changed = true;
				}
			} );
			if ( ! changed ) {
				break;
			}
		}
	}

	/* ---------- Errors / messages ---------- */

	function clearErrors( form ) {
		var wrap = form.closest( '.sf-wrap' );
		wrap.querySelectorAll( '.sf-error, .sf-error-summary' ).forEach( function ( el ) {
			el.remove();
		} );
		wrap.querySelectorAll( '.sf-has-error' ).forEach( function ( el ) {
			el.classList.remove( 'sf-has-error' );
		} );
		wrap.querySelectorAll( '[aria-invalid]' ).forEach( function ( el ) {
			el.removeAttribute( 'aria-invalid' );
		} );
	}

	function showErrors( form, data ) {
		var wrap = form.closest( '.sf-wrap' );
		var summary = document.createElement( 'div' );
		summary.className = 'sf-error-summary';
		summary.setAttribute( 'role', 'alert' );
		summary.textContent = data.message || cfg.i18n.error;
		wrap.insertBefore( summary, form );

		var first = null;
		Object.keys( data.errors || {} ).forEach( function ( id ) {
			if ( id === '_form' ) {
				return;
			}
			var field = form.querySelector( '[data-sf-field="' + id + '"]' );
			if ( ! field ) {
				return;
			}
			field.classList.add( 'sf-has-error' );
			var msg = document.createElement( 'div' );
			msg.className = 'sf-error';
			msg.textContent = data.errors[ id ];
			field.appendChild( msg );
			field.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
				el.setAttribute( 'aria-invalid', 'true' );
			} );
			first = first || field.querySelector( 'input, select, textarea' );
		} );
		if ( first ) {
			first.focus();
		} else {
			summary.setAttribute( 'tabindex', '-1' );
			summary.focus();
		}
	}

	function resetCaptcha( form ) {
		if ( window.turnstile && form.querySelector( '.cf-turnstile' ) ) {
			try { window.turnstile.reset( form.querySelector( '.cf-turnstile' ) ); } catch ( e ) {}
		}
		if ( window.grecaptcha && form.querySelector( '.g-recaptcha' ) ) {
			try { window.grecaptcha.reset(); } catch ( e ) {}
		}
	}

	function setBusy( form, busy ) {
		var btn = form.querySelector( '.sf-submit' );
		if ( busy ) {
			form.dataset.sfSubmitting = '1';
		} else {
			delete form.dataset.sfSubmitting;
		}
		if ( btn ) {
			btn.disabled = busy;
			if ( busy ) {
				btn.dataset.label = btn.textContent;
				btn.textContent = cfg.i18n.sending || btn.textContent;
			} else if ( btn.dataset.label ) {
				btn.textContent = btn.dataset.label;
			}
		}
	}

	/* ---------- File checks (the server re-checks everything) ---------- */

	function checkFiles( form ) {
		var errors = {};
		form.querySelectorAll( 'input[type="file"][data-max-bytes]' ).forEach( function ( input ) {
			var wrap = input.closest( '[data-sf-field]' );
			if ( ! wrap || wrap.hidden || ! input.files ) {
				return;
			}
			var id = wrap.getAttribute( 'data-sf-field' );
			var maxFiles = parseInt( input.getAttribute( 'data-max-files' ), 10 ) || 1;
			var maxBytes = parseInt( input.getAttribute( 'data-max-bytes' ), 10 ) || 0;
			if ( input.files.length > maxFiles ) {
				errors[ id ] = ( cfg.i18n.tooMany || '' ).replace( '%d', maxFiles );
				return;
			}
			for ( var i = 0; i < input.files.length; i++ ) {
				if ( maxBytes && input.files[ i ].size > maxBytes ) {
					errors[ id ] = ( cfg.i18n.tooBig || '' ).replace( '%s', input.files[ i ].name );
					return;
				}
			}
		} );
		return errors;
	}

	/* ---------- Submit ---------- */

	function send( form ) {
		if ( ! window.fetch || ! window.FormData || ! cfg.ajaxUrl ) {
			form.submit();
			return;
		}
		var body = new FormData( form );
		body.append( 'action', 'spiral_forms_submit' );
		body.append( 'sf_page_url', window.location.href.split( '#' )[ 0 ] );

		fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				var data = res.data || {};
				if ( res.success ) {
					if ( data.redirect ) {
						window.location.href = data.redirect;
						return;
					}
					var wrap = form.closest( '.sf-wrap' );
					var box = document.createElement( 'div' );
					box.className = 'sf-confirmation';
					box.setAttribute( 'role', 'status' );
					box.setAttribute( 'tabindex', '-1' );
					box.innerHTML = data.message || '';
					wrap.innerHTML = '';
					wrap.appendChild( box );
					box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					box.focus( { preventScroll: true } );
					return;
				}
				setBusy( form, false );
				resetCaptcha( form );
				showErrors( form, data );
			} )
			.catch( function () {
				// Network/parse problem: fall back to a normal POST so nothing is lost.
				form.submit();
			} );
	}

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form.classList || ! form.classList.contains( 'sf-form' ) ) {
			return;
		}
		e.preventDefault();
		if ( form.dataset.sfSubmitting ) {
			return;
		}
		clearErrors( form );
		var fileErrors = checkFiles( form );
		if ( Object.keys( fileErrors ).length ) {
			showErrors( form, { errors: fileErrors, message: cfg.i18n.fix } );
			return;
		}
		setBusy( form, true );

		var v3 = form.querySelector( '.sf-recaptcha-v3' );
		if ( v3 && window.grecaptcha && window.grecaptcha.execute ) {
			window.grecaptcha.ready( function () {
				window.grecaptcha.execute( v3.getAttribute( 'data-sitekey' ), { action: 'submit' } ).then( function ( token ) {
					v3.value = token;
					send( form );
				}, function () {
					send( form );
				} );
			} );
			return;
		}
		send( form );
	} );

	document.addEventListener( 'input', onChange );
	document.addEventListener( 'change', onChange );
	function onChange( e ) {
		var form = e.target.closest && e.target.closest( '.sf-form' );
		if ( form ) {
			applyConditions( form );
		}
	}

	// Reset if the page is restored from bfcache (back button).
	window.addEventListener( 'pageshow', function () {
		document.querySelectorAll( '.sf-form' ).forEach( function ( form ) {
			setBusy( form, false );
		} );
	} );

	function init() {
		document.querySelectorAll( '.sf-form' ).forEach( applyConditions );
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
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
