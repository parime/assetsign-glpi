<?php

namespace GlpiPlugin\Remise\Console;

use GlpiPlugin\Remise\Remise;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Alternative au CronTask GLPI (Remise::cronRemiseExpire), cf.
 * RunRemindersCommand pour le contexte d'usage.
 *
 * Usage : php bin/console plugins:remise:run-expiration
 */
class RunExpirationCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:remise:run-expiration');
        $this->setDescription('Marque comme expirées les remises hors délai (alternative au CronTask GLPI)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = Remise::runExpiration();
        $output->writeln(sprintf('%d remise(s) marquée(s) comme expirée(s).', $count));

        return Command::SUCCESS;
    }
}
