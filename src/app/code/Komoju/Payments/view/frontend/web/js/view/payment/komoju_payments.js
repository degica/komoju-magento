define(
    [
    "uiComponent",
    "Magento_Checkout/js/model/payment/renderer-list",
    ],
    function (
        Component,
        rendererList
    ) {
    "useStrict";
    rendererList.push({
        type: "komoju_payments",
        component: "Komoju_Payments/js/view/payment/method-renderer/cc-form",
    });

    return Component.extend({

        initialize: function () {
            var self = this;

            this._super();
            
            
            console.log('hey hey hey')
        }
    });
});
