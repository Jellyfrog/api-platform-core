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
            // Sorted and deduplicated so that logically identical group sets share a cache entry.
            $groups = array_values(array_unique((array) $context[AbstractNormalizer::GROUPS]));
            sort($groups);
            $options['serializer_groups'] = $groups;
        }

        // Narrow the relations the way the serializer narrows the payload, otherwise a sparse
        // fieldset eager loads relations that the response is never going to contain.
        if (isset($context[AbstractNormalizer::ATTRIBUTES])) {
            $options['serializer_attributes'] = (array) $context[AbstractNormalizer::ATTRIBUTES];
        }

        $eagerRelations = $this->withoutAlreadyEagerLoaded(
            $builder,
            $this->getEagerRelations(
                $builder->getModel()::class,
                $operation->getForceEager() ?? $this->forceEager,
                $options,
            )
        );

        if ([] !== $eagerRelations) {
            $builder->with($eagerRelations);
        }

        return $builder;
    }

    /**
     * Builder::with() merges eager loads by relation name, so an unconstrained path would replace
     * the constraints another extension or filter already set on that relation. Leave those alone,
     * including their nested paths: whoever constrained a relation owns its subtree.
     *
     * @param Builder<Model> $builder
     * @param list<string>   $eagerRelations
     *
     * @return list<string>
     */
    private function withoutAlreadyEagerLoaded(Builder $builder, array $eagerRelations): array
    {
        if ([] === $eagerRelations || [] === ($alreadyLoaded = $builder->getEagerLoads())) {
            return $eagerRelations;
        }

        return array_values(array_filter($eagerRelations, static function (string $path) use ($alreadyLoaded): bool {
            $prefix = '';
            foreach (explode('.', $path) as $segment) {
                $prefix = '' === $prefix ? $segment : $prefix.'.'.$segment;

                if (isset($alreadyLoaded[$prefix])) {
                    return false;
                }
            }

            return true;
        }));
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

        // Don't cache an empty result: the metadata it derives from may not be resolvable yet (a
        // missing table, a resource not registered), and ModelMetadata deliberately recomputes those.
        if ([] === $eagerRelations) {
            return $eagerRelations;
        }

        return $this->localCache[$key] = $eagerRelations;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $eagerRelations
     * @param array<string>        $visited
     */
    private function collectEagerRelations(string $modelClass, bool $forceEager, array $options, array &$eagerRelations, array $visited = [], string $prefix = ''): void
    {
        if (\count($eagerRelations) >= $this->maxJoins || \in_array($modelClass, $visited, true)) {
            return;
        }

        $visited[] = $modelClass;

        foreach ($this->modelMetadata->getRelationsForClass($modelClass) as $relation) {
            if (\count($eagerRelations) >= $this->maxJoins) {
                break;
            }

            // Relations may come from a dumped metadata file, only trust entries shaped as expected.
            $name = $relation['name'] ?? null;
            $methodName = $relation['method_name'] ?? null;
            if (!\is_string($name) || !\is_string($methodName)) {
                continue;
            }

            try {
                $propertyMetadata = $this->propertyMetadataFactory->create($modelClass, $name, $options);
            } catch (PropertyNotFoundException) {
                continue;
            }

            $fetchEager = $propertyMetadata->getFetchEager();

            // A relation exposed through its own URI is linked to, not embedded. Otherwise an explicit
            // fetchEager decides on its own, and without one the relation must be readable and eager
            // loading must be forced.
            if (false === $fetchEager || null !== $propertyMetadata->getUriTemplate()) {
                continue;
            }

            if (true !== $fetchEager && (!$forceEager || false === $propertyMetadata->isReadable())) {
                continue;
            }

            $path = '' === $prefix ? $methodName : $prefix.'.'.$methodName;
            $eagerRelations[] = $path;

            $related = $relation['related'] ?? null;
            if (\is_string($related) && (true === $propertyMetadata->isReadableLink() || true === $fetchEager)) {
                $this->collectEagerRelations($related, $forceEager, $options, $eagerRelations, $visited, $path);
            }
        }
    }
}
