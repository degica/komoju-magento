define(
    [
        "jquery",
        "Magento_Checkout/js/view/payment/default",
        "Magento_Checkout/js/model/full-screen-loader",
        "Magento_Checkout/js/action/select-payment-method",
        "Magento_Checkout/js/checkout-data",
        "ko",
        "Magento_Ui/js/model/messageList",
        "mage/translate",
        "mage/url"
    ],
    function (
        $,
        Component,
        fullScreenLoader,
        selectPaymentMethodAction,
        checkoutData,
        ko,
        messageList,
        $t,
        url
    ) {
    "use strict";
    return Component.extend({
        defaults: {
            template: "Komoju_Payments/payment/form",
            active: true,
            redirectAfterPlaceOrder: false,
            komojuMethod: ko.observable(''),
            komojuSession: ko.observable(''),
            isDataLoaded: ko.observable(false),
            komojuToken: ko.observable(''),
            komojuFieldEnabledMethods: ['credit_card', 'konbini', 'bank_transfer']
        },

        initialize: function () {
            this._super();
            this.loadKomojuData();
        },

        loadKomojuData: function () {
            const self = this;
            self.isDataLoaded(false);

            $.get(url.build('komoju/komojufield/komojusessiondata'))
            .done(function (response) {
                self.komojuSession(response.komojuSession);
                self.isDataLoaded(true);
                self.komojuToken(null);
            });
        },

        submitPayment: function () {
            const komojuField = document.querySelector(`komoju-fields[payment-type='${this.komojuMethod()}']`);

            if (komojuField) {
                return new Promise((resolve, reject) => {
                    komojuField.addEventListener('komoju-invalid', reject);
                    komojuField.submit().then(token => {
                        komojuField.removeEventListener('komoju-invalid', reject);
                        if (token) {
                            resolve(token);
                        } else {
                            reject(new Error("Token not found"));
                        }
                    }).catch(error => {
                        komojuField.removeEventListener('komoju-invalid', reject);
                        reject(error);
                    });
                });
            } else {
                return Promise.reject(new Error("Komoju fields component not found"));
            }
        },

        afterPlaceOrder: function() {
            if (this.komojuToken) {
                this.sendToken(this.komojuToken()).done(function(response) {
                    if (response.success) {
                        let redirectUrl = url.build('checkout/onepage/success');

                        if (response.data && response.data.redirect_url) {
                            redirectUrl = response.data.redirect_url
                        }
                        $.mage.redirect(
                            redirectUrl
                        );
                    } else {
                        messageList.addErrorMessage({ message: response.message });
                    }
                }).fail(function(error) {
                    console.error('Error during token submission:', error);
                    messageList.addErrorMessage({ message: $t("There was an error processing your payment. Please try again.") });
                    fullScreenLoader.stopLoader();
                });
            } else {
                console.error('No token available for submission.');
                messageList.addErrorMessage({ message: $t("There was an error obtaining the payment token. Please try again.") });
            }
        },

        getData: function() {
          return {
              'method': this.getCode(),
              'additional_data': null
          };
        },

        placeOrder: function (data, event) {
            if (!this.validate()) {
                return false;
            }

            fullScreenLoader.startLoader();

            const boundSuper = this._super.bind(this);

            if (this.komojuFieldEnabledMethods.includes(this.komojuMethod())) {
                this.submitPayment().then(token => {
                    this.komojuToken(token);
                    boundSuper(data, event);
                }).catch(error => {
                    console.error('Error during token submission:', error);
                    messageList.addErrorMessage({ message: $t("There was an error processing your payment. Please try again.") });
                    fullScreenLoader.stopLoader();
                });
            } else {
                this.komojuToken(null);
                boundSuper(data, event);
            }
        },

        sendToken: function (token) {
            if (!token) {
                const redirectUrl = this.redirectUrl() + "?payment_method=" + this.komojuMethod();
                $.mage.redirect(
                    redirectUrl
                );
                return;
            }

            const serviceUrl = url.build('komoju/komojufield/processToken');

            const data = {
                'id': this.komojuSession().id,
                'token': token
            }

            return $.ajax({
                url: serviceUrl,
                type: 'POST',
                data: JSON.stringify(data),
                contentType: 'application/json',
                success: function (response) { },
                error: function (xhr, status, error) {
                    console.error('Failed to send token:', error);
                }
            });
        },

        selectPaymentMethod: function () {
            this.moveBillingForm();
            selectPaymentMethodAction(this.getData());
            checkoutData.setSelectedPaymentMethod(this.getCode());
            return true;
        },

        moveBillingForm: function () {
            var billingForm = document.querySelector('.komoju_payment_billing');
            var currentNode = document.querySelector('#' + this.komojuMethod() + '-node');

            currentNode.appendChild(billingForm);
        },

        getConfig: function() {
            var code = this.getCode();

            return window.checkoutConfig.payment[code];
        },

        getAvailablePaymentMethods: function() {
            var config = this.getConfig();

            return config.available_payment_methods;
        },

        redirectUrl: function () {
            var config = this.getConfig();

            return config.redirect_url;
        },

        getTitle: function () {
            var config = this.getConfig();

            return config.title;
        },

        getSession: function () {
            return JSON.stringify(this.komojuSession());
        },

        showTitle: function () {
            var config = this.getConfig();

            return config.show_title;
        },

        getEnabledPaymentTypes: function() {
            var options = [];
            var option;
            var availablePaymentMethods = this.getAvailablePaymentMethods();

            for (option in availablePaymentMethods) {
                if (Object.prototype.hasOwnProperty.call(availablePaymentMethods, option)) {
                    options.push({
                        value: option,
                        displayText: availablePaymentMethods[option],
                    });
                }
            }

            if (options.length === 0) {
                messageList.addErrorMessage(
                {message: $t("Encountered an issue communicating with KOMOJU. Please wait a moment and try again.")}
                );
            }

            return options;
        }
    });
});
