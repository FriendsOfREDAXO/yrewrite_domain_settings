<?php

namespace FriendsOfRedaxo\DomainSettings;

use rex;
use rex_addon;
use rex_article;
use rex_clang;
use rex_sql;
use rex_yform_manager_dataset;
use rex_yform_manager_table;
use rex_yrewrite;

use function in_array;
use function is_scalar;

/**
 * Reads global values.
 *
 * Values live in one table with a row per domain and language. Reading falls
 * back from the requested language to the configured fallback language, so a
 * value only has to be maintained where it actually differs - which covers
 * both "not translated yet" and "identical in every language" with the same
 * mechanism.
 *
 * Values of a tab that is not assigned to a domain are left out: the
 * assignment decides where a tab exists, in the backend and in the frontend
 * alike. The rows themselves stay untouched, so assigning the domain again
 * brings them back.
 *
 * Read straight from the tables, like YForm reads everything else - there is
 * no cache file. Values are held for the current request only, per domain and
 * language, so a template asking for twenty keys still queries once. A file
 * cache would buy a fraction of a millisecond (0.016 ms against 0.11 ms
 * measured over four tabs) and cost what file caches cost: it grows with the
 * whole installation although a request only ever needs one domain, and it
 * goes stale on schema changes YForm has no event for.
 */
final class DomainSettings
{
    public const ADDON = 'yrewrite_domain_settings';

    /**
     * Values already read in this request.
     *
     * @var array<int, array<int, array<string, string>>> domain => clang => key => value
     */
    private static array $loaded = [];

    /**
     * Returns a single value, or $default when it is not set anywhere.
     *
     * In debug mode an unset key returns a visible `{{ key }}` placeholder
     * instead of null, so a typo shows up while building rather than silently
     * emptying the frontend.
     */
    public static function get(string $key, mixed $default = null, ?int $domainId = null, ?int $clangId = null): mixed
    {
        $domainId ??= self::getCurrentDomainId();
        $clangId ??= rex_clang::getCurrentId();

        $value = self::valuesFor($domainId, $clangId)[$key] ?? null;
        if (!self::isEmpty($value)) {
            return $value;
        }

        $fallbackClangId = self::getFallbackClangId($domainId);
        if ($fallbackClangId !== $clangId) {
            $value = self::valuesFor($domainId, $fallbackClangId)[$key] ?? null;
            if (!self::isEmpty($value)) {
                return $value;
            }
        }

        if (null === $default && rex::isDebugMode()) {
            return '{{ ' . $key . ' }}';
        }

        return $default;
    }

