<?php
require "sigsci.php";
$sigsci = new SigSciModuleSimple();
if ($sigsci->block()) {
    // set http response and exit
    http_response_code(406);
    echo "BLOCKED\n";
    exit();
}

?>

Hello World
