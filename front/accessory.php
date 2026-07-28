<?php

use GlpiPlugin\Remise\Accessory;

Session::checkRight(Accessory::$rightname, READ);

Html::header(Accessory::getTypeName(2), $_SERVER['PHP_SELF'], 'admin', Accessory::class);

Search::show(Accessory::class);

Html::footer();
