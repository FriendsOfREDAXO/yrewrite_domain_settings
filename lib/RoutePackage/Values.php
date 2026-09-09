<?php

namespace FriendsOfRedaxo\DomainSettings\RoutePackage;

use FriendsOfRedaxo\Api\Auth\BearerAuth;
use FriendsOfRedaxo\Api\RouteCollection;
use FriendsOfRedaxo\Api\RoutePackage;
use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;
use rex;
use rex_clang;
use rex_type;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;

use function is_array;
use function is_scalar;
use function sprintf;

/**
 * Exposes global values over the api addon.
 *
 * Two shapes of route, not one per value: a value is a field, not a resource.
 * `/api/domain-settings` returns everything, `/api/domain-settings/<section>` one section -
 * so a site with 150 fields still has a handful of routes, and the token
 * editor stays readable.
 *
 * The scope name is the route name, so registering one route per section
 * yields one permission per section: a token can be limited to `domain-settings/read`
 * for a single area without exposing the rest.
 *
 * What the API adds over YForm's own REST routes is the fallback chain -
 * clients get resolved values instead of raw rows they would have to merge
 * themselves.
 */
class Values extends RoutePackage
{
    public function loadRoutes(): void
    {
        // 'required' => false is what makes these optional - a default alone
        // does not: getQuerySet() treats every parameter without an explicit
        // 'required' key as mandatory (RouteCollection.php:251) and throws,
        // which surfaces as a 500 rather than a usable answer.
        $query = [
            'domain_id' => ['type' => 'integer', 'required' => false, 'default' => 0],
            'clang_id' => ['type' => 'integer', 'required' => false, 'default' => 0],
        ];

        RouteCollection::registerRoute(
            'domain-settings/read',
            new Route(
                'domain-settings',
                ['_controller' => self::class . '::handleAll', 'query' => $query],
                [], [], '', [], ['GET'],
            ),
            'Read all global values for a domain and language, fallback applied',
            null,
            new BearerAuth(),
        );

        // One route - and therefore one scope - per section, so access can be
        // granted per area instead of all or nothing.
        foreach (Backend::getAllSections() as $table => $label) {
            $slug = Backend::sectionSlug($table);

            RouteCollection::registerRoute(
                'domain-settings/read/' . $slug,
                new Route(
                    'domain-settings/' . $slug,
                    ['_controller' => self::class . '::handleSection', 'query' => $query, 'section' => $table],
                    [], [], '', [], ['GET'],
                ),
                sprintf('Read global values of section "%s", any domain and language, fallback applied', $label),
                null,
                new BearerAuth(),
            );

            // Writing is its own scope: a token that may read must not be able
            // to change anything by accident. The body schema is built from
            // the fields that actually exist, so the OpenAPI spec lists them
            // and anything else is rejected.
            $body = [];
            foreach (Backend::getFieldNames($table) as $name) {
                $body[$name] = ['type' => 'string', 'required' => false];
            }

            RouteCollection::registerRoute(
                'domain-settings/write/' . $slug,
                new Route(
                    'domain-settings/' . $slug,
                    ['_controller' => self::class . '::handleWrite', 'query' => $query, 'Body' => $body, 'section' => $table],
                    [], [], '', [], ['PATCH'],
                ),
                sprintf('Update global values of section "%s", for any domain and language', $label),
                null,
                new BearerAuth(),
            );
        }
    }

    /** @param array<string, mixed> $Parameter */
    public static function handleAll($Parameter): Response
    {
        [$domainId, $clangId, $error] = self::resolveContext($Parameter);

        if (null !== $error) {
            return $error;
        }

        return new JsonResponse([
            'data' => DomainSettings::getAll($domainId, $clangId),
            'meta' => ['domain_id' => $domainId, 'clang_id' => $clangId],
        ]);
    }

    /** @param array<string, mixed> $Parameter */
    public static function handleSection($Parameter): Response
    {
        [$domainId, $clangId, $error] = self::resolveContext($Parameter);

        if (null !== $error) {
            return $error;
        }

        $table = rex_type::string($Parameter['section'] ?? '');
        $values = DomainSettings::getSectionValues($table, $domainId, $clangId);
        $names = Backend::getFieldNames($table);

        return new JsonResponse([
            'data' => array_intersect_key($values, array_flip($names)),
            'meta' => ['domain_id' => $domainId, 'clang_id' => $clangId, 'section' => Backend::sectionSlug($table)],
        ]);
    }

