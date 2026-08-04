# Feuille de route

Ce document liste ce qui est **envisagé**, pas engagé sur une date précise. Pour ce qui manque déjà aujourd'hui de façon plus factuelle, voir [USER_GUIDE.md](USER_GUIDE.md#ce-qui-nest-pas-encore-implémenté).

## Envisagé

- **Fournisseur de signature externe** (Yousign, DocuSeal...) pour un niveau de signature électronique renforcé (eIDAS "avancée"/"qualifiée"), en plus de la signature à l'écran actuelle (niveau "simple"). Le point d'extension existe déjà dans le code (`Provider\SignatureProviderInterface`) — seul `CanvasProvider` (signature à l'écran) est implémenté à ce jour ; brancher un prestataire externe n'exigerait pas de revoir l'architecture existante. Explicitement écarté du cycle précédent (voir "Réalisé récemment").
- **SonarCloud** en CI, en complément de PHPStan/phpcs déjà en place — non ajouté à ce jour, nécessite un compte externe et une décision du mainteneur sur l'outillage souhaité. Explicitement écarté du cycle précédent.
- **Proposer la création automatique des intitulés de base sur un GLPI fraîchement installé** — aujourd'hui, `install()` sème déjà quelques valeurs par défaut pour les intitulés propres au plugin (`Accessory`, `MaintenanceChecklistItem`, `Template`), mais rien ne compense l'absence d'intitulés **cœur GLPI** que le plugin utilise (ex: Etats déclencheurs de remise/restitution/don/vente) sur une instance neuve sans configuration métier existante — l'onglet Configuration affiche alors des listes de déclenchement par État vides, sans qu'il soit évident pour l'administrateur qu'il faut aller les créer ailleurs (Configuration > Listes déroulantes > États) avant que le plugin soit réellement utilisable. Idée : proposer (pas imposer) la création d'un jeu d'États de base pertinents pour le workflow du plugin, détectée quand aucun État n'existe encore ou qu'aucun n'est configuré comme déclencheur.
- **Repères d'état des lieux visuel : décalage occasionnel signalé par l'utilisateur, cause probable identifiée et corrigée côté ressenti, mais pas totalement élucidée** — le positionnement lui-même (calcul `left`/`top` en %) s'est révélé exact au pixel dans tous les tests manuels (fiche Remise admin, page de signature réelle, fenêtre réduite à 900px) ; la vraie cause plausible du "repère pas au bon endroit" était plutôt la **latence perçue** : chaque ajout de repère attendait la régénération complète du PDF côté serveur (`Remise::refreshDamageAnnotationPdf()`) avant de s'afficher, mesurée à ~4,3 à 4,8 secondes dans l'environnement Docker de test — largement le temps pour l'utilisateur de cliquer ailleurs ou de perdre le fil avant que le repère n'apparaisse enfin à l'endroit du clic initial. **Corrigé** : affichage optimiste du repère (apparaît en ~10ms au clic, `public/js/sign/damage-annotation.js`), la confirmation serveur reste asynchrone en arrière-plan. Si un décalage réel (pas seulement perçu) se reproduit malgré ça, il faudra une capture d'écran précise (point cliqué vs position obtenue, navigateur/zoom) pour aller plus loin.
- **Régénération complète du PDF non signé à chaque interaction sur l'état des lieux visuel, toujours lente en elle-même** — le correctif ci-dessus masque la lenteur perçue (le repère apparaît tout de suite), mais la confirmation serveur elle-même prend encore plusieurs secondes (~4-5s en Docker de dev, probablement moins en production mais jamais instantané) à chaque ajout/déplacement/suppression de repère, puisque `HandoverPdfBuilder::build()` reconstruit l'intégralité du PDF (mise en page, QR code, chiffrement si `protect_pdf` actif...) plutôt qu'une mise à jour incrémentale. Pistes possibles : debouncer les régénérations rapprochées (ne régénérer qu'une fois après une rafale de repères, pas à chaque clic), ou différer la régénération à la sauvegarde/fermeture du panneau plutôt qu'à chaque mutation individuelle.

