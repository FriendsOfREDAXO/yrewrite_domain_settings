<?php

namespace FriendsOfRedaxo\DomainSettings\Tests;

use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\Test\AbstractSuite;
use FriendsOfRedaxo\DomainSettings\Test\Assert;
use rex;
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
}
