# Feuille de route

Ce document liste ce qui est **envisagé**, pas engagé sur une date précise. Pour ce qui manque déjà aujourd'hui de façon plus factuelle, voir [USER_GUIDE.md](USER_GUIDE.md#ce-qui-nest-pas-encore-implémenté).

## Envisagé

- **Recherche RSE / empreinte carbone** ("Passeport environnemental", V3 de la roadmap) : piste explorée partiellement — un plugin GLPI Carbon existe déjà, basé sur l'API Boavizta (self-hosted ou endpoint public `https://api.boavizta.org`, sans garantie de SLA/rate-limit publiée pour l'endpoint public) — à approfondir avant tout engagement de date.
- **Proposer la création automatique des intitulés de base sur un GLPI fraîchement installé** — aujourd'hui, `install()` sème déjà quelques valeurs par défaut pour les intitulés propres au plugin (`Accessory`, `MaintenanceChecklistItem`, `Template`), mais rien ne compense l'absence d'intitulés **cœur GLPI** que le plugin utilise (ex: Etats déclencheurs de remise/restitution/don/vente) sur une instance neuve sans configuration métier existante — l'onglet Configuration affiche alors des listes de déclenchement par État vides, sans qu'il soit évident pour l'administrateur qu'il faut aller les créer ailleurs (Configuration > Listes déroulantes > États) avant que le plugin soit réellement utilisable. Idée : proposer (pas imposer) la création d'un jeu d'États de base pertinents pour le workflow du plugin, détectée quand aucun État n'existe encore ou qu'aucun n'est configuré comme déclencheur.
- **Repères d'état des lieux visuel : décalage occasionnel signalé par l'utilisateur, cause probable identifiée et corrigée côté ressenti, mais pas totalement élucidée** — le positionnement lui-même (calcul `left`/`top` en %) s'est révélé exact au pixel dans tous les tests manuels (fiche Remise admin, page de signature réelle, fenêtre réduite à 900px) ; la vraie cause plausible du "repère pas au bon endroit" était plutôt la **latence perçue** : chaque ajout de repère attendait la régénération complète du PDF côté serveur (`Remise::refreshDamageAnnotationPdf()`) avant de s'afficher, mesurée à ~4,3 à 4,8 secondes dans l'environnement Docker de test — largement le temps pour l'utilisateur de cliquer ailleurs ou de perdre le fil avant que le repère n'apparaisse enfin à l'endroit du clic initial. **Corrigé** : affichage optimiste du repère (apparaît en ~10ms au clic, `public/js/sign/damage-annotation.js`), la confirmation serveur reste asynchrone en arrière-plan. Si un décalage réel (pas seulement perçu) se reproduit malgré ça, il faudra une capture d'écran précise (point cliqué vs position obtenue, navigateur/zoom) pour aller plus loin.

## Réalisé récemment (2026-08-30)

- **Module d'aide à la décision - livré** (issue #79, cf. tableau V2 ci-dessous) : troisième
  indicateur du Passeport matériel, après le score de santé et la valeur résiduelle dont il
  dépend explicitement (toutes deux déjà livrées, cf. entrées ci-dessous). Moteur de règles
  simple à seuils, explicitement **pas** du machine learning ("architecture prête pour de
  l'IA plus tard sans y aller maintenant", au sens où `getDecisionAidRecommendations()` reste
  le seul point d'entrée consulté par l'affichage - un futur moteur différent pourrait la
  remplacer sans rien toucher d'autre, sans construire d'abstraction supplémentaire par
  anticipation) : « Prévoir un remplacement » sous le seuil de score de santé déjà réglable
  (réutilisé tel quel, aucun second réglage redondant), « Réévaluer l'usage » sous un
  pourcentage réglable du prix d'achat d'origine (seul nouveau réglage introduit,
  `Config::residual_value_low_threshold_percent`). Chaque règle exige sa propre donnée
  source réellement disponible - jamais une recommandation inventée. Plusieurs règles
  déclenchées : toutes affichées, jamais une seule masquant les autres. Calculé à
  l'affichage, jamais persisté, même principe que les deux indicateurs dont il dépend.

## Réalisé récemment (2026-08-27)

