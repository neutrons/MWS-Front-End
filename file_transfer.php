<?php
/*
 * Created on Oct 25, 2012
 *
 * Code and functions related to file transfers (upload, download and also queries)
 * 
 * Query strings for downloads will need 3 variables: TransID=xxxx&Action=download&File=zzzzz
 * Note that File should just contain the filename, not the full pathname.  The actual
 * directory the file is stored in is determinied from the TransID
 * 
 * Query strings for file queries will need 2 variables (and note that Action is different):
 * TransID=xxxx&Action=Query
 * 
 * HTTP POST's don't have query strings, so the 'Action' and 'TransID' variables must
 * be transmitted in multipart mime format.  Note that the file names (multiple files
 * can be uploaded) are passed in the header for the particular part.
 * 
 * 
 */
 
 
# A note about SELinux:  If SELinux is enabled, then it's very likely that
# any files we want to download will need a specific security context in
# order for httpd to read them.  You can set the default context on files
# in a specific directory with the command:
# chcon -t httpd_sys_content_t <dir>

    require 'php_functions.php';
    require 'db_functions.php';


    /* -------------- Main body -------------- */
    session_start();
    
    // Check for the user name and password values
    if ( ! isset( $_SERVER['PHP_AUTH_USER'])       ||
         ! isset( $_SERVER['PHP_AUTH_PW'])         ||
         (strlen( $_SERVER['PHP_AUTH_USER']) == 0) ||
         (strlen( $_SERVER['PHP_AUTH_PW']) == 0) ) {
        // Most browsers will see the following headers and be
        // smart enough to ask the user for name and password and
        // then retry.  Web services apps probably won't, but then
        // they should have been smart enough to send the auth
        // info without being prompted for it.
        header( 'WWW-Authenticate: Basic Realm="Authentication"');
        header( 'HTTP/1.1 401 Unauthorized');
        return;
    }

    // Authenticate to the LDAP server.  ldap_auth() throws
    // an exception if there's an error, so if it returns,
    // we know we're good to go.
    ldap_auth();

	try {
        
    // Validate the query string
    $transId = "";  // empty strings are equivalent to boolean false
    $action = "";
    $file = "";
    if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    	$_GET_lower = array_change_key_case($_GET, CASE_LOWER);
    	
		if ( array_key_exists( 'transid', $_GET_lower)) {
    		$transId = $_GET_lower['transid'];
		}
		
		if ( array_key_exists( 'action', $_GET_lower)) {
			$action = strtolower( $_GET_lower['action']);
		}
		
		if ( array_key_exists( 'file', $_GET_lower)) {
			$file = $_GET_lower['file'];
		}
    }
    elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
    	$_POST_lower = array_change_key_case( $_POST, CASE_LOWER);
    	
		if ( array_key_exists( 'transid', $_POST_lower)) {
    		$transId = $_POST_lower['transid'];
		}
		if ( array_key_exists( 'action', $_POST_lower)) {
			$action = strtolower( $_POST_lower['action']);
		}
    }
    
    if ( ! $transId) {
        // Transaction ID must be specified in the query string
        header( 'HTTP/1.1 400 Bad Request');
        echo "TransID parameter not specified.";
        return;
    }
    
    if ( ! $action) {
        // Action must be specified in the query string
        header( 'HTTP/1.1 400 Bad Request');
        echo "Action parameter not specified.";
        return;
    }

    // Only download and queries can use GET requests
    if ($_SERVER['REQUEST_METHOD'] == 'GET' &&
        $action != "download" &&
        $action != "query" ) {
		header( 'HTTP/1.1 400 Bad Request');
		echo "Unrecognized 'action' type for a GET request";
		return;
	}
    
    // Only uploads can use POST requests
    if ($_SERVER['REQUEST_METHOD'] == 'POST' &&
        $action != "upload") {
		header( 'HTTP/1.1 400 Bad Request');
		echo "Unrecognized 'action' type for a POST request";
		return;
    }
    
    // If it's a download request, there must be a file variable
    if ($action == "download" && ! $file) {
		header( 'HTTP/1.1 400 Bad Request');
		echo "File to download not specified.";
		return;	
    }

    // Check to see if the user is asking about one of his own transactions
    $pdo = open_db();
    $user = find_user( $pdo, $transId);
    if ($user === false) {
        header( 'HTTP/1.1 404 Not Found');
        echo "Transaction ID $transId does not exist.";
        return;
    }

    if ($user != $_SERVER['PHP_AUTH_USER']) {
        header( 'HTTP/1.1 403 Forbidden');
        echo "Transaction ID $transId not owned by  {$_SERVER['PHP_AUTH_USER']}.";
        // Strictly speaking, this is something of a security hole in that
        // it confirms the existance of a transaction that the user doesn't own.
        return;
    }
       
	// Check the 'action' var: should be either 'download', 'query' or 'upload'
	// Handle file download requests
	if ($action == "download") {
		$fullPathName = find_directory( $pdo, $transId) . DIRECTORY_SEPARATOR . $file;	
		if ( ! file_exists( $fullPathName)) {
        	// file not found
        	header( 'HTTP/1.1 404 Not Found');
        	echo "The specified file - $file - could not be found.";
        	return;
    	}

	    if (filesize( $fullPathName) === false) {
	        // File exists, but we can't read it.  Probably a permissions or
	        // selinux issue.
	        header( 'HTTP/1.1 500 Internal Server Error');
	        echo "Cannot read $file.";
	        return;
	    }
		
		// Actually transfer the file
        header( 'HTTP/1.1 200 OK');
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . $file);
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($fullPathName));
        ob_clean();
        flush();
        readfile($fullPathName);
		return;
		
	} elseif ($action == "query") {
		// Get a directory listing and return it as a JSON object
		$dir = find_directory( $pdo, $transId);
		
		$listing = array();
		if ($handle = opendir($dir)) {
		    // loop over the directory
		    while (false !== ($entry = readdir($handle))) {
		    	if ($entry != '.' && $entry != '..') {
		    		$listing[] = $entry;
		    		// appenda entry to the listing (but doesn't include
		    		// . or .. directories)
		    	}
		    }

		    closedir($handle);
		    
		    // If we just returned $listing now, we'd only get an array, not
		    // a complete JSON object (ie: a name/value pair enclosed in braces).
		    // Converting listing into a PHP associative array results in an
		    // actual object when it's encoded.
		    $listing = array( "filenames" => $listing);
		    echo json_encode( $listing, JSON_PRETTY_PRINT);
		} else {
			// couldn't open the directory
			header( 'HTTP/1.1 500 Internal Server Error');
    		echo "Cannot open directory $dir.";
    		return;
		}
	} elseif ($action == "upload") {
		$dir = find_directory( $pdo, $transId);
		
		// Check the $_FILES supergloblal
		foreach ( $_FILES as $theFile) {
			if ($theFile["error"] == UPLOAD_ERR_OK) {
				$pathname = $dir . DIRECTORY_SEPARATOR . $theFile["name"];
        		if (move_uploaded_file( $theFile["tmp_name"], $pathname) == false) {
		        	header( 'HTTP/1.1 500 Internal Server Error');
					 echo "move_uploaded_file() failed:  ". $theFile['tmp_name'] . " ==> $pathname";
        		}
    		}
    		else {
    			header( 'HTTP/1.1 500 Internal Server Error');
				echo "File upload failed.  Error #". $theFile['error'] . "\n";
				echo "(See the PHP docs for the _FILES superglobal.)\n";
				echo "Relevent info about the file:\n";
				print_r( $theFile);
    		}
		}
	}
	
	    
    } catch (DbException $e) {
        header( 'HTTP/1.1 500 Internal Server Error');
        echo "Database Exception:<BR/>\n";
        echo 'ErrorInfo: ' . $e->getErrorInfo() . "<BR/>\n";
        echo 'Message: ' . $e->getMessage() . "<BR/>\n";
        return;
    } catch (Exception $e) {
        header( 'HTTP/1.1 500 Internal Server Error');
        echo 'Unknown Exception:<BR/>\n';
        echo 'Message: ' . $e->getMessage() . "<BR/>\n";
    }

 
 
?>
