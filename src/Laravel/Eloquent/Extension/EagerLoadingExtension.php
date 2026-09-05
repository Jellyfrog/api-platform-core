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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

final class EagerLoadingExtension implements QueryExtensionInterface
{
    /**
     * @var array<string, list<string>>
     */
    private array $localCache = [];

    public function __construct(
        private readonly PropertyMetadataFactoryInterface $propertyMetadataFactory,
        private readonly ModelMetadata $modelMetadata,
        private readonly bool $forceEager = true,
        private readonly int $maxJoins = 30,
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
        if (!isset($context[AbstractNormalizer::GROUPS]) && !isset($context[AbstractNormalizer::ATTRIBUTES])) {
            $context += $operation->getNormalizationContext() ?? [];
        }

        if (empty($context[AbstractNormalizer::GROUPS]) && !isset($context[AbstractNormalizer::ATTRIBUTES])) {
            return $builder;
        }

        $options = [];
        if (!empty($context[AbstractNormalizer::GROUPS])) {
            $options['serializer_groups'] = (array) $context[AbstractNormalizer::GROUPS];
        }

        $eagerRelations = $this->getEagerRelations(
            $builder->getModel()::class,
            $operation->getForceEager() ?? $this->forceEager,
            $options,
        );

        if ([] !== $eagerRelations) {
            $builder->with($eagerRelations);
        }

        return $builder;
    }

    /**
     * The relations to eager load are computed from static metadata only, cache them per model and serialization context.
     *
     * @param class-string<Model>  $modelClass
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function getEagerRelations(string $modelClass, bool $forceEager, array $options): array
    {
        $key = hash('xxh3', serialize([$modelClass, $forceEager, $options]));

        if (isset($this->localCache[$key])) {
            return $this->localCache[$key];
        }

        $eagerRelations = [];
        $this->collectEagerRelations($modelClass, $forceEager, $options, $eagerRelations);

        return $this->localCache[$key] = $eagerRelations;
    }

    /**
     * @param class-string<Model>  $modelClass
     * @param array<string, mixed> $options
     * @param list<string>         $eagerRelations
     * @param array<class-string>  $visited
     */
    private function collectEagerRelations(string $modelClass, bool $forceEager, array $options, array &$eagerRelations, array $visited = [], string $prefix = ''): void
    {
        if (\count($eagerRelations) >= $this->maxJoins || \in_array($modelClass, $visited, true) || !is_a($modelClass, Model::class, true)) {
            return;
        }

        $visited[] = $modelClass;

        foreach ($this->modelMetadata->getRelations(new $modelClass()) as $relation) {
            if (\count($eagerRelations) >= $this->maxJoins) {
                break;
            }

            try {
                $propertyMetadata = $this->propertyMetadataFactory->create($modelClass, $relation['name'], $options);
            } catch (PropertyNotFoundException|ResourceClassNotFoundException) {
                continue;
            }

            $fetchEager = $propertyMetadata->getFetchEager();

            // Skip relations that opted out, are not readable, or are exposed through their own URI: those are linked to, not embedded.
            if (false === $fetchEager
                || null !== $propertyMetadata->getUriTemplate()
                || false === $propertyMetadata->isReadable()
                || (!$forceEager && true !== $fetchEager)
            ) {
                continue;
            }

            $path = '' === $prefix ? $relation['method_name'] : $prefix.'.'.$relation['method_name'];
            $eagerRelations[] = $path;

            if (true === $propertyMetadata->isReadableLink() || true === $fetchEager) {
                $this->collectEagerRelations($relation['related'], $forceEager, $options, $eagerRelations, $visited, $path);
            }
        }
    }
}
