<?php

namespace GlpiPlugin\Remise\Console;

use GlpiPlugin\Remise\Remise;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Alternative au CronTask GLPI (Remise::cronRemiseExpiryWarning), cf.
 * RunRemindersCommand pour le contexte d'usage.
 *
 * Usage : php bin/console plugins:remise:warn-expiring
 */
class WarnExpiringCommand extends Command
{
   protected function configure(): void {
       $this->setName('plugins:remise:warn-expiring');
       $this->setDescription('Alerte le technicien des remises sur le point d\'expirer (alternative au CronTask GLPI)');
   }

   protected function execute(InputInterface $input, OutputInterface $output): int {
       $count = Remise::runExpiryWarnings();
       $output->writeln(sprintf('%d alerte(s) de pré-expiration envoyée(s).', $count));

       return Command::SUCCESS;
   }
}
