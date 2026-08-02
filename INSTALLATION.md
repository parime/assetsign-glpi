# Installation

## Prérequis

- GLPI 11.0.x — **11.0.8 ou plus récent recommandé** (corrige plusieurs failles de sécurité critiques du cœur GLPI lui-même : RCE, injection SQL, contournement MFA — sans lien avec ce plugin, mais applicable à toute instance GLPI 11).
- PHP 8.3+ (testé avec PHP 8.5)
- MariaDB / MySQL
- Un serveur SMTP configuré dans GLPI (pour l'envoi des e-mails de remise et de relance)
- Composer **uniquement si vous développez sur le plugin** (pour relancer les tests, mettre à jour Dompdf...) — **pas nécessaire pour l'installer**, voir ci-dessous.

Le dossier `vendor/` (Dompdf et ses dépendances, ~14 Mo, dépendances de production uniquement) est **commité directement dans ce dépôt**, volontairement, contrairement à la convention habituelle qui l'exclut. Un serveur GLPI de production n'a pas forcément Composer installé : un simple `git clone` (ou une release ZIP) suffit intégralement, sans aucune étape `composer install` sur le serveur cible. `composer.lock` est commité avec, pour que quiconque reconstruit `vendor/` (mise à jour de Dompdf, par exemple) obtienne exactement les mêmes versions. Seules les dépendances de développement (PHPUnit, PHPStan...) restent exclues de ce `vendor/` embarqué : elles n'ont aucune utilité en production — voir [ARCHITECTURE.md](ARCHITECTURE.md#tests-automatisés) pour les réinstaller si besoin.

## Installation

### 1. Récupérer le code sur le serveur GLPI

Deux façons de faire, au choix :

**a) `git clone`** (pratique pour ensuite `git pull` lors des mises à jour) :
```bash
cd /chemin/vers/glpi/plugins
git clone https://github.com/parime/remise-glpi.git remise
```

