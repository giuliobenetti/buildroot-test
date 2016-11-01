<?php
include(dirname(__FILE__) . "/../web/config.inc.php");
include(dirname(__FILE__) . "/../web/db.inc.php");

function parse_config($path) {
	$opts_set = array();
	$opts_unset = array();

	$configf = fopen($path, "r");
	while (!feof($configf)) {
		$line = trim(fgets($configf));
		if (!strncmp($line, "BR2_", strlen("BR2_"))) {
			preg_match("/(BR2_[a-zA-Z0-9_]*)=(.*)/", $line, $matches);
			$name = $matches[1];
			$value = str_replace('"', '', $matches[2]);
			$opts_set[$name] = $value;
		}
		else if (!strncmp($line, "# BR2_", strlen("# BR2_"))) {
			preg_match("/# (BR2_[a-zA-Z0-9_]*) is not set/", $line, $matches);
			$name = $matches[1];
			$opts_unset[$name] = "";
		}
	}

	fclose($configf);

	return array($opts_set, $opts_unset);
}

function insert_all_symbols($db, $opts)
{
	$sql = "insert ignore into config_symbol (name, value) values ";

	$opts_array = array_map(function($k, $v) {
			return "(\"" . $k . "\", \"" . $v . "\")";
		}, array_keys($opts), $opts);

	$sql .= join(",", $opts_array) . ";";

	$db->query($sql);
}

function get_all_symbols_id($db, $opts)
{
	$sql = "select id from config_symbol where ";

	$opts_array = array_map(function($k, $v) {
			return "(name=\"" . $k . "\" and value=\"" . $v . "\")";
		}, array_keys($opts), $opts);

	$sql .= join(" or ", $opts_array) . ";";

	$result = $db->query($sql);

	$symbolids = array();
	while($row = mysql_fetch_array($result)) {
		array_push($symbolids, $row["id"]);
	}

	return $symbolids;
}

function insert_symbol_result($db, $resultid, $symbolsid)
{
	$sql = "insert into symbol_per_result (result_id, symbol_id) values ";
	$symarray = array_map(function($v) use ($resultid) {
			return "(" . $resultid . "," . $v . ")";
		}, $symbolsid);
	$sql .= join(",", $symarray) . ";";
	$db->query($sql);
}

function insert_config($db, $resultid, $opts_set)
{
	/* Only keep the options with non-empty values */
	$opts = array_filter($opts_set, function($v) {
			return $v != "";
		});
	insert_all_symbols($db, $opts);
	$symbolsid = get_all_symbols_id($db, $opts);
	insert_symbol_result($db, $resultid, $symbolsid);
}

