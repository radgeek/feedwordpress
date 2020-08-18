<?php
$subdir = dirname(__FILE__);
$ver = SIMPLEPIE_VERSION;
$mod = basename(__FILE__);

if ( is_readable("${subdir}/${ver}/${mod}") ) :
    $modClassPath = "${subdir}/${ver}/${mod}";
else :
    $modClassPath = "${subdir}/default/${mod}";
endif;

require_once("${modClassPath}");
