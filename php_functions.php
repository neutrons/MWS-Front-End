<?php

/*
    This is actually job.php from Nathan Wells's github project:
    https://github.com/nwwells/mws-php

    I added the ldap_auth() function myself
    RGM, April 2012
*/

/****************************************
 A note about SELinux:
 If SELinux is enabled on the server, then the following values
 will need to be set to true (using setsebool)

 httpd_can_connect_ldap
 httpd_can_network_connect

*****************************************/


# Define exception types

# for 401s
class MwsAuthenticationException extends Exception {};
# for 403s
class MwsAuthorizationException extends Exception {};
# for unauthorized users
class UserAuthorizationException extends Exception {};
# general MWS Error code exception
class MwsErrorCodeException extends Exception {};

# thrown if the config file is missing a required key
class MissingInitException extends Exception {};


# location of various files we'll need.  (Things like the sqlite
# db file and the ini file with the MWS values.)
# Defaults to a dir that's one level up from the document root so
# that files in it aren't directly accessible from a browser.
if (! defined( 'SUPPORT_DIR'))
 { define ('SUPPORT_DIR', dirname( $_SERVER['DOCUMENT_ROOT']) . DIRECTORY_SEPARATOR . "moab_support_files"); }

# Read some necessary configuration constants from an ini file
# (Slightly better than hard-coding them into the script)
# Note: It should be obvious from all the array_key_exists() calls,
# but we're expecting several key/value pairs to exist in the ini file and
# we won't run if they're not there.  These values are used for building up
# the URL string in the curl calls and for the LDAP calls.
try {  
    $configs = parse_ini_file( SUPPORT_DIR . DIRECTORY_SEPARATOR . "config.ini");

    # verify that the necessary values exist and then define constants for
    # them.  (The constants are probably overkill, but they don't hurt
    # anything...)
    if ( array_key_exists('MWS_SCHEME', $configs)) { define('MWS_SCHEME', $configs['MWS_SCHEME']); } else { throw new MissingInitException( 'MWS_SCHEME'); }
    if ( array_key_exists('MWS_HOST',   $configs)) { define('MWS_HOST',   $configs['MWS_HOST']);   } else { throw new MissingInitException( 'MWS_HOST'); }
    if ( array_key_exists('MWS_PORT',   $configs)) { define('MWS_PORT',   $configs['MWS_PORT']);   } else { throw new MissingInitException( 'MWS_PORT'); }
    if ( array_key_exists('MWS_BASE',   $configs)) { define('MWS_BASE',   $configs['MWS_BASE']);   } else { throw new MissingInitException( 'MWS_BASE'); }
    if ( array_key_exists('MWS_USER',   $configs)) { define('MWS_USER',   $configs['MWS_USER']);   } else { throw new MissingInitException( 'MWS_USER'); }
    if ( array_key_exists('MWS_PASS',   $configs)) { define('MWS_PASS',   $configs['MWS_PASS']);   } else { throw new MissingInitException( 'MWS_PASS'); }

    if ( array_key_exists('LDAP_HOST',   $configs)) { define('LDAP_HOST',   $configs['LDAP_HOST']);   } else { throw new MissingInitException( 'LDAP_HOST'); }
    if ( array_key_exists('LDAP_BASE_DN',   $configs)) { define('LDAP_BASE_DN',   $configs['LDAP_BASE_DN']);   } else { throw new MissingInitException( 'LDAP_BASE_DN'); }
    if ( array_key_exists('LDAP_FILTER',   $configs)) { define('LDAP_FILTER',   $configs['LDAP_FILTER']);   } else { throw new MissingInitException( 'LDAP_FILTER'); }

} catch (MissingInitException $e) {
    die( "Missing required key in ini file: " . $e->getMessage());
}

// Note: This is unfortunately rather specific to the SNS ldap server
// It'd be nice to have a more generic function...
// Note: successful authentication is indicated by the function simply
// returning.  If authentication is unsuccessful, the function throws
// one of the exceptions defined above.
function ldap_auth() {

    // Check the session variable - if the session is valid, skip the
    // actual authentication unless it's been more than a minute
    // since the last one  (Hopefully, this will speed things up when
    // the client hits us with lots of connections.)
    if (isset($_SESSION['ldap_auth_time']))
    {
        if (time() - $_SESSION['ldap_auth_time'] < 60)
        {
            // don't bother authenticating
            return;
        }
    }

    // This dn is probably specific to SNS...
    $bind_dn = 'uid=' . $_SERVER['PHP_AUTH_USER'] . ',ou=users,' . LDAP_BASE_DN;

    $ldap_link = ldap_connect( LDAP_HOST);
    if ($ldap_link == false)
    {
        throw new MwsAuthenticationException( "Failed to connect to LDAP server");
    }

    // Need protocol version 3 to use SSL
    if ( ! ldap_set_option( $ldap_link, LDAP_OPT_PROTOCOL_VERSION, 3))
    {
        throw new MwsAuthenticationException( "Failed to set LDAP protocol version 3.");
    }

    // Attempt to bind (thus verifying user name and password)
    if ( ! ldap_bind( $ldap_link, $bind_dn, $_SERVER['PHP_AUTH_PW']))
    {
        throw new MwsAuthenticationException( "Failed to bind to DN='$bind_dn'.  Username/password combo probably wrong.");
    }
    
    $search_result = ldap_search( $ldap_link, LDAP_BASE_DN, LDAP_FILTER);
    if ($search_result == false)
    {
        throw new MwsAuthorizationException( "LDAP search failed.");
    }
   
    $ldap_users = ldap_get_entries( $ldap_link, $search_result);
    // There should actually be only one result, but regardless, we're going
    // to look at the first result and specifically the memberuid array in 
    // that result.
    $members = $ldap_users[0]['memberuid'];
    if (in_array( $_SERVER['PHP_AUTH_USER'], $members) == false)
    {
        throw new MwsAuthorizationException( "User not authorized to access resouce.");
    }
    
    // If we make it here, it's success.  We can just return.  We threw one of the
    // exceptions listed above if there was an error.  Update the session variable
    // so we don't have to hit the LDAP server next time
    $_SESSION['ldap_auth_time'] = time();
    return;
}



