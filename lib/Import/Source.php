<?php

namespace FriendsOfRedaxo\DomainSettings\Import;

use rex;
use rex_addon;
use rex_sql;

use function is_array;
use function is_scalar;

/**
 * Reads what global_settings has stored.
 *
 * Every access to that addon goes through here, so the rest of the import
 * never has to know its table names - and works out of the box on an
 * installation that does not have it at all.
 *
 * @internal
 */
class Source
{
    public const ADDON = 'global_settings';

    /** @var list<SourceField>|null */
    private static ?array $fields = null;

    /**
     * Whether there is anything to import from.
     *
     * Checked rather than assumed: the import page is the only caller, and it
     * must not offer a button that leads into a missing table.
     */
    public static function isAvailable(): bool
    {
        if (!rex_addon::get(self::ADDON)->isAvailable()) {
            return false;
        }

        $sql = rex_sql::factory();

        return [] !== $sql->getArray('SHOW TABLES LIKE ' . $sql->escape(self::fieldTable()));
    }

    /**
     * Every field global_settings knows, in the order it shows them.
     *
     * @return list<SourceField>
     */
    public static function getFields(): array
    {
        if (null !== self::$fields) {
            return self::$fields;
        }

        if (!self::isAvailable()) {
            return self::$fields = [];
        }

        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT name, title, notice, priority, attributes, type_id, `default`, params, callback
             FROM ' . $sql->escapeIdentifier(self::fieldTable()) . '
             ORDER BY priority, id',
        );

        $fields = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['name']) || !is_scalar($row['name'])) {
                continue;
            }

            $fields[] = new SourceField(
                name: (string) $row['name'],
                title: self::str($row, 'title'),
                typeId: (int) self::str($row, 'type_id'),
                notice: self::str($row, 'notice'),
                default: self::str($row, 'default'),
                params: self::str($row, 'params'),
                callback: self::str($row, 'callback'),
                priority: (int) self::str($row, 'priority'),
                attributes: self::str($row, 'attributes'),
            );
        }

        return self::$fields = $fields;
    }

    /**
     * The stored values of one language, keyed by field name.
     *
     * global_settings keeps one row per language in a single wide table, so
     * this is one row - structural columns dropped.
     *
     * @return array<string, string>
     */
    public static function getValues(int $clangId): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT * FROM ' . $sql->escapeIdentifier(self::valueTable()) . ' WHERE clang = :clang LIMIT 1',
            ['clang' => $clangId],
        );

        if ([] === $rows || !is_array($rows[0])) {
            return [];
        }

        $row = $rows[0];
        unset($row['id'], $row['clang']);

        $values = [];

        foreach ($row as $name => $value) {
            if (null === $value || !is_scalar($value)) {
                continue;
            }

            $values[(string) $name] = (string) $value;
        }

        return $values;
    }

    /**
     * The languages global_settings holds a row for.
     *
     * @return list<int>
     */
    public static function getClangIds(): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $sql = rex_sql::factory();
        $rows = $sql->getArray('SELECT clang FROM ' . $sql->escapeIdentifier(self::valueTable()) . ' ORDER BY clang');

        $ids = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['clang']) && is_scalar($row['clang'])) {
                $ids[] = (int) $row['clang'];
            }
        }

        return $ids;
    }

    /** Drops what this request has read - the tests change the source between checks. */
    public static function resetCache(): void
    {
        self::$fields = null;
    }

    public static function fieldTable(): string
    {
        return rex::getTable('global_settings_field');
    }

    public static function valueTable(): string
    {
        return rex::getTable('global_settings');
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
