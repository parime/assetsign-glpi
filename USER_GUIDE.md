[🇫🇷 Français](#-français) · [🇬🇧 English](#-english)

## 🇫🇷 Français

**Guide d'utilisation**

Ce guide suppose le plugin déjà installé et configuré — voir [INSTALLATION.md](INSTALLATION.md) sinon.

### Comment ça marche, vu par chaque personne

**L'administrateur** configure une fois pour toutes (Configuration > AssetSign) : quels types de matériel sont concernés, quand une signature est déclenchée (affectation, réaffectation, restitution, ou changement d'État), les délais de relance, le logo et la charte à afficher sur les PDF.

**Le technicien** n'a rien de spécial à faire : il continue d'affecter le matériel dans GLPI comme d'habitude. Le plugin se charge du reste. Il peut consulter à tout moment l'historique des remises (menu Outils > Assetsigns) et relancer manuellement un bénéficiaire qui n'a pas encore signé.

**Le bénéficiaire** reçoit un e-mail, clique sur le lien, se connecte à GLPI s'il ne l'est pas déjà, relit le document et signe à l'écran. Une fois signé, il retrouve le PDF signé dans l'onglet Documents de son profil.

### Aperçu

**Le paramétrage, organisé par onglet, avec aperçu du PDF en direct** (avant même d'enregistrer) — voir [INSTALLATION.md](INSTALLATION.md#3-configurer) pour le détail des réglages :

![Page de configuration avec onglets et aperçu en direct](docs/screenshots/config.png)

**Le PDF généré**, identique à ce que l'aperçu en direct affichait déjà avant signature :

![PDF de remise généré](docs/screenshots/pdf-genere.png)

**Une fiche de maintenance interne**, avec un type de saisie propre à chaque point de contrôle (case à cocher, texte libre, menu déroulant) :

![Formulaire de fiche de maintenance avec types de saisie variés](docs/screenshots/maintenance.png)

#### Les intitulés (Configuration > Intitulés)

Trois listes déroulantes configurables par l'administrateur apparaissent comme n'importe quel autre intitulé natif de GLPI (Configuration > Intitulés), sans écran séparé à connaître :

**Accessoires de remise** — le catalogue proposé lors de l'ajout d'un accessoire sur une fiche (chargeur, sacoche, souris...) :

![Liste des accessoires de remise dans Intitulés](docs/screenshots/intitules-accessoires.png)

**Gabarits de remise** — un gabarit par type de fiche (Remise/Restitution/Don/Vente), avec son propre texte de conditions générales et son statut par défaut. Le gabarit par défaut de chaque type s'édite directement depuis l'onglet correspondant de Configuration > AssetSign (voir plus bas) — cette liste reste utile pour gérer plusieurs gabarits par type/entité au-delà du seul gabarit par défaut :

![Liste des gabarits de remise dans Intitulés](docs/screenshots/intitules-gabarits.png)

**Points de contrôle de maintenance** — chaque point définit son propre type de saisie (case à cocher, texte libre, ou menu déroulant avec ses propres options), configuré directement depuis cette liste :

![Liste des points de contrôle de maintenance dans Intitulés](docs/screenshots/intitules-checklist-maintenance.png)

#### Les listes transverses (Outils > Assetsigns / Fiches de maintenance)

**Outils > Assetsigns ("Gestion des fiches")** : toutes les remises/restitutions/dons/ventes de tout le parc, quel que soit le matériel ou le bénéficiaire, avec téléchargement direct des PDF (non signé et signé) et annulation en action groupée. Cette liste sert de tableau de bord central pour le technicien qui n'a pas besoin de rouvrir chaque fiche matériel une par une :

![Liste transverse de toutes les remises](docs/screenshots/liste-assetsigns.png)

**Outils > Fiches de maintenance** : la même logique, mais pour les fiches de maintenance internes (sans bénéficiaire, avec un PDF téléchargeable systématique et une signature du technicien optionnelle, cf. plus bas) — un second point d'entrée vers la même liste que l'onglet Maintenance d'un matériel donné :

![Liste transverse de toutes les fiches de maintenance](docs/screenshots/liste-maintenance.png)

#### L'onglet « Assetsigns » sur la fiche d'un matériel

Chaque matériel géré par le plugin (ordinateur, écran, périphérique, téléphone, ou un actif personnalisé) gagne un onglet **Assetsigns** dans son menu latéral, à côté des onglets natifs de GLPI. Il liste l'historique des remises déjà faites sur ce matériel — avec, pour chaque ligne, un lien de téléchargement direct du PDF (non signé et/ou signé selon l'avancement) sans avoir à ouvrir la fiche — et propose un formulaire de création manuelle pour un Don ou une Vente — avec le choix entre un bénéficiaire interne (un compte GLPI existant, via le menu déroulant) ou externe (nom et contact en texte libre, pour une personne ou une association sans compte GLPI) :

