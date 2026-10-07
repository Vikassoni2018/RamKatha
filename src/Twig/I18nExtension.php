<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\I18n\ContentLocalizer;
use App\Service\I18n\Locales;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Hindi/English helpers for templates:
 *
 *   {{ story|localized('title') }}      English text on /en pages when it exists, else the Hindi original
 *   <h1{{ lang_attr(story, 'title') }}> ' lang="hi"' when that text is not in the page language, else ''
 *   content_lang(story, 'title')        language of that text ("en" or the content language)
 *   {{ locale_url('en') }}              the current page in the other language (language switcher)
 *   {% if canonical_route() == 'app_home' %}   route name without the .hi/.en suffix
 *   {{ js_messages()|json_encode }}     UI strings for the Stimulus controllers (translation keys js.*)
 */
final class I18nExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContentLocalizer $content,
        private readonly RequestStack $requests,
        private readonly RouterInterface $router,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('content_lang', $this->content->textLanguage(...)),
            new TwigFunction('lang_attr', $this->langAttribute(...), ['is_safe' => ['html']]),
            new TwigFunction('canonical_route', $this->canonicalRoute(...)),
            new TwigFunction('locale_url', $this->localeUrl(...)),
            new TwigFunction('available_locales', Locales::all(...)),
            new TwigFunction('og_locale', Locales::ogLocale(...)),
            new TwigFunction('js_messages', $this->jsMessages(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('localized', $this->content->text(...)),
        ];
    }

    /**
     * ' lang="hi"' when the localized text of this field is not in the page language (an untranslated
     * Hindi text on an English page), otherwise an empty string — Hindi pages stay unchanged.
     *
     * @param object|array<string, mixed>|null $subject
     */
    public function langAttribute(object|array|null $subject, string $field = 'title'): string
    {
        $language = $this->content->textLanguage($subject, $field);
        if ($language === $this->content->locale() || !preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language)) {
            return '';
        }

        return ' lang="'.$language.'"';
    }

    /** Route name without the locale suffix ("app_story_show" for app_story_show.en). */
    public function canonicalRoute(): ?string
    {
        $request = $this->requests->getMainRequest();
        $route = $request?->attributes->get('_canonical_route') ?? $request?->attributes->get('_route');

        return \is_string($route) ? $route : null;
    }

    /** URL of the page being viewed, in another language. Keeps route parameters and the query string. */
    public function localeUrl(string $locale): string
    {
        $locale = Locales::normalize($locale);
        $request = $this->requests->getMainRequest();
        if (null === $request) {
            return $this->router->generate('app_home', ['_locale' => $locale]);
        }

        $route = $request->attributes->get('_canonical_route');
        if (\is_string($route)) {
            if (!$this->acceptsGet($request, $route.'.'.$locale)) {
                // A page rendered after a POST-only action (e.g. a failed form): offer the home page.
                return $this->router->generate('app_home', ['_locale' => $locale]);
            }
            $params = $request->attributes->get('_route_params', []);
            $query = array_diff_key($request->query->all(), $params);

            return $this->router->generate($route, ['_locale' => $locale] + $params + $query);
        }

        // Admin preview of a story: switch the preview language.
        if ('admin_story_preview' === $request->attributes->get('_route')) {
            return $this->router->generate('admin_story_preview', $request->attributes->get('_route_params', []) + ['locale' => $locale]);
        }

        // No (localized) route, e.g. a 404 page: swap the path prefix.
        $path = $request->getPathInfo();
        $current = Locales::fromPath($path);
        $path = substr($path, \strlen(Locales::PREFIXES[$current])) ?: '/';
        $path = Locales::PREFIXES[$locale].('/' === $path && '' !== Locales::PREFIXES[$locale] ? '' : $path);

        return $request->getBaseUrl().$path.(null !== $request->getQueryString() ? '?'.$request->getQueryString() : '');
    }

    /**
     * The js.* translations of the current language, keyed without the "js." prefix.
     *
     * @return array<string, string>
     */
    public function jsMessages(): array
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            return [];
        }
        $catalogue = $this->translator->getCatalogue();
        $keys = [];
        for ($c = $catalogue; null !== $c; $c = $c->getFallbackCatalogue()) {
            foreach (array_keys($c->all('messages')) as $key) {
                if (str_starts_with($key, 'js.')) {
                    $keys[$key] = true;
                }
            }
        }
        $messages = [];
        foreach (array_keys($keys) as $key) {
            $messages[substr($key, 3)] = $this->translator->trans($key);
        }

        return $messages;
    }

    private function acceptsGet(Request $request, string $routeName): bool
    {
        if ($request->isMethodCacheable()) {
            return true;
        }
        $route = $this->router->getRouteCollection()->get($routeName);
        $methods = $route?->getMethods() ?? [];

        return [] === $methods || \in_array('GET', $methods, true);
    }
}
