# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added

- **Valeur résiduelle (linéaire / durée personnalisable / saisie manuelle)** (issue #77,
  cf. ROADMAP.md tableau V2 et `docs/design/ADR-passeport-v1.md`) : nouvel indicateur en tête
  du Passeport matériel, estimation simple (pas un module comptable complet) pour aider à
  trancher réemploi vs sortie de parc. Calcul linéaire à partir du prix d'achat et de la date
  d'achat déjà lus depuis `Infocom` (même source que la fiche d'identité/le score de santé,
  jamais dupliquée) : `valeur = prix_achat × max(0, 1 − âge_jours / durée_jours)`, plafonné à
  0. Durée de vie utile "personnalisable" au sens de la roadmap = un nouveau réglage par
  entité (`Config::residual_value_duration_months`, mois, défaut 60), pas une deuxième méthode
  de calcul - onglet dédié « Valeur résiduelle » de la page de configuration
  (`Config::enable_residual_value`, activé par défaut). Aucune valeur inventée : rien n'est
  affiché tant que le prix d'achat Infocom est inconnu, exactement comme l'âge/le temps
  utilisé le font déjà. Saisie manuelle toujours prioritaire sur le calcul automatique
  (nouvelle classe `ResidualValue`, table dédiée `glpi_plugin_assetsign_residualvalues`
  1-vers-1 sur `itemtype`/`items_id` - un type d'item natif GLPI ne pouvant recevoir de
  colonne supplémentaire, même patron que `VenteDetails`/`Movement`), avec un petit
  formulaire inline sur l'onglet Passeport matériel (`front/residualvalue.form.php`,
  `Api\ResidualValueFormController`) permettant de saisir une valeur ou de revenir au calcul
  automatique - jamais un simple repli dégradé, un vrai choix toujours disponible. Le libellé
  distingue toujours explicitement une valeur « estimée » d'une valeur « saisie manuelle »,
  pour ne jamais laisser croire à l'un ou l'autre à tort.

