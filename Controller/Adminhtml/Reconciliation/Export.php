<?php

declare(strict_types=1);

namespace PayMongo\Payment\Controller\Adminhtml\Reconciliation;

use PayMongo\Payment\Logger\Logger;
use PayMongo\Payment\Model\Reconciliation\Service;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Admin action: export reconciliation results (payments, refunds, discrepancies)
 * for a given date range as a downloadable CSV file.
 *
 * Reuses the same Service::reconcile() call as the Fetch (AJAX) controller so the
 * exported file always matches what the on-screen tables show for that date range.
 *
 * Route: GET paymongo/reconciliation/export?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Export extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'PayMongo_Payment::reconciliation';

    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly Service $service,
        private readonly Logger $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface|ResponseInterface
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        $dateFromStr = (string) $this->getRequest()->getParam('date_from', '');
        $dateToStr = (string) $this->getRequest()->getParam('date_to', '');

        if ($dateFromStr === '' || $dateToStr === '') {
            $this->messageManager->addErrorMessage(__('Date From and Date To are required to export.'));
            return $resultRedirect->setPath('paymongo/reconciliation/index');
        }

        try {
            $from = new \DateTime($dateFromStr . ' 00:00:00');
            $to = new \DateTime($dateToStr . ' 23:59:59');
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Invalid date format. Use Y-m-d.'));
            return $resultRedirect->setPath('paymongo/reconciliation/index');
        }

        if ($from > $to) {
            $this->messageManager->addErrorMessage(__('Date From must be before Date To.'));
            return $resultRedirect->setPath('paymongo/reconciliation/index');
        }

        try {
            $data = $this->service->reconcile($from, $to);
        } catch (LocalizedException $e) {
            $this->logger->error('Reconciliation export failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect->setPath('paymongo/reconciliation/index');
        } catch (\Exception $e) {
            $this->logger->critical('Reconciliation export unexpected error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('An unexpected error occurred. See logs for details.'));
            return $resultRedirect->setPath('paymongo/reconciliation/index');
        }

        $csv = $this->buildCsv($data, $dateFromStr, $dateToStr);
        $fileName = sprintf('paymongo-reconciliation_%s_to_%s.csv', $dateFromStr, $dateToStr);

        return $this->fileFactory->create($fileName, $csv, DirectoryList::VAR_DIR, 'text/csv');
    }

    /**
     * Build a single CSV combining the discrepancies, payments, and refunds sections
     * in the same shape as the on-screen tables.
     *
     * @param array{payments: array, refunds: array, discrepancies: array} $data
     * @param string $dateFromStr
     * @param string $dateToStr
     * @return string
     */
    private function buildCsv(array $data, string $dateFromStr, string $dateToStr): string
    {
        $handle = fopen('php://temp', 'w+');

        // Sanitised like every other cell: these two come straight from request params, and
        // the date parser accepts values beginning -, + and @ (e.g. "-1 day"), which are
        // three of the four prefixes spreadsheet apps treat as the start of a formula.
        fputcsv($handle, [
            'PayMongo Reconciliation',
            $this->sanitizeCell($dateFromStr . ' to ' . $dateToStr),
        ]);
        fputcsv($handle, []);

        fputcsv($handle, ['Discrepancies']);
        fputcsv($handle, ['Type', 'Payment/Refund ID', 'Order #', 'PayMongo Amount', 'Magento Amount', 'Message']);
        foreach ($data['discrepancies'] as $row) {
            fputcsv($handle, [
                $this->sanitizeCell((string) ($row['type'] ?? '')),
                $this->sanitizeCell((string) ($row['payment_id'] ?? $row['refund_id'] ?? '')),
                $this->sanitizeCell((string) ($row['order_increment'] ?? '')),
                $row['paymongo_amount'] ?? $row['amount'] ?? '',
                $row['magento_amount'] ?? '',
                $this->sanitizeCell((string) ($row['message'] ?? '')),
            ]);
        }
        fputcsv($handle, []);

        fputcsv($handle, ['Payments']);
        fputcsv($handle, ['ID', 'Status', 'Amount', 'Currency', 'Method', 'Description', 'Created At']);
        foreach ($data['payments'] as $row) {
            fputcsv($handle, [
                $this->sanitizeCell((string) ($row['id'] ?? '')),
                $this->sanitizeCell((string) ($row['status'] ?? '')),
                $row['amount'] ?? '',
                $this->sanitizeCell((string) ($row['currency'] ?? '')),
                $this->sanitizeCell((string) ($row['method'] ?? '')),
                $this->sanitizeCell((string) ($row['description'] ?? '')),
                $this->sanitizeCell((string) ($row['created_at'] ?? '')),
            ]);
        }
        fputcsv($handle, []);

        fputcsv($handle, ['Refunds']);
        fputcsv($handle, ['ID', 'Status', 'Amount', 'Payment ID', 'Reason', 'Created At']);
        foreach ($data['refunds'] as $row) {
            fputcsv($handle, [
                $this->sanitizeCell((string) ($row['id'] ?? '')),
                $this->sanitizeCell((string) ($row['status'] ?? '')),
                $row['amount'] ?? '',
                $this->sanitizeCell((string) ($row['payment_id'] ?? '')),
                $this->sanitizeCell((string) ($row['reason'] ?? '')),
                $this->sanitizeCell((string) ($row['created_at'] ?? '')),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Neutralize potential CSV formula injection (OWASP): a cell value starting with
     * =, +, -, or @ can be interpreted as a formula by Excel/Sheets. Prefixing with a
     * single quote forces it to be treated as plain text while staying human-readable.
     *
     * @param string $value
     * @return string
     */
    private function sanitizeCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
