<?php

declare(strict_types=1);

namespace PayMongo\Payment\Controller\Adminhtml\Config;

use PayMongo\Payment\Gateway\PaymongoClient;
use PayMongo\Payment\Logger\Logger;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Admin AJAX controller: returns the payment method types PayMongo reports as
 * active on this account.
 *
 * This reflects PayMongo's LIVE-mode account approval status only (per PayMongo's
 * docs, their Payment Methods / capability-request status page "is only available
 * in live mode"). It has no bearing on test mode — PayMongo's test mode lets you
 * simulate any of this module's supported methods regardless of live approval, by
 * design, precisely so merchants can validate an integration before requesting to
 * go live. The frontend maps each returned type to the corresponding config group
 * and badges it accordingly, so this is purely informational for go-live planning,
 * not a signal that anything is broken today.
 *
 * Used by the "Check PayMongo Account Status" button on the Payment Methods
 * config page — deliberately not run automatically on page load, since it's a
 * live external API call.
 *
 * Route: GET paymongo/config/checkCapabilities
 * ACL:   PayMongo_Payment::config
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class CheckCapabilities extends Action
{
    /** ACL resource required to call this action. */
    public const ADMIN_RESOURCE = 'PayMongo_Payment::config';

    /** @var JsonFactory */
    private JsonFactory $jsonFactory;

    /** @var PaymongoClient */
    private PaymongoClient $paymongoClient;

    /** @var Logger */
    private Logger $logger;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param PaymongoClient $paymongoClient
     * @param Logger $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        PaymongoClient $paymongoClient,
        Logger $logger
    ) {
        parent::__construct($context);
        $this->jsonFactory    = $jsonFactory;
        $this->paymongoClient = $paymongoClient;
        $this->logger         = $logger;
    }

    /**
     * Check PayMongo account capabilities and return the raw list of active types.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $storeId = (int) $this->getRequest()->getParam('store', 0) ?: null;

        try {
            $active = $this->paymongoClient->getAccountPaymentMethodCapabilities($storeId);

            return $result->setData(['active' => $active]);
        } catch (LocalizedException $e) {
            $this->logger->error('PayMongo account capabilities check failed: ' . $e->getMessage());
            return $result->setData(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->critical('PayMongo account capabilities unexpected error: ' . $e->getMessage());
            return $result->setData(['error' => 'An unexpected error occurred. See logs for details.']);
        }
    }
}
