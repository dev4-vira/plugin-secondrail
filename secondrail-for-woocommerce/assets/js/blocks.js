/**
 * Registers the SecondRail payment method in the block checkout. No card field is rendered: the
 * order is placed and the shopper is redirected to SecondRail's hosted payment page.
 */
( function () {
	'use strict';

	var registry = window.wc.wcBlocksRegistry;
	var settings = window.wc.wcSettings.getSetting( 'secondrail_data', {} );
	var createElement = window.wp.element.createElement;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;
	var title = decodeEntities( settings.title || '' );

	function Content() {
		return createElement( 'p', null, decodeEntities( settings.description || '' ) );
	}

	function Label( props ) {
		return createElement( props.components.PaymentMethodLabel, { text: title } );
	}

	registry.registerPaymentMethod( {
		name: 'secondrail',
		label: createElement( Label ),
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: { features: settings.supports || [] },
	} );
} )();
