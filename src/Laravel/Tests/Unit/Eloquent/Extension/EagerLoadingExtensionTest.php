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
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\Book;

class EagerLoadingExtensionTest extends TestCase
{
    /**
     * @param array<class-string, array<string, mixed>> $relationsCache
     */
    private function createModelMetadataWithCache(array $relationsCache): ModelMetadata
    {
        $modelMetadata = new ModelMetadata();
        $refl = new \ReflectionProperty(ModelMetadata::class, 'relationsLocalCache');
        $refl->setValue($modelMetadata, $relationsCache);

        return $modelMetadata;
    }

    public function testNoGroupsReturnsBuilderUnchanged(): void
    {
        $propertyNameCollectionFactory = $this->createMock(PropertyNameCollectionFactoryInterface::class);
        $propertyMetadataFactory = $this->createMock(PropertyMetadataFactoryInterface::class);
        $modelMetadata = $this->createModelMetadataWithCache([]);

        $extension = new EagerLoadingExtension(
            $propertyNameCollectionFactory,
            $propertyMetadataFactory,
            $modelMetadata,
        );

        $builder = $this->createMock(Builder::class);
        $builder->method('getModel')->willReturn(new Book());
        $builder->expects($this->never())->method('with');

        $operation = new Get(class: Book::class);

        $result = $extension->apply($builder, [], $operation);
        $this->assertSame($builder, $result);
    }

    public function testRelationInGroupIsEagerLoaded(): void
    {
        $propertyNameCollectionFactory = $this->createMock(PropertyNameCollectionFactoryInterface::class);
        $propertyMetadataFactory = $this->createMock(PropertyMetadataFactoryInterface::class);

        $modelMetadata = $this->createModelMetadataWithCache([
            Book::class => [
                'author' => [
                    'name' => 'author',
                    'method_name' => 'author',
                    'type' => 'Illuminate\Database\Eloquent\Relations\BelongsTo',
                    'related' => Book::class,
                    'foreign_key' => 'author_id',
                ],
            ],
        ]);

        $extension = new EagerLoadingExtension(
            $propertyNameCollectionFactory,
            $propertyMetadataFactory,
            $modelMetadata,
        );

        $propertyMetadataFactory->method('create')->willReturn(
            new ApiProperty(readable: true, readableLink: false)
        );

        $builder = $this->createMock(Builder::class);
        $builder->method('getModel')->willReturn(new Book());
        $builder->expects($this->once())
            ->method('with')
            ->with(['author'])
            ->willReturnSelf();

        $operation = new Get(class: Book::class, normalizationContext: ['groups' => ['book:read']]);

        $extension->apply($builder, [], $operation);
    }

    public function testUriTemplateRelationIsSkipped(): void
    {
        $propertyNameCollectionFactory = $this->createMock(PropertyNameCollectionFactoryInterface::class);
        $propertyMetadataFactory = $this->createMock(PropertyMetadataFactoryInterface::class);

        $modelMetadata = $this->createModelMetadataWithCache([
            Book::class => [
                'comments' => [
                    'name' => 'comments',
                    'method_name' => 'comments',
                    'type' => 'Illuminate\Database\Eloquent\Relations\HasMany',
                    'related' => Book::class,
                    'foreign_key' => 'post_id',
                ],
            ],
        ]);

        $extension = new EagerLoadingExtension(
            $propertyNameCollectionFactory,
            $propertyMetadataFactory,
            $modelMetadata,
        );

        $propertyMetadataFactory->method('create')->willReturn(
            new ApiProperty(readable: true, uriTemplate: '/posts/{post}/comments{._format}')
        );

        $builder = $this->createMock(Builder::class);
        $builder->method('getModel')->willReturn(new Book());
        $builder->expects($this->never())->method('with');

        $operation = new Get(class: Book::class, normalizationContext: ['groups' => ['post:read']]);

        $extension->apply($builder, [], $operation);
    }

    public function testFetchEagerFalseIsSkipped(): void
    {
        $propertyNameCollectionFactory = $this->createMock(PropertyNameCollectionFactoryInterface::class);
        $propertyMetadataFactory = $this->createMock(PropertyMetadataFactoryInterface::class);

        $modelMetadata = $this->createModelMetadataWithCache([
            Book::class => [
                'author' => [
                    'name' => 'author',
                    'method_name' => 'author',
                    'type' => 'Illuminate\Database\Eloquent\Relations\BelongsTo',
                    'related' => Book::class,
                    'foreign_key' => 'author_id',
                ],
            ],
        ]);

        $extension = new EagerLoadingExtension(
            $propertyNameCollectionFactory,
            $propertyMetadataFactory,
            $modelMetadata,
        );

        $propertyMetadataFactory->method('create')->willReturn(
            new ApiProperty(readable: true, fetchEager: false)
        );

        $builder = $this->createMock(Builder::class);
        $builder->method('getModel')->willReturn(new Book());
        $builder->expects($this->never())->method('with');

        $operation = new Get(class: Book::class, normalizationContext: ['groups' => ['book:read']]);

        $extension->apply($builder, [], $operation);
    }

