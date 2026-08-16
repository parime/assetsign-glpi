<?php

namespace GlpiPlugin\Assetsign\Console;

use GlpiPlugin\Assetsign\Assetsign;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Alternative au CronTask GLPI (Assetsign::cronAssetsignExpire), cf.
 * RunRemindersCommand pour le contexte d'usage.
 *
 * Usage : php bin/console plugins:assetsign:run-expiration
 */
class RunExpirationCommand extends Command
{
   protected function configure(): void {
       $this->setName('plugins:assetsign:run-expiration');
       $this->setDescription('Marque comme expirées les remises hors délai (alternative au CronTask GLPI)');
   }

   protected function execute(InputInterface $input, OutputInterface $output): int {
       $count = Assetsign::runExpiration();
       $output->writeln(sprintf('%d assetsign(s) marquée(s) comme expirée(s).', $count));

       return Command::SUCCESS;
   }
}
