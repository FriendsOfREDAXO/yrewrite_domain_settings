<?php

namespace FriendsOfRedaxo\DomainSettings\Tests;

use FriendsOfRedaxo\DomainSettings\DomainPerm;
use FriendsOfRedaxo\DomainSettings\DomainSettings;
use FriendsOfRedaxo\DomainSettings\Test\AbstractSuite;
use FriendsOfRedaxo\DomainSettings\Test\Assert;
use ReflectionMethod;
use rex;
use rex_complex_perm;
use rex_sql;
use rex_var;
use rex_yrewrite_domains_perm;
use yrewrite_domain_settings;

use function is_array;

/**
 * The API of 2.3.0 has to keep behaving exactly as it did.
 *
 * Every check here stands for a call pattern found in real projects, not for
 * something the documentation happens to mention:
 *
 * - `(int) getValue('x')`, `getValue('x') ?: ''`, `getValue('x') == 0` - all
 *   of them break the moment an unset key returns the debug placeholder
 *   instead of null.
 * - `getValue()` without a key, indexed into afterwards, and elsewhere joined
 *   on its `id` from a second table.
 * - `REX_DOMAIN_SETTING` in templates that store markup in the value.
 */
final class LegacyApiSuite extends AbstractSuite
{
    private bool $rowWasCreated = false;

    public function setUp(): void
    {
        // The old getValue() has no domain argument: it reads whatever domain
        // the current request resolves to. Asking for that domain instead of
        // assuming 0 keeps the suite honest on instances that have articles.
        $this->fixtures->setValues(
            $this->fixtures->fallbackClangId(),
            ['selftest_text' => 'legacy'],
            DomainSettings::getCurrentDomainId(),
        );
    }

    public function tearDown(): void
    {
        $this->removeCreatedRow();
    }

    public function getTitle(): string
    {
        return 'Legacy API (2.3.0)';
    }

    public function testGetValueWithKeyReturnsTheValue(): void
    {
        Assert::same('legacy', yrewrite_domain_settings::getValue('selftest_text'));
    }

    /**
     * The one that would have bitten hardest: with the placeholder, a
     * `getValue('online') == 0` guard silently flips over.
     */
    public function testGetValueNeverReturnsTheDebugPlaceholder(): void
    {
        $debug = rex::isDebugMode();
        rex::setProperty('debug', true);

        try {
            Assert::same(
                '{{ selftest_missing }}',
                DomainSettings::get('selftest_missing'),
                'the new api still shows the placeholder',
            );
            Assert::null(
                yrewrite_domain_settings::getValue('selftest_missing'),
                'the old api must keep returning null',
            );
        } finally {
            rex::setProperty('debug', $debug);
        }
    }

    /**
     * Without a key the raw row comes back, structural columns included.
     * Project code reads $row['id'] and joins other tables on it.
     */
    public function testGetValueWithoutKeyReturnsTheRawRow(): void
    {
        $table = rex::getTable('yrewrite_domain_settings');
        $clangId = $this->fixtures->fallbackClangId();
        $sql = rex_sql::factory();

        $domainId = DomainSettings::getCurrentDomainId();

        $existing = $sql->getArray(
            'SELECT id FROM ' . $table . ' WHERE domain_id = :domain AND clang_id = :clang',
            ['domain' => $domainId, 'clang' => $clangId],
        );

        if ([] === $existing) {
            $sql->setQuery(
                'INSERT INTO ' . $table . ' SET domain_id = :domain, clang_id = :clang',
                ['domain' => $domainId, 'clang' => $clangId],
            );
            $this->rowWasCreated = true;
        }

        try {
            $row = yrewrite_domain_settings::getValue();

            Assert::true(is_array($row), 'getValue() without a key returns an array');
            Assert::hasKey('id', $row);
            Assert::hasKey('domain_id', $row);
            Assert::same($domainId, (int) $row['domain_id']);
        } finally {
            $this->removeCreatedRow();
        }
    }

    /** No row at all means null, not an empty array. */
    public function testGetValueWithoutKeyReturnsNullWhenNothingIsStored(): void
    {
        $table = rex::getTable('yrewrite_domain_settings');
        $clangId = $this->fixtures->fallbackClangId();

        $rows = rex_sql::factory()->getArray(
            'SELECT id FROM ' . $table . ' WHERE domain_id = :domain AND clang_id = :clang',
            ['domain' => DomainSettings::getCurrentDomainId(), 'clang' => $clangId],
        );

        if ([] !== $rows) {
            // This installation has real values on that domain; deleting them
            // to prove a point is not worth it.
            return;
        }

        Assert::null(yrewrite_domain_settings::getValue());
    }

    /** Markup in the value has to survive - that is what it always did. */
    public function testLegacyRexVarStaysUnescaped(): void
    {
        $output = rex_var::parse('REX_DOMAIN_SETTING[key="selftest_text"]', rex_var::ENV_FRONTEND);

        Assert::false(
            str_contains($output, 'rex_escape('),
            'REX_DOMAIN_SETTING must keep returning the raw value',
        );
        Assert::contains('yrewrite_domain_settings::getValue', $output);
    }

    /** The permission key is unchanged, so roles keep their domains. */
    public function testComplexPermIsStillRegisteredUnderTheOldKey(): void
    {
        $registered = rex_complex_perm::getAll();

        Assert::hasKey('yrewrite_domains', $registered);
        Assert::same(rex_yrewrite_domains_perm::class, $registered['yrewrite_domains']);
        Assert::true(is_subclass_of(rex_yrewrite_domains_perm::class, DomainPerm::class));
    }

    /**
     * getDomains() returns the raw permission value, which is the string `all`
     * for a role that may edit every domain. A declared array return type
     * would turn that into a TypeError in code doing `=== 'all'`.
     */
    public function testGetDomainsKeepsItsUntypedReturn(): void
    {
        $method = new ReflectionMethod(rex_yrewrite_domains_perm::class, 'getDomains');

        Assert::false($method->hasReturnType(), 'getDomains() must not declare a return type');
    }

    /** Without a logged-in user this used to fatal; now it is just empty. */
    public function testGetAllowedDomainsSurvivesWithoutAUser(): void
    {
        Assert::same([], yrewrite_domain_settings::getAllowedDomains());
    }

    private function removeCreatedRow(): void
    {
        if (!$this->rowWasCreated) {
            return;
        }

        rex_sql::factory()->setQuery(
            'DELETE FROM ' . rex::getTable('yrewrite_domain_settings')
            . ' WHERE domain_id = :domain AND clang_id = :clang',
            [
                'domain' => DomainSettings::getCurrentDomainId(),
                'clang' => $this->fixtures->fallbackClangId(),
            ],
        );

        $this->rowWasCreated = false;
        DomainSettings::deleteCache();
    }
}
