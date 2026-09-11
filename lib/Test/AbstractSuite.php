<?php

namespace FriendsOfRedaxo\DomainSettings\Test;

use ReflectionClass;

/**
 * Base class for test suites. Subclass and add public test* methods.
 *
 * Lifecycle: setUpBeforeClass() -> [setUp() -> testFoo() -> tearDown()]* ->
 * tearDownAfterClass(). Same shape as YForm's AbstractTestSuite, so anyone who
 * has written tests for YForm will recognise it.
 */
abstract class AbstractSuite
{
    public function __construct(
        protected readonly Fixtures $fixtures,
    ) {}

    public function setUpBeforeClass(): void {}

    public function tearDownAfterClass(): void {}

    public function setUp(): void {}

    public function tearDown(): void {}

    /** Human-readable name, used in the console output. */
    public function getTitle(): string
    {
        $short = (new ReflectionClass(static::class))->getShortName();

        return preg_replace('/Suite$/', '', $short) ?: $short;
    }
}
