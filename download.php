<?php

# A note about SELinux:  If SELinux is enabled, then it's very likely that
# any files we want to download will need a specific security context in
# order for httpd to read them.  You can set the default context on files
# in a specific directory with the command:
# chcon -t httpd_sys_content_t <dir>

    require 'php_functions.php';
    require 'db_functions.php';


    /* -------------- Main body -------------- */
    
    // Check for the user name and password values
    if ( ! isset( $_SERVER['PHP_AUTH_USER']) ||
         ! isset( $_SERVER['PHP_AUTH_PW']) )
    {
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
    
    // The file to be downloaded is looked up using the job ID and the
    // job ID is specified as a GET var
    $_GET_lower = array_change_key_case($_GET, CASE_LOWER);
    $jobid = $_GET_lower['jobid'];
    if ( ! $jobid) {
        // Job ID must be specified on the command line
        header( 'HTTP/1.1 400 Bad Request');
        echo "JobID parameter not specified in the requested URL<BR/>\n";
        return;
    }

    try {
    $pdo = open_db();

    // Check to see if the user is asking about one of his own jobs
    $user = find_user( $pdo, $jobid);
    if ($user === false) {
        header( 'HTTP/1.1 404 Not Found');
        echo "Job ID $jobid does not exist.<BR/>\n";
        return;
    }

    if ($user != $_SERVER['PHP_AUTH_USER']) {
        header( 'HTTP/1.1 403 Forbidden');
        echo "Job ID $jobid not owned by  {$_SERVER['PHP_AUTH_USER']}.<BR/>\n";
        // Strictly speaking, this is something of a security hole in that
        // it confirms the existance of a job that the user doesn't own.
        // That info is probably available elsewhere - showq and such - so
        // we're probably ok here.
        return;
    }
    


    $outfile = find_output_file( $pdo, $jobid);

    if ($outfile === false) {
        // Should never actually get here since $user should also be false
        // up above.  Just in case, though....
        header( 'HTTP/1.1 404 Not Found');
        echo "Job ID $jobid does not exist.<BR/>\n";
        return;
    }

    if ( ! $outfile || ! file_exists( $outfile)) {
        // file not found
        header( 'HTTP/1.1 404 Not Found');
        if ($outfile) {
            echo "Job ID $jobid found, but its associated output file " .
                "- $outfile - was not found.<BR/>\n";
        } else {
         echo "Job ID $jobid has no associated output file.<BR/>\n";
        }
        return;
    }

    if (filesize( $outfile) === false) {
        // File exists, but we can't read it.  Probably a permissions or
        // selinux issue.
        header( 'HTTP/1.1 500 Internal Server Error');
        echo "Cannot read $outfile<BR/>\n";
        return;
    }

    // Success!

    // Check the PHP_SELF var.  If we were called as "filecheck", then
    // we don't want to download the file, just test for its existance.
    // (Which we've successfully done if we've gotten this far.)
    $tokens = explode( '/', $_SERVER['PHP_SELF']);
    $last = end($tokens);
    if ($last == 'filecheck') {
        // File exists.  Returing a 200 status code is sufficient
        header( 'HTTP/1.1 200 OK');
        echo "$outfile: " . filesize($outfile) . " bytes<br/>\n";
    } else {
        // Actually transfer the file
        header( 'HTTP/1.1 200 OK');
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename='.basename($outfile));
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($outfile));
        ob_clean();
        flush();
        readfile($outfile);
    }
    return;    
    
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

