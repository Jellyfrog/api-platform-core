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
use ApiPlatform\Metadata\Exception\RuntimeException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Serializer\Mapping\AttributeMetadataInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * Eager loads relations.
 *
 * This is the Eloquent counterpart of the Doctrine ORM eager loading extension and follows the same
 * rules: relations are walked from the serialization context, a relation exposed through its own URI
 * is linked instead of embedded, and a relation subtree is only walked when it is a readable link.
 * Doctrine joins and selects, Eloquent issues one batched query per relation, so the joins Doctrine
 * counts against `max_joins` are the eager loaded paths here.
 */
final class EagerLoadingExtension implements QueryExtensionInterface
{
    /**
     * @var array<string, list<string>>
     */
    private array $localCache = [];

    public function __construct(
        private readonly PropertyMetadataFactoryInterface $propertyMetadataFactory,
        private readonly ModelMetadata $modelMetadata,
        private readonly int $maxJoins = 30,
        private readonly bool $forceEager = true,
        private readonly ?ClassMetadataFactoryInterface $classMetadataFactory = null,
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
        $options = [];
        $forceEager = $operation->getForceEager() ?? $this->forceEager;

        if (!isset($context[AbstractNormalizer::GROUPS]) && !isset($context[AbstractNormalizer::ATTRIBUTES])) {
            $context += isset($context['api_denormalize'])
                ? ($operation->getDenormalizationContext() ?? [])
                : ($operation->getNormalizationContext() ?? []);
        }

        if (empty($context[AbstractNormalizer::GROUPS]) && !isset($context[AbstractNormalizer::ATTRIBUTES])) {
            return $builder;
        }

        if (!empty($context[AbstractNormalizer::GROUPS])) {
            $options['serializer_groups'] = (array) $context[AbstractNormalizer::GROUPS];
        }

        if ($normalizationGroups = $operation->getNormalizationContext()['groups'] ?? null) {
            $options['normalization_groups'] = $normalizationGroups;
        }

        if ($denormalizationGroups = $operation->getDenormalizationContext()['groups'] ?? null) {
            $options['denormalization_groups'] = $denormalizationGroups;
        }

        $eagerRelations = $this->withoutAlreadyEagerLoaded(
            $builder,
            $this->getEagerRelations($builder->getModel()::class, $forceEager, $options, $context)
        );

        if ([] !== $eagerRelations) {
            $builder->with($eagerRelations);
        }

        return $builder;
    }

    /**
     * Builder::with() merges eager loads by relation name, so an unconstrained path would replace
     * the constraints another extension or filter already set on that relation. Leave those alone,
     * including their nested paths: whoever constrained a relation owns its subtree. Doctrine reuses
     * the existing join instead, which Eloquent cannot do without dropping that constraint.
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
     * @param array<string, mixed> $normalizationContext
     *
     * @return list<string>
     */
    private function getEagerRelations(string $modelClass, bool $forceEager, array $options, array $normalizationContext): array
    {
        // Only the parts of the context the walk actually reads take part in the key, the rest of it
        // holds request scoped objects that are neither serializable nor relevant here.
        $key = hash('xxh3', serialize([$modelClass, $forceEager, $options, [
            $normalizationContext[AbstractNormalizer::ATTRIBUTES] ?? null,
            $normalizationContext[AbstractObjectNormalizer::ENABLE_MAX_DEPTH] ?? null,
        ]]));

        if (isset($this->localCache[$key])) {
            return $this->localCache[$key];
        }

        $eagerRelations = [];
        $this->collectEagerRelations($modelClass, $forceEager, $options, $normalizationContext, $eagerRelations);

        // Don't cache an empty result: the metadata it derives from may not be resolvable yet (a
        // missing table, a resource not registered), and ModelMetadata deliberately recomputes those.
        if ([] === $eagerRelations) {
            return $eagerRelations;
        }

        return $this->localCache[$key] = $eagerRelations;
    }

