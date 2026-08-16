<?php

namespace GlpiPlugin\Assetsign\Console;

use GlpiPlugin\Assetsign\Assetsign;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Alternative au CronTask GLPI (Assetsign::cronAssetsignReminders), pour les
 * organisations qui prefereraient piloter les relances depuis un
 * ordonnanceur externe (cron systeme, tache planifiee Kubernetes, etc.)
 * plutot que de dependre du declenchement interne de GLPI.
 *
 * Usage : php bin/console plugins:assetsign:run-reminders
 *
 * Le CronTask GLPI reste actif par defaut ; si vous utilisez cette commande
 * depuis un ordonnanceur externe, desactivez le CronTask correspondant
 * (Administration > Actions automatiques > "assetsignReminders") pour eviter
 * un double envoi des relances.
 */
class RunRemindersCommand extends Command
{
   protected function configure(): void {
       $this->setName('plugins:assetsign:run-reminders');
       $this->setDescription('Envoie les relances de signature dues (alternative au CronTask GLPI)');
   }

   protected function execute(InputInterface $input, OutputInterface $output): int {
       $count = Assetsign::runReminders();
       $output->writeln(sprintf('%d relance(s) envoyée(s).', $count));

       return Command::SUCCESS;
   }
}
