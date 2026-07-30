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

# Vide aussi l'OPcache du VRAI process web (Apache/PHP-FPM), pas seulement le
# cache de gabarits Twig ci-dessus : ce script tourne en CLI, un SAPI distinct
# de celui qui sert les vraies requetes web, il ne peut donc pas vider LEUR
# OPcache directement. front/opcache_reset.php, lui, s'execute dans le pool
# web ou OPcache est une memoire VRAIMENT partagee entre tous les workers d'un
# meme hote — une seule requete suffit a la vider pour tous, sans redemarrer
# aucun service. Doit venir APRES plugin:activate ci-dessus (pas avant) : le
# endpoint n'est routable que si le plugin est actif, or "plugin:install
# --force" desactive le plugin le temps de la migration. Best-effort (URL
# fixee a localhost, marche tant que le serveur web ecoute sur cette machine,
# ce qui est le cas standard) : si curl/wget sont absents ou que l'appel
# echoue (topologie reseau inhabituelle), le rappel ci-dessous reste le filet
# de securite.
OPCACHE_RESET_OK=0
if command -v curl >/dev/null 2>&1; then
    curl -s -o /dev/null -m 5 "http://localhost/plugins/remise/front/opcache_reset.php" && OPCACHE_RESET_OK=1
elif command -v wget >/dev/null 2>&1; then
    wget -q -O /dev/null -T 5 "http://localhost/plugins/remise/front/opcache_reset.php" && OPCACHE_RESET_OK=1
fi

echo ""
echo "Mise a jour du plugin remise terminee."
if [ "$OPCACHE_RESET_OK" = "1" ]; then
    echo "OPcache PHP vide automatiquement (si actif sur ce serveur) : aucun redemarrage necessaire."
else
    echo "Impossible de vider automatiquement l'OPcache PHP (curl/wget absents, ou le serveur"
    echo "web n'ecoute pas sur cette machine sous 'localhost'). Si un OPcache PHP est actif"
    echo "sur ce serveur (opcache.enable=1 en production, frequent), redemarrez PHP-FPM/Apache"
    echo "maintenant : un simple remplacement des fichiers sur disque ne suffit pas toujours"
    echo "a faire recharger le code PHP."
fi
