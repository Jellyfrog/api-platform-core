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

namespace ApiPlatform\Laravel\Tests\Eloquent\Metadata\Factory\Property;

use ApiPlatform\Laravel\Eloquent\Metadata\Factory\Property\EloquentPropertyNameCollectionMetadataFactory;
use ApiPlatform\Laravel\Eloquent\Metadata\ModelMetadata;
use ApiPlatform\Metadata\ResourceClassResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\Post;

final class EloquentPropertyNameCollectionMetadataFactoryTest extends TestCase
{
    use RefreshDatabase;
    use WithWorkbench;

    public function testToManyRelationsAreExcludedFromPropertyNames(): void
    {
        $resourceClassResolver = $this->createMock(ResourceClassResolverInterface::class);
        $resourceClassResolver->method('isResourceClass')->willReturn(true);

        $factory = new EloquentPropertyNameCollectionMetadataFactory(
            new ModelMetadata(),
            null,
            $resourceClassResolver,
        );

        $properties = iterator_to_array($factory->create(Post::class));

        $this->assertNotContains('comments', $properties, 'HasMany relations should not be included in property names');
    }
}
