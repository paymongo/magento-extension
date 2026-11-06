<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\Config\Backend;

use PayMongo\Payment\Model\Config\Source\PaymentDisplayMode;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Backend model for the Payment Display Mode field.
 *
 * Magento's own payment method availability check reads payment/paymongo/active
 * directly (Magento\Payment\Model\Method\AbstractMethod::isAvailable()), independently
 * of our custom logic. Rather than exposing a second "Enabled" toggle for the legacy/
 * unified method, this keeps display mode a single visible control by writing that
 * flag automatically whenever the mode is saved.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class DisplayMode extends Value
{
    private const XML_PATH_LEGACY_ACTIVE = 'payment/paymongo/active';

    /** @var WriterInterface */
    private WriterInterface $configWriter;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param WriterInterface $configWriter
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        WriterInterface $configWriter,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->configWriter = $configWriter;
    }

    /**
     * Sync the legacy paymongo/active flag to match the selected display mode.
     *
     * @return $this
     */
    public function afterSave(): self
    {
        $this->configWriter->save(
            self::XML_PATH_LEGACY_ACTIVE,
            $this->getValue() === PaymentDisplayMode::UNIFIED ? '1' : '0',
            (string) $this->getScope(),
            (int) $this->getScopeId()
        );

        return parent::afterSave();
    }
}
