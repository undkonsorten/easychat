<?php

namespace Undkonsorten\Easychat\Indexing;

use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Resolves a frontend uri back into what it addresses — page, language and route
 * arguments — the same way TYPO3 routes an incoming request.
 *
 * Seeding point ids from this instead of the uri path keeps them stable when a slug
 * or a route enhancer changes: the page is still the same page, and a record behind
 * a route enhancer is still the same record.
 */
class RouteArgumentsResolver
{
    public function __construct(
        private readonly SiteMatcher $siteMatcher,
    ) {}

    /**
     * @return array{pageUid: int, language: int, arguments: string}|null null if the uri cannot be routed
     */
    public function resolve(string $uri): ?array
    {
        if ($uri === '') {
            return null;
        }

        try {
            $request = new ServerRequest($uri, 'GET');
            parse_str($request->getUri()->getQuery(), $queryParams);
            $request = $request->withQueryParams($queryParams);

            $siteRouteResult = $this->siteMatcher->matchRequest($request);
            if (!$siteRouteResult instanceof SiteRouteResult
                || !($site = $siteRouteResult->getSite()) instanceof Site
                || ($language = $siteRouteResult->getLanguage()) === null
            ) {
                return null;
            }

            $pageArguments = $site->getRouter()->matchRequest($request, $siteRouteResult);
            if (!$pageArguments instanceof PageArguments) {
                return null;
            }
        } catch (\Throwable) {
            // Unroutable for whatever reason (unknown host, page gone, enhancer mismatch);
            // the caller falls back to the uri itself.
            return null;
        }

        $arguments = $pageArguments->getArguments();
        unset($arguments['cHash'], $arguments['id']);
        self::sortRecursive($arguments);

        return [
            'pageUid' => $pageArguments->getPageId(),
            'language' => $language->getLanguageId(),
            'arguments' => http_build_query($arguments),
        ];
    }

    /**
     * @param array<array-key, mixed> $array
     */
    private static function sortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::sortRecursive($value);
            }
        }
    }
}
