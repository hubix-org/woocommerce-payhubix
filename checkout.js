const settings = window.wc.wcSettings.getSetting( 'payhubix_gateway_data', {} );
const label = window.wp.htmlEntities.decodeEntities( settings.title ) || window.wp.i18n.__( 'PayHubix', 'payhubix_gateway' );
const Content = () => {
    return window.wp.htmlEntities.decodeEntities( settings.description || '' );
};
const Block_Gateway = {
    name: 'payhubix_gateway',
    label: label,
    content: Object( window.wp.element.createElement )( Content, null ),
    edit: Object( window.wp.element.createElement )( Content, null ),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway );