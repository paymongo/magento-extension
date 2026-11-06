<?php

declare(strict_types=1);

namespace PayMongo\Payment\Controller\Adminhtml\Reconciliation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin controller: PayMongo > Reconciliation index page.
 *
 * Renders the date-range form and results grid. The actual data fetch is handled
 * by the sibling Fetch controller via AJAX POST so that the page can be bookmarked
 * without re-triggering the API call.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Index extends Action
{
    /** ACL resource required to access this page. */
    public const ADMIN_RESOURCE = 'PayMongo_Payment::reconciliation';

    /** @var PageFactory */
    private PageFactory $pageFactory;

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(Context $context, PageFactory $pageFactory)
    {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
    }

    /**
     * Render the reconciliation admin page.
     *
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute(): \Magento\Framework\View\Result\Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('PayMongo_Payment::reconciliation_menu');
        $page->getConfig()->getTitle()->prepend(__('PayMongo Payment Reconciliation'));
        return $page;
    }
}