# utility function to get a CURL configured for MWS
function run_curl($resource, $query_string=null, $config_closure=null, $info_store=null) {
  $url = MWS_SCHEME . '://' . MWS_HOST . ':' . MWS_PORT . MWS_BASE . $resource;
  $userpwd = MWS_USER . ':' . MWS_PASS;

  if ( isset($query_string))
  {
    $url = $url . '?' . $query_string;
  }

  $ch = curl_init($url);

  # do call specific configuration
  if(isset($config_closure)) call_user_func($config_closure, $ch);

  # These options should override input.
  curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

  # TODO - this is improper from a security perspective, since
  # we essentially trust any certificate and, therefore, identity
  # of the MWS server is not guaranteed
  #
  # However, since we can rely on DNS being configured properly
  # at this point, it's not 100% necessary to fix right now.
  #curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

  # These are stubs for the correct (secure) settings. Thanks to
  # http://unitstep.net/blog/2009/05/05/using-curl-in-php-to-access-https-ssltls-protected-sites/
  #curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
  #curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
  #curl_setopt($ch, CURLOPT_CAINFO, "/path/to/certificate/authority");

  $resp = curl_exec($ch);
  $info_store = curl_getinfo($ch);
  curl_close($ch);
/**
  if($info_store['http_code'] == 401) {
    header( "HTTP/1.1 401 Unauthorized");
    throw new MwsAuthenticationException('MWS has refused access to the configured username/password');
  } else
**/
  if ($info_store['http_code'] >= 400) {
    error_log('error when calling MWS!');
    error_log('response: '.$resp);
    error_log('info: '.json_encode($info_store));
//    header( sprintf( "HTTP/1.1 %d", $info_store['http_code']));
//    throw new MwsErrorCodeException('Call to MWS failed with error '.$info_store['http_code']);
  }

/***********
  $return_vals[] = $info_store['http_code'];
  $return_vals[] = $resp;
  return $return_vals;
**************/
  return array( $info_store['http_code'], $resp);
}


// Need to test this!
function get_job($job_id, $username) {
  #Get job object from MWS
  list($http_code, $query_response) = run_curl("jobs/" . $job_id, $_SERVER['QUERY_STRING']);
  
  if ($http_code == 200)
  {
    if (check_user($username, json_decode($query_response))) {
      return array( $http_code, $query_response);
    }
  }
  else
    return array( $http_code, $query_response);

}


function get_jobs($username) {

  #Get job objects from MWS
  list($http_code, $query_response) = run_curl("jobs", $_SERVER['QUERY_STRING']);
   
  #error_log( "************ get_jobs response *************");
  #error_log( "Return code: $http_code");
  #error_log( $query_response);
  #error_log( "********************************************");

  if ($http_code == 200) {
    $query_json = json_decode( $query_response);
    
    // Filter out jobs for other users
    $user_jobs = array();
    foreach ($query_json->results as $job) {
        if (check_user($username, $job, true)) {
        array_push($user_jobs, $job);
        }
    }

    // Overwrite the original response with the filtered data
    $query_json->results = $user_jobs;
    $query_json->resultCount = count($user_jobs);
    # will need to change this when we support max and offset
    $query_json->totalCount = $query_json->resultCount;
    $query_response = json_encode( $query_json);
  }

  return array($http_code, $query_response);
}


function submit_job($job) {
  if (!is_string($job))
    $job = json_encode($job);

  return run_curl("jobs", $_SERVER['QUERY_STRING'], function($ch) use ($job) {
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $job);
  });
}

function check_user($username, $job_info, $silent=false) {
// job_info is JSON encoded response from the MWS .../rest/jobs/<job_id> url
  if($job_info->user == $username) {
    return true;
  } else {
    if ($silent) {
      return false;
    } else {
      throw new UserAuthorizationException("User '".$username."' is not authorized to see job '".$job."'");
    }
  }
}

?>
