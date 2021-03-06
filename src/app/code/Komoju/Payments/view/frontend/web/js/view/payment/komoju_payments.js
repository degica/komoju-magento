define(
    [
    "uiComponent",
    "Magento_Checkout/js/model/payment/renderer-list",
    ],
    function (
        Component,
        rendererList
    ) {
    "use strict";
    rendererList.push({
        type: "komoju_payments",
        component: "Komoju_Payments/js/view/payment/method-renderer/form",
    });

    return Component.extend({});
});
