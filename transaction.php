<?php


# functions for handling transactions (primarily starting and stopping them)
# We check the PHP_SELF variable and behave according.  (This design assumes
# appropriate aliases exist in the httpd.conf file...)
#
# Aliases that this script responds to:
#	start_transaction
#	stop_transaction
#

require_once 'ini_file.php';
require_once 'php_functions.php';
require_once 'db_functions.php';


class FilesystemException extends Exception {};
class TransactionException extends Exception {};

# Encabsulates the logic for choosing a new (and unique) directory name
# for each transcation.  Creates the directory and returns the name
function create_trans_directory() {
	// We'll form the directory name from the user name and the unix time
	// (We'll perform an existance check and add a suffix to the name if we
	// need to.  This will protect us if we ever create more than one
	// transaction per second)
	$baseDirName = DATA_FILE_ROOT . DIRECTORY_SEPARATOR . $_SERVER['PHP_AUTH_USER'] . '-' .time();
	$suffix = 1;
	$dirName = $baseDirName;
	while (file_exists( $dirName)) {
		$dirName = 	$baseDirName . '-' . $suffix;
		$suffix++;
	}
	
	// Create the directory
	if (! mkdir ($dirName)) {
		// Makedir failed?!?
		throw new FilesystemException( "Failed to create directory: $dirName");
	}
	
 	return $dirName;
}


# Performs the functions related to creating new transactions: creates
# a directory for the transaction, adds the appropriate entry to the 
# database table.
# Returns an array with 2 entries: transId and dirName
function start_transaction() {
	$pdo = open_db();
	$dirName = create_trans_directory();
	$transId = add_transaction( $pdo, $_SERVER['PHP_AUTH_USER'], $dirName);
	return array( 'transId' => $transId, 'dirName' => $dirName);
}


# Performs the equivalent of 'rm -rf'.  Be careful with this!
function recursive_rm( $dirName) {
	$contents = scandir( $dirName);
	if ($contents) {
		foreach ($contents as $entry) {
			if (($entry == '.') || ($entry == '..')) {
				continue;  // trying to recursively delete .. would cause a lot of problems...
			}
			$fullPathName = $dirName . DIRECTORY_SEPARATOR . $entry;
			if (is_dir( $fullPathName)) {
				recursive_rm ( $fullPathName);
			} else {
				if (unlink ( $fullPathName) == false) {
					# TODO: What do we do if the file can't be deleted?!?
				}
			}
		}
	}
	
	if ( rmdir( $dirName) == false) {
		# TODO: What do we do if the directory can't be deleted?!?
	}
}

# Performs the functions related to ending a transaction: removes the
# relevant rows from the db tables, deletes the directory and all its
# contents
function stop_transaction($transId) {
	$pdo = open_db();
	if (check_transaction( $pdo, $transId, $_SERVER['PHP_AUTH_USER']) == false) {
		throw new TransactionException("Failed to close transaction $transId:  User " .
										$_SERVER['PHP_AUTH_USER'] . ' doesn\'t own the transaction.');
	}
	
	# Remove the directory (and its contents) from the filesystem	
	$dirName = get_dir_name( $pdo, $transId);
	recursive_rm( $dirName);
	// NOTE: Now that I think about it, I suspect recursive_rm won't work because any output
	// files that are created will be owned by the user and apache won't have permissions to
	// delete them.  A possible solution is to change the ownership of everything over to the
	// user and then submit a job to MWS that does the "rm -rf" 
	
	remove_transaction( $pdo, $transId);
}

/* -------------- Main body -------------- */
session_start();

// Check for the user name and password values
if (!isset ($_SERVER['PHP_AUTH_USER']) || !isset ($_SERVER['PHP_AUTH_PW'])) {
	// Most browsers will see the following headers and be
	// smart enough to ask the user for name and password and
	// then retry.  Web services apps probably won't, but then
	// they should have been smart enough to send the auth
	// info without being prompted for it.
	header('WWW-Authenticate: Basic Realm="Authentication"');
	header('HTTP/1.1 401 Unauthorized');
	return;
}

// Authenticate to the LDAP server.  ldap_auth() throws
// an exception if there's an error, so if it returns,
// we know we're good to go.
ldap_auth();

// Check the PHP_SELF var.  We know how to behave if we were called as
// 'start_transaction' and 'stop_transaction'.  Anything else is an
// error
$tokens = explode('/', $_SERVER['PHP_SELF']);
$last = end($tokens);

$_GET_lower = array_change_key_case($_GET, CASE_LOWER);
if (! array_key_exists( 'action', $_GET_lower)) {
	header( 'HTTP/1.1 400 Bad Request');
	echo "No action specified.";
	return;
}
$action = strtolower( $_GET_lower['action']);
if ($action == 'start') {
	$transData = start_transaction();
	echo json_encode( $transData, JSON_PRETTY_PRINT);
	
	// Return a 200 status code along with the new transaction ID
	header('HTTP/1.1 200 OK');
	
} elseif ($action == 'stop') {
	// Get the transaction ID from the query string
	if (! array_key_exists( 'transid', $_GET_lower)) {
		header( 'HTTP/1.1 400 Bad Request');
		echo "No transaction ID specified.";
		return;
	}
	
	$transId = strtolower( $_GET_lower['transid']);
	
	// Stop the transaction
	stop_transaction( $transId);
	
	// Return a 200...
	header('HTTP/1.1 200 OK');
} else {
	// Unknown action
	header( 'HTTP/1.1 400 Bad Request');
	echo 'Unrecognized action: ' . $action ;
}

?>