    /**
     * Updates the values of one section for a domain and language.
     *
     * Only fields declared on the route are written, and only those actually
     * present in the body - so a PATCH with one field leaves the rest alone.
     *
     * Saving goes through the dataset, so YForm's validators run and its data
     * events fire (which is what drops the value cache).
     *
     * @param array<string, mixed> $Parameter
     */
    public static function handleWrite($Parameter): Response
    {
        [$domainId, $clangId, $error] = self::resolveContext($Parameter);

        if (null !== $error) {
            return $error;
        }

        $table = rex_type::string($Parameter['section'] ?? '');
        $payload = json_decode((string) rex::getRequest()->getContent(), true);

        if (!is_array($payload) || [] === $payload) {
            return new JsonResponse(['error' => 'Body must be a non-empty JSON object'], 400);
        }

        $allowed = Backend::getFieldNames($table);
        $values = array_intersect_key($payload, array_flip($allowed));

        if ([] === $values) {
            return new JsonResponse([
                'error' => 'No known field in body',
                'known_fields' => $allowed,
            ], 400);
        }

        $dataset = Backend::getDataset($table, ['domain_id' => $domainId, 'clang_id' => $clangId]);

        foreach ($values as $column => $value) {
            $dataset->setValue((string) $column, is_scalar($value) ? (string) $value : '');
        }

        if (!$dataset->save()) {
            return new JsonResponse([
                'error' => 'Validation failed',
                'messages' => $dataset->getMessages(),
            ], 422);
        }

        return new JsonResponse([
            'data' => array_intersect_key(DomainSettings::getSectionValues($table, $domainId, $clangId), array_flip($allowed)),
            'meta' => [
                'domain_id' => $domainId,
                'clang_id' => $clangId,
                'section' => Backend::sectionSlug($table),
                'updated' => array_keys($values),
            ],
        ]);
    }

    /**
     * Reads domain and language from the query, falling back to the defaults.
     *
     * @param array<string, mixed> $Parameter
     *
     * @return array{int, int, JsonResponse|null}
     */
    private static function resolveContext($Parameter): array
    {
        // The query string, nothing else. $_REQUEST would also carry cookies
        // depending on request_order, and the request body would arrive here
        // too - Symfony parses a PATCH body into the request bag when the
        // content type is missing. Both routes declare domain_id and clang_id
        // as query parameters; the body carries the values to write.
        $query = RouteCollection::getQuerySet(
            rex::getRequest()->query->all(),
            $Parameter['query'],
        );

        if (!is_array($query)) {
            $query = [];
        }

        $domainIdValue = $query['domain_id'] ?? 0;
        $clangIdValue = $query['clang_id'] ?? 0;
        $domainId = is_scalar($domainIdValue) ? (int) $domainIdValue : 0;
        $clangId = is_scalar($clangIdValue) ? (int) $clangIdValue : 0;

        // The route declares domain_id as optional with a default of 0, so 0
        // has to mean "the first domain", not "unknown" - getAllDomains()
        // drops the id 0 as soon as a real yrewrite domain exists, and every
        // parameterless call would answer 400.
        $domains = Backend::getAllDomains();

        if (0 === $domainId) {
            $domainId = (int) array_key_first($domains);
        } elseif (!isset($domains[$domainId])) {
            // Without this a write request could create rows for domains that
            // do not exist, and every one of them would end up in the value
            // cache the frontend reads.
            return [0, 0, new JsonResponse(['error' => 'Unknown domain_id'], 400)];
        }

        if (0 === $clangId) {
            // The domain's fallback, not the site's start language: a domain
            // may not serve that language at all.
            $clangId = DomainSettings::getFallbackClangId($domainId);
        } elseif (!rex_clang::exists($clangId)) {
            return [0, 0, new JsonResponse(['error' => 'Unknown clang_id'], 400)];
        }

        return [$domainId, $clangId, null];
    }
}
