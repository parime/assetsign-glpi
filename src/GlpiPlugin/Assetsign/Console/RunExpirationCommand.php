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
 * Alternative au CronTask GLPI (Assetsign::cronAssetsignExpire), cf.
 * RunRemindersCommand pour le contexte d'usage.
 *
 * Usage : php bin/console plugins:assetsign:run-expiration
 */
class RunExpirationCommand extends Command
{
   protected function configure(): void {
       $this->setName('plugins:assetsign:run-expiration');
       $this->setDescription('Marque comme expirées les attributions hors délai (alternative au CronTask GLPI)');
   }

   protected function execute(InputInterface $input, OutputInterface $output): int {
       $count = Assetsign::runExpiration();
       $output->writeln(sprintf('%d assetsign(s) marquée(s) comme expirée(s).', $count));

       return Command::SUCCESS;
   }
}
