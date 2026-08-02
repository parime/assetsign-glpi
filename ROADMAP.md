# Feuille de route

Ce document liste ce qui est **envisagé**, pas engagé sur une date précise. Pour ce qui manque déjà aujourd'hui de façon plus factuelle, voir [USER_GUIDE.md](USER_GUIDE.md#ce-qui-nest-pas-encore-implémenté).

## Envisagé

- **Fournisseur de signature externe** (Yousign, DocuSeal...) pour un niveau de signature électronique renforcé (eIDAS "avancée"/"qualifiée"), en plus de la signature à l'écran actuelle (niveau "simple"). Le point d'extension existe déjà dans le code (`Provider\SignatureProviderInterface`) — seul `CanvasProvider` (signature à l'écran) est implémenté à ce jour ; brancher un prestataire externe n'exigerait pas de revoir l'architecture existante.
- **Étendre la couverture de tests automatisés aux contrôleurs `front/*.php`** eux-mêmes, actuellement testés manuellement via des scripts `curl` contre une vraie instance plutôt qu'en PHPUnit (risque réel de `Html::redirect()`/`exit()` interrompant le process de test si ces fichiers étaient inclus directement — cf. [TROUBLESHOOTING.md](TROUBLESHOOTING.md)). Une extraction plus poussée de leur logique vers des classes testables (à l'image de `Api\SignController` pour `front/sign.php`) réduirait ce point mort.
- **SonarCloud** en CI, en complément de PHPStan/phpcs déjà en place — non ajouté à ce jour, nécessite un compte externe et une décision du mainteneur sur l'outillage souhaité.

## Explicitement hors périmètre pour l'instant

- Publication sur le Marketplace officiel GLPI — ce dépôt reste distribué via GitHub (clone ou release ZIP), voir [INSTALLATION.md](INSTALLATION.md).
