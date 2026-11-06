<?php

declare(strict_types=1);

namespace PayMongo\Payment\Logger;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\State as AppState;

/**
 * Audit logger for privileged PayMongo admin actions.
 *
 * Records who performed an action (refund, manual capture) and when, satisfying
 * the v1.1.0.0 audit logging requirement. Writes to the same PayMongo log file
 * as the general logger, distinguished by the 'AUDIT' prefix so log aggregators
 * can filter on it separately.
 *
 * Admin identity is only resolved when running in the adminhtml area; in frontend
 * context (e.g. webhook) the actor is recorded as 'system'.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class AuditLogger
{
    /** @var Logger */
    private Logger $logger;

    /** @var AdminSession */
    private AdminSession $adminSession;

    /** @var AppState */
    private AppState $appState;

    /**
     * @param Logger $logger
     * @param AdminSession $adminSession
     * @param AppState $appState
     */
    public function __construct(
        Logger $logger,
        AdminSession $adminSession,
        AppState $appState
    ) {
        $this->logger = $logger;
        $this->adminSession = $adminSession;
        $this->appState = $appState;
    }

    /**
     * Log a refund action with the admin user identity.
     *
     * @param string $orderId Magento order increment ID
     * @param float $amount Refund amount in PHP
     * @param string $paymentId PayMongo payment ID (pay_…)
     * @param string|null $notes Optional notes attached to the refund
     * @return void
     */
    public function logRefund(string $orderId, float $amount, string $paymentId, ?string $notes = null): void
    {
        $this->log('REFUND', $orderId, [
            'amount' => number_format($amount, 2),
            'paymongo_payment_id' => $paymentId,
            'notes' => $notes,
        ]);
    }

    /**
     * Log a manual capture action with the admin user identity.
     *
     * The PayMongo payment (pay_…) is logged too when known, the ID refund entries use, so a
     * capture and the refunds of the same money can be matched.
     *
     * @param string $orderId Magento order increment ID
     * @param float $amount Captured amount in PHP
     * @param string $paymentIntentId PayMongo payment intent ID (pi_…)
     * @param string|null $paymentId PayMongo payment ID (pay_…), if known
     * @return void
     */
    public function logCapture(string $orderId, float $amount, string $paymentIntentId, ?string $paymentId = null): void
    {
        $this->log('CAPTURE', $orderId, array_filter([
            'amount' => number_format($amount, 2),
            'paymongo_payment_intent_id' => $paymentIntentId,
            'paymongo_payment_id' => $paymentId,
        ], static fn ($value): bool => $value !== null));
    }

    /**
     * Write a structured audit entry to the PayMongo log.
     *
     * @param string $action Action label (REFUND, CAPTURE)
     * @param string $orderId Order increment ID
     * @param array $context Additional context fields
     * @return void
     */
    private function log(string $action, string $orderId, array $context): void
    {
        $this->logger->info('AUDIT:' . $action, array_merge(
            [
                'order_id' => $orderId,
                'actor' => $this->resolveActor(),
            ],
            array_filter($context, fn($v) => $v !== null)
        ));
    }

    /**
     * Resolve the identity of the user performing the action.
     *
     * Returns the admin username when in the adminhtml area, or 'system' when
     * the action originates from an automated context (webhook, cron, API).
     *
     * @return string
     */
    private function resolveActor(): string
    {
        try {
            if ($this->appState->getAreaCode() === \Magento\Framework\App\Area::AREA_ADMINHTML) {
                $user = $this->adminSession->getUser();
                if ($user && $user->getUserName()) {
                    return $user->getUserName();
                }
            }
        } catch (\Exception $e) {
            // Area not yet set during early bootstrap — fall through to 'system'
        }

        return 'system';
    }
}