    /**
     * Returns every value for the given domain and language, fallback applied.
     *
     * @return array<string, string>
     */
    public static function getAll(?int $domainId = null, ?int $clangId = null): array
    {
        $domainId ??= self::getCurrentDomainId();
        $clangId ??= rex_clang::getCurrentId();

        $values = [];

        foreach (self::valuesFor($domainId, self::getFallbackClangId($domainId)) as $key => $value) {
            if (!self::isEmpty($value)) {
                $values[$key] = $value;
            }
        }

        foreach (self::valuesFor($domainId, $clangId) as $key => $value) {
            if (!self::isEmpty($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * The language a value falls back to when it is not filled in.
     *
     * With a domain given, the answer can differ per domain: yrewrite lets
     * every domain run its own set of languages, so one may serve German and
     * English while another adds French. Falling back to a language a domain
     * does not serve would inherit from something that is never delivered
     * there, so in that case the domain's own start language takes over.
     */
    public static function getFallbackClangId(?int $domainId = null): int
    {
        $configured = self::getConfiguredFallbackClangId();

        if (null === $domainId) {
            return $configured;
        }

        $available = Backend::getDomainClangIds($domainId);

        if ([] === $available || in_array($configured, $available, true)) {
            return $configured;
        }

        $startClang = Backend::getDomainStartClangId($domainId);

        return null !== $startClang && in_array($startClang, $available, true)
            ? $startClang
            : $available[0];
    }

    /**
     * The stored rows of a table for one domain, indexed by language.
     *
     * One query for all requested languages - the query builder turns the
     * array into an IN(). Shared by the three callers that need the raw rows:
     * the section reader below, the inherited-values hint in the backend, and
     * the compatibility layer.
     *
     * @internal
     *
     * @param list<int> $clangIds
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rowsByClang(string $table, int $domainId, array $clangIds): array
    {
        if ([] === $clangIds) {
            // where('clang_id', []) builds `IN ()`, which is a syntax error
            // rather than an empty result.
            return [];
        }

        $rows = [];

        $datasets = rex_yform_manager_dataset::query($table)
            ->where('domain_id', $domainId)
            ->where('clang_id', array_values(array_unique($clangIds)))
            ->find();

        foreach ($datasets as $dataset) {
            if (!$dataset instanceof rex_yform_manager_dataset) {
                continue;
            }

            // The driver hands columns back as int or as string depending on
            // its configuration, so cast rather than assume either.
            $clangValue = $dataset->getValue('clang_id');

            if (!is_scalar($clangValue)) {
                continue;
            }

            $rows[(int) $clangValue] = $dataset->getData();
        }

        return $rows;
    }

    /**
     * Every value of a single section, fallback applied.
     *
     * Not served from the value cache: that one merges all sections into one
     * key namespace, so a field name used in two sections would resolve to the
     * wrong section's value here. Callers that address one section - the REST
     * routes - have to get exactly that section.
     *
     * Empty for a domain the section is not assigned to, so a client reading
     * one section sees what the frontend sees.
     *
     * @return array<string, string>
     */
    public static function getSectionValues(string $table, ?int $domainId = null, ?int $clangId = null): array
    {
        $domainId ??= self::getCurrentDomainId();
        $clangId ??= rex_clang::getCurrentId();

        // Same rule as the cache: a tab not offered on this domain holds no
        // values for it, however many rows are still sitting in its table.
        if (!Backend::isSectionVisibleForDomain($table, $domainId)) {
            return [];
        }

        $fallbackClangId = self::getFallbackClangId($domainId);

        $byClang = self::rowsByClang($table, $domainId, [$clangId, $fallbackClangId]);

        $values = [];
        foreach ([$fallbackClangId, $clangId] as $id) {
            foreach ($byClang[$id] ?? [] as $key => $value) {
                if (self::isEmpty($value) || !is_scalar($value)) {
                    continue;
                }

                $values[$key] = (string) $value;
            }
        }

        unset($values['id'], $values['domain_id'], $values['clang_id']);

        return $values;
    }

    /** The fallback configured on the settings page, ignoring any domain. */
    public static function getConfiguredFallbackClangId(): int
    {
        $configured = rex_addon::get(self::ADDON)->getConfig('fallback_clang_id');

        if (is_numeric($configured) && rex_clang::exists((int) $configured)) {
            return (int) $configured;
        }

        return rex_clang::getStartId();
    }

    /**
     * The domain the current request belongs to, or 0 without yrewrite.
     *
     * Domain 0 means "no yrewrite domain": yrewrite builds its implicit
     * `default` domain without an id (see rex_yrewrite::init(), the id is the
     * 13th constructor argument and is omitted there), so it casts to 0 and
     * never collides with a real domain from the database.
     *
     * The article guard is not cosmetic: getCurrentDomain() calls
     * rex_article::getCurrent()->getId() without a null check, and getCurrent()
     * returns null whenever article 1 does not exist - in the console, in a
     * cronjob, or on an instance that has no content yet.
     */
    public static function getCurrentDomainId(): int
    {
        if (!rex_addon::get('yrewrite')->isAvailable()) {
            return 0;
        }

        if (null === rex_article::getCurrent()) {
            return 0;
        }

        $domain = rex_yrewrite::getCurrentDomain();

        return null === $domain ? 0 : (int) $domain->getId();
    }

    /**
     * Whether a media file is referenced by any global value.
     *
     * Hooked into MEDIA_IS_IN_USE so the media pool warns instead of silently
     * dropping a logo that is still on the site. Only media fields are
     * inspected, not every text column, so a filename mentioned inside a
     * footer text does not raise a false alarm.
     *
     * Reads the tables rather than the value cache, on purpose: the cache
     * leaves out tabs that are not assigned to a domain, and a file used only
     * there would look free to delete - until the domain is assigned again and
     * the value points at nothing. "In use" here means "stored anywhere", not
     * "delivered right now".
     */
    public static function isMediaInUse(string $filename): bool
    {
        if ('' === $filename) {
            return false;
        }

        foreach (self::getMediaFieldsByTable() as $table => $fields) {
            $sql = rex_sql::factory();

            $columns = [];
            foreach ($fields as $name) {
                $columns[] = $sql->escapeIdentifier($name);
            }

            $rows = $sql->getArray(
                'SELECT ' . implode(', ', $columns) . ' FROM ' . $sql->escapeIdentifier($table),
            );

            foreach ($rows as $row) {
                foreach ($row as $value) {
                    if (!is_scalar($value) || '' === (string) $value) {
                        continue;
                    }

                    // be_medialist stores several filenames separated by commas.
                    if (in_array($filename, array_map('trim', explode(',', (string) $value)), true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Drops what this request has read so far.
     *
     * There is no cache file any more, so this only matters within one
     * request: after a save, the next read has to see the new rows. Kept
     * public and kept being called from the write paths for exactly that -
     * and because project code may call it.
     */
    public static function deleteCache(): void
    {
        self::$loaded = [];
    }

    /**
     * Media field names per section table, sections without one left out.
     *
     * @return array<string, list<string>>
     */
    private static function getMediaFieldsByTable(): array
    {
        $fields = [];

        foreach (array_keys(Backend::getAllSections()) as $tableName) {
            $table = rex_yform_manager_table::get($tableName);
            if (null === $table) {
                continue;
            }

            $names = [];

            foreach ($table->getValueFields() as $field) {
                if (in_array($field->getTypeName(), ['be_media', 'be_medialist'], true)) {
                    $names[] = (string) $field->getName();
                }
            }

            if ([] !== $names) {
                $fields[$tableName] = $names;
            }
        }

        return $fields;
    }

    /**
     * Every value of one domain and language, tab by tab.
     *
     * Read once per request and held afterwards: a template asking for twenty
     * keys must not query twenty times. Deliberately not written to disk -
     * see the class comment.
     *
     * Values are cast to string on the way out. YForm creates `integer` and
     * `number` columns as nullable, so the raw rows would carry nulls into
     * every caller that expects a string.
     *
     * @return array<string, string>
     */
    private static function valuesFor(int $domainId, int $clangId): array
    {
        if (isset(self::$loaded[$domainId][$clangId])) {
            return self::$loaded[$domainId][$clangId];
        }

        $values = [];
        $sql = rex_sql::factory();

        foreach (array_keys(Backend::getAllSections()) as $table) {
            // A tab that is not offered on this domain does not answer for it
            // either: the rows stay in the table, out of every read, and come
            // back the moment the domain is assigned again.
            if (!Backend::isSectionVisibleForDomain($table, $domainId)) {
                continue;
            }

            $rows = $sql->getArray(
                'SELECT * FROM ' . $sql->escapeIdentifier($table) . ' WHERE domain_id = :domain AND clang_id = :clang',
                ['domain' => $domainId, 'clang' => $clangId],
            );

            foreach ($rows as $row) {
                unset($row['id'], $row['domain_id'], $row['clang_id']);

                foreach ($row as $key => $value) {
                    // A filled value beats an empty one from another tab,
                    // whichever was read first. Opening a tab writes an empty
                    // row even where that tab is not used, and without this
                    // such a placeholder would shadow real content further
                    // down the list of tabs.
                    if (!isset($values[$key]) || self::isEmpty($values[$key])) {
                        $values[(string) $key] = (string) $value;
                    }
                }
            }
        }

        return self::$loaded[$domainId][$clangId] = $values;
    }

    /**
     * A missing value is an empty string or null - but not "0", which is how
     * an unchecked checkbox is stored. Treating that as empty would make it
     * impossible to switch an option off in a single language.
     */
    private static function isEmpty(mixed $value): bool
    {
        return null === $value || '' === $value;
    }
}
