<?php 
/*
 * Bitstorm 2 - A small and fast Bittorrent tracker
 * Copyright 2011 Inpun LLC
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
    printf("Connect failed: %s\n", $mysqli->connect_errno);
    exit();
}

//Inputs that are needed, do not continue without these
valdata('info_hash', true);
$q = mysqli_query($mysqli, 'SELECT IFNULL(SUM(peer_torrent.left > 0), 0) AS leech, IFNULL(SUM(peer_torrent.left = 0), 0) AS seed '
		. 'FROM peer_torrent join torrent on peer_torrent.torrent_id = torrent.id '
		. "WHERE torrent.hash = '" . mysqli_real_escape_string($mysqli, bin2hex($_GET['info_hash'])) . "' AND peer_torrent.state != 'stopped' "
		. 'AND peer_torrent.last_updated >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . (__INTERVAL + __TIMEOUT) . ' SECOND) '
		. 'GROUP BY `peer_torrent`.`torrent_id`') or die(mysqli_error($mysqli));
$seeders = 0;
$leechers = 0;
if ($r = mysqli_fetch_array($q)) {
	$seeders = $r[1];
	$leechers = $r[0];
}
$q = mysqli_query($mysqli, 'SELECT count(*) '
		. 'FROM peer_torrent join torrent on peer_torrent.torrent_id = torrent.id '
		. "WHERE torrent.hash = '" . mysqli_real_escape_string(bin2hex($_GET['info_hash']))
		. "' AND peer_torrent.state = 'completed'") or die(mysqli_error($mysqli));
$complete = 0;
if ($r = mysqli_fetch_array($q)) {
	$complete = $r[0];
}
die(track(intval($complete[0]), $seeders[0], $leechers[0], $_GET['info_hash']));
		
function track($x, $s=0, $l=0, $info_hash) {
	if (is_string($x)) { //Did we get a string? Return an error to the client
		return 'd14:failure reason'.strlen($x).':'.$x.'e';
	}
	// based on https://wiki.theory.org/BitTorrentSpecification#Tracker_.27scrape.27_Convention
	$r = 'd5:filesd' . strlen($info_hash) . ':' . $info_hash . 'd8:completei' . $s . 'e10:downloadedi' . $x . 'e10:incompletei' . $l . 'eeee';
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
?>