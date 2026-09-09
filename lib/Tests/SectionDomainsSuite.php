<?php

namespace FriendsOfRedaxo\DomainSettings\Tests;

use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;
use FriendsOfRedaxo\DomainSettings\Test\AbstractSuite;
use FriendsOfRedaxo\DomainSettings\Test\Assert;
use FriendsOfRedaxo\DomainSettings\Test\Fixtures;
use rex;
use rex_config;
use rex_sql;
use rex_user;
use rex_yform_manager_table;
use rex_yform_manager_table_api;
use RuntimeException;

use function array_key_exists;
use function count;
use function in_array;

/**
 * Which domains a tab is offered on.
 *
 * A tab without an assignment shows up everywhere - that is what keeps every
 * installation that never touches the setting working as before. Where a tab
 * is assigned, it counts: it is offered for editing there and its values are
 * delivered there, and nowhere else. The rows are never touched, so taking a
 * domain away is reversible - that pair of promises is what
 * testTheAssignmentDecidesWhereValuesAreDelivered() and
 * testValuesComeBackWhenTheDomainIsAssignedAgain() are here for.
 *
 * The assignment lives in rex_config and is therefore the one piece of state
 * these tests share with the instance they run on - it is saved before the
 * first check and put back after every one of them, see restore().
 */
final class SectionDomainsSuite extends AbstractSuite
{
    /**
     * Config key holding the assignment.
     *
     * A copy of Backend::CONFIG_SECTION_DOMAINS, which is private - and it has
     * to be a copy, because the value is needed to save and restore the real
     * setting around the run. If it ever drifts, the restore below would put
     * the backup under the wrong key; that is what
     * testAssignmentIsStoredUnderTheDocumentedKey() notices.
     */
    private const CONFIG_KEY = 'section_domains';

    /** Two more unreachable domains, in the same range as Fixtures::DOMAIN_ID. */
    private const DOMAIN_UNTOUCHED = Fixtures::DOMAIN_ID + 1;
    private const DOMAIN_OTHER = Fixtures::DOMAIN_ID + 2;

    private bool $hadConfig = false;

    /** @var array<string, mixed> */
    private array $backup = [];

    public function getTitle(): string
    {
        return 'Section domains';
    }

    public function setUpBeforeClass(): void
    {
        $this->hadConfig = rex_config::has(DomainSettings::ADDON, self::CONFIG_KEY);
        $config = rex_config::get(DomainSettings::ADDON, self::CONFIG_KEY, []);
        $this->backup = is_array($config) ? $config : [];
    }

    /** After every check, not only at the end: a failure must not leak either. */
    public function tearDown(): void
    {
        $this->restore();
    }

    public function tearDownAfterClass(): void
    {
        $this->restore();
    }

    /**
     * The key the backup above is written back to.
     *
     * Reads what Backend actually wrote rather than trusting the constant.
     */
    public function testAssignmentIsStoredUnderTheDocumentedKey(): void
    {
        $domains = array_keys(Backend::getAllDomains());

        if (count($domains) < 2) {
            Assert::skip('needs two domains to store an assignment at all');
        }

        rex_config::remove(DomainSettings::ADDON, self::CONFIG_KEY);
        $this->assign($this->fixtures->table(), [$domains[0]]);

        Assert::true(
            rex_config::has(DomainSettings::ADDON, self::CONFIG_KEY),
            'the assignment is stored under `' . self::CONFIG_KEY . '`',
        );
    }

    /** No entry means every domain - including ones nobody has heard of. */
    public function testUnassignedSectionIsOfferedEverywhere(): void
    {
        $table = $this->fixtures->table();
        $this->assign($table, []);

        Assert::same([], Backend::getSectionDomainIds($table));
        Assert::false(array_key_exists($table, Backend::getSectionDomains()), 'nothing is written for it');

        foreach (array_keys(Backend::getAllDomains()) as $domainId) {
            Assert::true(Backend::isSectionVisibleForDomain($table, $domainId), 'domain ' . $domainId);
        }

        Assert::true(
            Backend::isSectionVisibleForDomain($table, Fixtures::DOMAIN_ID),
            'a domain the assignment never heard of',
        );
    }