## Réalisé récemment (2026-08-04)

- **Tests automatisés des contrôleurs `front/remise.form.php`/`front/maintenance.form.php`** : logique extraite dans `Api\RemiseFormController`/`Api\MaintenanceFormController` (même principe que `Api\SignController`), couverte par `RemiseFormControllerTest`/`MaintenanceFormControllerTest`. `accessory.form.php`/`maintenancechecklistitem.form.php` volontairement laissés tels quels (délégation pure à `CommonDBTM`, rien à extraire).
- **Gabarits édités directement dans les onglets de configuration** (Remise/Restitution/Don/Vente) — plus besoin de naviguer vers l'écran séparé `front/template.form.php` pour le gabarit par défaut de chaque type ; la liste complète (Configuration > Intitulés) reste accessible pour le cas de plusieurs gabarits par type/entité.
- **Fil d'Ariane cohérent avec l'emplacement réel du plugin** (secteur `tools`, pas `admin`) sur toutes les pages du plugin, y compris depuis Configuration > Intitulés pour les Gabarits/Accessoires/Points de contrôle de maintenance (`getSectorizedDetails()`/`getMenuContent()` sur `Remise`/`Maintenance`/`Template`/`Accessory`/`MaintenanceChecklistItem`).
- **Nom de l'entreprise** (texte affiché à côté du logo sur les PDF) et **protection PDF** (chiffrement contre copie/édition, impression toujours autorisée) — idées reprises du plugin concurrent [responsivas](https://github.com/monta990/responsivas) après revue comparative.
- **Affichage optimiste des repères d'état des lieux visuel** (apparaissent immédiatement au clic, sans attendre la régénération du PDF côté serveur) — cf. point ci-dessus sur la lenteur restante.
- Passe UX/UI exploratoire de la page de configuration : pas d'incohérence trouvée au-delà de ce qui précède.

## Vision produit à long terme : Passeport numérique du cycle de vie matériel

Direction de fond proposée par l'utilisateur, à ne pas confondre avec les points "envisagés" ci-dessus (portée bien plus large, nécessite sa propre phase d'analyse dédiée avant tout code). Le plugin ne doit plus être vu comme un simple outil de remise/prêt de matériel, mais évoluer vers :

> Le passeport numérique du cycle de vie des équipements IT dans GLPI.

Chaîne de vie visée (`Achat → Réception → Préparation → Contrôle qualité → Attribution → Changement utilisateur → Retour → Reconditionnement → Réattribution → Vente / Don / Destruction`), sans remplacer ni dupliquer les objets natifs GLPI (Computer, Monitor, Phone...) — le plugin les enrichit d'un nouvel onglet **« Passeport matériel »**, qui doit répondre à « que s'est-il passé avec ce matériel depuis son achat jusqu'à aujourd'hui ? ».

### Architecture fonctionnelle globale

Trois couches, chacune dépendant de la précédente :

1. **Socle (données brutes)** — une timeline d'événements métier immuable + des snapshots utilisateur indépendants de `users_id`. Tout le reste du passeport n'est que de la lecture/agrégation de ce socle : aucune autre couche n'a le droit d'écrire sa propre vérité parallèle sur "qui a fait quoi quand".
2. **Vue (lecture agrégée)** — l'onglet Passeport matériel lui-même : fiche d'identité, historique des utilisateurs successifs ("vies"), frise chronologique. Purement dérivé de la couche 1, aucune table supplémentaire nécessaire au-delà d'éventuels index/vues.
3. **Indicateurs et modules avancés (calculs + intégrations externes)** — score de santé, âge réel/temps utilisé, valeur résiduelle, moteur de décision, passeport environnemental. Dépendent de la couche 1 (et parfois de sources externes comme Boavizta) ; peuvent être recalculés/mis en cache sans jamais réécrire l'historique brut.

