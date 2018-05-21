<?php
/*
* Bitstorm 2 - A small and fast Bittorrent tracker
* Copyright 2011 Peter Caprioli
* Copyright 2013 Laurent Etiemble
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

 /*************************
 ** Configuration start **
 *************************/

require_once 'config.php';

 /***********************
 ** Configuration end **
 ***********************/

//Use the correct content-type
header("Content-type: Text/Plain");

//Connect to the MySQL server
$mysqli = new mysqli(__DB_SERVER, __DB_USERNAME, __DB_PASSWORD, __DB_DATABASE);
if ($mysqli->connect_errno) {
    printf("Connect failed: %s\n", $mysqli->error);
    exit();
}

//Inputs that are needed, do not continue without these
valdata('peer_id', true);
valdata('port');
valdata('info_hash', true);

//Make sure we have something to use as a key
if (!isset($_GET['key'])) {
	$_GET['key'] = '';
}

$downloaded = isset($_GET['uploaded']) ? intval($_GET['uploaded']) : 0;
$uploaded = isset($_GET['uploaded']) ? intval($_GET['uploaded']) : 0;
$left = isset($_GET['left']) ? intval($_GET['left']) : 0;

//Validate key as well
valdata('key');

//Do we have a valid client port?
if (!ctype_digit($_GET['port']) || $_GET['port'] < 1 || $_GET['port'] > 65535) {
	die(track('Invalid client port'));
}

//Hack to get comatibility with trackon
if ($_GET['port'] == 999 && substr($_GET['peer_id'], 0, 10) == '-TO0001-XX') {
	die("d8:completei0e10:incompletei0e8:intervali600e12:min intervali60e5:peersld2:ip12:72.14.194.184:port3:999ed2:ip11:72.14.194.14:port3:999ed2:ip12:72.14.194.654:port3:999eee");
}

$mysqli->query('INSERT INTO `peer` (`hash`, `user_agent`, `ip_address`, `key`, `port`) '
	. "VALUES ('" . $mysqli->real_escape_string(bin2hex($_GET['peer_id'])) . "', '" . $mysqli->real_escape_string(substr($_SERVER['HTTP_USER_AGENT'], 0, 80)) 
	. "', INET_ATON('" . $mysqli->real_escape_string($_SERVER['REMOTE_ADDR']) . "'), '" . $mysqli->real_escape_string(sha1($_GET['key'])) . "', " . intval($_GET['port']) . ") "
	. 'ON DUPLICATE KEY UPDATE `user_agent` = VALUES(`user_agent`), `ip_address` = VALUES(`ip_address`), `port` = VALUES(`port`), `id` = LAST_INSERT_ID(`peer`.`id`)') 
	or die(track('Cannot update peer: '.$mysqli->error));
$pk_peer = $mysqli->insert_id;

$mysqli->query("INSERT INTO `torrent` (`hash`) VALUES ('" . $mysqli->real_escape_string(bin2hex($_GET['info_hash'])) . "') "
 	. "ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)") or die(track('Cannot update torrent' . $mysqli->error)); // ON DUPLICATE KEY UPDATE is just to make $mysqli->insert_id work
$pk_torrent = $mysqli->insert_id;

//User agent is required
if (!isset($_SERVER['HTTP_USER_AGENT'])) {
	$_SERVER['HTTP_USER_AGENT'] = "N/A";
}
if (!isset($_GET['uploaded'])) {
	$_GET['uploaded'] = 0;
}
if (!isset($_GET['downloaded'])) {
	$_GET['downloaded'] = 0;
}
if (!isset($_GET['left'])) {
	$_GET['left'] = 0;
}

$mysqli->query('INSERT INTO `peer_torrent` (`peer_id`, `torrent_id`, `uploaded`, `downloaded`, `left`, `last_updated`) '
	. 'SELECT ' . $pk_peer . ', `torrent`.`id`, ' . intval($_GET['uploaded']) . ', ' . intval($_GET['downloaded']) . ', ' . intval($_GET['left']) . ', UTC_TIMESTAMP() '
	. 'FROM `torrent` '
	. "WHERE `torrent`.`hash` = '" . $mysqli->real_escape_string(bin2hex($_GET['info_hash'])) . "' "
	. 'ON DUPLICATE KEY UPDATE `uploaded` = VALUES(`uploaded`), `downloaded` = VALUES(`downloaded`), `left` = VALUES(`left`), `last_updated` = VALUES(`last_updated`), '
	. '`id` = LAST_INSERT_ID(`peer_torrent`.`id`)')
	or die(track($mysqli->error));
