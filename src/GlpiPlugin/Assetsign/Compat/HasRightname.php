<?php

// Declare le trait GlpiPlugin\Assetsign\Compat\HasRightname dans la variante
// adaptee a la version de GLPI (cf. GlpiVersion). Chaque classe qui l'utilise
// fournit sa propre valeur via la constante RIGHTNAME.
require_once \GlpiPlugin\Assetsign\Compat\GlpiVersion::compatDir() . '/HasRightname.php';
