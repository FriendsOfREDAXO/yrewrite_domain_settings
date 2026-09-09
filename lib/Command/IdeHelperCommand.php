<?php

namespace FriendsOfRedaxo\DomainSettings\Command;

use FriendsOfRedaxo\DomainSettings\Backend;
use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refreshes .phpstorm.meta.php so the IDE completes global field names.
 *
 * The file is also refreshed on cache:clear, so this command is only needed
 * when you want it right now without clearing the cache.
 */
class IdeHelperCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this->setDescription('Refreshes .phpstorm.meta.php so the IDE completes global field names');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $written = Backend::writeIdeHelper();

        if (-1 === $written) {
            $io->error('Could not write .phpstorm.meta.php');
            return self::FAILURE;
        }

        if (0 === $written) {
            $io->warning('No fields defined yet - nothing to complete.');
            return self::SUCCESS;
        }

        $io->success($written . ' field name(s) written.');
        $io->listing(Backend::getFieldNames());

        return self::SUCCESS;
    }
}
