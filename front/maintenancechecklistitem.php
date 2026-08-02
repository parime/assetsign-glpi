<?php

use GlpiPlugin\Remise\MaintenanceChecklistItem;

Session::checkRight(MaintenanceChecklistItem::$rightname, READ);

Html::header(MaintenanceChecklistItem::getTypeName(2), $_SERVER['PHP_SELF'], 'admin', MaintenanceChecklistItem::class);

Search::show(MaintenanceChecklistItem::class);

Html::footer();