- **Mouvements structurés (départ/destination/documents/signature)** (issue #75, cf.
  `docs/design/ADR-passeport-v1.md` section 3.2 pour le schéma initialement proposé, tranché
  pendant l'analyse #76) : nouvelle classe `Movement`, généralisant la notion de remise
  (toujours personne A → personne B) à n'importe quel déplacement physique de matériel
  (transfert inter-site, retour au stock, envoi vers un centre de réparation...) - lieu et date
  de départ, lieu et date de destination (`Location`, dropdown natif GLPI, jamais une nouvelle
  table de lieux), document(s) joint(s) (onglet Documents natif, `Document`/`Document_Item`,
  jamais un nouveau stockage), signature optionnelle (`Signature`, même mécanisme déjà utilisé
  par les fiches de maintenance, jamais un second système). Déviation assumée par rapport au
  schéma initialement proposé dans l'ADR (deux colonnes ajoutées à `glpi_plugin_assetsign_assetsigns`
  plutôt qu'une classe séparée) : un mouvement doit pouvoir exister sans aucune remise associée
  (ex: un simple retour au stock n'a pas de bénéficiaire à faire signer), documentée dans l'ADR
  lui-même. Nouvel onglet « Mouvements » sur chaque matériel géré, page dédiée (Outils >
  Mouvements, liste avec filtres de recherche réels + création autonome), statuts colorés
  (Prévu/En cours/Terminé/Annulé) avec actions de transition. Alimente la même frise que le
  Passeport matériel (`glpi_plugin_assetsign_events`, nouveau type d'événement `TYPE_MOVEMENT`) -
  un nouveau producteur d'événements, jamais une réécriture des producteurs existants. Fonctionnalité
  et signature optionnelle toutes deux opt-in par entité (`Config::enable_movements`/
  `enable_movement_signature`, défaut désactivé, comme les autres fonctionnalités du plugin).

- **Checklists de contrôle qualité configurables, réutilisables sur les mouvements de matériel**
  (issue #74, cf. `docs/design/ADR-passeport-v1.md` pour l'analyse complète et les 6 risques
  techniques tranchés en amont, issue #76) : nouveau catalogue `ChecklistItem` (Configuration >
  Intitulés, même motif que les points de contrôle de maintenance existants - case à cocher, texte
  libre ou menu déroulant), chaque point taggé sur les types de mouvement où il s'applique
  (Attribution/Restitution/Don/Vente). Un nouveau bloc « Contrôle qualité » apparaît sur la fiche
  d'une Attribution tant qu'elle reste modifiable (résultats déjà enregistrés toujours affichés,
  formulaire d'édition tant que `isStillEditable()`), quel que soit le mode de création
  (déclenchement automatique par affectation/État, ou création manuelle Don/Vente). Le nombre de
  contrôles remplis (badge coloré, X/Y) est fusionné dans la frise du Passeport matériel ET
  utilisateur sans jamais dupliquer la donnée dans `glpi_plugin_assetsign_events` (pure agrégation
  à l'affichage, batchée en 2 requêtes au total pour toute la frise - jamais une par événement).
  Nouvel index composite `(itemtype, items_id, date)` sur `glpi_plugin_assetsign_events` au
  passage (risque de volume identifié pendant l'analyse). « Mouvements structurés » (issue #75)
  reste analysé mais non implémenté (schéma SQL proposé dans l'ADR) plutôt que de livrer les deux
  items V1 à moitié.

## [2.3.1] - 2026-08-25

### Security

- **`front/opcache_reset.php` protégé uniquement par une restriction d'adresse IP contournable**
  (revue de sécurité marketplace GLPI, sévérité faible, issue #98) : la vérification
  `REMOTE_ADDR==127.0.0.1/::1` seule est contournable derrière un reverse-proxy en mode loopback
  (`REMOTE_ADDR` vaut alors `127.0.0.1` pour tout le trafic externe), permettant un appel répété
  non authentifié (petit déni de service CPU). Corrigé en ajoutant un jeton partagé généré une
  seule fois à l'installation (`plugin_assetsign_install()`, persisté dans
  `GLPI_PLUGIN_DOC_DIR`, hors racine web), vérifié via `hash_equals()` en plus de (pas à la place
  de) la restriction IP existante conservée en défense en profondeur. `update.sh` (seul appelant
  réel de ce endpoint) lit ce même jeton sur le système de fichiers et le transmet. Vérifié en
  conditions réelles : appel sans jeton ou avec un jeton incorrect → 403 ; avec le bon jeton → 200
  ; cycle complet `update.sh` (migration, activation, vidage de cache, réinitialisation d'OPcache)
  confirmé fonctionnel de bout en bout.

### Fixed

- **Listes « Gestion des fiches » et « Fiches de maintenance » : aucune colonne ne permettait
  d'ouvrir la fiche elle-même** : la colonne ID était un simple nombre non cliquable (datatype
  `number`), et la seule colonne déjà cliquable (le matériel concerné, `items_id`) pointe vers la
  fiche du matériel lui-même (Ordinateur, etc.), pas vers l'attribution/la fiche de maintenance en
  question (constat direct du porteur du plugin sur un écran comparable d'un autre plugin de la
  même famille : clic sur l'ID fonctionne, clic sur le libellé de la ligne ne fait rien). La colonne
  ID passe désormais en `datatype => 'itemlink'` (`itemtype => self::class`) sur
  `Assetsign::rawSearchOptions()` et `Maintenance::rawSearchOptions()`, vérifié en conditions
  réelles (HTML rendu : `<a href="…/assetsign.form.php?id=…">`/`<a href="…/maintenance.form.php?id=…">`).

## [2.3.0] - 2026-08-24

### Added

- **Traductions complétées dans les 5 langues** (#91) : régénéré `locales/assetsign.pot` via
  l'outil d'extraction officiel (`vendor/bin/extract-locales`, nouvelle dépendance dev
  `glpi-project/tools`, absente jusqu'ici) plutôt qu'une comparaison manuelle à la regex, ce qui a
  révélé 77 chaînes ajoutées au fil des dernières fonctionnalités (passeport matériel, score de
  santé, réforme automatique, gabarits...) jamais traduites en anglais/allemand/espagnol/italien.
  Traduites dans les 4 langues, `fr_FR.po` complété (langue source, chaîne identique). 8 chaînes
  devenues obsolètes par des renommages antérieurs (ex. « remise » → « Attribution », #89/#90)
  marquées comme telles (`#~`), pas supprimées.
- **Vérification CI de complétude des traductions** (#91, suggestion explicite de l'issue) :
  nouveau job "locales" qui régénère le `.pot` à chaque run et échoue si une chaîne du code n'a pas
  de traduction dans une des 5 langues, pour éviter que ce manque se reproduise à chaque nouvelle
  fonctionnalité.

### Changed

- README (FR/EN) : la section Installation (prérequis, récupération du code, installation/
  activation, droits, configuration) est désormais reprise directement dans le README au lieu
  d'être uniquement accessible via un lien vers [INSTALLATION.md](INSTALLATION.md), qui reste la
  référence pour le détail complet (script de mise à jour, pièges de cache Twig/OPcache).
- USER_GUIDE.md (FR/EN) : documente désormais la date de réforme automatique sur changement
  d'État (v2.2.0), absente du guide alors que la fonctionnalité était déjà publiée.

### Fixed

- **Incohérence de terminologie EN/DE/ES sur « Réforme »** : les traductions de #91 utilisaient un
  mot indépendant (« Disposal »/« Ausmusterung »/« Baja ») au lieu du libellé natif que GLPI
  lui-même utilise pour ce même champ (`Infocom::decommission_date`, confirmé dans
  `locales/en_GB.po`/`de_DE.po`/`es_ES.po` du cœur GLPI : « Decommission date » /
  « Außerbetriebnahme » / « Desmantelamiento »). Corrigé pour aligner ce plugin sur le vocabulaire
  déjà utilisé par l'onglet Finances/Infocom natif juste à côté, un utilisateur ne doit pas voir
  deux mots différents pour le même champ. `it_IT` n'était pas concerné (traduction déjà alignée).

- **Cartes de tableau de bord (`Hooks::DASHBOARD_CARDS`) disparaissant silencieusement en présence
  d'un autre plugin actif hookant le même point d'extension** (confirmé en conditions réelles avec
  `glpi-vulnerability-manager` sur une instance partagée : ses cartes étaient absentes du sélecteur
  « Ajouter une carte » à cause de ce bug côté `assetsign`) : `plugin_assetsign_dashboard_cards()`
  déclarait une signature sans paramètre et retournait uniquement ses propres cartes, alors que
  `Plugin::doHookFunction()` enchaîne tous les callbacks enregistrés via le même accumulateur
  (`$ret = call_user_func($function, $ret)` pour chacun, sans jamais faire de `array_merge()`
  lui-même) : un callback qui ignore cet accumulateur écrase donc systématiquement la contribution
  de tout plugin déjà passé dans la chaîne. Corrigé en acceptant `?array $cards = null` (nullable :
  GLPI appelle le tout premier callback de la chaîne avec un `null` explicite, pas un tableau vide)
  et en fusionnant dessus, même correctif que celui appliqué en premier sur
  `glpi-vulnerability-manager`.

## [2.2.2] - 2026-08-19

### Changed

- **Termine le nettoyage d'affichage « remise » → « Attribution »** amorcé par la v2.2.1 : cette dernière avait renommé le mot « Assetsign » en « Attribution », mais avait délibérément laissé de côté plusieurs chaînes utilisant encore le mot français « remise » pour ce même concept (dette d'une migration `remise` → `assetsign` incomplète depuis la v2.0.0), pour rester strictement dans le périmètre du mot « Assetsign » et ne pas risquer de collision avec le renommage de classe `Assetsign` → `Remise` en cours sur la branche `dev` (toujours non fusionné, toujours non impacté par ce correctif). « Attribution » étant désormais le mot établi pour ce concept côté utilisateur, ce correctif aligne les chaînes restantes sur la même convention. **Renommage d'affichage uniquement** — aucun identifiant de code touché (classes, namespace, méthodes, tables, clé de domaine gettext).
  - Libellés de type (`Accessory::getTypeName()`, `Template::getTypeName()`, recherche « Type d'attribution » sur `Assetsign`/`Template`/`NotificationTargetAssetsign`), texte semé à l'installation d'un gabarit par défaut.
  - Message d'erreur (matériel sans utilisateur assigné), description des 2 CronTask et des 2 commandes CLI équivalentes, message de relance impossible sur une fiche déjà signée/expirée.
  - Nom de l'événement de notification « Nouvelle attribution de matériel » (Administration > Notifications) et libellé du tag `##assetsign.type##`.
  - Libellé de type et en-têtes du PDF de la fiche d'Attribution (`Workflow\HandoverType`) — `getCanonicalLabel()` reste volontairement non traduit (utilisé comme nom de Document GLPI, indépendant de la langue de qui a déclenché l'action, cf. le commentaire de `Assetsign::getCanonicalTypeLabel()`), mais suit la même convention de mot.
  - Titre de la page de signature, textes d'aide de la page de Configuration (x4 : durée de validité, champ Observations, fiche de maintenance, rétention Passeport matériel), message de création automatique sur la fiche utilisateur, message « aucune attribution » sur l'onglet matériel.
  - `locales/*.po` (5 langues) + `assetsign.pot`, recompilés en `.mo` — plusieurs de ces chaînes n'avaient en réalité **jamais été traduites dans aucune langue** (msgid orphelins d'une étape de nommage encore plus ancienne, « assetsign » au lieu de « remise » ou « attribution » — dette antérieure à ce correctif, qui faisait retomber silencieusement l'affichage sur le texte français brut dans les 4 langues non françaises), corrigé au passage en réutilisant le mot « Attribution » déjà établi par langue (`Attribution`/`Attributions` en anglais et français, `Zuweisung`/`Zuweisungen` en allemand, `Atribución`/`Atribuciones` en espagnol, `Attribuzione`/`Attribuzioni` en italien). Une entrée orpheline déjà identifiée et volontairement laissée par la v2.2.1 (l'ancien message mentionnant l'onglet « Assetsigns ») n'a pas été retouchée — même raison, nettoyage séparé.
  - **Vérifié en conditions réelles** sur l'environnement Docker de test (GLPI 11.0.8), en français et en anglais : les 10 onglets de la page Configuration (Général/Attribution/Restitution/Don/Vente/Réforme/Compléments/Maintenance/Passeport matériel/Score de santé), un cycle complet attribution → signature → restitution → signature en tant que Technicien (profil non admin), création et signature d'une fiche de don et d'une fiche de vente, changement d'État déclenchant la réforme (`Infocom::decommission_date`) toujours fonctionnel après ce correctif, frise du Passeport matériel et onglet Passeport utilisateur affichant les bons libellés pour les 5 événements. Aucune erreur PHP/SQL dans les journaux GLPI pendant toute la session. Suite PHPUnit (175 tests), phpcs et phpstan au vert.

## [2.2.1] - 2026-08-19

### Changed

- **Renommage d'affichage « Assetsign » → « Attribution »** pour le type de fiche (remise/prêt de matériel) : le mot anglais « Assetsign » restait visible tel quel dans une interface par ailleurs en français (ex: l'onglet de la page Configuration, capture d'écran fournie par l'utilisateur — « Général | Assetsign | Restitution | Don | Vente | ... »), sans traduction française pour ce mot forgé. Choix retenu par l'utilisateur parmi trois options proposées (Assignation / Prêt / Attribution). **Renommage d'affichage uniquement** : aucun identifiant de code touché (classes, namespace `GlpiPlugin\Assetsign`, méthodes, tables, clé de domaine gettext `assetsign`) — indépendant du renommage de classe `Assetsign` → `Remise` en cours sur la branche `dev` (non fusionné, non impacté par ce changement).
  - Onglet de la page Configuration (`config_form.html.twig`), titre de fiche (`assetsign_form.html.twig`), libellé + compteur de l'onglet sur un matériel/utilisateur (`Assetsign::getTypeName()`/`getTabNameForItem()`), message d'erreur de la page de signature (« Attribution introuvable. »), les trois cartes de tableau de bord par statut (`hook.php` : en attente de signature / signées / expirées), texte d'aide de la Configuration (boutons Don/Vente, onglet Réforme, description du Passeport matériel), pied de page du PDF de remise (« Attribution #N »), confirmation de relance groupée, commentaires des deux tâches planifiées concernées (Setup > Actions automatiques).
  - Traductions mises à jour dans les 5 langues (`locales/*.po` + `.pot`, recompilées en `.mo` via `msgfmt`) — chaque langue traduit désormais proprement le concept d'« Attribution » (ex: `Attribution`/`Attributions` en anglais, `Atribución`/`Atribuciones` en espagnol, `Zuweisung`/`Zuweisungen` en allemand, `Attribuzione`/`Attribuzioni` en italien) plutôt que d'hériter du mot « Handover »/équivalent utilisé jusqu'ici pour ce même concept — les 5 langues convergent maintenant sur un seul mot cohérent.
  - **Le nom de marque du plugin lui-même, « Assetsign & signature » (et ses variantes, ex: `AssetSign — Signature électronique`, l'objet e-mail de test), est resté volontairement inchangé** : décision produit distincte, non demandée ici.
  - **Deux entrées de traduction déjà orphelines avant ce changement** (ancien message de création manuelle mentionnant `l'onglet "Assetsigns"`, remplacé depuis par un lien direct — cf. « Réalisé récemment (2026-08-05) » ci-dessous) laissées telles quelles : aucun code ne les produit plus, un nettoyage général des chaînes orphelines reste une tâche à part (déjà menée par le passé, cf. TROUBLESHOOTING.md).
  - Vérifié en conditions réelles sur l'environnement Docker de test (GLPI 11.0.8) : onglet de la page Configuration affichant bien « Attribution » à l'emplacement exact de la capture d'écran fournie, onglet d'un matériel affichant « Attributions » avec son compteur, aucune occurrence résiduelle du mot « Assetsign » dans le HTML rendu en dehors de « Assetsign & signature » (marque, volontairement conservée).

## [2.2.0] - 2026-08-19

### Added

- **Date de réforme automatique sur changement d'État** (cf. ROADMAP.md, issue #78) : nouveau groupe d'États configurable par entité (`Config::getReformeStates()`, onglet « Réforme » de la configuration), même mécanisme que les déclenchements Assetsign/Restitution/Don/Vente déjà existants. Quand le matériel passe dans l'un de ces États, le plugin écrit désormais automatiquement `Infocom::decommission_date` (champ natif GLPI, libellé « Réforme ») à la date du jour — la frise du Passeport matériel l'affichait déjà en lecture seule (`PassportEvent::getInfocomPseudoEvents()`), elle n'était simplement jamais renseignée sans saisie manuelle jusqu'ici. Effet de bord **pur** sur Infocom : aucune fiche Assetsign créée, aucun nouveau type d'événement Passeport (décision documentée dans le commentaire de l'utilisateur sur l'issue #78 — pas besoin de dupliquer une notion de « fiche » pour une simple date déjà native à GLPI). Une date de réforme déjà renseignée (saisie manuelle ou déclenchement précédent) n'est **jamais écrasée**. `Infocom::canApplyOn()` respecté (aucune écriture pour un itemtype retiré des types compatibles Infocom au niveau du cœur GLPI), Infocom créé s'il n'existait pas encore pour ce matériel. Un même État peut être configuré à la fois pour la réforme et un autre déclenchement (ex: Vente) : les deux mécanismes sont indépendants et se déclenchent tous les deux pour le même changement. Vérifié en conditions réelles sur l'environnement Docker de test : script contre le vrai noyau GLPI (vrai hook `item_update`, pas un appel direct à la logique de déclenchement) **et** flux HTTP complet (login réel, création d'un matériel et changement d'État via `front/computer.form.php` comme un vrai administrateur, confirmation en base et dans la frise du Passeport matériel via `ajax/common.tabs.php`) — les deux confirment l'écriture de la date du jour et la non-altération d'une date déjà saisie manuellement.

## [2.1.0] - 2026-08-17

### Added

- **Droits par défaut pour Admin et Technician** : une installation fraîche n'accordait de droits sur AssetSign qu'au profil Super-Admin — même Technician, qui traite les remises au quotidien, n'avait aucun accès tant qu'un Super-Admin n'allait pas les accorder manuellement. Admin et Technician reçoivent désormais automatiquement un droit d'usage courant (consulter/créer/modifier des remises et des fiches de maintenance, pas de suppression ni de configuration) à l'installation. Un profil déjà installé n'est jamais modifié rétroactivement — un admin qui personnalise ou révoque ce droit voit son choix conservé lors des futures mises à jour du plugin.
- **Habillage des notifications e-mail** : les 5 notifications du plugin (nouveau document, relance, signé, expiré, sur le point d'expirer) étaient en HTML brut sans mise en forme. Elles utilisent désormais un gabarit visuel sobre (bandeau, nom de l'entreprise si renseigné, pied de page), cohérent avec le soin déjà apporté aux fiches PDF. Sans logo (contrairement aux PDF) : les images encodées en data URI sont trop souvent bloquées par les clients de messagerie pour être fiables dans un e-mail.
- Documentation : nouvelle section « Accorder les droits aux profils qui en ont besoin » dans INSTALLATION.md.

### Fixed

- **Message trompeur après une signature réussie** : `Token::validate()` vérifiait l'invalidité générique du jeton avant de vérifier s'il avait déjà été utilisé avec succès — un bénéficiaire qui rechargeait la page de signature juste après avoir signé (ou dont la connexion coupait pendant que le traitement serveur se terminait) voyait « Ce lien de signature n'est plus valide » au lieu du message correct « Ce document a déjà été traité ». Ordre des vérifications corrigé.
- **Date de vente non formatée** sur la fiche PDF : affichée au format ISO brut (`2026-08-17`) au lieu du format français utilisé partout ailleurs sur le même document (`17/08/2026`).

## [2.0.2] - 2026-08-17

### Fixed

- **Nom du bénéficiaire/technicien vide sur les fiches PDF (remise, maintenance) et la page de
  signature** pour tout compte GLPI sans `firstname`/`realname` renseignés (cas des comptes de
  démonstration `glpi`/`normal`/`tech`/`post-only`, et de tout compte réel créé sans remplir ces
  champs optionnels) : 9 emplacements sur 12 utilisaient un simple
  `trim(firstname . ' ' . realname)` sans repli sur le login, produisant une chaîne vide au lieu du
  nom d'utilisateur. Corrigé en réutilisant partout `formatUserName()` (PHP) ou un repli explicite
  sur `user.name` (Twig) — le motif déjà utilisé correctement ailleurs dans la base de code
  (`Assetsign.php`, `Maintenance.php`, `NotificationTargetAssetsign.php`).
  - Découvert et confirmé sur un vrai document signé lors de la vérification exhaustive du plugin
    (fiche PDF affichait « Signataire : (normal@test.local) », nom manquant).
  - Fichiers concernés : `HandoverPdfBuilder.php`, `SignController.php`, `Maintenance.php`,
    `PassportEvent.php` (×2), `handover.html.twig`, `maintenance.html.twig`, `sign_page.html.twig`,
    `assetsign_form.html.twig`.

## [2.0.1] - 2026-08-16

### Fixed

- **Bug bloquant pour tout utilisateur sans droit `plugin_assetsign_assetsign`/`plugin_assetsign_maintenance`** :
  `Assetsign::getMenuContent()` et `Maintenance::getMenuContent()` déclarent un type de retour `: array`
  mais renvoyaient tel quel le résultat de `parent::getMenuContent()`, qui peut légitimement valoir
  `false` (aucun menu à afficher pour cet utilisateur). Conséquence : `TypeError` non rattrapée dès le
  rendu du menu (`Html::generateMenuSession()`), qui plantait **toute page GLPI** pour n'importe quel
  compte sans ces droits — y compris juste après la connexion. Corrigé en retournant `[]` au lieu de
  `$menu` dans ce cas (équivalent falsy côté appelant, mais conforme au type déclaré).
  - Découvert lors d'un test de bout en bout avec un compte non-admin (profil « normal ») dans le
    cadre de la vérification exhaustive post-renommage `assetsign` — non couvert par la suite
    PHPUnit existante (exécutée jusque-là uniquement avec un compte Super-Admin) ni par le premier
    cycle Playwright de la v2.0.0.
  - Suite complète re-vérifiée après correctif : 170 tests / 378 assertions vertes, aucune régression.

## [2.0.0] - 2026-08-16

### Changed

- **BREAKING** : renommage complet de la clé technique du plugin, `remise` → `assetsign`, pour une
  diffusion à un public international ("remise" n'a aucun sens hors français, et peut même se
  confondre avec "réduction"). Casse toute installation existante — aucune migration fournie
  (décision explicite : aucune installation connue en dehors de l'instance de test de ce dépôt).
  Réinstallation propre requise : désinstaller `remise`, installer `assetsign`.
  - Namespace PHP `GlpiPlugin\Remise` → `GlpiPlugin\Assetsign`, classe cœur `Remise` →
    `Assetsign`, et toutes les classes qui en dérivaient (`RemiseAccessory` →
    `AssetsignAccessory`, `NotificationTargetRemise` → `NotificationTargetAssetsign`,
    `RemiseFormController` → `AssetsignFormController`).
  - 15 tables `glpi_plugin_remise_*` → `glpi_plugin_assetsign_*`.
  - 4 droits de profil (`plugin_remise_*` → `plugin_assetsign_*`), tâches CronTask
    (`remiseReminders`/`remiseExpire`/`remiseExpiryWarning`/`remiseCleanupTokens` →
    équivalents `assetsign*`), commandes console (`plugins:remise:*` → `plugins:assetsign:*`).
  - Manifeste marketplace `remise.xml` → `assetsign.xml`, dépôt GitHub et paquet Composer
    `remise-glpi` → `assetsign-glpi`.
  - Vocabulaire métier français (remise/restitution/don/vente comme types de fiche, distincts les
    uns des autres) délibérément **non traduit** — seule la clé/marque du plugin change, pas les
    mots français ordinaires qui décrivent chaque workflow.
  - Vérifié en conditions réelles avant publication : installation et activation propres sur une
    instance GLPI 11 fraîche (zéro erreur PHP), suite complète (170 tests, 378 assertions) verte,
    cycle réel affectation → détection → génération PDF → onglets Assetsigns/Passeport matériel
    confirmé de bout en bout via Playwright.

## [1.23.0] - 2026-08-12

### Fixed

- CI : le job `semgrep` tolérait silencieusement tout échec (`continue-on-error: true`), alors
  qu'il fait partie des status checks requis sur `main` depuis la veille (v1.22.0) — dans les
  faits, ce statut "requis" ne bloquait rien de réel. Retiré, et les deux findings jusque-là
  masqués par cette tolérance corrigés pour de vrai : le script d'installation Trivy (`curl | sh`)
  remplacé par un téléchargement direct du binaire + vérification de checksum SHA256 officiel ; un
  faux positif d'instanciation dynamique dans `front/assign_user_asset.php` (même garde-fou déjà
  utilisé ailleurs dans le code) supprimé explicitement via un commentaire `// nosemgrep: ...`
  justifié, plutôt que masqué globalement.

### Added

- CI : couverture de code réelle sur la suite PHPUnit (`pcov`, résumé affiché dans les logs de la
  CI via `--coverage-text` — pas d'upload externe, aucun compte Codecov lié à ce dépôt). Nécessite
  `<source><include>` dans `phpunit.xml` (absent jusqu'ici, sans quoi PHPUnit échoue avec
  `failOnWarning="true"` plutôt que de simplement ignorer la couverture).

Suite du rapprochement CI/CD avec le plugin jumeau Configuration-glpi-auto (v1.22.0) — corrige
cette fois deux angles morts découverts en creusant plus loin : un check "requis" qui ne l'était
pas vraiment, et une couverture de tests jamais mesurée. Aucun changement de comportement du
plugin lui-même. Un test end-to-end du flux de signature (Playwright) a été évalué mais reporté :
le flux réel (authentification du bénéficiaire spécifique + jeton + rotation CSRF, cf.
`front/sign.php`) est plus complexe qu'un test de fumée ne peut couvrir correctement sans plus de
temps dédié.

## [1.22.0] - 2026-08-12

### Added

- CI : job `validation` (`.github/workflows/ci.yml`) qui vérifie que tous les fichiers JSON, YAML
  et XML du dépôt sont bien formés (`composer.json`, `.github/workflows/*.yml`,
  `docs/remise.xml`...), indépendamment du reste de la CI (ni PHP ni Docker/GLPI requis).
  Configuration yamllint dédiée (`.yamllint.yml`).
- CI : exécution nocturne planifiée (`schedule: cron`) de `ci.yml`, en plus des déclenchements sur
  push/PR — détecte une régression qui n'apparaîtrait qu'avec le temps (nouvelle image
  `glpi/glpi`, avisory de sécurité publié après coup sur une dépendance déjà mergée) sans attendre
  un prochain push.
- CI : annulation automatique des exécutions en cours de la même branche/PR dès qu'un nouveau push
  arrive (`concurrency`), pour ne pas empiler des runs Docker/GLPI concurrents.
- `.github/dependabot.yml` : labels automatiques (`dependencies`, `security`, `ci`) sur les PR
  Dependabot, pour un triage plus rapide.
- Ce fichier CHANGELOG.md.

Alignement de la CI/CD sur les pratiques déjà en place sur le plugin jumeau
[Configuration-glpi-auto](https://github.com/parime/Configuration-glpi-auto). Aucun changement de
comportement du plugin lui-même.

[Unreleased]: https://github.com/parime/remise-glpi/compare/v1.23.0...HEAD
[1.23.0]: https://github.com/parime/remise-glpi/releases/tag/v1.23.0
[1.22.0]: https://github.com/parime/remise-glpi/releases/tag/v1.22.0