    public function testAssignmentRoundTrips(): void
    {
        $domains = array_keys(Backend::getAllDomains());

        if (count($domains) < 2) {
            Assert::skip('needs two domains');
        }

        $table = $this->fixtures->table();
        $this->assign($table, [$domains[0]]);

        Assert::same([$domains[0]], Backend::getSectionDomainIds($table));
        Assert::true(Backend::isSectionVisibleForDomain($table, $domains[0]));
        Assert::false(Backend::isSectionVisibleForDomain($table, $domains[1]), 'the other domain is not assigned');
    }

    /**
     * Picking every domain is stored as picking none.
     *
     * Otherwise a domain added later would hide every tab that was "assigned
     * to all of them" at the time it was set.
     */
    public function testEveryDomainIsStoredAsNoAssignment(): void
    {
        $table = $this->fixtures->table();
        $domains = array_keys(Backend::getAllDomains());

        $this->assign($table, $domains);

        Assert::same([], Backend::getSectionDomainIds($table));
        Assert::false(array_key_exists($table, Backend::getSectionDomains()), 'nothing is written for it');

        foreach ($domains as $domainId) {
            Assert::true(Backend::isSectionVisibleForDomain($table, $domainId), 'domain ' . $domainId);
        }
    }

    /** The table name arrives from a request and must not become a config key. */
    public function testUnknownTableNeverBecomesAConfigKey(): void
    {
        $domains = array_keys(Backend::getAllDomains());

        $this->assign('rex_article', [$domains[0]]);
        $this->assign('does_not_exist', [$domains[0]]);

        Assert::false(array_key_exists('rex_article', Backend::getSectionDomains()), 'rex_article');
        Assert::false(array_key_exists('does_not_exist', Backend::getSectionDomains()), 'unknown table');
    }

    /**
     * So does the domain id - and an unknown one must be dropped rather than
     * stored, otherwise a hand-written request could hide a tab from every
     * domain there is.
     */
    public function testUnknownDomainIsDropped(): void
    {
        $table = $this->fixtures->table();
        $domains = array_keys(Backend::getAllDomains());

        $this->assign($table, [Fixtures::DOMAIN_ID]);
        Assert::same([], Backend::getSectionDomainIds($table), 'an unknown domain alone is no assignment');
        Assert::true(
            Backend::isSectionVisibleForDomain($table, $domains[0]),
            'and must not take the tab off the domains that do exist',
        );

        $this->assign($table, [$domains[0], Fixtures::DOMAIN_ID]);
        Assert::false(
            in_array(Fixtures::DOMAIN_ID, Backend::getSectionDomainIds($table), true),
            'an unknown domain is not stored next to a real one either',
        );
    }

    /**
     * Deleting a tab takes its assignment with it.
     *
     * A tab created under the same name later would otherwise inherit a limit
     * nobody set - and never show up on the domain it was made for.
     */
    public function testDeletingASectionTakesItsAssignmentWithIt(): void
    {
        $domains = array_keys(Backend::getAllDomains());

        if (count($domains) < 2) {
            Assert::skip('needs two domains to store an assignment at all');
        }

        $table = $this->createSection('Selftest Zuordnung', 'selftest_zuordnung');

        try {
            $this->assign($table, [$domains[0]]);
            Assert::same([$domains[0]], Backend::getSectionDomainIds($table), 'precondition: it is assigned');

            Assert::true(Backend::deleteSection($table));
            Assert::false(
                array_key_exists($table, Backend::getSectionDomains()),
                'the assignment must not stay behind',
            );
        } finally {
            $this->dropSection($table);
        }
    }

    /** An unassigned tab is offered everywhere, so it overlaps with anything. */
    public function testAnUnassignedSectionSharesEveryDomain(): void
    {
        $a = $this->fixtures->table();
        $b = rex::getTable(DomainSettings::ADDON);
        $domains = array_keys(Backend::getAllDomains());

        $this->assign($a, []);
        $this->assign($b, []);
        Assert::true(Backend::sectionsShareDomain($a, $b), 'two unassigned tabs');

        $this->assign($a, [$domains[0]]);
        Assert::true(Backend::sectionsShareDomain($a, $b), 'one of them unassigned');
        Assert::true(Backend::sectionsShareDomain($b, $a), 'and the other way round');
    }

