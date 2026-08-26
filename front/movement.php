<?php

use GlpiPlugin\Assetsign\Movement;

Session::checkRight(Movement::$rightname, READ);

Html::header(Movement::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Movement::class);

Movement::showCreateForm();
Search::show(Movement::class);

Html::footer();
