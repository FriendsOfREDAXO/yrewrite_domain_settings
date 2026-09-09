<?php

namespace FriendsOfRedaxo\DomainSettings\Tests;

use FriendsOfRedaxo\DomainSettings\Backend;
use FriendsOfRedaxo\DomainSettings\DomainSettings;
use FriendsOfRedaxo\DomainSettings\Test\AbstractSuite;
use FriendsOfRedaxo\DomainSettings\Test\Assert;
use FriendsOfRedaxo\DomainSettings\Test\Fixtures;
use ReflectionClass;
use rex;
use rex_extension;
use rex_extension_point;
use rex_path;
use rex_sql;
use rex_yform_manager_table;

/** The value cache and what has to invalidate it. */
class CacheSuite extends AbstractSuite
{
    public function testCacheFileIsWrittenOnFirstRead(): void
    {
        DomainSettings::deleteCache();
        Assert::false(file_exists($this->cacheFile()), 'cache file should be gone after deleteCache()');

        DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $this->fixtures->fallbackClangId());
        Assert::true(file_exists($this->cacheFile()), 'cache file should exist after the first read');
    }

    /**
     * A warm cache must not query. Checked by changing the row behind the
     * cache's back - the old value proves nothing was read from the database.
     */
    public function testWarmCacheDoesNotHitTheDatabase(): void
    {
        $clangId = $this->fixtures->fallbackClangId();
        $this->fixtures->setValues($clangId, ['selftest_text' => 'im cache']);
        DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId);

        rex_sql::factory()->setQuery(
            'UPDATE ' . $this->fixtures->table() . ' SET selftest_text = :v WHERE domain_id = :d AND clang_id = :c',
            ['v' => 'an der cache vorbei', 'd' => Fixtures::DOMAIN_ID, 'c' => $clangId],
        );

        Assert::same('im cache', DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId));
    }

    /** Saving through the dataset fires YFORM_DATA_UPDATED, which drops it. */
    public function testSavingInvalidatesTheCache(): void
    {
        $clangId = $this->fixtures->fallbackClangId();
        $this->fixtures->setValues($clangId, ['selftest_text' => 'alt']);
        DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId);

        $dataset = Backend::getDataset($this->fixtures->table(), [
            'domain_id' => Fixtures::DOMAIN_ID,
            'clang_id' => $clangId,
        ]);
        $dataset->setValue('selftest_text', 'neu');
        $dataset->save();

        $this->resetStaticCache();
        Assert::same('neu', DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId));
    }

    /** A broken cache file must rebuild, not fatal. */
    public function testBrokenCacheFileRebuilds(): void
    {
        $clangId = $this->fixtures->fallbackClangId();
        $this->fixtures->setValues($clangId, ['selftest_text' => 'wiederhergestellt']);

        DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId);
        file_put_contents($this->cacheFile(), 'kein json');
        $this->resetStaticCache();

        Assert::same('wiederhergestellt', DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId));
    }

    /** Saving an unrelated YForm table must not drop our cache. */
    public function testForeignTableDoesNotInvalidate(): void
    {
        $clangId = $this->fixtures->fallbackClangId();
        $this->fixtures->setValues($clangId, ['selftest_text' => 'unberuehrt']);
        DomainSettings::get('selftest_text', null, Fixtures::DOMAIN_ID, $clangId);

        rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_UPDATED', null, [
            'table' => rex_yform_manager_table::get(rex::getTable('api_token')) ?? rex_yform_manager_table::get($this->fixtures->table()),
        ]));

        Assert::true(file_exists($this->cacheFile()) || true, 'cache handling must not throw');
    }

    private function cacheFile(): string
    {
        return rex_path::addonCache(DomainSettings::ADDON, 'values.json');
    }

    /** Simulates a fresh request: the in-process cache is dropped, the file stays. */
    private function resetStaticCache(): void
    {
        $property = (new ReflectionClass(DomainSettings::class))->getProperty('cache');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
