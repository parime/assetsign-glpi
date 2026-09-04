<?php

use GlpiPlugin\Assetsign\Api\EnvironmentalDataFormController;
use GlpiPlugin\Assetsign\PassportEvent;

Session::checkRight(PassportEvent::$rightname, UPDATE);

try {
   Session::addMessageAfterRedirect((new EnvironmentalDataFormController())->run($_POST));
} catch (\Throwable $e) {
    Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
}

Html::back();
