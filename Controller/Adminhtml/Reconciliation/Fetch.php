<?php

declare(strict_types=1);

namespace PayMongo\Payment\Controller\Adminhtml\Reconciliation;

use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Reconciliation\Service;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Admin AJAX controller: fetches and returns reconciliation data as JSON.
 *
 * Accepts a POST with `date_from` and `date_to` (Y-m-d strings). Returns a JSON
 * body with three keys:
 *   - payments      array of normalized PayMongo payment rows
 *   - refunds       array of normalized PayMongo refund rows
 *   - discrepancies array of flagged discrepancies (see Service for types)
 *
 * On error the response includes an `error` key with a human-readable message.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Fetch extends Action
{
    /** ACL resource required to call this action. */
    public const ADMIN_RESOURCE = 'PayMongo_Payment::reconciliation';

    /** @var JsonFactory */
    private JsonFactory $jsonFactory;

    /** @var Service */
    private Service $service;

    /** @var Logger */
    private Logger $logger;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param Service $service
     * @param Logger $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Service $service,
        Logger $logger
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->service     = $service;
        $this->logger      = $logger;
    }

    /**
     * Execute the reconciliation fetch.
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute(): \Magento\Framework\Controller\Result\Json
    {
        $result = $this->jsonFactory->create();

        $dateFromStr = $this->getRequest()->getParam('date_from', '');
        $dateToStr   = $this->getRequest()->getParam('date_to', '');

        if (empty($dateFromStr) || empty($dateToStr)) {
            return $result->setData(['error' => 'date_from and date_to are required.']);
        }

        try {
            $from = new \DateTime($dateFromStr . ' 00:00:00');
            $to   = new \DateTime($dateToStr . ' 23:59:59');
        } catch (\Exception $e) {
            return $result->setData(['error' => 'Invalid date format. Use Y-m-d.']);
        }

        if ($from > $to) {
            return $result->setData(['error' => 'date_from must be before date_to.']);
        }

        try {
            $data = $this->service->reconcile($from, $to);
            return $result->setData($data);
        } catch (LocalizedException $e) {
            $this->logger->error('Reconciliation fetch failed: ' . $e->getMessage());
            return $result->setData(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->critical('Reconciliation unexpected error: ' . $e->getMessage());
            return $result->setData(['error' => 'An unexpected error occurred. See logs for details.']);
        }
    }
}
