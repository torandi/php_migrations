#!/usr/bin/php
<?php
require "color_terminal.php";

$file_dir = realpath(dirname($argv[0]));

if(file_exists("$file_dir/config.php")) {
	require "$file_dir/config.php";
} else if(file_exists(dirname(__FILE__)."/config.php")) {
	$file_dir = dirname(__FILE__);
	require "$file_dir/config.php";
} else {
	die("Please create config.php. You can look at config-example.php for ideas.\n");
}

$ignored_files = array(
	'^\..*',                        /* skip hidden files */
	'(?<!\.(php|sql))$',            /* everything not .php or .sql */
	'^(update_database|create_migration|config(-example)?|color_terminal)\.php$',
);

/* append project-wide ignores */
if ( is_callable(array('Config', 'ignored')) ){
	$ignored_files = array_merge($ignored_files, Config::ignored());
}

function usage() {
	global $argv;
	echo "Usage: ".$argv[0]." [options] <username>\n";
	echo "Username may be optional, depending on your config file.\n";
	echo "Options:\n";
	echo "\t --check (-c): Checks if there are migrations to run, won't run any migrations.\n";
	echo "\t --help (-h): Show this text.\n";
	die();
}

if($argc > 2) {
	usage();
}

$check_only = false; /* True if we should only check if there are migrations to run */
$username = null;

if(isset($argv[1])) {
	if($argv[1] == "--help" || $argv[1] == '-h') {
		usage();
	} else if($argv[1] == "--check" || $argv[1] == '-c') {
		$check_only = true;
		if(isset($argv[2])) {
			$username = $argv[2];
		}
	} else {
		$username = $argv[1];
	}
}

function ask_for_password() {
	echo "Password: ";
	flush();
	system('stty -echo');
	$password = trim(fgets(STDIN));
	system('stty echo');
	echo "\n";
	return $password;
}

try {
	$db = Config::fix_database($username);
} catch(Exception $e) {
	die("fix_database misslyckades. Exception: ".$e->getMessage()."\n");
}

if($check_only) {
	$count = 0;
	foreach(migration_list() as $version => $file) {
		if(!migration_applied($version)) ++$count;
	}

	if($count > 0) {
		echo "There are $count new migration(s) to run\n";
	} else {
		echo "Database up-to-date\n";
	}
	exit($count);
}

create_migration_table_if_not_exists();

$db->autocommit(FALSE);

run_hook("begin");

foreach(migration_list() as $version => $file) {
	if(!migration_applied($version)) {
		run_migration($version,$file);
	}
}

ColorTerminal::set("green");
echo "All migrations completed\n";
ColorTerminal::set("normal");

run_hook("end");


$db->close();

function is_ignored($filename, &$match){
	global $ignored_files;

	/* skip files in ignore list */
	foreach ( $ignored_files as $pattern ){
		if ( preg_match("/$pattern/", $filename) ){
			$match = $pattern;
			return true;
		}
	}

	return false;
}

/**
 * Creates a hash :migration_version => file_name
 */
function migration_list() {
	global $file_dir;
	$dir = opendir($file_dir);
	$files = array();
	while($f = readdir()) {
		if ( is_ignored($f, $match) ) continue;

		$files[get_version($f)] = $f;
	}
	ksort($files);
	closedir($dir);
	return $files;
}

function get_version($file) {
	return $file;
}

function manual_step_confirm() {
	$ans = '';
	while($ans != 'yes') {
		echo("Please type 'yes' to manual_step_confirm you have completed the step above, or quit with ctrl+c: ");
		flush();
		$ans = trim(fgets(STDIN));
		echo("\n");
		flush();
	}
}


/**
 * Runs the migration
 */
function run_migration($version, $filename) {
	global $db, $file_dir;
	try {
		$ext = pathinfo($filename,  PATHINFO_EXTENSION);

		run_hook("pre_migration", $filename);

		ColorTerminal::set("blue");
		echo "============= BEGIN $filename =============\n";
		ColorTerminal::set("normal");
		if(filesize("$file_dir/$filename") == 0) {
			ColorTerminal::set("red");
			echo "$filename is empty. Migrations aborted\n";
			ColorTerminal::set("normal");
			exit;
		}
		switch($ext) {
			case "php":
				echo "Parser: PHP\n";
				{
					require "$file_dir/$filename";
				}
				break;
			case "sql":
				echo "Parser: MySQL\n";
				$queries = preg_split("/;[[:space:]]*\n/",file_contents("$file_dir/$filename"));
				foreach($queries as $q) {
					$q = trim($q);
					if($q != "") {
						echo "$q\n";
						$ar=run_sql($q);
						echo "Affected rows: $ar \n";
					}
				}
				break;
			default:
				ColorTerminal::set("red");
				echo "Unknown extention: $ext\n";
				echo "All following migrations aborted\n";
				$db->rollback();
				ColorTerminal::set("normal");
				exit;
		}
		//Add migration to schema_migrations:
		run_sql("INSERT INTO `schema_migrations` (`version`) VALUES ('$version');");

		$db->commit();
		ColorTerminal::set("green");
		echo "Migration complete\n";
		echo "============= END $filename =============\n";
		ColorTerminal::set("normal");

		run_hook("post_migration", $filename);

	} catch (Exception $e) {
		ColorTerminal::set("red");
		if($e instanceof QueryException) {
			echo "Error in migration $filename:\n".$e->getMessage()."\n";
		} else {
			echo "Error in migration $filename:\n".$e."\n";
		}
		ColorTerminal::set("lightred");
		echo "============= ROLLBACK  =============\n";
		$db->rollback();
		echo "All following migrations aborted\n";
		ColorTerminal::set("normal");

		run_hook("post_rollback", $filename);

		exit;
	}
}

/**
 * Returns true if the specified version is applied
 */
function migration_applied($version) {
	global $db;
	$stmt = $db->prepare("SELECT 1 FROM `schema_migrations` WHERE `version` = ?");
	$stmt->bind_param('s', $version);
	$stmt->execute();
	$res = $stmt->fetch();
	$stmt->close();
	return $res;
}

/**
 * Helper funcition for migration scripts
 */
function migration_sql($query) {
	echo $query."\n";
	echo "Affected rows: ".run_sql($query)."\n";
}

/**
 * Runs sql query or throw exception
 */
function run_sql($query) {
	global $db;
	$status = $db->query($query);
	if($status == false) {
		throw new QueryException("Query failed: ".$db->error);
	}
	$affected_rows = $db->affected_rows;
	return $affected_rows;
}

function file_contents($filename) {
	$handle = fopen($filename, "r");
	$contents = fread($handle, filesize($filename));
	fclose($handle);
	return $contents;
}

function run_hook($hook, $arg = null) {
	$hook_method = $hook . "_hook";
	if ( is_callable(array('Config', $hook_method)) ){
		if($arg == null) {
			call_user_func("Config::" . $hook_method);
		} else {
			call_user_func("Config::" . $hook_method, $arg);
		}
	}
}

/**
 * Creates the migration table if is does not exist
 */
function create_migration_table_if_not_exists() {
	global $db;
	run_sql("CREATE TABLE IF NOT EXISTS `schema_migrations` (
		`version` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		`timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY `unique_schema_migrations` (`version`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci; ");
}

class QueryException extends Exception{
	public function __construct($message) {
		$this->message = $message;
	}
}
?>
