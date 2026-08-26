<?php

use GlpiPlugin\Assetsign\Api\ResidualValueFormController;
use GlpiPlugin\Assetsign\PassportEvent;

Session::checkRight(PassportEvent::$rightname, UPDATE);

try {
   Session::addMessageAfterRedirect((new ResidualValueFormController())->run($_POST));
} catch (\Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}

Html::back();
