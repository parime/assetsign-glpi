# Guide d'utilisation

Ce guide suppose le plugin déjà installé et configuré — voir [INSTALLATION.md](INSTALLATION.md) sinon.

## Comment ça marche, vu par chaque personne

**L'administrateur** configure une fois pour toutes (Configuration > Remise & signature) : quels types de matériel sont concernés, quand une signature est déclenchée (affectation, réaffectation, restitution, ou changement d'État), les délais de relance, le logo et la charte à afficher sur les PDF.

**Le technicien** n'a rien de spécial à faire : il continue d'affecter le matériel dans GLPI comme d'habitude. Le plugin se charge du reste. Il peut consulter à tout moment l'historique des remises (menu Administration > Remise & signature > Remises) et relancer manuellement un bénéficiaire qui n'a pas encore signé.

**Le bénéficiaire** reçoit un e-mail, clique sur le lien, se connecte à GLPI s'il ne l'est pas déjà, relit le document et signe à l'écran. Une fois signé, il retrouve le PDF signé dans l'onglet Documents de son profil.

## Fonctionnalités

- Détection automatique de l'affectation/réaffectation/restitution d'un matériel, sans action manuelle du technicien.
- Génération PDF (fiche de remise ou de restitution) avec gabarits personnalisables (conditions générales, charte informatique) — un nouveau gabarit part d'un texte par défaut modifiable plutôt que d'un champ vide.
- Signature à l'écran intégrée (aucun service externe requis, aucun coût de licence) — souris, doigt ou stylet, avec prévisualisation du PDF avant de signer.
- Relances automatiques en cas d'inaction, avec limite de nombre configurable, puis expiration automatique du lien passé un certain délai.
- Relance manuelle à tout moment depuis la fiche d'une remise, ou en action groupée sur plusieurs remises sélectionnées depuis la liste (Administration > Remise & signature > Remises).
- Alerte au technicien quelques jours avant l'expiration réelle du lien (configurable par entité, désactivable) — pour pouvoir relancer le bénéficiaire autrement (appel, passage sur place) avant qu'il ne soit trop tard, plutôt que d'apprendre l'inaction une fois le lien déjà expiré.
- Rattachement automatique du PDF signé au matériel *et* à l'utilisateur (onglet Documents natif de GLPI).
- Preuve de signature consultable directement sur la fiche de chaque remise signée (menu Administration > Remise & signature > Remises) : signataire, adresse IP, navigateur, empreinte SHA-256 du document — utile en cas de contestation, sans avoir à rouvrir le PDF pour retrouver ces informations.
- Historique complet et horodaté de chaque remise, avec preuve de signature (adresse IP, date/heure, empreinte du document).
- Accessoires remis (chargeur, sacoche, écran additionnel...) : catalogue configurable, associable à chaque remise avec quantité et commentaire.
- Marque et modèle du matériel affichés automatiquement sur le PDF quand l'information existe dans GLPI.
- Logo de l'entreprise personnalisable par simple envoi de fichier depuis le poste de l'administrateur — aperçu en direct du rendu final avant même d'enregistrer. Utile pour un GLPI partagé entre plusieurs sociétés/marques : chaque entité peut avoir son propre logo, et une entité (ex. la racine, ou une filiale) peut cocher « Imposer ce logo à toutes les entités enfants » pour forcer le même logo sur toute sa descendance, y compris les sous-entités de ses entités enfants — même si celles-ci ont déjà envoyé leur propre logo.
- Lien vers la charte informatique complète configurable par entité (utile si plusieurs sociétés/sites hébergent leur charte à des endroits différents), en plus d'un texte de charte propre à chaque gabarit.
- Fonctionne avec les **actifs personnalisés** créés dans GLPI (Configuration > Actifs personnalisés), en plus des types standards (ordinateurs, écrans, périphériques, téléphones) — aucune modification du plugin nécessaire.
- Configuration indépendante par entité, avec héritage automatique (une entité sans réglage propre hérite de celui de son entité parente la plus proche).
- Interface disponible en français et en anglais (détectée automatiquement selon la langue du compte GLPI du destinataire pour les e-mails).
- Tableau de bord GLPI natif : voir la section [Tableau de bord](#tableau-de-bord) ci-dessous.
- Menu dédié **Administration > Remise & signature > Gestion des fiches** : vue transverse de toutes les remises/restitutions (tous matériels et bénéficiaires confondus), avec téléchargement direct du PDF (non signé et signé) et annulation d'une ou plusieurs demandes en attente (individuellement ou en action groupée).
- Conditions générales et charte informatique activables **indépendamment** sur chaque gabarit (deux cases à cocher) : un gabarit peut par exemple n'afficher que la charte, ou aucune des deux sections, sans avoir à vider le texte.
- Bouton « Retour à l'accueil GLPI » proposé au bénéficiaire une fois sa signature enregistrée (et sur l'écran d'erreur d'un lien invalide/expiré), pour ne pas le laisser sur une page sans issue.
- Objet et corps des e-mails adaptés automatiquement au type de fiche (remise ou restitution) via la balise `##remise.type##` — un seul jeu de notifications pour tous les types, au lieu d'un texte figé qui parlait toujours de « remise » même pour une restitution.
- Champ « Observations » libre et optionnel (désactivé par défaut, activable par entité) : permet de noter un état constaté du matériel, repris sur le PDF tant que la fiche n'est pas signée.
- **Don de matériel** et **Vente de matériel** : deux workflows supplémentaires, désactivables par entité, déclenchables soit manuellement depuis l'onglet Remise d'un matériel (boutons dédiés « Créer une fiche de don »/« Créer une fiche de vente »), soit **automatiquement par changement d'État** (comme Remise/Restitution), au choix de l'administrateur parmi ses propres États. La Vente ajoute un prix et une date de vente, repris sur le PDF dès qu'ils sont renseignés — quand la Vente est déclenchée automatiquement (prix inconnu à cet instant), la fiche n'y fait simplement pas encore référence, et le prix/la date restent modifiables après coup depuis la fiche.
- **Bénéficiaire interne ou externe** (Don/Vente uniquement) : la création manuelle propose un choix — un compte GLPI existant (workflow signé habituel, e-mail + signature à l'écran), ou une personne/organisation **externe à l'entreprise** (nom et contact en texte libre, aucune signature électronique requise puisqu'un tiers sans compte GLPI ne peut pas se connecter pour signer — le PDF généré fait alors directement foi). Si un changement d'État déclenche automatiquement un don/une vente sur un matériel **sans utilisateur assigné** (aucun bénéficiaire interne connu), le plugin ne peut rien créer tout seul mais affiche un message invitant à créer la fiche manuellement avec le bon bénéficiaire.
- **État des lieux visuel** (désactivable par entité) : 3 vues de référence (arrière, avant, dessous) toujours affichées sur la fiche dès que le réglage est actif — un technicien (depuis la fiche admin) **ou le bénéficiaire lui-même (depuis sa page de signature, avant de signer)** peut cliquer dessus pour déposer un repère, avec description et gravité optionnelles, repris sur le PDF.
- **Remarque libre du bénéficiaire** : un champ texte optionnel sur la page de signature, que le bénéficiaire remplit lui-même avant de signer (ex. signaler un souci constaté à la réception) — repris sur le PDF sous "Remarque du bénéficiaire", et visible en lecture seule sur la fiche admin.
- **Fiches de maintenance/préparation** : formulaire interne (non signé, sans bénéficiaire) avec une checklist de points de contrôle entièrement configurable par l'administrateur (Configuration > Intitulés) et un commentaire libre — accessible depuis l'onglet Maintenance de chaque matériel *et* directement depuis la liste des fiches (Outils > Fiches de maintenance > Nouvelle fiche, avec sélection du matériel). Chaque point de contrôle définit son propre **type de saisie** (case à cocher, texte libre, ou menu déroulant avec des options définies par l'administrateur) — pas seulement des cases à cocher.
- **Aperçu du PDF en temps réel** sur les pages de configuration et de gabarit : cocher/décocher une case ou modifier un texte met à jour l'aperçu affiché, avant même d'enregistrer — rendu strictement identique au vrai PDF (mêmes gabarits Twig, mêmes données).
- **Paramétrage organisé par onglet** (Configuration > Remise & signature > Configuration) : un onglet par type de fiche (Général, Remise, Restitution, Don, Vente, Compléments, Maintenance), chacun avec ses propres réglages, son aperçu, et un lien direct vers ses gabarits — un seul formulaire, un seul enregistrement malgré la navigation par onglets.

## Ce qui n'est *pas* encore implémenté

- **Fournisseur de signature externe** (Yousign, DocuSeal...) pour un niveau de signature électronique renforcé (eIDAS "avancée"/"qualifiée"). Seule la signature à l'écran intégrée est disponible aujourd'hui — elle correspond à un niveau de signature électronique "simple", pas à une signature cryptographique.
- Couverture de tests automatisés : volontairement partielle aujourd'hui, à étendre au fil des évolutions (voir [ARCHITECTURE.md](ARCHITECTURE.md#tests-automatisés)).

Voir aussi [ROADMAP.md](ROADMAP.md) pour ce qui est envisagé.

## Alternative au cron GLPI

Le CronTask GLPI (**Administration > Actions automatiques** > `remiseReminders` / `remiseExpire` / `remiseExpiryWarning`) est actif par défaut et suffit dans la plupart des cas.

Si vous préférez piloter ces actions depuis un ordonnanceur externe (cron système, tâche planifiée...) plutôt que de dépendre du cycle interne de GLPI, trois commandes console existent :

```bash
php bin/console plugins:remise:run-reminders
php bin/console plugins:remise:run-expiration
php bin/console plugins:remise:warn-expiring
```

**Si vous utilisez ces commandes depuis un ordonnanceur externe, désactivez les CronTask GLPI correspondants** pour éviter un double envoi des relances — les deux mécanismes appellent exactement la même logique, ils ne sont pas complémentaires.

## Tableau de bord

Quatre cartes natives (widgets « grand nombre ») apparaissent dans le groupe **« Remise & signature »** de l'éditeur de tableau de bord GLPI (menu Tableau de bord > Modifier > Ajouter une carte) :

- Remises en attente de signature
- Remises signées
- Remises expirées
- Échecs de création (30 derniers jours) — un échec de création automatique (rare : génération PDF, envoi du jeton...) n'interrompt jamais la sauvegarde du matériel qui l'a déclenché, mais reste sinon invisible sans consulter le fichier de log du plugin ; cette carte le rend visible d'un coup d'œil.

Chaque carte renvoie vers la liste filtrée correspondante en un clic, et respecte l'entité active sélectionnée.

## FAQ

**Un compte GLPI est-il obligatoire pour signer ?**
Oui pour un bénéficiaire interne (workflow Remise/Restitution/Don/Vente habituel). Pour Don et Vente uniquement, un bénéficiaire externe (nom/contact en texte libre, sans compte GLPI) peut être choisi lors de la création manuelle — dans ce cas, aucune signature électronique n'est demandée : le PDF généré fait directement foi.

**Que se passe-t-il si le bénéficiaire ne signe jamais ?**
Le plugin relance automatiquement jusqu'à la limite configurée, puis marque la demande comme expirée une fois le délai de validité du lien dépassé. Le technicien peut aussi relancer manuellement à tout moment avant l'expiration.

**Le lien de signature peut-il être transféré ou réutilisé par quelqu'un d'autre ?**
Non. La page de signature vérifie que l'utilisateur connecté est bien le bénéficiaire réel de cette remise précise — un autre utilisateur authentifié qui obtiendrait le lien par erreur se voit refuser l'accès. Le jeton est aussi à usage unique et se désactive après un nombre de tentatives invalides.

**Un changement de nom de l'utilisateur (mariage, divorce...) déclenche-t-il une nouvelle demande de signature ?**
Non. Seuls les changements sur le matériel lui-même (affectation, État) déclenchent une remise — modifier une fiche utilisateur n'a aucun effet sur les remises déjà signées ou en cours.

**Si je modifie la fiche d'un matériel sans toucher au champ Utilisateur ni à l'État, est-ce qu'une remise se déclenche par erreur ?**
Non. Le plugin ne réagit qu'aux changements réels des champs qu'il surveille (Utilisateur, État selon la configuration) — modifier un autre champ (nom, emplacement...) n'a aucun effet.

**Comment savoir si des échecs silencieux de création se sont produits ?**
La carte de tableau de bord « Échecs de création (30 derniers jours) » les rend visibles sans avoir à consulter les logs du plugin à la main.

**Peut-on gérer plusieurs sociétés/marques avec des logos et chartes différents ?**
Oui, via la configuration indépendante par entité avec héritage. Voir la case « Imposer ce logo à toutes les entités enfants » dans [INSTALLATION.md](INSTALLATION.md#3-configurer) pour forcer un même logo sur toute une descendance d'entités.

**Le plugin fonctionne-t-il avec mes actifs personnalisés (assets custom) ?**
Oui, automatiquement dès qu'ils sont actifs dans GLPI (Configuration > Actifs personnalisés) — aucune modification du plugin n'est nécessaire, ils apparaissent dans la liste des types de matériel gérés.
