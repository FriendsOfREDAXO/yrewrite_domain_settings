<?php

namespace FriendsOfRedaxo\DomainSettings\Tests;

use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;
use FriendsOfRedaxo\DomainSettings\Test\AbstractSuite;
use FriendsOfRedaxo\DomainSettings\Test\Assert;
use rex_request;

use function in_array;

/**
 * Which domain and language the editing page works in.
 *
 * Resolved from the request, then the session, then a default - and validated
 * against what the user may edit at every step. The last part is why this is
 * tested: a domain that slips through here is a domain someone writes to.
 */
class ContextSuite extends AbstractSuite
{
    private string $domainParam = '';
    private string $clangParam = '';

    public function setUp(): void
    {
        $this->domainParam = rex_request::get('domain_id', 'string', '');
        $this->clangParam = rex_request::get('clang_id', 'string', '');
        Backend::resetCaches();
    }

    public function tearDown(): void
    {
        $this->putParam('domain_id', $this->domainParam);
        $this->putParam('clang_id', $this->clangParam);
        Backend::resetCaches();
    }

    /**
     * Writes a query parameter, or removes it when there is no value.
     *
     * There is no setter for this - the core writes $_GET directly in its own
     * tests (core/tests/context_test.php). Reading goes through rex_request,
     * so nothing here reads the superglobal.
     */
    private function putParam(string $name, string $value): void
    {
        // rexstan flags both lines below: using $_GET is forbidden, and
        // rightly so in production code. Simulating a request has no other
        // way - there is no setter - and the two occurrences are kept here,
        // in one helper, rather than spread across the checks.
        if ('' === $value) {
            unset($_GET[$name]);

            return;
        }

        $_GET[$name] = $value;
    }

    /**
     * getDomains() filters by the user's domain permission and deliberately
     * returns nothing without a login - which in the console is always. Every
     * check here therefore runs as the stand-in admin the fixtures provide.
     */
    private function asAdmin(callable $check): void
    {
        $this->fixtures->withAdminUser(function () use ($check): void {
            Backend::resetCaches();
            $check();
        });
    }

    /**
     * Without a request and without a session, the first domain the user may
     * edit. Never 0 as a stand-in for "none" - that is a real domain id.
     */
    public function testFallsBackToTheFirstAllowedDomain(): void
    {
        $this->asAdmin(function (): void {
            $domains = Backend::getDomains();

            if ([] === $domains) {
                Assert::skip('needs at least one editable domain');
            }

            $this->putParam('domain_id', '');
            Backend::resetCaches();

            Assert::same((int) array_key_first($domains), Backend::getActiveDomainId());
        });
    }

    /** A domain named in the query string wins over the default. */
    public function testRequestPicksTheDomain(): void
    {
        $this->asAdmin(function (): void {
            $domains = Backend::getDomains();

            if (count($domains) < 2) {
                Assert::skip('needs two editable domains');
            }

            $ids = array_keys($domains);
            $second = (int) $ids[1];

            $this->putParam('domain_id', (string) $second);
            Backend::resetCaches();

            Assert::same($second, Backend::getActiveDomainId());
        });
    }

    /**
     * The important one: a domain the user may not edit is not honoured, however
     * it arrives. Otherwise the editing page would open on someone else's domain.
     */
    public function testUnknownDomainIsIgnored(): void
    {
        $this->asAdmin(function (): void {
            $domains = Backend::getDomains();

            if ([] === $domains) {
                Assert::skip('needs at least one editable domain');
            }

            $this->putParam('domain_id', '999999');
            Backend::resetCaches();

            $active = Backend::getActiveDomainId();

            Assert::true(isset($domains[$active]), 'active domain has to be an allowed one');
            Assert::same((int) array_key_first($domains), $active);
        });
    }

    /** Not even domain 0, which yrewrite uses as its implicit default. */
    public function testZeroIsNotAFreePass(): void
    {
        $this->asAdmin(function (): void {
            $domains = Backend::getDomains();

            if (isset($domains[0])) {
                Assert::skip('domain 0 is editable here, nothing to guard against');
            }

            $this->putParam('domain_id', '0');
            Backend::resetCaches();

            Assert::false(0 === Backend::getActiveDomainId(), 'domain 0 must not be handed out unchecked');
        });
    }

    /** The language is resolved against the ones that domain actually serves. */
    public function testClangComesFromTheDomainsLanguages(): void
    {
        $this->asAdmin(function (): void {
            $domainId = Backend::getActiveDomainId();
            $clangIds = Backend::getEditableClangIds($domainId);

            if ([] === $clangIds) {
                Assert::skip('needs an editable language');
            }

            $this->putParam('clang_id', '');
            Backend::resetCaches();

            Assert::true(
                in_array(Backend::getActiveClangId($domainId), $clangIds, true),
                'active language has to be one the domain serves',
            );
        });
    }

    /** A language the domain does not serve gives way to the fallback. */
    public function testForeignClangGivesWayToTheFallback(): void
    {
        $this->asAdmin(function (): void {
            $domainId = Backend::getActiveDomainId();
            $clangIds = Backend::getEditableClangIds($domainId);

            if ([] === $clangIds) {
                Assert::skip('needs an editable language');
            }

            $this->putParam('clang_id', '999999');
            Backend::resetCaches();

            $active = Backend::getActiveClangId($domainId);

            Assert::true(in_array($active, $clangIds, true), 'has to stay within the domain');

            $fallback = DomainSettings::getFallbackClangId($domainId);

            if (in_array($fallback, $clangIds, true)) {
                Assert::same($fallback, $active);
            }
        });
    }

    /** A language named in the query string wins, as long as the domain serves it. */
    public function testRequestPicksTheClang(): void
    {
        $this->asAdmin(function (): void {
            $domainId = Backend::getActiveDomainId();
            $clangIds = Backend::getEditableClangIds($domainId);

            if (count($clangIds) < 2) {
                Assert::skip('needs two editable languages');
            }

            $wanted = $clangIds[1];

            $this->putParam('clang_id', (string) $wanted);
            Backend::resetCaches();

            Assert::same($wanted, Backend::getActiveClangId($domainId));
        });
    }

    /**
     * resetCaches() says it drops everything this class keeps for a request.
     * The resolved context is part of that - it was not, which is what kept
     * this suite from being writable in the first place.
     */
    public function testResetCachesDropsTheResolvedContext(): void
    {
        $this->asAdmin(function (): void {
            $domains = Backend::getDomains();

            if (count($domains) < 2) {
                Assert::skip('needs two editable domains');
            }

            $ids = array_keys($domains);

            $this->putParam('domain_id', (string) $ids[0]);
            Backend::resetCaches();
            Assert::same((int) $ids[0], Backend::getActiveDomainId());

            $this->putParam('domain_id', (string) $ids[1]);
            Backend::resetCaches();
            Assert::same((int) $ids[1], Backend::getActiveDomainId());
        });
    }
}
