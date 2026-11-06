<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Method;

use Magento\Store\Model\ScopeInterface;

/**
 * PayMongo Direct Online Banking payment method — retired (PYM-34).
 *
 * It offered the same banks as BPI Online and UnionBank Online under a third, generic
 * name, so it is no longer offered at checkout or configurable in the admin. The class
 * stays so orders placed with it still open: Magento loads an order's payment method by
 * its code, and an unknown code breaks the order view, invoice and email.
 *
 * Maps to the 'dob' payment_method_type in the PayMongo API. Its Checkout Session
 * offers BPI ('dob'), plus UnionBank ('dob_ubp') while UnionBank Online is enabled.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class Dob extends AbstractPaymongoMethod
{
    public const CODE = 'paymongo_dob';

    /** @var string */
    protected $_code = self::CODE;

    /** @var string PayMongo API payment_method_type */
    protected string $paymongoMethodType = 'dob';

    /**
     * Never active, whatever payment/paymongo_dob/active says.
     *
     * A store that enabled it before the retirement keeps that value in core_config_data,
     * with no admin field left to switch it off. Answering here, rather than deleting the
     * value, hides it from Separate mode (through isAvailable()) and from All-in-One
     * (through EnabledMethodTypes) alike, and leaves the stored title that past orders
     * show untouched.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isActive($storeId = null): bool
    {
        return false;
    }

    /**
     * Offer UnionBank only while UnionBank Online is enabled too.
     *
     * Still needed while the method is retired: an order placed with it before then can
     * have its PayMongo page opened again (Redirect, or createPaymongoCheckoutSession),
     * and a customer who banks with UnionBank must still be able to pay it.
     *
     * A merchant switches UnionBank Online on once PayMongo has activated UnionBank for
     * the account. Asking for 'dob_ubp' before then could get the whole session refused.
     *
     * @return string[]
     */
    public function getCheckoutSessionTypes(): array
    {
        $types = ['dob'];

        if ($this->_scopeConfig->isSetFlag(
            'payment/' . UnionBankOnline::CODE . '/active',
            ScopeInterface::SCOPE_STORE,
            $this->getStore() ?: null
        )) {
            $types[] = 'dob_ubp';
        }

        return $types;
    }
}
