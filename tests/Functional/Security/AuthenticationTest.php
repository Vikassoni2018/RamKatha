<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\DataFixtures\AppFixtures;
use App\Entity\User;
use App\Tests\Functional\AppWebTestCase;

final class AuthenticationTest extends AppWebTestCase
{
    private function submitLogin(string $email, string $password, array $extra = []): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form[action="/login"] button[type="submit"]')->form(['email' => $email, 'password' => $password] + $extra);
        $this->client->submit($form);
    }

    public function testLoginWithValidCredentials(): void
    {
        $this->submitLogin('user@example.test', AppFixtures::PASSWORD);

        self::assertResponseRedirects('/account');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testWrongPasswordIsRejectedWithoutRevealingWhichFieldFailed(): void
    {
        $this->submitLogin('user@example.test', 'wrong-password');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert');
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login', 302, 'Still anonymous.');
    }

    public function testDeactivatedAccountCannotLogIn(): void
    {
        $this->submitLogin('deactivated@example.test', AppFixtures::PASSWORD);

        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    public function testCrossSiteLoginIsRejected(): void
    {
        $this->client->request('POST', '/login', [
            'email' => 'user@example.test',
            'password' => AppFixtures::PASSWORD,
            '_csrf_token' => 'csrf-token',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login', 302, 'Login CSRF must not authenticate the visitor.');
    }

    public function testExternalTargetPathCannotCauseAnOpenRedirect(): void
    {
        $this->submitLogin('user@example.test', AppFixtures::PASSWORD, ['_target_path' => 'https://evil.example/phish']);

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('evil.example', $location);
    }

    public function testLogoutRequiresPostWithCsrf(): void
    {
        $this->loginAs('user');

        // A link or <img src="/logout"> on another site must not log the user out.
        $this->client->request('GET', '/logout');
        $this->client->request('POST', '/logout', [], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/logout', ['_csrf_token' => 'csrf-token'], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    public function testDeactivationEndsAnExistingSession(): void
    {
        $user = $this->loginAs('user');
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $em = $this->em();
        $em->find(User::class, $user->getId())?->deactivate();
        $em->flush();

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    public function testPasswordsAreHashed(): void
    {
        $hash = $this->user('user')->getPassword();

        self::assertNotSame(AppFixtures::PASSWORD, $hash);
        self::assertTrue(password_verify(AppFixtures::PASSWORD, (string) $hash));
    }

    public function testRegistration(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form[name="registration"]')->form([
            'registration[displayName]' => 'नए भक्त',
            'registration[email]' => 'naye.bhakt@example.test',
            'registration[plainPassword][first]' => 'Jai-Shri-Ram#2026',
            'registration[plainPassword][second]' => 'Jai-Shri-Ram#2026',
        ]);
        $form['registration[agreeTerms]']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/account');
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'naye.bhakt@example.test']);
        self::assertNotNull($user);
        self::assertSame(['ROLE_USER'], $user->getRoles(), 'Self-registration never grants staff roles.');
        self::assertFalse($user->isVerified());
    }

    public function testRegistrationRejectsExtraFieldsLikeRoles(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $values = $crawler->filter('form[name="registration"]')->form([
            'registration[displayName]' => 'Mallory',
            'registration[email]' => 'mallory@example.test',
            'registration[plainPassword][first]' => 'Jai-Shri-Ram#2026',
            'registration[plainPassword][second]' => 'Jai-Shri-Ram#2026',
        ])->getPhpValues();
        $values['registration']['agreeTerms'] = '1';
        $values['registration']['roles'] = ['ROLE_SUPER_ADMIN'];

        $this->client->request('POST', '/register', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'mallory@example.test']));
    }

    public function testRegistrationHoneypotSilentlyDropsBots(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form[name="registration"]')->form([
            'registration[displayName]' => 'Bot',
            'registration[email]' => 'bot@example.test',
            'registration[plainPassword][first]' => 'Jai-Shri-Ram#2026',
            'registration[plainPassword][second]' => 'Jai-Shri-Ram#2026',
            'registration[website]' => 'https://spam.example',
        ]);
        $form['registration[agreeTerms]']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/');
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'bot@example.test']));
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form[name="registration"]')->form([
            'registration[displayName]' => 'Duplicate',
            'registration[email]' => 'USER@example.test',
            'registration[plainPassword][first]' => 'Jai-Shri-Ram#2026',
            'registration[plainPassword][second]' => 'Jai-Shri-Ram#2026',
        ]);
        $form['registration[agreeTerms]']->tick();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
    }
}