- **Valeur résiduelle (linéaire / durée personnalisable / saisie manuelle) - livrée** (issue #77,
  cf. tableau V2 ci-dessous) : nouvel indicateur en tête du Passeport matériel, calculé à
  l'affichage à partir du prix/de la date d'achat déjà lus depuis `Infocom` (même source que
  la fiche d'identité/le score de santé, jamais dupliquée) - `valeur = prix_achat × max(0, 1 −
  âge_jours / durée_jours)`, plafonné à 0. La "durée personnalisable" est un réglage par
  entité (`Config::residual_value_duration_months`, mois, défaut 60), pas une deuxième méthode
  de calcul. Saisie manuelle toujours prioritaire sur le calcul automatique (nouvelle classe/
  table dédiée `ResidualValue`, même patron 1-vers-1 `itemtype`/`items_id` que `Movement`),
  avec un petit formulaire inline sur le Passeport matériel pour saisir une valeur ou revenir
  au calcul automatique - jamais un simple repli dégradé. Aucune valeur inventée quand le prix
  d'achat est inconnu, exactement comme l'âge/le temps utilisé.

## Réalisé récemment (2026-08-26)

- **Fin de vie structurée : destruction (prestataire/certificat) et don (organisme/justificatif) - livrées**
  (issue #78, dernier volet restant après la date de réforme automatique livrée le 2026-08-19, cf.
  entrée ci-dessous) : nouveau type `Assetsign::TYPE_DESTRUCTION`, même traitement complet que
  Don/Vente (déclenchement manuel, déclenchement automatique par État configurable, coordination
  bidirectionnelle État ↔ fiche, gabarit PDF dédié). Le Don gagne un organisme bénéficiaire, jusqu'ici
  sans aucune donnée dédiée. Prestataire/organisme stockés dans deux nouvelles tables dédiées
  (`DestructionDetails`/`DonDetails`, même motif que `VenteDetails`) ; certificat/justificatif : upload
  de fichier attaché comme Document natif GLPI directement sur la fiche (`Assetsign::attachUploadedDocument()`,
  même motif que `Movement::attachDocument()`), sans nouvelle table de stockage de fichiers. Le
  prix/acheteur/documents de la Vente restent hors périmètre (déjà couverts, cf. ligne "Fin de vie
  structurée" ci-dessous). Détail complet dans CHANGELOG.md `[Unreleased]`.
- **QR code imprimable sur le matériel - livré** (issue #82, cf. tableau V3 ci-dessous et
  `docs/design/ADR-passeport-v1.md`) : bouton « Imprimer une étiquette QR code » sur
  l'onglet Passeport matériel, page dédiée minimaliste (`front/qrlabel.php` +
  `templates/qr_label.html.twig`, bouton `window.print()`, CSS `@media print`). Le QR
  code encode une URL absolue (`$CFG_GLPI['url_base']`, même convention que
  `Assetsign::getSignUrl()`) utilisant `forcetab` (même convention que le lien « créez
  maintenant la fiche » de `Assetsign::handleStateBasedTrigger()`) pour ouvrir
  directement l'onglet Passeport matériel du matériel scanné. Aucun mécanisme d'accès
  anonyme introduit — la connexion GLPI habituelle reste exigée, cohérent avec le reste
  du plugin. Génération du QR code extraite de `Pdf\PdfRenderingHelpers` (jusque-là
  privée, ne servait que le PDF) vers une nouvelle classe partagée `QrCode`, réutilisée
  telle quelle par les deux. Nouveau réglage d'entité `enable_qr_label` (défaut actif,
  distinct de `show_qr_code` qui ne concerne que le PDF).

## Réalisé récemment (2026-08-25)

- **Analyse architecture + schéma SQL + ADR (issue #76) - livrée** : `docs/design/ADR-passeport-v1.md` tranche explicitement les 6 risques techniques listés dans la section dédiée ci-dessous, propose le schéma SQL du reste du V1 et redécoupe la roadmap par version en tickets réels croisés avec les issues GitHub ouvertes.
- **Checklists de contrôle qualité configurables - livrées** (issue #74, cf. tableau V1 ci-dessous et ADR pour le détail architectural) : nouveau catalogue `ChecklistItem` réutilisable sur les 4 types de mouvement Assetsign (Attribution/Restitution/Don/Vente), formulaire d'édition sur la fiche `Assetsign` elle-même tant qu'elle reste modifiable, résumé (X/Y contrôles, badge coloré) fusionné dans la frise du Passeport matériel/utilisateur sans dupliquer la donnée. Index composite `(itemtype, items_id, date)` ajouté sur `glpi_plugin_assetsign_events` au passage (risque de volume identifié pendant l'analyse).

## Réalisé récemment (2026-08-19)

- **Fin du nettoyage d'affichage « remise » → « Attribution » — livré** (v2.2.2, suite directe de la v2.2.1 ci-dessous) : la v2.2.1 avait volontairement laissé de côté les chaînes utilisant encore le mot français « remise » pour ce même concept (dette antérieure à la v2.0.0), pour rester dans le strict périmètre du mot « Assetsign » et ne pas risquer de collision avec le renommage de classe en cours sur `dev`. Toutes ces chaînes (libellés de type, message d'erreur, nom d'événement de notification, description des tâches planifiées/commandes CLI, en-têtes du PDF de la fiche d'Attribution, textes d'aide de Configuration) suivent désormais la même convention « Attribution ». Corrigé au passage : plusieurs de ces chaînes n'avaient en réalité jamais été traduites dans aucune des 5 langues (dette encore plus ancienne, orpheline d'une étape de nommage antérieure utilisant le mot « assetsign »). Détail complet dans CHANGELOG.md [2.2.2]. Re-testé en conditions réelles (Docker, admin + Technicien non admin, chaque sous-workflow jusqu'au bout : attribution, restitution, don, vente, réforme, Passeport matériel et utilisateur) sans aucune régression détectée.
- **Renommage d'affichage « Assetsign » → « Attribution » — livré** (demande directe de l'utilisateur, capture d'écran de la page Configuration à l'appui : le mot anglais « Assetsign » restait visible tel quel dans une interface par ailleurs en français). Choix retenu parmi trois options proposées (Assignation / Prêt / Attribution). **Renommage d'affichage uniquement** — aucun identifiant de code touché (classes, namespace `GlpiPlugin\Assetsign`, méthodes, tables, clé de domaine gettext `assetsign`), et **explicitement indépendant** du renommage de classe `Assetsign` → `Remise` actuellement en cours sur la branche `dev` (non fusionné à ce jour, non impacté par ce changement). Le nom de marque du plugin lui-même (« Assetsign & signature ») est resté volontairement inchangé — décision produit distincte, non demandée ici. Détail complet dans CHANGELOG.md [2.2.1].
- **Date de réforme automatique sur changement d'État — livrée** (idée soumise par l'utilisateur le 2026-08-18, cf. issue #78 et commentaire de l'utilisateur dessus) : `Infocom::decommission_date` ("Réforme") n'était jusqu'ici que saisi à la main, simplement lu et affiché dans la frise du Passeport matériel (`PassportEvent::getInfocomPseudoEvents()`, jamais écrit par le plugin). Réutilise exactement le même mécanisme que `Config::getHandoverStates()`/`getReturnStates()`/`getDonationStates()`/`getVenteStates()` + `Assetsign::handleStateBasedTrigger()` (déclenchement sur `states_id`, hook `item_update`) : nouveau groupe d'États configurable par entité (`Config::getReformeStates()`, onglet « Réforme » de la configuration), qui écrit désormais automatiquement `Infocom::decommission_date` à la date du jour quand le matériel passe dans l'un de ces États — la frise l'affiche alors sans aucune saisie manuelle.
  - **Décision tranchée** (restait ouverte dans le commentaire de l'utilisateur sur #78) : effet de bord **pur** sur `Infocom`, PAS un cinquième type `Assetsign::TYPE_*` ni un nouvel événement Passeport — la frise lit déjà ce champ nativement (`getInfocomPseudoEvents()`, code d'affichage non modifié), inutile de dupliquer une notion de « fiche » pour une simple date déjà native à GLPI. Recoupe directement la ligne « Fin de vie structurée » du tableau V2 ci-dessous (issue #78 reste ouverte : ne couvre que la date automatique, pas la partie vente/don/destruction avec prestataire/certificat).
  - **Garde-fou explicite demandé** : une date de réforme déjà renseignée (saisie manuelle, ou déclenchement automatique précédent) n'est **jamais écrasée** — vérifié par un test dédié et par un scénario réel (Infocom pré-existant avec une date manuelle, changement d'État, date inchangée).
  - `Infocom::canApplyOn()` respecté (même garde-fou que le code d'affichage existant : aucune écriture pour un itemtype retiré des types compatibles Infocom au niveau du cœur GLPI) ; Infocom créé via `add()` s'il n'existait pas encore pour ce matériel (jamais consulté via son propre onglet Infocom).
  - Un même État peut être configuré à la fois comme déclencheur de réforme ET d'un autre type (ex: Vente, pour un matériel vendu pour pièces détachées tout en étant sorti d'inventaire) : les deux mécanismes sont indépendants, aucun `return` anticipé dans `handleStateBasedTrigger()` pour la branche réforme.
  - **Vérifié en conditions réelles** sur l'environnement Docker de test, à deux niveaux : (1) script exécuté contre le vrai noyau GLPI (`Glpi\Kernel\Kernel`), changement d'État via un VRAI `$item->update()` (donc le vrai hook `item_update` du cœur GLPI, pas un appel direct à la logique de déclenchement comme le fait PHPUnit) — confirmé sur un matériel sans Infocom préexistant (ligne créée, date du jour) et sur un matériel avec date manuelle préexistante (inchangée) ; (2) flux HTTP complet rejouant une vraie session d'administrateur (login réel, création d'un matériel via `front/computer.form.php`, changement d'État via ce même contrôleur, lecture de la frise via `ajax/common.tabs.php` exactement comme le ferait un navigateur) — confirmé en base (`glpi_infocoms.decommission_date`) et dans le HTML réellement rendu de l'onglet Passeport matériel (entrée « Réforme » présente). Suite PHPUnit (5 nouveaux tests dédiés dans `AssetsignTest.php`, 175 tests au total), phpcs et phpstan au vert.

## Réalisé récemment (2026-08-05)

- **Cohérence bidirectionnelle entre l'État du matériel et la création manuelle d'une fiche de Don/Vente — livrée**, les deux sens demandés :
  - **Sens "création manuelle → État du matériel"** (`Remise::syncItemStateAfterManualCreation()`, appelée depuis `createManual()`) : créer une fiche de Don/Vente met désormais à jour `states_id` sur le matériel lui-même, vers le premier État configuré dans `Config::getDonationStates()`/`getVenteStates()` pour l'entité (convention documentée pour le cas — rare — de plusieurs États configurés pour un même type : aucun moyen de deviner lequel correspond à cette fiche précise). Mise à jour en SQL direct (comme `cancelPendingRemisesFor()`), volontairement PAS via `$item->update()` : un update classique redéclencherait le hook `item_update` → `handleStateBasedTrigger()` pour ce même changement, qui recréerait une deuxième fiche (bénéficiaire interne encore assigné) ou réafficherait le message du sens 2 (aucun bénéficiaire) — piège identifié et couvert par un test dédié (`testCreateManualSyncsItemStateToConfiguredDonationState`, qui vérifie explicitement qu'une seule fiche existe après coup).
  - **Sens "changement d'État → invitation à créer la fiche"** : le message affiché (`handleStateBasedTrigger()`) contient maintenant un **lien direct** vers le formulaire de création, au lieu d'un texte purement informatif. Une vraie redirection HTTP (`Html::redirect()`) est impossible depuis ce point : le hook s'exécute EN PLEIN MILIEU de la sauvegarde native de l'item (`CommonDBTM::update()` → `Plugin::doHook()`), avant que le contrôleur GLPI n'ait fini son propre travail post-sauvegarde — l'interrompre casserait des choses hors du contrôle du plugin. Le lien utilise `forcetab` (paramètre standard du cœur GLPI pour deep-linker un onglet, ex: `Toolbox::getItemTypeName()`/notifications de tickets) pour ouvrir directement l'onglet Remises, et `tab_params[remise_prefill_type]` (autre paramètre standard, même précédent que `Reservation.php` pour préremplir un champ via un lien) pour présélectionner le bon type et révéler tout de suite les champs à compléter.
    - **Piège réel rencontré et corrigé pendant l'implémentation** : un premier essai avec un simple `?remise_prefill_type=X` sur le lien ne fonctionnait PAS — vérifié en conditions réelles (curl authentifié, cf. TROUBLESHOOTING.md) que GLPI charge le contenu de chaque onglet via un **appel AJAX séparé** (`ajax/common.tabs.php`), dont l'URL est construite côté serveur à partir d'une liste fixe de paramètres (`_glpi_tab`, `_itemtype`, `id`...) qui ne reprend PAS automatiquement les paramètres additionnels de la page initiale. Seul `tab_params` (lu explicitement par `CommonGLPI::displayFullPageForItem()` et propagé dans la construction de l'URL ajax de CHAQUE onglet, cf. `CommonGLPI::showTabsContent()`) survit à ce chargement asynchrone.
  - Deux nouveaux tests dans `RemiseTest.php` (`testCreateManualSyncsItemStateToConfiguredDonationState`, `testCreateManualDoesNotTouchStateWhenNoneConfigured`) plus une extension de `testHandleStateBasedTriggerWarnsWhenDonationHasNoUser` (vérifie la présence du lien `forcetab`/`tab_params` dans le message).
  - **Vérifié en conditions réelles** (au-delà de PHPUnit) : script exécuté dans le vrai bootstrap GLPI (`Glpi\Kernel\Kernel`) contre la base de test réelle — changement d'État sans utilisateur → message avec lien correct ; création manuelle → État du matériel synchronisé, une seule fiche créée. Puis test HTTP complet (login réel, `curl`) confirmant que le lien généré aboutit bien, une fois l'onglet chargé en AJAX comme un vrai navigateur le ferait, à un bouton de type "Don" pré-sélectionné dans le formulaire.

## Réalisé récemment (2026-08-04)

- **Tests automatisés des contrôleurs `front/remise.form.php`/`front/maintenance.form.php`** : logique extraite dans `Api\RemiseFormController`/`Api\MaintenanceFormController` (même principe que `Api\SignController`), couverte par `RemiseFormControllerTest`/`MaintenanceFormControllerTest`. `accessory.form.php`/`maintenancechecklistitem.form.php` volontairement laissés tels quels (délégation pure à `CommonDBTM`, rien à extraire).
- **Gabarits édités directement dans les onglets de configuration** (Remise/Restitution/Don/Vente) — plus besoin de naviguer vers l'écran séparé `front/template.form.php` pour le gabarit par défaut de chaque type ; la liste complète (Configuration > Intitulés) reste accessible pour le cas de plusieurs gabarits par type/entité.
- **Fil d'Ariane cohérent avec l'emplacement réel du plugin** (secteur `tools`, pas `admin`) sur toutes les pages du plugin, y compris depuis Configuration > Intitulés pour les Gabarits/Accessoires/Points de contrôle de maintenance (`getSectorizedDetails()`/`getMenuContent()` sur `Remise`/`Maintenance`/`Template`/`Accessory`/`MaintenanceChecklistItem`).
- **Nom de l'entreprise** (texte affiché à côté du logo sur les PDF) et **protection PDF** (chiffrement contre copie/édition, impression toujours autorisée) — idées identifiées après une revue comparative de plugins concurrents.
- **Affichage optimiste des repères d'état des lieux visuel** (apparaissent immédiatement au clic, sans attendre la régénération du PDF côté serveur) — cf. point ci-dessus sur la lenteur restante.
- Passe UX/UI exploratoire de la page de configuration : pas d'incohérence trouvée au-delà de ce qui précède.
- **Passeport matériel — MVP livré** (voir section dédiée ci-dessous pour le détail architectural) : table `glpi_plugin_remise_events`, classe `PassportEvent` (attribution/restitution/don/vente/maintenance), nouvel onglet sur le matériel avec frise chronologique (style natif GLPI : ligne verticale, coche/point plein) et compteur de "vies", anonymisation RGPD du snapshot bénéficiaire configurable (`Config::passport_retention_years`, `CronTask` quotidien), activable/désactivable (`Config::enable_passport`) et filtrable par type d'événement affiché, le tout dans son propre onglet "Passeport matériel" de la page de configuration.
- **Passeport utilisateur — MVP livré** (vue symétrique, cf. section dédiée ci-dessous) : nouvel onglet "Passeport utilisateur" sur la fiche d'un compte GLPI (`PassportEvent::showForUser()`), même frise chronologique que le Passeport matériel mais filtrée par `users_id` — reçu/rendu/donné/acheté, avec le nom et le numéro de série de chaque matériel concerné (repli explicite et indépendant si l'un des deux manque, ex: matériel purgé depuis). Bornes de compte (entrée/désactivation-suppression) lues directement sur `glpi_users` (`begin_date`/`date_creation`, `end_date`/`date_mod`), jamais dupliquées. Aucune nouvelle table : pure vue de lecture sur le socle déjà existant.
- **Rétro-remplissage depuis `glpi_logs` — livré** (Passeport matériel ET utilisateur, cf. section dédiée ci-dessous) : la faisabilité notée "à valider" a été vérifiée en conditions réelles (`id_search_option` 70/31, stables dans le cœur GLPI, avec `old_id`/`new_id` déjà en entiers) puis implémentée — `PassportEvent::backfillFromLogs()` rejoue exactement la même logique que le déclenchement en direct (affectation ET changement d'État, sur les mêmes réglages déjà existants `Config::getHandoverStates()` et consorts), déclenché automatiquement à la première consultation d'un passeport vide, plus un bouton "Forcer la recherche" pour un nouveau passage explicite.
- **Dates Infocom fusionnées dans la frise du Passeport matériel — livré** : achat, commande, livraison, mise en service, début/fin de garantie (calculée depuis `warranty_date` + `warranty_duration`), réforme, prix d'achat — lues depuis `Infocom` au moment de l'affichage, jamais copiées dans `glpi_plugin_remise_events` (respecte le principe "ne pas dupliquer l'inventaire GLPI"). Chaque date gérée indépendamment (absence totale d'Infocom, informations partielles ou complètes toutes gérées sans jamais planter ni afficher de valeur inventée), `Infocom::canApplyOn()` respecté (aucune date affichée pour un itemtype que l'administrateur a retiré des types compatibles Infocom au niveau du cœur GLPI). Activable/désactivable par entité (`Config::show_infocom_dates`), dans le même onglet "Passeport matériel" de la configuration.
- **Fiche d'identité augmentée — livrée** : carte de synthèse en tête de l'onglet Passeport matériel (modèle, fabricant, n° série, État, utilisateur/entité actuels, achat, fin de garantie) — pure agrégation de données déjà natives GLPI, aucune nouvelle table, aucun champ obligatoire (chaque information affichée uniquement si renseignée). Toujours visible même si `Config::show_infocom_dates` est désactivé (utile indépendamment de la frise Infocom).
- **Tickets liés au matériel fusionnés dans la frise — livré** : lecture seule, jamais copiés/stockés dans `glpi_plugin_remise_events` (contrairement à Remise/Maintenance) — chaque ticket filtré par les droits RÉELS du lecteur courant (`Ticket::can(..., READ)`, jamais un simple droit générique), donc potentiellement différent d'une personne à l'autre consultant le même passeport, exactement comme l'onglet Tickets natif du matériel. Activable/désactivable par entité (`Config::show_linked_tickets`).
- **Indicateurs temporels — livrés (première brique du V2)** : âge physique (depuis l'achat Infocom si connu, sinon depuis l'entrée dans GLPI — le libellé précise toujours la source réelle), temps réellement utilisé (somme des "vies" déjà calculées, en pourcentage de l'âge), temps en stock (le reste), durée lisible affichée directement sur chaque "vie" ("X ans Y mois"). Aucune nouvelle table, calculé à l'affichage à partir de données déjà agrégées (`getLivesForItem()`), jamais une valeur inventée quand aucune date n'est disponible.
- **Score de santé matériel — livré (deuxième brique du V2)** : score 0-100 (100 = état idéal), formule standard du secteur ITAM `100 - Σ(poids × dégradation)` (méthodologie confirmée par une recherche web, cf. sources en fin de section ci-dessous). Quatre facteurs retenus sur les six suggérés à l'origine — **contrôles (checklists) et batterie volontairement omis**, aucune donnée fiable disponible dans ce plugin/GLPI pour les alimenter (décision explicite de l'utilisateur) :
  - **Âge** : dégradation linéaire jusqu'à 5 ans (seuil fixe `PLUGIN_REMISE_HEALTH_AGE_FULL_DEGRADATION_DAYS`).
  - **Incidents** : nombre brut de tickets liés au matériel (tous statuts, jamais filtré par droit — un simple compteur agrégé dans un score n'expose aucun contenu de ticket, contrairement à l'affichage des tickets eux-mêmes dans la frise).
  - **État physique** : marqueurs de dégât de l'état des lieux visuel déjà existant (`glpi_plugin_remise_damagemarkers`), un mineur = 1 point, un majeur = 2 points.
  - **Mouvements** : nombre de "vies" (changements de détenteur) — une rotation fréquente use davantage un matériel qu'une affectation stable.
  - **Poids réglables par l'administrateur** (`Config::health_weight_age`/`health_weight_incidents`/`health_weight_damage`/`health_weight_movements`, Configuration > Passeport matériel) — décision explicite de l'utilisateur (pas une formule figée). Poids **relatifs** : comptent proportionnellement au total des poids actifs, pas besoin de sommer exactement à 100 ; un poids à 0 désactive simplement ce facteur. Valeurs de départ : Âge 30, Incidents 30, État physique 25, Mouvements 15 — raisonnables mais pas une science exacte, à ajuster selon l'usage réel.
  - Activable/désactivable dans son ensemble (`Config::enable_health_score`) ; aucun score affiché si tous les poids sont à 0.
  - **Sources consultées** : la formule "100 moins la somme des dégradations pondérées et normalisées" est la méthodologie standard retrouvée dans plusieurs sources ITAM (voir liens ci-dessous) — confirme que l'approche retenue ici (facteurs → dégradation 0-100 normalisée → pondération → score inversé) suit une pratique établie, pas une invention ad hoc.
    - [About Asset Health (IFS Cloud docs)](https://docs.ifs.com/ifsclouddocs/25r1/EquipmentAdministration/AboutAssetHealth.htm)
    - [What Is Asset Health in IT Asset Management? (AssetLoom)](https://medium.com/@assetloom/what-is-asset-health-in-it-asset-management-1659eb9939b2)
    - [Asset Record Health (eTelligent Solutions)](https://www.etelligentsolutions.com/esi/mie/help/html/asset_record_health.htm)

## Vision produit à long terme : Passeport numérique du cycle de vie matériel

Direction de fond proposée par l'utilisateur, à ne pas confondre avec les points "envisagés" ci-dessus (portée bien plus large, nécessite sa propre phase d'analyse dédiée avant tout code). Le plugin ne doit plus être vu comme un simple outil de remise/prêt de matériel, mais évoluer vers :

> Le passeport numérique du cycle de vie des équipements IT dans GLPI.

Chaîne de vie visée (`Achat → Réception → Préparation → Contrôle qualité → Attribution → Changement utilisateur → Retour → Reconditionnement → Réattribution → Vente / Don / Destruction`), sans remplacer ni dupliquer les objets natifs GLPI (Computer, Monitor, Phone...) — le plugin les enrichit d'un nouvel onglet **« Passeport matériel »**, qui doit répondre à « que s'est-il passé avec ce matériel depuis son achat jusqu'à aujourd'hui ? ».

### Architecture fonctionnelle globale

Trois couches, chacune dépendant de la précédente :

1. **Socle (données brutes)** — une timeline d'événements métier immuable + des snapshots utilisateur indépendants de `users_id`. Tout le reste du passeport n'est que de la lecture/agrégation de ce socle : aucune autre couche n'a le droit d'écrire sa propre vérité parallèle sur "qui a fait quoi quand".
2. **Vue (lecture agrégée)** — l'onglet Passeport matériel lui-même : fiche d'identité, historique des utilisateurs successifs ("vies"), frise chronologique. Purement dérivé de la couche 1, aucune table supplémentaire nécessaire au-delà d'éventuels index/vues.
3. **Indicateurs et modules avancés (calculs + intégrations externes)** — score de santé, âge réel/temps utilisé, valeur résiduelle, moteur de décision, passeport environnemental. Dépendent de la couche 1 (et parfois d'une source de données externe dédiée) ; peuvent être recalculés/mis en cache sans jamais réécrire l'historique brut.

Cette séparation est ce qui permet de livrer un MVP utile (couches 1+2 seules) avant d'investir dans les couches suivantes.

### Fonctionnalités fondatrices (socle, à construire en premier)

Tout le reste de la roadmap ci-dessous dépend de ces deux éléments — aucune fonctionnalité "avancée" (score de santé, timeline graphique, valeur résiduelle...) n'est possible sans eux :

- **Timeline d'événements immuable** (table candidate `glpi_plugin_remise_events`) : achat, réception, préparation, contrôle qualité, attribution, prêt, retour, transfert, maintenance, reconditionnement, vente/don/destruction, chacun avec date, technicien, commentaire, documents/photos, signature éventuelle, état du matériel, accessoires.
- **Snapshot utilisateur indépendant de `users_id`** (table candidate `glpi_plugin_remise_users_history`) : nom, prénom, e-mail, entité, fonction éventuelle, dates de début/fin, figés au moment de l'événement — si le compte GLPI est supprimé/désactivé plus tard, l'historique reste lisible.

Table candidate supplémentaire pour la couche 3 : `glpi_plugin_remise_asset_metrics` (indicateurs calculés : santé, âge réel, valeur résiduelle) — à valider/affiner pendant la phase d'analyse dédiée, pas figée ici.

### Roadmap par version

**MVP — le socle, en lecture simple — ✅ Livré le 2026-08-04**
| Fonctionnalité | Objectif métier | Valeur utilisateur | Difficulté | Dépendances | Priorité |
|---|---|---|---|---|---|
| Timeline d'événements (table + écriture depuis les points d'entrée existants : remise, retour, maintenance, don, vente) | Ne plus perdre l'historique métier une fois une fiche traitée | Base de toute réponse à "qui a utilisé ce PC ?" | Moyenne (nouvelle table + hooks sur les workflows existants) | Aucune | Critique |
| Snapshot utilisateur à chaque attribution | Historique lisible même si l'utilisateur GLPI disparaît | Fiabilité de l'audit dans le temps | Faible | Timeline d'événements | Critique |
| Onglet "Passeport matériel" (liste chronologique brute, pas encore graphique) | Un seul endroit pour consulter la vie du matériel | Réponse directe au besoin exprimé ("qui a utilisé ce PC depuis son achat ?") | Faible/Moyenne (nouvel onglet `getTabNameForItem`/`displayTabContentForItem`, pattern déjà connu du plugin) | Timeline d'événements | Critique |
| Compteur de "vies" + détail par utilisateur avec dates | Vue rapide du nombre de mains par lesquelles est passé un matériel | Lecture immédiate, sans dérouler toute la timeline | Faible (agrégation du snapshot) | Snapshot utilisateur | Haute |
| *(ajouté pendant le MVP, hors périmètre initial)* Activation/désactivation par entité + filtres d'affichage par type d'événement | Ne pas imposer la fonctionnalité, laisser choisir ce qui apparaît dans la frise | Contrôle admin sans devoir toucher au code | Faible (booléens/JSON sur `Config`, même convention que le reste du plugin) | Timeline d'événements | Haute |

**V1 — rendre le passeport lisible et actionnable**
| Fonctionnalité | Objectif métier | Valeur utilisateur | Difficulté | Dépendances | Priorité |
|---|---|---|---|---|---|
| ~~Timeline graphique (frise verticale, cartes cliquables)~~ — **livré avec le MVP** (style natif GLPI, cf. "Réalisé récemment"). | | | | | |
| ~~Fiche d'identité augmentée~~ — **livré**, cf. "Réalisé récemment" ci-dessus. | | | | | |
| ~~Checklists de contrôle qualité configurables, réutilisables (remise, retour, vente, don)~~ - **livré le 2026-08-25** (issue #74, cf. `docs/design/ADR-passeport-v1.md`) : catalogue `ChecklistItem` (case à cocher/texte libre/menu déroulant, tagué par type de mouvement Assetsign::TYPE_*), formulaire posé sur la fiche `Assetsign` elle-même (tant qu'`isStillEditable()`, quel que soit le mode de création automatique ou manuel), résumé (X/Y contrôles) affiché dans la frise du Passeport matériel/utilisateur sans jamais dupliquer la donnée dans `glpi_plugin_assetsign_events`. « Sortie de stock » n'a pas de mouvement Assetsign formalisé à ce jour (pas de fiche créée pour ce cas) - hors périmètre de ce lot, à revoir si/quand la notion existe. | | | | | |
| Mouvements structurés (départ/destination/documents/signature) | Modéliser un mouvement comme plus qu'une ligne de remise | Cohérence de la couche socle | Moyenne | Timeline d'événements | **Livré** (issue #75, classe `Movement` dédiée - cf. `docs/design/ADR-passeport-v1.md` section 3.2 pour le schéma initialement proposé et sa déviation documentée, `Location`/`Document_Item`/`Signature` natifs réutilisés, nouveau producteur sur la frise du Passeport) |
| ~~Dates Infocom fusionnées dans la frise~~ — **livré**, cf. "Réalisé récemment" ci-dessus. | | | | | |
| ~~Tickets liés au matériel affichés dans la frise~~ — **livré**, cf. "Réalisé récemment" ci-dessus. | | | | | |
| ~~Rétro-remplissage de l'historique pour le matériel déjà existant avant l'installation du plugin, à partir de `glpi_logs`~~ — **livré**, cf. "Réalisé récemment" et section dédiée ci-dessous. | | | | | |

**V2 — indicateurs et aide à la décision**
| Fonctionnalité | Objectif métier | Valeur utilisateur | Difficulté | Dépendances | Priorité |
|---|---|---|---|---|---|
| ~~Score de santé matériel~~ — **livré**, cf. "Réalisé récemment" ci-dessus. | | | | | |
| ~~Indicateurs temporels~~ — **livré**, cf. "Réalisé récemment" ci-dessus. | | | | | |
| ~~Valeur résiduelle (linéaire / durée personnalisable / saisie manuelle)~~ — **livrée**, cf. "Réalisé récemment" ci-dessus. | | | | | |
| ~~Fin de vie structurée (vente : prix/acheteur/documents ; don : organisme/justificatif ; destruction : prestataire/certificat)~~ — **livrée** : date de réforme automatique le 2026-08-19, prestataire/certificat (destruction) et organisme/justificatif (don) le 2026-08-26 (cf. "Réalisé récemment" ci-dessus et issue #78) ; prix/acheteur/documents de la vente déjà couverts au préalable (`VenteDetails`, `users_id`) | Tracer proprement la sortie définitive | Conformité, preuve en cas de contrôle | Faible (déjà partiellement présent via Remise::TYPE_VENTE/TYPE_DON) | Timeline d'événements | Moyenne |
| ~~Module d'aide à la décision (moteur de règles simple, ex: "réévaluer"/"préparer remplacement" avec raisons)~~ — **livré**, cf. "Réalisé récemment" ci-dessus. | | | | | |

**V3 — extensions et intégrations externes**
| Fonctionnalité | Objectif métier | Valeur utilisateur | Difficulté | Dépendances | Priorité |
|---|---|---|---|---|---|
| Passeport environnemental (empreinte fabrication, source, niveau de confiance ; sources : constructeur, une API externe dédiée, saisie manuelle) | Amorcer un volet RSE réaliste, sans données inventées | Reporting environnemental crédible | Moyenne/Haute (intégration API externe, gestion de son indisponibilité) | Fiche d'identité (V1) | Basse |
| Bénéfice du réemploi ("impact évité" : durée prévue vs réelle) | Valoriser la prolongation de durée de vie | Argument RSE chiffré et transparent | Faible (calcul dérivé), une fois le passeport environnemental posé | Passeport environnemental, indicateurs temporels | Basse |
| ~~QR code sur le matériel (scan → état/historique/actions)~~ — **livré** (issue #82, cf. `docs/design/ADR-passeport-v1.md`) : bouton « Imprimer une étiquette QR code » sur l'onglet Passeport matériel, étiquette imprimable dédiée (`front/qrlabel.php`), QR code encodant un lien `forcetab` absolu vers le Passeport matériel (connexion GLPI requise si nécessaire, aucun accès anonyme introduit). | | | | | |
| Kits/accessoires avec contrôle automatique au retour | Détecter un accessoire manquant à la restitution | Réduction de perte de matériel | Moyenne (nouvelle notion de kit, au-delà des accessoires actuels) | Checklists (V1) | Basse |
| Dashboard RSE, app mobile technicien, signatures multiples | Extensions déjà identifiées comme envisageables | — | Haute (chacune un chantier à part) | Variable selon la fonctionnalité | Basse |

### Passeport utilisateur (vue symétrique) — MVP livré le 2026-08-05

Idée soumise par l'utilisateur (2026-08-04) : le même principe que le Passeport matériel, mais pivoté côté personne — répondre à « quel matériel cette personne a-t-elle reçu, rendu, acheté... et à quelle date ? », de sa création de compte à sa désactivation/suppression. Réutilise directement le socle déjà livré (`glpi_plugin_remise_events`, qui porte déjà `users_id` sur chaque ligne) — aucune nouvelle table, aucun nouvel événement, seulement une nouvelle vue :

- **Nouvel onglet "Passeport utilisateur" sur `User`** (`PassportEvent::showForUser()`) : même frise chronologique et même style visuel que le Passeport matériel, filtrée par `users_id` au lieu de `itemtype`/`items_id`. Coexiste avec l'onglet "Remises" déjà existant côté utilisateur (table brute) — l'un agrège en lecture seule (Remise + Maintenance, sans Maintenance ici puisqu'elle n'a pas de notion de bénéficiaire), l'autre reste la fiche détaillée de chaque Remise.
- **Bornes de compte** : `begin_date` (date d'entrée explicite, cf. Administration > Utilisateurs) si renseignée, sinon `date_creation` (toujours disponible) ; fin de frise uniquement si le compte est réellement désactivé/supprimé (`end_date` si renseignée, sinon `date_mod` en repli approximatif — le cœur GLPI ne conserve aucune date de désactivation explicite). Jamais dupliquées dans `glpi_plugin_remise_events`.
- **Affichage minimal par ligne : nom du matériel + numéro de série**, repli explicite et indépendant si l'un des deux manque (matériel purgé depuis, ou itemtype sans champ série) — jamais de ligne vide ou de valeur inventée.
- **Vérifié en conditions réelles** sur l'environnement de test (`localhost:8090`) : onglet visible sur une fiche utilisateur réelle, contenu chargé via `ajax/common.tabs.php`, nouvel événement (réaffectation d'un matériel réel via `front/computer.form.php`) apparu correctement dans la frise avec nom + n° de série + lien vers la fiche source ; repli "compte actif"/"inconnue" confirmé sur un compte sans `date_creation` (le compte administrateur par défaut de cette instance, plus ancien que l'ajout de cette colonne). Suite PHPUnit (131 tests), phpcs et phpstan au vert.

### Rétro-remplissage depuis l'historique natif GLPI — livré le 2026-08-05

Initialement noté "Haute difficulté — à valider avant de s'engager" (risque de faisabilité sur le format de `glpi_logs`) : vérifié en conditions réelles sur l'environnement de test, puis implémenté suite à la demande explicite de l'utilisateur ("tu peux pas faire en sorte que le plugin récupère l'information ?").

**Faisabilité confirmée** : `glpi_logs` porte une colonne `id_search_option` qui identifie de façon stable (constante du cœur GLPI, identique sur `Computer`/`Monitor`/`Phone`/`Peripheral`...) le champ modifié — `70` pour l'Utilisateur, `31` pour l'État — avec `old_id`/`new_id` déjà en entiers (aucun parsing de texte nécessaire).

**`PassportEvent::backfillFromLogs()`** rejoue exactement la même logique que le déclenchement en direct (`Remise::handleUserBasedTrigger()`/`handleStateBasedTrigger()`), sur les **mêmes réglages déjà existants** (`Config::getHandoverStates()`/`getReturnStates()`/`getDonationStates()`/`getVenteStates()`, `sign_on_assignment`/`sign_on_reassignment`/`sign_on_return`) plutôt qu'un réglage dupliqué — décision explicite de l'utilisateur, cf. discussion. Les changements groupés par `date_mod` (une même sauvegarde peut modifier Utilisateur ET État à la fois) respectent la même priorité "affectation avant État" qu'en direct, pour ne jamais produire deux événements pour un seul changement réel.

- **Déclenchement automatique** à la première consultation d'un passeport vide (matériel ou utilisateur) — idempotent par événement candidat (jamais par simple compteur), donc rappelable sans risque.
- **Bouton "Forcer la recherche dans l'historique"** dans les deux onglets (matériel et utilisateur) pour un nouveau passage explicite, sans attendre une consultation "à vide" — utile si les réglages d'États déclencheurs changent après coup.
- **`PassportEvent::backfillUserHistoryFromLogs()`** (côté utilisateur) retrouve d'abord tous les matériels concernés (`glpi_logs` est indexé par matériel, jamais par utilisateur) avant de rejouer l'historique de chacun.
- Snapshot reconstruit à partir de l'identité **actuelle** du bénéficiaire (`glpi_logs` ne conserve aucun snapshot historique du nom/e-mail) — approximation assumée. Chaque événement reconstitué est marqué explicitement ("Reconstitué automatiquement depuis l'historique GLPI").
- **Vérifié en conditions réelles** : matériel avec plusieurs changements d'utilisateur avant l'installation de cette fonctionnalité → frise et compteur de "vies" correctement reconstitués dès la première consultation ; bouton "Forcer la recherche" confirmé idempotent (aucun doublon sur un second appel) ; Passeport utilisateur d'une personne ayant eu plusieurs matériels différents → agrégation correcte via `backfillUserHistoryFromLogs()`. Suite PHPUnit (140 tests, dont 9 dédiés au rétro-remplissage), phpcs et phpstan au vert.

### Risques techniques à trancher pendant la phase d'analyse

- **Volume de la table d'événements** : un historique immuable ne cesse de grossir ; prévoir l'indexation (au minimum `itemtype`+`items_id`+`date`) dès le MVP plutôt qu'en réaction à un ralentissement constaté.
- **Ne pas dupliquer l'inventaire GLPI** : le passeport doit rester une lecture enrichie des objets natifs (Computer, Monitor...), jamais une source de vérité parallèle sur des champs déjà natifs (modèle, fabricant, n° série...).
- **RGPD / droit à l'oubli vs traçabilité** : le snapshot utilisateur "indépendant de `users_id`" conserve nom/e-mail même après suppression du compte — à trancher explicitement avec l'utilisateur (durée de conservation, anonymisation partielle possible ou non) avant d'implémenter, pas après.
- **Performance des indicateurs calculés** (score de santé, âge réel...) : doivent être mis en cache/recalculés à intervalle, jamais recalculés à chaque affichage de l'onglet — même piège que celui déjà documenté dans ce plugin pour `Config::getLatestGithubVersion()`.
- **Dépendance à une API externe** pour le passeport environnemental : disponibilité et limites de débit hors du contrôle du plugin — la saisie manuelle doit rester le chemin garanti, jamais un simple filet de secours dégradé.
- **Migration progressive sans casser Remise/Maintenance existants** : les workflows actuels (remise, restitution, don, vente, maintenance) doivent devenir des *producteurs d'événements* pour la timeline, pas être réécrits — le risque principal est de vouloir refondre l'existant au lieu de le brancher sur le nouveau socle.

**Avant tout code** - ✅ **Fait le 2026-08-25** (issue #76) : voir `docs/design/ADR-passeport-v1.md` pour (1) l'analyse détaillée de l'architecture actuelle, (2) le schéma SQL, (3) les classes PHP et hooks GLPI nécessaires, (4) la roadmap par version redécoupée en tickets réels croisés avec les issues GitHub, (5) la décision explicite tranchée sur chacun des 6 risques listés ci-dessus.

## Explicitement hors périmètre pour l'instant

(aucun point pour l'instant — la publication sur le Marketplace officiel GLPI, jusqu'ici hors périmètre, est en cours via `assetsign.xml`.)
