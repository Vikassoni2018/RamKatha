<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Functional\AppWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AccessControlTest extends AppWebTestCase
{
    public function testAnonymousVisitorsAreSentToLogin(): void
    {
        foreach (['/admin', '/admin/stories', '/admin/donations', '/account', '/collections'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login', 302, $url);
        }
    }

    /** @return iterable<string, array{string, array<string, int>}> */
    public static function roleMatrix(): iterable
    {
        yield 'reader' => ['user', ['/admin' => 403, '/admin/stories' => 403, '/account' => 200]];
        yield 'author' => ['author', ['/admin' => 200, '/admin/stories' => 200, '/admin/users' => 403, '/admin/donations' => 403, '/admin/taxonomy/category' => 403]];
        yield 'reviewer' => ['reviewer', ['/admin/stories' => 200, '/admin/audio' => 403, '/admin/users' => 403]];
        yield 'editor' => ['editor', ['/admin/stories' => 200, '/admin/audio' => 200, '/admin/taxonomy/category' => 200, '/admin/users' => 403, '/admin/donations' => 403, '/admin/settings/general' => 403]];
        yield 'admin' => ['admin', ['/admin/users' => 200, '/admin/donations' => 200, '/admin/audit' => 200, '/admin/settings/general' => 403, '/admin/system' => 403]];
        yield 'super admin' => ['superadmin', ['/admin/settings/general' => 200, '/admin/system' => 200]];
    }

    /** @param array<string, int> $expectations */
    #[DataProvider('roleMatrix')]
    public function testRoleBasedAccess(string $user, array $expectations): void
    {
        $this->loginAs($user);

        foreach ($expectations as $url => $status) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame($status, \sprintf('%s → %s', $user, $url));
        }
    }

    public function testAuthorCannotEditSomeoneElsesStory(): void
    {
        $this->loginAs('author');
        $story = $this->story('makhan-chor-bal-krishna'); // created by the editor

        $this->client->request('GET', '/admin/stories/'.$story->getId().'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDeactivatedSessionIsTerminated(): void
    {
        $this->client->loginUser($this->user('deactivated'));

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }
}