    /**
     * Tabs on different domains never answer for the same domain, so the same
     * field name in both of them is not a clash - which is what build() asks
     * this for before it reports duplicates.
     */
    public function testSectionsOnDifferentDomainsDoNotShare(): void
    {
        $domains = array_keys(Backend::getAllDomains());

        if (count($domains) < 2) {
            Assert::skip('needs two domains');
        }

        $a = $this->fixtures->table();
        $b = rex::getTable(DomainSettings::ADDON);

        $this->assign($a, [$domains[0]]);
        $this->assign($b, [$domains[1]]);
        Assert::false(Backend::sectionsShareDomain($a, $b), 'assigned to different domains');

        $this->assign($b, [$domains[0]]);
        Assert::true(Backend::sectionsShareDomain($a, $b), 'assigned to the same domain');
    }

    /** The tab list of a domain follows the assignment. */
    public function testNavigationFollowsTheAssignment(): void
    {
        $domains = array_keys(Backend::getAllDomains());

        if (count($domains) < 2) {
            Assert::skip('needs two domains');
        }

        $table = $this->fixtures->table();
        $this->assign($table, [$domains[0]]);

        // getSections() is fail-closed and the console has no user, so without
        // a user in place this would compare empty lists and pass regardless.
        $this->fixtures->withAdminUser(static function () use ($table, $domains): void {
            Assert::hasKey($table, Backend::getSections($domains[0]), 'assigned domain');
            Assert::false(
                array_key_exists($table, Backend::getSections($domains[1])),
                'the tab must not be offered on a domain it is not assigned to',
            );
            Assert::hasKey($table, Backend::getSections(), 'without a domain the assignment does not apply');
        });
    }

    /**
     * The assignment narrows, it never widens.
     *
     * It is applied after YForm's table permission, so a user who may not edit
     * a table does not get to see it because it happens to be assigned to the
     * domain they are on.
     */
    public function testTheAssignmentNeverWidensThePermission(): void
    {
        $table = $this->fixtures->table();
        $domains = array_keys(Backend::getAllDomains());

        // Offered on every domain - the widest the assignment can be.
        $this->assign($table, []);

        $this->withUserWithoutPermissions(static function () use ($domains): void {
            Assert::same([], Backend::getSections(), 'precondition: nothing without the table permission');

            foreach ($domains as $domainId) {
                Assert::same([], Backend::getSections($domainId), 'domain ' . $domainId);
            }
        });
    }

    /**
     * The central promise: the assignment decides where values are delivered.
     *
     * A tab that is not offered on a domain does not answer for it either -
     * otherwise the setting would hide the input while the frontend kept
     * showing what nobody can edit any more.
     *
     * A default is passed on purpose: without one, get() answers with a
     * `{{ key }}` placeholder in debug mode, which would pass a null check
     * for the wrong reason.
     */
    public function testTheAssignmentDecidesWhereValuesAreDelivered(): void
    {
        $domains = array_keys(Backend::getAllDomains());

        if (count($domains) < 2) {
            Assert::skip('needs two domains to store an assignment at all');
        }

        $clangId = $this->fixtures->fallbackClangId();
        $table = $this->fixtures->table();

        $this->fixtures->setValues($clangId, ['selftest_text' => 'nur wo zugeordnet']);
        $this->assign($table, [$domains[0]]);

        Assert::false(
            Backend::isSectionVisibleForDomain($table, Fixtures::DOMAIN_ID),
            'precondition: the tab is not offered on the domain the value belongs to',
        );

        Assert::same(
            'kein wert',
            DomainSettings::get('selftest_text', 'kein wert', Fixtures::DOMAIN_ID, $clangId),
            'get() must not answer for a domain the tab is not assigned to',
        );
        Assert::false(
            array_key_exists('selftest_text', DomainSettings::getAll(Fixtures::DOMAIN_ID, $clangId)),
            'and getAll() must not carry it either',
        );
        Assert::same(
            [],
            DomainSettings::getSectionValues($table, Fixtures::DOMAIN_ID, $clangId),
            'and neither must getSectionValues(), which the REST routes use',
        );
    }

