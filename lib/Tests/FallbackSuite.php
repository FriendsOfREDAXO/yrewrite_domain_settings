<?php

namespace FriendsOfRedaxo\DomainSettings\Tests;

use FriendsOfRedaxo\DomainSettings\DomainSettings;
use FriendsOfRedaxo\DomainSettings\Test\AbstractSuite;
use FriendsOfRedaxo\DomainSettings\Test\Assert;
use FriendsOfRedaxo\DomainSettings\Test\Fixtures;
use rex;

/** The inheritance chain - the core of the addon. */
class FallbackSuite extends AbstractSuite
{
    public function testOwnValueWins(): void
    {
        $this->fixtures->setValues($this->fixtures->fallbackClangId(), ['selftest_text' => 'fallback']);
        $other = $this->fixtures->otherClangId();

        if (null === $other) {
            Assert::skip('needs a second language');
        }

        $this->fixtures->setValues($other, ['selftest_text' => 'own']);

        Assert::same('own', DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $other));
    }

    public function testEmptyInheritsFromFallbackLanguage(): void
    {
        $other = $this->fixtures->otherClangId();

        if (null === $other) {
            Assert::skip('needs a second language');
        }

        $this->fixtures->setValues($this->fixtures->fallbackClangId(), ['selftest_text' => 'fallback']);
        $this->fixtures->setValues($other, ['selftest_text' => '']);

        Assert::same('fallback', DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $other));
    }

    /**
     * "0" must not inherit - otherwise a checkbox could never be switched off
     * in a single language.
     */
    public function testZeroDoesNotInherit(): void
    {
        $other = $this->fixtures->otherClangId();

        if (null === $other) {
            Assert::skip('needs a second language');
        }

        $this->fixtures->setValues($this->fixtures->fallbackClangId(), ['selftest_flag' => '1']);
        $this->fixtures->setValues($other, ['selftest_flag' => '0']);

        Assert::same('0', DomainSettings::get('selftest_flag', null, Fixtures::DOMAIN_ID, $other));
    }

    public function testUnknownKeyReturnsNullOrDefault(): void
    {
        $debug = rex::isDebugMode();
        rex::setProperty('debug', false);

        try {
            Assert::null(DomainSettings::get('selftest_missing', null, Fixtures::DOMAIN_ID, $this->fixtures->fallbackClangId()));
            Assert::same('x', DomainSettings::get('selftest_missing', 'x', Fixtures::DOMAIN_ID, $this->fixtures->fallbackClangId()));
        } finally {
            rex::setProperty('debug', $debug);
        }
    }

    public function testUnknownKeyShowsPlaceholderInDebugMode(): void
    {
        $debug = rex::isDebugMode();
        rex::setProperty('debug', true);

        try {
            Assert::same(
                '{{ selftest_missing }}',
                DomainSettings::get('selftest_missing', null, Fixtures::DOMAIN_ID, $this->fixtures->fallbackClangId()),
            );
            // An explicit default still wins over the placeholder.
            Assert::same('x', DomainSettings::get('selftest_missing', 'x', Fixtures::DOMAIN_ID, $this->fixtures->fallbackClangId()));
        } finally {
            rex::setProperty('debug', $debug);
        }
    }

    /**
     * get() and getAll() are separate code paths applying the same rules -
     * the most likely place for the two to drift apart.
     */
    public function testGetAllAgreesWithGet(): void
    {
        $clangId = $this->fixtures->otherClangId() ?? $this->fixtures->fallbackClangId();
        $this->fixtures->setValues($this->fixtures->fallbackClangId(), ['selftest_text' => 'fallback', 'selftest_flag' => '1']);

        foreach (DomainSettings::getAll(Fixtures::DOMAIN_ID, $clangId) as $key => $value) {
            Assert::same(
                DomainSettings::get($key, null, Fixtures::DOMAIN_ID, $clangId),
                $value,
                'getAll differs from get for "' . $key . '"',
            );
        }
    }

    public function testUnknownDomainIsEmpty(): void
    {
        Assert::same([], DomainSettings::getAll(999999, $this->fixtures->fallbackClangId()));
    }

    /** Must not fatal without an article or yrewrite context. */
    public function testCurrentDomainIdWithoutContext(): void
    {
        Assert::true(DomainSettings::getCurrentDomainId() >= 0);
    }
}
