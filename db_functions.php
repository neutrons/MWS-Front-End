<?php

# A set of functions for accessing and manipulating the sqlite database that
# maps job ids to output files

# A note about SELinux:  If SELinux is enabled, then it's very likely that
# write access by httpd is restricted to files with a specific security
# context.  The database file will need this context.  You can set the
# default context on files in a specific directory with the command:
# chcon -t httpd_sys_rw_content_t <dir>

# A note about transactions:  References to "transactions" and "transaction ID's"
# don't refer to the normal transactions that people think about in the context
# of database.  In this case, a transaction is the group of actions required to
# submit a remote job and get the results back.  ie: upload script (or scripts)
# to execute, execute script(s) on the cluster (possibly multiple times with 
# different inputs) and download the results.


require_once 'ini_file.php';


# For problems creating/opening/reading/writing the SQLite DB
class DbException extends Exception {
    private $PDOErrorInfo;  // from the PDO::errorInfo()

    public function __construct( $errorInfo, $message = "", $code = 0, $previous = NULL) {
        parent::__construct( $message, $code, $previous);
        $this->PDOErrorInfo = $errorInfo; 
    }

    final public function getErrorInfo() {
        return $this->PDOErrorInfo; 
    }
};


# We'll need two tables: one with one row per transaction, which holds the transaction ID, the
# user name and the directory we've created.  The second will map moab job ID's to transaction
# ID's (There can be more than one job ID per transaction.)
define('TRANS_TABLE', 'Transactions');
define('JOB_TABLE', 'Jobs');
function check_trans_table( $pdo) {
	$stmt = 'CREATE TABLE IF NOT EXISTS ' . TRANS_TABLE .
        ' (transId INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, directory TEXT, when_added INTEGER)';
    // SQLite doesn't have a specific date type.  We're using integer seconds (Unix time)
    if ( $pdo->exec( $stmt) === false) {
        throw new DbException ( $pdo->errorInfo(), 'Error creating ' . TRANS_TABLE . ' table');
    }
	
}

function check_job_table( $pdo) {
	$stmt = 'CREATE TABLE IF NOT EXISTS ' . JOB_TABLE .
        ' (transId INTEGER, jobId TEXT, FOREIGN KEY(transId) REFERENCES ' .
        TRANS_TABLE . '(transId) ON DELETE CASCADE)';
    
    if ( $pdo->exec( $stmt) === false) {
        throw new DbException ( $pdo->errorInfo(), 'Error creating ' . JOB_TABLE . ' table');
    }
	
}

# Opens (or creates, if necessary) the database file.  Will also create the one
# table if necessary.  Returns a PDO object
function open_db() {

    // Try to open the db itself.  If that fails, try to create it.
    $dbFile = SUPPORT_DIR . DIRECTORY_SEPARATOR . "jobs.sqlite";
    $pdo = new PDO( "sqlite:$dbFile", null, null /* array(PDO::ATTR_PERSISTENT => true) */ );
    if (! $pdo) {
        throw new DbException ( $pdo->errorInfo(), "Error opening (or creating) " . $dbFile);
    }

	$stmt = 'PRAGMA foreign_keys = ON';  # foreign key constraints aren't enabled by default
    if ( $pdo->exec( $stmt) === false) {
        throw new DbException ( $pdo->errorInfo(), 'Error enabling foreign keys');
    }
        
    check_trans_table( $pdo);
    check_job_table( $pdo);

    return $pdo;
}


# Verifies that a particular transaction ID is valid and belongs to the
# specified user
# returns true or false
# pdo is a PDO object (with an already opened database).
# transId is an integer
function check_transaction( $pdo, $transId, $username) {
	$qstring = 'SELECT * FROM ' . TRANS_TABLE . " WHERE transId == '$transId'";
	$results = $pdo->query( $qstring);
	if ($results == false) {
		throw new DbException ( $pdo->errorInfo(), 'Error searching for transaction in ' . TRANS_TABLE . 'table');
	}
	
	$rows = $results->fetchAll();
	if (count( $rows) == 1) {
		if ($rows[0]['username'] == $username) {
			return true;	
		}
	}
	
	return false;
}

# Returns the name of the directory associated with this transaction
# (throws an exception if the transaction doesn't exist)
# pdo is a PDO object (with an already opened database).
function get_dir_name( $pdo, $transId) {
	$qstring = 'SELECT * FROM ' . TRANS_TABLE . " WHERE transId == '$transId'";
	$results = $pdo->query( $qstring);
	$row = $results->fetch();
	if ($row == FALSE) {
		throw new DbException ( $pdo->errorInfo(), "Transaction ID $transId not found in " . TRANS_TABLE . 'table');
		return "";  // This line should never execute
	}
	
	return $row['directory'];
}

