# Feuille de route

Ce document liste ce qui est **envisagé**, pas engagé sur une date précise. Pour ce qui manque déjà aujourd'hui de façon plus factuelle, voir [USER_GUIDE.md](USER_GUIDE.md#ce-qui-nest-pas-encore-implémenté).

## Envisagé

- **Fournisseur de signature externe** pour un niveau de signature électronique renforcé (eIDAS "avancée"/"qualifiée"), en plus de la signature à l'écran actuelle (niveau "simple"). Le point d'extension existe déjà dans le code (`Provider\SignatureProviderInterface`) — seul `CanvasProvider` (signature à l'écran) est implémenté à ce jour ; brancher un prestataire externe n'exigerait pas de revoir l'architecture existante. Explicitement écarté du cycle précédent (voir "Réalisé récemment").
- **SonarCloud** en CI, en complément de PHPStan/phpcs déjà en place — non ajouté à ce jour, nécessite un compte externe et une décision du mainteneur sur l'outillage souhaité. Explicitement écarté du cycle précédent.
- **Proposer la création automatique des intitulés de base sur un GLPI fraîchement installé** — aujourd'hui, `install()` sème déjà quelques valeurs par défaut pour les intitulés propres au plugin (`Accessory`, `MaintenanceChecklistItem`, `Template`), mais rien ne compense l'absence d'intitulés **cœur GLPI** que le plugin utilise (ex: Etats déclencheurs de remise/restitution/don/vente) sur une instance neuve sans configuration métier existante — l'onglet Configuration affiche alors des listes de déclenchement par État vides, sans qu'il soit évident pour l'administrateur qu'il faut aller les créer ailleurs (Configuration > Listes déroulantes > États) avant que le plugin soit réellement utilisable. Idée : proposer (pas imposer) la création d'un jeu d'États de base pertinents pour le workflow du plugin, détectée quand aucun État n'existe encore ou qu'aucun n'est configuré comme déclencheur.
- **Repères d'état des lieux visuel : décalage occasionnel signalé par l'utilisateur, cause probable identifiée et corrigée côté ressenti, mais pas totalement élucidée** — le positionnement lui-même (calcul `left`/`top` en %) s'est révélé exact au pixel dans tous les tests manuels (fiche Remise admin, page de signature réelle, fenêtre réduite à 900px) ; la vraie cause plausible du "repère pas au bon endroit" était plutôt la **latence perçue** : chaque ajout de repère attendait la régénération complète du PDF côté serveur (`Remise::refreshDamageAnnotationPdf()`) avant de s'afficher, mesurée à ~4,3 à 4,8 secondes dans l'environnement Docker de test — largement le temps pour l'utilisateur de cliquer ailleurs ou de perdre le fil avant que le repère n'apparaisse enfin à l'endroit du clic initial. **Corrigé** : affichage optimiste du repère (apparaît en ~10ms au clic, `public/js/sign/damage-annotation.js`), la confirmation serveur reste asynchrone en arrière-plan. Si un décalage réel (pas seulement perçu) se reproduit malgré ça, il faudra une capture d'écran précise (point cliqué vs position obtenue, navigateur/zoom) pour aller plus loin.
- **Régénération complète du PDF non signé à chaque interaction sur l'état des lieux visuel, toujours lente en elle-même** — le correctif ci-dessus masque la lenteur perçue (le repère apparaît tout de suite), mais la confirmation serveur elle-même prend encore plusieurs secondes (~4-5s en Docker de dev, probablement moins en production mais jamais instantané) à chaque ajout/déplacement/suppression de repère, puisque `HandoverPdfBuilder::build()` reconstruit l'intégralité du PDF (mise en page, QR code, chiffrement si `protect_pdf` actif...) plutôt qu'une mise à jour incrémentale. Pistes possibles : debouncer les régénérations rapprochées (ne régénérer qu'une fois après une rafale de repères, pas à chaque clic), ou différer la régénération à la sauvegarde/fermeture du panneau plutôt qu'à chaque mutation individuelle.

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
| Checklists de contrôle qualité configurables, réutilisables (sortie de stock, remise, retour, vente, don) | Standardiser les contrôles avant chaque mouvement | Traçabilité qualité, moins d'oublis | Moyenne (généralise l'existant sur les fiches de maintenance) | Timeline d'événements (pour rattacher le résultat) | Moyenne |
| Mouvements structurés (départ/destination/documents/signature) | Modéliser un mouvement comme plus qu'une ligne de remise | Cohérence de la couche socle | Moyenne | Timeline d'événements | Moyenne |
| ~~Dates Infocom fusionnées dans la frise~~ — **livré**, cf. "Réalisé récemment" ci-dessus. | | | | | |
| ~~Tickets liés au matériel affichés dans la frise~~ — **livré**, cf. "Réalisé récemment" ci-dessus. | | | | | |
| ~~Rétro-remplissage de l'historique pour le matériel déjà existant avant l'installation du plugin, à partir de `glpi_logs`~~ — **livré**, cf. "Réalisé récemment" et section dédiée ci-dessous. | | | | | |

