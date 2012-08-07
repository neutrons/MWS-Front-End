<?php

# A set of functions for accessing and manipulating the sqlite database that
# maps job ids to output files

# A note about SELinux:  If SELinux is enabled, then it's very likely that
# write access by httpd is restricted to files with a specific security
# context.  The database file will need this context.  You can set the
# default context on files in a specific directory with the command:
# chcon -t httpd_sys_rw_content_t <dir>

# location of various files we'll need.  (Things like the sqlite
# db file and the ini file with the MWS values.)
# Defaults to a dir that's one level up from the document root so
# that files in it aren't directly accessible from a browser.
if (! defined( 'SUPPORT_DIR'))
 { define ('SUPPORT_DIR', dirname( $_SERVER['DOCUMENT_ROOT']) . DIRECTORY_SEPARATOR . 'moab_support_files'); }


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


# The name of the table we'll use in the db
define( 'TABLE_NAME', 'Output_Files');

# Opens (or creates, if necessary) the database file.  Will also create the one
# table if necessary.  Returns a PDO object
function open_db() {

    // Try to open the db itself.  If that fails, try to create it.
    $dbFile = SUPPORT_DIR . DIRECTORY_SEPARATOR . "jobs.sqlite";
    $pdo = new PDO( "sqlite:$dbFile", null, null /* array(PDO::ATTR_PERSISTENT => true) */ );
    if (! $pdo) {
        throw new DbException (  $pdo->errorInfo(), "Error opening (or creating) " . $dbFile);
    }

    if ( $pdo->exec( 'CREATE TABLE IF NOT EXISTS ' . TABLE_NAME . ' (jobId TEXT, username TEXT, filename TEXT, PRIMARY KEY (jobId))') === false) {
        throw new DbException (  $pdo->errorInfo(), 'Error creating ' . TABLE_NAME . ' table');
    }

    return $pdo;
}

# Adds a jobID and output file tuple to the table.
# Returns nothing on success.  Throws an exception if there was a problem
# pdo is a PDO object (with an already opened database).
# jobId and outputFile are both strings
function add_row( $pdo, $jobId, $username, $outputFile) {
    if ($pdo->exec( 'INSERT INTO ' . TABLE_NAME . " VALUES ( \"$jobId\", \"$username\", \"$outputFile\")") === false) {
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



?>
