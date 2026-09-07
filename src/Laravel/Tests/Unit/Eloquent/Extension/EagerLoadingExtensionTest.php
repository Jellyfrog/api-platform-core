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

namespace ApiPlatform\Laravel\Tests\Unit\Eloquent\Extension;

use ApiPlatform\Laravel\Eloquent\Extension\EagerLoadingExtension;
use ApiPlatform\Laravel\Eloquent\Metadata\ModelMetadata;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Exception\RuntimeException;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Serializer\Mapping\AttributeMetadata;
use Symfony\Component\Serializer\Mapping\ClassMetadata;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Workbench\App\Models\Author;
use Workbench\App\Models\Book;

class EagerLoadingExtensionTest extends TestCase
{
    /** @var Builder<Model> */
    private Builder $builder;

    public function testNoGroupsReturnsBuilderUnchanged(): void
    {
        $result = $this->applyExtension([], new ApiProperty(readable: true), null, normalizationContext: null);

        $this->assertSame($this->builder, $result);
    }

    public function testRelationInGroupIsEagerLoaded(): void
    {
        $this->applyExtension(
            [Book::class => ['author' => $this->relation('author', Book::class)]],
            new ApiProperty(readable: true, readableLink: false),
            ['author'],
        );
    }

    public function testUriTemplateRelationIsSkipped(): void
    {
        $this->applyExtension(
            [Book::class => ['comments' => $this->relation('comments', Book::class)]],
            new ApiProperty(readable: true, uriTemplate: '/posts/{post}/comments{._format}'),
            null,
        );
    }

    public function testFetchEagerFalseIsSkipped(): void
    {
        $this->applyExtension(
            [Book::class => ['author' => $this->relation('author', Book::class)]],
            new ApiProperty(readable: true, fetchEager: false),
            null,
        );
    }

    public function testForceEagerFalseOnlyLoadsExplicitFetchEager(): void
    {
        // Eloquent has no per-relation fetch mode, so fetchEager plays the role Doctrine's EAGER
        // fetch mode plays in the Symfony version.
        $this->applyExtension(
            [Book::class => [
                'author' => $this->relation('author', Book::class),
                'editor' => $this->relation('editor', Book::class),
            ]],
            static fn (string $resourceClass, string $property): ApiProperty => 'editor' === $property
                ? new ApiProperty(readable: true, fetchEager: true)
                : new ApiProperty(readable: true),
            ['editor'],
            forceEager: false,
        );
    }

    public function testSelfReferencingRelationDoesNotLoop(): void
    {
        $this->applyExtension(
            [Book::class => ['parent' => $this->relation('parent', Book::class)]],
            new ApiProperty(readable: true, readableLink: true),
            ['parent'],
        );
    }

    public function testNestedEagerLoading(): void
    {
        $this->applyExtension(
            [
                Book::class => ['author' => $this->relation('author', Author::class)],
                Author::class => ['books' => $this->relation('books', Book::class)],
            ],
            // Only an embedded relation is walked further, so the author is walked and its books,
            // rendered as links, end the branch.
            static fn (string $resourceClass, string $property): ApiProperty => new ApiProperty(
                readable: true,
                readableLink: 'author' === $property,
            ),
            ['author', 'author.books'],
        );
    }

    public function testMaxJoinsExceededThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The total number of eager loaded relations has exceeded the specified maximum.');