**V2 — indicateurs et aide à la décision**
| Fonctionnalité | Objectif métier | Valeur utilisateur | Difficulté | Dépendances | Priorité |
|---|---|---|---|---|---|
| Score de santé matériel (pondération ajustable : âge, incidents, contrôles, état physique, batterie, mouvements) | Prioriser quel matériel surveiller/remplacer | Décision plus rapide qu'un examen manuel | Moyenne/Haute (moteur de scoring + UI de pondération) | Timeline d'événements, checklists (V1) | Haute |
| Indicateurs temporels (âge physique, temps réellement utilisé, temps en stock, durée par utilisateur) | Distinguer "possédé depuis" de "réellement utilisé" | Pilotage du parc plus fin qu'une simple date d'achat | Moyenne (calculs sur l'historique des mouvements) | Timeline d'événements | Moyenne |
| Valeur résiduelle (linéaire / durée personnalisable / saisie manuelle) | Estimation simple, pas un module comptable complet | Aide à trancher réemploi vs sortie | Faible/Moyenne | Fiche d'identité (V1) | Moyenne |
| Fin de vie structurée (vente : prix/acheteur/documents ; don : organisme/justificatif ; destruction : prestataire/certificat) | Tracer proprement la sortie définitive | Conformité, preuve en cas de contrôle | Faible (déjà partiellement présent via Remise::TYPE_VENTE/TYPE_DON) | Timeline d'événements | Moyenne |
| Module d'aide à la décision (moteur de règles simple, ex: "réévaluer"/"préparer remplacement" avec raisons) | Aider l'équipe IT à décider quoi faire d'un matériel | Passe d'une donnée brute à une recommandation | Moyenne (règles), architecture prête pour de l'IA plus tard sans y aller maintenant | Score de santé, valeur résiduelle | Basse/Moyenne |

**V3 — extensions et intégrations externes**
| Fonctionnalité | Objectif métier | Valeur utilisateur | Difficulté | Dépendances | Priorité |
|---|---|---|---|---|---|
| Passeport environnemental (empreinte fabrication, source, niveau de confiance ; sources : constructeur, une API externe dédiée, saisie manuelle) | Amorcer un volet RSE réaliste, sans données inventées | Reporting environnemental crédible | Moyenne/Haute (intégration API externe, gestion de son indisponibilité) | Fiche d'identité (V1) | Basse |
| Bénéfice du réemploi ("impact évité" : durée prévue vs réelle) | Valoriser la prolongation de durée de vie | Argument RSE chiffré et transparent | Faible (calcul dérivé), une fois le passeport environnemental posé | Passeport environnemental, indicateurs temporels | Basse |
| QR code sur le matériel (scan → état/historique/actions) | Accès terrain rapide sans chercher le matériel dans GLPI | Gain de temps technicien | Moyenne | Onglet Passeport matériel (MVP) | Basse |
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

**Avant tout code** : dédier une session à part pour (1) analyser l'architecture actuelle du plugin en détail, (2) proposer le schéma SQL définitif, (3) identifier les classes PHP et hooks GLPI nécessaires, (4) reprendre la roadmap par version ci-dessus pour la découper en tickets/sprints réels, (5) documenter les choix techniques (ADR).

## Explicitement hors périmètre pour l'instant

(aucun point pour l'instant — la publication sur le Marketplace officiel GLPI, jusqu'ici hors périmètre, est en cours via `docs/remise.xml`.)
