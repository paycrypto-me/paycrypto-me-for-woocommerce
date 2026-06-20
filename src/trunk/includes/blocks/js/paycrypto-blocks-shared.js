import { decodeEntities } from '@wordpress/html-entities';
import { createElement, useCallback, useEffect } from '@wordpress/element';

export const isCheckoutPage = typeof document !== 'undefined'
    && document.body?.classList.contains('woocommerce-checkout');

export function createPaymentComponents(settings, label) {
    function buildExpressButton({ onClick, buttonAttributes }) {
        // Apply inline styles only when WC Blocks explicitly provides the value.
        // CSS in _express-button.scss acts as the visual default; inline overrides it when
        // the merchant configures the block editor's express payment style controls.
        const style = {};
        if (buttonAttributes?.height) style.height = `${buttonAttributes.height}px`;
        if (buttonAttributes?.borderRadius !== undefined) style.borderRadius = `${buttonAttributes.borderRadius}px`;

        const props = {
            className: 'wc-paycrypto-me-express-button',
            style,
            title: decodeEntities(settings.description || ''),
            type: 'button',
        };

        if (typeof onClick === 'function') {
            props.onClick = () => onClick();
        }

        const expressText = decodeEntities(settings.express_payment_text || '') || label;
        const iconSrc = settings.show_express_icon !== false
            ? (settings.express_icon || settings.icon)
            : null;
        const icon = iconSrc
            ? createElement('img', {
                src: decodeEntities(iconSrc),
                alt: label,
                className: 'wc-paycrypto-me-express-button__icon',
              })
            : null;

        const children = settings.express_icon_position === 'right'
            ? [expressText, icon]
            : [icon, expressText];

        return createElement('button', props, children);
    }

    const Content = ({ eventRegistration, emitResponse }) => {
        const onPaymentSetup = eventRegistration?.onPaymentSetup;
        const successType = emitResponse?.responseTypes?.SUCCESS || 'success';
        const description = decodeEntities(settings.description || '');

        const getPaymentSetupResponse = useCallback(() => ({
            type: successType,
            meta: { paymentMethodData: { paycrypto_me_crypto_currency: settings.crypto_currency } },
        }), [successType]);

        useEffect(() => {
            if (typeof onPaymentSetup !== 'function') return undefined;
            const unsubscribe = onPaymentSetup(() => getPaymentSetupResponse());
            return () => { if (typeof unsubscribe === 'function') unsubscribe(); };
        }, [onPaymentSetup, getPaymentSetupResponse]);

        if (!description) return null;
        return createElement('div', {
            className: 'wc-paycrypto-me-description',
            dangerouslySetInnerHTML: { __html: description },
        });
    };

    // Dedicated component for the express payment button slot.
    // WooCommerce Blocks does not pass eventRegistration.onPaymentSetup to express content
    // components in all versions — this component handles both cases explicitly.
    const ExpressContent = ({ eventRegistration, emitResponse, onClick, buttonAttributes }) => {
        // Run once on mount. Reading eventRegistration/emitResponse inside the closure avoids
        // adding them as deps — in the express context they may be new objects each render,
        // which would cause an infinite re-registration loop.
        useEffect(() => {
            const onPaymentSetup = eventRegistration?.onPaymentSetup;
            if (typeof onPaymentSetup !== 'function') return undefined;
            const successType = emitResponse?.responseTypes?.SUCCESS || 'success';
            const unsubscribe = onPaymentSetup(() => ({
                type: successType,
                meta: { paymentMethodData: { paycrypto_me_crypto_currency: settings.crypto_currency } },
            }));
            return () => { if (typeof unsubscribe === 'function') unsubscribe(); };
        }, []); // eslint-disable-line react-hooks/exhaustive-deps

        // WC Blocks passes onClick to trigger checkout. In some versions onClick() alone
        // does not flush the before-processing phase, so we dispatch it explicitly as fallback.
        const handleClick = useCallback(() => {
            if (typeof onClick === 'function') onClick();
            try {
                window.wp?.data
                    ?.dispatch('wc/store/checkout')
                    ?.__internalSetBeforeProcessing?.();
            } catch (_) {}
        }, [onClick]);

        return buildExpressButton({ onClick: handleClick, buttonAttributes });
    };

    return { Content, ExpressContent };
}
