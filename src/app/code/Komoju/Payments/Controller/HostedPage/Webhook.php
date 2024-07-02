<?php

namespace Komoju\Payments\Controller\HostedPage;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

use Komoju\Payments\Model\WebhookEvent;
use Komoju\Payments\Plugin\WebhookEventProcessor;

/**
 * API endpoint to receive Webhook notifications from Komoju. To make the
 * endpoint a POST endpoint a Magento Action needs to implement the
 * HttpPostActionInterface. And because all POST have automatic CSRF checking,
 * and as this is a API endpoint to receive JSON requests it doesn't have a
 * CSRF token, this class is also implementing the CsrfAwareActionInterface
 * interface and disabling checking by returning false from the inherited methods.
 */
class Webhook extends \Magento\Framework\App\Action\Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @var \Magento\Framework\Controller\ResultFactory
     */
    protected $_resultFactory;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \Komoju\Payments\Gateway\Config\Config
     */
    private $config;

    /**
     * @var \Komoju\Payments\Model\ExternalPayment
     */
    private $externalPayment;

    /**
     * @var \Komoju\Payments\Model\RefundFactory
     */
    private $komojuRefundFactory;

    /**
     * @var \Magento\Sales\Model\Order|false
     */
    private $order = false;

    /**
     * @var \Magento\Sales\Model\Order\CreditmemoFactory
     */
    private $creditmemoFactory;

    /**
     * @var \Magento\Sales\Model\Service\CreditmemoService
     */
    private $creditmemoService;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Controller\ResultFactory $resultFactory,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Komoju\Payments\Model\ExternalPaymentFactory $externalPaymentFactory,
        \Komoju\Payments\Model\RefundFactory $komojuRefundFactory,
        \Komoju\Payments\Gateway\Config\Config $config,
        \Psr\Log\LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?: ObjectManager::getInstance()->get(\Psr\Log\LoggerInterface::class);
        $this->_resultFactory = $resultFactory;
        $this->orderRepository = $orderRepository;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->externalPayment = $externalPaymentFactory->create();
        $this->komojuRefundFactory = $komojuRefundFactory;
        $this->config = $config;

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

        $this->logger->info('HMAC Valid, processing event');

        $requestBody = $this->getRequest()->getContent();
        $webhookEvent = new WebhookEvent($requestBody);

        $this->logger->info('event type: ' . $webhookEvent->eventType());

        $externalOrderNum = $webhookEvent->externalOrderNum();
        try {
            $order = $this->getOrder($externalOrderNum);

            $webhookEventProcessor = new WebhookEventProcessor(
                $this->creditmemoFactory,
                $this->creditmemoService,
                $this->komojuRefundFactory,
                $this->logger,
                $webhookEvent,
                $order
            );
            $webhookEventProcessor->processEvent();
        } catch (NoSuchEntityException $exception) {
            // if we can't find a matching komoju_external_payment then we're
            // assuming that the order belongs to another system
            $message = 'No matching records found for external_order_num: ' . $externalOrderNum . '. Ignoring event';
            $this->logger->info($message);
        }


        $result = $this->_resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode(200);
        $result->setData('');

        $this->logger->info('result: ' . json_encode($result));

        return $result;
    }

    /**
     * Required to be implemented by the CsrfAwareActionInterface. Since request
     * validation is being handled by the X-Komoju-Signature HMAC we will never
     * throw a CSRF validation exception. Returning null defaults to using
     * Magento's internal exception creation, which doesn't matter since it
     * will never be executed.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Required to be implemented by the CsrfAwareActionInterface. By default
     * all non-AJAX post requests do CSRF checking. Because the webhook requests
     * are being validated with the HMAC header, and this is not a form, we can't
     * validate with CSRF tokens. To prevent this from throwing an error we need
     * to explicitly return true to overwrite the check.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Finds the order that maps to the external_order_num sent in the webhook
     * If it can't find a matching Order then we're assuming that the order belongs
     * to a separate system and ignoring any events sent for it.
     * @var string $externalOrderNum
     * @return \Magento\Sales\Api\Data\OrderInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function getOrder($externalOrderNum)
    {
        $this->logger->info('$externalOrderNum:' . $externalOrderNum);
        $payment = $this->externalPayment->getCollection()->getRecordForExternalOrderNum($externalOrderNum);
        $orderId = $payment->getSalesOrderId();

        return $this->orderRepository->get($orderId);
    }

    /**
     * Checks the HMAC header to ensure that the request has come from
     * Komoju and has not been modified along the way. hash_equals is a
     * consistent time string comparison method, so we're safe from timing
     * attacks.
     * @return bool
     */
    private function isHmacValid()
    {
        $hmacHeader = $this->getRequest()->getHeader('x-komoju-signature');
        $webhookSecretToken = $this->config->getWebhookSecretToken();
        $reqBody = $this->getRequest()->getContent();

        $calculatedHmac = hash_hmac('sha256', $reqBody, $webhookSecretToken);

        return hash_equals($hmacHeader, $calculatedHmac);
    }
}
