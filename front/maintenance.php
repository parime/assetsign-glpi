<?php

use GlpiPlugin\Assetsign\Maintenance;

Session::checkRight(Maintenance::$rightname, READ);

Html::header(Maintenance::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Maintenance::class);

Maintenance::showCreateForm();
Search::show(Maintenance::class);

Html::footer();