$pk_peer_torrent = $mysqli->insert_id;

//Did the client stop the torrent?
if (isset($_GET['event']) && $_GET['event'] === 'stopped') {
	$mysqli->query("UPDATE `peer_torrent` SET `stopped` = TRUE WHERE `id` = " . $pk_peer_torrent) or die (track($mysqli->error));
	die(track(array(), 0, 0)); //The RFC says its OK to return an empty string when stopping a torrent however some clients will whine about it so we return an empty dictionary
}

$numwant = __MAX_PPR; //Can be modified by client

//Set number of peers to return
if (isset($_GET['numwant']) && ctype_digit($_GET['numwant']) && $_GET['numwant'] <= __MAX_PPR && $_GET['numwant'] >= 0) {
	$numwant = (int)$_GET['numwant'];
}

$q = $mysqli->query('SELECT INET_NTOA(peer.ip_address), peer.port, peer.hash '
	. 'FROM peer_torrent '
	. 'JOIN peer ON peer.id = peer_torrent.peer_id '
	. 'WHERE peer_torrent.torrent_id = ' . $pk_torrent . ' AND peer_torrent.stopped = FALSE '
	. 'AND peer_torrent.last_updated >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . (__INTERVAL + __TIMEOUT) . ' SECOND) '
	. 'AND peer.id != ' . $pk_peer . ' '
	. 'ORDER BY RAND() '
	. 'LIMIT ' . $numwant) or die(track($mysqli->error));

$reply = array(); //To be encoded and sent to the client

while ($r = $q->fetch_array()) { //Runs for every client with the same infohash
	$reply[] = array($r[0], $r[1], $r[2]); //ip, port, peerid
}

$q = $mysqli->query('SELECT IFNULL(SUM(peer_torrent.left > 0), 0) AS leech, IFNULL(SUM(peer_torrent.left = 0), 0) AS seed '
	. 'FROM peer_torrent '
	. 'WHERE peer_torrent.torrent_id = ' . $pk_torrent . ' AND `peer_torrent`.`stopped` = FALSE '
	. 'AND peer_torrent.last_updated >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . (__INTERVAL + __TIMEOUT) . ' SECOND) '
	. 'GROUP BY `peer_torrent`.`torrent_id`') or die(track($mysqli->error));

$seeders = 0;
$leechers = 0;

if ($r = $q->fetch_array())
{
	$seeders = $r[1];
	$leechers = $r[0];
}

die(track($reply, $seeders[0], $leechers[0]));

//Bencoding function, returns a bencoded dictionary
//You may go ahead and enter custom keys in the dictionary in
//this function if you'd like.
function track($list, $c=0, $i=0) {
	if (is_string($list)) { //Did we get a string? Return an error to the client
		return 'd14:failure reason'.strlen($list).':'.$list.'e';
	}
	$p = ''; //Peer directory
	foreach($list as $d) { //Runs for each client
		$pid = '';
		if (!isset($_GET['no_peer_id'])) { //Send out peer_ids in the reply
			$real_id = bshex2bin($d[2]);
			$pid = '7:peer id'.strlen($real_id).':'.$real_id;
		}
		$p .= 'd2:ip'.strlen($d[0]).':'.$d[0].$pid.'4:porti'.$d[1].'ee';
	}
	//Add some other paramters in the dictionary and merge with peer list
	$r = 'd8:intervali'.__INTERVAL.'e12:min intervali'.__INTERVAL_MIN.'e8:completei'.$c.'e10:incompletei'.$i.'e5:peersl'.$p.'ee';
	return $r;
}

//Do some input validation
function valdata($g, $fixed_size=false) {
	if (!isset($_GET[$g])) {
		die(track('Invalid request, missing data'));
	}
	if (!is_string($_GET[$g])) {
		die(track('Invalid request, unknown data type'));
	}
	if ($fixed_size && strlen($_GET[$g]) != 20) {
		die(track('Invalid request, length on fixed argument not correct'));
	}
	if (strlen($_GET[$g]) > 80) { //128 chars should really be enough
		die(track('Request too long'));
	}
}

function bshex2bin($hex) {
	$r = '';
	for ($i=0; $i < strlen($hex); $i+=2) {
		$r .= chr(hexdec($hex{$i}.$hex{($i+1)}));
	}
	return $r;
}
?>
