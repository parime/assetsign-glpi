# Plugin GLPI `remise` — Remise de matériel & signature électronique

<p align="center"><img src="docs/banner.png" alt="Remise de matériel — Signature, suivi, traçabilité" width="180"></p>

Créé par **Vincent Guillotte**, avec l'aide de [Claude Code](https://claude.com/claude-code).

## Sommaire

- [Qu'est-ce que ce plugin ?](#quest-ce-que-ce-plugin-)
- [Aperçu](#aperçu)
- [Comment ça marche, vu par chaque personne](#comment-ça-marche-vu-par-chaque-personne)
- [Fonctionnalités](#fonctionnalités)
- [Ce qui n'est *pas* encore implémenté](#ce-qui-nest-pas-encore-implémenté)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Vérifier que ça fonctionne](#vérifier-que-ça-fonctionne)
- [Alternative au cron GLPI](#alternative-au-cron-glpi)
- [Tableau de bord](#tableau-de-bord)
- [Mettre à jour le plugin](#mettre-à-jour-le-plugin)
- [Pour les développeurs et contributeurs](#pour-les-développeurs-et-contributeurs)
- [Licence](#licence)

## Qu'est-ce que ce plugin ?

Quand un ordinateur, un écran, un téléphone ou tout autre matériel est remis à quelqu'un dans l'entreprise, il faut souvent une trace écrite : que le bénéficiaire reconnaît avoir reçu tel matériel, dans tel état, à telle date, et qu'il accepte les conditions d'usage (charte informatique, conditions générales...). En pratique, cette étape est souvent oubliée, faite sur papier et jamais archivée, ou gérée à la main dans un tableur.

Ce plugin l'automatise entièrement à partir de GLPI, l'outil que vos techniciens utilisent déjà pour gérer le parc :

1. Un technicien affecte un matériel à un utilisateur dans GLPI (comme il le fait déjà normalement).
2. Le plugin le détecte automatiquement et génère une **fiche de remise en PDF** (matériel, bénéficiaire, accessoires, conditions générales, charte informatique).
3. Le bénéficiaire reçoit un **e-mail** avec un lien vers cette fiche.
4. Il se connecte à GLPI avec son propre compte, **consulte le PDF et signe à l'écran** (souris, doigt ou stylet).
5. Le **PDF signé** est automatiquement archivé — sur la fiche du matériel *et* sur la fiche de l'utilisateur, consultable à tout moment.
6. Si le bénéficiaire ne signe pas, le plugin **relance automatiquement** puis marque le document comme expiré au bout d'un délai configurable.

Le même mécanisme fonctionne aussi en sens inverse : quand un matériel est **restitué** (désaffecté), une fiche de restitution peut être générée et signée de la même façon.

**Sécurité** : la page de signature n'est jamais accessible par un simple lien anonyme — il faut être connecté à GLPI, et le lien ne fonctionne que pour le bénéficiaire réel du document. Un autre utilisateur authentifié qui obtiendrait le lien par erreur (transfert d'e-mail, etc.) se voit refuser l'accès.

> Ce plugin a été conçu et **validé de bout en bout** dans un environnement GLPI 11.0.7 réel (Docker) : affectation → détection → génération PDF → e-mail → connexion → consultation → signature → rattachement — chaque étape a été testée avec de vraies requêtes contre une instance GLPI, y compris les cas de refus d'accès.

## Aperçu

**Le paramétrage, organisé par onglet, avec aperçu du PDF en direct** (avant même d'enregistrer) :

![Page de configuration avec onglets et aperçu en direct](docs/screenshots/config.png)

**La fiche d'une remise, côté technicien** — statut, observations, état des lieux visuel avec repères de dommage, accessoires remis :

![Fiche de remise avec état des lieux visuel et accessoires](docs/screenshots/remise-fiche.png)

**La page de signature, côté bénéficiaire** — consultation du PDF, état des lieux visuel, remarque libre, signature à l'écran :

![Page de signature côté bénéficiaire](docs/screenshots/sign-page.png)

**Le PDF généré**, identique à ce que l'aperçu en direct affichait déjà avant signature :

![PDF de remise généré](docs/screenshots/pdf-genere.png)

**Une fiche de maintenance interne**, avec un type de saisie propre à chaque point de contrôle (case à cocher, texte libre, menu déroulant) :

![Formulaire de fiche de maintenance avec types de saisie variés](docs/screenshots/maintenance.png)

## Comment ça marche, vu par chaque personne

**L'administrateur** configure une fois pour toutes (Configuration > Remise & signature) : quels types de matériel sont concernés, quand une signature est déclenchée (affectation, réaffectation, restitution, ou changement d'État), les délais de relance, le logo et la charte à afficher sur les PDF.

**Le technicien** n'a rien de spécial à faire : il continue d'affecter le matériel dans GLPI comme d'habitude. Le plugin se charge du reste. Il peut consulter à tout moment l'historique des remises (menu Administration > Remise & signature > Remises) et relancer manuellement un bénéficiaire qui n'a pas encore signé.

**Le bénéficiaire** reçoit un e-mail, clique sur le lien, se connecte à GLPI s'il ne l'est pas déjà, relit le document et signe à l'écran. Une fois signé, il retrouve le PDF signé dans l'onglet Documents de son profil.

## Fonctionnalités

- Détection automatique de l'affectation/réaffectation/restitution d'un matériel, sans action manuelle du technicien.
- Génération PDF (fiche de remise ou de restitution) avec gabarits personnalisables (conditions générales, charte informatique) — un nouveau gabarit part d'un texte par défaut modifiable plutôt que d'un champ vide.
- Signature à l'écran intégrée (aucun service externe requis, aucun coût de licence) — souris, doigt ou stylet, avec prévisualisation du PDF avant de signer.
- Relances automatiques en cas d'inaction, avec limite de nombre configurable, puis expiration automatique du lien passé un certain délai.
- Relance manuelle à tout moment depuis la fiche d'une remise, ou en action groupée sur plusieurs remises sélectionnées depuis la liste (Administration > Remise & signature > Remises).
- Alerte au technicien quelques jours avant l'expiration réelle du lien (configurable par entité, désactivable) — pour pouvoir relancer le bénéficiaire autrement (appel, passage sur place) avant qu'il ne soit trop tard, plutôt que d'apprendre l'inaction une fois le lien déjà expiré.
- Rattachement automatique du PDF signé au matériel *et* à l'utilisateur (onglet Documents natif de GLPI).
- Preuve de signature consultable directement sur la fiche de chaque remise signée (menu Administration > Remise & signature > Remises) : signataire, adresse IP, navigateur, empreinte SHA-256 du document — utile en cas de contestation, sans avoir à rouvrir le PDF pour retrouver ces informations.
- Historique complet et horodaté de chaque remise, avec preuve de signature (adresse IP, date/heure, empreinte du document).
- Accessoires remis (chargeur, sacoche, écran additionnel...) : catalogue configurable, associable à chaque remise avec quantité et commentaire.
- Marque et modèle du matériel affichés automatiquement sur le PDF quand l'information existe dans GLPI.
- Logo de l'entreprise personnalisable par simple envoi de fichier depuis le poste de l'administrateur — aperçu en direct du rendu final avant même d'enregistrer. Utile pour un GLPI partagé entre plusieurs sociétés/marques : chaque entité peut avoir son propre logo, et une entité (ex. la racine, ou une filiale) peut cocher « Imposer ce logo à toutes les entités enfants » pour forcer le même logo sur toute sa descendance, y compris les sous-entités de ses entités enfants — même si celles-ci ont déjà envoyé leur propre logo.
- Lien vers la charte informatique complète configurable par entité (utile si plusieurs sociétés/sites hébergent leur charte à des endroits différents), en plus d'un texte de charte propre à chaque gabarit.
- Fonctionne avec les **actifs personnalisés** créés dans GLPI (Configuration > Actifs personnalisés), en plus des types standards (ordinateurs, écrans, périphériques, téléphones) — aucune modification du plugin nécessaire.
- Configuration indépendante par entité, avec héritage automatique (une entité sans réglage propre hérite de celui de son entité parente la plus proche).
- Interface disponible en français et en anglais (détectée automatiquement selon la langue du compte GLPI du destinataire pour les e-mails).
- Tableau de bord GLPI natif : trois indicateurs (remises en attente / signées / expirées), filtrés automatiquement selon l'entité active, comme les autres widgets GLPI.
- Menu dédié **Administration > Remise & signature > Gestion des fiches** : vue transverse de toutes les remises/restitutions (tous matériels et bénéficiaires confondus), avec téléchargement direct du PDF (non signé et signé) et annulation d'une ou plusieurs demandes en attente (individuellement ou en action groupée).
- Conditions générales et charte informatique activables **indépendamment** sur chaque gabarit (deux cases à cocher) : un gabarit peut par exemple n'afficher que la charte, ou aucune des deux sections, sans avoir à vider le texte.
- Bouton « Retour à l'accueil GLPI » proposé au bénéficiaire une fois sa signature enregistrée (et sur l'écran d'erreur d'un lien invalide/expiré), pour ne pas le laisser sur une page sans issue.
- Objet et corps des e-mails adaptés automatiquement au type de fiche (remise ou restitution) via la balise `##remise.type##` — un seul jeu de notifications pour tous les types, au lieu d'un texte figé qui parlait toujours de « remise » même pour une restitution.
- Champ « Observations » libre et optionnel (désactivé par défaut, activable par entité) : permet de noter un état constaté du matériel, repris sur le PDF tant que la fiche n'est pas signée.
- **Don de matériel** et **Vente de matériel** : deux workflows supplémentaires, désactivables par entité, déclenchables soit manuellement depuis l'onglet Remise d'un matériel (boutons dédiés « Créer une fiche de don »/« Créer une fiche de vente »), soit **automatiquement par changement d'État** (comme Remise/Restitution), au choix de l'administrateur parmi ses propres États. La Vente ajoute un prix et une date de vente, repris sur le PDF dès qu'ils sont renseignés — quand la Vente est déclenchée automatiquement (prix inconnu à cet instant), la fiche n'y fait simplement pas encore référence, et le prix/la date restent modifiables après coup depuis la fiche.
- **Bénéficiaire interne ou externe** (Don/Vente uniquement) : la création manuelle propose un choix — un compte GLPI existant (workflow signé habituel, e-mail + signature à l'écran), ou une personne/organisation **externe à l'entreprise** (nom et contact en texte libre, aucune signature électronique requise puisqu'un tiers sans compte GLPI ne peut pas se connecter pour signer — le PDF généré fait alors directement foi). Si un changement d'État déclenche automatiquement un don/une vente sur un matériel **sans utilisateur assigné** (aucun bénéficiaire interne connu), le plugin ne peut rien créer tout seul mais affiche un message invitant à créer la fiche manuellement avec le bon bénéficiaire.
- **État des lieux visuel** (désactivable par entité) : 3 vues de référence (arrière, avant, dessous) toujours affichées sur la fiche dès que le réglage est actif — un technicien (depuis la fiche admin) **ou le bénéficiaire lui-même (depuis sa page de signature, avant de signer)** peut cliquer dessus pour déposer un repère, avec description et gravité optionnelles, repris sur le PDF.
- **Remarque libre du bénéficiaire** : un champ texte optionnel sur la page de signature, que le bénéficiaire remplit lui-même avant de signer (ex. signaler un souci constaté à la réception) — repris sur le PDF sous "Remarque du bénéficiaire", et visible en lecture seule sur la fiche admin.
- **Fiches de maintenance/préparation** : formulaire interne (non signé, sans bénéficiaire) avec une checklist de points de contrôle entièrement configurable par l'administrateur (Configuration > Intitulés) et un commentaire libre — accessible depuis l'onglet Maintenance de chaque matériel *et* directement depuis la liste des fiches (Outils > Fiches de maintenance > Nouvelle fiche, avec sélection du matériel). Chaque point de contrôle définit son propre **type de saisie** (case à cocher, texte libre, ou menu déroulant avec des options définies par l'administrateur) — pas seulement des cases à cocher.
- **Aperçu du PDF en temps réel** sur les pages de configuration et de gabarit : cocher/décocher une case ou modifier un texte met à jour l'aperçu affiché, avant même d'enregistrer — rendu strictement identique au vrai PDF (mêmes gabarits Twig, mêmes données).
- **Paramétrage organisé par onglet** (Configuration > Remise & signature > Configuration) : un onglet par type de fiche (Général, Remise, Restitution, Don, Vente, Compléments, Maintenance), chacun avec ses propres réglages, son aperçu, et un lien direct vers ses gabarits — un seul formulaire, un seul enregistrement malgré la navigation par onglets.

## Ce qui n'est *pas* encore implémenté

- **Fournisseur de signature externe** (Yousign, DocuSeal...) pour un niveau de signature électronique renforcé (eIDAS "avancée"/"qualifiée"). Seule la signature à l'écran intégrée est disponible aujourd'hui — elle correspond à un niveau de signature électronique "simple", pas à une signature cryptographique.
- Couverture de tests automatisés : volontairement partielle aujourd'hui, à étendre au fil des évolutions (voir [ARCHITECTURE.md](ARCHITECTURE.md#tests-automatisés)).

## Prérequis

- GLPI 11.0.x
- PHP 8.3+ (testé avec PHP 8.5)
- MariaDB / MySQL
- Un serveur SMTP configuré dans GLPI (pour l'envoi des e-mails de remise et de relance)
- Composer **uniquement si vous développez sur le plugin** (pour relancer les tests, mettre à jour Dompdf...) — **pas nécessaire pour l'installer**, voir ci-dessous.

Le dossier `vendor/` (Dompdf et ses dépendances, ~14 Mo, dépendances de production uniquement) est **commité directement dans ce dépôt**, volontairement, contrairement à la convention habituelle qui l'exclut. Un serveur GLPI de production n'a pas forcément Composer installé : un simple `git clone` (ou une release ZIP) suffit intégralement, sans aucune étape `composer install` sur le serveur cible. `composer.lock` est commité avec, pour que quiconque reconstruit `vendor/` (mise à jour de Dompdf, par exemple) obtienne exactement les mêmes versions. Seules les dépendances de développement (PHPUnit) restent exclues de ce `vendor/` embarqué : elles n'ont aucune utilité en production — voir [ARCHITECTURE.md](ARCHITECTURE.md#tests-automatisés) pour les réinstaller si besoin.

## Installation

### 1. Récupérer le code sur le serveur GLPI

Le dépôt est public : un simple `git clone` suffit, sans authentification :

```bash
cd /chemin/vers/glpi/plugins
git clone https://github.com/parime/remise-glpi.git remise
```

Important : le dossier doit impérativement s'appeler **`remise`** — GLPI déduit la clé du plugin (`plugin_version_remise()`, etc.) du nom du dossier dans `plugins/`.

### 2. Installer et activer dans GLPI

Depuis l'interface (**Configuration > Plugins**) : trouvez « Remise & signature », cliquez **Installer** puis **Activer**.

Ou en ligne de commande :
```bash
php bin/console plugin:install remise
php bin/console plugin:activate remise
```

### 3. Configurer

Un menu **Administration > Remise & signature** apparaît (Remises, Gabarits de remise, Configuration).

**La configuration est indépendante par entité, avec héritage automatique.** Deux façons d'y accéder, qui affichent le même formulaire :
- la page **Administration > Remise & signature > Configuration**, qui édite l'entité actuellement active dans le sélecteur en haut de l'écran ;
- l'onglet **« Remise & signature »** directement sur la fiche d'une entité (Configuration > Entités > *nom de l'entité*), pour éditer précisément la configuration de cette entité-là.

Une entité qui n'a jamais été configurée hérite automatiquement des réglages de son entité parente la plus proche qui en a une (pas forcément la racine — une organisation à plusieurs niveaux peut configurer une entité intermédiaire et voir ses entités enfants en hériter). Une entité peut aussi n'écraser qu'une partie des réglages (par exemple juste son adresse d'expédition) tout en restant sur le même logo que le reste du groupe — voir plus bas la case **« Imposer ce logo à toutes les entités enfants »**, qui permet justement à une entité parente de forcer un réglage précis sur toute sa descendance même quand celle-ci a sa propre configuration.

Depuis ce formulaire, réglez notamment :

- l'adresse d'expédition, les délais de relance, la durée de validité du lien de signature,
- **les types de matériel gérés** : décochez ce que vous ne voulez pas voir passer par le plugin (par défaut : ordinateurs, écrans, périphériques, téléphones). Vos actifs personnalisés apparaissent aussi automatiquement dans cette liste dès qu'ils sont actifs.
- **les déclencheurs par affectation** (première affectation, réaffectation, restitution) — basés sur le champ "Utilisateur" du matériel, ce qui convient à la plupart des GLPI. Un transfert direct entre deux personnes est traité comme une remise normale au nouveau détenteur.
- **les déclencheurs par État** (optionnel) — si votre organisation pilote plutôt le cycle de vie du matériel via son État (ex. "En prêt" / "Disponible") que via l'affectation directe, choisissez ici, parmi vos propres États existants, ceux qui doivent déclencher une remise, une restitution, un don ou une vente.

Si les deux mécanismes sont configurés et se déclenchent en même temps, une seule remise est créée — le déclenchement par affectation est prioritaire.

Le formulaire de configuration est organisé en onglets (un par type de fiche, plus un onglet Général et un onglet Compléments) : chaque onglet affiche un aperçu du PDF qui se met à jour automatiquement dès qu'un réglage change, avant même d'enregistrer.

Vérifiez aussi que les notifications GLPI sont actives (**Configuration > Notifications**, mode "Email"), et que le serveur SMTP est configuré.

## Vérifier que ça fonctionne

1. Affectez un ordinateur à un utilisateur ayant **un compte GLPI actif et une adresse e-mail valide**.
2. Une entrée apparaît dans **Remise & signature > Remises** avec le statut « Envoyé ».
3. L'utilisateur reçoit un e-mail avec un lien vers la page de signature.
4. S'il n'est pas déjà connecté, GLPI le redirige vers la page de connexion, puis le ramène sur le lien d'origine une fois authentifié.
5. Il consulte le PDF, signe à l'écran, valide.
6. Le PDF signé apparaît dans l'onglet **Documents** du matériel *et* de la fiche utilisateur ; le statut de la remise passe à « Signé ».

## Alternative au cron GLPI

Le CronTask GLPI (**Administration > Actions automatiques** > `remiseReminders` / `remiseExpire` / `remiseExpiryWarning`) est actif par défaut et suffit dans la plupart des cas.

Si vous préférez piloter ces actions depuis un ordonnanceur externe (cron système, tâche planifiée...) plutôt que de dépendre du cycle interne de GLPI, trois commandes console existent :

```bash
php bin/console plugins:remise:run-reminders
php bin/console plugins:remise:run-expiration
php bin/console plugins:remise:warn-expiring
```

**Si vous utilisez ces commandes depuis un ordonnanceur externe, désactivez les CronTask GLPI correspondants** pour éviter un double envoi des relances — les deux mécanismes appellent exactement la même logique, ils ne sont pas complémentaires.

## Tableau de bord

Trois cartes natives (widgets « grand nombre ») apparaissent dans le groupe **« Remise & signature »** de l'éditeur de tableau de bord GLPI (menu Tableau de bord > Modifier > Ajouter une carte) :

- Remises en attente de signature
- Remises signées
- Remises expirées

Chaque carte renvoie vers la liste filtrée correspondante en un clic, et respecte l'entité active sélectionnée.

## Mettre à jour le plugin

Une modification du code (nouvelle fonctionnalité, correctif) ne se signale jamais automatiquement côté GLPI — ce dépôt est hors du Marketplace officiel. La marche à suivre :

1. Sur le serveur GLPI : `cd plugins/remise && git pull` (ou re-téléchargez l'archive ZIP en remplaçant le dossier).
2. Lancez `sh update.sh` (depuis `plugins/remise/`) : ce script regroupe les trois étapes qu'il est facile d'oublier ou de faire dans le mauvais ordre — migration de la base (`plugin:install --force`, sans risque si déjà à jour), réactivation, et vidage du cache GLPI (`cache:clear`).

Le détail de ce que fait `update.sh`, et pourquoi chaque étape est nécessaire :
- **Migration de la base** (`php bin/console plugin:install remise --force`) : nécessaire si le changement ajoute une table ou un champ. GLPI ne le fait jamais tout seul après un simple remplacement de fichiers, même si le numéro de version dans `setup.php` (`PLUGIN_REMISE_VERSION`) a été incrémenté (il l'est systématiquement à chaque changement de structure) — sans cette étape, GLPI affiche bien le plugin comme "à mettre à jour" sur **Configuration > Plugins**, mais les nouvelles tables/colonnes n'existent pas tant que le bouton (ou cette commande) n'a pas été actionné.
- **Vidage du cache** (`php bin/console cache:clear`) : indispensable dès qu'un fichier `.twig` a changé (gabarit de PDF, de page de configuration, d'e-mail...). En environnement de production réel (pas un `git clone` sur poste de dev), GLPI désactive volontairement l'auto-rechargement de Twig (`Glpi\Application\Environment::shouldExpectResourcesToChange()` renvoie `false`) : sans ce vidage, l'ancienne version compilée du gabarit continue d'être servie indéfiniment, **sans aucune erreur ni avertissement** — le nouveau fichier est bien sur le disque, mais jamais rendu. Piège rencontré en conditions réelles (constaté après une mise à jour où la page de configuration affichait encore l'ancien texte d'aperçu, sans aucun onglet, malgré un `git pull` réussi et le bon numéro de version sur disque) et documenté dans [TROUBLESHOOTING.md](TROUBLESHOOTING.md).
- **OPcache**, si activé sur le serveur (fréquent en production, indépendant du cache GLPI ci-dessus) : `update.sh` tente désormais de le vider automatiquement lui-même (3 essais avec courte pause) en appelant `front/opcache_reset.php` juste après la réactivation du plugin — ce endpoint s'exécute dans le pool web (Apache/PHP-FPM), où OPcache est une mémoire réellement partagée entre tous les workers, contrairement au processus CLI de `update.sh` lui-même. Si cet appel échoue (serveur web injoignable en `localhost`, `curl`/`wget` absents...), `update.sh` l'indique clairement et affiche alors le rappel habituel : un redémarrage manuel de PHP-FPM/Apache peut rester nécessaire pour que le nouveau **code PHP** soit réellement rechargé (droits root généralement requis, `update.sh` ne peut pas le faire lui-même dans ce cas).

## Pour les développeurs et contributeurs

Cette section utilisateur s'arrête ici. Pour comprendre comment le plugin est construit, faire tourner sa suite de tests, ou contribuer au code :

- **[ARCHITECTURE.md](ARCHITECTURE.md)** — structure du code, vue d'ensemble des sous-systèmes, sécurité et qualité du dépôt GitHub, suite de tests automatisés (ce qui est couvert, comment la lancer).
- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** — pièges rencontrés et décisions non évidentes prises en conditions réelles, utile pour dépanner un problème ou comprendre pourquoi le code est écrit d'une certaine façon avant de le modifier.

## Licence

GPL-2.0-or-later, conformément aux conventions des plugins GLPI.
