[🇫🇷 Français](#-français) · [🇬🇧 English](#-english)

## 🇫🇷 Français

**Contribuer**

### Workflow des branches

- `dev` accumule le travail en cours — poussez-y vos changements.
- `main` est la branche stable, protégée : toute modification (sauf par un administrateur du dépôt) doit passer par une Pull Request avec au moins une revue approuvée, et la CI doit être verte.
- Ne travaillez pas directement sur `main`.

### Avant de pousser

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

### Messages de commit

Ce dépôt écrit ses messages de commit en français, à l'impératif, et explique le **pourquoi** plutôt que de reformuler le diff : la raison du changement, ce qui a été vérifié, un piège rencontré s'il y en a un. `git log` sur ce dépôt donne le ton à suivre.

### Numéro de version

Toute Pull Request qui change le comportement du plugin (fonctionnalité, correctif, migration de schéma) doit incrémenter `PLUGIN_ASSETSIGN_VERSION` dans `setup.php`. Ce numéro est ce que GLPI affiche sur **Configuration > Plugins**, et c'est lui qui signale à un administrateur qu'une mise à jour est disponible.

### Publier une release

Une fois `main` à jour avec les changements voulus :

1. Vérifiez que `PLUGIN_ASSETSIGN_VERSION` dans `setup.php` correspond à la version que vous voulez publier.
2. Créez et poussez un tag annoté au même format (`vX.Y.Z`, avec le `v`) :
   ```bash
   git tag -a v1.2.3 -m "v1.2.3 : description courte"
   git push origin v1.2.3
   ```
3. `.github/workflows/release.yml` se déclenche automatiquement : il vérifie que le tag correspond bien à `PLUGIN_ASSETSIGN_VERSION` (échoue sinon), construit l'archive de distribution, l'installe réellement sur une instance GLPI fraîche pour valider sa structure, puis publie la GitHub Release avec l'archive en pièce jointe et un changelog généré à partir des Pull Requests fusionnées depuis le tag précédent.

Pas d'archive à construire soi-même. Pensez en revanche à ajouter une entrée dans [CHANGELOG.md](CHANGELOG.md) (format [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)) pour toute version publiée — complémentaire du changelog auto-généré par GitHub (qui liste les PR fusionnées, mais pas toujours le *pourquoi* d'un correctif ou d'une régression rencontrée).

### Signaler une vulnérabilité

Ne passez pas par une issue publique — voir [SECURITY.md](SECURITY.md) pour la procédure (avis de sécurité privé GitHub).

### Pour aller plus loin

- **[ARCHITECTURE.md](ARCHITECTURE.md)** — structure du code, sous-systèmes, sécurité du dépôt, suite de tests.
- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** — pièges déjà rencontrés et décisions non évidentes ; à consulter avant de modifier un mécanisme qui semble étrange au premier regard, la raison y est probablement déjà documentée.

### Références officielles GLPI (base de tout développement sur ce plugin)

Ce plugin suit les conventions officielles de développement de plugins GLPI 11 — utile à connaître avant de modifier `setup.php`/`hook.php` ou la structure du code :

- **[Tutoriel officiel de création de plugin](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/tutorial.html)** — documentation développeur GLPI, explique `plugin_init_<key>()`, les hooks, l'autoloading PSR-4 natif.
- **[Documentation plugins GLPI, page "Create a new plugin"](https://glpi-plugins.readthedocs.io/fr/latest/empty/index.html#create-a-new-plugin)** — conventions de structure côté écosystème `pluginsGLPI` (marketplace).
- **[pluginsGLPI/empty](https://github.com/pluginsGLPI/empty)** — squelette officiel minimal, référence pour `setup.php`/`hook.php`/`plugin.xml`.
- **[pluginsGLPI/example](https://github.com/pluginsGLPI/example)** — squelette officiel plus complet, référence pour la structure `src/` (flat, autoloader natif GLPI plutôt qu'un `autoload.psr-4` Composer explicite — ce plugin dévie volontairement de cette convention avec `src/GlpiPlugin/Assetsign/`, cf. TROUBLESHOOTING.md/ARCHITECTURE.md pour la justification).

**Point critique déjà vérifié en conditions réelles** (cf. `git log` sur `docs/assetsign.xml`) : la clé `<key>` du fichier de soumission marketplace **doit être identique** à la clé technique réelle du plugin (`assetsign`, celle utilisée par `plugin_init_assetsign()` et le nom du dossier d'installation) — `Plugin::load()` dans le cœur GLPI l'utilise littéralement comme nom de répertoire ET comme suffixe de fonction d'initialisation. Une valeur différente ferait échouer silencieusement toute installation via le Marketplace GLPI.

## 🇬🇧 English

**Contributing**

### Branch workflow

- `dev` accumulates work in progress — push your changes there.
- `main` is the stable, protected branch: any change (except by a repository administrator) must go through a Pull Request with at least one approved review, and CI must be green.
- Do not work directly on `main`.

### Before pushing

All these checks run automatically in `.github/workflows/ci.yml` on every push/Pull Request, but it's worth running them locally first:

```bash
# From a GLPI container with the plugin installed under plugins/assetsign/
composer install --no-interaction --prefer-dist
vendor/bin/phpcs --standard=vendor/glpi-project/coding-standard/GlpiStandard/ruleset.xml src front hook.php setup.php
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

- **Code style**: the official GLPI standard (`GlpiStandard`), no house convention. `phpcs` (not `phpcbf`) fails CI rather than silently fixing things — use `phpcbf` locally to auto-fix whatever can be.
- **Static analysis**: PHPStan level 5 (`phpstan.neon.dist`). A few unavoidable false positives (GLPI hook methods type-hinted to the parent class due to signature constraints) are already documented and ignored in the config — if you run into a new one of the same kind, add it in the same place with the same justification rather than generating a baseline that would blanket-mask everything indiscriminately.
- **Tests**: see [ARCHITECTURE.md](ARCHITECTURE.md#tests-automatisés) for the required environment (dedicated GLPI container, never against a production database) and what's already covered. Add a test for any non-trivial behavior you introduce or fix.

### Commit messages

This repository writes its commit messages in French, in the imperative mood, and explains the **why** rather than restating the diff: the reason for the change, what was verified, a pitfall encountered if there was one. `git log` on this repository sets the tone to follow.

### Version number

Any Pull Request that changes the plugin's behavior (feature, fix, schema migration) must bump `PLUGIN_ASSETSIGN_VERSION` in `setup.php`. This number is what GLPI displays under **Configuration > Plugins**, and it's what signals to an administrator that an update is available.

### Publishing a release

Once `main` is up to date with the intended changes:

1. Check that `PLUGIN_ASSETSIGN_VERSION` in `setup.php` matches the version you want to publish.
2. Create and push an annotated tag in the same format (`vX.Y.Z`, with the `v`):
   ```bash
   git tag -a v1.2.3 -m "v1.2.3: short description"
   git push origin v1.2.3
   ```
3. `.github/workflows/release.yml` triggers automatically: it checks that the tag actually matches `PLUGIN_ASSETSIGN_VERSION` (fails otherwise), builds the distribution archive, actually installs it on a fresh GLPI instance to validate its structure, then publishes the GitHub Release with the archive attached and a changelog generated from the Pull Requests merged since the previous tag.

No archive to build by hand. Do remember, however, to add an entry to [CHANGELOG.md](CHANGELOG.md) (in [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) format) for every published version — a complement to the changelog GitHub auto-generates (which lists merged PRs, but not always the **why** of a fix or a regression encountered).

### Reporting a vulnerability

Do not go through a public issue — see [SECURITY.md](SECURITY.md) for the procedure (private GitHub security advisory).

### Going further

- **[ARCHITECTURE.md](ARCHITECTURE.md)** — code structure, subsystems, repository security, test suite.
- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** — pitfalls already encountered and non-obvious decisions; check it before changing a mechanism that looks odd at first glance, the reason is probably already documented there.

### Official GLPI references (the basis for any development on this plugin)

This plugin follows the official GLPI 11 plugin development conventions — useful to know before modifying `setup.php`/`hook.php` or the code structure:

- **[Official plugin creation tutorial](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/tutorial.html)** — GLPI developer documentation, explains `plugin_init_<key>()`, hooks, native PSR-4 autoloading.
- **[GLPI plugins documentation, "Create a new plugin" page](https://glpi-plugins.readthedocs.io/fr/latest/empty/index.html#create-a-new-plugin)** — structure conventions on the `pluginsGLPI` ecosystem (marketplace) side.
- **[pluginsGLPI/empty](https://github.com/pluginsGLPI/empty)** — official minimal skeleton, reference for `setup.php`/`hook.php`/`plugin.xml`.
- **[pluginsGLPI/example](https://github.com/pluginsGLPI/example)** — fuller official skeleton, reference for the `src/` structure (flat, native GLPI autoloader rather than an explicit Composer `autoload.psr-4` — this plugin deliberately deviates from that convention with `src/GlpiPlugin/Assetsign/`, see TROUBLESHOOTING.md/ARCHITECTURE.md for the rationale).

**Critical point already verified under real conditions** (see `git log` on `docs/assetsign.xml`): the `<key>` in the marketplace submission file **must be identical** to the plugin's actual technical key (`assetsign`, the one used by `plugin_init_assetsign()` and the installation directory name) — `Plugin::load()` in the GLPI core literally uses it as both the directory name AND the initialization function suffix. A different value would make any installation via the GLPI Marketplace fail silently.
