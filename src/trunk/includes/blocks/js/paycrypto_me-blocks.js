import { registerPaymentMethod, registerExpressPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { createElement } from '@wordpress/element';
import { isCheckoutPage, createPaymentComponents } from './paycrypto-blocks-shared.js';

const settings = getSetting('paycrypto_me_data', {});
const label = decodeEntities(settings.title) || __('Pay with Bitcoin', 'paycrypto-me-for-woocommerce');

const { Content, ExpressContent } = createPaymentComponents(settings, label);

const Label = ({ components }) =>
    createElement(components.PaymentMethodLabel, { text: label, className: 'wc-paycrypto-me-label' });

registerPaymentMethod({
    name: 'paycrypto_me',
    label: createElement(Label),
    content: createElement(Content),
    edit: createElement(Content),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: { features: settings.supports || ['products'] },
});

if (settings.enable_express_payment && isCheckoutPage) {
    registerExpressPaymentMethod({
        name: 'paycrypto_me_express',
        title: label,
        description: decodeEntities(settings.description || ''),
        content: createElement(ExpressContent),
        edit: createElement(ExpressContent),
        canMakePayment: () => settings.enable_express_payment,
        paymentMethodId: 'paycrypto_me',
        gatewayId: settings.gateway_id || 'paycrypto_me',
        supports: { features: settings.supports || ['products'], style: ['height', 'borderRadius'] },
    });
}

if (process.env.NODE_ENV === 'development') {
    console.log('PayCrypto.Me payment method registered');
    console.log('PayCrypto.Me settings:', settings);
}
