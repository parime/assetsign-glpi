# ADR : Passeport numérique du cycle de vie matériel - analyse et socle V1

- **Statut** : Accepté
- **Date** : 2026-08-25
- **Répond à** : issue [#76](https://github.com/parime/assetsign-glpi/issues/76) ("Passeport : analyse architecture + schema SQL + ADR avant la suite")
- **Contexte source** : ROADMAP.md, sections "Vision produit à long terme" et "Risques techniques à trancher pendant la phase d'analyse"

Ce document est la session d'analyse dédiée demandée par ROADMAP.md avant de reprendre le Passeport numérique (reste du V1, puis V2/V3). Il (1) analyse l'architecture actuelle, (2) tranche explicitement les 6 risques listés dans l'issue #76, (3) propose le schéma SQL/les classes/hooks nécessaires pour le reste du V1, et (4) redécoupe la roadmap par version en tickets réels, croisés avec les issues GitHub déjà ouvertes.

## 1. Analyse de l'architecture actuelle

Le Passeport (MVP livré le 2026-08-04, cf. ROADMAP.md) suit déjà strictement les trois couches documentées dans ROADMAP.md ("Architecture fonctionnelle globale") :

1. **Socle** : `glpi_plugin_assetsign_events` (classe `PassportEvent`), une ligne = un événement métier immuable. Alimenté exclusivement par `PassportEvent::recordForAssetsign()`/`recordForMaintenance()`, appelées respectivement depuis `Assetsign::launchWorkflow()` (point de passage commun à toutes les voies de création : déclenchement automatique par affectation/État, création manuelle Don/Vente) et `Maintenance::createWithChecklist()`. **`Assetsign`/`Maintenance` restent les seules sources de vérité pour leur propre workflow** (statut, PDF, preuve de signature) - `PassportEvent` ne fait qu'agréger, jamais dupliquer.
2. **Vue** : `PassportEvent::showForItem()`/`showForUser()` - fiche d'identité, "vies", frise chronologique. Aucune table supplémentaire : Infocom et les tickets liés sont fusionnés dans la frise en pseudo-événements calculés à l'affichage (`getInfocomPseudoEvents()`, `getLinkedTicketPseudoEvents()`), jamais copiés.
3. **Indicateurs** : score de santé (`getHealthScore()`) et indicateurs temporels (`getIdentityCard()`), calculés à l'affichage à partir de la couche 1, aucune table dédiée à ce jour (cf. risque de performance, section 2.4 ci-dessous).

Points d'ancrage déjà en place et réutilisés tels quels par ce document : `Config::getForEntity()` (résolution de configuration héritée par entité), le registre `Workflow\WorkflowTypeRegistry` (un type de fiche = une classe), le motif dropdown+valeurs (`MaintenanceChecklistItem` + `glpi_plugin_assetsign_maintenancechecklistvalues`), et le motif d'édition post-création guardée par `isStillEditable()` (`addAccessory()`, `updateObservations()`, `updateVenteDetails()`).

## 2. Les 6 risques (issue #76) - décisions

### 2.1 Volume de la table d'événements

**Décision** : l'index existant `KEY item (itemtype, items_id)` + `KEY date (date)` sur `glpi_plugin_assetsign_events` reste **insuffisant** pour la requête réellement exécutée par `showForItem()` (`WHERE itemtype = ? AND items_id = ? AND event_type IN (...) ORDER BY date`) - un index composite couvrant est nécessaire pour éviter un tri en mémoire (filesort) dès que l'historique d'un matériel grossit. **Action, implémentée dans cette PR** : ajout d'un index composite `item_date (itemtype, items_id, date)` via une migration additive (`Migration::addKey()`, idempotent par `hasKey()`), sans jamais recréer la table ni toucher aux données existantes.

Le même principe (index composite dès la création, pas en réaction à un ralentissement) s'applique aux **nouvelles** tables de ce document : `glpi_plugin_assetsign_checklistvalues` porte déjà une contrainte `UNIQUE (plugin_assetsign_assetsigns_id, plugin_assetsign_checklistitems_id)`, qui sert aussi d'index pour la requête de lecture (`WHERE plugin_assetsign_assetsigns_id = ?`).

### 2.2 Ne pas dupliquer l'inventaire GLPI natif

**Décision, déjà appliquée et reconduite** : aucune donnée déjà native GLPI (modèle, fabricant, n° série, État, utilisateur/entité actuels) n'est recopiée dans le Passeport - `getIdentityCard()` les relit à chaque affichage via `Dropdown::getDropdownName()`/`User::getFriendlyNameById()`. Même principe strictement reconduit pour les checklists qualité (section 3) : `ChecklistItem` (catalogue) et `glpi_plugin_assetsign_checklistvalues` (résultats) ne stockent que des données **propres au plugin** (jamais un doublon d'un champ Computer/Monitor/Infocom), et le résumé affiché dans la frise du Passeport (`PassportEvent::attachChecklistSummaries()`) est calculé à l'affichage par jointure sur `source_items_id`, jamais copié dans `glpi_plugin_assetsign_events`.