Cette séparation est ce qui permet de livrer un MVP utile (couches 1+2 seules) avant d'investir dans les couches suivantes.

### Fonctionnalités fondatrices (socle, à construire en premier)

Tout le reste de la roadmap ci-dessous dépend de ces deux éléments — aucune fonctionnalité "avancée" (score de santé, timeline graphique, valeur résiduelle...) n'est possible sans eux :

- **Timeline d'événements immuable** (table candidate `glpi_plugin_remise_events`) : achat, réception, préparation, contrôle qualité, attribution, prêt, retour, transfert, maintenance, reconditionnement, vente/don/destruction, chacun avec date, technicien, commentaire, documents/photos, signature éventuelle, état du matériel, accessoires.
- **Snapshot utilisateur indépendant de `users_id`** (table candidate `glpi_plugin_remise_users_history`) : nom, prénom, e-mail, entité, fonction éventuelle, dates de début/fin, figés au moment de l'événement — si le compte GLPI est supprimé/désactivé plus tard, l'historique reste lisible.

Table candidate supplémentaire pour la couche 3 : `glpi_plugin_remise_asset_metrics` (indicateurs calculés : santé, âge réel, valeur résiduelle) — à valider/affiner pendant la phase d'analyse dédiée, pas figée ici.

### Roadmap par version

**MVP — le socle, en lecture simple**
| Fonctionnalité | Objectif métier | Valeur utilisateur | Difficulté | Dépendances | Priorité |
|---|---|---|---|---|---|
| Timeline d'événements (table + écriture depuis les points d'entrée existants : remise, retour, maintenance, don, vente) | Ne plus perdre l'historique métier une fois une fiche traitée | Base de toute réponse à "qui a utilisé ce PC ?" | Moyenne (nouvelle table + hooks sur les workflows existants) | Aucune | Critique |
| Snapshot utilisateur à chaque attribution | Historique lisible même si l'utilisateur GLPI disparaît | Fiabilité de l'audit dans le temps | Faible | Timeline d'événements | Critique |
| Onglet "Passeport matériel" (liste chronologique brute, pas encore graphique) | Un seul endroit pour consulter la vie du matériel | Réponse directe au besoin exprimé ("qui a utilisé ce PC depuis son achat ?") | Faible/Moyenne (nouvel onglet `getTabNameForItem`/`displayTabContentForItem`, pattern déjà connu du plugin) | Timeline d'événements | Critique |
| Compteur de "vies" + détail par utilisateur avec dates | Vue rapide du nombre de mains par lesquelles est passé un matériel | Lecture immédiate, sans dérouler toute la timeline | Faible (agrégation du snapshot) | Snapshot utilisateur | Haute |

