<?php

namespace FriendsOfRedaxo\DomainSettings\Tests;

use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;
use FriendsOfRedaxo\DomainSettings\Test\AbstractSuite;
use FriendsOfRedaxo\DomainSettings\Test\Assert;
use FriendsOfRedaxo\DomainSettings\Test\Fixtures;
use rex;
use rex_extension;
use rex_extension_point;
use rex_path;
use rex_sql;
use rex_yform_manager_table;
use rex_yform_manager_table_api;

use function in_array;

/**
 * How values are read: from the tables, held for the request only.
 *
 * There is no cache file - values come out of the tables the way YForm reads
 * everything else. What is read once is kept for the rest of the request, so
 * a template asking for twenty keys queries once; that memo is dropped
 * whenever something is saved.
 */
class ReadingSuite extends AbstractSuite
{
    public function getTitle(): string
    {
        return 'Reading';
    }

    /** No file is written, on the first read or on any later one. */
    public function testNothingIsWrittenToDisk(): void
    {
        $clangId = $this->fixtures->fallbackClangId();
        $this->fixtures->setValues($clangId, ['selftest_text' => 'ohne datei']);

        DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId);
        DomainSettings::getAll(Fixtures::DOMAIN_ID, $clangId);

        Assert::false(
            file_exists(rex_path::addonCache(DomainSettings::ADDON, 'values.json')),
            'the old cache file must not come back',
        );
    }

    /**
     * Read once, then held: changing the row behind its back stays invisible
     * until something drops the memo. That is what keeps twenty REX_DOMAIN_VALUE
     * in one template at one query.
     */
    public function testValuesAreHeldForTheRequest(): void
    {
        $clangId = $this->fixtures->fallbackClangId();
        $this->fixtures->setValues($clangId, ['selftest_text' => 'gelesen']);

        Assert::same('gelesen', DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId));

        $this->writeBehindItsBack($clangId, 'am lesepfad vorbei');

        Assert::same(
            'gelesen',
            DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId),
            'the second read must not query again',
        );

        DomainSettings::deleteCache();

        Assert::same(
            'am lesepfad vorbei',
            DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId),
            'and after dropping the memo it must',
        );
    }

    /** Saving through the dataset fires YFORM_DATA_UPDATED, which drops the memo. */
    public function testSavingIsVisibleInTheSameRequest(): void
    {
        $clangId = $this->fixtures->fallbackClangId();
        $this->fixtures->setValues($clangId, ['selftest_text' => 'alt']);

        Assert::same('alt', DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId));

        $dataset = Backend::getDataset($this->fixtures->table(), [
            'domain_id' => Fixtures::DOMAIN_ID,
            'clang_id' => $clangId,
        ]);
        $dataset->setValue('selftest_text', 'neu');
        $dataset->save();

        Assert::same(
            'neu',
            DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId),
            'a save has to be visible without clearing anything',
        );
    }

    /** Saving an unrelated YForm table must not throw the memo away. */
    public function testForeignTableDoesNotDropTheMemo(): void
    {
        $clangId = $this->fixtures->fallbackClangId();
        $this->fixtures->setValues($clangId, ['selftest_text' => 'unberuehrt']);
        DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId);

        $foreign = null;
        foreach (rex_yform_manager_table::getAll() as $table) {
            if (!str_starts_with($table->getTableName(), rex::getTable(DomainSettings::ADDON))) {
                $foreign = $table;
                break;
            }
        }

        if (null === $foreign) {
            Assert::skip('no YForm table outside this addon to fire the event with');
        }

        $this->writeBehindItsBack($clangId, 'haette nicht gelesen werden duerfen');

        rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_UPDATED', null, [
            'table' => $foreign,
        ]));

        Assert::same(
            'unberuehrt',
            DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId),
            'a foreign table is none of our business',
        );
    }

    /**
     * A new field answers right away - no cache to clear first.
     *
     * This is what the cache file cost: YForm has no event for schema changes,
     * so a column added or removed in the table manager stayed invisible until
     * someone ran cache:clear.
     */
    public function testANewFieldAnswersWithoutClearingAnything(): void
    {
        $clangId = $this->fixtures->fallbackClangId();
        $table = $this->fixtures->table();

        // Warm the memo, so a stale read would be the likely outcome.
        DomainSettings::getAll(Fixtures::DOMAIN_ID, $clangId);

        rex_yform_manager_table_api::setTableField($table, [
            'prio' => 99,
            'type_id' => 'value',
            'type_name' => 'text',
            'name' => 'selftest_spaet',
            'label' => 'selftest_spaet',
            'list_hidden' => 0,
            'search' => 0,
        ]);

        $managerTable = rex_yform_manager_table::get($table);
        Assert::true(null !== $managerTable, 'precondition: the fixture table is there');
        rex_yform_manager_table_api::generateTableAndFields($managerTable);
        Backend::resetCaches();

        $sql = rex_sql::factory();
        $sql->setQuery(
            'UPDATE ' . $sql->escapeIdentifier($table) . ' SET selftest_spaet = :v WHERE domain_id = :d AND clang_id = :c',
            ['v' => 'spaet dazu', 'd' => Fixtures::DOMAIN_ID, 'c' => $clangId],
        );

        // Only what a save would do anyway - not cache:clear.
        DomainSettings::deleteCache();

        Assert::same(
            'spaet dazu',
            DomainSettings::get('selftest_spaet', null, Fixtures::DOMAIN_ID, $clangId),
            'the new column must be readable straight away',
        );
    }

    /**
     * A field name only one tab uses is not reported.
     *
     * The report itself - two tabs, one domain, same name - is checked where
     * that situation is built, in SectionDomainsSuite.
     */
    public function testAUniqueFieldNameIsNotReported(): void
    {
        Assert::false(
            in_array('selftest_text', Backend::getDuplicateFieldNames(), true),
            'the fixture field belongs to one tab only',
        );
    }

    /** Writes straight to the column, bypassing everything that drops the memo. */
    private function writeBehindItsBack(int $clangId, string $value): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'UPDATE ' . $sql->escapeIdentifier($this->fixtures->table())
                . ' SET selftest_text = :v WHERE domain_id = :d AND clang_id = :c',
            ['v' => $value, 'd' => Fixtures::DOMAIN_ID, 'c' => $clangId],
        );
    }
}
