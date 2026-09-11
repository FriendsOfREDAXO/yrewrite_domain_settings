<?php

namespace FriendsOfRedaxo\DomainSettings\Tests;

use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;
use FriendsOfRedaxo\DomainSettings\Test\AbstractSuite;
use FriendsOfRedaxo\DomainSettings\Test\Assert;
use rex;
use rex_config;
use rex_sql;
use rex_var;

/**
 * Regressions for the holes a review found - they must not come back.
 */
class SecuritySuite extends AbstractSuite
{
    /**
     * The table name reaches deleteSection() from a request, and dropping
     * whatever it names would happily take rex_article with it.
     */
    public function testDeleteRefusesForeignTables(): void
    {
        Assert::false(Backend::isSectionDeletable('rex_article'), 'rex_article must not be deletable');
        Assert::false(Backend::deleteSection('rex_article'), 'deleteSection must refuse a foreign table');

        $exists = rex_sql::factory()->getArray("SHOW TABLES LIKE 'rex_article'");
        Assert::false([] === $exists, 'rex_article must still exist');
    }

    /** The addon would lose its storage; the editing page falls back to it. */
    public function testMainSectionCannotBeDeleted(): void
    {
        Assert::false(Backend::isSectionDeletable(rex::getTable('yrewrite_domain_settings')));
        Assert::false(Backend::deleteSection(rex::getTable('yrewrite_domain_settings')));
    }

    /**
     * REX_DOMAIN_VALUE escapes by default. An editor holding only
     * yrewrite_domain_settings[] must not be able to place markup on every
     * page that reads the value.
     */
    public function testRexVarEscapesByDefault(): void
    {
        $output = rex_var::parse('REX_DOMAIN_VALUE[key="selftest_text"]', rex_var::ENV_FRONTEND);
        Assert::contains('rex_escape(', $output, 'REX_DOMAIN_VALUE must escape by default');
    }

    public function testRexVarAllowsExplicitHtmlOptOut(): void
    {
        $output = rex_var::parse('REX_DOMAIN_VALUE[key="selftest_text" output="html"]', rex_var::ENV_FRONTEND);
        Assert::false(str_contains($output, 'rex_escape('), 'output="html" must return the raw value');
    }

    /** Only exactly "html" opts out - no accidental loosening. */
    public function testRexVarOnlyExactHtmlOptsOut(): void
    {
        foreach (['HTML', 'raw', ''] as $value) {
            $output = rex_var::parse('REX_DOMAIN_VALUE[key="selftest_text" output="' . $value . '"]', rex_var::ENV_FRONTEND);
            Assert::contains('rex_escape(', $output, 'output="' . $value . '" must still escape');
        }
    }

    /**
     * A user without domain permissions gets an empty list - not everything.
     *
     * The editing page turns an empty list into a stop; what matters here is
     * that the list is empty in the first place. It used to return every
     * domain when no user was set, which made the filter a no-op outside the
     * backend.
     */
    public function testDomainListDoesNotFallOpenWithoutUser(): void
    {
        $user = rex::getUser();
        rex::setProperty('user', null);

        try {
            Assert::same([], Backend::getDomains(), 'no user must mean no domains');
            Assert::same([], Backend::getSections(), 'no user must mean no sections');
        } finally {
            rex::setProperty('user', $user);
        }
    }

    /**
     * An unknown table is nowhere visible, not everywhere.
     *
     * It has no assignment, and an empty assignment means "every domain" - so
     * without the guard a foreign table would read as in scope and be passed
     * on to the query behind it.
     */
    public function testVisibilityRefusesForeignTables(): void
    {
        Assert::false(Backend::isSectionVisibleForDomain('rex_article', 1));
        Assert::false(Backend::isSectionVisibleForDomain('does_not_exist', 1));
    }

    /**
     * A list of ids the user may not use must leave the assignment alone.
     *
     * setSectionDomains() drops ids the user has no permission for, and an
     * empty list lifts the limit. Both are right on their own; together they
     * would turn "assign it to domains I cannot see" into "assign it to all of
     * them".
     */
    public function testSetSectionDomainsDoesNotWidenOnAnUnusableList(): void
    {
        $table = $this->fixtures->table();
        $key = 'section_domains';

        $had = rex_config::has(DomainSettings::ADDON, $key);
        /** @var array<string, list<int>> $backup */
        $backup = (array) rex_config::get(DomainSettings::ADDON, $key, []);

        try {
            // Written past setSectionDomains() on purpose: the starting point
            // has to exist whether or not the installation has two domains.
            $config = $backup;
            $config[$table] = [999998];
            rex_config::set(DomainSettings::ADDON, $key, $config);
            Backend::resetCaches();

            $this->fixtures->withAdminUser(static function () use ($table): void {
                Backend::setSectionDomains($table, [999999]);
            });

            Assert::same(
                [999998],
                Backend::getSectionDomainIds($table),
                'an assignment that survives the permission filter empty must not lift the limit',
            );
        } finally {
            if ($had) {
                rex_config::set(DomainSettings::ADDON, $key, $backup);
            } else {
                rex_config::remove(DomainSettings::ADDON, $key);
            }

            Backend::resetCaches();
            DomainSettings::deleteCache();
        }
    }
}
