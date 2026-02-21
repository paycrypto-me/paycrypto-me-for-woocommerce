const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
    ...defaultConfig,
    entry: {
        'paycrypto_me-blocks': [
            path.resolve(process.cwd(), 'includes/blocks/js/paycrypto_me-blocks.js'),
            path.resolve(process.cwd(), 'includes/blocks/scss/paycrypto_me-blocks.scss'),
        ],
    },
    output: {
        ...defaultConfig.output,
        path: path.resolve(process.cwd(), 'assets/blocks/'),
        filename: '[name].js',
    },
    plugins: [
        ...(defaultConfig.plugins || []),
        new MiniCssExtractPlugin({ filename: '[name].css' }),
    ],
    externals: {
        '@woocommerce/blocks-registry': ['wc', 'wcBlocksRegistry'],
        '@woocommerce/settings': ['wc', 'wcSettings'],
        '@wordpress/element': 'wp.element',
        '@wordpress/i18n': 'wp.i18n',
        '@wordpress/html-entities': 'wp.htmlEntities',
    },
};