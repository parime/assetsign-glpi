<?php

use GlpiPlugin\Remise\Remise;

Session::checkRight(Remise::$rightname, UPDATE);

if (!isset($_POST['assign'])) {
    Html::displayNotFoundError();
}

$itemtype = (string) ($_POST['itemtype'] ?? '');
$items_id = (int) ($_POST['items_id'] ?? 0);
$users_id = (int) ($_POST['users_id'] ?? 0);

if (!is_subclass_of($itemtype, CommonDBTM::class)) {
    Html::displayNotFoundError();
}

$item = new $itemtype();
// !can($items_id, UPDATE) : meme garde-fou de segregation par entite que
// Remise::createManual()/Maintenance::createWithChecklist() (cf. TROUBLESHOOTING.md) -
// le droit generique Remise::$rightname verifie ci-dessus n'est jamais
// restreint par entite a lui seul.
if (!$item->getFromDB($items_id) || !$item->can($items_id, UPDATE)) {
    Html::displayNotFoundError();
}

// La mise a jour de users_id declenche automatiquement le hook natif
// plugin_remise_item_assignment() (cf. hook.php) exactement comme si le
// technicien avait modifie le champ "Utilisateur" depuis la fiche du
// materiel : aucune logique supplementaire necessaire ici pour creer la
// remise. Si users_id ne change pas reellement (deja assigne a cette
// personne), le hook ne cree rien (il ne reagit qu'a un changement reel de
// valeur, cf. Remise::handleUserBasedTrigger()).
$item->update(['id' => $items_id, 'users_id' => $users_id]);

Session::addMessageAfterRedirect(__('Matériel assigné.', 'remise'));
Html::back();
