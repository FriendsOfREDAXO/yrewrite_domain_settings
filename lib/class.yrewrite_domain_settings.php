<?php

use FriendsOfRedaxo\DomainSettings\DomainSettings;

/**
 * The API of this addon up to 2.3.0, kept working.
 *
 * Every method behaves as it did before: same signatures, same return types,
 * same null cases. What changed underneath is that values are now stored per
 * domain *and* language, so a value that is not filled in for the current
 * language falls back to the fallback language. In a single-language
 * installation that is indistinguishable from the old behaviour.
 *
 * Two deliberate differences, both fixes rather than breaks:
 *
 * - The domain is resolved through rex_yrewrite::getCurrentDomain() instead of
 *   getDomainByArticleId(rex_article::getCurrentId()). That is issue #35/#36:
 *   on a domain's own start article the old lookup came back empty, which is
 *   why people had to invent an extra "homepage" category. It also no longer
 *   dies with a fatal error when there is no current article, in the console
 *   for instance.
 * - It is not cached in a singleton any more, so the value does not go stale
 *   within one request.
 *
 * @deprecated 2.4.0 use FriendsOfRedaxo\DomainSettings\DomainSettings
 */
class yrewrite_domain_settings
{
    private static ?yrewrite_domain_settings $instance = null;

    private rex_addon $addon;

    private function __construct()
    {
        $this->addon = rex_addon::get('yrewrite_domain_settings');
    }

    public static function getInstance(): yrewrite_domain_settings
    {
        return self::$instance ??= new self();
    }

    /**
     * A single value, or the whole row when no key is given.
     *
     * Passing no key returns the raw row including `id` and `domain_id`, which
     * is what it always did - project code indexes into that array and even
     * joins other tables on the `id`.
     *
     * @return mixed the value, the row as array, or null when nothing is stored
     */
    public static function getValue(string $key = '')
    {
        if ('' !== $key) {
            // Passing null as the default would not help: get() cannot tell an
            // omitted argument from an explicit null, so it would still return
            // the visible `{{ key }}` placeholder in debug mode - and that
            // breaks every caller testing the result with `?:` or `== 0`.
            // A sentinel that cannot occur as a stored value is the only way
            // to ask for "null when unset" without changing the new API.
            $missing = new stdClass();
            $value = DomainSettings::get($key, $missing);

            return $value === $missing ? null : $value;
        }

        $row = self::getRow();

        return [] === $row ? null : $row;
    }

    /**
     * Domains the current user may edit, as `[['domain' => …, 'id' => …], …]`.
     *
     * @return list<array{domain: string, id: string}>
     */
    public static function getAllowedDomains(): array
    {
        $allDomains = rex_yrewrite_domains_select::getDomains();
        $user = rex::getUser();

        if (null === $user) {
            return [];
        }

        if ($user->isAdmin() || rex_complex_perm::ALL === $user->getComplexPerm('yrewrite_domains')->getDomains()) {
            return $allDomains;
        }

        $allowedDomains = $user->getComplexPerm('yrewrite_domains')->getDomains();

        return array_values(array_filter($allDomains, static function ($domain) use ($allowedDomains) {
            return in_array($domain['id'], (array) $allowedDomains, false);
        }));
    }

    /**
     * The stored row for the current domain and language, fallback merged in.
     *
     * Read through the dataset rather than the value cache because the caller
     * expects the structural columns as well, which the cache drops.
     *
     * @return array<string, mixed>
     */
    private static function getRow(): array
    {
        $table = rex_yform_manager_table::get(rex::getTable('yrewrite_domain_settings'));
        if (null === $table) {
            return [];
        }

        $domainId = DomainSettings::getCurrentDomainId();
        $clangId = rex_clang::getCurrentId();
        $fallbackClangId = DomainSettings::getFallbackClangId();

        $row = self::fetchRow($table, $domainId, $clangId);

        if ($clangId === $fallbackClangId) {
            return $row;
        }

        $fallbackRow = self::fetchRow($table, $domainId, $fallbackClangId);

        if ([] === $row) {
            return $fallbackRow;
        }

        foreach ($row as $key => $value) {
            if (null === $value || '' === $value) {
                unset($row[$key]);
            }
        }

        return $row + $fallbackRow;
    }

    /** @return array<string, mixed> */
    private static function fetchRow(rex_yform_manager_table $table, int $domainId, int $clangId): array
    {
        $item = $table->query()
            ->where('domain_id', $domainId)
            ->where('clang_id', $clangId)
            ->findOne();

        return null === $item ? [] : $item->getData();
    }
}