![Onglet Assetsigns d'un ordinateur avec le formulaire de création Don/Vente](docs/screenshots/onglet-ordinateur-assetsign-creation.png)

Une fois une remise signée, la même fiche affiche son statut, ses dates, et le bénéficiaire réel :

![Onglet Assetsigns d'un ordinateur avec une remise déjà signée](docs/screenshots/onglet-ordinateur-assetsign-signee.png)

#### L'onglet « Maintenance » sur la fiche d'un matériel

Un second onglet dédié, indépendant des remises signées (pas de bénéficiaire, pas de jeton, pas d'e-mail), avec le formulaire de nouvelle fiche de maintenance directement accessible — chaque point de contrôle affiche son propre type de saisie (case à cocher, champ texte, menu déroulant) tel que défini dans Intitulés. Une fois créée, la fiche génère toujours un PDF téléchargeable (même mise en page que les fiches remise/don/vente, avec le logo de l'entité) ; si l'administrateur a activé la signature du technicien (Configuration > AssetSign > Maintenance), un pavé de signature apparaît sur ce même formulaire et doit être rempli avant de pouvoir créer la fiche — sans jeton ni e-mail, le technicien étant déjà connecté :

![Onglet Maintenance d'un ordinateur avec le formulaire de checklist](docs/screenshots/onglet-ordinateur-maintenance.png)

#### L'onglet « Passeport matériel » sur la fiche d'un matériel

Un troisième onglet, en lecture seule, qui répond à « qui a utilisé ce matériel depuis son achat ? ». En tête, une carte de synthèse (modèle, fabricant, n° série, État, utilisateur/entité actuels, achat, fin de garantie) donne une vue d'ensemble immédiate sans avoir à dérouler toute la frise — chaque information n'apparaît que si elle est réellement renseignée. Vient ensuite une frise chronologique agrégeant automatiquement les remises, restitutions, dons, ventes et fiches de maintenance de ce matériel (rien à ressaisir, ces événements viennent des onglets ci-dessus), avec un compteur de « vies » (nombre de bénéficiaires successifs et leurs périodes). Chaque événement conserve le nom du bénéficiaire au moment où il a eu lieu, même si le compte GLPI correspondant est supprimé plus tard — ce nom peut être anonymisé après un délai configurable (Configuration > AssetSign > Passeport matériel), la date et le type d'événement restant alors seuls visibles. La fonctionnalité elle-même peut être désactivée par entité, et les types d'événement affichés dans la frise sont filtrables, depuis ce même onglet de configuration.

Pour du matériel déjà présent avant l'installation de cette fonctionnalité, la frise ne reste pas vide : à la première consultation, le plugin retrouve automatiquement dans l'historique natif de GLPI (les mêmes changements d'Utilisateur/d'État déjà utilisés pour déclencher les remises automatiques) tout ce qui s'est passé auparavant. Un bouton **« Forcer la recherche dans l'historique »** permet de relancer cette recherche à tout moment (par exemple après avoir modifié les États déclencheurs), sans jamais créer de doublon.

La frise complète aussi automatiquement les dates connues depuis l'onglet **Infocom** du matériel (achat, commande, livraison, mise en service, garantie, réforme, prix d'achat) — répond à « que s'est-il passé avant même la première attribution ? ». Rien n'est recopié : seules les dates effectivement renseignées apparaissent (un matériel sans aucune information financière/administrative n'affiche simplement rien de plus). Désactivable par entité (Configuration > AssetSign > Passeport matériel).

La date de réforme peut d'ailleurs être **renseignée automatiquement** plutôt que saisie à la main : dans Configuration > AssetSign > onglet **Réforme**, choisissez les États du matériel qui doivent déclencher l'écriture de cette date (même mécanisme que les déclenchements Attribution/Restitution/Don/Vente). Aucune fiche n'est créée pour autant — seul le champ natif GLPI « Réforme » (onglet Finances/Infocom) est écrit à la date du jour, exactement ce que la frise ci-dessus affiche déjà en lecture seule. Une date déjà renseignée (saisie manuelle ou déclenchement précédent) n'est jamais écrasée.

Les **tickets liés** au matériel apparaissent également dans la frise, en lecture seule (rien n'est recopié ni modifiable depuis cet onglet) — chaque technicien ne voit que les tickets auxquels il a réellement accès, exactement comme sur l'onglet Tickets natif du matériel. Désactivable par entité, dans le même onglet de configuration.

La carte de synthèse affiche aussi des **indicateurs temporels** : l'âge du matériel (depuis son achat si la date est connue dans Infocom, sinon depuis son entrée dans GLPI — le libellé précise toujours laquelle des deux), le temps réellement utilisé (cumul des périodes où le matériel a été attribué à quelqu'un, en pourcentage de son âge) et le temps passé en stock. La durée de chaque « vie » (période d'attribution à une même personne) est également affichée directement dans la liste.

![Fiche d'identité du Passeport matériel avec indicateurs temporels](docs/screenshots/passeport-fiche-identite.png)

Un **score de santé** (0 à 100, 100 = état idéal) est calculé à partir de quatre facteurs : l'âge, le nombre de tickets liés (incidents), l'état physique (marqueurs de dégât relevés lors des états des lieux) et le nombre de changements de détenteur. Le détail du calcul (pourcentage de dégradation par facteur) est affiché sous le score :

![Score de santé avec détail du calcul par facteur](docs/screenshots/passeport-score-sante.png)

L'importance de chaque facteur se règle dans Configuration > AssetSign > Passeport matériel : les poids sont **relatifs** (pas besoin de les faire sommer à 100 exactement), et mettre un poids à 0 désactive simplement ce facteur. Le score peut aussi être désactivé entièrement.

![Réglages des poids du score de santé dans la configuration](docs/screenshots/passeport-reglages-poids.png)

Un bouton **« Imprimer une étiquette QR code »** (désactivable par entité, Configuration > AssetSign > Compléments — réglage distinct du QR code des fiches PDF) ouvre une page dédiée, pensée pour l'impression, avec le nom/n° de série du matériel, le QR code et un bouton « Imprimer ». Une fois l'étiquette collée sur le matériel, la scanner avec un téléphone ouvre directement cet onglet Passeport matériel dans GLPI (connexion habituelle requise si nécessaire, comme n'importe quel lien GLPI — aucun accès anonyme).

#### L'onglet « Assetsigns » sur la fiche d'un utilisateur

Le même onglet Assetsigns existe aussi côté utilisateur (fiche d'un compte GLPI, Administration > Utilisateurs) — filtré cette fois par bénéficiaire plutôt que par matériel, avec une colonne supplémentaire indiquant à quel matériel chaque ligne correspond (une même personne ayant pu recevoir plusieurs matériels différents dans le temps) : pratique pour retrouver d'un coup d'œil tout ce qu'une personne a reçu, télécharger directement chaque PDF, sans avoir à connaître à l'avance sur quel matériel chercher. Un raccourci **"Assigner un matériel à cet utilisateur"** permet en plus d'affecter directement un matériel depuis cet écran (choix du type puis du matériel) — l'affectation déclenche automatiquement la création d'une remise, exactement comme changer le champ "Utilisateur" depuis la fiche du matériel :

![Onglet Assetsigns sur la fiche d'un utilisateur](docs/screenshots/onglet-utilisateur-assetsign.png)

#### L'onglet « Passeport utilisateur » sur la fiche d'un utilisateur

Vue symétrique du Passeport matériel : une frise chronologique de tout ce qu'une personne a reçu, rendu, donné ou acheté, de son entrée dans l'entreprise à sa désactivation/suppression. Chaque ligne indique le nom et le numéro de série du matériel concerné (repli explicite si l'un des deux manque, par exemple un matériel supprimé depuis) et renvoie vers la fiche d'origine. Comme pour le Passeport matériel, l'historique antérieur à l'installation de cette fonctionnalité est retrouvé automatiquement (même bouton « Forcer la recherche dans l'historique » disponible ici, sur l'ensemble du matériel déjà eu par cette personne).

### Fonctionnalités

- Détection automatique de l'affectation/réaffectation/restitution d'un matériel, sans action manuelle du technicien.
- Génération PDF (fiche de remise ou de restitution) avec gabarits personnalisables (conditions générales, charte informatique) — un nouveau gabarit part d'un texte par défaut modifiable plutôt que d'un champ vide. Variables disponibles dans ce texte libre (`{beneficiaire}`, `{technicien}`, `{materiel}`, `{date}`, `{entite}`), remplacées automatiquement sur le PDF : un seul gabarit rédigé une fois plutôt qu'une variante par cas.
- Signature à l'écran intégrée (aucun service externe requis, aucun coût de licence) — souris, doigt ou stylet, avec prévisualisation du PDF avant de signer.
- Relances automatiques en cas d'inaction, avec limite de nombre configurable, puis expiration automatique du lien passé un certain délai.
- Relance manuelle à tout moment depuis la fiche d'une remise, ou en action groupée sur plusieurs remises sélectionnées depuis la liste (Outils > Assetsigns).
- Alerte au technicien quelques jours avant l'expiration réelle du lien (configurable par entité, désactivable) — pour pouvoir relancer le bénéficiaire autrement (appel, passage sur place) avant qu'il ne soit trop tard, plutôt que d'apprendre l'inaction une fois le lien déjà expiré.
- Rattachement automatique du PDF signé au matériel *et* à l'utilisateur (onglet Documents natif de GLPI).
- Preuve de signature consultable directement sur la fiche de chaque remise signée (menu Outils > Assetsigns) : signataire, adresse IP, navigateur, empreinte SHA-256 du document — utile en cas de contestation, sans avoir à rouvrir le PDF pour retrouver ces informations.
- Historique complet et horodaté de chaque remise, avec preuve de signature (adresse IP, date/heure, empreinte du document).
- Accessoires remis (chargeur, sacoche, écran additionnel...) : catalogue configurable, associable à chaque remise avec quantité et commentaire.
- Marque et modèle du matériel affichés automatiquement sur le PDF quand l'information existe dans GLPI.
- Logo de l'entreprise personnalisable par simple envoi de fichier depuis le poste de l'administrateur — aperçu en direct du rendu final avant même d'enregistrer. Utile pour un GLPI partagé entre plusieurs sociétés/marques : chaque entité peut avoir son propre logo, et une entité (ex. la racine, ou une filiale) peut cocher « Imposer ce logo à toutes les entités enfants » pour forcer le même logo sur toute sa descendance, y compris les sous-entités de ses entités enfants — même si celles-ci ont déjà envoyé leur propre logo.
- Lien vers la charte informatique complète configurable par entité (utile si plusieurs sociétés/sites hébergent leur charte à des endroits différents), en plus d'un texte de charte propre à chaque gabarit.
- Fonctionne avec les **actifs personnalisés** créés dans GLPI (Configuration > Actifs personnalisés), en plus des types standards (ordinateurs, écrans, périphériques, téléphones) — aucune modification du plugin nécessaire.
- Configuration indépendante par entité, avec héritage automatique (une entité sans réglage propre hérite de celui de son entité parente la plus proche).
- Interface disponible en français, anglais, espagnol, allemand et italien (détectée automatiquement selon la langue du compte GLPI du destinataire pour les e-mails).
- Tableau de bord GLPI natif : voir la section [Tableau de bord](#tableau-de-bord) ci-dessous.
- Menu dédié **Outils > Assetsigns ("Gestion des fiches")** : vue transverse de toutes les remises/restitutions (tous matériels et bénéficiaires confondus), avec téléchargement direct du PDF (non signé et signé) et annulation d'une ou plusieurs demandes en attente (individuellement ou en action groupée).
- Conditions générales et charte informatique activables **indépendamment** sur chaque gabarit (deux cases à cocher) : un gabarit peut par exemple n'afficher que la charte, ou aucune des deux sections, sans avoir à vider le texte.
- Bouton « Retour à l'accueil GLPI » proposé au bénéficiaire une fois sa signature enregistrée (et sur l'écran d'erreur d'un lien invalide/expiré), pour ne pas le laisser sur une page sans issue.
- Objet et corps des e-mails adaptés automatiquement au type de fiche (remise ou restitution) via la balise `##remise.type##` — un seul jeu de notifications pour tous les types, au lieu d'un texte figé qui parlait toujours de « remise » même pour une restitution.
- Champ « Observations » libre et optionnel (désactivé par défaut, activable par entité) : permet de noter un état constaté du matériel, repris sur le PDF tant que la fiche n'est pas signée.
- **Don de matériel**, **Vente de matériel** et **Destruction de matériel** : trois workflows supplémentaires, désactivables par entité, déclenchables soit manuellement depuis l'onglet Remise d'un matériel (boutons dédiés « Créer une fiche de don »/« Créer une fiche de vente »/« Créer une fiche de destruction »), soit **automatiquement par changement d'État** (comme Remise/Restitution), au choix de l'administrateur parmi ses propres États. La Vente ajoute un prix et une date de vente ; le Don, un organisme bénéficiaire (avec justificatif joint en pièce jointe, facultatif) ; la Destruction, un prestataire (avec certificat de destruction joint, facultatif) — tous repris sur le PDF dès qu'ils sont renseignés. Quand l'une de ces fiches est déclenchée automatiquement (organisme/prestataire/prix inconnu à cet instant), elle n'y fait simplement pas encore référence, et ces informations restent modifiables après coup depuis la fiche.
- **Bénéficiaire interne ou externe** (Don/Vente/Destruction uniquement) : la création manuelle propose un choix — un compte GLPI existant (workflow signé habituel, e-mail + signature à l'écran), ou une personne/organisation **externe à l'entreprise** (nom et contact en texte libre, aucune signature électronique requise puisqu'un tiers sans compte GLPI ne peut pas se connecter pour signer — le PDF généré fait alors directement foi). Si un changement d'État déclenche automatiquement un don/une vente/une destruction sur un matériel **sans utilisateur assigné** (aucun bénéficiaire interne connu), le plugin ne peut rien créer tout seul mais affiche un message invitant à créer la fiche manuellement avec le bon bénéficiaire.
- **État des lieux visuel** (désactivable par entité) : 3 vues de référence (arrière, avant, dessous) toujours affichées sur la fiche dès que le réglage est actif — un technicien (depuis la fiche admin) **ou le bénéficiaire lui-même (depuis sa page de signature, avant de signer)** peut cliquer dessus pour déposer un repère, avec description et gravité optionnelles, repris sur le PDF.
- **Remarque libre du bénéficiaire** : un champ texte optionnel sur la page de signature, que le bénéficiaire remplit lui-même avant de signer (ex. signaler un souci constaté à la réception) — repris sur le PDF sous "Remarque du bénéficiaire", et visible en lecture seule sur la fiche admin.
- **Délégation de la signature** (bénéficiaire interne uniquement, désactivable par entité) : quand le bénéficiaire désigné est indisponible durablement (congés, arrêt maladie, départ...), la signature peut être confiée à un autre compte GLPI. Deux modes activables indépendamment (Configuration > AssetSign > Délégation de signature) — un technicien/administrateur délègue depuis la fiche Assetsign (bouton « Déléguer la signature »), ou le bénéficiaire se délègue lui-même depuis sa page de signature (motif obligatoire dans ce cas) ; le second mode nécessite le premier. Le bénéficiaire d'origine reste tracé comme signataire prévu (jamais modifié) ; le lien de signature qui lui a été envoyé devient invalide dès la délégation, seul le nouveau lien envoyé au délégué fonctionne — un administrateur peut à tout moment révoquer la délégation pour rendre la main au bénéficiaire d'origine. Le PDF signé, la preuve de signature et l'historique de la fiche portent tous une mention explicite de la délégation (qui a réellement signé, par qui, quand, pour quel motif).
- **Fiches de maintenance/préparation** : formulaire interne (sans bénéficiaire, sans jeton, sans e-mail) avec une checklist de points de contrôle entièrement configurable par l'administrateur (Configuration > Intitulés) et un commentaire libre — accessible depuis l'onglet Maintenance de chaque matériel *et* directement depuis la liste des fiches (Outils > Fiches de maintenance > Nouvelle fiche, avec sélection du matériel). Chaque point de contrôle définit son propre **type de saisie** (case à cocher, texte libre, ou menu déroulant avec des options définies par l'administrateur) — pas seulement des cases à cocher. **État des lieux visuel disponible aussi sur ces fiches** (même réglage que ci-dessous) : les repères sont déposés au moment de la création (une fiche de maintenance est un constat figé, jamais modifiée après coup — contrairement à une remise, aucune fenêtre d'édition ultérieure n'est proposée), puis simplement affichés en lecture seule sur la fiche une fois créée.
- **PDF de la fiche de maintenance, toujours généré** (Configuration > AssetSign > Maintenance) : même mise en page que les fiches remise/don/vente (logo de l'entité, style identique), téléchargeable depuis la fiche, l'onglet Maintenance, ou la liste transverse. **Signature du technicien optionnelle** (case à cocher, désactivée par défaut) : si activée, un pavé de signature identique à celui d'une vente apparaît sur le formulaire de création et devient obligatoire — mais contrairement au bénéficiaire d'une remise, le technicien signe **directement sur ce même formulaire, en une seule requête** (aucun jeton, aucun e-mail, aucune page séparée, puisqu'il est déjà connecté). La preuve de signature (signataire, adresse IP, empreinte SHA-256 du PDF, horodatage) est enregistrée et consultable sur la fiche, comme pour une remise signée.
- **Aperçu du PDF en temps réel** sur les pages de configuration et de gabarit : cocher/décocher une case ou modifier un texte met à jour l'aperçu affiché, avant même d'enregistrer — rendu strictement identique au vrai PDF (mêmes gabarits Twig, mêmes données).
- **Paramétrage organisé par onglet** (Configuration > AssetSign > Configuration) : un onglet par type de fiche (Général, Remise, Restitution, Don, Vente, Destruction, Réforme, Compléments, Maintenance), chacun avec ses propres réglages, son aperçu, et l'édition du gabarit par défaut de ce type directement intégrée dans l'onglet — un seul formulaire, un seul enregistrement malgré la navigation par onglets.
- **Nom de l'entreprise et protection du PDF** (onglet Général) : un nom d'entreprise optionnel affiché à côté du logo sur les fiches PDF, et une case « Protéger le PDF » qui chiffre le document généré pour empêcher sa copie/modification dans un lecteur qui respecte cette restriction (la consultation et l'impression restent toujours possibles).
- **QR code optionnel sur les fiches PDF** (désactivable, onglet Compléments) : renvoie directement vers la fiche correspondante dans GLPI, pratique pour la retrouver depuis un contrôle physique du matériel.
- **Étiquette QR code imprimable sur le matériel** (désactivable par entité, onglet Compléments — réglage distinct du précédent) : bouton sur l'onglet Passeport matériel ouvrant une page dédiée à imprimer et coller sur le matériel ; scannée, elle renvoie directement vers ce même onglet Passeport matériel.
- **Symbole monétaire personnalisable** (onglet Vente) : affiché après le prix sur le PDF (`€` par défaut, modifiable en `$`, `CHF`...) — utile pour toute organisation hors zone euro.
- **Bouton « Envoyer un e-mail de test »** (onglet Général) : vérifie que l'envoi d'e-mail fonctionne avec la configuration actuelle, sans attendre une vraie fiche à signer.
- **Suivi de version** (en tête de l'onglet Général) : compare la version installée à la dernière version publiée sur GitHub, pour repérer immédiatement un environnement resté sur une ancienne version.
- **Filigrane configurable sur l'aperçu en direct** (texte + opacité, onglet Compléments) : distingue visuellement un aperçu non enregistré d'un vrai PDF — n'apparaît jamais sur le document final.

### Ce qui n'est *pas* encore implémenté

- **Fournisseur de signature externe** pour un niveau de signature électronique renforcé (eIDAS "avancée"/"qualifiée"). Seule la signature à l'écran intégrée est disponible aujourd'hui — elle correspond à un niveau de signature électronique "simple", pas à une signature cryptographique.
- Couverture de tests automatisés : volontairement partielle aujourd'hui, à étendre au fil des évolutions (voir [ARCHITECTURE.md](ARCHITECTURE.md#tests-automatisés)).

Voir aussi [ROADMAP.md](ROADMAP.md) pour ce qui est envisagé.

### Alternative au cron GLPI

Le CronTask GLPI (**Administration > Actions automatiques** > `assetsignReminders` / `assetsignExpire` / `assetsignExpiryWarning`) est actif par défaut et suffit dans la plupart des cas.

Si vous préférez piloter ces actions depuis un ordonnanceur externe (cron système, tâche planifiée...) plutôt que de dépendre du cycle interne de GLPI, trois commandes console existent :

```bash
php bin/console plugins:assetsign:run-reminders
php bin/console plugins:assetsign:run-expiration
php bin/console plugins:assetsign:warn-expiring
```

**Si vous utilisez ces commandes depuis un ordonnanceur externe, désactivez les CronTask GLPI correspondants** pour éviter un double envoi des relances — les deux mécanismes appellent exactement la même logique, ils ne sont pas complémentaires.

### Tableau de bord

Quatre cartes natives (widgets « grand nombre ») apparaissent dans le groupe **« AssetSign »** de l'éditeur de tableau de bord GLPI (menu Tableau de bord > Modifier > Ajouter une carte) :

- Assetsigns en attente de signature
- Assetsigns signés
- Assetsigns expirés
- Échecs de création (30 derniers jours) — un échec de création automatique (rare : génération PDF, envoi du jeton...) n'interrompt jamais la sauvegarde du matériel qui l'a déclenché, mais reste sinon invisible sans consulter le fichier de log du plugin ; cette carte le rend visible d'un coup d'œil.

Chaque carte renvoie vers la liste filtrée correspondante en un clic, et respecte l'entité active sélectionnée.

### FAQ

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

## 🇬🇧 English

**User guide**

This guide assumes the plugin is already installed and configured — see [INSTALLATION.md](INSTALLATION.md) otherwise.

### How it works, from each person's point of view

**The administrator** configures it once and for all (Configuration > AssetSign): which equipment types are covered, when a signature is triggered (assignment, reassignment, return, or status change), reminder delays, and the logo and charter to display on the PDFs.

**The technician** has nothing special to do: they keep assigning equipment in GLPI as usual. The plugin takes care of the rest. They can check the handover history at any time (menu Tools > Assetsigns) and manually send a reminder to a recipient who hasn't signed yet.

**The recipient** gets an e-mail, clicks the link, logs in to GLPI if not already, reviews the document and signs on screen. Once signed, they find the signed PDF in the Documents tab of their profile.

### Screenshots

**Settings, organized by tab, with a live PDF preview** (even before saving) — see [INSTALLATION.md](INSTALLATION.md#3-configurer) for the setting details:

![Page de configuration avec onglets et aperçu en direct](docs/screenshots/config.png)

**The generated PDF**, identical to what the live preview already showed before signing:

![PDF de remise généré](docs/screenshots/pdf-genere.png)

**An internal maintenance record**, with an input type specific to each checklist item (checkbox, free text, dropdown):

![Formulaire de fiche de maintenance avec types de saisie variés](docs/screenshots/maintenance.png)

#### Dropdowns (Configuration > Dropdowns)

Three dropdown lists configurable by the administrator appear like any other native GLPI dropdown (Configuration > Dropdowns), with no separate screen to learn:

**Handover accessories** — the catalog offered when adding an accessory to a record (charger, bag, mouse...):

![Liste des accessoires de remise dans Intitulés](docs/screenshots/intitules-accessoires.png)

**Handover templates** — one template per record type (Handover/Return/Donation/Sale), with its own terms-and-conditions text and default status. Each type's default template is edited directly from the matching tab of Configuration > AssetSign (see below) — this list stays useful for managing several templates per type/entity beyond just the default one:

![Liste des gabarits de remise dans Intitulés](docs/screenshots/intitules-gabarits.png)

**Maintenance checklist items** — each item defines its own input type (checkbox, free text, or dropdown with its own options), configured directly from this list:

![Liste des points de contrôle de maintenance dans Intitulés](docs/screenshots/intitules-checklist-maintenance.png)

#### Cross-asset lists (Tools > Assetsigns / Maintenance records)

**Tools > Assetsigns ("Record management")**: every handover/return/donation/sale across the whole fleet, regardless of the equipment or recipient, with direct download of the PDFs (unsigned and signed) and bulk cancellation. This list acts as a central dashboard for the technician, who doesn't need to reopen each equipment record one by one:

![Liste transverse de toutes les remises](docs/screenshots/liste-assetsigns.png)

**Tools > Maintenance records**: the same logic, but for internal maintenance records (no recipient, with a PDF always downloadable and an optional technician signature, see below) — a second entry point to the same list as the Maintenance tab of a given piece of equipment:

![Liste transverse de toutes les fiches de maintenance](docs/screenshots/liste-maintenance.png)

#### The "Assetsigns" tab on an equipment record

Every piece of equipment managed by the plugin (computer, monitor, peripheral, phone, or a custom asset) gains an **Assetsigns** tab in its side menu, next to GLPI's native tabs. It lists the history of handovers already done on that equipment — with, for each row, a direct PDF download link (unsigned and/or signed depending on progress) with no need to open the record — and offers a manual creation form for a Donation or a Sale — with a choice between an internal recipient (an existing GLPI account, via the dropdown) or an external one (free-text name and contact, for a person or association with no GLPI account):

![Onglet Assetsigns d'un ordinateur avec le formulaire de création Don/Vente](docs/screenshots/onglet-ordinateur-assetsign-creation.png)

Once a handover is signed, the same record displays its status, its dates, and the actual recipient:

![Onglet Assetsigns d'un ordinateur avec une remise déjà signée](docs/screenshots/onglet-ordinateur-assetsign-signee.png)

#### The "Maintenance" tab on an equipment record

A second dedicated tab, independent from signed handovers (no recipient, no token, no e-mail), with the new maintenance record form directly accessible — each checklist item shows its own input type (checkbox, text field, dropdown) as defined in Dropdowns. Once created, the record always generates a downloadable PDF (same layout as handover/donation/sale records, with the entity's logo); if the administrator has enabled the technician signature (Configuration > AssetSign > Maintenance), a signature pad appears on that same form and must be filled in before the record can be created — no token or e-mail, since the technician is already logged in:

![Onglet Maintenance d'un ordinateur avec le formulaire de checklist](docs/screenshots/onglet-ordinateur-maintenance.png)

#### The "Asset Passport" tab on an equipment record

A third, read-only tab that answers "who has used this equipment since it was purchased?". At the top, a summary card (model, manufacturer, serial number, status, current user/entity, purchase, warranty end) gives an immediate overview without having to scroll through the whole timeline — each piece of information only appears if it's actually filled in. Next comes a timeline automatically aggregating this equipment's handovers, returns, donations, sales and maintenance records (nothing to re-enter, these events come from the tabs above), with a "lives" counter (number of successive recipients and their periods). Every event keeps the recipient's name as it was at the time it happened, even if the matching GLPI account is deleted later — that name can be anonymized after a configurable delay (Configuration > AssetSign > Asset Passport), leaving only the date and event type visible. The feature itself can be disabled per entity, and the event types shown in the timeline are filterable, from that same configuration tab.

For equipment that already existed before this feature was installed, the timeline doesn't stay empty: on first viewing, the plugin automatically finds everything that happened before in GLPI's native history (the same User/Status changes already used to trigger automatic handovers). A **"Force search in history"** button lets you re-run that search at any time (for example after changing the triggering statuses), without ever creating a duplicate.

The timeline also automatically fills in the dates known from the equipment's **Financial and administrative information** tab (purchase, order, delivery, start of use, warranty, decommission, purchase price) — answering "what happened even before the first assignment?". Nothing is duplicated: only dates that are actually filled in appear (equipment with no financial/administrative information simply shows nothing extra). Can be disabled per entity (Configuration > AssetSign > Asset Passport).

The decommission date can also be **filled in automatically** rather than entered by hand: under Configuration > AssetSign > the **Decommission** tab, choose which equipment statuses should trigger writing that date (the same mechanism as the existing Attribution/Return/Donation/Sale triggers). No record is created either way — only GLPI's native "Decommission date" field (Financial info tab) gets written with today's date, exactly what the timeline above already shows read-only. A date already filled in (manual entry or a previous trigger) is never overwritten.

The equipment's **linked tickets** also appear in the timeline, read-only (nothing is copied or editable from this tab) — each technician only sees the tickets they actually have access to, exactly as on the equipment's native Tickets tab. Can be disabled per entity, in the same configuration tab.

The summary card also shows **time indicators**: the equipment's age (since its purchase if the date is known in the financial info, otherwise since it entered GLPI — the label always specifies which of the two), the time actually in use (sum of the periods it was assigned to someone, as a percentage of its age) and the time spent in stock. The length of each "life" (period assigned to the same person) is also shown directly in the list.

![Fiche d'identité du Passeport matériel avec indicateurs temporels](docs/screenshots/passeport-fiche-identite.png)

A **health score** (0 to 100, 100 = ideal condition) is computed from four factors: age, the number of linked tickets (incidents), physical condition (damage markers recorded during condition reports) and the number of holder changes. The calculation breakdown (degradation percentage per factor) is shown under the score:

![Score de santé avec détail du calcul par facteur](docs/screenshots/passeport-score-sante.png)

How much each factor matters is set in Configuration > AssetSign > Asset Passport: the weights are **relative** (no need to make them add up to exactly 100), and setting a weight to 0 simply disables that factor. The score itself can also be disabled entirely.

![Réglages des poids du score de santé dans la configuration](docs/screenshots/passeport-reglages-poids.png)

A **"Print a QR code label"** button (can be disabled per entity, Configuration > AssetSign > Extras — a setting separate from the PDF QR code) opens a dedicated, print-friendly page with the equipment's name/serial number, the QR code, and a "Print" button. Once the label is stuck onto the equipment, scanning it with a phone opens this same Asset Passport tab directly in GLPI (the usual GLPI login is required if needed, like any GLPI link — no anonymous access).

#### The "Assetsigns" tab on a user's record

The same Assetsigns tab also exists on the user side (a GLPI account's record, Administration > Users) — filtered this time by recipient rather than by equipment, with an extra column showing which equipment each row corresponds to (the same person may have received several different pieces of equipment over time): handy for seeing at a glance everything a person has received, and downloading each PDF directly, with no need to know in advance which equipment to look under. A **"Assign equipment to this user"** shortcut also lets you assign equipment directly from this screen (choose the type, then the equipment) — the assignment automatically triggers the creation of a handover, exactly like changing the "User" field from the equipment record:

![Onglet Assetsigns sur la fiche d'un utilisateur](docs/screenshots/onglet-utilisateur-assetsign.png)

#### The "User Passport" tab on a user's record

A view symmetrical to the Asset Passport: a timeline of everything a person has received, returned, donated or bought, from when they joined the company to their deactivation/deletion. Each row shows the name and serial number of the equipment involved (with an explicit fallback if either is missing, e.g. equipment deleted since) and links back to the original record. As with the Asset Passport, history predating the installation of this feature is found automatically (the same "Force search in history" button is available here, across all the equipment this person has ever had).

### Features

- Automatic detection of equipment assignment/reassignment/return, with no manual action from the technician.
- PDF generation (handover or return record) with customizable templates (terms and conditions, IT charter) — a new template starts from an editable default text rather than a blank field. Variables available in that free text (`{beneficiaire}`, `{technicien}`, `{materiel}`, `{date}`, `{entite}`), automatically replaced on the PDF: one template written once rather than a variant per case.
- Built-in on-screen signature (no external service required, no license cost) — mouse, finger or stylus, with a PDF preview before signing.
- Automatic reminders in case of inaction, with a configurable maximum count, then automatic link expiration after a certain delay.
- Manual reminder at any time from a handover's record, or as a bulk action on several selected handovers from the list (Tools > Assetsigns).
- Alert to the technician a few days before the link actually expires (configurable per entity, can be disabled) — to allow following up with the recipient another way (call, in-person visit) before it's too late, rather than finding out about the inaction once the link has already expired.
- Automatic attachment of the signed PDF to the equipment **and** the user (GLPI's native Documents tab).
- Signature proof viewable directly on the record of every signed handover (menu Tools > Assetsigns): signer, IP address, browser, document SHA-256 fingerprint — useful in case of a dispute, with no need to reopen the PDF to find this information.
- Full, timestamped history of every handover, with signature proof (IP address, date/time, document fingerprint).
- Accessories handed over (charger, bag, additional monitor...): configurable catalog, attachable to each handover with a quantity and a comment.
- Equipment brand and model automatically shown on the PDF when the information exists in GLPI.
- Company logo customizable by simply uploading a file from the administrator's machine — live preview of the final rendering even before saving. Useful for a GLPI instance shared between several companies/brands: each entity can have its own logo, and an entity (e.g. the root, or a subsidiary) can check "Force this logo on all child entities" to force the same logo across its whole descendance, including the sub-entities of its child entities — even if those have already uploaded their own logo.
- Link to the full IT charter, configurable per entity (useful if several companies/sites host their charter in different places), in addition to a charter text specific to each template.
- Works with **custom assets** created in GLPI (Configuration > Custom assets), in addition to the standard types (computers, monitors, peripherals, phones) — no plugin modification needed.
- Independent configuration per entity, with automatic inheritance (an entity with no setting of its own inherits from its closest parent entity).
- Interface available in French, English, Spanish, German and Italian (automatically detected from the recipient's GLPI account language for e-mails).
- Native GLPI dashboard: see the [Dashboard](#dashboard) section below.
- Dedicated **Tools > Assetsigns ("Record management")** menu: a cross-asset view of every handover/return (all equipment and recipients combined), with direct PDF download (unsigned and signed) and cancellation of one or several pending requests (individually or as a bulk action).
- Terms and conditions and IT charter can be enabled **independently** on each template (two checkboxes): a template can, for example, show only the charter, or neither section, with no need to empty out the text.
- A "Back to GLPI home" button is offered to the recipient once their signature is recorded (and on the error screen for an invalid/expired link), so they're never left on a dead-end page.
- E-mail subject and body automatically adapted to the record type (handover or return) via the `##remise.type##` tag — a single set of notifications for every type, instead of fixed text that always talked about a "handover" even for a return.
- Free, optional "Observations" field (disabled by default, can be enabled per entity): lets you note the equipment's observed condition, carried over to the PDF as long as the record isn't signed.
- **Equipment donation** and **equipment sale**: two additional workflows, can be disabled per entity, triggerable either manually from an equipment's Handover tab (dedicated "Create a donation record"/"Create a sale record" buttons), or **automatically by a status change** (like Handover/Return), from statuses the administrator chooses themselves. A Sale adds a price and a sale date, carried over to the PDF as soon as they're filled in — when the Sale is triggered automatically (price unknown at that point), the record simply doesn't reference it yet, and the price/date remain editable afterwards from the record.
- **Internal or external beneficiary** (Donation/Sale only): manual creation offers a choice — an existing GLPI account (the usual signed workflow, e-mail + on-screen signature), or a person/organization **outside the company** (free-text name and contact, no electronic signature required since a third party with no GLPI account can't log in to sign — the generated PDF then serves directly as proof). If a status change automatically triggers a donation/sale on equipment **with no assigned user** (no known internal recipient), the plugin can't create anything on its own but shows a message inviting you to create the record manually with the right recipient.
- **Visual condition report** (can be disabled per entity): 3 reference views (back, front, underside) always shown on the record as soon as the setting is active — a technician (from the admin record) **or the recipient themselves (from their signature page, before signing)** can click on them to drop a marker, with an optional description and severity, carried over to the PDF.
- **Free recipient remark**: an optional text field on the signature page, filled in by the recipient themselves before signing (e.g. reporting an issue noticed on receipt) — carried over to the PDF under "Recipient remark", and visible read-only on the admin record.
- **Maintenance/preparation records**: an internal form (no recipient, no token, no e-mail) with a checklist of items fully configurable by the administrator (Configuration > Dropdowns) and a free comment — accessible from every equipment's Maintenance tab **and** directly from the record list (Tools > Maintenance records > New record, with equipment selection). Each checklist item defines its own **input type** (checkbox, free text, or dropdown with options defined by the administrator) — not just checkboxes. **Visual condition report also available on these records** (same setting as above): markers are dropped at creation time (a maintenance record is a frozen snapshot, never edited afterwards — unlike a handover, no later editing window is offered), then simply shown read-only on the record once created.
- **Maintenance record PDF, always generated** (Configuration > AssetSign > Maintenance): same layout as handover/donation/sale records (entity logo, identical style), downloadable from the record, the Maintenance tab, or the cross-asset list. **Optional technician signature** (checkbox, disabled by default): if enabled, a signature pad identical to a sale's appears on the creation form and becomes mandatory — but unlike a handover's recipient, the technician signs **directly on that same form, in a single request** (no token, no e-mail, no separate page, since they're already logged in). The signature proof (signer, IP address, PDF SHA-256 fingerprint, timestamp) is recorded and viewable on the record, just like a signed handover.
- **Real-time PDF preview** on the configuration and template pages: checking/unchecking a box or editing a text updates the displayed preview, even before saving — rendering strictly identical to the real PDF (same Twig templates, same data).
- **Settings organized by tab** (Configuration > AssetSign > Configuration): one tab per record type (General, Handover, Return, Donation, Sale, Extras, Maintenance), each with its own settings, its preview, and the editing of that type's default template built directly into the tab — a single form, a single save despite the tabbed navigation.
- **Company name and PDF protection** (General tab): an optional company name shown next to the logo on PDF records, and a "Protect the PDF" checkbox that encrypts the generated document to prevent copying/editing in a reader that respects this restriction (viewing and printing always remain possible).
- **Optional QR code on PDF records** (can be disabled, Extras tab): links directly to the matching record in GLPI, handy for finding it during a physical check of the equipment.
- **Printable QR code label on equipment** (can be disabled per entity, Extras tab — a setting separate from the one above): a button on the Asset Passport tab opens a dedicated page to print and stick onto the equipment; scanning it links directly back to that same Asset Passport tab.
- **Customizable currency symbol** (Sale tab): shown after the price on the PDF (`€` by default, changeable to `$`, `CHF`...) — useful for any organization outside the euro zone.
- **"Send a test e-mail" button** (General tab): checks that e-mail sending works with the current configuration, without waiting for a real record to sign.
- **Version tracking** (at the top of the General tab): compares the installed version to the latest one published on GitHub, to immediately spot an environment still on an old version.
- **Configurable watermark on the live preview** (text + opacity, Extras tab): visually distinguishes an unsaved preview from a real PDF — never appears on the final document.

### What is **not** implemented yet

- **External signature provider** for a stronger electronic signature level (eIDAS "advanced"/"qualified"). Only the built-in on-screen signature is available today — it corresponds to a "simple" electronic signature level, not a cryptographic signature.
- Automated test coverage: deliberately partial today, to be extended as the plugin evolves (see [ARCHITECTURE.md](ARCHITECTURE.md#tests-automatisés)).

See also [ROADMAP.md](ROADMAP.md) for what's under consideration.

### Alternative to the GLPI cron

The GLPI CronTask (**Administration > Automatic actions** > `assetsignReminders` / `assetsignExpire` / `assetsignExpiryWarning`) is active by default and is enough in most cases.

If you'd rather drive these actions from an external scheduler (system cron, scheduled task...) instead of depending on GLPI's internal cycle, three console commands exist:

```bash
php bin/console plugins:assetsign:run-reminders
php bin/console plugins:assetsign:run-expiration
php bin/console plugins:assetsign:warn-expiring
```

**If you use these commands from an external scheduler, disable the matching GLPI CronTasks** to avoid sending reminders twice — the two mechanisms call exactly the same logic, they are not complementary.

### Dashboard

Four native cards ("big number" widgets) appear in the **"AssetSign"** group of the GLPI dashboard editor (menu Dashboard > Edit > Add a card):

- Assetsigns awaiting signature
- Assetsigns signed
- Assetsigns expired
- Creation failures (last 30 days) — an automatic creation failure (rare: PDF generation, token sending...) never interrupts saving the equipment that triggered it, but otherwise stays invisible without checking the plugin's log file; this card makes it visible at a glance.

Each card links to the matching filtered list in one click, and respects the currently selected active entity.

### FAQ

**Is a GLPI account required to sign?**
Yes, for an internal recipient (the usual Handover/Return/Donation/Sale workflow). For Donation and Sale only, an external recipient (free-text name/contact, no GLPI account) can be chosen during manual creation — in that case, no electronic signature is requested: the generated PDF serves directly as proof.

**What happens if the recipient never signs?**
The plugin sends automatic reminders up to the configured limit, then marks the request as expired once the link's validity period is exceeded. The technician can also send a manual reminder at any time before expiration.

**Can the signature link be forwarded or reused by someone else?**
No. The signature page checks that the logged-in user is actually the real recipient of that specific handover — another authenticated user who obtained the link by mistake is denied access. The token is also single-use and deactivates after a number of invalid attempts.

**Does a user's name change (marriage, divorce...) trigger a new signature request?**
No. Only changes to the equipment itself (assignment, status) trigger a handover — editing a user's record has no effect on handovers already signed or in progress.

**If I edit an equipment's record without touching the User field or the status, does a handover trigger by mistake?**
No. The plugin only reacts to actual changes on the fields it watches (User, status depending on configuration) — editing another field (name, location...) has no effect.

**How do I know if silent creation failures have occurred?**
The "Creation failures (last 30 days)" dashboard card makes them visible without having to check the plugin's logs by hand.

**Can several companies/brands with different logos and charters be managed?**
Yes, via the independent per-entity configuration with inheritance. See the "Force this logo on all child entities" checkbox in [INSTALLATION.md](INSTALLATION.md#3-configurer) to force the same logo across a whole descendance of entities.

**Does the plugin work with my custom assets?**
Yes, automatically as soon as they're active in GLPI (Configuration > Custom assets) — no plugin modification is needed, they appear in the list of managed equipment types.
