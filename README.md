# AssetSign — Remise, restitution, don et vente de matériel avec signature électronique

<p align="center"><img src="docs/banner.png" alt="AssetSign — Signature, suivi, traçabilité" width="180"></p>

<p align="center"><strong>La preuve écrite de chaque remise de matériel, sans papier, sans tableur, sans y penser.</strong></p>

Créé par **Vincent GUILLOTTE**.

🇫🇷 **Français** | [🇬🇧 English](README.en.md)

## Sommaire

- [Qu'est-ce que ce plugin ?](#quest-ce-que-ce-plugin-)
- [Ce qui le distingue](#ce-qui-le-distingue)
- [Installation](#installation)
- [Aperçu](#aperçu)
- [Documentation](#documentation)
- [Licence](#licence)

## Qu'est-ce que ce plugin ?

Quand un ordinateur, un écran, un téléphone ou tout autre matériel est remis à quelqu'un dans l'entreprise, il faut souvent une trace écrite : que le bénéficiaire reconnaît avoir reçu tel matériel, dans tel état, à telle date, et qu'il accepte les conditions d'usage (charte informatique, conditions générales...). En pratique, cette étape est souvent oubliée, faite sur papier et jamais archivée, ou gérée à la main dans un tableur — jusqu'au jour où un litige ou un audit la réclame, et qu'elle n'existe nulle part.

Ce plugin l'automatise entièrement à partir de GLPI, l'outil que vos techniciens utilisent déjà pour gérer le parc — sans nouvel écran à apprendre, sans service externe à payer :

1. Un technicien affecte un matériel à un utilisateur dans GLPI (comme il le fait déjà normalement).
2. Le plugin le détecte automatiquement et génère une **fiche de remise en PDF** (matériel, bénéficiaire, accessoires, conditions générales, charte informatique).
3. Le bénéficiaire reçoit un **e-mail** avec un lien vers cette fiche.
4. Il se connecte à GLPI avec son propre compte, **consulte le PDF et signe à l'écran** (souris, doigt ou stylet).
5. Le **PDF signé** est automatiquement archivé — sur la fiche du matériel *et* sur la fiche de l'utilisateur, consultable à tout moment.
6. Si le bénéficiaire ne signe pas, le plugin **relance automatiquement** puis marque le document comme expiré au bout d'un délai configurable.

Le même mécanisme fonctionne aussi en sens inverse : quand un matériel est **restitué** (désaffecté), une fiche de restitution peut être générée et signée de la même façon. Deux workflows supplémentaires existent aussi pour un **Don** ou une **Vente** de matériel, y compris à une personne ou une association extérieure à l'entreprise — voir le [guide d'utilisation](USER_GUIDE.md) pour le détail complet des fonctionnalités.

**Sécurité** : la page de signature n'est jamais accessible par un simple lien anonyme — il faut être connecté à GLPI, et le lien ne fonctionne que pour le bénéficiaire réel du document. Un autre utilisateur authentifié qui obtiendrait le lien par erreur (transfert d'e-mail, etc.) se voit refuser l'accès.

> Ce plugin a été conçu et **validé de bout en bout** dans un environnement GLPI réel (Docker) : affectation → détection → génération PDF → e-mail → connexion → consultation → signature → rattachement — chaque étape a été testée avec de vraies requêtes contre une instance GLPI, y compris les cas de refus d'accès.

## Ce qui le distingue

- **Le passeport de vie complet de chaque matériel**, pas juste la remise du jour : un nouvel onglet **Passeport matériel** reconstitue automatiquement — y compris rétroactivement, sur un parc déjà existant, sans ressaisie — la frise chronologique de tout ce qui lui est arrivé (remises, restitutions, dons, ventes, maintenances, achat, garantie, tickets liés), avec un **score de santé sur 100** calculé à partir de l'âge, des incidents, de l'état physique et du nombre de détenteurs successifs — pondérable par vos soins. La même vue existe côté utilisateur (« qu'a reçu cette personne depuis son arrivée ? »).
- **Zéro coût, zéro dépendance externe** : la signature à l'écran est intégrée (canvas natif, aucun service tiers, aucun abonnement), et chaque signature produit une vraie preuve consultable — signataire, adresse IP, empreinte SHA-256 du document, horodatage.
- **Pensé pour le multi-site et le multi-marque** : réglages, logo, charte et conditions générales indépendants par entité avec héritage automatique — une même instance GLPI peut servir plusieurs sociétés sans qu'elles se voient.
- **Au-delà de la remise** : don et vente de matériel (bénéficiaire interne ou externe à l'entreprise), fiches de maintenance/préparation avec checklist entièrement configurable, état des lieux visuel avec repères de dommage cliquables directement sur un schéma du matériel.
- **S'installe sans rien casser** : fonctionne immédiatement avec vos actifs personnalisés existants, respecte les droits GLPI natifs, aucune donnée dupliquée hors de ce que le workflow de signature exige réellement.
- **Interface en 5 langues** (français, anglais, espagnol, allemand, italien), détectée automatiquement selon la langue du compte du destinataire.

## Installation

**Prérequis** : GLPI 11.0.8+ (recommandé — corrige plusieurs failles critiques du cœur GLPI lui-même), PHP 8.3+, MariaDB/MySQL, un serveur SMTP configuré dans GLPI. Composer n'est nécessaire que pour développer sur le plugin — **pas pour l'installer** : `vendor/` (Dompdf et ses dépendances, ~14 Mo, production uniquement) est commité directement dans ce dépôt, un simple `git clone` ou une release ZIP suffit sur le serveur cible.

**1. Récupérer le code** dans `plugins/` de votre GLPI, sous le nom **`assetsign`** (GLPI en déduit la clé du plugin) :

```bash
cd /chemin/vers/glpi/plugins
git clone https://github.com/parime/assetsign-glpi.git assetsign
```

Ou, sans Git disponible : téléchargez `assetsign-glpi-X.Y.Z.zip` depuis les [releases](https://github.com/parime/assetsign-glpi/releases) et extrayez-la dans `plugins/` (elle contient déjà un dossier `assetsign/`, pas de renommage à faire).

**2. Installer et activer**, depuis l'interface (**Configuration > Plugins**, « Assetsign & signature ») ou en ligne de commande :

```bash
php bin/console plugin:install assetsign
php bin/console plugin:activate assetsign
```

**3. Accorder les droits** : après une installation fraîche, seul Super-Admin a accès. Admin et Technician reçoivent automatiquement un droit d'usage courant (suffisant pour traiter les remises au quotidien). Pour un autre profil, ou ajuster ce qui est accordé par défaut : **Administration > Profils** > profil concerné > onglet **« Assetsign & signature »**.

**4. Configurer** : un menu **Administration > Assetsign & signature** apparaît, avec un formulaire par onglet (aperçu PDF en direct) — adresse d'expédition, types de matériel gérés, déclencheurs (par affectation ou par État). La configuration est indépendante par entité, avec héritage automatique.

📖 Le détail complet (schémas, captures d'écran, chaque réglage, script de mise à jour `update.sh`, pièges de cache Twig/OPcache) est dans **[INSTALLATION.md](INSTALLATION.md)**.

## Aperçu

**La fiche d'une remise, côté technicien** — statut, observations, état des lieux visuel avec repères de dommage, accessoires remis :

![Fiche de remise avec état des lieux visuel et accessoires](docs/screenshots/assetsign-fiche.png)

**La page de signature, côté bénéficiaire** — consultation du PDF, état des lieux visuel, remarque libre, signature à l'écran :

![Page de signature côté bénéficiaire](docs/screenshots/sign-page.png)

Toutes les autres captures d'écran (paramétrage, tableau de bord, listes, onglets, Intitulés...) sont dans le [guide d'utilisation](USER_GUIDE.md#aperçu).

## Documentation

| Document | Pour qui / pour quoi |
|---|---|
| **[INSTALLATION.md](INSTALLATION.md)** | Prérequis, installation, premier réglage, mise à jour du plugin. |
| **[USER_GUIDE.md](USER_GUIDE.md)** | Utilisation au quotidien : fonctionnement détaillé, liste complète des fonctionnalités, tableau de bord, FAQ. |
| **[ARCHITECTURE.md](ARCHITECTURE.md)** | Pour les développeurs : structure du code, sous-systèmes, sécurité du dépôt, suite de tests automatisés. |
| **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** | Pièges rencontrés et décisions non évidentes prises en conditions réelles. |
| **[CONTRIBUTING.md](CONTRIBUTING.md)** | Workflow de contribution, vérifications avant de pousser, comment publier une release. |
| **[ROADMAP.md](ROADMAP.md)** | Ce qui est envisagé, pas encore engagé sur une date précise. |
| **[SECURITY.md](SECURITY.md)** | Comment signaler une vulnérabilité. |
| **[CHANGELOG.md](CHANGELOG.md)** | Historique des versions publiées. |

## Licence

[GPL-3.0-only](LICENSE), conformément aux conventions des plugins GLPI.
