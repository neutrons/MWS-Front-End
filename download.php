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
    if ($jobid) {
        $pdo = open_db();
        $outfile = find_output_file( $pdo, $jobid);

        if ($outfile) {
            if (file_exists( $outfile)) {
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

                exit;  // success
            }  else {  // file not found
                echo "$outfile does not exist! <br/>";
            }
        } else {  // file not found.  404
            echo "No outfile found for Job $jobid <br/>";
        }
    } else {  // job id not specified on command line
        echo "Job ID not specified! <br/>";
    }
    


?>

<?php
/*
<hr />
<hr />
<form action="index.php" method="post">
<p>
Email address:<br />
<input type="text" name="email" size="20" maxlength="50" value="" />
</p>
<p>
Password:<br />
<input type="password" name="pswd" size="20" maxlength="15" value="" />
</p>
<p>
<input type="submit" name="subscribe" value="subscribe!" />
<input type="submit" name="cancel" value="cancel!" />
</p>
</form>
  
*/
?>  


