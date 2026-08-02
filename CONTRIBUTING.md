# Contribuer

## Workflow des branches

- `dev` accumule le travail en cours — poussez-y vos changements.
- `main` est la branche stable, protégée : toute modification (sauf par un administrateur du dépôt) doit passer par une Pull Request avec au moins une revue approuvée, et la CI doit être verte.
- Ne travaillez pas directement sur `main`.

## Avant de pousser

Toutes ces vérifications tournent automatiquement dans `.github/workflows/ci.yml` à chaque push/Pull Request, mais autant les faire passer localement d'abord :

```bash
# Depuis un conteneur GLPI avec le plugin installé sous plugins/remise/
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

Toute Pull Request qui change le comportement du plugin (fonctionnalité, correctif, migration de schéma) doit incrémenter `PLUGIN_REMISE_VERSION` dans `setup.php`. Ce numéro est ce que GLPI affiche sur **Configuration > Plugins**, et c'est lui qui signale à un administrateur qu'une mise à jour est disponible.

## Publier une release

Une fois `main` à jour avec les changements voulus :

1. Vérifiez que `PLUGIN_REMISE_VERSION` dans `setup.php` correspond à la version que vous voulez publier.
2. Créez et poussez un tag annoté au même format (`vX.Y.Z`, avec le `v`) :
   ```bash
   git tag -a v1.2.3 -m "v1.2.3 : description courte"
   git push origin v1.2.3
   ```
3. `.github/workflows/release.yml` se déclenche automatiquement : il vérifie que le tag correspond bien à `PLUGIN_REMISE_VERSION` (échoue sinon), construit l'archive de distribution, l'installe réellement sur une instance GLPI fraîche pour valider sa structure, puis publie la GitHub Release avec l'archive en pièce jointe et un changelog généré à partir des Pull Requests fusionnées depuis le tag précédent.

Rien d'autre à faire à la main — pas de changelog à rédiger, pas d'archive à construire soi-même.

## Signaler une vulnérabilité

Ne passez pas par une issue publique — voir [SECURITY.md](SECURITY.md) pour la procédure (avis de sécurité privé GitHub).

## Pour aller plus loin

- **[ARCHITECTURE.md](ARCHITECTURE.md)** — structure du code, sous-systèmes, sécurité du dépôt, suite de tests.
- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** — pièges déjà rencontrés et décisions non évidentes ; à consulter avant de modifier un mécanisme qui semble étrange au premier regard, la raison y est probablement déjà documentée.