# Adds a new transaction to the database.
# returns the new transaction ID on success (and throws an exception on error)
# pdo is a PDO object (with an already opened database)
# username and directoryName are strings
# Returns the id of the transaction that was created
# Note: this function doesn't actually create the directory!  That's
# assumed to have been done at a higher level. 
function add_transaction( $pdo, $username, $directoryName) {
	$stmt = $pdo->prepare( 'INSERT INTO ' . TRANS_TABLE . 
		 ' (username, directory, when_added) VALUES ( ?, ?, strftime(\'%s\', \'now\'))');
	if ($stmt->execute( array( $username, $directoryName)) === false) {
		throw new DbException( $pdo->errorInfo(), 'Error inserting row in ' . TRANS_TABLE . ' table');
	}
	
	# Get the transaction ID
	# TODO: Is there a better way than executing another query?!?
	$qstring = 'SELECT transId FROM ' . TRANS_TABLE . ' ORDER BY transId DESC';
	$results = $pdo->query( $qstring);
	$row = $results->fetch();
	$transId = $row['transId'];
	return $transId;
}


# Adds a new moab (or whatever scheduler we're using) job ID to
# a particular transaction
# pdo is a PDO object (with an already opened database)
# transId is an integer, jobId is a string
# returns nothing on success (and throws an exception on error)
function add_job_id( $pdo, $transId, $jobId) {
	$stmt = $pdo->prepare( 'INSERT INTO ' . JOB_TABLE . ' VALUES ( ?, ?)');
	if ($stmt->execute( array( $transId, $jobId)) === false) {
		throw new DbException( $pdo->errorInfo(), 'Error inserting row in ' . JOB_TABLE . ' table');
	}
}

# Removes a transaction from the database
# (Note: Since the jobs table is set to cascade deletes, this function
# will result in any jobs that reference that transaction being deleted)
# returns nothing on success (and throws an exception on error)
function remove_transaction( $pdo, $transId) {
	$stmt = $pdo->prepare( 'DELETE FROM ' . TRANS_TABLE . ' WHERE transId == ?');
	if ($stmt->execute( array( $transId)) === false) {
		throw new DbException( $pdo->errorInfo(), 'Error deleting row from ' . TRANS_TABLE . ' table');
	}
}

/*************************************

# Adds a jobID and output file tuple to the table.
# Returns nothing on success.  Throws an exception if there was a problem
# pdo is a PDO object (with an already opened database).
# jobId and outputFile are both strings
function add_row( $pdo, $jobId, $username, $outputFile) {
    $stmt = 'INSERT INTO ' . TABLE_NAME . " VALUES ( \"$jobId\", \"$username\", \"$outputFile\", strftime('%s', 'now'))";
    if ($pdo->exec( $stmt) === false) {
        throw new DbException (  $pdo->errorInfo(), 'Error inserting row in ' . TABLE_NAME . ' table');
    }
}

# Searches the table for the specified jobID and returns the name of the
# associated output file.  Returns boolean false if the id doesn't exist
# pdo is a PDO object (with an already opened database).
function find_output_file( $pdo, $jobId) {

    $outfile = '';  // empty string
    $qstring = 'SELECT filename from ' . TABLE_NAME .  ' WHERE jobId == \'' . $jobId . '\'';
    $results = $pdo->query( $qstring);
    if ($results === false) {
        throw new DbException (  $pdo->errorInfo(), 'Error querying ' . TABLE_NAME . ' for job ID ' . $jobId);
    }

    $row = $results->fetch();
    if ($row !== false) {
        $outfile = $row['filename'];
    } else {
        $outfile = false;
    }

    // This is a sanity check.  There should be at most a single row returned.
    $row = $results->fetch();
    if ($row !== false) {
        throw new DbException( $pdo->errorInfo(), 'Multiple results returned from query for job ID ' . $jobId);
    }

    $results->closeCursor();
    return $outfile;
}


# Searches the table for the specified jobID and returns the username 
# associated with it.  Returns boolean FALSE if the id doesn't exist
# pdo is a PDO object (with an already opened database).
function find_user( $pdo, $jobId) {

    $user = '';  // empty string
    $qstring = 'SELECT username from ' . TABLE_NAME .  ' WHERE jobId == \'' . $jobId . '\'';
    $results = $pdo->query( $qstring);
    if ($results === false) {
        throw new DbException (  $pdo->errorInfo(), 'Error querying ' . TABLE_NAME . ' for job ID ' . $jobId);
    }

    $row = $results->fetch();
    if ($row !== false) {
        $user = $row['username'];
    } else {
        $user = false;
    }


    // This is a sanity check.  There should be at most a single row returned.
    $row = $results->fetch();
    if ($row !== false) {
        throw new DbException( $pdo->errorInfo(), 'Multiple results returned from query for job ID ' . $jobId);
    }

    $results->closeCursor();
    return $user;
}

***************************************/

?>
