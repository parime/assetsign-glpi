<?php

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Security\AssetAssignmentGuard;

Session::checkRight(Assetsign::$rightname, UPDATE);

if (!isset($_POST['assign'])) {
    Html::displayNotFoundError();
}

$itemtype = (string) ($_POST['itemtype'] ?? '');
$items_id = (int) ($_POST['items_id'] ?? 0);
$users_id = (int) ($_POST['users_id'] ?? 0);

// AssetAssignmentGuard::resolveAssignableItem() encadre l'itemtype (famille CommonDBTM
// uniquement) et la segregation par entite (can($items_id, UPDATE)) - cf. son propre docblock,
// meme garde-fou que Assetsign::createManual()/Maintenance::createWithChecklist().
$item = (new AssetAssignmentGuard())->resolveAssignableItem($itemtype, $items_id);
if ($item === null) {
    Html::displayNotFoundError();
}

// La mise a jour de users_id declenche automatiquement le hook natif
// plugin_assetsign_item_assignment() (cf. hook.php) exactement comme si le
// technicien avait modifie le champ "Utilisateur" depuis la fiche du
// materiel : aucune logique supplementaire necessaire ici pour creer la
// assetsign. Si users_id ne change pas reellement (deja assigne a cette
// personne), le hook ne cree rien (il ne reagit qu'a un changement reel de
// valeur, cf. Assetsign::handleUserBasedTrigger()).
$item->update(['id' => $items_id, 'users_id' => $users_id]);

Session::addMessageAfterRedirect(__('Matériel assigné.', 'assetsign'));
Html::back();
