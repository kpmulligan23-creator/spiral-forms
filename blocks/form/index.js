( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;
	var data = window.SpiralFormsBlock || { forms: [], newUrl: '' };

	var options = [ { value: 0, label: __( '— Select a form —', 'spiral-forms' ) } ].concat( data.forms );

	function picker( props ) {
		return el( SelectControl, {
			label: __( 'Form', 'spiral-forms' ),
			value: props.attributes.formId,
			options: options,
			onChange: function ( v ) {
				props.setAttributes( { formId: parseInt( v, 10 ) || 0 } );
			},
		} );
	}

	wp.blocks.registerBlockType( 'spiral-forms/form', {
		edit: function ( props ) {
			var blockProps = useBlockProps();
			var inspector = el( InspectorControls, null, el( PanelBody, { title: __( 'Form', 'spiral-forms' ) }, picker( props ) ) );

			if ( ! props.attributes.formId ) {
				return el( 'div', blockProps, inspector,
					el( Placeholder, { icon: 'feedback', label: __( 'Spiral Form', 'spiral-forms' ) },
						data.forms.length
							? picker( props )
							: el( 'a', { href: data.newUrl, target: '_blank' }, __( 'Create your first form', 'spiral-forms' ) )
					)
				);
			}

			return el( 'div', blockProps, inspector,
				el( 'div', { style: { pointerEvents: 'none' } },
					el( ServerSideRender, { block: 'spiral-forms/form', attributes: props.attributes } )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