    /**
     * The other half of it: nothing is lost, so a wrong click is undone by
     * putting the domain back.
     */
    public function testValuesComeBackWhenTheDomainIsAssignedAgain(): void
    {
        $domains = array_keys(Backend::getAllDomains());

        if (count($domains) < 2) {
            Assert::skip('needs two domains to store an assignment at all');
        }

        $clangId = $this->fixtures->fallbackClangId();
        $table = $this->fixtures->table();

        $this->fixtures->setValues($clangId, ['selftest_text' => 'kehrt zurück']);
        $this->assign($table, [$domains[0]]);

        Assert::same(
            'kein wert',
            DomainSettings::get('selftest_text', 'kein wert', Fixtures::DOMAIN_ID, $clangId),
            'precondition: not delivered while the domain is not assigned',
        );

        // Back to "every domain", the state the tab was created in.
        $this->assign($table, []);

        Assert::same(
            'kehrt zurück',
            DomainSettings::get('selftest_text', 'kein wert', Fixtures::DOMAIN_ID, $clangId),
            'the row was never touched, so the value is back unchanged',
        );
    }

    /**
     * Saving the assignment has to drop the value cache itself.
     *
     * Nothing else does it here: no dataset was saved, so YFORM_DATA_UPDATED
     * never fires - and a cache built before the change would keep answering
     * for a domain that is no longer assigned. Deliberately calls Backend
     * directly instead of assign(), which is what the backend page does.
     */
    public function testSavingTheAssignmentDropsTheValueCache(): void
    {
        $domains = array_keys(Backend::getAllDomains());

        if (count($domains) < 2) {
            Assert::skip('needs two domains to store an assignment at all');
        }

        $clangId = $this->fixtures->fallbackClangId();
        $table = $this->fixtures->table();

        $this->fixtures->setValues($clangId, ['selftest_text' => 'im cache']);

        Assert::same(
            'im cache',
            DomainSettings::get('selftest_text', 'kein wert', Fixtures::DOMAIN_ID, $clangId),
            'precondition: the cache is built and holds the value',
        );

        $this->fixtures->withAdminUser(static function () use ($table, $domains): void {
            Backend::setSectionDomains($table, [$domains[0]]);
        });

        Assert::same(
            'kein wert',
            DomainSettings::get('selftest_text', 'kein wert', Fixtures::DOMAIN_ID, $clangId),
            'the cached value must be gone without anyone clearing the cache',
        );
    }

    /**
     * A file used only where a tab is not assigned still counts as in use.
     *
     * isMediaInUse() reads the tables rather than the value cache for exactly
     * this: filtered, the media pool would offer to delete a logo that comes
     * back into service the moment the domain is assigned again.
     */
    public function testMediaStaysInUseWhileItsDomainIsUnassigned(): void
    {
        $domains = array_keys(Backend::getAllDomains());

        if (count($domains) < 2) {
            Assert::skip('needs two domains to store an assignment at all');
        }

        $clangId = $this->fixtures->fallbackClangId();
        $table = $this->createSection('Selftest media', 'selftest_image', 'be_media');

        try {
            // The row through the dataset, the value through SQL: a be_media
            // field takes its value from the media widget in the backend, so
            // setValue() on the dataset leaves the column empty. What is under
            // test here is isMediaInUse(), not YForm's way of saving.
            $this->write($table, Fixtures::DOMAIN_ID, $clangId, []);

            $sql = rex_sql::factory();
            $sql->setTable($table);
            $sql->setWhere(['domain_id' => Fixtures::DOMAIN_ID, 'clang_id' => $clangId]);
            $sql->setValue('selftest_image', 'selftest-logo.jpg');
            $sql->update();
            DomainSettings::deleteCache();

            Assert::same(
                'selftest-logo.jpg',
                Backend::getDataset($table, ['domain_id' => Fixtures::DOMAIN_ID, 'clang_id' => $clangId])->getValue('selftest_image'),
                'precondition: the file name is stored',
            );

            $this->assign($table, [$domains[0]]);

            Assert::same(
                [],
                DomainSettings::getSectionValues($table, Fixtures::DOMAIN_ID, $clangId),
                'precondition: the value is not delivered on that domain',
            );

            Assert::true(
                DomainSettings::isMediaInUse('selftest-logo.jpg'),
                'the media pool must still see the file as in use',
            );
        } finally {
            $this->dropSection($table);
        }
    }

