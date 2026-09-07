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
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Orchestra\Testbench\TestCase;
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
            new ApiProperty(readable: true, readableLink: true),
            ['author', 'author.books'],
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
            new ApiProperty(readable: true, readableLink: true),
            null,
            alreadyEagerLoaded: ['author' => static function (): void {}],
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
     *
     * @return Builder<Model>
     */
    private function applyExtension(array $relations, ApiProperty|\Closure $propertyMetadata, ?array $expectedEagerLoads, bool $forceEager = true, ?array $normalizationContext = ['groups' => ['book:read']], array $alreadyEagerLoaded = []): Builder
    {
        $propertyMetadataFactory = $this->createMock(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactory->method('create')->willReturnCallback(
            $propertyMetadata instanceof \Closure ? $propertyMetadata : (static fn (): ApiProperty => $propertyMetadata)
        );

        $this->builder = $builder = $this->mockBuilder($expectedEagerLoads, $alreadyEagerLoaded);

        $extension = new EagerLoadingExtension(
            $propertyMetadataFactory,
            new ModelMetadata(relations: $relations),
            forceEager: $forceEager,
        );

        return $extension->apply($builder, [], new Get(class: Book::class, normalizationContext: $normalizationContext));
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