**V1 — rendre le passeport lisible et actionnable**
| Fonctionnalité | Objectif métier | Valeur utilisateur | Difficulté | Dépendances | Priorité |
|---|---|---|---|---|---|
| Timeline graphique (frise verticale, cartes cliquables) | Remplacer une liste texte par une vue moderne | Adoption/lisibilité, surtout pour un non-technicien | Moyenne (JS/Twig, inspiration Linear/Notion/GitHub/carnet d'entretien auto) | Timeline d'événements (MVP) | Haute |
| Fiche d'identité augmentée (carte de synthèse : modèle, fabricant, n° série, achat, garantie, statut, utilisateur/entité actuels) | Vue d'ensemble immédiate sans naviguer la timeline | Gain de temps pour un contrôle rapide | Faible (déjà en grande partie dans GLPI, agrégation) | Aucune nouvelle donnée | Haute |
| Checklists de contrôle qualité configurables, réutilisables (sortie de stock, remise, retour, vente, don) | Standardiser les contrôles avant chaque mouvement | Traçabilité qualité, moins d'oublis | Moyenne (généralise l'existant sur les fiches de maintenance) | Timeline d'événements (pour rattacher le résultat) | Moyenne |
| Mouvements structurés (départ/destination/documents/signature) | Modéliser un mouvement comme plus qu'une ligne de remise | Cohérence de la couche socle | Moyenne | Timeline d'événements | Moyenne |

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
| Passeport environnemental (empreinte fabrication, source, niveau de confiance ; sources : constructeur, API Boavizta, saisie manuelle) | Amorcer un volet RSE réaliste, sans données inventées | Reporting environnemental crédible | Moyenne/Haute (intégration API externe, gestion de son indisponibilité) | Fiche d'identité (V1) | Basse |
| Bénéfice du réemploi ("impact évité" : durée prévue vs réelle) | Valoriser la prolongation de durée de vie | Argument RSE chiffré et transparent | Faible (calcul dérivé), une fois le passeport environnemental posé | Passeport environnemental, indicateurs temporels | Basse |
| QR code sur le matériel (scan → état/historique/actions) | Accès terrain rapide sans chercher le matériel dans GLPI | Gain de temps technicien | Moyenne | Onglet Passeport matériel (MVP) | Basse |
| Kits/accessoires avec contrôle automatique au retour | Détecter un accessoire manquant à la restitution | Réduction de perte de matériel | Moyenne (nouvelle notion de kit, au-delà des accessoires actuels) | Checklists (V1) | Basse |
| Dashboard RSE, app mobile technicien, signatures multiples | Extensions déjà identifiées comme envisageables | — | Haute (chacune un chantier à part) | Variable selon la fonctionnalité | Basse |

### Risques techniques à trancher pendant la phase d'analyse

- **Volume de la table d'événements** : un historique immuable ne cesse de grossir ; prévoir l'indexation (au minimum `itemtype`+`items_id`+`date`) dès le MVP plutôt qu'en réaction à un ralentissement constaté.
- **Ne pas dupliquer l'inventaire GLPI** : le passeport doit rester une lecture enrichie des objets natifs (Computer, Monitor...), jamais une source de vérité parallèle sur des champs déjà natifs (modèle, fabricant, n° série...).
- **RGPD / droit à l'oubli vs traçabilité** : le snapshot utilisateur "indépendant de `users_id`" conserve nom/e-mail même après suppression du compte — à trancher explicitement avec l'utilisateur (durée de conservation, anonymisation partielle possible ou non) avant d'implémenter, pas après.
- **Performance des indicateurs calculés** (score de santé, âge réel...) : doivent être mis en cache/recalculés à intervalle, jamais recalculés à chaque affichage de l'onglet — même piège que celui déjà documenté dans ce plugin pour `Config::getLatestGithubVersion()`.
- **Dépendance à une API externe (Boavizta)** pour le passeport environnemental : disponibilité et limites de débit hors du contrôle du plugin — la saisie manuelle doit rester le chemin garanti, jamais un simple filet de secours dégradé.
- **Migration progressive sans casser Remise/Maintenance existants** : les workflows actuels (remise, restitution, don, vente, maintenance) doivent devenir des *producteurs d'événements* pour la timeline, pas être réécrits — le risque principal est de vouloir refondre l'existant au lieu de le brancher sur le nouveau socle.

**Avant tout code** : dédier une session à part pour (1) analyser l'architecture actuelle du plugin en détail, (2) proposer le schéma SQL définitif, (3) identifier les classes PHP et hooks GLPI nécessaires, (4) reprendre la roadmap par version ci-dessus pour la découper en tickets/sprints réels, (5) documenter les choix techniques (ADR) — cohérent avec la façon dont [[project-glpi-vulnerability-manager|le plugin jumeau glpi-vulnerability-manager]] a été construit sprint par sprint.

## Explicitement hors périmètre pour l'instant

(aucun point pour l'instant — la publication sur le Marketplace officiel GLPI, jusqu'ici hors périmètre, est en cours via `docs/remise.xml`.)
