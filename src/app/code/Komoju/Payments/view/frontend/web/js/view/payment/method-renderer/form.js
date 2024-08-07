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
            var self = this;

            self.isDataLoaded(false);

            $.get(url.build('komoju/komojufield/komojusessiondata'))
            .done(function (response) {
                self.komojuSession(response.komojuSession);
                self.isDataLoaded(true);
                self.komojuToken(null);
            });
        },

        submitPayment: function () {
            var komojuField = document.querySelector('komoju-fields[payment-type=' + this.komojuMethod() + ']');

            if (komojuField) {
                return new Promise(function (resolve, reject) {
                    komojuField.addEventListener('komoju-invalid', reject);
                    komojuField.submit().then(function (token) {
                        komojuField.removeEventListener('komoju-invalid', reject);
                        if (token) {
                            resolve(token);
                        } else {
                            reject(new Error("Token not found"));
                        }
                    }).catch(function (error) {
                        komojuField.removeEventListener('komoju-invalid', reject);
                        reject(error);
                    });
                });
            }
            return Promise.reject(new Error("Komoju fields component not found"));
        },

        afterPlaceOrder: function() {
            var self = this;
            var redirectUrl = url.build('checkout/onepage/success');
            var message = $t("There was an error obtaining the payment token. Please try again.");

            if (!self.komojuFieldEnabledMethods.includes(self.komojuMethod()) || !self.komojuToken()) {
                redirectUrl = this.redirectUrl() + "?payment_method=" + this.komojuMethod();
                $.mage.redirect(redirectUrl);
                return;
            }

            self.sendToken(self.komojuToken()).done(function(response) {
                if (response.success) {
                    if (response.data && response.data.redirect_url) {
                        redirectUrl = response.data.redirect_url;
                    }
                    $.mage.redirect(redirectUrl);
                } else {
                    messageList.addErrorMessage({ message: response.message });
                    fullScreenLoader.stopLoader();
                }
            }).fail(function(error) {
                console.error('Error during token submission:', error);
                message = $t("There was an error processing your payment. Please try again.");
                messageList.addErrorMessage({ message: message });
                fullScreenLoader.stopLoader();
            });
        },

        getData: function() {
            return {
                'method': this.getCode(),
                'additional_data': null
            };
        },

        placeOrder: function (data, event) {
            var self = this;
            var boundSuper = this._super.bind(this);
            var message = $t("There was an error processing your payment. Please try again.");

            if (!this.validate()) {
                return false;
            }

            fullScreenLoader.startLoader();

            if (!self.komojuFieldEnabledMethods.includes(self.komojuMethod())) {
                self.komojuToken(null);
                boundSuper(data, event);
                return;
            }

            self.submitPayment().then(function (token) {
                self.komojuToken(token);
                boundSuper(data, event);
            }).catch(function (error) {
                console.error('Error during token submission:', JSON.stringify(error));

                messageList.addErrorMessage({ message: message });
                fullScreenLoader.stopLoader();
            });
        },

        sendToken: function (token) {
            var redirectUrl = this.redirectUrl() + "?payment_method=" + this.komojuMethod();
            var serviceUrl = url.build('komoju/komojufield/processToken');
            var data = {
                'id': this.komojuSession().id,
                'token': token
            };

            if (!token) {
                $.mage.redirect(redirectUrl);
                return;
            }

            return $.ajax({
                url: serviceUrl,
                type: 'POST',
                data: JSON.stringify(data),
                contentType: 'application/json',
                success: function () {},
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

        getLocale: function() {
            var config = this.getConfig();

            return config.locale;
        },

        getPublishableKey: function () {
            var config = this.getConfig();

            return config.publishable_key;
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
