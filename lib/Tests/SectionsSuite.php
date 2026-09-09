<?php

namespace FriendsOfRedaxo\DomainSettings\Tests;

use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;
use FriendsOfRedaxo\DomainSettings\Test\AbstractSuite;
use FriendsOfRedaxo\DomainSettings\Test\Assert;
use FriendsOfRedaxo\DomainSettings\Test\Fixtures;
use rex;
use rex_sql;
use rex_sql_column;
use rex_sql_table;
use rex_yform_manager_table;
use rex_yform_manager_table_api;

use function array_key_exists;

/** Creating, renaming, deleting and recognising sections. */
class SectionsSuite extends AbstractSuite
{
    public function testTestSectionIsRecognised(): void
    {
        $sections = Backend::getAllSections();
        Assert::hasKey($this->fixtures->table(), $sections, 'test section not recognised');
        Assert::hasKey(rex::getTable(DomainSettings::ADDON), $sections, 'main section not recognised');
    }

    public function testSlugRoundTrip(): void
    {
        $table = $this->fixtures->table();
        $slug = Backend::sectionSlug($table);

        Assert::same(Fixtures::SECTION_SUFFIX, $slug);
        Assert::same($table, Backend::sectionBySlug($slug));
        Assert::same('main', Backend::sectionSlug(rex::getTable(DomainSettings::ADDON)));
        Assert::null(Backend::sectionBySlug('does-not-exist'));
    }

    /**
     * Renaming changes the label only. The table name carries the permissions
     * and the page key, so it has to stay put.
     */
    public function testRenameKeepsTableAndSlug(): void
    {
        $table = $this->fixtures->table();

        Assert::true(Backend::renameSection($table, 'Umbenannt'));
        Assert::same('Umbenannt', Backend::getAllSections()[$table] ?? null);
        Assert::same(Fixtures::SECTION_SUFFIX, Backend::sectionSlug($table), 'slug must not change');

        Backend::renameSection($table, 'Selftest');
    }

    public function testRenameRejectsEmptyLabelAndUnknownTable(): void
    {
        Assert::false(Backend::renameSection($this->fixtures->table(), '  '));
        Assert::false(Backend::renameSection('rex_article', 'Nope'));
    }

    /** Deleting one section must leave the others alone. */
    public function testDeleteHitsExactlyOneTable(): void
    {
        $before = array_keys(Backend::getAllSections());
        $extra = Backend::createSection('Selftest Wegwerf');

        Assert::false(null === $extra, 'could not create the second test section');
        Assert::true(Backend::deleteSection((string) $extra));

        $after = array_keys(Backend::getAllSections());
        sort($before);
        sort($after);
        Assert::same($before, $after, 'deleting took other sections with it');

        $exists = rex_sql::factory()->getArray('SHOW TABLES LIKE ' . rex_sql::factory()->escape((string) $extra));
        Assert::same([], $exists, 'table was not dropped');
        Assert::null(rex_yform_manager_table::get((string) $extra), 'YForm registration was not removed');
    }

    public function testDuplicateSectionIsRefused(): void
    {
        Assert::null(Backend::createSection('Selftest'), 'an existing section must not be created twice');
        Assert::null(Backend::createSection('   '), 'an empty label must be refused');
    }

    /** Sections share one key namespace, so a value is reachable either way. */
    public function testValuesAreReachableAcrossSections(): void
    {
        $clangId = $this->fixtures->fallbackClangId();
        $this->fixtures->setValues($clangId, ['selftest_text' => 'aus dem Testbereich']);

        Assert::same('aus dem Testbereich', DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId));
        Assert::hasKey('selftest_text', DomainSettings::getAll(Fixtures::DOMAIN_ID, $clangId));
    }

    /**
     * The reserved page keys stay reserved.
     *
     * `main` belongs to the base table, `settings` and `help` to the static
     * subpages - a section on one of those keys would take over the page or
     * make the other section unreachable. The list is a copy of what
     * package.yml declares, so it can drift; this is what notices that.
     */
    public function testReservedSlugsAreRefused(): void
    {
        foreach (['Main', 'Settings', 'Help'] as $label) {
            Assert::null(
                Backend::createSection($label),
                'a section called "' . $label . '" must be refused',
            );
        }
    }

    /**
     * A reserved key made by hand stays out of the navigation.
     *
     * createSection() refuses those labels, but the YForm table manager takes
     * the same route - and the addon documents sections as ordinary YForm
     * tables. What must not happen is that such a table takes over the admin
     * page; what must still happen is that it can be found and deleted.
     */
    public function testReservedSlugMadeByHandIsKeptOutOfTheNavigation(): void
    {
        $table = rex::getTable(DomainSettings::ADDON) . '_settings';

        if (null !== rex_yform_manager_table::get($table)) {
            Assert::skip('a section with the reserved key `settings` already exists here');
        }

        rex_sql_table::get($table)
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new rex_sql_column('domain_id', 'int(10) unsigned', false, '0'))
            ->ensureColumn(new rex_sql_column('clang_id', 'int(10) unsigned', false, '0'))
            ->ensure();
        rex_yform_manager_table_api::setTable(['table_name' => $table, 'name' => 'Reserved', 'hidden' => 1]);
        Backend::resetCaches();

        try {
            Assert::true(
                array_key_exists($table, Backend::getAllSections()),
                'it stays a section, so it can still be read and deleted',
            );
            Assert::false(
                array_key_exists($table, Backend::getSections()),
                'but it must not reach the navigation',
            );
        } finally {
            rex_yform_manager_table_api::removeTable($table);
            rex_sql_table::get($table)->drop();
            Backend::resetCaches();
        }
    }
}
