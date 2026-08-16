# Contribuer

## Workflow des branches

- `dev` accumule le travail en cours — poussez-y vos changements.
- `main` est la branche stable, protégée : toute modification (sauf par un administrateur du dépôt) doit passer par une Pull Request avec au moins une revue approuvée, et la CI doit être verte.
- Ne travaillez pas directement sur `main`.

## Avant de pousser

Toutes ces vérifications tournent automatiquement dans `.github/workflows/ci.yml` à chaque push/Pull Request, mais autant les faire passer localement d'abord :

```bash
# Depuis un conteneur GLPI avec le plugin installé sous plugins/assetsign/
composer install --no-interaction --prefer-dist
vendor/bin/phpcs --standard=vendor/glpi-project/coding-standard/GlpiStandard/ruleset.xml src front hook.php setup.php
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

- **Style de code** : le standard officiel GLPI (`GlpiStandard`), pas de convention maison. `phpcs` (pas `phpcbf`) échoue la CI plutôt que corriger en silence — utilisez `phpcbf` en local pour corriger automatiquement ce qui peut l'être.
- **Analyse statique** : PHPStan niveau 5 (`phpstan.neon.dist`). Quelques faux positifs inévitables (méthodes de hook GLPI type-hintées à la classe parente par obligation de signature) sont déjà documentés et ignorés dans la config — si vous en rencontrez un nouveau de la même famille, ajoutez-le au même endroit avec la même justification plutôt que de générer une baseline qui masquerait tout sans distinction.
- **Tests** : voir [ARCHITECTURE.md](ARCHITECTURE.md#tests-automatisés) pour l'environnement requis (conteneur GLPI dédié, jamais contre une base de production) et ce qui est déjà couvert. Ajoutez un test pour tout comportement non trivial que vous introduisez ou corrigez.

## Messages de commit

Ce dépôt écrit ses messages de commit en français, à l'impératif, et explique le **pourquoi** plutôt que de reformuler le diff : la raison du changement, ce qui a été vérifié, un piège rencontré s'il y en a un. `git log` sur ce dépôt donne le ton à suivre.

## Numéro de version

Toute Pull Request qui change le comportement du plugin (fonctionnalité, correctif, migration de schéma) doit incrémenter `PLUGIN_ASSETSIGN_VERSION` dans `setup.php`. Ce numéro est ce que GLPI affiche sur **Configuration > Plugins**, et c'est lui qui signale à un administrateur qu'une mise à jour est disponible.

## Publier une release

Une fois `main` à jour avec les changements voulus :

1. Vérifiez que `PLUGIN_ASSETSIGN_VERSION` dans `setup.php` correspond à la version que vous voulez publier.
2. Créez et poussez un tag annoté au même format (`vX.Y.Z`, avec le `v`) :
   ```bash
   git tag -a v1.2.3 -m "v1.2.3 : description courte"
   git push origin v1.2.3
   ```
3. `.github/workflows/release.yml` se déclenche automatiquement : il vérifie que le tag correspond bien à `PLUGIN_ASSETSIGN_VERSION` (échoue sinon), construit l'archive de distribution, l'installe réellement sur une instance GLPI fraîche pour valider sa structure, puis publie la GitHub Release avec l'archive en pièce jointe et un changelog généré à partir des Pull Requests fusionnées depuis le tag précédent.

Pas d'archive à construire soi-même. Pensez en revanche à ajouter une entrée dans [CHANGELOG.md](CHANGELOG.md) (format [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)) pour toute version publiée — complémentaire du changelog auto-généré par GitHub (qui liste les PR fusionnées, mais pas toujours le *pourquoi* d'un correctif ou d'une régression rencontrée).

## Signaler une vulnérabilité

Ne passez pas par une issue publique — voir [SECURITY.md](SECURITY.md) pour la procédure (avis de sécurité privé GitHub).

## Pour aller plus loin

- **[ARCHITECTURE.md](ARCHITECTURE.md)** — structure du code, sous-systèmes, sécurité du dépôt, suite de tests.
- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** — pièges déjà rencontrés et décisions non évidentes ; à consulter avant de modifier un mécanisme qui semble étrange au premier regard, la raison y est probablement déjà documentée.

## Références officielles GLPI (base de tout développement sur ce plugin)

Ce plugin suit les conventions officielles de développement de plugins GLPI 11 — utile à connaître avant de modifier `setup.php`/`hook.php` ou la structure du code :

- **[Tutoriel officiel de création de plugin](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/tutorial.html)** — documentation développeur GLPI, explique `plugin_init_<key>()`, les hooks, l'autoloading PSR-4 natif.
- **[Documentation plugins GLPI, page "Create a new plugin"](https://glpi-plugins.readthedocs.io/fr/latest/empty/index.html#create-a-new-plugin)** — conventions de structure côté écosystème `pluginsGLPI` (marketplace).
- **[pluginsGLPI/empty](https://github.com/pluginsGLPI/empty)** — squelette officiel minimal, référence pour `setup.php`/`hook.php`/`plugin.xml`.
- **[pluginsGLPI/example](https://github.com/pluginsGLPI/example)** — squelette officiel plus complet, référence pour la structure `src/` (flat, autoloader natif GLPI plutôt qu'un `autoload.psr-4` Composer explicite — ce plugin dévie volontairement de cette convention avec `src/GlpiPlugin/Assetsign/`, cf. TROUBLESHOOTING.md/ARCHITECTURE.md pour la justification).

**Point critique déjà vérifié en conditions réelles** (cf. `git log` sur `docs/assetsign.xml`) : la clé `<key>` du fichier de soumission marketplace **doit être identique** à la clé technique réelle du plugin (`assetsign`, celle utilisée par `plugin_init_assetsign()` et le nom du dossier d'installation) — `Plugin::load()` dans le cœur GLPI l'utilise littéralement comme nom de répertoire ET comme suffixe de fonction d'initialisation. Une valeur différente ferait échouer silencieusement toute installation via le Marketplace GLPI.