    public function testForceEagerFalseOnlyLoadsExplicitFetchEager(): void
    {
        $propertyNameCollectionFactory = $this->createMock(PropertyNameCollectionFactoryInterface::class);
        $propertyMetadataFactory = $this->createMock(PropertyMetadataFactoryInterface::class);

        $modelMetadata = $this->createModelMetadataWithCache([
            Book::class => [
                'author' => [
                    'name' => 'author',
                    'method_name' => 'author',
                    'type' => 'Illuminate\Database\Eloquent\Relations\BelongsTo',
                    'related' => Book::class,
                    'foreign_key' => 'author_id',
                ],
                'editor' => [
                    'name' => 'editor',
                    'method_name' => 'editor',
                    'type' => 'Illuminate\Database\Eloquent\Relations\BelongsTo',
                    'related' => Book::class,
                    'foreign_key' => 'editor_id',
                ],
            ],
        ]);

        $extension = new EagerLoadingExtension(
            $propertyNameCollectionFactory,
            $propertyMetadataFactory,
            $modelMetadata,
            forceEager: false,
        );

        $propertyMetadataFactory->method('create')->willReturnCallback(
            static function (string $resourceClass, string $property) {
                if ('editor' === $property) {
                    return new ApiProperty(readable: true, fetchEager: true);
                }

                return new ApiProperty(readable: true);
            }
        );

        $builder = $this->createMock(Builder::class);
        $builder->method('getModel')->willReturn(new Book());
        $builder->expects($this->once())
            ->method('with')
            ->with(['editor'])
            ->willReturnSelf();

        $operation = new Get(class: Book::class, normalizationContext: ['groups' => ['book:read']]);

        $extension->apply($builder, [], $operation);
    }

    public function testSelfReferencingRelationDoesNotLoop(): void
    {
        $propertyNameCollectionFactory = $this->createMock(PropertyNameCollectionFactoryInterface::class);
        $propertyMetadataFactory = $this->createMock(PropertyMetadataFactoryInterface::class);

        $modelMetadata = $this->createModelMetadataWithCache([
            Book::class => [
                'parent' => [
                    'name' => 'parent',
                    'method_name' => 'parent',
                    'type' => 'Illuminate\Database\Eloquent\Relations\BelongsTo',
                    'related' => Book::class,
                    'foreign_key' => 'parent_id',
                ],
            ],
        ]);

        $extension = new EagerLoadingExtension(
            $propertyNameCollectionFactory,
            $propertyMetadataFactory,
            $modelMetadata,
        );

        $propertyMetadataFactory->method('create')->willReturn(
            new ApiProperty(readable: true, readableLink: true)
        );

        $builder = $this->createMock(Builder::class);
        $builder->method('getModel')->willReturn(new Book());
        $builder->expects($this->once())
            ->method('with')
            ->with(['parent'])
            ->willReturnSelf();

        $operation = new Get(class: Book::class, normalizationContext: ['groups' => ['book:read']]);

        $extension->apply($builder, [], $operation);
    }

    public function testNestedEagerLoading(): void
    {
        $propertyNameCollectionFactory = $this->createMock(PropertyNameCollectionFactoryInterface::class);
        $propertyMetadataFactory = $this->createMock(PropertyMetadataFactoryInterface::class);

        $authorModel = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'authors';
        };

        $modelMetadata = $this->createModelMetadataWithCache([
            Book::class => [
                'author' => [
                    'name' => 'author',
                    'method_name' => 'author',
                    'type' => 'Illuminate\Database\Eloquent\Relations\BelongsTo',
                    'related' => $authorModel::class,
                    'foreign_key' => 'author_id',
                ],
            ],
            $authorModel::class => [
                'books' => [
                    'name' => 'books',
                    'method_name' => 'books',
                    'type' => 'Illuminate\Database\Eloquent\Relations\HasMany',
                    'related' => Book::class,
                    'foreign_key' => 'author_id',
                ],
            ],
        ]);

        $extension = new EagerLoadingExtension(
            $propertyNameCollectionFactory,
            $propertyMetadataFactory,
            $modelMetadata,
        );

        $propertyMetadataFactory->method('create')->willReturn(
            new ApiProperty(readable: true, readableLink: true)
        );

        $builder = $this->createMock(Builder::class);
        $builder->method('getModel')->willReturn(new Book());
        $builder->expects($this->once())
            ->method('with')
            ->with($this->callback(function (array $relations) {
                return \in_array('author', $relations, true) && \in_array('author.books', $relations, true);
            }))
            ->willReturnSelf();

        $operation = new Get(class: Book::class, normalizationContext: ['groups' => ['book:read']]);

        $extension->apply($builder, [], $operation);
    }
}
