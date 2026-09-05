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

    /**
     * Runs the extension over a seeded relation graph.
     *
     * @param array<class-string, array<string, mixed>> $relations          seeds the model relation cache
     * @param ApiProperty|\Closure                      $propertyMetadata   the metadata returned for every property, or a callable returning it
     * @param list<string>|null                         $expectedEagerLoads the relations expected to be eager loaded, null when none should be
     * @param array<string, mixed>|null                 $normalizationContext
     *
     * @return Builder<Model>
     */
    private function applyExtension(array $relations, ApiProperty|\Closure $propertyMetadata, ?array $expectedEagerLoads, bool $forceEager = true, ?array $normalizationContext = ['groups' => ['book:read']]): Builder
    {
        $propertyMetadataFactory = $this->createMock(PropertyMetadataFactoryInterface::class);
        $propertyMetadataFactory->method('create')->willReturnCallback(
            $propertyMetadata instanceof \Closure ? $propertyMetadata : (static fn (): ApiProperty => $propertyMetadata)
        );

        $this->builder = $builder = $this->createMock(Builder::class);
        $builder->method('getModel')->willReturn(new Book());

        if (null === $expectedEagerLoads) {
            $builder->expects($this->never())->method('with');
        } else {
            $builder->expects($this->once())->method('with')->with($expectedEagerLoads)->willReturnSelf();
        }

        $extension = new EagerLoadingExtension(
            $propertyMetadataFactory,
            new ModelMetadata(relations: $relations),
            forceEager: $forceEager,
        );

        return $extension->apply($builder, [], new Get(class: Book::class, normalizationContext: $normalizationContext));
    }

    /**
     * @param class-string<Model> $related
     *
     * @return array{name: string, method_name: string, related: class-string<Model>}
     */
    private function relation(string $name, string $related): array
    {
        return ['name' => $name, 'method_name' => $name, 'related' => $related];
    }
}
