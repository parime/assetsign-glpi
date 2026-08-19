# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

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