### 2.3 RGPD / droit à l'oubli vs traçabilité

**Décision** : le mécanisme déjà en production pour le snapshot bénéficiaire du Passeport (`Config::passport_retention_years` par entité, `0` = jamais, `PassportEvent::anonymizeOldSnapshots()` en tâche planifiée quotidienne qui vide `snapshot_name`/`snapshot_email` sans jamais toucher `users_id`) **s'étend correctement, sans modification**, aux nouvelles données de ce document : les résultats de checklist (`glpi_plugin_assetsign_checklistvalues`) ne portent **aucune donnée personnelle** (une valeur de contrôle qualité - "Accessoires complets", "Housse remise" - décrit l'état du matériel, jamais une personne), donc **hors du périmètre RGPD** de ce mécanisme, pas besoin de l'étendre. Si le V1 "mouvements structurés" (issue #75, non implémenté dans cette PR, cf. section 4) introduit un jour un champ libre type "signataire externe", il devra suivre le même patron d'anonymisation par ancienneté - noté explicitement dans le ticket de suivi plutôt que traité par anticipation ici.

### 2.4 Performance des indicateurs calculés

**Décision** : le résumé de checklist qualité ajouté à la frise (`attachChecklistSummaries()`) suit la même règle déjà appliquée par `getHealthScore()`/`getIdentityCard()` - calculé à l'affichage, mais **en 2 requêtes batchées au total pour toute la frise** (jamais une requête par événement affiché, jamais de N+1) : le nombre de points *applicables* est mis en cache en mémoire par type de mouvement le temps de l'appel (au plus 4 valeurs distinctes), le nombre de points *remplis* est récupéré en une seule requête `GROUP BY plugin_assetsign_assetsigns_id` sur l'ensemble des `source_items_id` concernés. Pas de cache persistant (`$GLPI_CACHE`) pour ce chiffre précis : à la différence de `Config::getLatestGithubVersion()` (appel réseau externe coûteux, cache 24h), une requête SQL groupée sur l'historique d'**un seul matériel** reste largement sous le seuil qui justifierait la complexité d'invalidation d'un cache. Si un futur indicateur V2 (valeur résiduelle, moteur de décision) s'avère coûteux à l'échelle du parc entier (pas d'un seul matériel), il devra passer par un recalcul planifié (`CronTask`) écrivant dans une table dédiée - ROADMAP.md notait déjà `glpi_plugin_assetsign_asset_metrics` comme table candidate pour cette couche 3 : **confirmée comme bon choix** pour V2, non créée dans cette PR (aucun indicateur V2 n'est implémenté ici).

### 2.5 Dépendance à une API externe (passeport environnemental)

**Décision (V3, non implémenté ici, tranchée par anticipation pour ne pas la rouvrir plus tard)** : le futur `glpi_plugin_assetsign_environmental_data` (issue #80) devra porter un champ `source` (`manufacturer` / `external_api` / `manual`) et un champ `confidence_level`, avec la **saisie manuelle toujours disponible et jamais un simple repli dégradé** - c'est-à-dire un vrai formulaire d'édition manuelle visible même quand l'intégration API est configurée et fonctionnelle, pas seulement quand elle échoue. Un appel à une API externe (ex: Boavizta, cf. ROADMAP.md "Envisagé") devra être **best-effort et jamais bloquant** pour l'affichage du Passeport (même principe que `Config::getLatestGithubVersion()` : `try`/`catch` autour de l'appel, mise en cache de la valeur obtenue, jamais une erreur remontée à l'utilisateur si l'API est indisponible).

### 2.6 Migration progressive sans casser Remise/Maintenance existants

**Décision, déjà appliquée et vérifiée dans cette PR** : le catalogue de checklist qualité (`ChecklistItem`) est une classe **volontairement séparée** de `MaintenanceChecklistItem` (jamais fusionnée ni renommée) et sa table de résultats (`glpi_plugin_assetsign_checklistvalues`) est **strictement nouvelle**, sans aucune modification de `glpi_plugin_assetsign_maintenancechecklistvalues`, `glpi_plugin_assetsign_assetsigns` (hors ajout de méthodes, aucune colonne changée) ni des workflows `Assetsign::launchWorkflow()`/`createManual()`/`handleStateBasedTrigger()`/`handleUserBasedTrigger()` déjà en place. Vérifié en conditions réelles (section 5) : les onglets Passeport matériel/utilisateur, Attribution/Restitution/Don/Vente et Maintenance continuent de fonctionner à l'identique après ce changement.

## 3. Schéma SQL et classes pour la suite du V1

### 3.1 Checklists de contrôle qualité configurables (issue #74) - implémenté dans cette PR

```sql
CREATE TABLE glpi_plugin_assetsign_checklistitems (
    id               int unsigned NOT NULL AUTO_INCREMENT,
    entities_id      int unsigned NOT NULL DEFAULT 0,
    is_recursive     tinyint NOT NULL DEFAULT 0,
    name             varchar(255) NOT NULL DEFAULT '',
    comment          text,
    is_active        tinyint NOT NULL DEFAULT 1,
    type             tinyint NOT NULL DEFAULT 0,   -- 0=case a cocher, 1=texte libre, 2=menu deroulant
    options          text,                          -- options du menu deroulant, une par ligne
    movement_types   text,                           -- JSON des Assetsign::TYPE_* applicables
    date_creation    timestamp NULL DEFAULT NULL,
    date_mod         timestamp NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY entities_id (entities_id),
    KEY is_recursive (is_recursive),
    KEY name (name)
);

CREATE TABLE glpi_plugin_assetsign_checklistvalues (
    id                                  int unsigned NOT NULL AUTO_INCREMENT,
    plugin_assetsign_assetsigns_id      int unsigned NOT NULL,
    plugin_assetsign_checklistitems_id  int unsigned NOT NULL,
    value                               text,
    PRIMARY KEY (id),
    UNIQUE KEY unicity (plugin_assetsign_assetsigns_id, plugin_assetsign_checklistitems_id),
    KEY plugin_assetsign_checklistitems_id (plugin_assetsign_checklistitems_id),
    CONSTRAINT fk_acv_assetsign FOREIGN KEY (plugin_assetsign_assetsigns_id) REFERENCES glpi_plugin_assetsign_assetsigns (id) ON DELETE CASCADE,
    CONSTRAINT fk_acv_checklistitem FOREIGN KEY (plugin_assetsign_checklistitems_id) REFERENCES glpi_plugin_assetsign_checklistitems (id) ON DELETE CASCADE
);
```

**Classes** : `ChecklistItem` (`CommonDropdown`, catalogue admin, Configuration > Intitulés + menu Assetsign, même motif que `MaintenanceChecklistItem`) ; méthodes ajoutées sur `Assetsign` (`getChecklistResults()`, `getChecklistValuesByItemId()`, `setChecklistValues()`, gardées par `isStillEditable()` - même patron que `addAccessory()`) ; `PassportEvent::attachChecklistSummaries()` (agrégation en lecture pour la frise, section 2.4).

**Point d'attache choisi (décision d'architecture)** : le formulaire de checklist est posé sur la fiche `Assetsign` elle-même (`assetsign_form.html.twig`, comme les accessoires/observations/marqueurs de dommage), **pas** sur le formulaire de création manuelle (`assetsign_tab.html.twig`) ni sur la page de signature publique (`front/sign.php`). Raison : `Attribution`/`Restitution` sont **presque toujours** créées automatiquement (déclenchement par changement d'utilisateur/État, `handleUserBasedTrigger()`/`handleStateBasedTrigger()`, qui s'exécutent en plein milieu d'un hook `item_update` du cœur GLPI - bloquer pour une saisie utilisateur y est architecturalement impossible, déjà documenté dans ROADMAP.md 2026-08-05 à propos de `Html::redirect()`). Le formulaire d'édition post-création (`isStillEditable()`) est le seul point où un contrôle qualité peut réellement être saisi par un technicien, **quel que soit** le mode de création (automatique ou manuel via `createManual()` pour Don/Vente) - un seul point d'entrée pour les 4 types, cohérent avec le fait qu'une checklist "avant chaque mouvement" doit rester remplissable même quand la fiche a été créée sans formulaire.

**Hors périmètre explicite de cette PR** (noté pour ne pas être oublié) : le PDF généré (`Pdf\HandoverPdfBuilder`) n'inclut pas encore les résultats de checklist. Une inclusion dans le PDF serait un ajout naturel mais indépendant, laissé à un futur ticket plutôt que d'élargir cette PR.

### 3.2 Mouvements structurés (issue #75) - analysé, non implémenté dans cette PR

Schéma proposé (à valider avant implémentation, pas figé) :

```sql
ALTER TABLE glpi_plugin_assetsign_assetsigns
    ADD COLUMN locations_id_from int unsigned NOT NULL DEFAULT 0 AFTER items_id,
    ADD COLUMN locations_id_to   int unsigned NOT NULL DEFAULT 0 AFTER locations_id_from;
```

Départ/destination réutiliseraient la table native `glpi_locations` (`Location`, dropdown natif GLPI) - **jamais** une nouvelle table de lieux, cf. risque 2.2. Les documents joints réutiliseraient `Document_Item` (relation polymorphe déjà native GLPI, déjà importée dans `Assetsign.php` pour le PDF signé/non signé) plutôt qu'une nouvelle table `glpi_plugin_assetsign_documents`. La signature existe déjà (`Signature`/`SignatureStamper`) - "mouvements structurés" n'en a donc besoin d'aucune nouvelle. **Raison de ne pas l'implémenter dans cette PR** : consigne explicite de préférer livrer un item V1 complet plutôt que deux items à moitié faits ; ticket de suivi créé (section 4) avec ce schéma comme point de départ documenté pour la prochaine session.

**Mise à jour (implémentation réelle, PR de suivi)** : le schéma ci-dessus a été révisé sur un point avant implémentation - une classe/table strictement **nouvelle** (`Movement`/`glpi_plugin_assetsign_movements`), plutôt que `ALTER TABLE glpi_plugin_assetsign_assetsigns ADD locations_id_from/to`. Raison : un mouvement structuré doit pouvoir exister **sans aucune remise associée** (un simple retour au stock, ou un transfert inter-site, n'a pas de bénéficiaire à faire signer) - greffer ça sur `Assetsign` aurait forcé chaque mouvement à porter tous les champs d'une remise (bénéficiaire, jeton, statut de signature à distance...) même quand ils n'ont aucun sens. Les trois principes ci-dessus (réutilisation stricte de `Location`, `Document`/`Document_Item`, `Signature`) sont scrupuleusement respectés dans l'implémentation - seul le support de stockage change, jamais le principe de ne rien dupliquer. La signature suit le même patron que `Maintenance` (personne déjà authentifiée, pas de jeton/e-mail/PDF dédié), opt-in par entité (`Config::enable_movements`/`enable_movement_signature`). Voir section 4 pour le statut à jour.

## 4. Roadmap par version - redécoupage en tickets réels

| Version | Fonctionnalité | Issue | Statut après cette PR |
|---|---|---|---|
| V1 | Checklists de contrôle qualité configurables | [#74](https://github.com/parime/assetsign-glpi/issues/74) | **Livré** par cette PR (`ChecklistItem`, formulaire sur `Assetsign`, résumé dans la frise du Passeport) |
| V1 | Mouvements structurés (départ/destination/documents/signature) | [#75](https://github.com/parime/assetsign-glpi/issues/75) | **Livré** (PR de suivi) - classe `Movement` dédiée (déviation documentée ci-dessus par rapport au schéma initialement proposé), `Location`/`Document_Item`/`Signature` natifs réutilisés tels quels, nouveau producteur sur la frise du Passeport (`PassportEvent::TYPE_MOVEMENT`) |
| V1 (suivi) | Index composite `(itemtype, items_id, date)` sur `glpi_plugin_assetsign_events` | *(nouveau, cf. risque 2.1)* | **Livré** par cette PR (migration additive, aucune régression fonctionnelle) |
| V2 | Valeur résiduelle | [#77](https://github.com/parime/assetsign-glpi/issues/77) | Non commencé - dépend de la Fiche d'identité (déjà livrée) |
| V2 | Fin de vie structurée (vente/don/destruction) | [#78](https://github.com/parime/assetsign-glpi/issues/78) | **Livré** : date de réforme automatique (2026-08-19), puis prestataire/certificat (destruction, nouveau type `Assetsign::TYPE_DESTRUCTION`) et organisme/justificatif (don, `DonDetails`) le 2026-08-26 (PR de suivi) |
| V2 | Module d'aide à la décision | [#79](https://github.com/parime/assetsign-glpi/issues/79) | Non commencé - dépend du score de santé (livré) et de la valeur résiduelle (#77) |
| V3 | Passeport environnemental | [#80](https://github.com/parime/assetsign-glpi/issues/80) | Non commencé - risque externe tranché par anticipation (section 2.5) |
| V3 | Bénéfice du réemploi | [#81](https://github.com/parime/assetsign-glpi/issues/81) | Non commencé - dépend de #80 |
| V3 | QR code sur le matériel | [#82](https://github.com/parime/assetsign-glpi/issues/82) | Non commencé |
| V3 | Kits/accessoires avec contrôle automatique | [#83](https://github.com/parime/assetsign-glpi/issues/83) | Non commencé - dépend des checklists (#74, livré) |
| V3 | Dashboard RSE, app mobile technicien, signatures multiples | [#84](https://github.com/parime/assetsign-glpi/issues/84) | Non commencé, grab-bag à redécouper le moment venu |
| - | Repères d'état des lieux visuel : veille récidive décalage | [#86](https://github.com/parime/assetsign-glpi/issues/86) | Watch-only, sans rapport avec le Passeport - non traité ici |

## 5. Vérification

Voir CHANGELOG.md `[Unreleased]` et la description de la Pull Request associée à cette PR pour le détail de la vérification en conditions réelles (checklists créées/remplies sur les 4 types de mouvement, résumé affiché dans la frise du Passeport matériel/utilisateur, régression zéro sur les onglets Passeport/Attribution/Maintenance existants), la suite PHPUnit dédiée et les traductions des 5 nouvelles chaînes dans les 5 langues.
