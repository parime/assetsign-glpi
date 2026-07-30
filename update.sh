#!/bin/sh
# A lancer depuis ce dossier (plugins/remise/) apres chaque "git pull"/mise a jour
# du code : regroupe les 3 etapes decrites dans le README (section "Mettre a
# jour le plugin") qu'il est facile d'oublier ou de faire dans le mauvais ordre :
# migration de la base, puis vidage du cache Twig/traductions de GLPI.
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
GLPI_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

cd "$GLPI_ROOT"
php bin/console plugin:install remise --force
php bin/console plugin:activate remise
php bin/console cache:clear

echo ""
echo "Mise a jour du plugin remise terminee."
echo "Si un OPcache PHP est actif sur ce serveur (opcache.enable=1 en production,"
echo "frequent), redemarrez PHP-FPM/Apache maintenant : un simple remplacement"
echo "des fichiers sur disque ne suffit pas toujours a faire recharger le code PHP."
