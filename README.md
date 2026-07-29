# Plugin GLPI `remise` — Remise de matériel & signature électronique

Créé par **Vincent Guillotte**, avec l'aide de [Claude Code](https://claude.com/claude-code).

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

## Comment ça marche, vu par chaque personne

**L'administrateur** configure une fois pour toutes (Configuration > Remise & signature) : quels types de matériel sont concernés, quand une signature est déclenchée (affectation, réaffectation, restitution, ou changement d'État), les délais de relance, le logo et la charte à afficher sur les PDF.

**Le technicien** n'a rien de spécial à faire : il continue d'affecter le matériel dans GLPI comme d'habitude. Le plugin se charge du reste. Il peut consulter à tout moment l'historique des remises (menu Administration > Remise & signature > Remises) et relancer manuellement un bénéficiaire qui n'a pas encore signé.

**Le bénéficiaire** reçoit un e-mail, clique sur le lien, se connecte à GLPI s'il ne l'est pas déjà, relit le document et signe à l'écran. Une fois signé, il retrouve le PDF signé dans l'onglet Documents de son profil.

## Fonctionnalités

- Détection automatique de l'affectation/réaffectation/restitution d'un matériel, sans action manuelle du technicien.
- Génération PDF (fiche de remise ou de restitution) avec gabarits personnalisables (conditions générales, charte informatique).
- Signature à l'écran intégrée (aucun service externe requis, aucun coût de licence) — souris, doigt ou stylet, avec prévisualisation du PDF avant de signer.
- Relances automatiques en cas d'inaction, avec limite de nombre configurable, puis expiration automatique du lien passé un certain délai.
- Relance manuelle à tout moment depuis la fiche d'une remise.
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

## Ce qui n'est *pas* encore implémenté

- **Fournisseur de signature externe** (Yousign, DocuSeal...) pour un niveau de signature électronique renforcé (eIDAS "avancée"/"qualifiée"). Seule la signature à l'écran intégrée est disponible aujourd'hui — elle correspond à un niveau de signature électronique "simple", pas à une signature cryptographique.
- Couverture de tests automatisés : volontairement partielle aujourd'hui, à étendre au fil des évolutions (voir section Tests plus bas).

## Prérequis

