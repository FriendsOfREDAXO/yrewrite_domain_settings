<?php

namespace FriendsOfRedaxo\DomainSettings\Command;

use FriendsOfRedaxo\DomainSettings\Test\AbstractSuite;
use FriendsOfRedaxo\DomainSettings\Test\AssertionFailed;
use FriendsOfRedaxo\DomainSettings\Test\Fixtures;
use FriendsOfRedaxo\DomainSettings\Tests\CacheSuite;
use FriendsOfRedaxo\DomainSettings\Tests\FallbackSuite;
use FriendsOfRedaxo\DomainSettings\Tests\LegacyApiSuite;
use FriendsOfRedaxo\DomainSettings\Tests\SectionsSuite;
use FriendsOfRedaxo\DomainSettings\Tests\SecuritySuite;
use ReflectionClass;
use ReflectionMethod;
use rex_console_command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function count;
use function sprintf;

/**
 * Runs the test suites against the current instance.
 *
 * Console command rather than PHPUnit, following YForm - which states it
 * plainly in its own test README: "Tests run as REDAXO console commands.
 * PHPUnit is not required." These tests need rex_clang, the file cache, YForm
 * tables and rex_var::parse(), none of which is worth mocking.
 *
 * YForm's own runner cannot be reused: its SuiteRegistry::all() is a hard-coded
 * class list that a foreign addon cannot register with.
 */
class TestCommand extends rex_console_command
{
    /** @var array<string, class-string<AbstractSuite>> */
    private const SUITES = [
        'fallback' => FallbackSuite::class,
        'sections' => SectionsSuite::class,
        'cache' => CacheSuite::class,
        'security' => SecuritySuite::class,
        'legacy' => LegacyApiSuite::class,
    ];

    protected function configure(): void
    {
        $this
            ->setDescription('Runs the test suites against this instance')
            ->addArgument('suite', InputArgument::OPTIONAL, 'Only this suite: ' . implode(', ', array_keys(self::SUITES)))
            ->setHelp(
                "Test data lives in a section of its own that is created and dropped around the run,\n"
                . 'and uses domain id ' . Fixtures::DOMAIN_ID . ' - editorial content is never touched.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getArgument('suite');

        if (null !== $only && !isset(self::SUITES[$only])) {
            $io->error('Unknown suite "' . $only . '". Available: ' . implode(', ', array_keys(self::SUITES)));
            return self::FAILURE;
        }

        $fixtures = new Fixtures();
        $passed = 0;
        $failures = [];

        try {
            $fixtures->setUp();

            foreach (self::SUITES as $key => $class) {
                if (null !== $only && $key !== $only) {
                    continue;
                }

                $suite = new $class($fixtures);
                $io->section($suite->getTitle());
                $suite->setUpBeforeClass();

                foreach ($this->testMethods($class) as $method) {
                    $suite->setUp();

                    try {
                        $suite->{$method}();
                        $io->writeln('  <info>ok</info>   ' . $this->humanise($method));
                        ++$passed;
                    } catch (AssertionFailed $e) {
                        $io->writeln('  <error>FAIL</error> ' . $this->humanise($method));
                        $io->writeln('       ' . $e->getMessage());
                        $failures[] = $suite->getTitle() . ' / ' . $this->humanise($method) . ': ' . $e->getMessage();
                    } catch (Throwable $e) {
                        $io->writeln('  <error>ERR</error>  ' . $this->humanise($method));
                        $io->writeln('       ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
                        $failures[] = $suite->getTitle() . ' / ' . $this->humanise($method) . ': ' . $e->getMessage();
                    } finally {
                        $suite->tearDown();
                    }
                }

                $suite->tearDownAfterClass();
            }
        } catch (Throwable $e) {
            $io->error('Setup failed: ' . $e->getMessage());
            $fixtures->tearDown();
            return self::FAILURE;
        } finally {
            // Always clean up, even when a test blew up halfway through.
            $fixtures->tearDown();
        }

        $io->newLine();

        if ([] !== $failures) {
            $io->error(sprintf('%d passed, %d failed', $passed, count($failures)));
            $io->listing($failures);
            return self::FAILURE;
        }

        $io->success(sprintf('%d checks passed', $passed));

        return self::SUCCESS;
    }

    /**
     * @param class-string<AbstractSuite> $class
     *
     * @return list<string>
     */
    private function testMethods(string $class): array
    {
        $methods = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'test')) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }

    private function humanise(string $method): string
    {
        return strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', substr($method, 4))));
    }
}
