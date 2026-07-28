<?php

use GlpiPlugin\Remise\Template;

Session::checkRight(Template::$rightname, READ);

Html::header(Template::getTypeName(2), $_SERVER['PHP_SELF'], 'admin', Template::class);

Search::show(Template::class);

Html::footer();