        // Both sides are embedded, so the walk ping-pongs between the two models until it gives up.
        $this->applyExtension(
            [
                Book::class => ['author' => $this->relation('author', Author::class)],
                Author::class => ['books' => $this->relation('books', Book::class)],
            ],
            new ApiProperty(readable: true, readableLink: true),
            null,
            maxJoins: 2,
        );
    }

    public function testEagerLoadUsesTheRelationMethodName(): void
    {
        $this->applyExtension(
            [Book::class => ['author_with_group' => $this->relation('author_with_group', Author::class, 'authorWithGroup')]],
            function (string $resourceClass, string $property): ApiProperty {
                // Metadata is looked up by property name, the eager load is issued by method name.
                $this->assertSame('author_with_group', $property);

                return new ApiProperty(readable: true, readableLink: false);
            },
            ['authorWithGroup'],
        );
    }

    public function testAlreadyEagerLoadedRelationIsNotReplaced(): void
    {
        // Builder::with() merges by relation name, so re-adding a relation another extension already
        // constrained would drop its constraint. Its nested paths would do the same.
        $this->applyExtension(
            [
                Book::class => ['author' => $this->relation('author', Author::class)],
                Author::class => ['books' => $this->relation('books', Book::class)],
            ],
            static fn (string $resourceClass, string $property): ApiProperty => new ApiProperty(
                readable: true,
                readableLink: 'author' === $property,
            ),
            null,
            alreadyEagerLoaded: ['author' => static function (): void {}],
        );
    }

    public function testRelationOutsideTheRequestedAttributesIsSkipped(): void
    {
        $this->applyExtension(
            [Book::class => ['author' => $this->relation('author', Author::class)]],
            new ApiProperty(readable: true, readableLink: false),
            null,
            context: [AbstractNormalizer::ATTRIBUTES => ['title' => true]],
        );
    }

    public function testRequestedAttributesNarrowTheSubtree(): void
    {
        // The author is asked for, but only its name is: its own relations are not part of the payload.
        $this->applyExtension(
            [
                Book::class => ['author' => $this->relation('author', Author::class)],
                Author::class => ['books' => $this->relation('books', Book::class)],
            ],
            new ApiProperty(readable: true, readableLink: true),
            ['author'],
            context: [AbstractNormalizer::ATTRIBUTES => ['author' => ['name' => true]]],
        );
    }

    public function testMaxDepthLimitsTheWalk(): void
    {
        $this->applyExtension(
            [
                Book::class => ['author' => $this->relation('author', Author::class)],
                Author::class => ['books' => $this->relation('books', Book::class)],
            ],
            new ApiProperty(readable: true, readableLink: true),
            ['author'],
            context: [AbstractObjectNormalizer::ENABLE_MAX_DEPTH => true],
            classMetadataFactory: $this->classMetadataFactory(Book::class, 'author', 1),
        );
    }

    public function testMaxDepthIsIgnoredWhenNotEnabled(): void
    {
        $this->applyExtension(
            [
                Book::class => ['author' => $this->relation('author', Author::class)],
                Author::class => ['books' => $this->relation('books', Book::class)],
            ],
            static fn (string $resourceClass, string $property): ApiProperty => new ApiProperty(
                readable: true,
                readableLink: 'author' === $property,
            ),
            ['author', 'author.books'],
            classMetadataFactory: $this->classMetadataFactory(Book::class, 'author', 1),
        );
    }

    public function testDenormalizationGroupsAreUsedWhenDenormalizing(): void
    {
        $propertyMetadataFactory = $this->createMock(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactory->method('create')->willReturnCallback(
            function (string $resourceClass, string $property, array $options): ApiProperty {
                // A relation denormalized from an IRI is read with the denormalization context.
                $this->assertSame(['book:write'], $options['serializer_groups']);

                return new ApiProperty(readable: true, readableLink: false);
            }
        );

        $extension = new EagerLoadingExtension(
            $propertyMetadataFactory,
            new ModelMetadata(relations: [Book::class => ['author' => $this->relation('author', Author::class)]]),
        );

        $extension->apply(
            $this->mockBuilder(['author']),
            [],
            new Get(class: Book::class, normalizationContext: ['groups' => ['book:read']], denormalizationContext: ['groups' => ['book:write']]),
            ['api_denormalize' => true],
        );
    }

    public function testComputedEagerLoadsAreNotSharedAcrossSerializationContexts(): void
    {
        $propertyMetadataFactory = $this->createMock(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactory->method('create')->willReturnCallback(
            static fn (string $resourceClass, string $property, array $options): ApiProperty => new ApiProperty(
                readable: ['book:read'] === ($options['serializer_groups'] ?? null),
                readableLink: false,
            )
        );

        $extension = new EagerLoadingExtension(
            $propertyMetadataFactory,
            new ModelMetadata(relations: [Book::class => ['author' => $this->relation('author', Author::class)]]),
        );

        $extension->apply(
            $this->mockBuilder(['author']),
            [],
            new Get(class: Book::class, normalizationContext: ['groups' => ['book:read']])
        );

        // The same model under different groups must not be served the first result.
        $extension->apply(
            $this->mockBuilder(null),
            [],
            new Get(class: Book::class, normalizationContext: ['groups' => ['book:write']])
        );
    }

    /**
     * Runs the extension over a seeded relation graph.
     *
     * @param array<class-string, array<string, mixed>> $relations            seeds the model relation cache
     * @param ApiProperty|\Closure                      $propertyMetadata     the metadata returned for every property, or a callable returning it
     * @param list<string>|null                         $expectedEagerLoads   the relations expected to be eager loaded, null when none should be
     * @param array<string, mixed>|null                 $normalizationContext the operation normalization context
     * @param array<string, mixed>                      $alreadyEagerLoaded   eager loads already registered on the builder
     * @param array<string, mixed>                      $context              the context the extension is applied with
     *
     * @return Builder<Model>
     */
    private function applyExtension(array $relations, ApiProperty|\Closure $propertyMetadata, ?array $expectedEagerLoads, bool $forceEager = true, ?array $normalizationContext = ['groups' => ['book:read']], array $alreadyEagerLoaded = [], array $context = [], int $maxJoins = 30, ?ClassMetadataFactoryInterface $classMetadataFactory = null): Builder
    {
        $propertyMetadataFactory = $this->createMock(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactory->method('create')->willReturnCallback(
            $propertyMetadata instanceof \Closure ? $propertyMetadata : (static fn (): ApiProperty => $propertyMetadata)
        );

        $this->builder = $builder = $this->mockBuilder($expectedEagerLoads, $alreadyEagerLoaded);

        $extension = new EagerLoadingExtension(
            $propertyMetadataFactory,
            new ModelMetadata(relations: $relations),
            maxJoins: $maxJoins,
            forceEager: $forceEager,
            classMetadataFactory: $classMetadataFactory,
        );

        return $extension->apply($builder, [], new Get(class: Book::class, normalizationContext: $normalizationContext), $context);
    }

    /**
     * @param list<string>|null    $expectedEagerLoads the relations expected to be eager loaded, null when none should be
     * @param array<string, mixed> $alreadyEagerLoaded eager loads already registered on the builder
     *
     * @return Builder<Model>
     */
    private function mockBuilder(?array $expectedEagerLoads, array $alreadyEagerLoaded = []): Builder
    {
        $builder = $this->createMock(Builder::class);
        $builder->method('getModel')->willReturn(new Book());
        $builder->method('getEagerLoads')->willReturn($alreadyEagerLoaded);

        if (null === $expectedEagerLoads) {
            $builder->expects($this->never())->method('with');
        } else {
            $builder->expects($this->once())->method('with')->with($expectedEagerLoads)->willReturnSelf();
        }

        return $builder;
    }

    /**
     * A serializer class metadata factory declaring a max depth on a single attribute.
     */
    private function classMetadataFactory(string $class, string $attribute, int $maxDepth): ClassMetadataFactoryInterface
    {
        $attributeMetadata = new AttributeMetadata($attribute);
        $attributeMetadata->setMaxDepth($maxDepth);

        $classMetadata = new ClassMetadata($class);
        $classMetadata->addAttributeMetadata($attributeMetadata);

        $classMetadataFactory = $this->createMock(ClassMetadataFactoryInterface::class);
        $classMetadataFactory->method('getMetadataFor')->willReturnCallback(
            static fn (string|object $value): ClassMetadata => $class === $value ? $classMetadata : new ClassMetadata(\is_string($value) ? $value : $value::class)
        );

        return $classMetadataFactory;
    }

    /**
     * @param class-string<Model> $related
     *
     * @return array{name: string, method_name: string, type: class-string, related: class-string<Model>, foreign_key: string}
     */
    private function relation(string $name, string $related, ?string $methodName = null): array
    {
        return [
            'name' => $name,
            'method_name' => $methodName ?? $name,
            'type' => BelongsTo::class,
            'related' => $related,
            'foreign_key' => $name.'_id',
        ];
    }
}
