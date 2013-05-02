<?php

    // Import some functions we're going to need
    require_once 'ini_file.php';
    require_once 'php_functions.php';
    require_once 'db_functions.php';
    
    /* -------------- Main body -------------- */  

    try {
   
    if (session_start() == false) {
        error_log( "Failed to start session. This shouldn't happen.");
    } 

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
    
    // Check request method (GET or PUT)
    if ($_SERVER['REQUEST_METHOD'] == 'GET') {
        // Various "get job" functions here...
        
        // Check the request URI.  Requests for individual jobs
        // will have a path of ".../jobs/<job-id>
        $short_uri = explode( "?", $_SERVER['REQUEST_URI']); // split off the query string (if there is one)
        $dirs = explode( "/", $short_uri[0]);
        if ($dirs[ count($dirs) - 1] == "jobs") {
            // Request for all jobs....
            list( $http_code, $query_response) = get_jobs($_SERVER['PHP_AUTH_USER']);
            // Success code is and 200.  Errors are 4xx...
            header( sprintf( "HTTP/1.1 %d", $http_code));
            echo $query_response;     
        }
        elseif ($dirs[ count($dirs) - 2] == "jobs") {
            // Request for a specific jobID...
            list($http_code, $query_response) = get_job( $dirs[count( $dirs) - 1], $_SERVER['PHP_AUTH_USER']);
            // Success code is and 200.  Errors are 4xx...
            header( sprintf( "HTTP/1.1 %d", $http_code));
            echo $query_response;            
        }
    }
    elseif ($_SERVER['REQUEST_METHOD'] == 'POST')
    {
        // Verify the username in the attached JSON actually matches
        // the user in the auth header
        $body = file_get_contents('php://input');
        $json_vars = json_decode( $body, true);
        if ( isset( $json_vars['user'])) {
            if ($json_vars['user'] != $_SERVER['PHP_AUTH_USER']) {
                $msg = "User name in request body doesn't match user name in authorization header";
                throw new MwsAuthorizationException( $msg);
            }
        }
        else {
            throw new MwsErrorCodeException( "JSON body must contain a user field.");
        }

        list( $http_code, $response_body) = submit_job( $body);
        // Success codes are 201 and 202.  Errors are 4xx...
        if ($http_code == 201 || $http_code == 202) {
            // Add the job id, user and output file to the database
            $json_vars = json_decode( $response_body, true);
            $jobID = $json_vars['id'];
            $outfile = $_GET['outfile'];
            $pdo = open_db();
            # Currently, add_row() is commented out in db_function.php.  I'm
            # not sure why, but we obviously can't call it until it's restored.
            #add_row( $pdo, $jobID, $_SERVER['PHP_AUTH_USER'], $outfile);
            # TODO: Should we enforce the existance of outfile?
        }
        if ($http_code == 201)
            header('HTTP/1.1 201 Created');
        elseif ($http_code == 202)
            header('HTTP/1.1 202 Accepted');
        else  // should never happen...
            header( sprintf( "HTTP/1.1 %d", $http_code));

        echo $response_body;
    }
    else {
        $msg = "Unrecognized request method: " . $_SERVER['REQUEST_METHOD'];
        throw new MwsErrorCodeException( $msg);
    }
    

    } catch (MwsAuthorizationException $e) {
    header( 'HTTP/1.1 401');
    echo "MwsAuthorizationException  ";
    echo $e->getMessage();
    } catch (MwsAuthenticationException $e) {
    header( 'HTTP/1.1 401');
    echo "MwsAuthenticationException  ";
    echo $e->getMessage();
    } catch (Exception $e) {
    echo "Unknown exception.  ";
    echo $e->getMessage();
    }
?>