function import_result($buildid, $filename)
{
    global $maindir;
    $buildresultdir = $maindir . "/results/";
    $tmpbuildresultdir = $buildresultdir . "tmp/";

    echo "Importing $buildid from $filename\n";

    $finalbuildresultdir = $buildresultdir . "/" . substr($buildid, 0, 3) . "/";

    /* Check that we don't have a build result with the same SHA1 */
    if (file_exists($finalbuildresultdir . $buildid)) {
      echo "We already have a build result with the same SHA1, sorry.\n";
      return;
    }

    /* Create the temporary directory where the tarball will be
       extracted */
    $thisbuildtmpdir = $tmpbuildresultdir . $buildid . "/";
    if (! mkdir($thisbuildtmpdir)) {
      echo "Cannot create temporary directory.\n";
      return;
    }

    /* Extract the tarball into the temporary directory */
    $tarcmd = "tar -C " . $thisbuildtmpdir . " --strip-components=1 -xf " . $filename;
    system($tarcmd, $retval);
    if ($retval != 0) {
      echo "Unable to uncompress build report file\n";
      return;
    }

    /* Perform some tests */
    if (! file_exists($thisbuildtmpdir . "status") ||
	! file_exists($thisbuildtmpdir . "gitid")  ||
	! file_exists($thisbuildtmpdir . "build-end.log") ||
	! file_exists($thisbuildtmpdir . "config") ||
	! file_exists($thisbuildtmpdir . "submitter")) {
      system("rm -rf " . $thisbuildtmpdir);
      echo "Invalid contents of the build report file\n";
      return;
    }

    system("grep -q 'fork: Cannot allocate memory' " . $thisbuildtmpdir . "build-end.log",
	   $retval);
    if ($retval == 0) {
      echo "Reject build result, there was a memory allocation problem\n";
      return;
    }

    /* Remove the build.log.bz2 file if it's in there */
    system("rm -f " . $thisbuildtmpdir . "build.log.bz2", $retval);

    /* Create the 'results/xyz/' directory if it doesn't already
       exists */
    if (! file_exists($finalbuildresultdir)) {
      if (! mkdir($finalbuildresultdir)) {
	system("rm -rf " . $thisbuildtmpdir);
	echo "Cannot create final output directory.\n";
	return;
      }
    }

    /* Move to the final location */
    echo "mv " . $thisbuildtmpdir . " " . $finalbuildresultdir . "\n";
    system("mv " . $thisbuildtmpdir . " " . $finalbuildresultdir, $retval);
    if ($retval != 0) {
      system("rm -rf " . $thisbuildtmpdir);
      echo "Unable to move build results to the final location";
      return;
    }

    $thisbuildfinaldir = $finalbuildresultdir . "/" . $buildid . "/";

    /* Get the status */
    $status_str = trim(file_get_contents($thisbuildfinaldir . "status", "r"));
    if ($status_str == "OK")
      $status = 0;
    else if ($status_str == "NOK")
      $status = 1;
    else if ($status_str == "TIMEOUT")
      $status = 2;

    /* Get the build date (use the mtime of the status file */
    $status_stat = stat($thisbuildfinaldir . "status");
    $builddate = strftime("%Y-%m-%d %H:%M:%S", $status_stat['mtime']);

    /* Get submitter and commitid */
    $submitter  = trim(file_get_contents($thisbuildfinaldir . "submitter", "r"));
    $commitid  = trim(file_get_contents($thisbuildfinaldir . "gitid", "r"));

    list($opts_set, $opts_unset) = parse_config($thisbuildfinaldir . "config");

    $arch = $opts_set["BR2_ARCH"];
    $subarch = "";

    if (array_key_exists("BR2_GCC_TARGET_CPU", $opts_set))
	    $subarch = $opts_set["BR2_GCC_TARGET_CPU"];
    else if (array_key_exists("BR2_GCC_TARGET_ARCH", $opts_set))
	    $subarch = $opts_set["BR2_GCC_TARGET_ARCH"];

    $found_libc = "";
    foreach (array("glibc", "uclibc", "musl") as $libc) {
	    if (array_key_exists("BR2_TOOLCHAIN_USES_" . strtoupper($libc), $opts_set)) {
		    $found_libc = $libc;
		    break;
	    }
    }

    $static = 0;
    if (array_key_exists("BR2_STATIC_LIBS", $opts_set))
	    $static = 1;

    if ($status == 0)
      $reason = "none";
    else {
	$tmp = Array();
	exec("tail -3 " . $thisbuildfinaldir . "build-end.log | grep -v '\[_all\]' | grep 'make.*: \*\*\*' | sed 's,.*\[\([^\]*\)\] Error.*,\\1,' | sed 's,.*/build/\([^/]*\)/.*,\\1,'", $tmp);
	if (trim($tmp[0]))
	  $reason = $tmp[0];
	else
	  $reason = "unknown";
    }

    $db = new db();

    /* Insert into the database */
    $sql = "insert into results (status, builddate, submitter, commitid, identifier, arch, reason, libc, static, subarch) values (" .
      $db->quote_smart($status) . "," .
      $db->quote_smart($builddate) . "," .
      $db->quote_smart($submitter) . "," .
      $db->quote_smart($commitid) . "," .
      $db->quote_smart($buildid) . "," .
      $db->quote_smart($arch) . "," .
      $db->quote_smart($reason) . "," .
      $db->quote_smart($found_libc) . "," .
      $db->quote_smart($static) . "," .
      $db->quote_smart($subarch) .
    ")";

    $ret = $db->query($sql);
    if ($ret == FALSE) {
      echo "Couldn't register result in DB\n";
      system("rm -rf " . $thisbuildfinaldir);
      return;
    }

    $resultdbid = $db->insertid();

    $sql = "insert into results_config (resultid, isset, name, value) values\n";

    foreach ($opts_set as $k => $v) {
	$sql .= "(" .
	  $db->quote_smart($resultdbid) . "," .
	  "1," .
	  $db->quote_smart($k) . "," .
	  $db->quote_smart($v) .
	  "),\n";
    }

    foreach ($opts_unset as $k) {
	$sql .= "(" .
	  $db->quote_smart($resultdbid) . "," .
	  "0," .
	  $db->quote_smart($k) . "," .
	  "''" .
	  "),\n";
    }

    $sql[strlen($sql)-2] = ';';

    $ret = $db->query($sql);
    if ($ret == FALSE) {
      echo "Couldn't register result config line $line in DB\n";
      $db->query("delete from results where id=$resultdbid");
      $db->query("delete from results_config where resultid=$resultdbid");
      return;
    }

    insert_config($db, $resultdbid, $opts_set);

    echo "Build result accepted. Thanks!";
}

?>