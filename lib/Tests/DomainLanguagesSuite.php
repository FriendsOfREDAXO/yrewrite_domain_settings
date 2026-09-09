<?php

namespace FriendsOfRedaxo\DomainSettings\Tests;

use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;
use FriendsOfRedaxo\DomainSettings\Test\AbstractSuite;
use FriendsOfRedaxo\DomainSettings\Test\Assert;
use rex_clang;

use function in_array;

/**
 * Languages belong to a domain, not to the installation.
 *
 * yrewrite lets every domain run its own set of languages - two on one, three
 * on another. Offering all of them everywhere would let an editor maintain
 * values that are never delivered, and let a value inherit from a language its
 * domain does not even have. Raised in issue #30 and the reason the
 * multilingual attempt from 2022 was never merged.
 */
final class DomainLanguagesSuite extends AbstractSuite
{
    public function getTitle(): string
    {
        return 'Domain languages';
    }

    /** No yrewrite domain behind it means no restriction, not "no languages". */
    public function testUnknownDomainIsUnrestricted(): void
    {
        Assert::same([], Backend::getDomainClangIds(999001));
    }

    /** Domain 0 is yrewrite's implicit default and serves everything. */
    public function testDomainZeroIsUnrestricted(): void
    {
        Assert::same([], Backend::getDomainClangIds(0));
    }

    public function testUnrestrictedDomainOffersEveryEditableLanguage(): void
    {
        Assert::same(
            array_map(static fn (rex_clang $c) => $c->getId(), Backend::getEditableClangs()),
            array_map(static fn (rex_clang $c) => $c->getId(), Backend::getEditableClangs(999001)),
        );
    }

    /** Every language a domain reports has to exist in the core. */
    public function testReportedLanguagesExist(): void
    {
        foreach (array_keys(Backend::getAllDomains()) as $domainId) {
            foreach (Backend::getDomainClangIds($domainId) as $clangId) {
                Assert::true(rex_clang::exists($clangId), 'clang ' . $clangId . ' of domain ' . $domainId);
            }
        }
    }

    /** The tabs of a domain never show a language that domain does not serve. */
    public function testEditableLanguagesStayInsideTheDomain(): void
    {
        foreach (array_keys(Backend::getAllDomains()) as $domainId) {
            $available = Backend::getDomainClangIds($domainId);
            if ([] === $available) {
                continue;
            }

            foreach (Backend::getEditableClangs($domainId) as $clang) {
                Assert::true(
                    in_array($clang->getId(), $available, true),
                    'domain ' . $domainId . ' must not offer clang ' . $clang->getId(),
                );
            }
        }
    }

    /** A value must not inherit from a language its domain does not serve. */
    public function testFallbackStaysInsideTheDomain(): void
    {
        foreach (array_keys(Backend::getAllDomains()) as $domainId) {
            $available = Backend::getDomainClangIds($domainId);
            if ([] === $available) {
                continue;
            }

            Assert::true(
                in_array(DomainSettings::getFallbackClangId($domainId), $available, true),
                'fallback of domain ' . $domainId . ' is outside its languages',
            );
        }
    }

    /** Without a domain the configured fallback applies unchanged. */
    public function testFallbackWithoutDomainIsTheConfiguredOne(): void
    {
        Assert::same(
            DomainSettings::getConfiguredFallbackClangId(),
            DomainSettings::getFallbackClangId(),
        );
    }
}
