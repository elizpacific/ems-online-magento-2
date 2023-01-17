<?php

namespace GingerPay\Payment\Model\Builders;

use GingerPluginSdk\Client;
use GingerPluginSdk\Properties\ClientOptions;

if (file_exists(__DIR__ . "/../../Library/vendor/autoload.php"))
{
    require_once __DIR__ . "/../../Library/vendor/autoload.php";
}


class ApiBuilder
{
    /**
     * @var ConfigRepository
     */
    protected $configRepository;

    /**
     * @var UrlProvider
     */
    protected $urlProvider;

    /**
     * @var \Ginger\ApiClient
     */
    protected $client = null;

    /**
     * @var string
     */
    protected $apiKey = null;

    /**
     * @var string
     */
    protected $endpoint = null;

    /**
     * Endpoint
     */
    const ENDPOINT = 'https://api.online.emspay.eu/';

    /**
     * Ginger
     */
    protected $ginger_lib;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;


    public function get(int $storeId = null, string $testApiKey = null)
    {

        if ($this->client !== null && $testApiKey === null)
        {
            return $this->client;
        }

        if (empty($storeId))
        {
            $storeId = $this->configRepository->getCurrentStoreId();
        }

        if ($testApiKey !== null)
        {
            $this->apiKey = $testApiKey;
        }

        if ($this->apiKey === null)
        {
            $this->apiKey = $this->configRepository->getApiKey((int)$storeId);
        }

        if ($this->endpoint === null)
        {
            $this->endpoint = $this->urlProvider->getEndPoint();
        }

        if (!$this->apiKey || !$this->endpoint)
        {
            $this->configRepository->addTolog('error', 'Missing Api Key / Api Endpoint');
            return false;
        }

        $options = new ClientOptions($this->endpoint,'true', $this->apiKey);

        $gingerClient = new \GingerPluginSdk\Client($options);

        return $gingerClient;
    }

    /**
     * Return Url Builder
     *
     * @return mixed
     */
    public function getReturnUrl()
    {
        return $this->urlBuilder->getUrl('ginger/checkout/process');
    }

    /**
     * Webhook Url Builder
     *
     * @return string
     */
    public function getWebhookUrl()
    {
        return $this->urlBuilder->getUrl('ginger/checkout/webhook/');
    }

    /**
     * Process Url Builder
     *
     * @param string $transactionId
     *
     * @return string
     */
    public function getSuccessProcessUrl(string $transactionId) : string
    {
        return $this->urlBuilder->getUrl('ginger/checkout/process', ['order_id' => $transactionId]);
    }

    /**
     * Checkout Webhook Url Builder
     *
     * @return string
     */
    public function getSuccessUrl()
    {
        return $this->urlBuilder->getUrl('checkout/onepage/success?utm_nooverride=1');
    }

    /**
     * @return string
     */
    public function getEndPoint()
    {
        return self::ENDPOINT;
    }
}
