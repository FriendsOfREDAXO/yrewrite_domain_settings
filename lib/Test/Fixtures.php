<?php

namespace FriendsOfRedaxo\DomainSettings\Test;

use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;
use rex;
use rex_clang;
use rex_sql;
use rex_yform_manager_table;
use rex_yform_manager_table_api;
use RuntimeException;

/**
 * Test data that cannot collide with real content.
 *
 * Two safeguards, both deliberate: everything lives in a section of its own
 * that is created and dropped around the run, and every row uses a domain id
 * far outside anything yrewrite hands out. During development the throwaway
 * scripts wrote to the real table and took editorial data with them twice -
 * that must not happen from a command that ships with the addon.
 */
final class Fixtures
{
    /** Section table used by the tests; created on setUp, dropped on tearDown. */
    public const SECTION_SUFFIX = 'selftest';

    /** Far outside anything yrewrite assigns, so real rows are never touched. */
    public const DOMAIN_ID = 999001;

    private ?string $table = null;

    public function setUp(): void
    {
        $this->tearDown();

        $table = rex::getTable(DomainSettings::ADDON) . '_' . self::SECTION_SUFFIX;
        $created = Backend::createSection('Selftest');

        if (null === $created) {
            throw new RuntimeException('Could not create the test section ' . $table);
        }

        $this->table = $created;

        foreach ([
            ['selftest_text', 'text'],
            ['selftest_flag', 'text'],
        ] as [$name, $type]) {
            rex_yform_manager_table_api::setTableField($created, [
                'prio' => 1,
                'type_id' => 'value',
                'type_name' => $type,
                'name' => $name,
                'label' => $name,
                'list_hidden' => 0,
                'search' => 0,
            ]);
        }

        rex_yform_manager_table::deleteCache();
        rex_yform_manager_table_api::generateTableAndFields(rex_yform_manager_table::get($created));
        rex_yform_manager_table::deleteCache();
        Backend::resetSections();
        DomainSettings::deleteCache();
    }

    public function tearDown(): void
    {
        $table = rex::getTable(DomainSettings::ADDON) . '_' . self::SECTION_SUFFIX;

        if (null !== rex_yform_manager_table::get($table)) {
            rex_yform_manager_table_api::removeTable($table);
            rex_yform_manager_table::deleteCache();
        }

        rex_sql::factory()->setQuery('DROP TABLE IF EXISTS ' . rex_sql::factory()->escapeIdentifier($table));

        $this->table = null;
        Backend::resetSections();
        DomainSettings::deleteCache();
    }

    public function table(): string
    {
        if (null === $this->table) {
            throw new RuntimeException('Fixtures not set up');
        }

        return $this->table;
    }

    public function fallbackClangId(): int
    {
        return DomainSettings::getFallbackClangId();
    }

    /** A language that is not the fallback, or null when only one exists. */
    public function otherClangId(): ?int
    {
        foreach (rex_clang::getAllIds() as $id) {
            if ($id !== $this->fallbackClangId()) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Writes one row of test values.
     *
     * The domain defaults to the unreachable test domain. Only the legacy-API
     * suite passes one, because the old getValue() has no domain parameter and
     * therefore has to be tested against whatever domain the current request
     * resolves to - which is 0 on the console.
     *
     * @param array<string, string> $values
     */
    public function setValues(int $clangId, array $values, ?int $domainId = null): void
    {
        $dataset = Backend::getDataset($this->table(), [
            'domain_id' => $domainId ?? self::DOMAIN_ID,
            'clang_id' => $clangId,
        ]);

        foreach ($values as $key => $value) {
            $dataset->setValue($key, $value);
        }

        $dataset->save();
        DomainSettings::deleteCache();
    }
}
