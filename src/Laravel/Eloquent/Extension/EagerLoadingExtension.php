<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Laravel\Eloquent\Extension;

use ApiPlatform\Laravel\Eloquent\Metadata\ModelMetadata;
use ApiPlatform\Metadata\Exception\PropertyNotFoundException;
use ApiPlatform\Metadata\Exception\ResourceClassNotFoundException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

final readonly class EagerLoadingExtension implements QueryExtensionInterface
{
    public function __construct(
        private PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory,
        private PropertyMetadataFactoryInterface $propertyMetadataFactory,
        private ModelMetadata $modelMetadata,
        private bool $forceEager = true,
        private int $maxJoins = 30,
    ) {
    }

    /**
     * @param Builder<Model>        $builder
     * @param array<string, string> $uriVariables
     * @param array<string, mixed>  $context
     *
     * @return Builder<Model>
     */
    public function apply(Builder $builder, array $uriVariables, Operation $operation, $context = []): Builder
    {
        $resourceClass = $builder->getModel()::class;
        $forceEager = $operation->getForceEager() ?? $this->forceEager;

        if (!isset($context['groups']) && !isset($context['attributes'])) {
            $context += $operation->getNormalizationContext() ?? [];
        }

        if (empty($context[AbstractNormalizer::GROUPS]) && !isset($context[AbstractNormalizer::ATTRIBUTES])) {
            return $builder;
        }

        $options = [];
        if (!empty($context[AbstractNormalizer::GROUPS])) {
            $options['serializer_groups'] = (array) $context[AbstractNormalizer::GROUPS];
        }

        $eagerRelations = $this->getEagerRelations($resourceClass, $forceEager, $options);

        if ([] !== $eagerRelations) {
            $builder->with($eagerRelations);
        }

        return $builder;
    }

    /**
     * @param class-string         $resourceClass
     * @param array<string, mixed> $options
     * @param array<class-string>  $visited
     *
     * @return list<string>
     */
    private function getEagerRelations(string $resourceClass, bool $forceEager, array $options, array $visited = [], string $prefix = '', int &$joinCount = 0): array
    {
        if ($joinCount >= $this->maxJoins) {
            return [];
        }

        if (\in_array($resourceClass, $visited, true)) {
            return [];
        }

        $visited[] = $resourceClass;

        $model = new $resourceClass();
        if (!$model instanceof Model) {
            return [];
        }

        $relations = $this->modelMetadata->getRelations($model);
        $eagerRelations = [];

        foreach ($relations as $relation) {
            if ($joinCount >= $this->maxJoins) {
                break;
            }

            try {
                $propertyMetadata = $this->propertyMetadataFactory->create($resourceClass, $relation['name'], $options);
            } catch (PropertyNotFoundException) {
                continue;
            } catch (ResourceClassNotFoundException) {
                continue;
            }

            $fetchEager = $propertyMetadata->getFetchEager();
            $uriTemplate = $propertyMetadata->getUriTemplate();

            if (false === $fetchEager || null !== $uriTemplate) {
                continue;
            }

            if (false === $propertyMetadata->isReadable()) {
                continue;
            }

            if (!$forceEager && true !== $fetchEager) {
                continue;
            }

            $methodName = $relation['method_name'];
            $path = '' === $prefix ? $methodName : $prefix.'.'.$methodName;
            $eagerRelations[] = $path;
            ++$joinCount;

            $relatedClass = $relation['related'];
            if (true === $propertyMetadata->isReadableLink() || true === $fetchEager) {
                $nested = $this->getEagerRelations($relatedClass, $forceEager, $options, $visited, $path, $joinCount);
                $eagerRelations = array_merge($eagerRelations, $nested);
            }
        }

        return $eagerRelations;
    }
}
