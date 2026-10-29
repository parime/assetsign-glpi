# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.17.1] - 2026-10-29

### Fixed

- Archive publiée : les fichiers de développement `.yamllint.yml` et `phpstan.glpi12.neon` n'y
  figurent plus (présents depuis la 2.16.0, sans effet sur le fonctionnement). Contrôle commun de
  l'archive à chaque PR (glpi-plugin-security v1.4.0).

## [2.17.0] - 2026-10-29

- Publiée dans le catalogue de la marketplace GLPI (`assetsign.xml`).

### Added

- **Liaison avec le plugin Carbon** (pluginsGLPI/carbon, 1.3 sur GLPI 11, 1.4 sur GLPI 12)
  quand il est actif (#228). Aucune dépendance : assetsign lit ses tables en lecture seule.
  - Passeport matériel : bloc « Plugin Carbon » avec l'empreinte de fabrication (scope 3),
    l'impact d'usage (scope 2) et l'énergie et les émissions des 12 derniers mois.
  - Empreinte de fabrication : la saisie manuelle reste prioritaire, celle de Carbon complète
    les matériels sans saisie. Elle alimente le bénéfice du réemploi (impact évité, avec sa
    source) et le tableau de bord RSE (nombre de matériels couverts par Carbon).
  - Score de santé : nouveau facteur « Consommation d'énergie » (moyenne journalière sur
    12 mois, entièrement dégradé à partir de 1 kWh/jour). Poids 0 par défaut : les scores
    existants ne changent pas tant qu'il n'est pas réglé.

### Changed

