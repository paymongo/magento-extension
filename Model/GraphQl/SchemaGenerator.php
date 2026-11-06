<?php

declare(strict_types=1);

namespace PayMongo\Payment\Model\GraphQl;

use GraphQL\Type\Definition\Type;
use Magento\Framework\GraphQl\ConfigInterface;
use Magento\Framework\GraphQl\Schema;
use Magento\Framework\GraphQl\Schema\SchemaGenerator as CoreSchemaGenerator;
use Magento\Framework\GraphQl\Schema\Type\TypeRegistry;
use Magento\Framework\GraphQl\SchemaFactory;

/**
 * Workaround for webonyx/graphql-php ≥15 compatibility.
 *
 * webonyx v15 calls the schema's typeLoader for every built-in scalar name
 * (String, Int, Float, Boolean, ID) to detect user scalar overrides. Magento's
 * core TypeRegistry throws a LogicException for names not present in its config
 * data, which causes a GraphQL execution error for any query that requests a
 * field typed as String!/Int!/etc. on a custom type.
 *
 * This subclass guards the typeLoader: if the requested name is a built-in
 * GraphQL scalar it returns null, allowing webonyx to fall through to its own
 * built-in scalar instances (Schema::getType lines 344-346). For all other
 * names the original registry lookup is used unchanged.
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class SchemaGenerator extends CoreSchemaGenerator
{
    /** @var TypeRegistry */
    private TypeRegistry $typeRegistry;

    /** @var ConfigInterface */
    private ConfigInterface $config;

    /** @var SchemaFactory */
    private SchemaFactory $schemaFactory;

    public function __construct(
        SchemaFactory $schemaFactory,
        ConfigInterface $config,
        TypeRegistry $typeRegistry
    ) {
        parent::__construct($schemaFactory, $config, $typeRegistry);
        $this->schemaFactory = $schemaFactory;
        $this->config = $config;
        $this->typeRegistry = $typeRegistry;
    }

    public function generate(): Schema
    {
        $builtInScalarNames = Type::BUILT_IN_SCALAR_NAMES;
        $typeRegistry = $this->typeRegistry;

        return $this->schemaFactory->create(
            [
                'query' => $this->typeRegistry->get('Query'),
                'mutation' => $this->typeRegistry->get('Mutation'),
                'typeLoader' => static function (string $name) use ($typeRegistry, $builtInScalarNames) {
                    if (\in_array($name, $builtInScalarNames, true)) {
                        return null;
                    }
                    return $typeRegistry->get($name);
                },
                'types' => function () {
                    $types = [];
                    foreach ($this->config->getDeclaredTypes() as $type) {
                        $types[] = $this->typeRegistry->get($type['name']);
                    }
                    return $types;
                },
            ]
        );
    }
}
