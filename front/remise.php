<?php

use GlpiPlugin\Remise\Remise;

Session::checkRight(Remise::$rightname, READ);

Html::header(Remise::getTypeName(2), $_SERVER['PHP_SELF'], 'admin', Remise::class);

Search::show(Remise::class);

Html::footer();
