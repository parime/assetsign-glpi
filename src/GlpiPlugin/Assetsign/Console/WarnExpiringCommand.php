<?php

namespace GlpiPlugin\Assetsign\Console;

use GlpiPlugin\Assetsign\Assetsign;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Alternative au CronTask GLPI (Assetsign::cronAssetsignExpiryWarning), cf.
 * RunRemindersCommand pour le contexte d'usage.
 *
 * Usage : php bin/console plugins:assetsign:warn-expiring
 */
class WarnExpiringCommand extends Command
{
   protected function configure(): void {
       $this->setName('plugins:assetsign:warn-expiring');
       $this->setDescription('Alerte le technicien des attributions sur le point d\'expirer (alternative au CronTask GLPI)');
   }

   protected function execute(InputInterface $input, OutputInterface $output): int {
       $count = Assetsign::runExpiryWarnings();
       $output->writeln(sprintf('%d alerte(s) de pré-expiration envoyée(s).', $count));

       return Command::SUCCESS;
   }
}