- GLPI 11.0.x
- PHP 8.3+ (testé avec PHP 8.5)
- MariaDB / MySQL
- Un serveur SMTP configuré dans GLPI (pour l'envoi des e-mails de remise et de relance)
- Composer **uniquement si vous développez sur le plugin** (pour relancer les tests, mettre à jour Dompdf...) — **pas nécessaire pour l'installer**, voir ci-dessous.

Le dossier `vendor/` (Dompdf et ses dépendances, ~14 Mo, dépendances de production uniquement) est **commité directement dans ce dépôt**, volontairement, contrairement à la convention habituelle qui l'exclut. Un serveur GLPI de production n'a pas forcément Composer installé : un simple `git clone` (ou une release ZIP) suffit intégralement, sans aucune étape `composer install` sur le serveur cible. `composer.lock` est commité avec, pour que quiconque reconstruit `vendor/` (mise à jour de Dompdf, par exemple) obtienne exactement les mêmes versions. Seules les dépendances de développement (PHPUnit) restent exclues de ce `vendor/` embarqué : elles n'ont aucune utilité en production — voir la section Tests pour les réinstaller si besoin.

## Installation

### 1. Récupérer le code sur le serveur GLPI

Le dépôt étant privé, il faut vous authentifier. Deux façons de faire, sur le serveur GLPI cible :

**Avec un token d'accès personnel GitHub** (le plus simple) :
```bash
cd /chemin/vers/glpi/plugins
git clone https://github.com/parime/remise-glpi.git remise
```
Quand git demande vos identifiants : le nom d'utilisateur importe peu, le mot de passe doit être un [token d'accès personnel](https://github.com/settings/tokens?type=beta) GitHub (accès en lecture seule sur ce dépôt suffit) — GitHub n'accepte plus les mots de passe classiques pour les opérations git.

**Avec une clé SSH dédiée** (plus adapté si ce serveur clone régulièrement le dépôt) :
```bash
ssh-keygen -t ed25519 -C "glpi-server-remise" -f ~/.ssh/remise_deploy -N ""
# ajoutez ~/.ssh/remise_deploy.pub comme "Deploy key" (lecture seule) sur le dépôt GitHub
GIT_SSH_COMMAND="ssh -i ~/.ssh/remise_deploy" git clone git@github.com:parime/remise-glpi.git remise
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
- **les déclencheurs par État** (optionnel) — si votre organisation pilote plutôt le cycle de vie du matériel via son État (ex. "En prêt" / "Disponible") que via l'affectation directe, choisissez ici, parmi vos propres États existants, ceux qui doivent déclencher une remise ou une restitution.

Si les deux mécanismes sont configurés et se déclenchent en même temps, une seule remise est créée — le déclenchement par affectation est prioritaire.

Vérifiez aussi que les notifications GLPI sont actives (**Configuration > Notifications**, mode "Email"), et que le serveur SMTP est configuré.

## Vérifier que ça fonctionne

1. Affectez un ordinateur à un utilisateur ayant **un compte GLPI actif et une adresse e-mail valide**.
2. Une entrée apparaît dans **Remise & signature > Remises** avec le statut « Envoyé ».
3. L'utilisateur reçoit un e-mail avec un lien vers la page de signature.
4. S'il n'est pas déjà connecté, GLPI le redirige vers la page de connexion, puis le ramène sur le lien d'origine une fois authentifié.
5. Il consulte le PDF, signe à l'écran, valide.
6. Le PDF signé apparaît dans l'onglet **Documents** du matériel *et* de la fiche utilisateur ; le statut de la remise passe à « Signé ».

## Alternative au cron GLPI

Le CronTask GLPI (**Administration > Actions automatiques** > `remiseReminders` / `remiseExpire`) est actif par défaut et suffit dans la plupart des cas.

Si vous préférez piloter ces actions depuis un ordonnanceur externe (cron système, tâche planifiée...) plutôt que de dépendre du cycle interne de GLPI, deux commandes console existent :

```bash
php bin/console plugins:remise:run-reminders
php bin/console plugins:remise:run-expiration
```

**Si vous utilisez ces commandes depuis un ordonnanceur externe, désactivez les CronTask GLPI correspondants** pour éviter un double envoi des relances — les deux mécanismes appellent exactement la même logique, ils ne sont pas complémentaires.

## Tableau de bord

Trois cartes natives (widgets « grand nombre ») apparaissent dans le groupe **« Remise & signature »** de l'éditeur de tableau de bord GLPI (menu Tableau de bord > Modifier > Ajouter une carte) :

- Remises en attente de signature
- Remises signées
- Remises expirées

Chaque carte renvoie vers la liste filtrée correspondante en un clic, et respecte l'entité active sélectionnée.

## Mettre à jour le plugin

Une modification du code (nouvelle fonctionnalité, correctif) ne se signale jamais automatiquement côté GLPI — le dépôt est privé, hors du Marketplace officiel. La marche à suivre :

1. Sur le serveur GLPI : `cd plugins/remise && git pull` (ou `git pull` via SSH si vous avez configuré une clé de déploiement).
2. Si le changement touche uniquement le comportement (pas la base de données), c'est terminé — GLPI sert le nouveau code au prochain chargement de page. **Attention à OPcache** si activé sur le serveur : un redémarrage de PHP-FPM/Apache ou un vidage de cache peut être nécessaire pour que le nouveau code soit réellement pris en compte.
3. Si le changement modifie la structure de la base (nouveau champ, nouvelle table), le nombre de version dans `setup.php` (`PLUGIN_REMISE_VERSION`) doit avoir été incrémenté : GLPI affiche alors le plugin comme nécessitant une mise à jour sur la page **Configuration > Plugins**, avec un bouton dédié qui relance proprement la migration.

## Tests automatisés

Le socle PHPUnit (`phpunit.xml`, `tests/bootstrap.php`) démarre un vrai noyau GLPI (`Glpi\Kernel\Kernel`) — GLPI 11 n'a plus de bootstrap léger, `inc/includes.php` ne fait plus que des vérifications de rétrocompatibilité. C'est le même mécanisme que `bin/console`, ça donne accès à une vraie connexion DB, à l'autoload des classes GLPI/plugin et aux `PLUGIN_HOOKS`, sans dépendre d'une requête HTTP.

**⚠️ À lancer uniquement contre une instance GLPI dédiée aux tests, jamais en production.** La plupart des tests écrivent en base (création d'entités, de configuration...) ; ils sont enveloppés dans une transaction annulée en `tearDown()` (`RemiseTestCase`), mais ce n'est pas un filet de sécurité absolu — une requête qui déclencherait un COMMIT implicite (DDL) y échapperait.

Installation et lancement (le plugin doit déjà être installé et actif sur l'instance de test) :
```bash
docker exec -u www-data <container_glpi> sh -c "cd /var/www/glpi/plugins/remise && composer install"
docker exec -u www-data <container_glpi> sh -c "cd /var/www/glpi/plugins/remise && vendor/bin/phpunit"
```
Si le plugin n'est pas installé dans `<glpi>/plugins/remise/` (donc à 3 niveaux sous la racine GLPI), définissez `GLPI_ROOT_DIR` :
```bash
GLPI_ROOT_DIR=/chemin/vers/glpi vendor/bin/phpunit
```

Ce qui est couvert aujourd'hui :
- `ConfigTest` : héritage de configuration par entité (une entité sans config propre hérite de son ancêtre le plus proche, pas directement de la racine ; une config directe prend le pas sur celle d'un ancêtre).
- `TemplateTest` : repli sur le gabarit par défaut de l'entité racine, et absence de plantage quand aucun gabarit n'existe pour un type donné.
- `SignatureImageValidatorTest` : rejet d'un canevas vide/transparent, d'un format invalide, d'une image trop petite ; acceptance d'un vrai tracé — logique pure (GD), aucun accès base.

**Piège rencontré en testant** : `Glpi\Kernel\Kernel('production')` désactive l'auto-reload de Twig (comportement voulu en production, coûteux en I/O sinon). Résultat : après une modification d'un fichier `.twig`, le rendu via `bin/console`/tests continue de servir l'ancienne version compilée en cache tant que `files/_cache/<version>-production/templates/` n'est pas vidé manuellement — aucune erreur, juste un rendu silencieusement obsolète. À vider après toute modification de template si un test via `bin/console` semble ignorer un changement pourtant bien enregistré sur disque.

## Notes techniques importantes pour la maintenance

Cette section s'adresse à qui reprend ou modifie le code — elle documente les pièges et décisions non évidentes rencontrés en conditions réelles.

- **Namespace PSR-4** : `GlpiPlugin\Remise`, classes dans `src/GlpiPlugin/Remise/` (composer.json), conforme à la convention GLPI 11 pour les plugins modernes.
- **Isolation des erreurs dans les hooks** : `plugin_remise_item_assignment()` et `plugin_remise_item_pre_purge()` (`hook.php`) enveloppent tout appel dans un `try/catch (\Throwable)`, avec journalisation via `Toolbox::logInFile('remise', ...)` (fichier `files/_log/remise.log`). Ces fonctions sont appelées de manière synchrone par `CommonDBTM::add()`/`update()` (`Plugin::doHook()`) : sans ce garde-fou, une exception côté plugin (fournisseur de signature non implémenté, échec de génération PDF...) remonterait jusque dans la sauvegarde de l'item GLPI lui-même — un technicien qui réaffecte un simple ordinateur se prendrait une erreur sur *sa* sauvegarde à cause d'un problème qui ne concerne que le plugin.
- **Accès à `front/sign.php`** : connexion GLPI obligatoire, via `Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(..., STRATEGY_AUTHENTICATED)` dans `setup.php` (explicite, alors que c'est déjà le comportement par défaut du pare-feu GLPI 11 — la ligne documente que c'est un choix assumé). Le contrôle d'identité fin (seul le bénéficiaire peut voir/signer *son* document, pas n'importe quel utilisateur connecté) est fait dans `SignController::assertCurrentUserIsBeneficiary()`, appelé par `show()`, `submit()` et le téléchargement du PDF. **Conséquence opérationnelle** : le bénéficiaire doit pouvoir se connecter à GLPI avec ses propres identifiants (compte AD synchronisé avec authentification LDAP, par exemple) — un compte GLPI qui existe uniquement pour la gestion de parc mais sans authentification possible bloquerait la signature.
- **CSRF** : GLPI valide automatiquement le jeton CSRF pour toute requête POST vers une page de plugin (`Hooks::CSRF_COMPLIANT`). Ne rappelez **jamais** `Session::checkCSRF()` vous-même dans un front controller : le jeton est à usage unique et serait déjà consommé, ce qui provoquerait un faux « Access denied ».
- **Cible de notification** : `NotificationTargetRemise` n'utilise **ni** `Notification::ITEM_USER` (bénéficiaire) **ni** `Notification::ITEM_TECH_IN_CHARGE` (technicien) : ces types natifs font un `INNER JOIN` sur `Profile_User` (`NotificationTarget::getProfileJoinCriteria()`) qui exige un profil GLPI actif dans l'entité — silencieusement sans erreur si absent. **Vérifié en conditions réelles** : `ITEM_TECH_IN_CHARGE` ne notifiait jamais le technicien dans l'environnement de test, malgré un profil actif en base, un e-mail configuré et `users_id_tech` correctement rempli. Deux types personnalisés (`TARGET_BENEFICIARY`, `TARGET_TECHNICIAN`) notifient directement par e-mail via `addUserFieldByEmail()`, sans cette jointure. Ce n'est pas lié à la connexion requise pour signer : ça concerne uniquement l'envoi de l'e-mail par le moteur de notifications GLPI (beaucoup d'employés synchronisés depuis l'Active Directory ont un compte GLPI sans profil associé — et un technicien à profil restreint peut être dans le même cas).
- **Nom des Documents GLPI (`getDocumentTitle()`) ET contenu du PDF volontairement non traduits** : le PDF non signé est généré pendant le hook `item_add`/`item_update` (session du **technicien** qui fait l'affectation), le PDF signé pendant `submit()` de la page de signature (session du **bénéficiaire**). Utiliser `__()` pour le type ("Remise"/"Restitution"), les titres ou le type de matériel ferait donc dépendre la langue du **contenu du document** (pas seulement son nom de fichier) de qui a déclenché l'action — **constaté en conditions réelles** : un même PDF affichant un titre en anglais puis un intitulé en français juste en dessous. `getCanonicalTypeLabel()` et `getCanonicalItemtypeLabel()` fixent volontairement ces libellés dans une seule langue (français), indépendamment de `getTypes()`/`getTypeName()` natif (qui restent traduits, à raison, pour tout ce qui est réellement propre à un utilisateur donné). Un PDF archivé est un document unique, potentiellement relu par plusieurs personnes à des dates différentes — ce n'est pas un contenu qui doit varier selon qui l'a généré.
- **E-mails multilingues** : chaque gabarit de notification a deux traductions (`NotificationTemplateTranslation`) — `language => ''` (française, universelle) et `language => 'en_GB'`. GLPI choisit automatiquement celle qui correspond à la langue du compte GLPI du destinataire, sinon retombe sur la française. Testé en direct : un utilisateur en `fr_FR` et un utilisateur en `en_GB` affectés au même type de matériel reçoivent chacun l'e-mail dans leur langue.
- **Document::add()** attend le fichier physique dans `GLPI_TMP_DIR` (entrée `_filename`), pas dans un dossier arbitraire.
- **Mode de notification** : la valeur exacte est `Notification_NotificationTemplate::MODE_MAIL` (= la chaîne `'mailing'`, pas `'mail'`).
- **Commandes console de plugin** : GLPI les découvre en scannant récursivement `src/` (et `inc/`) à la recherche de tout fichier se terminant par `Command.php` dont la classe étend `Symfony\Component\Console\Command\Command` — aucun enregistrement dans `setup.php` n'est nécessaire. Seule contrainte : le nom de la commande doit commencer par `plugins:remise:`.
- **Traductions dans les gabarits Twig** : la fonction Twig est `__('texte', 'remise')` (une fonction, enregistrée par `I18nExtension`), pas un filtre `|trans` — GLPI ne fournit pas ce filtre, l'utiliser fait planter le rendu (`Twig\Error\SyntaxError`).
- **Menu d'administration (`Hooks::MENU_TOADD`)** : la documentation du hook décrit un format `['types' => [...], 'icon' => '...']` par catégorie — **ce n'est pas ce que le code lit réellement**. Le code attend une liste PLATE de classes directement : `['admin' => [Remise::class, Template::class, Config::class]]`. Suivre la documentation littéralement casse le menu sur **toutes** les pages GLPI, avec l'erreur `Twig\Error\RuntimeError: "Class name must be a valid object or a string"`. L'icône s'auto-détecte depuis la première classe listée qui implémente `getIcon()`.
- **Actifs personnalisés et `plugin_init_<plugin>()`** : `Glpi\Asset\AssetDefinitionManager::getInstance()->getDefinitions()` renvoie **toujours un tableau vide** quand on l'appelle depuis `plugin_init_remise()`. Cause : l'ordre des listeners de boot de GLPI exécute `InitializePlugins` (qui déclenche `plugin_init_<plugin>()`) **avant** `CustomObjectsBoot` (qui peuple réellement le cache des définitions). Contournement utilisé ici (`Config::getAllManageableItemtypes()`) : une requête SQL directe sur `glpi_assets_assetdefinitions`, disponible dès la connexion DB, bien avant `InitializePlugins`.
- **Héritage de configuration par entité (`Config::getForEntity()`)** : ne se limite pas à "l'entité elle-même, sinon directement la racine" — la config effective est celle de l'ancêtre **le plus proche** qui en possède une, calculée via `getAncestorsOf('glpi_entities', $entities_id)` puis en gardant, parmi les ancêtres ayant une ligne de config, celui dont le champ `level` de `glpi_entities` est le plus élevé (= le plus profond/proche). Ainsi, une organisation à plusieurs niveaux (Racine > Région > Site) peut configurer le plugin sur une entité intermédiaire et voir ce réglage s'appliquer à ses entités enfants sans config propre, même si la racine n'a pas non plus de config.
- **Type "Échange" retiré** : `Remise::TYPE_EXCHANGE` (valeur `2`) et le réglage `sign_on_exchange` ont été supprimés — un transfert direct entre deux personnes crée désormais une remise normale (`TYPE_HANDOVER`) au nouveau détenteur, comme une réaffectation classique. La valeur `2` reste volontairement commentée comme inutilisée pour ne pas être réutilisée par erreur pour autre chose. Montée de version : `Template::install()` désactive (`is_active = 0`, sans supprimer) tout gabarit existant de type `2` sur une installation déjà en place.
- **`runReminders()`/`runExpiration()` résolvent la config PAR REMISE, pas globalement** : chaque remise résout sa propre config via `Config::getForEntity((int) $remise->fields['entities_id'])` à l'intérieur de la boucle, plutôt qu'une seule fois avant la boucle — sans quoi la config d'une entité non-racine n'avait jamais d'effet pour les tâches automatiques. Conséquence technique pour `runExpiration()` : le filtre de délai ne peut plus se faire dans la clause SQL `WHERE` (chaque entité a potentiellement sa propre durée de validité) — il se fait en PHP, ligne par ligne, après récupération de toutes les remises encore ouvertes.
- **Cartes de tableau de bord (`CardProvider`) restreintes à l'entité active** : `countByStatus()` ajoute `getEntitiesRestrictCriteria(Remise::getTable(), '', '', true)` à la clause `WHERE`, exactement comme `Glpi\Dashboard\Provider::bigNumberItem()` le fait nativement pour tout itemtype possédant un champ `entities_id`. Sans ça, les cartes comptaient les remises de tout GLPI sans tenir compte de l'entité active sélectionnée.
- **Logo imposé aux entités enfants (`logo_force_children`) : résolution séparée de l'héritage habituel de la config** : `Config::getForEntity()` n'hérite le logo (comme le reste des réglages) que pour une entité qui n'a AUCUNE ligne de config propre — une entité qui a déjà personnalisé autre chose (son e-mail d'expéditeur, par exemple) resterait sinon bloquée sur son propre logo, sans qu'une entité parente puisse malgré tout lui imposer le sien. `Config::getEffectiveLogoDocumentId()` calcule donc la valeur affichée/utilisée séparément : elle remonte tous les ancêtres (`getAncestorsOf()`, pas seulement le parent direct — une entité petite-enfant d'une entité qui impose hérite donc aussi) à la recherche de ceux ayant `logo_force_children = 1`, et retient celui de niveau le plus profond (le plus proche) en cas d'imposition sur plusieurs branches différentes. Une entité qui impose elle-même un logo à ses propres entités enfants n'est pas pour autant immunisée contre l'imposition d'un de ses ancêtres : la règle « ancêtre imposant le plus proche gagne » s'applique uniformément, y compris à elle-même. **Vérifié en conditions réelles** sur une hiérarchie à 3 niveaux (A impose logo1 → B a sa propre config avec logo2 mais pas d'imposition → C, petite-enfant de A sans config propre) : B et C affichent bien logo1, pas logo2 ; et avec D (entité enfant de B, imposant logo3) et E (entité enfant de D), E affiche logo3 (l'imposition de D, plus proche, l'emporte sur celle de A), tandis que D lui-même affiche logo1 (imposé par A, son propre ancêtre le plus proche).
- **Passe de revue de code (réutilisation, code mort, efficacité, architecture)** : le code a été relu en entier après plusieurs mois de développement continu, jamais revu indépendamment jusque-là. Résultat, appliqué et vérifié par une nouvelle exécution complète des tests et des flux réels (affectation → signature → restitution → tableau de bord) :
  - Code mort supprimé : `Token::invalidate()`, `Config::getDefault()`, `Remise::getConfig()`, les états `viewed`/`signed`/`refused` de `SignatureCallbackResult` (jamais produits ni consommés, seul `ignored` l'est réellement), deux variables `$newId` jamais lues (`front/accessory.form.php`, `front/template.form.php`), et l'enregistrement `add_javascript` vide dans `setup.php` (le script de signature est chargé directement en balise `<script>` par `sign_page.html.twig`, pas par ce hook).
  - **`Signature::getForRemise()` avait aussi été supprimé ici comme code mort, à tort** : sa suppression a révélé que la preuve de signature (adresse IP, empreinte du document...) collectée par `Signature::recordProof()` à chaque signature n'était affichée strictement **nulle part** — pas un oubli de nettoyage mais un vrai manque fonctionnel, puisque c'est la valeur même d'une preuve de signature électronique. Remis en place et câblé : la fiche d'une remise signée (`remise_form.html.twig`) affiche désormais signataire, IP, navigateur et empreinte SHA-256 via `Signature::getForRemise()`, appelé depuis `Remise::showForm()` uniquement quand `status === STATUS_SIGNED`. Vérifié en conditions réelles : signature avec IP/user-agent factices, preuve retrouvée en base, puis affichage confirmé sur la vraie page `remise.form.php` (les 4 informations apparaissent bien dans le HTML rendu).
  - Duplication réduite : `Remise::STATUSES_AWAITING_SIGNATURE` (envoyé/consulté) et `Remise::STATUSES_STILL_EDITABLE` (tout ce qui précède la signature) remplacent le même couple/quadruplet de statuts recopié à 6 endroits différents (formulaire, relance manuelle, crons, `CardProvider`) ; `Remise::daysSince()` remplace le même calcul `floor((time() - strtotime(...)) / DAY_TIMESTAMP)` dupliqué dans `runReminders()`/`runExpiration()` ; `Config::pickRowOfClosestEntity()` factorise l'algorithme de résolution "ancêtre le plus proche parmi plusieurs candidats", jusque-là recopié à l'identique entre `getForEntity()` et `getEffectiveLogoDocumentId()` ; les 7 classes qui déclarent `$rightname` référencent maintenant `Profile::RIGHT_REMISE`/`RIGHT_CONFIG`/`RIGHT_TEMPLATE` plutôt que de recopier la chaîne littérale.
  - Efficacité : `runReminders()`/`runExpiration()` n'appellent plus `$remise->getFromDB($row['id'])` (une requête `SELECT *` redondante, la ligne complète étant déjà en main) et mettent en cache `Config::getForEntity()` par entité le temps de la boucle (plusieurs remises partagent souvent la même entité) ; `Config::getEffectiveLogoDocumentId()` accepte un logo déjà connu en fallback optionnel, évitant à `showConfigForm()` de rappeler `getForEntity()` une deuxième fois pour la même entité.
  - Conservé intentionnellement malgré des références nulles ou faibles (documenté ici pour ne pas être re-signalé par erreur comme oubli) : `SignatureProviderInterface::handleCallback()` et `AbstractProvider::$providerConfig` — c'est l'infrastructure d'extensibilité pour un futur fournisseur externe asynchrone (Yousign/DocuSeal, cf. section "Ce qui n'est pas encore implémenté"), pas du code mort accidentel ; `SignatureRequestResult::$reference`/`$signUrl` — le canvas natif ne les lit pas (il construit son URL autrement), mais ce sont des champs légitimes du contrat de retour d'un fournisseur externe.

## Structure du plugin

```
remise/
├── composer.json           # dépendance Dompdf (vendor/ commité, voir Prérequis)
├── setup.php / hook.php    # déclaration, hooks, install/uninstall
├── src/GlpiPlugin/Remise/  # classes métier (PSR-4)
├── front/                  # contrôleurs (remise, template, config, sign public)
├── templates/               # gabarits Twig (admin + PDF + page de signature)
├── js/sign/                 # signature_pad.js, PDF.js, logique de la page de signature
└── locales/                 # traductions (fr_FR, en_GB)
```

## Licence

GPL-2.0-or-later, conformément aux conventions des plugins GLPI.