**b) Archive de release** — téléchargez `remise-glpi-X.Y.Z.zip` depuis la [page des releases](https://github.com/parime/remise-glpi/releases) et extrayez-la dans `plugins/` :
```bash
cd /chemin/vers/glpi/plugins
unzip remise-glpi-X.Y.Z.zip
```
L'archive contient déjà un dossier `remise/` à la racine — pas d'étape supplémentaire de renommage.

Important, dans les deux cas : le dossier doit impérativement s'appeler **`remise`** — GLPI déduit la clé du plugin (`plugin_version_remise()`, etc.) du nom du dossier dans `plugins/`.

### 2. Installer et activer dans GLPI

Depuis l'interface (**Configuration > Plugins**) : trouvez « Remise & signature », cliquez **Installer** puis **Activer**.

Ou en ligne de commande :
```bash
php bin/console plugin:install remise
php bin/console plugin:activate remise
```

### 3. Configurer

Un menu **Administration > Remise & signature** apparaît (Remises, Gabarits de remise, Configuration).

Le formulaire de configuration est organisé en onglets (un par type de fiche, plus un onglet Général et un onglet Compléments), chacun avec un aperçu du PDF qui se met à jour automatiquement dès qu'un réglage change, avant même d'enregistrer :

![Page de configuration avec onglets et aperçu en direct](docs/screenshots/config.png)

**La configuration est indépendante par entité, avec héritage automatique.** Deux façons d'y accéder, qui affichent le même formulaire :
- la page **Administration > Remise & signature > Configuration**, qui édite l'entité actuellement active dans le sélecteur en haut de l'écran ;
- l'onglet **« Remise & signature »** directement sur la fiche d'une entité (Configuration > Entités > *nom de l'entité*), pour éditer précisément la configuration de cette entité-là.

Une entité qui n'a jamais été configurée hérite automatiquement des réglages de son entité parente la plus proche qui en a une (pas forcément la racine — une organisation à plusieurs niveaux peut configurer une entité intermédiaire et voir ses entités enfants en hériter). Une entité peut aussi n'écraser qu'une partie des réglages (par exemple juste son adresse d'expédition) tout en restant sur le même logo que le reste du groupe — voir la case **« Imposer ce logo à toutes les entités enfants »**, qui permet justement à une entité parente de forcer un réglage précis sur toute sa descendance même quand celle-ci a sa propre configuration.

Depuis ce formulaire, réglez notamment :

- l'adresse d'expédition, les délais de relance, la durée de validité du lien de signature,
- **les types de matériel gérés** : décochez ce que vous ne voulez pas voir passer par le plugin (par défaut : ordinateurs, écrans, périphériques, téléphones). Vos actifs personnalisés apparaissent aussi automatiquement dans cette liste dès qu'ils sont actifs.
- **les déclencheurs par affectation** (première affectation, réaffectation, restitution) — basés sur le champ "Utilisateur" du matériel, ce qui convient à la plupart des GLPI. Un transfert direct entre deux personnes est traité comme une remise normale au nouveau détenteur.
- **les déclencheurs par État** (optionnel) — si votre organisation pilote plutôt le cycle de vie du matériel via son État (ex. "En prêt" / "Disponible") que via l'affectation directe, choisissez ici, parmi vos propres États existants, ceux qui doivent déclencher une remise, une restitution, un don ou une vente.

Si les deux mécanismes sont configurés et se déclenchent en même temps, une seule remise est créée — le déclenchement par affectation est prioritaire.

Vérifiez aussi que les notifications GLPI sont actives (**Configuration > Notifications**, mode "Email"), et que le serveur SMTP est configuré.

## Vérifier que ça fonctionne

1. Affectez un ordinateur à un utilisateur ayant **un compte GLPI actif et une adresse e-mail valide**.
2. Une entrée apparaît dans **Remise & signature > Remises** avec le statut « Envoyé ».
3. L'utilisateur reçoit un e-mail avec un lien vers la page de signature.
4. S'il n'est pas déjà connecté, GLPI le redirige vers la page de connexion, puis le ramène sur le lien d'origine une fois authentifié.
5. Il consulte le PDF, signe à l'écran, valide.
6. Le PDF signé apparaît dans l'onglet **Documents** du matériel *et* de la fiche utilisateur ; le statut de la remise passe à « Signé ».

## Mettre à jour le plugin

Une modification du code (nouvelle fonctionnalité, correctif) ne se signale jamais automatiquement côté GLPI — ce dépôt est hors du Marketplace officiel. La marche à suivre :

1. Sur le serveur GLPI : `cd plugins/remise && git pull` (ou re-téléchargez l'archive ZIP en remplaçant le dossier).
2. Lancez `sh update.sh` (depuis `plugins/remise/`) : ce script regroupe les trois étapes qu'il est facile d'oublier ou de faire dans le mauvais ordre — migration de la base (`plugin:install --force`, sans risque si déjà à jour), réactivation, et vidage du cache GLPI (`cache:clear`).

Le détail de ce que fait `update.sh`, et pourquoi chaque étape est nécessaire :
- **Migration de la base** (`php bin/console plugin:install remise --force`) : nécessaire si le changement ajoute une table ou un champ. GLPI ne le fait jamais tout seul après un simple remplacement de fichiers, même si le numéro de version dans `setup.php` (`PLUGIN_REMISE_VERSION`) a été incrémenté (il l'est systématiquement à chaque changement de structure) — sans cette étape, GLPI affiche bien le plugin comme "à mettre à jour" sur **Configuration > Plugins**, mais les nouvelles tables/colonnes n'existent pas tant que le bouton (ou cette commande) n'a pas été actionné.
- **Vidage du cache** (`php bin/console cache:clear`) : indispensable dès qu'un fichier `.twig` a changé (gabarit de PDF, de page de configuration, d'e-mail...). En environnement de production réel (pas un `git clone` sur poste de dev), GLPI désactive volontairement l'auto-rechargement de Twig (`Glpi\Application\Environment::shouldExpectResourcesToChange()` renvoie `false`) : sans ce vidage, l'ancienne version compilée du gabarit continue d'être servie indéfiniment, **sans aucune erreur ni avertissement** — le nouveau fichier est bien sur le disque, mais jamais rendu. Piège rencontré en conditions réelles (constaté après une mise à jour où la page de configuration affichait encore l'ancien texte d'aperçu, sans aucun onglet, malgré un `git pull` réussi et le bon numéro de version sur disque) et documenté dans [TROUBLESHOOTING.md](TROUBLESHOOTING.md).
- **OPcache**, si activé sur le serveur (fréquent en production, indépendant du cache GLPI ci-dessus) : `update.sh` tente désormais de le vider automatiquement lui-même (3 essais avec courte pause) en appelant `front/opcache_reset.php` juste après la réactivation du plugin — ce endpoint s'exécute dans le pool web (Apache/PHP-FPM), où OPcache est une mémoire réellement partagée entre tous les workers, contrairement au processus CLI de `update.sh` lui-même. Si cet appel échoue (serveur web injoignable en `localhost`, `curl`/`wget` absents...), `update.sh` l'indique clairement et affiche alors le rappel habituel : un redémarrage manuel de PHP-FPM/Apache peut rester nécessaire pour que le nouveau **code PHP** soit réellement rechargé (droits root généralement requis, `update.sh` ne peut pas le faire lui-même dans ce cas).
