<?php

namespace GingerPay\Payment\Model\Builders;

use GingerPay\Payment\Model\Methods\Afterpay;
use GingerPay\Payment\Model\Methods\KlarnaPayLater;
use GingerPay\Payment\Model\Methods\KlarnaPayNow;
use GingerPluginSdk\Collections\AdditionalAddresses;
use GingerPluginSdk\Collections\PhoneNumbers;
use GingerPluginSdk\Collections\Transactions;
use GingerPluginSdk\Entities\Address;
use GingerPluginSdk\Entities\Customer;
use GingerPluginSdk\Entities\Order;
use GingerPluginSdk\Entities\PaymentMethodDetails;
use GingerPluginSdk\Entities\Transaction;
use GingerPluginSdk\Properties\Amount;
use GingerPluginSdk\Properties\Country;
use GingerPluginSdk\Properties\EmailAddress;
use GingerPluginSdk\Properties\Locale;
use GingerPluginSdk\Tests\OrderStub;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ProductMetadata;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\OrderRepository;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use GingerPluginSdk\Properties\Currency;

class ServiceOrderBuilder
{
    /**
     * @var ConfigRepository
     */
    protected $configRepository;
    /**
     * @var Resolver
     */
    protected $resolver;

    /**
     * @var Header
     */
    protected $httpHeader;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var HistoryFactory
     */
    public $historyFactory;

    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    protected $historyRepository;

    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var OrderCommentHistory
     */
    protected $orderCommentHistory;

    /**
     * @var OrderSender
     */
    protected $orderSender;

    /**
     * @var ProductMetadata
     */
    public $productMetadata;

    /**
     * @var Order
     */
    public $orderEntitie;

    /**
     * @param OrderInterface $order
     *
     * @return bool
     */
    public function cancel(OrderInterface $order): bool
    {
        if ($order->getId() && $order->getState() != Order::STATE_CANCELED)
        {
            $comment = __("The order was canceled");
            $this->configRepository->addTolog('info', $order->getIncrementId() . ' ' . $comment);
            $order->registerCancellation($comment)->save();
            return true;
        }
        return false;
    }

    /**
     * @param OrderInterface $order
     * @param string $method
     *
     * @return array
     */
    public function get(OrderInterface $order, string $method): array
    {
        $customer = $order->getBillingAddress();
        $additionalData = $order->getPayment()->getAdditionalInformation();
        $street = implode(' ', $customer->getStreet());
        list($address, $houseNumber) = $this->parseAddress($street);

        $postCode = $customer->getPostcode();
        if (strlen($postCode) == 6) {
            $postCode = wordwrap($postCode, 4, ' ', true);
        }

        $customerData = [
            'merchant_customer_id' => $customer->getEntityId(),
            'email_address' => $customer->getEmail(),
            'first_name' => $customer->getFirstname(),
            'last_name' => $customer->getLastname(),
            'address_type' => $customer->getAddressType(),
            'address' => $street,
            'postal_code' => $postCode,
            'housenumber' => $houseNumber,
            'country' => $customer->getCountryId(),
            'phone_numbers' => [$customer->getTelephone()],
            'user_agent' => $this->getUserAgent(),
            'ip_address' => $order->getRemoteIp(),
            'forwarded_ip' => $order->getXForwardedFor(),
            'locale' => $this->resolver->getLocale()
        ];

        if (isset($additionalData['prefix'])) {
            $customerData['gender'] = $additionalData['prefix'];
        }

        if (isset($additionalData['dob'])) {
            $customerData['birthdate'] = date('Y-m-d', strtotime($additionalData['dob']));
        }

        if ($method == KlarnaPayLater::METHOD_CODE || $method == Afterpay::METHOD_CODE) {
            $customerData['address'] = implode(' ', [trim($street), $postCode, trim($customer->getCity())]);
        }

        if ($method == KlarnaPayNow::METHOD_CODE) {
            $customerData['address'] = implode(' ', [trim($customer->getCity()), trim($address)]);
        }

        $this->configRepository->addTolog('customer', $customerData);

        return $customerData;
    }

    /**
     * @param string $streetAddress
     *
     * @return array
     */
    protected function parseAddress(string $streetAddress): array
    {
        $address = $streetAddress;
        $houseNumber = '';

        $offset = strlen($streetAddress);

        while (($offset = $this->rstrpos($streetAddress, ' ', $offset)) != false) {
            if ($offset < strlen($streetAddress) - 1 && is_numeric($streetAddress[$offset + 1])) {
                $address = trim(substr($streetAddress, 0, $offset));
                $houseNumber = trim(substr($streetAddress, $offset + 1));
                break;
            }
        }

        if (empty($houseNumber) && strlen($streetAddress) > 0 && is_numeric($streetAddress[0])) {
            $pos = strpos($streetAddress, ' ');

            if ($pos !== false) {
                $houseNumber = trim(substr($streetAddress, 0, $pos), ", \t\n\r\0\x0B");
                $address = trim(substr($streetAddress, $pos + 1));
            }
        }

        return [$address, $houseNumber];
    }

    /**
     * @param string $haystack
     * @param string $needle
     * @param null|int $offset
     *
     * @return int
     */
    protected function rstrpos($haystack, $needle, $offset = null)
    {
        $size = strlen($haystack);

        if (null === $offset) {
            $offset = $size;
        }

        $pos = strpos(strrev($haystack), strrev($needle), $size - $offset);

        if ($pos === false) {
            return 0;
        }

        return $size - $pos - strlen($needle);
    }

    public function getTransactions($platformCode, $issuer_id = null, $verifiedTermsOfService = null)
    {
        return new Transaction(
            paymentMethod: $platformCode,
            paymentMethodDetails: new PaymentMethodDetails(
                issuer_id: $issuer_id,
                verified_terms_of_service: $verifiedTermsOfService
            )
        );
    }

