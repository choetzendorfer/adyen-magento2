<?php
/**
 *                       ######
 *                       ######
 * ############    ####( ######  #####. ######  ############   ############
 * #############  #####( ######  #####. ######  #############  #############
 *        ######  #####( ######  #####. ######  #####  ######  #####  ######
 * ###### ######  #####( ######  #####. ######  #####  #####   #####  ######
 * ###### ######  #####( ######  #####. ######  #####          #####  ######
 * #############  #############  #############  #############  #####  ######
 *  ############   ############  #############   ############  #####  ######
 *                                      ######
 *                               #############
 *                               ############
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2015 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Controller\Process;

use Magento\Framework\App\Request\Http as Http;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterface;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterfaceFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment as OrderPaymentResource;
use Magento\Payment\Model\InfoInterface;

class Redirect extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Quote\Model\Quote
     */
    protected $_quote = false;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * @var \Magento\Sales\Model\Order
     */
    protected $_order;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var \Adyen\Payment\Logger\AdyenLogger
     */
    protected $_adyenLogger;

    /**
     * @var \Adyen\Payment\Helper\Data
     */
    protected $_adyenHelper;

    /**
     * @var \Adyen\Payment\Model\Api\PaymentRequest
     */
    protected $_paymentRequest;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $_orderRepository;

    /**
     * @var PaymentTokenFactoryInterface
     */
    private $paymentTokenFactory;

    /**
     * @var OrderPaymentExtensionInterfaceFactory
     */
    private $paymentExtensionFactory;

    /**
     * @var OrderPaymentResource
     */
    private $orderPaymentResource;

    /**
     * @var \Magento\Framework\Serialize\SerializerInterface
     */
    private $serializer;

    /**
     * Redirect constructor.
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Adyen\Payment\Logger\AdyenLogger $adyenLogger
     * @param \Adyen\Payment\Helper\Data $adyenHelper
     * @param \Adyen\Payment\Model\Api\PaymentRequest $paymentRequest
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param PaymentTokenFactoryInterface $paymentTokenFactory
     * @param OrderPaymentExtensionInterfaceFactory $paymentExtensionFactory
     * @param OrderPaymentResource $orderPaymentResource
     * @param \Magento\Framework\Serialize\SerializerInterface $serializer
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Adyen\Payment\Logger\AdyenLogger $adyenLogger,
        \Adyen\Payment\Helper\Data $adyenHelper,
        \Adyen\Payment\Model\Api\PaymentRequest $paymentRequest,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        PaymentTokenFactoryInterface $paymentTokenFactory,
        OrderPaymentExtensionInterfaceFactory $paymentExtensionFactory,
        OrderPaymentResource $orderPaymentResource,
        \Magento\Framework\Serialize\SerializerInterface $serializer
    ) {
        parent::__construct($context);
        $this->_adyenLogger = $adyenLogger;
        $this->_adyenHelper = $adyenHelper;
        $this->_paymentRequest = $paymentRequest;
        $this->_orderRepository = $orderRepository;
        $this->paymentTokenFactory = $paymentTokenFactory;
        $this->paymentExtensionFactory = $paymentExtensionFactory;
        $this->orderPaymentResource = $orderPaymentResource;
        $this->serializer = $serializer;
        if (interface_exists(\Magento\Framework\App\CsrfAwareActionInterface::class)) {
            $request = $this->getRequest();
            if ($request instanceof Http && $request->isPost()) {
                $request->setParam('isAjax', true);
                $request->getHeaders()->addHeaderLine('X_REQUESTED_WITH', 'XMLHttpRequest');
            }
        }
    }

    /**
     * Validate 3D secure payment
     */
    public function execute()
    {
        $active = null;

        // check if 3d is active
        $order = $this->_getOrder();

        if ($order->getPayment()) {
            $active = $order->getPayment()->getAdditionalInformation('3dActive');
            $success = $order->getPayment()->getAdditionalInformation('3dSuccess');
            $checkoutAPM = $order->getPayment()->getAdditionalInformation('checkoutAPM');
        }

        // check if 3D secure is active. If not just go to success page
        if ($active && $success != true) {
            $this->_adyenLogger->addAdyenResult("3D secure is active");

            // check if the GET request contains the required 3DS params
            if ($this->getRequest()->getParam('PaRes') && $this->getRequest()->getParam('MD')) {
                $this->_adyenLogger->addAdyenResult("Process 3D secure payment");
                $requestMD = $this->getRequest()->getParam('MD');
                $requestPaRes = $this->getRequest()->getParam('PaRes');

                //Reset the payment's additional info to the new MD and PaRes
                $order->getPayment()->setAdditionalInformation('md', $requestMD);
                $order->getPayment()->setAdditionalInformation('paRequest', $requestPaRes);

                $order->getPayment()->setAdditionalInformation('paResponse', $requestPaRes);

                try {
                    $result = $this->_authorise3d($order->getPayment());
                    $responseCode = $result['resultCode'];
                } catch (\Exception $e) {
                    $this->_adyenLogger->addAdyenResult("Process 3D secure payment was refused");
                    $responseCode = 'Refused';
                }

                $this->_adyenLogger->addAdyenResult("Process 3D secure payment result is: " . $responseCode);

                // check if authorise3d was successful
                if ($responseCode == 'Authorised') {
                    $order->addStatusHistoryComment(__('3D-secure validation was successful'))->save();
                    // set back to false so when pressed back button on the success page
                    // it will reactivate 3D secure
                    $order->getPayment()->setAdditionalInformation('3dActive', '');
                    $order->getPayment()->setAdditionalInformation('3dSuccess', true);

                    if (!$this->_adyenHelper->isCreditCardVaultEnabled() &&
                        !empty($result['additionalData']['recurring.recurringDetailReference'])) {
                        $this->_adyenHelper->createAdyenBillingAgreement($order, $result['additionalData']);
                    } elseif (!empty($result['additionalData']['recurring.recurringDetailReference'])
                    ) {
                        try {
                            $additionalData = $result['additionalData'];
                            $token = $additionalData['recurring.recurringDetailReference'];
                            $expirationDate = $additionalData['expiryDate'];
                            $cardType = $additionalData['paymentMethod'];
                            $cardSummary = $additionalData['cardSummary'];
                            /** @var PaymentTokenInterface $paymentToken */
                            $paymentToken = $this->paymentTokenFactory->create(
                                PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD
                            );
                            $paymentToken->setGatewayToken($token);
                            $paymentToken->setExpiresAt($this->getExpirationDate($expirationDate));
                            $details = [
                                'type' => $cardType,
                                'maskedCC' => $cardSummary,
                                'expirationDate' => $expirationDate
                            ];
                            $paymentToken->setTokenDetails(json_encode($details));
                            $extensionAttributes = $this->getExtensionAttributes($order->getPayment());
                            $extensionAttributes->setVaultPaymentToken($paymentToken);
                            $orderPayment = $order->getPayment()->setExtensionAttributes($extensionAttributes);
                            $add = $this->serializer->unserialize($orderPayment->getAdditionalData());
                            $add['force_save'] = true;
                            $orderPayment->setAdditionalData($this->serializer->serialize($add));
                            $this->orderPaymentResource->save($orderPayment);
                        } catch (\Exception $e) {
                            $this->_adyenLogger->error((string)$e->getMessage());
                        }
                    }

                    $this->_orderRepository->save($order);

                    $this->_redirect('checkout/onepage/success', ['_query' => ['utm_nooverride' => '1']]);
                } else {
                    /*
                     * Since responseCode!='Authorised' the order could be cancelled immediately,
                     * but redirect payments can have multiple conflicting responses.
                     * The order will be cancelled if an Authorization
                     * Success=False notification is processed instead
                    */
                    $order->addStatusHistoryComment(
                        __(
                            '3D-secure validation was unsuccessful. This order will be cancelled when the related 
                                notification has been processed.'
                        )
                    )->save();

                    $this->messageManager->addErrorMessage("3D-secure validation was unsuccessful");

                    // reactivate the quote
                    $session = $this->_getCheckout();

                    // restore the quote
                    $session->restoreQuote();

                    $this->_redirect($this->_adyenHelper->getAdyenAbstractConfigData('return_path'));
                }
            } else {
                $this->_adyenLogger->addAdyenResult("Customer was redirected to bank for 3D-secure validation.");
                $order->addStatusHistoryComment(
                    __(
                        'Customer was redirected to bank for 3D-secure validation. Once the shopper authenticated, 
                        the order status will be updated accordingly. 
                        <br />Make sure that your notifications are being processed! 
                        <br />If the order is stuck on this status, the shopper abandoned the session. 
                        The payment can be seen as unsuccessful. 
                        <br />The order can be automatically cancelled based on the OFFER_CLOSED notification. 
                        Please contact Adyen Support to enable this.'
                    )
                )->save();
                $this->_view->loadLayout();
                $this->_view->getLayout()->initMessages();
                $this->_view->renderLayout();
            }
        } elseif (!empty($checkoutAPM)) {
            $this->_view->loadLayout();
            $this->_view->getLayout()->initMessages();
            $this->_view->renderLayout();
        } else {
            $this->_redirect('checkout/onepage/success', ['_query' => ['utm_nooverride' => '1']]);
        }
    }

    /**
     * Return checkout session object
     *
     * @return \Magento\Checkout\Model\Session
     */
    protected function _getCheckoutSession()
    {
        return $this->_checkoutSession;
    }

    /**
     * Get order object
     *
     * @return \Magento\Sales\Model\Order
     */
    protected function _getOrder()
    {
        if (!$this->_order) {
            $incrementId = $this->_getCheckout()->getLastRealOrderId();
            $this->_orderFactory = $this->_objectManager->get(\Magento\Sales\Model\OrderFactory::class);
            $this->_order = $this->_orderFactory->create()->loadByIncrementId($incrementId);
        }
        return $this->_order;
    }

    /**
     * @return \Magento\Checkout\Model\Session
     */
    protected function _getCheckout()
    {
        return $this->_objectManager->get(\Magento\Checkout\Model\Session::class);
    }

    /**
     * @return mixed
     */
    protected function _getQuote()
    {
        return $this->_objectManager->get(\Magento\Quote\Model\Quote::class);
    }

    /**
     * @return mixed
     */
    protected function _getQuoteManagement()
    {
        return $this->_objectManager->get(\Magento\Quote\Model\QuoteManagement::class);
    }

    /**
     * Called by redirect controller when cc payment has 3D secure
     *
     * @param $payment
     * @return mixed
     * @throws \Exception
     */
    protected function _authorise3d($payment)
    {
        try {
            $response = $this->_paymentRequest->authorise3d($payment);
        } catch (\Exception $e) {
            throw $e;
        }

        return $response;
    }

    /**
     * @param $expirationDate
     * @return string
     */
    private function getExpirationDate($expirationDate)
    {
        $expirationDate = explode('/', $expirationDate);
        //add leading zero to month
        $month = sprintf("%02d", $expirationDate[0]);
        $expDate = new \DateTime(
            $expirationDate[1]
            . '-'
            . $month
            . '-'
            . '01'
            . ' '
            . '00:00:00',
            new \DateTimeZone('UTC')
        );
        // add one month
        $expDate->add(new \DateInterval('P1M'));
        return $expDate->format('Y-m-d 00:00:00');
    }

    /**
     * Get payment extension attributes
     *
     * @param InfoInterface $payment
     * @return OrderPaymentExtensionInterface
     */
    private function getExtensionAttributes(InfoInterface $payment)
    {
        $extensionAttributes = $payment->getExtensionAttributes();
        if (null === $extensionAttributes) {
            $extensionAttributes = $this->paymentExtensionFactory->create();
            $payment->setExtensionAttributes($extensionAttributes);
        }
        return $extensionAttributes;
    }
}
