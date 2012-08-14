<?
/*
    Copyright 2012, Jeff Sharkey

    Licensed under the Apache License, Version 2.0 (the "License");
    you may not use this file except in compliance with the License.
    You may obtain a copy of the License at

        http://www.apache.org/licenses/LICENSE-2.0

    Unless required by applicable law or agreed to in writing, software
    distributed under the License is distributed on an "AS IS" BASIS,
    WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
    See the License for the specific language governing permissions and
    limitations under the License.
*/


// Script that drives icecast radio station by picking next
// track dynamically.  Ready to be used with ices2.


include("getid3/getid3/getid3.php");
$id3 = new getID3;

$path = "/home/jsharkey/Desktop/xmitr/music/";

mysql_connect("localhost", "root", "");
mysql_select_db("xmitr");


// Scan path for new music.
function scanmusic($match = null, $now = null) {
	global $path, $id3;
	$list = shell_exec("find $path |grep .mp3");
	$list = explode("\n", $list);
	if($now == null) $now = time();

	echo "\nsearching for new music...";

	foreach($list as $file) {
		if(empty($file)) continue;
		$filesql = mysql_real_escape_string($file);

		if($match != null)
			if(!strstr($filesql, $match))
				continue;

		// check to see if path already exists
		$result = mysql_query("SELECT ADDED FROM track WHERE PATH = \"$filesql\"") or die(mysql_error());
		if(mysql_num_rows($result) > 0) continue;

		// try filling details with id3 tags
		$details = $id3->analyze($file);
		if(array_key_exists("error", $details)) continue;
		$artist = mysql_real_escape_string($details["tags"]["id3v2"]["artist"][0]);
		$album = mysql_real_escape_string($details["tags"]["id3v2"]["album"][0]);
		$track = mysql_real_escape_string($details["tags"]["id3v2"]["title"][0]);
		$length = round($details["playtime_seconds"]+0);

		echo "\nadding $artist - $album - $track";
		mysql_query("INSERT INTO track VALUES (\"$artist\", \"$album\", \"$track\", $length, \"$file\", $now, 0)") or die(mysql_error());
	}

	echo "\ndone!\n\n";
}


// Pick next track to play.  Based on combination of how recently track
// was added, how recently it was last played, and some randomness.
function picktrack() {
	// only consider tracks newer than 3 months, and not played in last hour
	$result = mysql_query("SELECT * FROM track WHERE ADDED > UNIX_TIMESTAMP(NOW() - INTERVAL 3 MONTH) AND PLAYED < UNIX_TIMESTAMP(NOW() - INTERVAL 1 HOUR)") or die(mysql_error);

	// score each of these tracks
	$now = time();
	$day = 60*60*24;
	$best = null;
	$bestscore = -1;
	while($row = mysql_fetch_assoc($result)) {
		$path = $row["PATH"];
		$added = $now - $row["ADDED"];
		$played = $now - $row["PLAYED"];
		$artist = $row["ARTIST"];
		$track = $row["TRACK"];

		$added /= $day;
		$played /= $day;

		$added = 1/$added;
		$played = $played/90;
		$random = rand(1,100)/100;

		// high added values are bad
		// high played values are good

		$score = 1;
		$score *= pow($added, 0.03);
		$score *= pow($played, 1);
		$score *= pow($random, 1);

		$added = round($added, 4);
		$played = round($played, 4);
		$score = round($score, 4);

		if($score > $bestscore) {
			$best = array($added, $played, $random, $score, "$artist - $track", $path);
			$bestscore = $score;
		}

	}

	return $best;
}

?>