    /**
     * Collects the relations to eager load.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $normalizationContext
     * @param list<string>         $eagerRelations
     * @param int                  $joinCount            the number of eager loaded relations
     * @param int|null             $currentDepth         the current max depth
     *
     * @throws RuntimeException when the max number of eager loaded relations has been reached
     */
    private function collectEagerRelations(string $modelClass, bool $forceEager, array $options, array $normalizationContext, array &$eagerRelations, int &$joinCount = 0, ?int $currentDepth = null, string $prefix = ''): void
    {
        if ($joinCount > $this->maxJoins) {
            throw new RuntimeException('The total number of eager loaded relations has exceeded the specified maximum. Raise the limit if necessary with the "api-platform.eager_loading.max_joins" configuration key (https://api-platform.com/docs/core/performance/#eager-loading), or limit the maximum serialization depth using the "enable_max_depth" option of the Symfony serializer (https://symfony.com/doc/current/components/serializer.html#handling-serialization-depth).');
        }

        $currentDepth = $currentDepth > 0 ? $currentDepth - 1 : $currentDepth;
        $attributesMetadata = $this->classMetadataFactory?->getMetadataFor($modelClass)->getAttributesMetadata();

        foreach ($this->modelMetadata->getRelationsForClass($modelClass) as $relation) {
            // Don't eager load if max depth is enabled and the current depth limit is reached
            if (0 === $currentDepth && ($normalizationContext[AbstractObjectNormalizer::ENABLE_MAX_DEPTH] ?? false)) {
                continue;
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
                // skip properties not found
                continue;
                // @phpstan-ignore-next-line indeed this can be thrown by the SerializerPropertyMetadataFactory
            } catch (ResourceClassNotFoundException) {
                // skip relations that are not resource classes
                continue;
            }

            // prepare the child context
            $childNormalizationContext = $normalizationContext;
            if (isset($normalizationContext[AbstractNormalizer::ATTRIBUTES])) {
                if ($inAttributes = isset($normalizationContext[AbstractNormalizer::ATTRIBUTES][$name])) {
                    $childNormalizationContext[AbstractNormalizer::ATTRIBUTES] = $normalizationContext[AbstractNormalizer::ATTRIBUTES][$name];
                }
            } else {
                $inAttributes = null;
            }

            $fetchEager = $propertyMetadata->getFetchEager();

            // A relation exposed through its own URI is linked to, not embedded.
            if (false === $fetchEager || null !== $propertyMetadata->getUriTemplate()) {
                continue;
            }

            // Eloquent has no per-relation fetch mode, so `fetchEager: true` plays the role Doctrine's
            // EAGER fetch mode plays there: it is the only way to opt a relation in when force_eager
            // is disabled. Otherwise the relation must be readable and part of the requested fieldset.
            if (true !== $fetchEager && (false === $forceEager || false === $propertyMetadata->isReadable() || false === $inAttributes)) {
                continue;
            }

            $path = '' === $prefix ? $methodName : $prefix.'.'.$methodName;
            $eagerRelations[] = $path;
            ++$joinCount;

            $related = $relation['related'] ?? null;
            if (!\is_string($related)) {
                continue;
            }

            // Avoid recursive eager loads for self-referencing relations
            if ($related === $modelClass) {
                continue;
            }

            // Only walk the relation's relations recursively if it's a readableLink
            if (true !== $fetchEager && true !== $propertyMetadata->isReadableLink()) {
                continue;
            }

            if (isset($attributesMetadata[$name])) {
                $maxDepth = $attributesMetadata[$name]->getMaxDepth();

                // The current depth is the lowest max depth available in the ancestor tree.
                if (null !== $maxDepth && (null === $currentDepth || $maxDepth < $currentDepth)) {
                    $currentDepth = $maxDepth;
                }
            }

            $this->collectEagerRelations(
                $related,
                $forceEager,
                $this->getPropertyContext($attributesMetadata[$name] ?? null, $options),
                $childNormalizationContext,
                $eagerRelations,
                $joinCount,
                $currentDepth,
                $path,
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function getPropertyContext(?AttributeMetadataInterface $attributeMetadata, array $options): array
    {
        if (null === $attributeMetadata) {
            return $options;
        }

        $hasNormalizationContext = (isset($options['normalization_groups']) || isset($options['serializer_groups'])) && [] !== $attributeMetadata->getNormalizationContexts();
        $hasDenormalizationContext = (isset($options['denormalization_groups']) || isset($options['serializer_groups'])) && [] !== $attributeMetadata->getDenormalizationContexts();

        if (!$hasNormalizationContext && !$hasDenormalizationContext) {
            return $options;
        }

        $propertyOptions = $options;
        $propertyOptions['normalization_groups'] ??= $options['serializer_groups'];
        $propertyOptions['denormalization_groups'] ??= $options['serializer_groups'];
        // we don't rely on 'serializer_groups' anymore because `context` changes normalization and/or denormalization
        // but does not have options for both at the same time
        unset($propertyOptions['serializer_groups']);

        if ($hasNormalizationContext) {
            $originalGroups = $options['normalization_groups'] ?? $options['serializer_groups'];
            $propertyContext = $attributeMetadata->getNormalizationContextForGroups((array) $originalGroups);
            $propertyOptions['normalization_groups'] = $propertyContext[AbstractNormalizer::GROUPS] ?? $originalGroups;
        }
        if ($hasDenormalizationContext) {
            $originalGroups = $options['denormalization_groups'] ?? $options['serializer_groups'];
            $propertyContext = $attributeMetadata->getDenormalizationContextForGroups((array) $originalGroups);
            $propertyOptions['denormalization_groups'] = $propertyContext[AbstractNormalizer::GROUPS] ?? $originalGroups;
        }

        return $propertyOptions;
    }
}
