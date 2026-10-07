<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Enum\StoryStatus;
use App\Tests\Functional\AppWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class StoryAdminTest extends AppWebTestCase
{
    private function transition(int $id, string $transition, array $params = []): void
    {
        $this->post(\sprintf('/admin/stories/%d/transition/%s', $id, $transition), $params);
        self::assertResponseRedirects(\sprintf('/admin/stories/%d/edit', $id));
    }

    public function testReviewThenPublishThroughTheAdmin(): void
    {
        $story = $this->story('navratri-aur-devi-ke-nau-rup');
        $id = (int) $story->getId();

        $this->loginAs('author');
        $this->transition($id, 'verify_source');
        self::assertSame(StoryStatus::UnderReview, $this->reload($story)->getStatus(), 'Authors cannot verify sources.');

        $this->loginAs('reviewer');
        $this->transition($id, 'verify_source', ['notes' => 'स्रोत मिलान किया।']);
        self::assertSame(StoryStatus::SourceVerified, $this->reload($story)->getStatus());
        $this->transition($id, 'approve');
        self::assertSame(StoryStatus::SourceVerified, $this->reload($story)->getStatus(), 'Reviewers cannot approve for publication.');

        $this->client->request('GET', '/katha/navratri-aur-devi-ke-nau-rup');
        self::assertResponseStatusCodeSame(404, 'Not public before publication.');

        $this->loginAs('editor');
        $this->transition($id, 'approve');
        $this->transition($id, 'publish');
        self::assertSame(StoryStatus::Published, $this->reload($story)->getStatus());

        $this->client->request('GET', '/katha/navratri-aur-devi-ke-nau-rup');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.story-header .badge-verified', 'स्रोत सत्यापित');
    }

    public function testDraftCannotJumpToPublished(): void
    {
        $story = $this->story('ganesh-ji-aur-chandrama');
        $this->loginAs('superadmin');

        $this->transition((int) $story->getId(), 'publish');

        self::assertSame(StoryStatus::Draft, $this->reload($story)->getStatus());
    }

    public function testTransitionsRequireSameOriginCsrf(): void
    {
        $story = $this->story('navratri-aur-devi-ke-nau-rup');
        $this->loginAs('reviewer');

        $this->client->request('POST', '/admin/stories/'.$story->getId().'/transition/verify_source', ['_csrf_token' => 'csrf-token'], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/admin/stories/'.$story->getId().'/transition/verify_source');
        self::assertResponseStatusCodeSame(400);

        self::assertSame(StoryStatus::UnderReview, $this->reload($story)->getStatus());
    }

    public function testPreviewOfDraftIsStaffOnly(): void
    {
        $story = $this->story('ganesh-ji-aur-chandrama'); // the author's own draft

        $this->loginAs('author');
        $this->client->request('GET', '/admin/stories/'.$story->getId().'/preview');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');

        $this->loginAs('user');
        $this->client->request('GET', '/admin/stories/'.$story->getId().'/preview');
        self::assertResponseStatusCodeSame(403);
    }

    public function testMediaUploadRejectsDisguisedScripts(): void
    {
        $this->loginAs('editor');
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, "<?php system(\$_GET['c']); ?>");

        $this->client->request('POST', '/admin/media/api/upload', ['_csrf_token' => 'csrf-token'], [
            'file' => new UploadedFile($path, 'cover.jpg', 'image/jpeg', null, true),
        ], ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_X_CSRF_TOKEN' => 'csrf-token', 'HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('JPEG, PNG', (string) $this->client->getResponse()->getContent());
        @unlink($path);
    }
}
