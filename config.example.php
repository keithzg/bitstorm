<?php
//MySQL details
define('__DB_SERVER', '');
define('__DB_USERNAME', '');
define('__DB_PASSWORD', '');
define('__DB_DATABASE', '');

//Peer announce interval (Seconds)
define('__INTERVAL', 1800);

//Time out if peer is this late to re-announce (Seconds)
define('__TIMEOUT', 120);

//Minimum announce interval (Seconds)
//Most clients obey this, but not all
define('__INTERVAL_MIN', 60);

// By default, never encode more than this number of peers in a single request
define('__MAX_PPR', 20);
?>
