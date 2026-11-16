<?php

/**
 * -------------------------------------------------------------------------
 * AssetSign for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/assetsign-glpi
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

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
