<?php

use GlpiPlugin\Assetsign\Assetsign;

Session::checkRight(Assetsign::$rightname, UPDATE);

if (!isset($_POST['assign'])) {
    Html::displayNotFoundError();
}

$itemtype = (string) ($_POST['itemtype'] ?? '');
$items_id = (int) ($_POST['items_id'] ?? 0);
$users_id = (int) ($_POST['users_id'] ?? 0);

if (!is_subclass_of($itemtype, CommonDBTM::class)) {
    Html::displayNotFoundError();
}

// Faux positif deja revu (cf. ARCHITECTURE.md) : is_subclass_of() ci-dessus restreint deja
// l'instanciation a la famille GLPI CommonDBTM, et can($items_id, UPDATE) juste en dessous
// encadre tout acces aux donnees de l'objet instancie - meme motif que Assetsign::createManual().
$item = new $itemtype(); // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation
// !can($items_id, UPDATE) : meme garde-fou de segregation par entite que
// Assetsign::createManual()/Maintenance::createWithChecklist() (cf. TROUBLESHOOTING.md) -
// le droit generique Assetsign::$rightname verifie ci-dessus n'est jamais
// restreint par entite a lui seul.
if (!$item->getFromDB($items_id) || !$item->can($items_id, UPDATE)) {
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
