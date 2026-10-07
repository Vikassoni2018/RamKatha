<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\UserCollection;
use App\Enum\CollectionVisibility;
use App\Tests\Functional\AppWebTestCase;

final class CollectionTest extends AppWebTestCase
{
    public function testCreateCollectionAddStoryAndPreventDuplicates(): void
    {
        $this->loginAs('user');
        $story = $this->story('makhan-chor-bal-krishna')->getUuid()->toRfc4122();

        $data = $this->json('POST', '/api/collections', ['name' => 'प्रातः श्रवण', 'story' => $story]);
        self::assertResponseStatusCodeSame(201);
        $created = array_values(array_filter($data['collections'], static fn (array $c) => 'प्रातः श्रवण' === $c['name']));
        self::assertCount(1, $created);
        self::assertTrue($created[0]['contains']);
        self::assertSame(1, $created[0]['count']);

        $again = $this->json('POST', $created[0]['itemsUrl'], ['story' => $story]);
        self::assertSame(['contains' => true, 'count' => 1], $again, 'Adding the same story twice must not duplicate it.');

        $removed = $this->json('DELETE', $created[0]['itemsUrl'], ['story' => $story]);
        self::assertSame(['contains' => false, 'count' => 0], $removed);
    }

    public function testCollectionNameIsValidated(): void
    {
        $this->loginAs('user');
        $this->json('POST', '/api/collections', ['name' => 'x']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testOtherUsersCannotModifyACollection(): void
    {
        $collection = $this->em()->getRepository(UserCollection::class)->findOneBy(['slug' => 'ratri-shravan']);
        self::assertNotNull($collection);
        $this->loginAs('editor');

        $this->json('POST', '/api/collections/'.$collection->getUuid()->toRfc4122().'/items', ['story' => $this->story('makhan-chor-bal-krishna')->getUuid()->toRfc4122()]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testPrivateCollectionsAreInvisibleToOthers(): void
    {
        $em = $this->em();
        $private = new UserCollection($this->user('user'), 'निजी संग्रह');
        $private->setSlug('niji-sangrah')->setVisibility(CollectionVisibility::Private);
        $em->persist($private);
        $em->flush();
        $url = '/collection/'.$private->getUuid()->toRfc4122();

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404, 'Anonymous visitors must not learn that it exists.');

        $this->loginAs('author');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        $this->loginAs('user');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testUnlistedCollectionIsShareableButNotIndexed(): void
    {
        $collection = $this->em()->getRepository(UserCollection::class)->findOneBy(['slug' => 'ratri-shravan']);
        self::assertNotNull($collection);

        $this->client->request('GET', '/collection/'.$collection->getUuid()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
    }
}
