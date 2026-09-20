[🇫🇷 Français](#-français) · [🇬🇧 English](#-english)

## 🇫🇷 Français

### Résumé

<!-- Quoi, et surtout pourquoi. -->

### Vérifications

- [ ] Testé contre une vraie instance GLPI (Docker, voir ARCHITECTURE.md), pas seulement PHPUnit
- [ ] `vendor/bin/phpstan analyse --no-progress` sans erreur
- [ ] `vendor/bin/phpcs --standard=vendor/glpi-project/coding-standard/GlpiStandard/ruleset.xml src front hook.php setup.php` sans erreur
- [ ] `vendor/bin/phpunit` vert
- [ ] `CHANGELOG.md` et, si pertinent, `ROADMAP.md` mis à jour
- [ ] Version bumpée (`setup.php`/`assetsign.xml`) si applicable

### Base

Cette PR cible `main` (workflow standard du dépôt), sauf mention contraire ci-dessus.

---

## 🇬🇧 English

### Summary

<!-- What, and mainly why. -->

### Checklist

- [ ] Tested against a real GLPI instance (Docker, see ARCHITECTURE.md), not just PHPUnit
- [ ] `vendor/bin/phpstan analyse --no-progress` clean
- [ ] `vendor/bin/phpcs --standard=vendor/glpi-project/coding-standard/GlpiStandard/ruleset.xml src front hook.php setup.php` clean
- [ ] `vendor/bin/phpunit` green
- [ ] `CHANGELOG.md` and, if relevant, `ROADMAP.md` updated
- [ ] Version bumped (`setup.php`/`assetsign.xml`) if applicable

### Base

This PR targets `main` (the repo's standard workflow), unless noted above.
