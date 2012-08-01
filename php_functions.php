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

# Define configuration constants
if (!defined('MWS_SCHEME')) define('MWS_SCHEME', 'http');
if (!defined('MWS_HOST')) define('MWS_HOST', 'localhost');
//if (!defined('MWS_HOST')) define('MWS_HOST', 'chadwick.sns.gov');
// NOTE: Once we're actually installed on chadwick, MWS_HOST should
// be changed back to localhost
if (!defined('MWS_PORT')) define('MWS_PORT', 8080);
if (!defined('MWS_BASE')) define('MWS_BASE', '/mws/rest/');
if (!defined('MWS_USER')) define('MWS_USER', 'admin');
//if (!defined('MWS_PASS')) define('MWS_PASS', 'PASSWORD_REMOVE_PRIOR_TO_GIT_PUSH');
if (!defined('MWS_PASS')) define('MWS_PASS', 'PASSWORD_REMOVED_PRIOR_TO_GIT_PUSH');


// Note: This is unfortunately rather specific to the SNS ldap server
// It'd be nice to have a more generic function...
function ldap_auth() {

    // Change these to match the SNS LDAP server
    $ldapconfig['host'] = 'ldaps://data.sns.gov/';
//    $ldapconfig['host'] = 'ldaps://odyssey.sns.gov';
    $ldapconfig['basedn'] = 'dc=sns,dc=ornl,dc=gov';
    $ldapconfig['filter'] = "(&(objectClass=posixGroup)(cn=SNS_Neutron))";

    // This dn is probably specific to SNS...
    $bind_dn = 'uid=' . $_SERVER['PHP_AUTH_USER'] . ',ou=users,' . $ldapconfig['basedn'];

    $ldap_link = ldap_connect( $ldapconfig['host']);
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
    
    $search_result = ldap_search( $ldap_link, $ldapconfig['basedn'], $ldapconfig['filter']);
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
    // exceptions listed above if there was an error.
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
   
  error_log( "************ get_jobs response *************");
  error_log( "Return code: $http_code");
  error_log( $query_response);
  error_log( "********************************************");

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
