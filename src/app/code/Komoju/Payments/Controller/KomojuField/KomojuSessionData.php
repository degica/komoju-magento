<?php

namespace Komoju\Payments\Controller\KomojuField;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Quote\Model\QuoteRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Cart;
use Komoju\Payments\Api\KomojuApi;
use Komoju\Payments\Gateway\Config\Config;

class KomojuSessionData extends Action
{
    protected JsonFactory $jsonResultFactory;
    protected QuoteRepository $quoteRepository;
    protected CheckoutSession $checkoutSession;
    private KomojuApi $komojuApi;
    private Config $config;
    private Cart $cart;

    public function __construct(
        Context $context,
        JsonFactory $jsonResultFactory,
        QuoteRepository $quoteRepository,
        CheckoutSession $checkoutSession,
        KomojuApi $komojuApi,
        Config $config,
        Cart $cart,
    ) {
        $this->jsonResultFactory = $jsonResultFactory;
        $this->quoteRepository = $quoteRepository;
        $this->checkoutSession = $checkoutSession;
        $this->komojuApi = $komojuApi;
        $this->config = $config;
        $this->cart = $cart;
        parent::__construct($context);
    }

    public function execute()
    {
        $quote = $this->checkoutSession->getQuote();
        $totalAmount = $quote->getGrandTotal();
        $currencyCode = $quote->getStoreCurrencyCode();
        $customerEmail = $quote->getCustomerEmail();
        $paymentMethod = $this->getRequest()->getParam('payment_method');

        $komojuSession = $this->createKomojuSession(
            $paymentMethod,
            $totalAmount,
            $currencyCode,
            $customerEmail,
            $quote
        );

        $result = $this->jsonResultFactory->create();
        return $result->setData([
            'komojuSession' => $komojuSession,
            'totalAmount' => $totalAmount,
            'currency' => $currencyCode
        ]);
    }

    private function createKomojuSession($paymentMethod, $totalAmount, $currencyCode, $customerEmail, $quote)
    {
        return $this->komojuApi->createSession([
            'amount' => $totalAmount,
            'currency' => $currencyCode,
            'default_locale' => $this->config->getKomojuLocale(),
            // 'payment_methods' => $this->komojuApi->paymentMethods(),
            'payment_types' => [$paymentMethod],
            'email' => $customerEmail,
            'metadata' => [
                'note' => 'testing'
            ]
        ]);
    }

    private function convertAddress($address)
    {
        return [
            'name' => $address->getName(),
            'street' => $address->getStreetFull(),
            'city' => $address->getCity(),
            'region' => $address->getRegion(),
            'postcode' => $address->getPostcode(),
            'country' => $address->getCountryId()
        ];
    }

    public function getKomojuSessionData() {
        $paymentMethod = $this->getRequest()->getParam('payment_method');
        $order = $this->getOrder();
        $billingAddress = $order->getBillingAddress();
        $externalOrderNum = $this->createExternalPayment($order);
        $currencyCode = $this->storeManager->getStore()->getBaseCurrencyCode();
        $returnUrl = $this->createReturnUrl($order->getEntityId());

        $billing_address = $this->convertToAddressParameter($order->getBillingAddress());
        $shipping_address = $this->convertToAddressParameter($order->getShippingAddress());

        $komojuSession = $this->komojuApi->createSession([
          'return_url' => $returnUrl,
          'default_locale' => $this->config->getKomojuLocale(),
          'payment_types' => [$paymentMethod],
          'email' => $order->getCustomerEmail(),
          'payment_data' => [
              'name' => $order->getCustomerName(),
              'amount' => $order->getGrandTotal(),
              'currency' => $currencyCode,
              'external_order_num' => $externalOrderNum,
              'billing_address'    => $billing_address,
              'shipping_address'   => $shipping_address,
          ],
        ]);

        return $komojuSession;
    }
}
