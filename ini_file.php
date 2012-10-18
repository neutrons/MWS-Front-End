<?php

# Code for parsing and sanity-checking the ini file.  Moved into a separate
# source file that all others can then include (presumably with require_once
# rather than 'require')
# Also defines variable called SUPPORT_DIR which will point to the location of
# various things (like the config file itself and the sqlite db file)


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

    if ( array_key_exists('LDAP_HOST',   $configs)) { define('LDAP_HOST',   $configs['LDAP_HOST']);          } else { throw new MissingInitException( 'LDAP_HOST'); }
    if ( array_key_exists('LDAP_BASE_DN',   $configs)) { define('LDAP_BASE_DN',   $configs['LDAP_BASE_DN']); } else { throw new MissingInitException( 'LDAP_BASE_DN'); }
    if ( array_key_exists('LDAP_FILTER',   $configs)) { define('LDAP_FILTER',   $configs['LDAP_FILTER']);    } else { throw new MissingInitException( 'LDAP_FILTER'); }
    
    if ( array_key_exists('DATA_FILE_ROOT', $configs)) { define( 'DATA_FILE_ROOT', $configs['DATA_FILE_ROOT']); } else { throw new MissingInitException( 'DATA_FILE_ROOT'); }

} catch (MissingInitException $e) {
    die( "Missing required key in ini file: " . $e->getMessage());
}

?>