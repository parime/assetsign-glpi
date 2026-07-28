# Plugin GLPI `remise` — Remise de matériel & signature électronique

Détecte automatiquement l'affectation d'un matériel (ordinateur, écran, périphérique, téléphone) à un utilisateur dans GLPI, génère une fiche de remise en PDF, la fait signer électroniquement par le bénéficiaire, relance automatiquement en cas d'inaction, puis rattache le PDF signé au matériel et à l'utilisateur.

**La page de signature exige une connexion GLPI** (choix du projet — pas d'accès anonyme via simple lien). Le bénéficiaire doit donc disposer d'un compte GLPI valide (ex. via une synchronisation Active Directory). Une fois connecté, seul le bénéficiaire réel du document peut le consulter ou le signer — un autre utilisateur authentifié qui obtiendrait le lien par un autre moyen (transfert d'e-mail, etc.) se voit refuser l'accès.

Ce plugin a été conçu et **validé de bout en bout** dans un environnement GLPI 11.0.7 réel (Docker) : affectation → détection → génération PDF → e-mail → connexion → consultation → signature → rattachement — chaque étape a été testée avec de vraies requêtes HTTP contre une instance GLPI, y compris les cas de refus d'accès (utilisateur non connecté, utilisateur connecté mais non concerné par le document).

## Prérequis

- GLPI 11.0.x
- PHP 8.3+ (testé avec PHP 8.5)
- MariaDB / MySQL
- Composer (pour installer la dépendance PDF du plugin — Dompdf)

## Installation — sur votre stack Docker (Conserto)

Votre GLPI tourne via `livraison/docker-stack.yml` avec un volume nommé `plugins` monté sur `/var/www/glpi/plugins`, lui-même lié sur l'hôte à `${PATH_BASE_GLPI_MIGR}/plugins`.

1. Copiez le dossier `remise/` de ce dépôt dans `${PATH_BASE_GLPI_MIGR}/plugins/` sur l'hôte Docker (ou dans un pipeline CI qui construit une image incluant ce dossier).
2. Installez les dépendances PHP du plugin **à l'intérieur du conteneur GLPI** (Dompdf n'est pas fourni par le cœur de GLPI) :
   ```bash
   docker exec -u root <container_glpi> sh -c "curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer"
   docker exec -u root <container_glpi> sh -c "cd /var/www/glpi/plugins/remise && composer install --no-dev --no-interaction"
   ```
   *(À terme, mieux vaut intégrer cette étape dans `packaging/Dockerfile` pour ne pas la refaire à chaque déploiement.)*
3. Installez puis activez le plugin :
   ```bash
   docker exec <container_glpi> php /var/www/glpi/bin/console plugin:install remise
   docker exec <container_glpi> php /var/www/glpi/bin/console plugin:activate remise
   ```
4. Un menu **Administration > Remise & signature** apparaît (sous-entrées : Remises, Gabarits de remise, Configuration). Depuis **Configuration**, réglez :
   - l'adresse d'expédition, le fournisseur de signature (`canvas` intégré par défaut — gratuit, aucune dépendance externe), les délais de relance, la durée de validité du lien,
   - **les types de matériel gérés** : décochez ce que vous ne voulez pas voir passer par le plugin (par défaut : ordinateurs, écrans, périphériques, téléphones). Vos **actifs personnalisés** (Configuration > Actifs personnalisés, GLPI 10.1+/11) apparaissent aussi automatiquement dans cette liste, dès qu'ils sont actifs — aucune modification du plugin n'est nécessaire pour en gérer un nouveau, il suffit de cocher la case correspondante. Ce mécanisme fonctionne car tout actif personnalisé possède toujours les champs "Utilisateur" et "État" comme les types natifs.
   - **les déclencheurs par affectation** (première affectation, réaffectation, restitution) — basés sur le champ "Utilisateur" du matériel, ce qui convient à la plupart des GLPI. Un transfert direct entre deux personnes (l'ancien détenteur n'est jamais repassé par "aucun") est traité comme une remise normale au nouveau détenteur — il n'existe pas de type "Échange" distinct (retiré, cf. notes techniques),
   - **les déclencheurs par État** (optionnel) — si votre organisation pilote plutôt le cycle de vie du matériel via son État (ex. "En prêt" / "Disponible") que via l'affectation directe, choisissez ici, parmi vos propres États existants, ceux qui doivent déclencher une remise ou une restitution. Le bénéficiaire notifié reste l'utilisateur actuellement affecté au matériel au moment du changement d'État. Ce mécanisme est indépendant des GLPI qui n'ont pas la même liste d'États : rien n'est présupposé, vous choisissez les vôtres.
   
   Si les deux mécanismes sont configurés et se déclenchent en même temps (un technicien change l'utilisateur *et* l'État dans la même action), une seule remise est créée — le déclenchement par affectation est prioritaire.
5. Activez les notifications si ce n'est pas déjà fait : **Configuration > Notifications**, vérifiez que le mode "Email" est actif (`notifications_mailing`), et que votre serveur SMTP est configuré (**Configuration > Notifications > Paramètres**).

## Vérifier que ça fonctionne

1. Affectez un ordinateur à un utilisateur ayant **un compte GLPI actif et une adresse e-mail valide**.
2. Une entrée apparaît dans **Remise & signature > Remises** (menu Administration) avec le statut « Envoyé ».
3. L'utilisateur reçoit un e-mail avec un lien `/plugins/remise/front/sign.php?t=...`.
4. S'il n'est pas déjà connecté, GLPI le redirige automatiquement vers la page de connexion, puis le ramène sur le lien d'origine une fois authentifié.
5. Il consulte le PDF, signe à l'écran, valide.
6. Le PDF signé apparaît dans l'onglet **Documents** du matériel *et* de la fiche utilisateur ; le statut de la remise passe à « Signé ».

## Ce qui est livré dans cette version (socle fonctionnel complet)

- Détection automatique (hooks `item_add`/`item_update`, pas de webhook externe).
- Génération PDF (Dompdf + Twig) à partir d'un gabarit configurable (Configuration > Remise > Gabarits).
- Signature native par canvas (`signature_pad.js`) + prévisualisation défilante du PDF (`PDF.js`), toutes deux vendorisées en local dans `js/sign/vendor/` (aucun CDN externe requis en production).
- Page de signature protégée par connexion GLPI **et** par un jeton à usage unique haché en base (jamais stocké en clair) propre à chaque bénéficiaire — double contrôle : il faut être connecté *et* être la bonne personne.
- Relances automatiques (CronTask GLPI natif, **et** commande console alternative — voir ci-dessous), rattachement natif `Document_Item`, notifications GLPI standards éditables comme n'importe quelle autre notification.
- Relance manuelle depuis la fiche d'une remise (bouton « Relancer maintenant », visible tant que le document n'est pas signé/expiré).
- Validation de la signature côté serveur : une image de signature vide, trop petite ou quasi vierge est rejetée avant tout traitement — le contrôle côté navigateur (`signature_pad.isEmpty()`) ne protège que des erreurs d'usage normales, pas d'un client modifié ou d'un appel direct à l'API.
- Traductions : `locales/en_GB.mo` fournit une traduction anglaise complète de l'interface (admin, notifications, page de signature). Le français reste la langue par défaut (texte source des appels `__()`), donc fonctionnel même sans fichier de traduction.
- Aucune fonctionnalité de refus : un bénéficiaire signe ou ne fait rien (auquel cas les relances puis l'expiration s'appliquent) — pas de statut « refusé » ni de motif à saisir.
- Historique natif GLPI (`Log`) sur chaque remise.
- Annulation automatique d'une remise encore en attente si le matériel change de nouveau de main avant signature (cf. `Remise::cancelPendingRemisesFor()`) : sans ça, l'ancien bénéficiaire garderait un lien de signature valide pour un matériel qu'il ne détient plus.
- Logo personnalisable sur les fiches PDF, envoyé directement depuis le poste de l'administrateur (Configuration > Remise & signature > champ fichier classique, PNG/JPG/GIF/WEBP) — pas besoin de passer par le module Documents au préalable. `Config::uploadLogo()` déplace le fichier envoyé dans `GLPI_TMP_DIR` puis l'attache via `Document::add()` (même convention que les PDF générés par le plugin), sans dépendre du composant de téléversement JS natif de GLPI.
- Aperçu en direct sur la page de configuration : un panneau à côté du formulaire affiche une fiche d'exemple (données fictives) rendue avec le **même gabarit Twig** que le vrai PDF (`HandoverPdfBuilder::renderPreview()`), donc visuellement identique. Le logo s'y met à jour instantanément dès qu'un fichier est choisi (lecture cliente via `FileReader`, avant tout envoi au serveur) ; le reste de l'aperçu (gabarit légal, en-têtes) reflète la configuration déjà enregistrée et se met à jour après sauvegarde.
- Éditeur riche (TinyMCE natif de GLPI, `enable_richtext: true` sur `fields.textareaField()`) pour les conditions générales et la charte informatique d'un gabarit : mise en forme et surtout **insertion de liens** (ex: renvoyer vers le document complet de la charte plutôt que de le retaper) via le bouton "lien" de la barre d'outils. Le contenu HTML est stocké tel quel et rendu avec `|raw` dans le PDF — Dompdf transforme nativement les balises `<a href>` en liens cliquables dans le PDF final.
- Champ dédié **`charter_url`** dans la configuration du plugin (pas seulement dans le gabarit) : un simple champ URL, configurable **par entité** comme le reste de la config, pour renvoyer vers la charte informatique complète hébergée par l'organisation (intranet, PDF déjà en ligne...) — l'hébergement variant d'une société à l'autre, ce champ reste volontairement séparé du texte du gabarit. Si renseigné, un lien cliquable est ajouté sur la fiche PDF en plus du texte de la charte. Vérifié en direct : lien correctement rendu dans le PDF (`<a href="...">`), persistant après sauvegarde, visible dans l'aperçu en direct de la configuration.
- Marque et modèle du matériel affichés sur la fiche PDF (`Remise::getTargetItem()` résout `manufacturers_id` via `Dropdown::getDropdownName()`, et le modèle via `CommonDBTM::getModelClass()` — générique quel que soit l'itemtype, la table/FK du modèle variant d'un type à l'autre contrairement à la marque). Lignes masquées si l'information n'est pas renseignée sur le matériel.
- Le technicien qui a déclenché la remise (`users_id_tech`) est notifié quand elle est signée **et** quand elle expire sans signature — sans quoi personne côté IT n'est jamais informé qu'un document est resté sans suite.
- Accessoires remis (chargeur, sacoche, écran additionnel...) : catalogue gérable dans Configuration > Intitulés (`Accessory`, liste déroulante standard), attachables à une remise (avec quantité et commentaire) directement depuis sa fiche tant qu'elle n'est pas signée — le PDF non signé est automatiquement régénéré à chaque ajout/retrait pour rester à jour avant que le bénéficiaire ne le consulte.

### Alternative au cron GLPI

Le CronTask GLPI (`Administration > Actions automatiques > remiseReminders` / `remiseExpire`) est actif **par défaut** et suffit dans la plupart des cas — c'est ce qui a été testé en conditions réelles (déclenchement via `front/cron.php`, identique à ce que fait votre `cron-worker.sh` en production).

Si vous préférez piloter ces actions depuis un ordonnanceur externe (cron système, tâche planifiée Kubernetes...) plutôt que de dépendre du cycle interne de GLPI, deux commandes console dédiées existent :

```bash
php bin/console plugins:remise:run-reminders
php bin/console plugins:remise:run-expiration
```

**Si vous utilisez ces commandes depuis un ordonnanceur externe, désactivez les CronTask GLPI correspondants** (`remiseReminders` / `remiseExpire`, dans Administration > Actions automatiques) pour éviter un double envoi des relances — les deux mécanismes appellent exactement la même logique (`Remise::runReminders()` / `runExpiration()`), ils ne sont pas complémentaires mais bien deux façons alternatives de déclencher la même chose.

## Ce qui n'est *pas* encore implémenté (phase 2)

- **Fournisseur de signature externe (Yousign, DocuSeal...)** : retiré du choix dans la configuration (le squelette `YousignProvider` levait une exception non rattrapée si sélectionné, ce qui cassait la création de toute remise). Seul le canvas natif est proposé. À réintroduire uniquement en implémentant un vrai appel API de bout en bout (upload du PDF, webhook de retour) — pas avant, pour ne pas exposer une option qui casse le plugin. Utile si un niveau de signature eIDAS renforcé est nécessaire (le canvas natif correspond à un niveau « simple »).
- Tests automatisés : le socle existe (voir section suivante) avec 3 classes de test (11 tests) ; à étendre au fil des évolutions plutôt que viser une couverture exhaustive d'un coup.

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

Vérifié en conditions réelles : en réintroduisant temporairement l'ancienne logique d'héritage ("soi-même puis racine") dans `Config::getForEntity()`, `ConfigTest::testChildEntityInheritsClosestAncestorConfigNotRoot` échoue immédiatement avec un message explicite — la suite détecte donc bien une vraie régression, pas seulement des assertions qui passent par construction.

**Piège rencontré en testant** : `Glpi\Kernel\Kernel('production')` désactive l'auto-reload de Twig (comportement voulu en production, coûteux en I/O sinon). Résultat : après une modification d'un fichier `.twig`, le rendu via `bin/console`/tests continue de servir l'ancienne version compilée en cache tant que `files/_cache/<version>-production/templates/` n'est pas vidé manuellement — aucune erreur, juste un rendu silencieusement obsolète. À vider après toute modification de template si un test via `bin/console` semble ignorer un changement pourtant bien enregistré sur disque.

## Tableau de bord

Trois cartes natives (widgets « grand nombre ») sont enregistrées via le hook `Hooks::DASHBOARD_CARDS` et apparaissent dans le groupe **« Remise & signature »** de l'éditeur de tableau de bord GLPI (menu Tableau de bord > Modifier > Ajouter une carte) :

- Remises en attente de signature
- Remises signées
- Remises expirées

Chaque carte renvoie vers la liste filtrée correspondante en un clic. D'autres types de cartes (courbe du nombre de signatures par mois, temps moyen avant signature) peuvent être ajoutés de la même façon dans `src/GlpiPlugin/Remise/Dashboard/CardProvider.php` si besoin — la mécanique d'enregistrement est en place et validée.

## Notes techniques importantes pour la maintenance

- **Namespace PSR-4** : `GlpiPlugin\Remise`, classes dans `src/GlpiPlugin/Remise/` (composer.json), conforme à la convention GLPI 11 pour les plugins modernes.
- **Isolation des erreurs dans les hooks** : `plugin_remise_item_assignment()` et `plugin_remise_item_pre_purge()` (`hook.php`) enveloppent tout appel dans un `try/catch (\Throwable)`, avec journalisation via `Toolbox::logInFile('remise', ...)` (fichier `files/_log/remise.log`). Ces fonctions sont appelées de manière synchrone par `CommonDBTM::add()`/`update()` (`Plugin::doHook()`) : sans ce garde-fou, une exception côté plugin (fournisseur de signature non implémenté, échec de génération PDF...) remonterait jusque dans la sauvegarde de l'item GLPI lui-même — un technicien qui réaffecte un simple ordinateur se prendrait une erreur sur *sa* sauvegarde à cause d'un problème qui ne concerne que le plugin.
- **Accès à `front/sign.php`** : connexion GLPI obligatoire, via `Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(..., STRATEGY_AUTHENTICATED)` dans `setup.php` (explicite, alors que c'est déjà le comportement par défaut du pare-feu GLPI 11 — la ligne documente que c'est un choix assumé). Le contrôle d'identité fin (seul le bénéficiaire peut voir/signer *son* document, pas n'importe quel utilisateur connecté) est fait dans `SignController::assertCurrentUserIsBeneficiary()`, appelé par `show()`, `submit()` et le téléchargement du PDF. **Conséquence opérationnelle** : le bénéficiaire doit pouvoir se connecter à GLPI avec ses propres identifiants (compte AD synchronisé avec authentification LDAP, par exemple) — un compte GLPI qui existe uniquement pour la gestion de parc mais sans authentification possible bloquerait la signature.
- **CSRF** : GLPI valide automatiquement le jeton CSRF pour toute requête POST vers une page de plugin (`Hooks::CSRF_COMPLIANT`). Ne rappelez **jamais** `Session::checkCSRF()` vous-même dans un front controller : le jeton est à usage unique et serait déjà consommé, ce qui provoquerait un faux « Access denied ».
- **Cible de notification** : `NotificationTargetRemise` n'utilise **ni** `Notification::ITEM_USER` (bénéficiaire) **ni** `Notification::ITEM_TECH_IN_CHARGE` (technicien) : ces types natifs font un `INNER JOIN` sur `Profile_User` (`NotificationTarget::getProfileJoinCriteria()`) qui exige un profil GLPI actif dans l'entité — silencieusement sans erreur si absent. **Vérifié en conditions réelles** : `ITEM_TECH_IN_CHARGE` ne notifiait jamais le technicien dans l'environnement de test, malgré un profil actif en base, un e-mail configuré et `users_id_tech` correctement rempli. Deux types personnalisés (`TARGET_BENEFICIARY`, `TARGET_TECHNICIAN`) notifient directement par e-mail via `addUserFieldByEmail()`, sans cette jointure. Ce n'est pas lié à la connexion requise pour signer : ça concerne uniquement l'envoi de l'e-mail par le moteur de notifications GLPI (beaucoup d'employés synchronisés depuis l'Active Directory, cf. `python/sync_ad_users_glpi.py`, ont un compte GLPI sans profil associé — et un technicien à profil restreint peut être dans le même cas).
- **Nom des Documents GLPI (`getDocumentTitle()`) ET contenu du PDF volontairement non traduits** : le PDF non signé est généré pendant le hook `item_add`/`item_update` (session du **technicien** qui fait l'affectation), le PDF signé pendant `submit()` de la page de signature (session du **bénéficiaire**). Utiliser `__()` pour le type ("Remise"/"Restitution"), les titres (`Remise::getPdfHeadings()`) ou le type de matériel (`Computer::getTypeName()`, lui aussi traduit côté cœur GLPI) ferait donc dépendre la langue du **contenu du document** (pas seulement son nom de fichier) de qui a déclenché l'action — **constaté en conditions réelles** : un même PDF affichant `<h1>Equipment handover form</h1>` puis `<h2>Bénéficiaire</h2>` juste en dessous, ou une cellule "Type : Computer" au milieu d'un tableau autrement entièrement en français. `getCanonicalTypeLabel()` et `getCanonicalItemtypeLabel()` fixent volontairement ces libellés dans une seule langue (français), indépendamment de `getTypes()`/`getTypeName()` natif (qui restent traduits, à raison, pour tout ce qui est réellement propre à un utilisateur donné : formulaires d'administration, e-mails localisés par destinataire). Un PDF archivé est un document unique, potentiellement relu par plusieurs personnes à des dates différentes — ce n'est pas un contenu qui doit varier selon qui l'a généré, contrairement aux e-mails (localisés par destinataire, cf. plus haut) ou aux écrans d'administration (langue du compte de l'admin qui les consulte).
- **E-mails multilingues** : chaque gabarit de notification a deux traductions (`NotificationTemplateTranslation`) — `language => ''` (française, universelle) et `language => 'en_GB'`. GLPI choisit automatiquement (`NotificationTemplate::getByLanguage()` : `WHERE language IN ($language, '') ORDER BY language DESC LIMIT 1`) celle qui correspond à la langue du compte GLPI du destinataire, sinon retombe sur la française. Testé en direct : un utilisateur en `fr_FR` et un utilisateur en `en_GB` affectés au même type de matériel reçoivent chacun l'e-mail dans leur langue. `NotificationTargetRemise::install()` ajoute aussi la traduction `en_GB` manquante sur une installation déjà existante (montée de version), sans dupliquer ni casser l'existant.
- **Document::add()** attend le fichier physique dans `GLPI_TMP_DIR` (entrée `_filename`), pas dans un dossier arbitraire.
- **Mode de notification** : la valeur exacte est `Notification_NotificationTemplate::MODE_MAIL` (= la chaîne `'mailing'`, pas `'mail'`).
- **Commandes console de plugin** : GLPI les découvre en scannant récursivement `src/` (et `inc/`) à la recherche de tout fichier se terminant par `Command.php` dont la classe étend `Symfony\Component\Console\Command\Command` — aucun enregistrement dans `setup.php` n'est nécessaire, contrairement aux autres extensions du plugin. Seule contrainte : le nom de la commande doit commencer par `plugins:remise:`.
- **Traductions dans les gabarits Twig** : la fonction Twig est `__('texte', 'remise')` (une fonction, enregistrée par `I18nExtension`), pas un filtre `|trans` — GLPI ne fournit pas ce filtre, l'utiliser fait planter le rendu (`Twig\Error\SyntaxError`).
- **Menu d'administration (`Hooks::MENU_TOADD`)** : la documentation du hook dans `Glpi\Plugin\Hooks` décrit un format `['types' => [...], 'icon' => '...']` par catégorie — **ce n'est pas ce que le code lit réellement** (`Html::generateMenuSession()`). Le code attend une liste PLATE de classes directement : `['admin' => [Remise::class, Template::class, Config::class]]`. Suivre la documentation littéralement casse le menu sur **toutes** les pages GLPI (pas seulement celles du plugin), avec l'erreur `Twig\Error\RuntimeError: "Class name must be a valid object or a string"`. L'icône s'auto-détecte depuis la première classe listée qui implémente `getIcon()` — il n'y a pas de clé `'icon'` séparée à fournir.
- **Actifs personnalisés et `plugin_init_<plugin>()`** : `Glpi\Asset\AssetDefinitionManager::getInstance()->getDefinitions()` renvoie **toujours un tableau vide** quand on l'appelle depuis `plugin_init_remise()`. Cause : l'ordre des listeners de boot de GLPI (`Glpi\Kernel\ListenersPriority::POST_BOOT_LISTENERS_PRIORITIES`) exécute `InitializePlugins` (priorité 110, qui déclenche `plugin_init_<plugin>()` pour chaque plugin actif) **avant** `CustomObjectsBoot` (priorité 100, qui appelle `AssetDefinitionManager::bootDefinitions()` et peuple réellement le cache des définitions). Un plugin ne peut donc pas découvrir les actifs personnalisés existants au moment de son propre `plugin_init`, même si la table `glpi_assets_assetdefinitions` contient déjà des lignes actives. Contournement utilisé ici (`Config::getAllManageableItemtypes()`) : une requête SQL directe sur `glpi_assets_assetdefinitions`, disponible dès la connexion DB (priorité 190, largement avant `InitializePlugins`), en reconstruisant le nom de classe selon la convention de GLPI (`Glpi\CustomAsset\{system_name}Asset`, cf. `Glpi\CustomObject\AbstractDefinition::getCustomObjectClassName()`) plutôt que de passer par le manager.
- **Héritage de configuration par entité (`Config::getForEntity()`)** : ne se limite pas à "l'entité elle-même, sinon directement la racine" — la config effective est celle de l'ancêtre **le plus proche** qui en possède une, calculée via `getAncestorsOf('glpi_entities', $entities_id)` (fonction globale de `DbUtils`) puis en gardant, parmi les ancêtres ayant une ligne de config, celui dont le champ `level` de `glpi_entities` est le plus élevé (= le plus profond/proche). Ainsi, une organisation à plusieurs niveaux (Racine > Région > Site) peut configurer le plugin sur une entité intermédiaire et voir ce réglage s'appliquer à ses entités filles sans config propre, même si la racine n'a pas non plus de config.
- **Type "Échange" retiré** : `Remise::TYPE_EXCHANGE` (valeur `2`) et le réglage `sign_on_exchange` ont été supprimés — un transfert direct entre deux personnes crée désormais une remise normale (`TYPE_HANDOVER`) au nouveau détenteur, comme une réaffectation classique. La valeur `2` reste volontairement commentée comme inutilisée (même convention que `STATUS_CANCELLED`/`5` pour l'ancien statut "refusé") pour ne pas être réutilisée par erreur pour autre chose. Montée de version : `Template::install()` désactive (`is_active = 0`, sans supprimer) tout gabarit existant de type `2` sur une installation déjà en place, plutôt que de le laisser trainer avec un type qui n'existe plus dans `Remise::getTypes()`.
- **`runReminders()`/`runExpiration()` résolvent la config PAR REMISE, pas globalement** : une version antérieure appelait `Config::getDefault()` (racine) une seule fois avant la boucle pour lire `reminder_delays`/`max_reminders`/`link_validity_days`, ignorant silencieusement toute config posée sur une entité non-racine — ces deux réglages n'avaient donc *jamais* d'effet pour les tâches automatiques (cron GLPI et commandes console alternatives), contrairement à la relance manuelle (`sendReminderNow()`), qui elle utilisait déjà correctement `Config::getForEntity()`. Corrigé : chaque remise résout maintenant sa propre config via `Config::getForEntity((int) $remise->fields['entities_id'])` à l'intérieur de la boucle. Conséquence technique pour `runExpiration()` : le filtre de délai ne peut plus se faire dans la clause SQL `WHERE` (une seule valeur ne suffit plus, chaque entité a potentiellement sa propre durée de validité) — il se fait désormais en PHP, ligne par ligne, après récupération de toutes les remises encore ouvertes. **Vérifié en conditions réelles** : une entité avec des réglages volontairement très courts (délai de relance = 1 jour, validité = 5 jours) déclenche bien relance et expiration alors que la racine (3,7,7 jours / 30 jours) ne l'aurait pas fait pour les mêmes remises.
- **Cartes de tableau de bord (`CardProvider`) restreintes à l'entité active** : une version antérieure comptait les remises de **tout GLPI**, sans tenir compte de l'entité active choisie dans le sélecteur en haut de l'écran — incohérent avec `Glpi\Dashboard\Provider::bigNumberItem()` (le mécanisme natif GLPI dont ces cartes s'inspirent), qui applique systématiquement `getEntitiesRestrictCriteria($table, '', '', $item->maybeRecursive())` dès que l'itemtype possède un champ `entities_id` (`isEntityAssign()`). Corrigé : `countByStatus()` ajoute désormais `getEntitiesRestrictCriteria(Remise::getTable(), '', '', true)` à la clause `WHERE`, exactement comme le cœur GLPI. **Vérifié en conditions réelles** avec deux entités contenant des remises « en attente » (7 sur la racine, 1 sur une entité de test) : en restreignant la session à la racine seule, la carte renvoie exactement 7 (elle exclut bien la remise de l'entité de test) ; en restreignant à l'entité de test seule, elle renvoie exactement 1.

## Structure du plugin

```
remise/
├── composer.json           # dépendance Dompdf
├── setup.php / hook.php    # déclaration, hooks, install/uninstall
├── src/GlpiPlugin/Remise/  # classes métier (PSR-4)
├── front/                  # contrôleurs (remise, template, config, sign public)
├── templates/               # gabarits Twig (admin + PDF + page de signature)
├── js/sign/                 # signature_pad.js, PDF.js, logique de la page de signature
└── locales/                 # modèle de traduction (remise.pot)
```

## Licence

GPL-2.0-or-later, conformément aux conventions des plugins GLPI.
