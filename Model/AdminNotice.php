<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;

/**
 * Shows an error to the admin user, but only when an admin is the one acting.
 *
 * Observers run wherever the event fires: an admin page, the PayMongo webhook, a REST
 * call or cron. A session message added outside the admin reaches nobody, and starting a
 * session for it from a webhook or cron leaves an empty session behind on every call. So
 * the observers record what happened as an order comment, and use this for the admin's
 * on-screen warning only.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class AdminNotice
{
    /** @var ManagerInterface */
    private ManagerInterface $messageManager;

    /** @var State */
    private State $appState;

    /**
     * @param ManagerInterface $messageManager
     * @param State $appState
     */
    public function __construct(ManagerInterface $messageManager, State $appState)
    {
        $this->messageManager = $messageManager;
        $this->appState = $appState;
    }

    /**
     * Add an error message for the admin user, if the current request is an admin one.
     *
     * @param Phrase $message
     * @return void
     */
    public function error(Phrase $message): void
    {
        try {
            $isAdmin = $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (\Exception $e) {
            $isAdmin = false;
        }

        if ($isAdmin) {
            $this->messageManager->addErrorMessage($message);
        }
    }
}