- Contenu par défaut des e-mails (signature, relances, délégation, cosignature, prêt,
  attestations, charte, départs) dans des gabarits Twig (`templates/notifications/`) au lieu
  de HTML écrit en PHP (#236, recommandation de la revue de la marketplace) : 262 chaînes HTML
  en moins. Le contenu semé à l'installation est identique à l'octet près.
- Plus aucun HTML écrit en PHP (#236) : contenus des tickets, tâches et suivis générés (arrivée,
  départ, écart d'attestation, charte non signée, prêt en retard, signalement d'un problème),
  textes par défaut des fiches, champs des points de contrôle, encarts de la page d'accueil,
  boutons « Ajouter » des listes, badges et liens des listes passent par des gabarits Twig. Le
  texte est toujours échappé par Twig.

### Fixed

- Bouton des listes Accessoires, Kits et Points de contrôle : « Ajouter » restait en français
  dans les autres langues.
- Pied de page des e-mails par défaut (« Généré automatiquement par GLPI ») dans la langue de
  l'e-mail, et non plus toujours en français. Vaut pour les nouvelles installations : un modèle
  déjà créé se modifie dans Configuration > Notifications.
- CI : les tests de la liaison avec le plugin Fields (champs personnalisés dans le PDF)
  s'ignoraient faute du plugin et ne tournaient nulle part. La CI installe Fields 1.24.6
  (archive officielle vérifiée par empreinte) et refuse désormais tout test ignoré ; le test
  qui supposait une interface en français est corrigé.

## [2.16.0] - 2026-10-27

- Publiée dans le catalogue de la marketplace GLPI (`assetsign.xml`).

### Changed

- **Menu Outils en trois rubriques** au lieu de sept entrées, comme le plugin GRC Manager :
  **Attributions** (attributions, mouvements, départs, campagnes d'attestation, plus les gabarits,
  accessoires et points de contrôle), **Fiches de maintenance** (droit dédié, inchangée) et
  **Pilotage du parc** (tableau de bord RSE, renouvellement du parc). Les pages d'une rubrique
  partagent une barre d'onglets et le fil d'Ariane « Rubrique > Page » ; aucune page n'est retirée.

## [2.15.0] - 2026-10-27

- Publiée dans le catalogue de la marketplace GLPI (`assetsign.xml`).

### Added

- **Onglet « AssetSign » dans les profils** (Administration > Profils) : matrice des quatre droits du
  plugin (remises, fiches de maintenance, gabarits, configuration). Ils n'étaient jusqu'ici réglables
  qu'en base, faute d'onglet. Visible avec le droit « Profils » du cœur et l'accès au plugin, profils
  en interface standard uniquement. Contrôle commun « profile-right » (glpi-plugin-security v1.1.0).
- **Version de la charte depuis GRC Manager** (grcmanager #283) : quand le plugin GRC Manager est
  actif, la version approuvée la plus récente de sa politique « Charte informatique » figure en
  tête de la charte signée dans les campagnes. Une nouvelle version approuvée change l'empreinte de
  la charte : les anciens signataires sont à faire re-signer (#224). Lecture seule de la table de
  GRC Manager, sans dépendance : rien ne change sans lui.

### Fixed

- **Désinstallation complète** : les liens de documents vers les objets du plugin, ses préférences
  d'affichage, notes, recherches enregistrées et lignes d'historique sont retirés des tables du
  cœur. Les documents eux-mêmes (attestations signées) restent liés au matériel et aux utilisateurs.

### Security

- Contrôles de sécurité communs aux plugins GLPI branchés sur la CI
  ([parime/glpi-plugin-security](https://github.com/parime/glpi-plugin-security)) : règles Semgrep,
  contenu des onglets sans droit, cibles de notification, restes après désinstallation.

## [2.14.0] - 2026-10-25

- Publiée dans le catalogue de la marketplace GLPI (`assetsign.xml`).

### Added

- **Préparation de poste (masterisation)** (issue #223) :
  - onglet Maintenance du matériel : choix « Maintenance » ou **« Préparation de poste »**. La
    préparation a ses propres points de contrôle, dont 5 proposés à l'installation et modifiables :
    BIOS, système et applications, antivirus, étiquette d'inventaire, nettoyage ;
  - **points obligatoires** (nouvelle option des points de contrôle, utilisable aussi pour la
    maintenance) : la fiche ne peut pas être validée tant qu'un point marqué * manque. Le navigateur
    le signale, et le serveur refuse aussi la fiche ;
  - **photos du matériel prêt** : sur smartphone, le champ ouvre directement l'appareil photo. Les
    photos (JPEG, PNG, WebP) sont jointes au matériel, à la fiche et au ticket ;
  - **ticket du nouvel arrivant** : un nouvel onglet « Préparation de poste » sur le ticket mène au
    matériel lié, avec la préparation et le ticket présélectionnés. À la validation, le PDF et les
    photos sont joints au ticket, avec un suivi privé. Le ticket n'est jamais fermé ni modifié ;
  - liste des fiches et recherche : colonnes « Nature » et « Ticket ».
- **Renouvellement du parc** (issue #222), menu Outils > Renouvellement du parc : les matériels dont
  l'amortissement se termine dans les 3, 6, 12 ou 24 prochains mois (fins déjà passées comprises),
  d'après la fiche financière GLPI (mise en service, ou à défaut achat, et durée d'amortissement, ou
  à défaut la durée par défaut de la Configuration). Budget estimé par année d'après la valeur
  d'achat, export CSV, et bouton « Créer le ticket de changement » : un changement groupé qui liste
  les matériels et le budget, chacun lié au changement. Option de Configuration pour le créer
  automatiquement chaque mois. Rien n'est modifié sur les matériels.

- **Versions de la charte informatique** (issue #224) : chaque campagne de charte enregistre
  l'empreinte de la version diffusée (une simple différence d'espaces ne compte pas). Quand le
  texte change, la page des campagnes l'indique (« La charte informatique a changé : N personne(s)
  ont signé une ancienne version ») avec le bouton « Leur faire signer la nouvelle version », qui
  lance une campagne limitée à ces personnes. Option de Configuration pour la lancer
  automatiquement, une seule fois par version. Page « Historique des versions de la charte » pour
  l'auditeur : texte de chaque version, date de première diffusion, campagnes et nombre de
  signataires. Les campagnes existantes reçoivent leur empreinte à la mise à jour.

- **Mode guichet pour la signature sur place** (issue #230) : la page de signature ouverte par « Faire signer sur place » propose un bouton « Plein écran (tablette) ». Après la signature, l'écran se verrouille : il indique à qui rendre l'appareil et seul le bouton « Reprendre la main (technicien) » en sort, vers la fiche. Le bouton Précédent du navigateur ne ramène plus dans la session du technicien, et l'ancien lien vers l'accueil GLPI n'est plus proposé dans ce mode.

- **Avis dans Teams, Slack ou Mattermost** (issue #231), Configuration > onglet Général, section « Avis
  dans un outil de messagerie » : un message est publié dans un canal (webhook entrant) quand une
  fiche est envoyée et à chaque relance, en plus des e-mails. Il ne contient jamais le lien de
  signature personnel, seulement un lien vers « Mes signatures », qui demande la connexion du
  bénéficiaire. L'adresse du webhook, qui vaut mot de passe, est chiffrée en base et jamais
  réaffichée ; seules les adresses https sont acceptées, jamais le serveur lui-même. Réglage par
  entité, avec bouton « Envoyer un message de test ». Un échec d'envoi est journalisé et
  n'empêche ni l'envoi de la fiche ni la relance.

### Changed

- **Écrans en Twig** (issue #236) : cartes « Arrivée » et « Départ » de l'onglet Attributions d'un
  utilisateur, rendues par un gabarit commun, et champs particuliers de la fiche d'un kit (éléments
  du parc et accessoires attendus).
- **Exigences du Marketplace GLPI vérifiées par la CI** : plus de SQL brut hors installation (l'heure de la base pour les jetons passe par le constructeur de requêtes de GLPI), et le HTML écrit en PHP ne peut plus augmenter : les nouveaux écrans s'écrivent en Twig (`.github/scripts/check-marketplace-readiness.php`, référence dans `.github/twig-baseline.txt`).

## [2.13.0] - 2026-10-24

- Publiée dans le catalogue de la marketplace GLPI (`assetsign.xml`).

### Added

- **Champs du plugin Fields sur la fiche PDF** (issue #229) : dans Configuration > Compléments, l'administrateur coche les blocs du plugin Fields (pluginsGLPI/fields) à imprimer ; leurs champs renseignés s'ajoutent au tableau du matériel, et `{champ:nom_du_champ}` les insère dans le texte du gabarit. Aucun bloc par défaut : la fiche est remise au bénéficiaire et un bloc peut porter des informations internes. Sans le plugin Fields, rien ne change.

- **Arrivée d'un salarié** (issue #221), le pendant du départ :
  - bouton « Préparer l'arrivée » dans l'onglet « Attributions » de la fiche utilisateur : un
    ticket « Arrivée de … » pour l'équipe, avec une tâche par étape (poste, licences, accès,
    remise du matériel). La liste se modifie par entité (Configuration, onglet Attribution) ;
  - la personne est demandeuse du ticket, sans notification : son matériel se remet depuis
    l'onglet « Remise de matériel » du ticket, avec la fiche à signer ;
  - GLPI refuse comme demandeur un compte pas encore valide (date de début à venir, aucun profil
    dans l'entité) : une note privée prévient l'équipe, et la tâche planifiée `assetsignArrivals`
    ajoute la personne dès que son compte devient valide ;
  - déclencheurs facultatifs, désactivés par défaut : création du compte (un compte importé dont
    la date de début est passée depuis plus de 30 jours est ignoré), réactivation du compte,
    approche de la date de début (tâche planifiée). Pas de connecteur propre à un logiciel RH :
    le SIRH alimente l'annuaire, synchronisé avec les comptes GLPI ;
  - un seul dossier en cours par personne, tant que son ticket n'est ni résolu ni clos.

## [2.12.0] - 2026-10-24

- Publiée dans le catalogue de la marketplace GLPI (`assetsign.xml`).

### Added

- **Signature de la charte informatique** (issue #204), preuve de sensibilisation pour ISO 27001
  A.5.10 et A.6.3 :
  - nouveau type de campagne « Signature de la charte informatique » (Outils > Campagnes) :
    chaque personne du périmètre, matériel ou pas, signe la charte en vigueur (charte du gabarit
    d'attribution par défaut et lien vers la charte complète), figée au lancement ; PDF signé
    dans sa langue, relances habituelles, page « Mes documents à signer » ;
  - à la date limite, un ticket liste les personnes qui ne l'ont pas signée (aucun compte n'est
    bloqué) ;
  - renouvellement automatique par entité (Configuration : période en mois, délai pour signer),
    par la tâche planifiée `assetsignCampaigns`.

- **Une seule campagne de charte en cours par entité** : lancer une nouvelle campagne de charte
  alors qu'une autre est encore en cours (ouverte et non échue) sur la même entité est refusé, avec
  son nom. Sinon chacun devait signer deux fois et le taux de signature ne suivait que la plus
  récente. Une campagne échue reste ouverte aux signatures tardives sans bloquer la suivante.

### Changed

- **Audit de code (issue #205)** : PHPStan passe au niveau 6 (paramètres et retours typés,
  génériques précisés) ; seule la forme des valeurs de tableaux reste à documenter. Condition
  toujours vraie retirée de la détection des réaffectations. Revue sans autre constat : aucun code
  mort, chaque exception PHPStan/Semgrep encore justifiée, `global` explicite et aucune
  redirection construite depuis la requête dans les pages, dépendances à jour et auditées en CI.

- **Tests** : préconditions explicites. La création automatique des informations financières
  (activée par l'assistant de Configuration-glpi-auto) est désactivée en mémoire le temps de chaque
  test, et le test du bandeau d'accueil ne vérifie plus que le bandeau des signatures. 32 tests
  échouaient sur une instance configurée par l'assistant, sans défaut du plugin.

## [2.11.0] - 2026-10-22

- Publiée dans le catalogue de la marketplace GLPI (`assetsign.xml`).

### Security

- **Test XSS de bout en bout** : une charge piégée est écrite dans chaque champ texte libre (feuilles,
  éléments, marqueurs, passeport, campagnes, attestations, maintenances, mouvements, gabarits,
  kits, accessoires, points de contrôle, configuration, nom du matériel et du bénéficiaire) ;
  aucune page ni onglet, y compris la page de signature, ne doit la restituer sans échappement.
- **Test de contrôle d'accès étendu à toutes les pages** : la matrice de bout en bout couvre
  désormais les 34 pages du plugin, en lecture comme en écriture. Les écritures refusées sont
  vérifiées en base : aucun objet de l'autre entité ne doit changer. La CI échoue si une nouvelle
  page n'y figure pas, sauf justification explicite (`tests/security/not-covered.txt`).
- Points de contrôle (maintenance, mouvements) : seuls ceux des entités de l'utilisateur sont
  proposés et appliqués. Ceux des autres entités apparaissaient dans ses fiches.
- **Test des téléversements de bout en bout** : un script (`.php`, `.phtml`, `.phar`) ou un faux
  PNG est refusé comme logo ou pièce jointe ; une image polyglotte (GIF valide suivi de PHP) est
  servie comme image, jamais exécutée ; rien ne peut être joint à un mouvement d'une autre entité.

### Added

- **PDF dans la langue du bénéficiaire** (issue #208) : tous les textes fixes des PDF (fiches
  d'attribution, restitution, don, vente, destruction, maintenance, attestation, départ, rapport de
  campagne) sont traduits dans les 5 langues du plugin. Chaque PDF est généré dans la langue du
  compte de la personne concernée (bénéficiaire, personne qui part ou qui atteste, technicien pour
  la maintenance ; langue par défaut de GLPI à défaut), quel que soit le compte qui déclenche la
  génération. Le rapport de campagne suit la langue de la personne qui le télécharge. Le contrat et
  la charte restent ceux saisis dans Configuration.

### Fixed

- **Matériel disponible proposé sur une feuille** (issue #206) : quand des statuts « en stock » sont
  configurés, ils font foi à eux seuls. Un matériel sans utilisateur mais réformé, détruit ou en
  réparation n'est plus proposé comme disponible. Sans statut configuré, le repli reste « sans
  utilisateur ».
- **« Ne pas faire signer »** (issue #207) : un brouillon jamais envoyé part désormais à la corbeille
  GLPI au lieu de rester affiché comme attribution « annulée » dans l'onglet Attributions
  (récupérable, historique et passeport de l'actif conservés). Une feuille déjà envoyée reste
  visible, annulée.
- **Liste des départs et onglet « Passeport utilisateur »** : erreur 500 pour tout profil qui
  n'est pas à la racine des entités (restriction d'entité sur une colonne `is_recursive`
  inexistante). Trouvé par le nouveau test d'accès.
- **Pièce jointe d'un type interdit** (justificatif de don, certificat de destruction, document
  d'un mouvement) : GLPI refusait le fichier, mais un document vide portant son nom restait joint,
  sans message d'erreur. Le type est désormais vérifié avant l'envoi et l'erreur affichée. Trouvé
  par le nouveau test des téléversements.
- Le pluriel « Départs » (titre de la liste des départs) n'était traduit dans aucune langue.

## [2.10.1] - 2026-10-20

### Security

- **Revue de sécurité marketplace, re-vérification sur 2.10.0.** Les
  18 constats du rapport 2.6.0 restaient corrigés, mais trois écrans ajoutés depuis reproduisaient
  la famille « droit global sans contrôle d'entité » :
  - attestations de détention, dossiers de départ et campagnes : consultation, PDF, export CSV,
    relance, clôture, annulation et signature sur place vérifient maintenant l'entité de
    l'enregistrement (`can()`), pas seulement le droit global ;
  - lancement d'une campagne et préparation d'un départ : l'entité visée (formulaire, ou entité
    de la personne) doit être dans le périmètre de la session.
- Export CSV des campagnes : neutralisation des cellules interprétables comme formule (commentaire
  libre saisi par la personne attestée).
- La vérification de la dernière version sur GitHub ne contacte plus api.github.com que si
  l'action automatique native « checkupdate » de GLPI est activée (désactivée par défaut), avec un
  délai total de 10 s.
- CI : règles Semgrep propres au projet (`.semgrep/`), bloquantes, qui détectent ces classes de
  défaut (droit global au lieu de droit + entité, `entities_id` venant de la requête, `|raw`).
- PDF.js réellement mis à jour en 6.4.299 : les PR Dependabot n'avaient modifié que
  `package.json`, les fichiers servis étaient restés en 6.2.108 (même écart que celui relevé sur
  `signature_pad` en 2.6.0). La CI vérifie désormais que chaque fichier vendorisé correspond à la
  version déclarée. Vérifié dans Chromium : PDF rendu, signature dessinée et enregistrée.
- **Le bénéficiaire est informé quand un tiers délègue sa signature** : nouvelle notification
  « Votre signature a été déléguée » (qui a délégué, à qui, pourquoi ; sans lien de signature),
  envoyée uniquement quand la délégation n'est pas faite par le bénéficiaire lui-même. Elle est
  installée automatiquement à la mise à jour et modifiable dans Configuration > Notifications.
  Nouveau tag de gabarit `##assetsign.delegation.by##`.
- CI : test de contrôle d'accès de bout en bout (technicien limité à une entité, puis
  super-admin), contrôle d'autorisation obligatoire sur chaque point d'entrée, et
  `dependency-review` sur les PR.

## [2.10.0] - 2026-10-18

### Added

- **« Mon matériel » dans le portail self-service** (issue #194). La page « Mes documents à
  signer » (menu Plugins de l'interface simplifiée, et bandeau de l'accueil) montre aussi :
  - le matériel affecté à l'utilisateur, avec modèle, n° de série et n° d'inventaire ;
  - les feuilles qu'il a signées pour ce matériel, y compris celle d'un ordinateur remis avec
    l'écran. Le PDF est servi par le plugin après avoir vérifié que l'utilisateur est bien le
    signataire : aucun droit n'est nécessaire sur le matériel ni sur les documents GLPI ;
  - un bouton « Signaler un problème » (panne, casse, perte, vol), qui crée un ticket à son nom,
    lié au matériel. Le matériel n'est jamais modifié : c'est l'équipe qui traite le ticket.
- **Départ : ticket « Récupération du matériel » pour l'équipe** (issue #195). Quand un départ est
  préparé (à la main, à la désactivation du compte ou X jours avant sa date de fin), un ticket est
  ouvert :
  - il liste et lie chaque matériel à récupérer ;
  - il est assigné au technicien qui a préparé le départ ;
  - le matériel ajouté plus tard au départ y est aussi lié.

  Un suivi est ajouté à la signature, à l'annulation, et le jour du départ, avec la liste du
  matériel encore affecté dans GLPI. Le plugin ne change jamais un statut ni une affectation, et
  n'ajoute pas de suivi à un ticket résolu ou clos (GLPI le rouvrirait). Un seul ticket par départ.
  L'écran du départ donne un lien vers le ticket. Option « Ouvrir un ticket “Récupération du
  matériel” » dans la configuration, onglet Départ (active par défaut).
- **Prêt temporaire** (issue #193). Une attribution avec une **date de retour prévue** devient un
  prêt :
  - la date se saisit dans la fenêtre de confirmation, ou sur la feuille (encart « Prêt
    temporaire »), y compris après signature pour prolonger un prêt ;
  - le PDF indique « Retour prévu au plus tard le … » ;
  - X jours avant l'échéance, le bénéficiaire et le technicien reçoivent un e-mail (nouvelle
    notification « Prêt arrivant à échéance », balise `##assetsign.loan_until##`) ;
  - une fois l'échéance passée sans retour, un ticket « Retour de prêt en retard » est ouvert, lié
    au matériel et assigné au technicien (un seul par prêt) ;
  - carte « Prêts en cours » sur la page d'accueil, avec les retards en rouge, et colonne
    « Retour prévu (prêt) » dans la liste des feuilles.

  Un prêt est considéré rendu dès que le matériel n'est plus affecté à l'emprunteur dans GLPI, ou
  qu'une restitution a été signée. Rien n'est désaffecté ni changé de statut automatiquement.
  Réglages dans la configuration : délai du rappel (3 jours par défaut) et ticket de retard (actif
  par défaut). Tâche planifiée quotidienne « assetsignLoans ».

## [2.9.0] - 2026-10-11

### Changed

- **Traduction anglaise** : « Attribution » devient *Handover* (32 libellés). En anglais,
  *attribution* désigne la mention d'un auteur, pas la remise de matériel à une personne.
- **PHP 8.2 minimum** (au lieu de 8.3), comme GLPI 11 et les plugins jumeaux : rien dans le code ni
  dans ses dépendances n'exigeait 8.3. Vérifié par un lint intégral sous PHP 8.2 et par la CI
  officielle GLPI (installation + tests sous PHP 8.2/MySQL 8.0 et PHP 8.5/MariaDB 10.6), ajoutée à
  la CI. `vendor/` régénéré : contrôle de plateforme Composer à 8.2 et carte des classes à jour.
- `masterminds/html5` et `symfony/polyfill-mbstring` ne sont plus embarqués dans `vendor/`
  (déclarés `replace` dans `composer.json`) : le cœur GLPI les fournit déjà et son autoloader passe
  avant celui du plugin, nos copies n'étaient jamais chargées. `sabberworm/php-css-parser` passe en
  9.5.0, qui ne dépend plus de `thecodingmachine/safe` (également fourni par le cœur).

### Fixed

- **Actifs personnalisés mélangés** : dans « Ajouter un élément du parc > Matériel disponible »,
  chaque type d'actif personnalisé (casque, hub…) listait les actifs de tous les autres types, car
  GLPI les range tous dans la même table. Même correction pour la liste du matériel détenu (départ
  d'un salarié, attestation annuelle) et pour les indicateurs RSE, qui comptaient ces actifs
  plusieurs fois.
- **Mise à jour depuis la 2.8.0** : une migration ajoutait des colonnes « après » une colonne créée
  plus loin dans la même mise à jour — l'installation neuve passait, mais la mise à jour d'une
  installation existante échouait (« Unknown column 'stock_states' »). Les nouvelles migrations ne
  dépendent plus de l'ordre des colonnes, et la CI GLPI 12 installe désormais la dernière version
  publiée avant d'appliquer le code par-dessus.
- La personne qui signe ne voit plus le message technique « Document copié avec succès ».
- Les messages techniques de GLPI (« Création du répertoire », « Document copié avec succès »)
  ne s'affichent plus à l'envoi immédiat d'une feuille (attribution ou restitution).
- **GLPI 12** : les pages qui affichent « élément introuvable » ou « accès refusé » (fiche,
  attestation, campagne, départ, maintenance, mouvement, aperçu, étiquette QR, configuration)
  provoquaient une erreur 500 sur GLPI 12, qui a supprimé `Html::displayNotFoundError()` et
  `Html::displayRightError()` ; remplacées par les exceptions HTTP de GLPI (présentes depuis 11.0.0).
  La CI analyse désormais aussi le plugin avec PHPStan contre les sources de GLPI 12.
- Le bouton « Ajouter » des menus Attributions, Fiches de maintenance, Mouvements et Départs menait
  à une page « L'élément demandé est introuvable » : ces fiches naissent d'un matériel ou d'une
  personne. Bouton retiré ; une page ouverte sans numéro de fiche renvoie à la liste en expliquant
  où la créer.
- Attribuer un poste dont GLPI recopie l'utilisateur vers les éléments connectés (option d'entité
  « mise à jour automatique de l'utilisateur ») créait une feuille séparée — et, en envoi immédiat, un
  e-mail — pour chaque écran ou périphérique connecté. Ces éléments sont couverts par la feuille du
  poste, où ils sont proposés comme éléments connectés.
- Les messages techniques de GLPI (« Document copié avec succès », « Fichier … supprimé ») ne
  s'affichent plus quand une modification de la fiche régénère le PDF non signé.
- La désinstallation supprime aussi la table `glpi_plugin_assetsign_residualvalues` (valeurs
  résiduelles saisies à la main), qui restait en base.
- Gabarits Twig : plus d'erreur « Variable … does not exist » quand GLPI est en mode
  développement/débogage (variables strictes) ; valeurs par défaut explicites sur les PDF de remise,
  de maintenance, la page de signature, la fiche et le passeport.
- Bootstrap PHPUnit : l'environnement GLPI est lu depuis `GLPI_ENVIRONMENT_TYPE` (comme
  `bin/console`) au lieu d'être forcé à `production`, pour tourner aussi dans la CI officielle GLPI.

### Added

- **Lien avec les tickets** (issue #183) : onglet « Remise de matériel » sur les tickets — on remet
  un matériel disponible à un demandeur : attribution dans GLPI, matériel lié au ticket, feuille
  préparée en brouillon et rattachée au ticket. Une feuille peut aussi être rattachée à un ticket
  ouvert du bénéficiaire. À la signature : suivi avec le document signé joint au ticket et, si
  réglé (par défaut), ticket résolu ; sinon une note privée explique pourquoi.
- **Kits avec des types d'éléments GLPI** (issue #181) : un kit peut attendre des éléments
  inventoriés par type (ex. « Pack télétravail » = 1 écran + 2 périphériques). Sur la fiche et
  dans la fenêtre d'attribution, chaque ligne indique « pourvu / attendu » et propose le matériel
  disponible de ce type ; le kit se choisit aussi depuis la fenêtre. À la restitution, les objets
  précis sont contrôlés un par un (#178).
- **Passeport de l'élément remis avec** (issue #182) : un écran, un casque… remis avec un poste a
  sa propre trace dans le passeport matériel — « Attribution : remis avec Ordinateur PC-123 » à
  l'envoi, « Restitution : rendu / rendu abîmé / manquant » au contrôle du retour (mis à jour si
  l'état change), lien vers la feuille ; rétro-remplissage depuis les feuilles existantes. Les
  contrôles qualité et le kit du poste ne sont pas affichés sur ces événements.
- **Restitution qui reprend la remise** (issue #178) : la feuille de restitution reprend les éléments
  du parc remis avec le poste (dernière attribution envoyée ou signée, repérée avant son éventuelle
  annulation). Contrôle du retour élément par élément — Rendu / Manquant / Abîmé, commentaire —
  repris sur le PDF ; à l'enregistrement, un élément rendu ou abîmé n'a plus d'utilisateur dans GLPI
  et prend le statut configuré, un élément manquant reste à la personne et peut ouvrir un ticket lié.
- **Matériel disponible proposé en premier** (issue #180) : pour ajouter un élément à une feuille
  (fiche et fenêtre d'attribution), liste « Matériel disponible » regroupée par type — sans
  utilisateur ou dans un statut « en stock » (nouveau réglage), pas déjà sur une autre feuille en
  attente ; la recherche dans tout le parc reste possible.
- **Feuilles restées en brouillon** (issue #179) : encadré « Feuilles à envoyer » sur la page
  d'accueil (ses brouillons et ceux préparés sans technicien, avec envoi en un clic et lien vers la
  liste complète) et rappel par e-mail au technicien après N jours (réglage, 3 par défaut, 0 =
  désactivé), puis chaque semaine tant que la feuille reste en brouillon (tâche planifiée
  `assetsignDraftReminder`, notification « Feuille restée en brouillon »).
- **Matériel remis avec attribué aussi dans GLPI** (issue #174) : un écran, casque, téléphone… ajouté
  à une feuille d'attribution est attribué au bénéficiaire dans GLPI (champ Utilisateur et statut :
  celui du matériel principal, un statut choisi, ou inchangé), à l'envoi de la feuille ou dès l'ajout
  si elle est déjà partie, sans créer de feuille séparée pour l'élément. Réglage par entité, activé par
  défaut.
- **Envoi manuel des feuilles et inventaire automatique** (issue #163) : nouveau mode d'envoi
  « Manuel » (aucune feuille ne part seule : brouillon et bouton « Envoyer pour signature », y compris
  pour les restitutions) à côté de « Confirmation » (défaut) et « Immédiat » ; option « Ignorer les
  changements faits par l'inventaire automatique » pour qu'un agent qui ne remonte plus l'utilisateur
  ne déclenche ni restitution ni nouvelle attribution.
- **Modifier la feuille avant l'envoi** : sur un brouillon, changement du bénéficiaire et du modèle
  de document, **aperçu du PDF** tel qu'il partira, et bouton « Modifier la feuille » dans la fenêtre
  d'attribution.
- **Fenêtre de confirmation à l'attribution** (issue #170) : attribuer un matériel depuis sa fiche
  ne déclenche plus l'envoi immédiat ; la feuille est préparée en brouillon et une fenêtre propose
  les éléments connectés au poste (pré-cochés), l'ajout d'écrans, périphériques, téléphones… ou
  d'accessoires du catalogue, puis « Envoyer pour signature », « Plus tard » (brouillon envoyable
  depuis l'onglet du matériel ou la fiche) ou « Ne pas faire signer ». Réglage par entité, activé
  par défaut ; inventaire automatique, actions massives et tâches planifiées envoient comme avant.
- **Matériel inventorié remis avec le poste** (issue #166) : sur une fiche, ajout d'éléments déjà
  inventoriés dans GLPI (écran, station d'accueil, téléphone, actif personnalisé…) ; le PDF reprend
  type, nom, marque/modèle, n° de série et n° d'inventaire, relus jusqu'à la signature puis figés.
  Les éléments connectés au poste (onglet Connexions de GLPI) sont proposés en un clic, ou ajoutés
  d'office aux fiches créées par une affectation (nouveau réglage « Ajouter d'office le matériel
  connecté au document »). Un élément ne peut figurer que sur une fiche en attente à la fois ; un
  badge signale un élément affecté à une autre personne que le bénéficiaire.

- **Tableau de bord RSE** (issue #142) : page Outils > Tableau de bord RSE et trois cartes pour le
  tableau de bord GLPI natif — empreinte de fabrication du parc et couverture, impact évité par le
  réemploi, âge moyen et durée de vie réelle, filières de fin de vie sur 12 mois et taux de seconde
  vie. Données réelles uniquement ; formule de l'impact évité mutualisée avec le passeport
  (`CsrLogic::avoidedImpact()`).
- **Attestation annuelle de détention du matériel (campagne de signature)** (issue #158) :
  - Outils > Campagnes d'attestation : périmètre (entité, sous-entités, groupe, profil), date limite ;
    une attestation par personne détenant du matériel géré, liste figée au lancement ;
  - réponse depuis GLPI (lien e-mail ou « Mes documents à signer ») : confirmer et signer (PDF signé,
    empreinte SHA-256), ou signaler un écart (manquant / en trop / inconnu + commentaire) qui ouvre un
    ticket au nom de la personne, rattaché au matériel, et prévient l'auteur de la campagne ;
  - relances automatiques jusqu'à la date limite (`assetsignCampaigns`), relance manuelle, clôture ;
  - suivi (taux de réponse, écarts, non-répondants), export CSV et PDF ;
  - notifications en 5 langues, traductions FR/EN/DE/ES/IT, tests.
- **Départ d'un salarié : restitution groupée de tout son matériel** (issue #157) — « dossier groupé » :
  - action « Préparer le départ » dans l'onglet Assetsign du compte utilisateur : une fiche de
    restitution ordinaire par matériel affecté (preuve, PDF, passeport, état du matériel inchangés),
    regroupées dans un départ (`glpi_plugin_assetsign_departures`) ; une seule invitation, une seule
    signature (page du départ, ou sur place sur l'écran du technicien), un PDF récapitulatif ;
  - les fiches d'un départ n'envoient ni lien ni e-mail ni relance individuels ; relances au niveau du
    départ (même calendrier, jamais deux relances rapprochées) ; une désaffectation pendant le départ ne
    crée pas de restitution individuelle en plus ;
  - pas de doublon : un seul départ ouvert par personne, restitution déjà en attente rattachée, matériel
    déjà rendu non redemandé ;
  - déclencheurs automatiques facultatifs, désactivés par défaut (onglet Restitution) : désactivation du
    compte, date de fin du compte à J-X (action automatique quotidienne `assetsignDepartures`) ;
  - « Mes documents à signer » : un départ = une ligne ; suivi dans Outils > Départs ;
  - notifications « Départ : restitution du matériel à signer / relance / restitution signée » en 5 langues.

---

## [2.8.0] - 2026-10-04

### Added

- **Signature sur place, en présence d'un technicien** (issue #152) : pour un bénéficiaire qui
  ne peut pas se connecter à GLPI (pas de mot de passe, pas de poste) — la page de signature
  exige une session GLPI, un simple lien ou QR code ne l'aurait donc pas aidé. Sur la fiche, le
  bouton « Faire signer sur place » ouvre la page de signature habituelle sur l'écran du
  technicien ; le bénéficiaire (ou son délégué) relit et signe :
  - le jeton émis est lié au technicien (`witness_users_id`) : lui seul peut l'ouvrir, valable
    24 h, sans invalider le lien envoyé par e-mail ; refusé dès que le réglage est désactivé ou
    que la fiche n'attend plus la signature du bénéficiaire ;
  - le PDF signé et la preuve (`glpi_plugin_assetsign_signatures.witness_name`) mentionnent
    « Signature recueillie sur place, en présence de » ce technicien, visible aussi dans la
    preuve affichée sur la fiche ;
  - pas d'auto-délégation depuis une page ouverte sur place ; contre-signature du responsable
    hors périmètre (il se connecte lui-même) ;
  - réglage par entité « Autoriser la signature sur place », **désactivé par défaut** (change le
    modèle de sécurité : identité attestée par le technicien plutôt que prouvée par la connexion
    du signataire).

- **Documents à signer sans adresse e-mail** (issue #150) : jusqu'ici, un utilisateur n'apprenait
  qu'un document attendait sa signature que par l'e-mail contenant le lien — un utilisateur sans
  adresse e-mail n'en était jamais informé. Désormais :
  - un bandeau « Vous avez N documents à signer » s'affiche sur la page d'accueil de GLPI,
    interface standard comme simplifiée (`Hooks::DISPLAY_CENTRAL`), uniquement quand un document
    attend l'utilisateur connecté ;
  - une page **Mes documents à signer** (`front/mysignatures.php`) les liste, avec un bouton
    « Signer » qui ouvre la page de signature habituelle ; aussi accessible depuis le menu
    Plugins de l'interface simplifiée ;
  - qui voit quoi : le bénéficiaire, ou son délégué si la signature a été déléguée, et le
    responsable à l'étape de contre-signature — mêmes règles que la page de signature, qui reste
    le contrôle final ;
  - le lien de l'e-mail ne pouvant pas être réaffiché (seul son hash est stocké), le bouton
    « Signer » émet un nouveau jeton, valable jusqu'à l'échéance de la fiche (pas un nouveau délai
    complet) ; le lien de l'e-mail, s'il existe, reste valable ;
  - réglage par entité « Signaler les documents à signer sur la page d'accueil de GLPI »
    (onglet Général), **actif par défaut** ;
  - traductions dans les 5 langues, y compris 3 chaînes de la contre-signature (#143) qui n'avaient
    jamais été traduites.

  Vérifié de bout en bout sur GLPI 11.0.9 et 12.0.0-rc2 avec un vrai compte Self-Service sans
  e-mail : bandeau à la connexion → page → « Signer » → signature soumise → fiche « Signée » →
  bandeau disparu ; bandeau aussi présent sur le tableau de bord de l'interface standard.

- **Compatibilité GLPI 12** (en plus de GLPI 11, depuis un seul et même paquet). Trois
  incompatibilités réelles corrigées, identifiées en faisant tourner la suite complète sur un vrai
  GLPI 12.0.0-rc2 :
  - GLPI 12 type `CommonGLPI::$rightname` (`string`), GLPI 11 non — et PHP impose à une
    sous-classe de reprendre exactement le type du parent, donc aucune déclaration unique ne
    fonctionne sur les deux. Les 17 classes concernées héritent désormais d'une classe
    intermédiaire (`Compat\Base\…Base`) déclarée selon la version installée (propriété typée en 12,
    non typée en 11) ; chaque classe fournit sa valeur via la constante `RIGHTNAME`.
    Une classe et non un trait : sur PHP 8.2 à 8.4, un trait ne peut pas
    redéclarer une propriété héritée avec une autre valeur (erreur fatale, ou en PHP 8.2 valeur
    partagée en silence avec la classe de GLPI). Nouveau job CI `php-compat` qui charge chaque
    classe concernée sur le vrai cœur GLPI, sous PHP 8.2 à 8.5 × GLPI 11 et PHP 8.3 à 8.5 × GLPI 12.
  - La classe globale `QueryExpression` n'existe plus en GLPI 12 → `Glpi\DBAL\QueryExpression`
    (déjà présente en GLPI 11).
  - `Toolbox::getURLContent()` n'existe plus en GLPI 12 → `Compat\Http`, qui utilise le nouveau
    client HTTP de GLPI 12 ou l'ancien en GLPI 11 (vérification de version GitHub).

  Résultat : 358/358 tests au vert sur GLPI 11.0.9 **et** sur GLPI 12.0.0-rc2, installation /
  activation / désinstallation réelles vérifiées sur les deux. Nouveau job CI `test-glpi12` pour
  que la compatibilité GLPI 12 ne puisse plus régresser silencieusement. Le garde-fou de version
  de `setup.php` reste en place (plafond relevé à 12.99.99) : il protège d'une future version
  majeure non encore vérifiée.

- **Signatures multiples (issue #143)** : une Attribution peut désormais exiger, en plus de la
  signature du bénéficiaire, la contre-signature de son responsable hiérarchique
  (`User::users_id_supervisor`, déjà renseigné par l'annuaire) — réglage par entité
  `Config::enable_co_signature`, désactivé par défaut. Le bénéficiaire signe d'abord (flux
  inchangé) ; le document, déjà signé une fois, est ensuite transmis au responsable pour
  contre-signature (nouveau statut `STATUS_AWAITING_COSIGNATURE`, nouveau lien/jeton dédié,
  nouvelle notification « Contre-signature requise ») avant d'être considéré comme définitivement
  signé (`document_id_cosigned`, PDF final à deux blocs de signature). Sans responsable renseigné
  pour le bénéficiaire, l'attribution reste mono-signataire, comme aujourd'hui — aucun changement
  de comportement pour les entités qui n'activent pas ce réglage. Limité aux Attributions (pas aux
  restitutions, dons, ventes, destructions, maintenances ou mouvements) pour cette première
  version.

- **Bénéfice du réemploi / « impact évité »** (issue #81) : nouvelle section du Passeport matériel
  valorisant la prolongation d'usage au-delà de la durée d'amortissement comptable du matériel
  (`Infocom::sink_time`, champ natif GLPI déjà saisi, en années). Calcul « évitement proportionnel »
  standard (économie circulaire) à partir de trois données réelles obligatoires — durée
  d'amortissement prévue, dates de mise en service/réforme, empreinte carbone de fabrication déjà
  saisie (issue #80) — jamais une moyenne sectorielle inventée : reste `null` (aucun affichage) tant
  que la durée réelle n'a pas dépassé la durée prévue, ou si l'un des trois prérequis manque, dans le
  droit fil du principe fondateur du plugin (`EnvironmentalData`, ne jamais estimer). Désactivé par
  défaut (case à cocher dédiée dans le paramétrage Environnement de l'entité), pour la même raison que
  le passeport environnemental : dépend entièrement de saisies manuelles préalables qu'aucune instance
  existante n'a encore faites pour ce nouvel indicateur.

### Fixed

- **Parenthèses vides « () » après le nom du signataire** sur le PDF signé et sur les fiches
  Assetsign/Maintenance/Mouvement quand le signataire n'a pas d'adresse e-mail — cas désormais
  courant avec #150/#152. L'e-mail n'est plus affiché que s'il existe.

- **La délégation d'auto-signature (page reçue par e-mail par le bénéficiaire lui-même) était
  totalement non fonctionnelle** : `sign_page.html.twig` est une page volontairement autonome, sans
  jQuery (JS moderne fetch/module uniquement) — le widget `User::dropdown()` y génère du JS qui
  suppose jQuery disponible et ne s'initialisait donc jamais, laissant un menu « Déléguer à » bloqué
  sur « ----- », quel que soit le profil ou la version. Bug distinct et plus ancien que celui déjà
  corrigé sur les deux menus techniciens/admin (`right='id'` vs `'all'`), découvert en retestant
  l'issue #115 en conditions réelles (instance neuve, Playwright). Remplacé par un `<select>` simple
  rempli côté serveur (`Assetsign::getDelegateCandidates()`, même périmètre `right='all'` + entité) :
  aucune dépendance JS, aucun changement du script de soumission existant (il lit déjà le `<select>`
  natif par son attribut `name`).

- **Le menu « Destinataire » du formulaire de création manuelle (Don/Vente/Destruction,
  `assetsign_tab.html.twig`) ne proposait que le compte actuellement connecté**, quel que soit son
  profil — exactement le même bug que celui déjà corrigé pour les deux menus de délégation de
  signature (`right => 'id'`, le défaut de `User::dropdown()`, jamais aligné sur `'all'` dans #122
  quand ce correctif avait été appliqué aux deux autres menus). Rapporté à nouveau sur l'issue #115
  après la sortie de ce correctif, ce qui a permis de découvrir ce troisième site jamais traité.

- **`plugin_init_assetsign()` plantait en erreur fatale PHP sur une version de GLPI incompatible**
  au lieu de laisser GLPI afficher son propre refus de version — confirmé en testant en direct
  contre un vrai conteneur GLPI 12.0.0-rc2 : `Type of GlpiPlugin\Assetsign\Assetsign::$rightname
  must be string, as in class CommonGLPI` (GLPI 12 type désormais cette propriété). Le correctif du
  même type sur `Hooks::CSRF_COMPLIANT` (plus haut dans ce fichier) n'avait traité qu'un symptôme :
  toute la fonction référence de vraies classes du plugin (`WorkflowTypeRegistry`,
  `Assetsign::class`...) sans aucun garde-fou de version. Ajout d'un `version_compare(GLPI_VERSION,
  PLUGIN_ASSETSIGN_MAX_GLPI, '>')` explicite en tête de fonction — pas un
  `Plugin::isPluginActive('assetsign')` : cette même fonction est aussi nécessaire *pendant*
  `plugin:install` lui-même, avant que le plugin soit actif (une première version de ce correctif,
  basée sur `isPluginActive()`, cassait l'installation sur GLPI 11 avec « type de workflow inconnu
  (id=0) », `WorkflowTypeRegistry` n'étant alors jamais peuplée pendant l'installation).

- **L'« état des lieux visuel » (repères de dommage) affichait toujours les 3 photos d'un
  ordinateur portable, même pour un Téléphone** — trouvé en vérifiant en conditions réelles la
  parité fonctionnelle PC/téléphone (attribution complète avec signature électronique réelle sur
  un vrai Téléphone). La fiche affichait bien « Type : Téléphone » et l'onglet fonctionnait
  entièrement (marqueurs, signature, PDF), mais `DamageMarker::getViewImageFilenames()` renvoyait
  systématiquement les 3 mêmes illustrations génériques (`public/images/damage-views/*.jpg`),
  quel que soit le type de matériel réel. Nouvelles illustrations dédiées
  (`*_telephone.jpg`) utilisées uniquement pour `Phone` ; tout le reste (Computer, Monitor,
  Peripheral, actifs personnalisés...) garde les 3 vues génériques existantes, inchangées.

### Added

- Tests de rendu confirmant que les 3 menus `User::dropdown()` du plugin (délégation
  technicien/admin, auto-délégation bénéficiaire, destinataire de création manuelle) proposent
  effectivement d'autres comptes que celui connecté (`"right":"all"` dans le JSON de configuration
  du widget, présent dans le HTML rendu) — aucun des trois n'était couvert jusqu'ici, ce qui a
  laissé ce troisième site du même bug passer inaperçu depuis #122.
- Tests de contenu pour `Pdf\MaintenancePdfBuilder::renderHtml()` — jusqu'ici seulement exercée
  indirectement via `Maintenance::createWithChecklist()` (qui vérifie qu'un `Document` est bien
  attaché, jamais ce que le HTML rendu contient réellement). Vérifie, à la même granularité que
  `AssetsignTest` pour `HandoverPdfBuilder::renderHtml()`, la présence conditionnelle des points de
  contrôle renseignés, du bloc QR code (`show_qr_code`), de la section état des lieux visuel
  (`enable_damage_annotation`) et de la zone de signature (`enable_maintenance_signature`).
- `Security\AssetAssignmentGuard`, extraite de `front/assign_user_asset.php` pour rendre testable en
  PHPUnit contre de vraies fixtures (entité/matériel/session) la logique de garde de ce front —
  itemtype restreint à la famille `CommonDBTM` (faux positif tainted-object-instantiation déjà revu,
  cf. ARCHITECTURE.md) puis `can($items_id, UPDATE)` (même garde-fou de ségrégation par entité que
  `Assetsign::createManual()`). Même motif que `Security\OpcacheResetGuard`, comportement du front
  inchangé, purement une extraction.
- Tests pour `Profile::install()`/`uninstall()` — aucun test dédié jusqu'ici. Couvre la nuance
  documentée directement dans la classe : un droit opérationnel (Admin/Technician) n'est écrit qu'à
  la création de la ligne et respecte une révocation manuelle sur une réinstallation ultérieure,
  alors que Super-Admin est toujours réinitialisé à tous les droits à chaque exécution.
- Tests pour `Pdf\SignatureStamper::apply()` — aucun test direct jusqu'ici (`SignControllerTest` ne
  couvre que la garde d'autorisation, jamais le flux de signature réel ; les assertions de contenu
  PDF existantes passent toutes par `HandoverPdfBuilder::renderHtml()` directement). Vérifie
  l'écriture réelle du fichier signé dans `GLPI_TMP_DIR`, la correspondance du hash SHA-256 avec le
  contenu réel sur disque, l'horodatage `signed_at`, et le chiffrement effectif du PDF quand
  `protect_pdf` est activé pour l'entité (entrée `/Encrypt` du PDF produit par CPDF).

## [2.7.1] - 2026-09-04

### Security

- **Rapport de sécurité 2.6.0, traité intégralement** : correctifs sur la délégation de signature
  (validation serveur du délégué — entité/statut actif — jusque-là vérifiée seulement côté Twig ;
  interdiction de re-délégation en chaîne B→C→D en auto-délégation) et 10 failles MEDIUM + 2 LOW
  héritées de la 2.5.0 jamais corrigées : contrôles de droit READ manquants sur les onglets
  Attributions/Maintenance/Passeport/Config/Mouvements, restriction d'entité manquante sur
  `front/config.php`/`front/preview.php` (`Session::haveAccessToEntity()`) et sur
  `Assetsign::showForUser()`/`PassportEvent::showForUser()`, XSS stocké potentiel via le contrat/la
  charte non purifiés avant rendu PDF (`RichText::getSafeHtml()`), schéma d'URL non validé sur
  `charter_url`, et une possibilité de déni de service sur le jeton de signature (un tiers
  authentifié pouvait épuiser la limite de tentatives d'un lien qui ne lui appartenait pas —
  `Token::recordAttempt()` séparé de `Token::validate()`, ne compte plus que les accès réellement
  autorisés).

### Fixed

- **Délégation de signature inutilisable en pratique** (rapporté sur la marketplace GLPI) : la liste
  déroulante de sélection du délégué (`User::dropdown`) n'affichait que le compte actuellement
  connecté (paramètre `right` par défaut `'id'`), rendant la fonctionnalité livrée en 2.6.0 non
  fonctionnelle pour désigner qui que ce soit d'autre.

## [2.7.0] - 2026-09-04

### Added

- **Passeport environnemental (empreinte de fabrication)** (issue #80, cf. ROADMAP.md V3) : amorce du
  volet RSE sur le Passeport matériel, activable par entité (`enable_environmental_passport`, défaut
  désactivé — nouvel onglet « Passeport environnemental » de la configuration). Nouvelle table dédiée
  `glpi_plugin_assetsign_environmentaldatas` (1-vers-1 `itemtype`/`items_id`, même patron que
  `ResidualValue`/`Movement`) : empreinte de fabrication en kg CO2-eq, source (constructeur / API
  externe dédiée / saisie manuelle) et niveau de confiance (élevé/moyen/faible, badge coloré — même
  convention que `Movement::getStatusColor()`), tous nullable, jamais de valeur par défaut. Formulaire
  de saisie manuelle sur l'onglet Passeport matériel (droit `UPDATE`), absence explicite tant que rien
  n'est saisi. **Recherche menée avant l'implémentation** (commentaire posté sur l'issue #80) : l'API
  publique Boavizta (piste notée dans ROADMAP.md, section « Envisagé ») n'expose, pour les
  ordinateurs/portables, que 2 « archétypes » génériques par catégorie (moyennes "pro"/"perso"),
  jamais une valeur propre à un modèle réel, et rien dans GLPI ne permet de choisir objectivement
  entre les deux — l'afficher comme la donnée de fabrication de CE matériel aurait été une donnée
  inventée, contraire au principe fondateur du plugin. **Scope réduit à la saisie manuelle uniquement
  dans cette version** en conséquence : le champ `source` garde ses 3 valeurs (pour ne pas fermer la
  porte à une vraie intégration future par modèle exact), mais aucun appel réseau ni bouton
  « Rechercher automatiquement » dans cette version — la source trace d'où le technicien tient le
  chiffre (fiche constructeur, outil externe consulté à la main, estimation propre), pas un mécanisme
  d'automatisation.

## [2.6.0] - 2026-09-03

### Added

- Tests pour `Api\MovementFormController` et `Api\PassportBackfillController` — c'étaient les
  deux seuls contrôleurs de la famille `Api\*Controller` (aux côtés d'`AssetsignFormController`,
  `MaintenanceFormController`, `ResidualValueFormController`, `QrLabelController`,
  `SignController`, tous déjà testés) sans test dédié couvrant leur propre logique de garde
  (itemtype valide, matériel/utilisateur trouvable et lisible par l'utilisateur courant).
- `Security\OpcacheResetGuard`, extraite de `front/opcache_reset.php` pour rendre testable en
  PHPUnit la logique d'autorisation (liste blanche d'IP + comparaison de jeton en temps constant)
  de ce point d'entrée — le seul déjà visé par une faille réelle (revue de sécurité marketplace
  GLPI, low, #98) et qui n'avait jusqu'ici aucun test de non-régression. Comportement du front
  inchangé, purement une extraction.

- **Délégation de la signature de restitution** (issue #115) : un document en attente de signature
  (Attribution/Restitution/Don/Vente/Destruction, bénéficiaire **interne** uniquement — un
  bénéficiaire externe n'a de toute façon aucun flux de signature à distance, cf.
  `Assetsign::launchWorkflow()`) peut désormais être délégué à un autre compte GLPI existant, pour
  ne plus bloquer le processus en cas d'absence prolongée (congés, arrêt maladie, départ...). Deux
  modes, chacun activable indépendamment par entité (`front/config.php`, onglet « Délégation de
  signature »), le second nécessitant le premier (dépendance vérifiée en JS **et** côté serveur
  dans `Config::upsertForEntity()`) : (1) un technicien/administrateur (droit `UPDATE` sur
  `Profile::RIGHT_ASSETSIGN`) désigne un délégué depuis la fiche Assetsign
  (`enable_signature_delegation`) ; (2) le bénéficiaire lui-même se délègue depuis sa page de
  signature (`enable_self_service_delegation`, motif obligatoire dans ce cas). Traçabilité complète
  via 4 nouvelles colonnes additives sur `glpi_plugin_assetsign_assetsigns`
  (`delegated_users_id`/`delegation_date`/`delegation_reason`/`delegated_by_users_id`) : le
  bénéficiaire d'origine (`users_id`) n'est jamais modifié, l'historique natif GLPI capture chaque
  délégation (`Assetsign::delegateSignatureTo()`, même mécanisme que `cancelRequest()`). La
  délégation régénère systématiquement le jeton de signature : l'ancien lien (déjà envoyé au
  bénéficiaire d'origine) devient invalide dans le même mouvement — c'est le seul mécanisme de
  « révocation » retenu (plus simple qu'un état actif/inactif vérifié à chaque accès), un
  administrateur pouvant rendre la main au bénéficiaire d'origine via `Assetsign::revokeDelegation()`
  (bouton « Révoquer » sur la fiche). `SignController::assertCurrentUserIsAuthorizedSigner()`
  (renommée depuis `assertCurrentUserIsBeneficiary()`) accepte désormais le bénéficiaire d'origine
  **ou** le délégué ; `Assetsign::getActualSigner()` résout l'identité du signataire réellement
  connecté (pas systématiquement le bénéficiaire d'origine) pour la preuve de signature ET la ligne
  « Signataire » du PDF final, qui porte en plus une mention explicite de délégation (délégué par
  qui, quand, pour quel motif) quand applicable. Nouvel événement de notification `delegated`
  (`NotificationTargetAssetsign`, cible dédiée `TARGET_DELEGATE`) : le délégué reçoit son propre
  lien de signature par e-mail, dans les 5 langues déjà supportées par le plugin.

- **Kits/accessoires avec contrôle automatique au retour** (issue #83, cf. ROADMAP.md tableau V3
  et `docs/design/ADR-passeport-v1.md`) : nouveau catalogue `Kit` — un GROUPE nommé et réutilisable
  d'accessoires censés voyager ensemble avec une remise (ex: « Kit ordinateur portable standard » =
  Chargeur + Sacoche + Souris). Même motif exact que `ChecklistItem` (issue #74) plutôt qu'un
  mécanisme parallèle : dropdown standard GLPI (CRUD/recherche/droits génériques gratuits), la
  composition du kit (`accessories_id`) stockée en JSON directement sur la ligne du catalogue —
  volontairement **pas** une nouvelle table pivot, un Kit n'ayant qu'une seule caractéristique
  propre (sa composition), contrairement à `AssetsignAccessory` qui porte en plus une quantité et
  un commentaire PROPRES À CHAQUE remise. Nouveau champ `plugin_assetsign_kits_id` sur `Assetsign`
  (pas de contrainte de clé étrangère, même choix déjà fait pour `plugin_assetsign_templates_id`) :
  un technicien peut tagguer une Attribution (ou une Restitution) « utilise le Kit X », éditable
  tant que la fiche reste modifiable (`Assetsign::updateKit()`, même garde `isStillEditable()` que
  `addAccessory()`/`updateVenteDetails()`). Le kit assigné à l'Attribution est reporté
  **automatiquement** sur la Restitution créée ensuite pour le même matériel
  (`Assetsign::resolveKitForAutomaticCreation()`, appelée depuis `createAssetsign()`) — un simple
  report d'une donnée déjà réellement saisie, jamais une invention, même principe que
  `writeDecommissionDateIfMissing()` (date de réforme automatique) — reste corrigeable ensuite par
  un technicien. Cœur de la détection automatique : `Kit::computeCompleteness()`, une comparaison
  PURE (aucun accès base, testée unitairement pour les cas complet/un manquant/tout manquant/aucun
  kit assigné) entre les accessoires ATTENDUS par le kit et ceux RÉELLEMENT enregistrés sur la
  Restitution (`AssetsignAccessory`, déjà existant — aucune nouvelle saisie requise), par simple
  PRÉSENCE d'accessoire, jamais par quantité. Affiché à deux endroits : sur la fiche Assetsign
  elle-même (section « Kit d'accessoires », à côté des accessoires) et, PUREMENT calculé à
  l'affichage comme le résumé de checklist qualité (`PassportEvent::attachChecklistSummaries()`),
  en badge coloré fusionné dans la frise du Passeport matériel/utilisateur sur l'événement de
  Restitution (`PassportEvent::attachKitSummaries()`, batché en 4 requêtes au total pour toute la
  frise, jamais une par événement affiché — même souci de performance documenté dans l'ADR) : vert
  si complet, orange si un ou plusieurs accessoires manquent, rouge si aucun accessoire du kit
  n'est revenu (perte totale, sévérité volontairement distincte du gris « pas encore rempli » de la
  checklist qualité). Absent (aucun badge) si aucun kit n'est assigné à la Restitution, ou si le
  kit assigné n'a plus aucun accessoire attendu configuré : l'absence de kit n'est pas un défaut à
  signaler.

- **Module d'aide à la décision** (issue #79, cf. ROADMAP.md tableau V2 et
  `docs/design/ADR-passeport-v1.md`) : troisième indicateur en tête du Passeport matériel,
  après le score de santé et la valeur résiduelle dont il dépend explicitement. Moteur de
  RÈGLES SIMPLE à seuils, explicitement PAS du machine learning (la roadmap le précise :
  "architecture prête pour de l'IA plus tard sans y aller maintenant") — deux règles
  indépendantes, chacune avec son libellé et sa raison explicite, calculées à l'affichage
  (`PassportEvent::getDecisionAidRecommendations()`), jamais persistées ni mises en cache,
  exactement comme le score de santé et la valeur résiduelle : « Prévoir un remplacement »
  (score de santé sous le seuil « Vigilance » déjà réglable,
  `Config::health_score_warning_threshold` — réutilisé tel quel, aucun second réglage
  redondant) et « Réévaluer l'usage » (valeur résiduelle sous un pourcentage réglable du prix
  d'achat d'origine, nouveau réglage `Config::residual_value_low_threshold_percent`, seul
  nouveau seuil introduit par cette PR). Chaque règle exige que sa propre donnée source soit
  réellement disponible (score de santé calculable, valeur résiduelle ET prix d'achat
  d'origine tous deux connus) — jamais une recommandation inventée à partir d'une donnée
  manquante ou d'une fonctionnalité désactivée. Plusieurs règles déclenchées simultanément :
  toutes affichées, jamais une seule masquant les autres — un vrai outil d'aide à la décision
  ne doit jamais cacher un facteur contributif. Nouvel onglet dédié « Aide à la décision » de
  la page de configuration (`Config::enable_decision_aid`, activé par défaut), nouveau bloc
  d'alertes colorées (Tabler `alert-danger`/`alert-warning`) sur l'onglet Passeport matériel,
  sibling visuel du score de santé et de la valeur résiduelle — silencieux (aucun bloc
  affiché) quand rien n'est à signaler ou que les deux indicateurs sources sont indisponibles,
  même convention que ces deux blocs. Aucune nouvelle table : `getDecisionAidRecommendations()`
  reste le seul point d'entrée consulté par `showForItem()`, de sorte qu'un futur moteur
  différent (modèle entraîné, appel à un service externe...) pourrait la remplacer sans
  toucher à l'affichage ni aux deux indicateurs sources — "architecture prête pour l'IA" au
  sens de la roadmap, sans construire d'abstraction supplémentaire par anticipation.

## [2.5.0] - 2026-08-30

### Added

- **Fin de vie structurée : destruction (prestataire/certificat) et don (organisme/justificatif)**
  (issue #78, dernière partie de "fin de vie structurée" — la date de réforme automatique était déjà
  livrée le 2026-08-19, cf. entrée correspondante ci-dessous dans l'historique) : nouveau type
  `Assetsign::TYPE_DESTRUCTION` (5), avec le même traitement complet que Don/Vente (déclenchement
  manuel via `createManual()`, déclenchement automatique par changement d'État configurable
  `Config::getDestructionStates()`, coordination bidirectionnelle État ↔ fiche via
  `syncItemStateAfterManualCreation()`, gabarit PDF dédié `Workflow\DestructionType`). Le Don
  (`Assetsign::TYPE_DON`, déjà existant) gagne un organisme bénéficiaire, jusqu'ici sans aucune donnée
  dédiée. Prestataire (Destruction) et organisme (Don) stockés dans deux nouvelles tables dédiées
  1-vers-1 avec `Assetsign` (`DestructionDetails`/`DonDetails`, même motif exact que `VenteDetails`
  déjà en place pour le prix de vente), éditables après coup via `Assetsign::updateDestructionDetails()`/
  `updateDonDetails()` — nécessaire pour une fiche déclenchée automatiquement par changement d'État,
  où ni le prestataire ni l'organisme ne sont connus au moment de la création (même logique déjà
  appliquée au prix de la Vente). Certificat de destruction et justificatif de don : upload de fichier
  simple (`<input type="file">`), attaché en tant que Document natif GLPI directement sur la fiche
  Assetsign elle-même (`Assetsign::attachUploadedDocument()`, nouvelle méthode reprenant exactement le motif
  déjà utilisé par `Movement::attachDocument()`) — visible depuis l'onglet Documents natif de la fiche,
  sans nouvelle table de stockage de fichiers. Le prix/acheteur/documents de la Vente n'étaient PAS
  dans le périmètre de cette PR : déjà couverts (prix via `VenteDetails`, acheteur via `users_id`),
  cf. ROADMAP.md qui avait explicitement restreint le périmètre restant de l'issue #78 à
  prestataire/certificat (destruction) et organisme/justificatif (don). `PassportEvent::TYPE_DESTRUCTION`
  (6) ajouté symétriquement à `TYPE_DON`/`TYPE_VENTE` pour la frise du Passeport matériel.

- **Valeur résiduelle (linéaire / durée personnalisable / saisie manuelle)** (issue #77,
  cf. ROADMAP.md tableau V2 et `docs/design/ADR-passeport-v1.md`) : nouvel indicateur en tête
  du Passeport matériel, estimation simple (pas un module comptable complet) pour aider à
  trancher réemploi vs sortie de parc. Calcul linéaire à partir du prix d'achat et de la date
  d'achat déjà lus depuis `Infocom` (même source que la fiche d'identité/le score de santé,
  jamais dupliquée) : `valeur = prix_achat × max(0, 1 − âge_jours / durée_jours)`, plafonné à
  0. Durée de vie utile "personnalisable" au sens de la roadmap = un nouveau réglage par
  entité (`Config::residual_value_duration_months`, mois, défaut 60), pas une deuxième méthode
  de calcul - onglet dédié « Valeur résiduelle » de la page de configuration
  (`Config::enable_residual_value`, activé par défaut). Aucune valeur inventée : rien n'est
  affiché tant que le prix d'achat Infocom est inconnu, exactement comme l'âge/le temps
  utilisé le font déjà. Saisie manuelle toujours prioritaire sur le calcul automatique
  (nouvelle classe `ResidualValue`, table dédiée `glpi_plugin_assetsign_residualvalues`
  1-vers-1 sur `itemtype`/`items_id` - un type d'item natif GLPI ne pouvant recevoir de
  colonne supplémentaire, même patron que `VenteDetails`/`Movement`), avec un petit
  formulaire inline sur l'onglet Passeport matériel (`front/residualvalue.form.php`,
  `Api\ResidualValueFormController`) permettant de saisir une valeur ou de revenir au calcul
  automatique - jamais un simple repli dégradé, un vrai choix toujours disponible. Le libellé
  distingue toujours explicitement une valeur « estimée » d'une valeur « saisie manuelle »,
  pour ne jamais laisser croire à l'un ou l'autre à tort.

## [2.4.0] - 2026-08-26

### Added

- **QR code imprimable sur le matériel** (issue #82, ROADMAP.md V3) : nouveau bouton
  « Imprimer une étiquette QR code » sur l'onglet Passeport matériel, ouvrant une page
  dédiée (`front/qrlabel.php`) minimaliste et pensée pour l'impression (bouton
  « Imprimer » via `window.print()`, CSS `@media print` masquant tout le reste). Le QR
  code encode une URL ABSOLUE (domaine inclus, `$CFG_GLPI['url_base']`, même convention
  que `Assetsign::getSignUrl()`) qui utilise le mécanisme standard `forcetab` du cœur
  GLPI (même convention que le lien « créez maintenant la fiche » de
  `Assetsign::handleStateBasedTrigger()`) pour ouvrir directement l'onglet Passeport
  matériel du matériel scanné. Aucun mécanisme d'accès anonyme introduit : scanner le QR
  code redirige vers un lien GLPI standard, qui exige la connexion habituelle si la
  personne n'est pas déjà authentifiée sur son téléphone - cohérent avec le reste du
  plugin (cf. README.md/SECURITY.md, aucune page n'est jamais accessible par un simple
  lien anonyme). Génération du QR code (`BaconQrCode`, déjà fourni par le cœur GLPI,
  jamais dupliqué dans le composer.json du plugin) extraite de `Pdf\PdfRenderingHelpers`
  (qui ne servait jusqu'ici que le QR code des fiches PDF, réglage `show_qr_code`) vers
  une nouvelle classe partagée `QrCode`, pour ne jamais dupliquer cette génération.
  Nouveau réglage d'entité dédié `enable_qr_label` (défaut ACTIF, distinct de
  `show_qr_code`) : Configuration > Assetsign & signature > Compléments.

- **Mouvements structurés (départ/destination/documents/signature)** (issue #75, cf.
  `docs/design/ADR-passeport-v1.md` section 3.2 pour le schéma initialement proposé, tranché
  pendant l'analyse #76) : nouvelle classe `Movement`, généralisant la notion de remise
  (toujours personne A → personne B) à n'importe quel déplacement physique de matériel
  (transfert inter-site, retour au stock, envoi vers un centre de réparation...) - lieu et date
  de départ, lieu et date de destination (`Location`, dropdown natif GLPI, jamais une nouvelle
  table de lieux), document(s) joint(s) (onglet Documents natif, `Document`/`Document_Item`,
  jamais un nouveau stockage), signature optionnelle (`Signature`, même mécanisme déjà utilisé
  par les fiches de maintenance, jamais un second système). Déviation assumée par rapport au
  schéma initialement proposé dans l'ADR (deux colonnes ajoutées à `glpi_plugin_assetsign_assetsigns`
  plutôt qu'une classe séparée) : un mouvement doit pouvoir exister sans aucune remise associée
  (ex: un simple retour au stock n'a pas de bénéficiaire à faire signer), documentée dans l'ADR
  lui-même. Nouvel onglet « Mouvements » sur chaque matériel géré, page dédiée (Outils >
  Mouvements, liste avec filtres de recherche réels + création autonome), statuts colorés
  (Prévu/En cours/Terminé/Annulé) avec actions de transition. Alimente la même frise que le
  Passeport matériel (`glpi_plugin_assetsign_events`, nouveau type d'événement `TYPE_MOVEMENT`) -
  un nouveau producteur d'événements, jamais une réécriture des producteurs existants. Fonctionnalité
  et signature optionnelle toutes deux opt-in par entité (`Config::enable_movements`/
  `enable_movement_signature`, défaut désactivé, comme les autres fonctionnalités du plugin).

- **Checklists de contrôle qualité configurables, réutilisables sur les mouvements de matériel**
  (issue #74, cf. `docs/design/ADR-passeport-v1.md` pour l'analyse complète et les 6 risques
  techniques tranchés en amont, issue #76) : nouveau catalogue `ChecklistItem` (Configuration >
  Intitulés, même motif que les points de contrôle de maintenance existants - case à cocher, texte
  libre ou menu déroulant), chaque point taggé sur les types de mouvement où il s'applique
  (Attribution/Restitution/Don/Vente). Un nouveau bloc « Contrôle qualité » apparaît sur la fiche
  d'une Attribution tant qu'elle reste modifiable (résultats déjà enregistrés toujours affichés,
  formulaire d'édition tant que `isStillEditable()`), quel que soit le mode de création
  (déclenchement automatique par affectation/État, ou création manuelle Don/Vente). Le nombre de
  contrôles remplis (badge coloré, X/Y) est fusionné dans la frise du Passeport matériel ET
  utilisateur sans jamais dupliquer la donnée dans `glpi_plugin_assetsign_events` (pure agrégation
  à l'affichage, batchée en 2 requêtes au total pour toute la frise - jamais une par événement).
  Nouvel index composite `(itemtype, items_id, date)` sur `glpi_plugin_assetsign_events` au
  passage (risque de volume identifié pendant l'analyse). « Mouvements structurés » (issue #75)
  reste analysé mais non implémenté (schéma SQL proposé dans l'ADR) plutôt que de livrer les deux
  items V1 à moitié.

## [2.3.1] - 2026-08-25

### Security

- **`front/opcache_reset.php` protégé uniquement par une restriction d'adresse IP contournable**
  (revue de sécurité marketplace GLPI, sévérité faible, issue #98) : la vérification
  `REMOTE_ADDR==127.0.0.1/::1` seule est contournable derrière un reverse-proxy en mode loopback
  (`REMOTE_ADDR` vaut alors `127.0.0.1` pour tout le trafic externe), permettant un appel répété
  non authentifié (petit déni de service CPU). Corrigé en ajoutant un jeton partagé généré une
  seule fois à l'installation (`plugin_assetsign_install()`, persisté dans
  `GLPI_PLUGIN_DOC_DIR`, hors racine web), vérifié via `hash_equals()` en plus de (pas à la place
  de) la restriction IP existante conservée en défense en profondeur. `update.sh` (seul appelant
  réel de ce endpoint) lit ce même jeton sur le système de fichiers et le transmet. Vérifié en
  conditions réelles : appel sans jeton ou avec un jeton incorrect → 403 ; avec le bon jeton → 200
  ; cycle complet `update.sh` (migration, activation, vidage de cache, réinitialisation d'OPcache)
  confirmé fonctionnel de bout en bout.

### Fixed

- **Listes « Gestion des fiches » et « Fiches de maintenance » : aucune colonne ne permettait
  d'ouvrir la fiche elle-même** : la colonne ID était un simple nombre non cliquable (datatype
  `number`), et la seule colonne déjà cliquable (le matériel concerné, `items_id`) pointe vers la
  fiche du matériel lui-même (Ordinateur, etc.), pas vers l'attribution/la fiche de maintenance en
  question (constat direct du porteur du plugin sur un écran comparable d'un autre plugin de la
  même famille : clic sur l'ID fonctionne, clic sur le libellé de la ligne ne fait rien). La colonne
  ID passe désormais en `datatype => 'itemlink'` (`itemtype => self::class`) sur
  `Assetsign::rawSearchOptions()` et `Maintenance::rawSearchOptions()`, vérifié en conditions
  réelles (HTML rendu : `<a href="…/assetsign.form.php?id=…">`/`<a href="…/maintenance.form.php?id=…">`).

## [2.3.0] - 2026-08-24

### Added

- **Traductions complétées dans les 5 langues** (#91) : régénéré `locales/assetsign.pot` via
  l'outil d'extraction officiel (`vendor/bin/extract-locales`, nouvelle dépendance dev
  `glpi-project/tools`, absente jusqu'ici) plutôt qu'une comparaison manuelle à la regex, ce qui a
  révélé 77 chaînes ajoutées au fil des dernières fonctionnalités (passeport matériel, score de
  santé, réforme automatique, gabarits...) jamais traduites en anglais/allemand/espagnol/italien.
  Traduites dans les 4 langues, `fr_FR.po` complété (langue source, chaîne identique). 8 chaînes
  devenues obsolètes par des renommages antérieurs (ex. « remise » → « Attribution », #89/#90)
  marquées comme telles (`#~`), pas supprimées.
- **Vérification CI de complétude des traductions** (#91, suggestion explicite de l'issue) :
  nouveau job "locales" qui régénère le `.pot` à chaque run et échoue si une chaîne du code n'a pas
  de traduction dans une des 5 langues, pour éviter que ce manque se reproduise à chaque nouvelle
  fonctionnalité.

### Changed

- README (FR/EN) : la section Installation (prérequis, récupération du code, installation/
  activation, droits, configuration) est désormais reprise directement dans le README au lieu
  d'être uniquement accessible via un lien vers [INSTALLATION.md](INSTALLATION.md), qui reste la
  référence pour le détail complet (script de mise à jour, pièges de cache Twig/OPcache).
- USER_GUIDE.md (FR/EN) : documente désormais la date de réforme automatique sur changement
  d'État (v2.2.0), absente du guide alors que la fonctionnalité était déjà publiée.

### Fixed

- **Incohérence de terminologie EN/DE/ES sur « Réforme »** : les traductions de #91 utilisaient un
  mot indépendant (« Disposal »/« Ausmusterung »/« Baja ») au lieu du libellé natif que GLPI
  lui-même utilise pour ce même champ (`Infocom::decommission_date`, confirmé dans
  `locales/en_GB.po`/`de_DE.po`/`es_ES.po` du cœur GLPI : « Decommission date » /
  « Außerbetriebnahme » / « Desmantelamiento »). Corrigé pour aligner ce plugin sur le vocabulaire
  déjà utilisé par l'onglet Finances/Infocom natif juste à côté, un utilisateur ne doit pas voir
  deux mots différents pour le même champ. `it_IT` n'était pas concerné (traduction déjà alignée).

- **Cartes de tableau de bord (`Hooks::DASHBOARD_CARDS`) disparaissant silencieusement en présence
  d'un autre plugin actif hookant le même point d'extension** (confirmé en conditions réelles avec
  `glpi-vulnerability-manager` sur une instance partagée : ses cartes étaient absentes du sélecteur
  « Ajouter une carte » à cause de ce bug côté `assetsign`) : `plugin_assetsign_dashboard_cards()`
  déclarait une signature sans paramètre et retournait uniquement ses propres cartes, alors que
  `Plugin::doHookFunction()` enchaîne tous les callbacks enregistrés via le même accumulateur
  (`$ret = call_user_func($function, $ret)` pour chacun, sans jamais faire de `array_merge()`
  lui-même) : un callback qui ignore cet accumulateur écrase donc systématiquement la contribution
  de tout plugin déjà passé dans la chaîne. Corrigé en acceptant `?array $cards = null` (nullable :
  GLPI appelle le tout premier callback de la chaîne avec un `null` explicite, pas un tableau vide)
  et en fusionnant dessus, même correctif que celui appliqué en premier sur
  `glpi-vulnerability-manager`.

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
