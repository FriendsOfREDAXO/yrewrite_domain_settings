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
use rex_yform_manager_table;
use rex_yrewrite;

use function in_array;
use function is_array;

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
            return self::$cache = $cached;
        }

        $data = self::build();
        rex_file::putCache(self::getCacheFile(), $data);

        return self::$cache = $data;
    }

    /**
     * Reads the table in a single query and indexes it by domain and language.
     *
     * @return array<int, array<int, array<string, string>>>
     */
    private static function build(): array
    {
        $data = [];
        $seen = [];
        $duplicates = [];

        foreach (array_keys(Backend::getAllSections()) as $table) {
            foreach (rex_sql::factory()->getArray('SELECT * FROM ' . $table) as $row) {
                $domainId = (int) $row['domain_id'];
                $clangId = (int) $row['clang_id'];
                unset($row['id'], $row['domain_id'], $row['clang_id']);

                foreach ($row as $key => $value) {
                    if (isset($seen[$key]) && $seen[$key] !== $table) {
                        $duplicates[$key] = true;
                    }
                    $seen[$key] = $table;
                }

                $data[$domainId][$clangId] = ($data[$domainId][$clangId] ?? []) + $row;
            }
        }

        if ([] !== $duplicates) {
            // Sections share one key namespace, so the same field name in two
            // sections would resolve unpredictably. Worth noticing rather than
            // silently picking one.
            rex_logger::factory()->warning(
                'domain_settings: field name(s) used in more than one section: ' . implode(', ', array_keys($duplicates)),
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
