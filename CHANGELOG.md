# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

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
