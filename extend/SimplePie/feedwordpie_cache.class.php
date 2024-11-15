<?php
$subdir = dirname( __FILE__ );
$ver = SIMPLEPIE_VERSION;
$mod = basename( __FILE__ );

if ( class_exists( 'SimplePie\\Cache' ) ) {
    $modClassPath = "{$subdir}/default/{$mod}";
}
else {
    $modClassPath = "{$subdir}/SimplePie_Cache/{$mod}";
}

require_once "{$modClassPath}";
