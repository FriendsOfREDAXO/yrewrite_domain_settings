<?php

namespace FriendsOfRedaxo\DomainSettings;

use rex;
use rex_addon;
use rex_article;
use rex_clang;
use rex_file;
use rex_logger;
use rex_path;
use rex_sql;
use rex_yform_manager_dataset;
use rex_yform_manager_table;
use rex_yrewrite;

use function in_array;
use function is_array;
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
 * The whole set is small enough to keep in a single cache file, which is read
 * lazily on first access. Requests that never ask for a value pay nothing.
 */
final class DomainSettings
{
    public const ADDON = 'yrewrite_domain_settings';
    private const CACHE_FILE = 'values.json';

    /** @var array<int, array<int, array<string, string>>>|null domain => clang => key => value */
    private static ?array $cache = null;

    /** The domain of this request; it cannot change while it is running. */
    private static ?int $currentDomainId = null;

    /**
     * Returns a single value, or $default when it is not set anywhere.
     *
     * In debug mode an unset key returns a visible `{{ key }}` placeholder
     * instead of null, so a typo shows up while building rather than silently
     * emptying the frontend.
     */
    public static function get(string $key, mixed $default = null, ?int $domainId = null, ?int $clangId = null): mixed
    {
        $data = self::load();

        $domainId ??= self::getCurrentDomainId();
        $clangId ??= rex_clang::getCurrentId();

        $value = $data[$domainId][$clangId][$key] ?? null;
        if (!self::isEmpty($value)) {
            return $value;
        }

        $fallbackClangId = self::getFallbackClangId($domainId);
        if ($fallbackClangId !== $clangId) {
            $value = $data[$domainId][$fallbackClangId][$key] ?? null;
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
        $data = self::load();

        $domainId ??= self::getCurrentDomainId();
        $clangId ??= rex_clang::getCurrentId();

        $values = [];

        foreach ($data[$domainId][self::getFallbackClangId($domainId)] ?? [] as $key => $value) {
            if (!self::isEmpty($value)) {
                $values[$key] = $value;
            }
        }

        foreach ($data[$domainId][$clangId] ?? [] as $key => $value) {
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
     * Every value of a single section, fallback applied.
     *
     * Not served from the value cache: that one merges all sections into one
     * key namespace, so a field name used in two sections would resolve to the
     * wrong section's value here. Callers that address one section - the REST
     * routes - have to get exactly that section.
     *
     * @return array<string, string>
     */
    public static function getSectionValues(string $table, ?int $domainId = null, ?int $clangId = null): array
    {
        $domainId ??= self::getCurrentDomainId();
        $clangId ??= rex_clang::getCurrentId();
        $fallbackClangId = self::getFallbackClangId($domainId);

        $byClang = [];
        $datasets = rex_yform_manager_dataset::query($table)
            ->where('domain_id', $domainId)
            ->where('clang_id', array_values(array_unique([$clangId, $fallbackClangId])))
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

            $byClang[(int) $clangValue] = $dataset->getData();
        }

        $values = [];
        foreach ([$fallbackClangId, $clangId] as $id) {
            foreach ($byClang[$id] ?? [] as $key => $value) {
                if (!self::isEmpty($value)) {
                    $values[$key] = (string) $value;
                }
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
        if (null !== self::$currentDomainId) {
            return self::$currentDomainId;
        }

        if (!rex_addon::get('yrewrite')->isAvailable()) {
            return self::$currentDomainId = 0;
        }

        if (null === rex_article::getCurrent()) {
            return self::$currentDomainId = 0;
        }

        $domain = rex_yrewrite::getCurrentDomain();

        return self::$currentDomainId = null === $domain ? 0 : (int) $domain->getId();
    }

    /**
     * Whether a media file is referenced by any global value.
     *
     * Hooked into MEDIA_IS_IN_USE so the media pool warns instead of silently
     * dropping a logo that is still on the site. Only media fields are
     * inspected, not every text column, so a filename mentioned inside a
     * footer text does not raise a false alarm.
     */
    public static function isMediaInUse(string $filename): bool
    {
        if ('' === $filename) {
            return false;
        }

        $fields = self::getMediaFieldNames();
        if ([] === $fields) {
            return false;
        }

        foreach (self::load() as $byClang) {
            foreach ($byClang as $row) {
                foreach ($fields as $name) {
                    $value = $row[$name] ?? '';
                    if ('' === $value) {
                        continue;
                    }
                    // be_medialist stores several filenames separated by commas.
                    if (in_array($filename, array_map('trim', explode(',', $value)), true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function deleteCache(): void
    {
        self::$cache = null;
        self::$currentDomainId = null;
        rex_file::delete(self::getCacheFile());
    }

    /**
     * Names of all media fields.
     *
     * @return list<string>
     */
    private static function getMediaFieldNames(): array
    {
        $names = [];

        foreach (array_keys(Backend::getAllSections()) as $tableName) {
            $table = rex_yform_manager_table::get($tableName);
            if (null === $table) {
                continue;
            }

            foreach ($table->getValueFields() as $field) {
                if (in_array($field->getTypeName(), ['be_media', 'be_medialist'], true)) {
                    $names[] = $field->getName();
                }
            }
        }

        return $names;
    }

    /** @return array<int, array<int, array<string, string>>> */
    private static function load(): array
    {
        if (null !== self::$cache) {
            return self::$cache;
        }

        $cached = rex_file::getCache(self::getCacheFile(), null);
        if (is_array($cached)) {
            return self::$cache = self::normalise($cached);
        }

        $data = self::build();
        rex_file::putCache(self::getCacheFile(), $data);

        return self::$cache = $data;
    }

    /**
     * Brings a decoded cache file back into the documented shape.
     *
     * The file is JSON on disk and can be anything - truncated by a full disk,
     * written by an older version, edited by hand. Claiming the shape without
     * checking would push the problem into every caller.
     *
     * @param array<mixed> $data
     *
     * @return array<int, array<int, array<string, string>>>
     */
    private static function normalise(array $data): array
    {
        $result = [];

        foreach ($data as $domainId => $byClang) {
            if (!is_array($byClang)) {
                continue;
            }

            foreach ($byClang as $clangId => $values) {
                if (!is_array($values)) {
                    continue;
                }

                foreach ($values as $key => $value) {
                    if (null !== $value && !is_scalar($value)) {
                        // Not a value this addon ever wrote - skip it rather
                        // than force it into a string.
                        continue;
                    }

                    $result[(int) $domainId][(int) $clangId][(string) $key] = (string) $value;
                }
            }
        }

        return $result;
    }

    /**
     * Reads every section in one query each and indexes the rows by domain and
     * language.
     *
     * Values are cast to string on the way in. YForm creates `integer` and
     * `number` columns as nullable, so the raw rows would carry nulls into the
     * cache file and from there into every caller that expects a string.
     *
     * @return array<int, array<int, array<string, string>>>
     */
    private static function build(): array
    {
        $data = [];
        $seen = [];
        $duplicates = [];

        foreach (array_keys(Backend::getAllSections()) as $table) {
            $first = true;

            foreach (rex_sql::factory()->getArray('SELECT * FROM ' . $table) as $row) {
                $domainId = (int) $row['domain_id'];
                $clangId = (int) $row['clang_id'];
                unset($row['id'], $row['domain_id'], $row['clang_id']);

                $row = array_map(static fn ($value) => (string) $value, $row);

                if ($first) {
                    // Depends on the columns, not on the rows - once per table
                    // is enough.
                    foreach (array_keys($row) as $key) {
                        if (isset($seen[$key]) && $seen[$key] !== $table) {
                            $duplicates[$key] = true;
                        }
                        $seen[$key] = $table;
                    }
                    $first = false;
                }

                $data[$domainId][$clangId] = ($data[$domainId][$clangId] ?? []) + $row;
            }
        }

        if ([] !== $duplicates) {
            // Sections share one key namespace, so the same field name in two
            // sections would resolve unpredictably. Worth noticing rather than
            // silently picking one.
            rex_logger::factory()->warning(
                'domain_settings: field name(s) used in more than one section: {fields}',
                ['fields' => implode(', ', array_keys($duplicates))],
            );
        }

        return $data;
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

    private static function getCacheFile(): string
    {
        return rex_path::addonCache(self::ADDON, self::CACHE_FILE);
    }
}
