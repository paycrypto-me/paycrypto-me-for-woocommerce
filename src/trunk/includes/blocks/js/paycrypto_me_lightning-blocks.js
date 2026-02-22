/**
 * Lightweight PayCrypto.Me Blocks integration
 * Minimal, tree-shakeable and optimized for the Lightning variant.
 */
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { createElement } from '@wordpress/element';
import { useEffect } from 'react';

const settings = getSetting('paycrypto_me_lightning_data', {});
console.log(settings);

const labelText = decodeEntities(settings.title || __('Pay with Bitcoin', 'paycrypto-me-for-woocommerce'));
const description = settings.description ? decodeEntities(settings.description) : '';

const Content = ({ eventRegistration, emitResponse }) => {
	useEffect(() => {
		const unsubscribe = eventRegistration.onPaymentSetup(async () => ({
			type: emitResponse.responseTypes.SUCCESS,
			meta: { paymentMethodData: { paycrypto_me_crypto_currency: settings.crypto_currency } },
		}));

		return () => {
			if (typeof unsubscribe === 'function') {
				unsubscribe();
			}
		};
	}, [eventRegistration, emitResponse, settings.crypto_currency]);

	if (!description) {
		return null;
	}

	return createElement('div', {
		className: 'wc-paycrypto-me-description',
		dangerouslySetInnerHTML: { __html: description },
	});
};

registerPaymentMethod({
	name: 'paycrypto_me_lightning',
	label: labelText,
	content: createElement(Content),
	edit: createElement(Content),
	canMakePayment: () => true,
	ariaLabel: labelText,
	supports: { features: settings.supports || ['products'] },
});