    /**
     * Copying stops at the target's assignment.
     *
     * Writing rows into a domain the tab is not offered on would create
     * exactly the leftovers the assignment is meant to avoid.
     */
    public function testCopyingSkipsATabTheTargetDomainDoesNotOffer(): void
    {
        $domains = array_keys(Backend::getAllDomains());

        if (count($domains) < 2) {
            Assert::skip('needs two domains to store an assignment at all');
        }

        $clangId = $this->fixtures->fallbackClangId();
        $table = $this->fixtures->table();

        $this->fixtures->setValues($clangId, ['selftest_text' => 'quelle'], $domains[0]);
        $this->assign($table, [$domains[0]]);

        $copied = null;
        $this->fixtures->withAdminUser(static function () use ($table, $domains, $clangId, &$copied): void {
            $copied = Backend::copyValues($domains[0], $clangId, Fixtures::DOMAIN_ID, $clangId, $table);
        });

        Assert::same(0, $copied, 'nothing is copied into a domain the tab is not assigned to');
        Assert::same(
            [],
            DomainSettings::getSectionValues($table, Fixtures::DOMAIN_ID, $clangId),
            'and no row shows up there',
        );
    }

    /**
     * Merging two tabs: a filled value beats an empty one, whichever was read
     * first.
     *
     * Opening a tab writes an empty row even where that tab is not used, so
     * without this the placeholder of one tab would shadow real content in
     * another - depending on nothing but the order the tables come back in.
     *
     * Both tabs are assigned to the same domain on purpose: that is the only
     * place a shared field name can collide at all, now that a tab only
     * answers where it is assigned. build() reports the shared name in the
     * log, which is exactly what it is for.
     */
    public function testAFilledValueBeatsAnEmptyOneFromAnotherTab(): void
    {
        $domains = array_keys(Backend::getAllDomains());

        if (count($domains) < 2) {
            Assert::skip('needs two domains to store an assignment at all');
        }

        $clangId = $this->fixtures->fallbackClangId();
        $field = 'selftest_geteilt';

        $a = $this->createSection('Selftest Merge A', $field);
        $b = $this->createSection('Selftest Merge B', $field);

        try {
            $this->assign($a, [$domains[0]]);
            $this->assign($b, [$domains[0]]);

            $this->write($a, $domains[0], $clangId, [$field => '']);
            $this->write($b, $domains[0], $clangId, [$field => 'aus b']);

            Assert::same(
                'aus b',
                DomainSettings::get($field, null, $domains[0], $clangId),
                'the filled value of the second tab must win',
            );

            // The other side of a shared name: it is reported, so an editor
            // can rename one of the two instead of wondering which wins.
            Assert::true(
                in_array($field, Backend::getDuplicateFieldNames(), true),
                'a name used by two tabs of one domain is reported',
            );

            // The other way round: with `+` exactly one of the two directions
            // would answer with the empty placeholder.
            $this->write($a, $domains[0], $clangId, [$field => 'aus a']);
            $this->write($b, $domains[0], $clangId, [$field => '']);

            Assert::same(
                'aus a',
                DomainSettings::get($field, null, $domains[0], $clangId),
                'and the filled value of the first tab as well',
            );
        } finally {
            $this->dropSection($a);
            $this->dropSection($b);
        }
    }

    /**
     * Whether a tab holds anything for a domain.
     *
     * Asked before a domain is taken away from a tab, because the rows stay
     * behind and keep answering in the frontend.
     */
    public function testSectionHasValuesLooksAtOneDomainOnly(): void
    {
        $clangId = $this->fixtures->fallbackClangId();
        $table = $this->fixtures->table();

        Assert::false(
            Backend::sectionHasValues($table, self::DOMAIN_UNTOUCHED),
            'nothing was ever written for this domain',
        );

        $this->write($table, self::DOMAIN_UNTOUCHED, $clangId, ['selftest_text' => '']);
        Assert::false(
            Backend::sectionHasValues($table, self::DOMAIN_UNTOUCHED),
            'an empty row is not a value - opening a tab writes one',
        );

        $this->write($table, self::DOMAIN_UNTOUCHED, $clangId, ['selftest_text' => 'inhalt']);
        Assert::true(Backend::sectionHasValues($table, self::DOMAIN_UNTOUCHED), 'now there is one');
        Assert::false(
            Backend::sectionHasValues($table, self::DOMAIN_OTHER),
            'and it belongs to that domain only',
        );
    }

