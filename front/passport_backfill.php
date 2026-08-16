<?php

use GlpiPlugin\Assetsign\Api\PassportBackfillController;
use GlpiPlugin\Assetsign\PassportEvent;

Session::checkRight(PassportEvent::$rightname, UPDATE);

$controller = new PassportBackfillController();

try {
   if (isset($_POST['itemtype'], $_POST['items_id'])) {
       Session::addMessageAfterRedirect($controller->runForItem($_POST));
   } else if (isset($_POST['users_id'])) {
       Session::addMessageAfterRedirect($controller->runForUser($_POST));
   }
} catch (\Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}

Html::back();
