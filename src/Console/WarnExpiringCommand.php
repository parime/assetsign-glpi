<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

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
