<?php

namespace Komoju\Payments\Controller\HostedPage;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Action\Action;
use Magento\Sales\Api\OrderRepositoryInterface;
use Komoju\Payments\Gateway\Config\Config;
use Psr\Log\LoggerInterface;
use Magento\Checkout\Model\Session;
use Komoju\Payments\Api\KomojuApi;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Event\ManagerInterface;

/**
 * The PostSessionRedirect endpoint serves as the return URL param for Komoju
 * sessions and is responsible for redirecting customers after visiting the session.
 * If the komoju session is detected as completed, the customer is redirected to Magento's
 * payment complete page.
 * If the session was not  completed, the order is canceled and the customer is
 * redirected back to the store's home page. To prevent this endpoint from being
 * used maliciously the Redirect endpoint is generating a HMAC and appending it to
 * the PostSessionRedirect URL, which is being used to ensure that the request hasn't been
 * tampered with.
 */
class PostSessionRedirect extends Action
{
    protected ResultFactory $_resultFactory;
    private LoggerInterface $logger;
    protected Session $_checkoutSession;
    private Config $config;
    private OrderRepositoryInterface $orderRepository;
    private KomojuApi $komojuApi;
    private ManagerInterface $eventManager;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        ResultFactory $resultFactory,
        Context $context,
        Config $config,
        LoggerInterface $logger = null,
        Session $checkoutSession,
        KomojuApi $komojuApi
    ) {
        $this->logger = $logger ?: ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class);
        $this->_resultFactory = $resultFactory;
        $this->config = $config;
        $this->orderRepository = $orderRepository;
        $this->_checkoutSession = $checkoutSession;
        $this->komojuApi = $komojuApi;

        return parent::__construct($context);
    }

    public function execute()
    {
        if (!$this->isHmacValid()) {
            $this->logger->info('HMAC param does not match expected value, exiting.');
            $result = $this->_resultFactory->create(ResultFactory::TYPE_RAW);
            $result->setHttpResponseCode(401);
            $result->setContents('hmac parameter is not valid');

            return $result;
        };

        $resultRedirect = $this->_resultFactory->create(ResultFactory::TYPE_REDIRECT);

        if ($this->isSessionCompleted()) {
            $successUrl = $this->processSuccessOrder();
            $resultRedirect->setUrl($successUrl);
        } else {
            $redirectUrl = $this->processFailedOrder();
            $resultRedirect->setUrl($redirectUrl);
        }
        return $resultRedirect;
    }

    private function processSuccessOrder()
    {
        return $this->_url->getUrl('checkout/onepage/success');
    }

    /**
     * If an order can be cancelled, cancel the order and restore the items to cart
     * and return the checkout url. Otherwise return the home page url
     * @return string
     */
    private function processFailedOrder()
    {
        $orderId = $this->getRequest()->getParam('order_id');
        $order = $this->getOrder($orderId);
        if ($order->canCancel()) {
            $this->_checkoutSession->restoreQuote();
            $order->registerCancellation();
            $order->save();
            return $this->_url->getUrl('checkout', ['_fragment' => 'payment']);
        }
        return $this->_url->getUrl('/');
    }

    /**
     * Gets the order resource that matches the orderId passed into the URL
     * @var int $orderId
     * @return \Magento\Sales\Model\Order
     */
    private function getOrder($orderId)
    {
        return $this->orderRepository->get($orderId);
    }

    /**
     * Checks whether the session status (payment) is 'completed' on Komoju
     * @return bool
     */
    private function isSessionCompleted()
    {
        $sessionId = $this->getRequest()->getParam('session_id');
        $session = $this->komojuApi->session($sessionId);
        return $session->status == 'completed';
    }

    /**
     * Checks to ensure that the hmac param is valid. Since this endpoint
     * has the ability to cancel orders it's important that any requests to
     * it ensure that the URL was generated by the Komoju plugin and have not
     * been modified
     * @return bool
     */
    private function isHmacValid()
    {
        $requestParams = $this->getRequest()->getParams();
        $orderId = $requestParams['order_id'];
        $hmacParam = rtrim($requestParams['hmac_magento'], "/");
        $secretKey = $this->config->getSecretKey();
        $urlForComp = 'komoju/hostedpage/postsessionredirect?order_id=' . $orderId;
        $calculatedHmac = hash_hmac('sha256', $urlForComp, $secretKey);

        return hash_equals($hmacParam, $calculatedHmac);
    }
}