    /**
     * A foreign table is refused before it reaches the query.
     *
     * The name arrives from a request, and rex_article has no domain_id column
     * - without the check this would not answer false, it would blow up.
     */
    public function testSectionHasValuesRefusesForeignTables(): void
    {
        Assert::false(Backend::sectionHasValues('rex_article', Fixtures::DOMAIN_ID));
        Assert::false(Backend::sectionHasValues('does_not_exist', Fixtures::DOMAIN_ID));
    }

    /**
     * Stores an assignment the way the settings page does.
     *
     * setSectionDomains() validates the ids against the domains the *user* may
     * edit, and the console has no user - without one in place every call here
     * would quietly store nothing and every assertion would be about an empty
     * config.
     *
     * @param array<int|string> $domainIds
     */
    private function assign(string $table, array $domainIds): void
    {
        // No deleteCache() here on purpose: setSectionDomains() drops it, and
        // clearing it again would hide it if that ever stopped being true.
        $this->fixtures->withAdminUser(static function () use ($table, $domainIds): void {
            Backend::setSectionDomains($table, $domainIds);
        });
    }

    /** Puts the instance's own assignment back, exactly as it was. */
    private function restore(): void
    {
        if ($this->hadConfig) {
            rex_config::set(DomainSettings::ADDON, self::CONFIG_KEY, $this->backup);
        } else {
            rex_config::remove(DomainSettings::ADDON, self::CONFIG_KEY);
        }

        Backend::resetCaches();
        DomainSettings::deleteCache();
    }

    /** A second test tab, built like the fixture one. */
    private function createSection(string $label, string $field, string $type = 'text'): string
    {
        $table = Backend::createSection($label);

        if (null === $table) {
            throw new RuntimeException('Could not create the test section ' . $label);
        }

        rex_yform_manager_table_api::setTableField($table, [
            'prio' => 1,
            'type_id' => 'value',
            'type_name' => $type,
            'name' => $field,
            'label' => $field,
            'list_hidden' => 0,
            'search' => 0,
        ]);

        $managerTable = rex_yform_manager_table::get($table);

        if (null === $managerTable) {
            throw new RuntimeException('Could not load the test section ' . $table);
        }

        rex_yform_manager_table_api::generateTableAndFields($managerTable);
        Backend::resetCaches();
        DomainSettings::deleteCache();

        return $table;
    }

    private function dropSection(string $table): void
    {
        if (null !== rex_yform_manager_table::get($table)) {
            Backend::deleteSection($table);
        }

        Backend::resetCaches();
        DomainSettings::deleteCache();
    }

    /**
     * Writes one row into any test tab.
     *
     * Fixtures::setValues() only ever writes to the fixture table; the tabs
     * this suite creates need the same thing.
     *
     * @param array<string, string> $values
     */
    private function write(string $table, int $domainId, int $clangId, array $values): void
    {
        $dataset = Backend::getDataset($table, [
            'domain_id' => $domainId,
            'clang_id' => $clangId,
        ]);

        foreach ($values as $key => $value) {
            $dataset->setValue($key, $value);
        }

        $dataset->save();
        DomainSettings::deleteCache();
    }

    /**
     * Runs a callback as a user with no permissions at all.
     *
     * Built rather than borrowed, the same way Fixtures::withAdminUser() does
     * it: no role and no admin flag means YForm hands out an empty table
     * permission, which is what the check above needs.
     */
    private function withUserWithoutPermissions(callable $callback): void
    {
        $previous = rex::getUser();

        $sql = rex_sql::factory();
        $sql->setValue('admin', 0);
        // hasRole() asks for it; an empty value keeps it out of the database.
        $sql->setValue('role', '');
        rex::setProperty('user', new rex_user($sql));

        try {
            $callback();
        } finally {
            rex::setProperty('user', $previous);
        }
    }
}
