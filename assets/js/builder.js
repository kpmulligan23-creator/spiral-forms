/* Spiral Forms builder. Plain JS + jQuery UI Sortable (bundled with WordPress). */
( function ( $ ) {
	'use strict';

	var cfg = window.SpiralFormsBuilder;
	var t = cfg.i18n;
	var $data = $( '#sf-form-data' );
	var state = JSON.parse( $data.val() || '{}' );
	state.fields = state.fields || [];
	state.settings = state.settings || {};
	state.settings.notifications = state.settings.notifications || [];

	var typeMap = {};
	cfg.types.forEach( function ( ty ) {
		typeMap[ ty.type ] = ty;
	} );

	var openIndex = -1;

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function slug( s ) {
		return String( s || '' ).toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' ).slice( 0, 40 ) || 'field';
	}

	function uniqueId( base, skipIndex ) {
		var id = base, n = 2;
		var taken = function ( v ) {
			return state.fields.some( function ( f, i ) {
				return i !== skipIndex && f.id === v;
			} );
		};
		while ( taken( id ) ) {
			id = base + '_' + n++;
		}
		return id;
	}

	function sync() {
		var out = JSON.parse( JSON.stringify( state ) );
		out.fields.forEach( function ( f ) {
			delete f._auto;
		} );
		$data.val( JSON.stringify( out ) );
	}

	/* ---------- Fields ---------- */

	function renderPalette() {
		var html = '';
		cfg.types.forEach( function ( ty ) {
			html += '<button type="button" class="button sf-add-field" data-type="' + esc( ty.type ) + '">' + esc( ty.label ) + '</button>';
		} );
		$( '#sf-palette-buttons' ).html( html );
	}

	function input( key, label, value, type ) {
		return '<p><label>' + esc( label ) + '<br><input type="' + ( type || 'text' ) + '" class="widefat" data-key="' + key + '" value="' + esc( value ) + '"></label></p>';
	}

	function fieldBody( f ) {
		var ty = typeMap[ f.type ] || {};
		var html = '';
		html += input( 'label', t.label, f.label );
		html += input( 'id', t.fieldId, f.id );
		html += '<p><label><input type="checkbox" data-key="required"' + ( f.required ? ' checked' : '' ) + '> ' + esc( t.required ) + '</label></p>';
		if ( ty.options ) {
			html += '<p><label>' + esc( t.options ) + '<br><textarea class="widefat" rows="5" data-key="options">' + esc( ( f.options || [] ).join( '\n' ) ) + '</textarea></label></p>';
		}
		if ( [ 'checkbox', 'radio', 'date', 'address' ].indexOf( f.type ) === -1 && ! ( f.type === 'name' && f.format === 'first-last' ) ) {
			html += input( 'placeholder', t.placeholder, f.placeholder );
		}
		if ( f.type === 'number' ) {
			html += '<div class="sf-row">' + input( 'min', t.min, f.min, 'number' ) + input( 'max', t.max, f.max, 'number' ) + '</div>';
		}
		html += '<p><label>' + esc( t.description ) + '<br><textarea class="widefat" rows="2" data-key="description">' + esc( f.description ) + '</textarea></label></p>';
		if ( f.type === 'name' ) {
			html += '<p><label>' + esc( t.nameFormat ) + '<br><select data-key="format">'
				+ '<option value=""' + ( f.format !== 'first-last' ? ' selected' : '' ) + '>' + esc( t.nameSimple ) + '</option>'
				+ '<option value="first-last"' + ( f.format === 'first-last' ? ' selected' : '' ) + '>' + esc( t.nameFirstLast ) + '</option>'
				+ '</select></label></p>';
		}
		if ( [ 'address' ].indexOf( f.type ) === -1 && ! ( f.type === 'name' && f.format === 'first-last' ) ) {
			html += input( 'default', t.default, f.default );
		}
		html += input( 'css_class', t.cssClass, f.css_class );
		html += conditionalUI( f );
		return html;
	}

	function conditionalUI( f ) {
		var c = f.conditional || {};
		var others = state.fields.filter( function ( o ) {
			return o.id !== f.id;
		} );
		var html = '<div class="sf-cond">'
			+ '<p><label><input type="checkbox" data-cond="enabled"' + ( c.enabled ? ' checked' : '' ) + ( others.length ? '' : ' disabled' ) + '> ' + esc( t.condEnable ) + '</label></p>';
		if ( ! c.enabled ) {
			return html + '</div>';
		}
		html += '<p class="sf-cond-head"><select data-cond="action">'
			+ '<option value="show"' + ( c.action !== 'hide' ? ' selected' : '' ) + '>' + esc( t.condShow ) + '</option>'
			+ '<option value="hide"' + ( c.action === 'hide' ? ' selected' : '' ) + '>' + esc( t.condHide ) + '</option>'
			+ '</select> ' + esc( t.condWhen ) + ' <select data-cond="match">'
			+ '<option value="all"' + ( c.match !== 'any' ? ' selected' : '' ) + '>' + esc( t.condAll ) + '</option>'
			+ '<option value="any"' + ( c.match === 'any' ? ' selected' : '' ) + '>' + esc( t.condAny ) + '</option>'
			+ '</select> ' + esc( t.condOfThese ) + '</p>';
		( c.rules || [] ).forEach( function ( r, ri ) {
			var target = state.fields.filter( function ( o ) {
				return o.id === r.field;
			} )[ 0 ];
			html += '<div class="sf-cond-rule" data-rule="' + ri + '"><select data-rkey="field">';
			others.forEach( function ( o ) {
				html += '<option value="' + esc( o.id ) + '"' + ( o.id === r.field ? ' selected' : '' ) + '>' + esc( o.label || o.id ) + '</option>';
			} );
			html += '</select> <select data-rkey="operator">';
			[ [ 'is', t.opIs ], [ 'is_not', t.opIsNot ], [ 'contains', t.opContains ], [ 'empty', t.opEmpty ], [ 'not_empty', t.opNotEmpty ] ].forEach( function ( op ) {
				html += '<option value="' + op[ 0 ] + '"' + ( r.operator === op[ 0 ] ? ' selected' : '' ) + '>' + esc( op[ 1 ] ) + '</option>';
			} );
			html += '</select> ';
			if ( r.operator !== 'empty' && r.operator !== 'not_empty' ) {
				if ( target && target.options && target.options.length && r.operator !== 'contains' ) {
					html += '<select data-rkey="value"><option value=""></option>';
					target.options.forEach( function ( o ) {
						html += '<option value="' + esc( o ) + '"' + ( o === r.value ? ' selected' : '' ) + '>' + esc( o ) + '</option>';
					} );
					html += '</select>';
				} else {
					html += '<input type="text" data-rkey="value" value="' + esc( r.value ) + '">';
				}
			}
			html += ' <button type="button" class="button-link sf-rule-del" title="' + esc( t.remove ) + '"><span class="dashicons dashicons-no-alt"></span></button></div>';
		} );
		html += '<p><button type="button" class="button button-small sf-rule-add">' + esc( t.condAddRule ) + '</button></p></div>';
		return html;
	}

	function rerenderBody( $card ) {
		var f = state.fields[ $card.data( 'index' ) ];
		$card.find( '.sf-field-body' ).html( fieldBody( f ) );
		sync();
	}

	function newRule( f ) {
		var other = state.fields.filter( function ( o ) {
			return o.id !== f.id;
		} )[ 0 ];
		return { field: other ? other.id : '', operator: 'is', value: '' };
	}

	function renderFields() {
		var $list = $( '#sf-fields' );
		if ( ! state.fields.length ) {
			$list.html( '<li class="sf-no-fields">' + esc( t.noFields ) + '</li>' );
			sync();
			return;
		}
		var html = '';
		state.fields.forEach( function ( f, i ) {
			var ty = typeMap[ f.type ] || { label: f.type };
			var open = i === openIndex;
			html += '<li class="sf-field-card' + ( open ? ' is-open' : '' ) + '" data-index="' + i + '">'
				+ '<div class="sf-field-head">'
				+ '<span class="sf-handle dashicons dashicons-move" title="Drag"></span>'
				+ '<button type="button" class="sf-toggle" aria-expanded="' + open + '">'
				+ '<strong class="sf-field-title">' + esc( f.label || '(' + ty.label + ')' ) + '</strong>'
				+ ( f.required ? ' <span class="sf-req">*</span>' : '' )
				+ ' <span class="sf-type">' + esc( ty.label ) + '</span>'
				+ ' <code class="sf-id">{field:' + esc( f.id ) + '}</code>'
				+ '</button>'
				+ '<span class="sf-actions">'
				+ '<button type="button" class="button-link sf-up" title="' + esc( t.moveUp ) + '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>'
				+ '<button type="button" class="button-link sf-down" title="' + esc( t.moveDown ) + '"><span class="dashicons dashicons-arrow-down-alt2"></span></button>'
				+ '<button type="button" class="button-link sf-dup" title="' + esc( t.duplicate ) + '"><span class="dashicons dashicons-admin-page"></span></button>'
				+ '<button type="button" class="button-link sf-del" title="' + esc( t.remove ) + '"><span class="dashicons dashicons-trash"></span></button>'
				+ '</span></div>'
				+ '<div class="sf-field-body"' + ( open ? '' : ' hidden' ) + '>' + fieldBody( f ) + '</div>'
				+ '</li>';
		} );
		$list.html( html );
		sync();
		renderTagList();
	}

	function move( from, to ) {
		if ( to < 0 || to >= state.fields.length ) {
			return;
		}
		var f = state.fields.splice( from, 1 )[ 0 ];
		state.fields.splice( to, 0, f );
		if ( openIndex === from ) {
			openIndex = to;
		}
		renderFields();
	}

	$( document ).on( 'click', '.sf-add-field', function () {
		var type = $( this ).data( 'type' );
		var ty = typeMap[ type ];
		var f = {
			id: uniqueId( slug( ty.label ) ),
			type: type,
			label: ty.label,
			required: 0,
			placeholder: '',
			description: '',
			default: '',
			options: ty.options ? [ t.newChoice, 'Second choice', 'Third choice' ] : [],
			css_class: '',
			min: '',
			max: '',
			format: type === 'name' ? 'first-last' : '',
			conditional: { enabled: 0, action: 'show', match: 'all', rules: [] },
			_auto: true,
		};
		state.fields.push( f );
		openIndex = state.fields.length - 1;
		renderFields();
		var $card = $( '#sf-fields .sf-field-card' ).last();
		$card[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'center' } );
		$card.find( '[data-key="label"]' ).trigger( 'focus' ).trigger( 'select' );
	} );

	$( '#sf-fields' )
		.on( 'click', '.sf-toggle', function () {
			var i = $( this ).closest( '.sf-field-card' ).data( 'index' );
			openIndex = openIndex === i ? -1 : i;
			renderFields();
		} )
		.on( 'click', '.sf-up', function () {
			var i = $( this ).closest( '.sf-field-card' ).data( 'index' );
			move( i, i - 1 );
		} )
		.on( 'click', '.sf-down', function () {
			var i = $( this ).closest( '.sf-field-card' ).data( 'index' );
			move( i, i + 1 );
		} )
		.on( 'click', '.sf-dup', function () {
			var i = $( this ).closest( '.sf-field-card' ).data( 'index' );
			var copy = JSON.parse( JSON.stringify( state.fields[ i ] ) );
			copy.id = uniqueId( copy.id );
			delete copy._auto;
			state.fields.splice( i + 1, 0, copy );
			openIndex = i + 1;
			renderFields();
		} )
		.on( 'click', '.sf-del', function () {
			if ( ! window.confirm( t.confirmDel ) ) {
				return;
			}
			var i = $( this ).closest( '.sf-field-card' ).data( 'index' );
			state.fields.splice( i, 1 );
			openIndex = -1;
			renderFields();
		} )
		.on( 'change', '[data-cond]', function () {
			var $card = $( this ).closest( '.sf-field-card' );
			var f = state.fields[ $card.data( 'index' ) ];
			f.conditional = f.conditional && f.conditional.rules ? f.conditional : { enabled: 0, action: 'show', match: 'all', rules: [] };
			var key = $( this ).data( 'cond' );
			f.conditional[ key ] = this.type === 'checkbox' ? ( this.checked ? 1 : 0 ) : $( this ).val();
			if ( key === 'enabled' && f.conditional.enabled && ! f.conditional.rules.length ) {
				f.conditional.rules.push( newRule( f ) );
			}
			rerenderBody( $card );
		} )
		.on( 'input change', '[data-rkey]', function ( e ) {
			var $card = $( this ).closest( '.sf-field-card' );
			var f = state.fields[ $card.data( 'index' ) ];
			var rule = f.conditional.rules[ $( this ).closest( '.sf-cond-rule' ).data( 'rule' ) ];
			var key = $( this ).data( 'rkey' );
			rule[ key ] = $( this ).val();
			if ( key !== 'value' && e.type === 'change' ) {
				if ( key === 'field' ) {
					rule.value = '';
				}
				rerenderBody( $card );
			} else {
				sync();
			}
		} )
		.on( 'click', '.sf-rule-add', function () {
			var $card = $( this ).closest( '.sf-field-card' );
			var f = state.fields[ $card.data( 'index' ) ];
			f.conditional.rules.push( newRule( f ) );
			rerenderBody( $card );
		} )
		.on( 'click', '.sf-rule-del', function () {
			var $card = $( this ).closest( '.sf-field-card' );
			var f = state.fields[ $card.data( 'index' ) ];
			f.conditional.rules.splice( $( this ).closest( '.sf-cond-rule' ).data( 'rule' ), 1 );
			if ( ! f.conditional.rules.length ) {
				f.conditional.enabled = 0;
			}
			rerenderBody( $card );
		} )
		.on( 'input change', '[data-key]', function ( e ) {
			var $card = $( this ).closest( '.sf-field-card' );
			var i = $card.data( 'index' );
			var f = state.fields[ i ];
			var key = $( this ).data( 'key' );
			var val = this.type === 'checkbox' ? ( this.checked ? 1 : 0 ) : $( this ).val();

			if ( key === 'options' ) {
				val = val.split( '\n' ).map( function ( s ) {
					return s.trim();
				} ).filter( Boolean );
			}
			if ( key === 'id' ) {
				f._auto = false;
				if ( e.type === 'change' ) {
					val = uniqueId( slug( val ), i );
					$( this ).val( val );
				}
			}
			f[ key ] = val;
			if ( key === 'format' ) {
				rerenderBody( $card );
				return;
			}

			if ( key === 'label' && f._auto ) {
				f.id = uniqueId( slug( val ), i );
				$card.find( '[data-key="id"]' ).val( f.id );
			}

			// Update header without a full re-render (keeps focus).
			var ty = typeMap[ f.type ] || { label: f.type };
			$card.find( '.sf-field-title' ).text( f.label || '(' + ty.label + ')' );
			$card.find( '.sf-id' ).text( '{field:' + f.id + '}' );
			$card.find( '.sf-req' ).remove();
			if ( f.required ) {
				$card.find( '.sf-field-title' ).after( ' <span class="sf-req">*</span>' );
			}
			sync();
			if ( key === 'id' || key === 'label' ) {
				renderTagList();
			}
		} );

	$( '#sf-fields' ).sortable( {
		handle: '.sf-handle',
		items: '> .sf-field-card',
		placeholder: 'sf-sort-placeholder',
		forcePlaceholderSize: true,
		update: function () {
			var order = $( '#sf-fields' ).children( '.sf-field-card' ).map( function () {
				return $( this ).data( 'index' );
			} ).get();
			var openField = state.fields[ openIndex ];
			state.fields = order.map( function ( i ) {
				return state.fields[ i ];
			} );
			openIndex = openField ? state.fields.indexOf( openField ) : -1;
			renderFields();
		},
	} );

	/* ---------- Settings ---------- */

	function renderSettings() {
		$( '[data-setting]' ).each( function () {
			var key = $( this ).data( 'setting' );
			var val = state.settings[ key ];
			if ( this.type === 'checkbox' ) {
				this.checked = !! parseInt( val, 10 );
			} else if ( this.type === 'radio' ) {
				this.checked = this.value === val;
			} else {
				$( this ).val( val == null ? '' : val );
			}
		} );
		toggleConfirm();
	}

	function toggleConfirm() {
		var redirect = state.settings.confirmation_type === 'redirect';
		$( '.sf-when-message' ).toggle( ! redirect );
		$( '.sf-when-redirect' ).toggle( redirect );
	}

	$( document ).on( 'input change', '[data-setting]', function () {
		var key = $( this ).data( 'setting' );
		if ( this.type === 'checkbox' ) {
			state.settings[ key ] = this.checked ? 1 : 0;
		} else if ( this.type === 'radio' ) {
			if ( this.checked ) {
				state.settings[ key ] = this.value;
			}
		} else {
			state.settings[ key ] = $( this ).val();
		}
		toggleConfirm();
		sync();
	} );

	/* ---------- Notifications ---------- */

	function nInput( i, key, label, value, wide ) {
		return '<p><label>' + esc( label ) + '<br><input type="text" class="' + ( wide ? 'large-text' : 'regular-text' ) + '" data-n="' + i + '" data-nkey="' + key + '" value="' + esc( value ) + '"></label></p>';
	}

	function tagSelect( i, key ) {
		var opts = '<option value="">' + esc( t.insertTag ) + '</option>';
		state.fields.forEach( function ( f ) {
			opts += '<option value="{field:' + esc( f.id ) + '}">' + esc( f.label || f.id ) + '</option>';
		} );
		Object.keys( cfg.smartTags ).forEach( function ( tag ) {
			if ( tag.indexOf( 'FIELD_ID' ) === -1 ) {
				opts += '<option value="' + esc( tag ) + '">' + esc( tag ) + '</option>';
			}
		} );
		return '<select class="sf-insert-tag" data-n="' + i + '" data-nkey="' + key + '">' + opts + '</select>';
	}

	function renderNotifications() {
		var html = '';
		state.settings.notifications.forEach( function ( n, i ) {
			html += '<div class="sf-notification postbox">'
				+ '<div class="sf-n-head"><label><input type="checkbox" data-n="' + i + '" data-nkey="enabled"' + ( parseInt( n.enabled, 10 ) ? ' checked' : '' ) + '> <strong>' + esc( n.label || t.notification + ' ' + ( i + 1 ) ) + '</strong></label>'
				+ ' <button type="button" class="button-link sf-n-del" data-n="' + i + '">' + esc( t.remove ) + '</button></div>'
				+ '<div class="sf-n-body">'
				+ nInput( i, 'label', t.name, n.label )
				+ '<div class="sf-with-tag">' + nInput( i, 'to', t.sendTo, n.to ) + tagSelect( i, 'to' ) + '</div>'
				+ '<div class="sf-with-tag">' + nInput( i, 'subject', t.subject, n.subject, true ) + tagSelect( i, 'subject' ) + '</div>'
				+ '<p><label>' + esc( t.message ) + '<br><textarea class="large-text" rows="7" data-n="' + i + '" data-nkey="message">' + esc( n.message ) + '</textarea></label>' + tagSelect( i, 'message' ) + '</p>'
				+ '<div class="sf-row">' + nInput( i, 'from_name', t.fromName, n.from_name ) + nInput( i, 'from_email', t.fromEmail, n.from_email ) + '</div>'
				+ '<div class="sf-with-tag">' + nInput( i, 'reply_to', t.replyTo, n.reply_to ) + tagSelect( i, 'reply_to' ) + '</div>'
				+ '</div></div>';
		} );
		$( '#sf-notifications' ).html( html );
		sync();
	}

	function renderTagList() {
		var parts = state.fields.map( function ( f ) {
			return '<code>{field:' + esc( f.id ) + '}</code>';
		} );
		Object.keys( cfg.smartTags ).forEach( function ( tag ) {
			if ( tag.indexOf( 'FIELD_ID' ) === -1 ) {
				parts.push( '<code title="' + esc( cfg.smartTags[ tag ] ) + '">' + esc( tag ) + '</code>' );
			}
		} );
		$( '#sf-tag-list' ).html( parts.join( ' ' ) );
	}

	$( '#sf-notifications' )
		.on( 'input change', 'input[data-nkey], textarea[data-nkey]', function () {
			var n = state.settings.notifications[ $( this ).data( 'n' ) ];
			var key = $( this ).data( 'nkey' );
			n[ key ] = this.type === 'checkbox' ? ( this.checked ? 1 : 0 ) : $( this ).val();
			sync();
		} )
		.on( 'change', '.sf-insert-tag', function () {
			var tag = $( this ).val();
			if ( ! tag ) {
				return;
			}
			var $target = $( '[data-n="' + $( this ).data( 'n' ) + '"][data-nkey="' + $( this ).data( 'nkey' ) + '"]' ).not( 'select' );
			var el = $target[ 0 ];
			var pos = typeof el.selectionStart === 'number' ? el.selectionStart : el.value.length;
			el.value = el.value.slice( 0, pos ) + tag + el.value.slice( pos );
			$target.trigger( 'input' );
			$( this ).val( '' );
		} )
		.on( 'click', '.sf-n-del', function () {
			if ( window.confirm( t.confirmDel ) ) {
				state.settings.notifications.splice( $( this ).data( 'n' ), 1 );
				renderNotifications();
			}
		} );

	$( '#sf-add-notification' ).on( 'click', function () {
		state.settings.notifications.push( {
			enabled: 1,
			label: t.notification + ' ' + ( state.settings.notifications.length + 1 ),
			to: '{admin_email}',
			subject: 'New entry: {form_name}',
			message: '{all_fields}',
			from_name: '{site_name}',
			from_email: '',
			reply_to: '',
		} );
		renderNotifications();
	} );

	/* ---------- Tabs ---------- */

	$( '.sf-tabs' ).on( 'click', '.nav-tab', function ( e ) {
		e.preventDefault();
		var tab = $( this ).data( 'tab' );
		$( '.sf-tabs .nav-tab' ).removeClass( 'nav-tab-active' );
		$( this ).addClass( 'nav-tab-active' );
		$( '.sf-tab-panel' ).attr( 'hidden', true ).filter( '[data-panel="' + tab + '"]' ).removeAttr( 'hidden' );
		if ( tab === 'notifications' ) {
			renderNotifications(); // refresh field list in tag dropdowns
		}
		history.replaceState( null, '', '#' + tab );
	} );

	/* ---------- Unsaved-changes guard ---------- */

	var initial = null;
	$( '#sf-builder-form' ).on( 'submit', function () {
		sync();
		initial = null;
		$( window ).off( 'beforeunload' );
	} );
	$( window ).on( 'beforeunload', function () {
		if ( initial !== null && initial !== $data.val() ) {
			return true;
		}
	} );

	renderPalette();
	renderFields();
	renderSettings();
	renderNotifications();
	renderTagList();
	initial = $data.val();

	var hash = ( window.location.hash || '' ).replace( '#', '' );
	if ( hash ) {
		$( '.sf-tabs .nav-tab[data-tab="' + hash + '"]' ).trigger( 'click' );
	}
} )( jQuery );
