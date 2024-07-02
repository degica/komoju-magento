<?php

namespace Komoju\Payments\Model;

use Komoju\Payments\Api\KomojuApi;
use Magento\Quote\Model\QuoteRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Komoju\Payments\Gateway\Config\Config;

class KomojuApiService
{
    protected KomojuApi $komojuApi;
    protected Config $config;
    protected CheckoutSession $checkoutSession;

    public function __construct(
        KomojuApi $komojuApi,
        Config $config,
        CheckoutSession $checkoutSession
    ) {
        $this->komojuApi = $komojuApi;
        $this->config = $config;
        $this->checkoutSession = $checkoutSession;
    }

    public function createKomojuSession($paymentMethod)
    {
        $quote = $this->checkoutSession->getQuote();
        $totalAmount = $quote->getGrandTotal();
        $currencyCode = $quote->getStoreCurrencyCode();
        $customerEmail = $quote->getCustomerEmail();

        return $this->komojuApi->createSession([
            'amount' => $totalAmount,
            'currency' => $currencyCode,
            'default_locale' => $this->config->getKomojuLocale(),
            'payment_types' => [$paymentMethod],
            'email' => $customerEmail,
            'metadata' => ['note' => 'testing']
        ]);
    }
}
