<?php

use GlpiPlugin\Assetsign\Assetsign;

Session::checkRight(Assetsign::$rightname, READ);

// L'absence de colonne locations_id sur glpi_plugin_assetsign_assetsigns masque deja
// le bouton "Afficher sur une carte" (CommonDBTM::maybeLocated() verifie juste
// sa presence), mais SQLProvider::buildSelect() honore quand meme le parametre
// as_map sans verifier cette capacite — un lien construit a la main, ou un
// critere de recherche deja bloque en session (ce qui s'est reellement produit
// chez un utilisateur), plante toujours la page en 500 (jointure
// glpi_locations jamais ajoutee). Neutralise ici, avant que Search::show() ne
// lise quoi que ce soit.
unset($_GET['as_map'], $_POST['as_map'], $_REQUEST['as_map']);

Html::header(Assetsign::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Assetsign::class);

Search::show(Assetsign::class);

Html::footer();
