# AssetSign — Remise, restitution, don et vente de matériel avec signature électronique
*AssetSign — Handover, return, donation and sale of equipment with electronic signature*

<p align="center"><img src="docs/banner.png" alt="AssetSign — Signature, suivi, traçabilité" width="180"></p>

<p align="center"><strong>La preuve écrite de chaque remise de matériel, sans papier, sans tableur, sans y penser.</strong></p>
<p align="center"><em>Written proof of every equipment handover — no paper, no spreadsheet, no extra effort.</em></p>

Créé par **Vincent GUILLOTTE**.
*Created by **Vincent GUILLOTTE**.*

## Sommaire
*Table of contents*

- [Qu'est-ce que ce plugin ?](#quest-ce-que-ce-plugin-)
- *[What is this plugin?](#quest-ce-que-ce-plugin-)*
- [Ce qui le distingue](#ce-qui-le-distingue)
- *[What sets it apart](#ce-qui-le-distingue)*
- [Aperçu](#aperçu)
- *[Screenshots](#aperçu)*
- [Documentation](#documentation)
- *[Documentation](#documentation)*
- [Licence](#licence)
- *[License](#licence)*

## Qu'est-ce que ce plugin ?
*What is this plugin?*

Quand un ordinateur, un écran, un téléphone ou tout autre matériel est remis à quelqu'un dans l'entreprise, il faut souvent une trace écrite : que le bénéficiaire reconnaît avoir reçu tel matériel, dans tel état, à telle date, et qu'il accepte les conditions d'usage (charte informatique, conditions générales...). En pratique, cette étape est souvent oubliée, faite sur papier et jamais archivée, ou gérée à la main dans un tableur — jusqu'au jour où un litige ou un audit la réclame, et qu'elle n'existe nulle part.

*When a computer, monitor, phone or any other piece of equipment is handed over to someone in the company, a written trace is often needed: that the recipient acknowledges receiving that equipment, in that condition, on that date, and accepts the terms of use (IT charter, terms and conditions...). In practice this step is often skipped, done on paper and never archived, or handled by hand in a spreadsheet — until the day a dispute or an audit asks for it, and it exists nowhere.*

Ce plugin l'automatise entièrement à partir de GLPI, l'outil que vos techniciens utilisent déjà pour gérer le parc — sans nouvel écran à apprendre, sans service externe à payer :

*This plugin automates it entirely from GLPI, the tool your technicians already use to manage assets — no new screen to learn, no external service to pay for:*

1. Un technicien affecte un matériel à un utilisateur dans GLPI (comme il le fait déjà normalement).
1. *A technician assigns equipment to a user in GLPI (as they already normally do).*
2. Le plugin le détecte automatiquement et génère une **fiche de remise en PDF** (matériel, bénéficiaire, accessoires, conditions générales, charte informatique).
2. *The plugin detects it automatically and generates a **handover sheet as a PDF** (equipment, recipient, accessories, terms and conditions, IT charter).*
3. Le bénéficiaire reçoit un **e-mail** avec un lien vers cette fiche.
3. *The recipient gets an **e-mail** with a link to that sheet.*
4. Il se connecte à GLPI avec son propre compte, **consulte le PDF et signe à l'écran** (souris, doigt ou stylet).
4. *They log in to GLPI with their own account, **review the PDF and sign on screen** (mouse, finger or stylus).*
5. Le **PDF signé** est automatiquement archivé — sur la fiche du matériel *et* sur la fiche de l'utilisateur, consultable à tout moment.
5. *The **signed PDF** is automatically archived — on the equipment record **and** on the user's record, viewable at any time.*
6. Si le bénéficiaire ne signe pas, le plugin **relance automatiquement** puis marque le document comme expiré au bout d'un délai configurable.
6. *If the recipient doesn't sign, the plugin **sends automatic reminders**, then marks the document as expired after a configurable delay.*

Le même mécanisme fonctionne aussi en sens inverse : quand un matériel est **restitué** (désaffecté), une fiche de restitution peut être générée et signée de la même façon. Deux workflows supplémentaires existent aussi pour un **Don** ou une **Vente** de matériel, y compris à une personne ou une association extérieure à l'entreprise — voir le [guide d'utilisation](USER_GUIDE.md) pour le détail complet des fonctionnalités.

*The same mechanism also works in reverse: when equipment is **returned** (unassigned), a return sheet can be generated and signed the same way. Two more workflows are also available for a **Donation** or a **Sale** of equipment, including to a person or association outside the company — see the [user guide](USER_GUIDE.md) for the full feature list.*

**Sécurité** : la page de signature n'est jamais accessible par un simple lien anonyme — il faut être connecté à GLPI, et le lien ne fonctionne que pour le bénéficiaire réel du document. Un autre utilisateur authentifié qui obtiendrait le lien par erreur (transfert d'e-mail, etc.) se voit refuser l'accès.

***Security**: the signature page is never reachable via a plain anonymous link — you must be logged in to GLPI, and the link only works for the actual recipient of the document. Another authenticated user who obtained the link by mistake (forwarded e-mail, etc.) is denied access.*

> Ce plugin a été conçu et **validé de bout en bout** dans un environnement GLPI réel (Docker) : affectation → détection → génération PDF → e-mail → connexion → consultation → signature → rattachement — chaque étape a été testée avec de vraies requêtes contre une instance GLPI, y compris les cas de refus d'accès.

> *This plugin was designed and **validated end-to-end** in a real GLPI environment (Docker): assignment → detection → PDF generation → e-mail → login → review → signature → attachment — every step was tested with real requests against a GLPI instance, including access-denial cases.*

## Ce qui le distingue
*What sets it apart*

- **Le passeport de vie complet de chaque matériel**, pas juste la remise du jour : un nouvel onglet **Passeport matériel** reconstitue automatiquement — y compris rétroactivement, sur un parc déjà existant, sans ressaisie — la frise chronologique de tout ce qui lui est arrivé (remises, restitutions, dons, ventes, maintenances, achat, garantie, tickets liés), avec un **score de santé sur 100** calculé à partir de l'âge, des incidents, de l'état physique et du nombre de détenteurs successifs — pondérable par vos soins. La même vue existe côté utilisateur (« qu'a reçu cette personne depuis son arrivée ? »).
- ***Each asset's complete life passport**, not just today's handover: a new **Asset Passport** tab automatically rebuilds — retroactively too, on an already-existing fleet, with no re-entry — the timeline of everything that happened to it (handovers, returns, donations, sales, maintenance, purchase, warranty, linked tickets), with a **health score out of 100** computed from age, incidents, physical condition and the number of successive holders — weights you can tune yourself. The same view exists on the user side ("what has this person received since they joined?").*
- **Zéro coût, zéro dépendance externe** : la signature à l'écran est intégrée (canvas natif, aucun service tiers, aucun abonnement), et chaque signature produit une vraie preuve consultable — signataire, adresse IP, empreinte SHA-256 du document, horodatage.
- ***Zero cost, zero external dependency**: the on-screen signature is built in (native canvas, no third-party service, no subscription), and every signature produces real, viewable proof — signer, IP address, document SHA-256 fingerprint, timestamp.*
- **Pensé pour le multi-site et le multi-marque** : réglages, logo, charte et conditions générales indépendants par entité avec héritage automatique — une même instance GLPI peut servir plusieurs sociétés sans qu'elles se voient.
- ***Built for multi-site and multi-brand setups**: settings, logo, charter and terms and conditions are independent per entity with automatic inheritance — a single GLPI instance can serve several companies without them seeing each other.*
- **Au-delà de la remise** : don et vente de matériel (bénéficiaire interne ou externe à l'entreprise), fiches de maintenance/préparation avec checklist entièrement configurable, état des lieux visuel avec repères de dommage cliquables directement sur un schéma du matériel.
- ***Beyond the handover**: equipment donation and sale (beneficiary internal or external to the company), maintenance/preparation sheets with a fully configurable checklist, visual condition report with damage markers clickable directly on an equipment diagram.*
- **S'installe sans rien casser** : fonctionne immédiatement avec vos actifs personnalisés existants, respecte les droits GLPI natifs, aucune donnée dupliquée hors de ce que le workflow de signature exige réellement.
- ***Installs without breaking anything**: works immediately with your existing custom assets, respects native GLPI rights, no data duplicated beyond what the signature workflow genuinely requires.*
- **Interface en 5 langues** (français, anglais, espagnol, allemand, italien), détectée automatiquement selon la langue du compte du destinataire.
- ***Interface in 5 languages** (French, English, Spanish, German, Italian), detected automatically from the recipient's account language.*

## Aperçu
*Screenshots*

**La fiche d'une remise, côté technicien** — statut, observations, état des lieux visuel avec repères de dommage, accessoires remis :

***A handover record, technician side** — status, observations, visual condition report with damage markers, accessories handed over:*

![Fiche de remise avec état des lieux visuel et accessoires](docs/screenshots/assetsign-fiche.png)

**La page de signature, côté bénéficiaire** — consultation du PDF, état des lieux visuel, remarque libre, signature à l'écran :

***The signature page, recipient side** — PDF review, visual condition report, free-text remark, on-screen signature:*

![Page de signature côté bénéficiaire](docs/screenshots/sign-page.png)

Toutes les autres captures d'écran (paramétrage, tableau de bord, listes, onglets, Intitulés...) sont dans le [guide d'utilisation](USER_GUIDE.md#aperçu).

*All other screenshots (settings, dashboard, lists, tabs, dropdowns...) are in the [user guide](USER_GUIDE.md#aperçu).*

## Documentation
*Documentation*

| Document | Pour qui / pour quoi — *For whom / for what* |
|---|---|
| **[INSTALLATION.md](INSTALLATION.md)** | Prérequis, installation, premier réglage, mise à jour du plugin.<br>*Prerequisites, installation, first-time setup, updating the plugin.* |
| **[USER_GUIDE.md](USER_GUIDE.md)** | Utilisation au quotidien : fonctionnement détaillé, liste complète des fonctionnalités, tableau de bord, FAQ.<br>*Day-to-day use: detailed behavior, full feature list, dashboard, FAQ.* |
| **[ARCHITECTURE.md](ARCHITECTURE.md)** | Pour les développeurs : structure du code, sous-systèmes, sécurité du dépôt, suite de tests automatisés.<br>*For developers: code structure, subsystems, repository security, automated test suite.* |
| **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** | Pièges rencontrés et décisions non évidentes prises en conditions réelles.<br>*Pitfalls encountered and non-obvious decisions made under real conditions.* |
| **[CONTRIBUTING.md](CONTRIBUTING.md)** | Workflow de contribution, vérifications avant de pousser, comment publier une release.<br>*Contribution workflow, checks before pushing, how to publish a release.* |
| **[ROADMAP.md](ROADMAP.md)** | Ce qui est envisagé, pas encore engagé sur une date précise.<br>*What's under consideration, not yet committed to a specific date.* |
| **[SECURITY.md](SECURITY.md)** | Comment signaler une vulnérabilité.<br>*How to report a vulnerability.* |
| **[CHANGELOG.md](CHANGELOG.md)** | Historique des versions publiées.<br>*History of published releases.* |

## Licence
*License*

[GPL-3.0-only](LICENSE), conformément aux conventions des plugins GLPI.

*[GPL-3.0-only](LICENSE), in line with GLPI plugin conventions.*
