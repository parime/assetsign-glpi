<?php

use GlpiPlugin\Remise\Remise;

Session::checkRight(Remise::$rightname, READ);

// Remise::maybeLocated() masque deja le bouton "Afficher sur une carte" (cf.
// son commentaire), mais SQLProvider::buildSelect() honore quand meme le
// parametre as_map sans verifier cette capacite — un lien construit a la
// main, ou un critere de recherche deja bloque en session (ce qui s'est
// reellement produit chez un utilisateur), plante toujours la page en 500
// (jointure glpi_locations jamais ajoutee). Neutralise ici, avant que
// Search::show() ne lise quoi que ce soit.
unset($_GET['as_map'], $_POST['as_map'], $_REQUEST['as_map']);

Html::header(Remise::getTypeName(2), $_SERVER['PHP_SELF'], 'admin', Remise::class);

Search::show(Remise::class);

Html::footer();