    /**
     * @param $order
     * @param $paymentDetails
     * @param $customerData
     * @param $urlProvider
     * @return Order
     */
    public function collectData($order, $paymentDetails, $customerData, $urlProvider)
    {
//        if($paymentDetails == 'pay-now'){
//            return new Order(
//                currency: new Currency('EUR'),// new Currency($payment_amount->getCurrencyCode()),
//                amount: new Amount($amount % 10),
//                customer: new Customer(
//                    additionalAddresses: new AdditionalAddresses(
//                        new Address(
//                            addressType: $customerData['address_type'],
//                            postalCode: $customerData['postal_code'],
//                            country: new Country($customerData['country'])
//                        ),
//                    ),
//                    firstName: $customerData['first_name'],
//                    lastName: $customerData['last_name'],
//                    emailAddress: new EmailAddress($customerData['email_address']),
//                    phoneNumbers: new PhoneNumbers(),
//                    country: new Country($customerData['country']),
//                    locale: new Locale($customerData['locale']),
//                    merchantCustomerId: $customerData['merchant_customer_id']
//                ),
//                webhook_url: $urlProvider->getWebhookUrl(),
//                return_url: $urlProvider->getReturnUrl()
//            );
//        } else {
//        dd($order->getBaseGrandTotal());
            return new Order(
                currency: new Currency($order->getOrderCurrencyCode()),
                amount: new Amount( (int)($order->getBaseGrandTotal())),
                transactions: new Transactions(
                    new Transaction(
                        paymentMethodDetails: new PaymentMethodDetails(),
                        paymentMethod: $paymentDetails
                    )
                ),
                customer: new Customer(
                    additionalAddresses: new AdditionalAddresses(
                        new Address(
                            addressType: $customerData['address_type'],
                            postalCode: $customerData['postal_code'],
                            country: new Country($customerData['country'])
                        ),
                    ),
                    firstName: $customerData['first_name'],
                    lastName: $customerData['last_name'],
                    emailAddress: new EmailAddress($customerData['email_address']),
                    phoneNumbers: new PhoneNumbers(),
                    country: new Country($customerData['country']),
                    locale: new Locale($customerData['locale']),
                    merchantCustomerId: $customerData['merchant_customer_id']
                ),
                webhook_url: $urlProvider->getWebhookUrl(),
                return_url: $urlProvider->getReturnUrl()
            );
//        }
    }

    /**
     * Collect data for extra_lines
     *
     * @return array
     */
    public function getExtraLines()
    {
        return [
            'user_agent' => $this->getUserAgent(),
            'platform_name' => 'Magento2',
            'platform_version' => $this->productMetadata->getVersion(),
            'plugin_name' => $this->configRepository->getPluginName(),
            'plugin_version' => $this->configRepository->getPluginVersion()
        ];
    }

    /**
     * Customer user agent for API
     *
     * @return mixed
     */
    public function getUserAgent()
    {
        return $_SERVER['HTTP_USER_AGENT'];
    }

    /**
     * Get Order by Transaction ID
     *
     * @param string $transactionId
     *
     * @return OrderInterface|null
     */
    public function getOrderByTransaction(string $transactionId)
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('gingerpay_transaction_id', $transactionId, 'eq')
            ->setPageSize(1)
            ->create();


        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        return reset($orders);
    }

    /**
     * @param OrderInterface $order
     * @param $message
     * @param bool $isCustomerNotified
     * @throws CouldNotSaveException
     */
    public function add(OrderInterface $order, $message, bool $isCustomerNotified = false)
    {
        if (!$message->getText()) {
            return;
        }
        /** @var OrderStatusHistoryInterface $history */
        $history = $this->historyFactory->create();
        $history->setParentId($order->getEntityId())
            ->setComment($message)
            ->setStatus($order->getStatus())
            ->setIsCustomerNotified($isCustomerNotified)
            ->setEntityName('order');

        $this->historyRepository->save($history);
    }

    /**
     * @param OrderInterface $order
     *
     * @throws LocalizedException
     */
    public function sendInvoiceEmail(OrderInterface $order)
    {
        /** @var Payment $payment */
        $payment = $order->getPayment();
        $method = $payment->getMethodInstance()->getCode();

        $invoice = $payment->getCreatedInvoice();
        $sendInvoice = $this->configRepository->sendInvoice($method, (int)$order->getStoreId());

        if ($invoice && $sendInvoice && !$invoice->getEmailSent()) {
            $this->invoiceSender->send($invoice);
            $msg = __('Invoice email sent to %1', $order->getCustomerEmail());
            $this->orderCommentHistory->add($order, $msg, true);
        }
    }

    /**
     * @param OrderInterface $order
     * @throws CouldNotSaveException
     */
    public function sendOrderEmail(OrderInterface $order)
    {
        if (!$order->getEmailSent() && !$order->getSendEmail()) {
            $order->setEmailSent(true);
            $this->orderRepository->save($order);
            $this->orderSender->send($order);
            $msg = __('Order email sent to %1', $order->getCustomerEmail());
            $this->orderCommentHistory->add($order, $msg, true);
        }
    }


    /**
     * @param OrderInterface $order
     * @param string $status
     * @return OrderInterface
     * @throws AlreadyExistsException
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function updateStatus(OrderInterface $order, string $status) : OrderInterface
    {
        if ($order->getStatus() !== $status) {
            $msg = __('Status updated from %1 to %2', $order->getStatus(), $status);
            $order->addStatusToHistory($status, $msg, false);
            $this->orderRepository->save($order);
        }

        return $order;
    }
}
