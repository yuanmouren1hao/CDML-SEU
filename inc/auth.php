<?php
/**
 * Authentication library
 *
 * Including this file will automatically try to login
 * a user by calling auth_login()
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */

if(!defined('DOKU_INC')) die('meh.');

// some ACL level defines
define('AUTH_NONE', 0);
define('AUTH_READ', 1);
define('AUTH_EDIT', 2);
define('AUTH_CREATE', 4);
define('AUTH_UPLOAD', 8);
define('AUTH_DELETE', 16);
define('AUTH_ADMIN', 255);

/**
 * Initialize the auth system.
 *
 * This function is automatically called at the end of init.php
 *
 * This used to be the main() of the auth.php
 *
 * @todo backend loading maybe should be handled by the class autoloader
 * @todo maybe split into multiple functions at the XXX marked positions
 * @triggers AUTH_LOGIN_CHECK
 * @return bool
 */
function auth_setup() {
    global $conf;
    /* @var DokuWiki_Auth_Plugin $auth */
    global $auth;
    /* @var Input $INPUT */
    global $INPUT;
    global $AUTH_ACL;
    global $lang;
    /* @var Doku_Plugin_Controller $plugin_controller */
    global $plugin_controller;
    $AUTH_ACL = array();

    if(!$conf['useacl']) return false;

    // try to load auth backend from plugins
    foreach ($plugin_controller->getList('auth') as $plugin) {
        if ($conf['authtype'] === $plugin) {
            $auth = $plugin_controller->load('auth', $plugin);
            break;
        } elseif ('auth' . $conf['authtype'] === $plugin) {
            // matches old auth backends (pre-Weatherwax)
            $auth = $plugin_controller->load('auth', $plugin);
            msg('Your authtype setting is deprecated. You must set $conf[\'authtype\'] = "auth' . $conf['authtype'] . '"'
                 . ' in your configuration (see <a href="https://www.dokuwiki.org/auth">Authentication Backends</a>)',-1,'','',MSG_ADMINS_ONLY);
        }
    }

    if(!isset($auth) || !$auth){
        msg($lang['authtempfail'], -1);
        return false;
    }

    if ($auth->success == false) {
        // degrade to unauthenticated user
        unset($auth);
        auth_logoff();
        msg($lang['authtempfail'], -1);
        return false;
    }

    // do the login either by cookie or provided credentials XXX
    $INPUT->set('http_credentials', false);
    if(!$conf['rememberme']) $INPUT->set('r', false);

    // handle renamed HTTP_AUTHORIZATION variable (can happen when a fix like
    // the one presented at
    // http://www.besthostratings.com/articles/http-auth-php-cgi.html is used
    // for enabling HTTP authentication with CGI/SuExec)
    if(isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    // streamline HTTP auth credentials (IIS/rewrite -> mod_php)
    if(isset($_SERVER['HTTP_AUTHORIZATION'])) {
        list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) =
            explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));
    }

    // if no credentials were given try to use HTTP auth (for SSO)
    if(!$INPUT->str('u') && empty($_COOKIE[DOKU_COOKIE]) && !empty($_SERVER['PHP_AUTH_USER'])) {
        $INPUT->set('u', $_SERVER['PHP_AUTH_USER']);
        $INPUT->set('p', $_SERVER['PHP_AUTH_PW']);
        $INPUT->set('http_credentials', true);
    }

    // apply cleaning (auth specific user names, remove control chars)
    if (true === $auth->success) {
        $INPUT->set('u', $auth->cleanUser(stripctl($INPUT->str('u'))));
        $INPUT->set('p', stripctl($INPUT->str('p')));
    }

    if(!is_null($auth) && $auth->canDo('external')) {
        // external trust mechanism in place
        $auth->trustExternal($INPUT->str('u'), $INPUT->str('p'), $INPUT->bool('r'));
    } else {
        $evdata = array(
            'user'     => $INPUT->str('u'),
            'password' => $INPUT->str('p'),
            'sticky'   => $INPUT->bool('r'),
            'silent'   => $INPUT->bool('http_credentials')
        );
        trigger_event('AUTH_LOGIN_CHECK', $evdata, 'auth_login_wrapper');
    }

    //load ACL into a global array XXX
    $AUTH_ACL = auth_loadACL();

    return true;
}

/**
 * Loads the ACL setup and handle user wildcards
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 *
 * @return array
 */
function auth_loadACL() {
    global $config_cascade;
    global $USERINFO;
    /* @var Input $INPUT */
    global $INPUT;

    if(!is_readable($config_cascade['acl']['default'])) return array();

    $acl = file($config_cascade['acl']['default']);

    $out = array();
    foreach($acl as $line) {
        $line = trim($line);
        if(empty($line) || ($line{0} == '#')) continue; // skip blank lines & comments
        list($id,$rest) = preg_split('/[ \t]+/',$line,2);

        // substitute user wildcard first (its 1:1)
        if(strstr($line, '%USER%')){
            // if user is not logged in, this ACL line is meaningless - skip it
            if (!$INPUT->server->has('REMOTE_USER')) continue;

            $id   = str_replace('%USER%',cleanID($INPUT->server->str('REMOTE_USER')),$id);
            $rest = str_replace('%USER%',auth_nameencode($INPUT->server->str('REMOTE_USER')),$rest);
        }

        // substitute group wildcard (its 1:m)
        if(strstr($line, '%GROUP%')){
            // if user is not logged in, grps is empty, no output will be added (i.e. skipped)
            foreach((array) $USERINFO['grps'] as $grp){
                $nid   = str_replace('%GROUP%',cleanID($grp),$id);
                $nrest = str_replace('%GROUP%','@'.auth_nameencode($grp),$rest);
                $out[] = "$nid\t$nrest";
            }
        } else {
            $out[] = "$id\t$rest";
        }
    }

    return $out;
}

/**
 * Event hook callback for AUTH_LOGIN_CHECK
 *
 * @param array $evdata
 * @return bool
 */
function auth_login_wrapper($evdata) {
    return auth_login(
        $evdata['user'],
        $evdata['password'],
        $evdata['sticky'],
        $evdata['silent']
    );
}

/**
 * This tries to login the user based on the sent auth credentials
 *
 * The authentication works like this: if a username was given
 * a new login is assumed and user/password are checked. If they
 * are correct the password is encrypted with blowfish and stored
 * together with the username in a cookie - the same info is stored
 * in the session, too. Additonally a browserID is stored in the
 * session.
 *
 * If no username was given the cookie is checked: if the username,
 * crypted password and browserID match between session and cookie
 * no further testing is done and the user is accepted
 *
 * If a cookie was found but no session info was availabe the
 * blowfish encrypted password from the cookie is decrypted and
 * together with username rechecked by calling this function again.
 *
 * On a successful login $_SERVER[REMOTE_USER] and $USERINFO
 * are set.
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @param   string  $user    Username
 * @param   string  $pass    Cleartext Password
 * @param   bool    $sticky  Cookie should not expire
 * @param   bool    $silent  Don't show error on bad auth
 * @return  bool             true on successful auth
 */
function auth_login($user, $pass, $sticky = false, $silent = false) {
    global $USERINFO;
    global $conf;
    global $lang;
    /* @var DokuWiki_Auth_Plugin $auth */
    global $auth;
    /* @var Input $INPUT */
    global $INPUT;

    $sticky ? $sticky = true : $sticky = false; //sanity check

    if(!$auth) return false;

    if(!empty($user)) {
        //usual login
        if(!empty($pass) && $auth->checkPass($user, $pass)) {
            // make logininfo globally available
            $INPUT->server->set('REMOTE_USER', $user);
            $secret                 = auth_cookiesalt(!$sticky, true); //bind non-sticky to session
            auth_setCookie($user, auth_encrypt($pass, $secret), $sticky);
            return true;
        } else {
            //invalid credentials - log off
            if(!$silent) msg($lang['badlogin'], -1);
            auth_logoff();
            return false;
        }
    } else {
        // read cookie information
        list($user, $sticky, $pass) = auth_getCookie();
        if($user && $pass) {
            // we got a cookie - see if we can trust it

            // get session info
            $session = $_SESSION[DOKU_COOKIE]['auth'];
            if(isset($session) &&
                $auth->useSessionCache($user) &&
                ($session['time'] >= time() - $conf['auth_security_timeout']) &&
                ($session['user'] == $user) &&
                ($session['pass'] == sha1($pass)) && //still crypted
                ($session['buid'] == auth_browseruid())
            ) {

                // he has session, cookie and browser right - let him in
                $INPUT->server->set('REMOTE_USER', $user);
                $USERINFO               = $session['info']; //FIXME move all references to session
                return true;
            }
            // no we don't trust it yet - recheck pass but silent
            $secret = auth_cookiesalt(!$sticky, true); //bind non-sticky to session
            $pass   = auth_decrypt($pass, $secret);
            return auth_login($user, $pass, $sticky, true);
        }
    }
    //just to be sure
    auth_logoff(true);
    return false;
}

/**
 * Builds a pseudo UID from browser and IP data
 *
 * This is neither unique nor unfakable - still it adds some
 * security. Using the first part of the IP makes sure
 * proxy farms like AOLs are still okay.
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @return  string  a MD5 sum of various browser headers
 */
function auth_browseruid() {
    /* @var Input $INPUT */
    global $INPUT;

    $ip  = clientIP(true);
    $uid = '';
    $uid .= $INPUT->server->str('HTTP_USER_AGENT');
    $uid .= $INPUT->server->str('HTTP_ACCEPT_CHARSET');
    $uid .= substr($ip, 0, strpos($ip, '.'));
    $uid = strtolower($uid);
    return md5($uid);
}

/**
 * Creates a random key to encrypt the password in cookies
 *
 * This function tries to read the password for encrypting
 * cookies from $conf['metadir'].'/_htcookiesalt'
 * if no such file is found a random key is created and
 * and stored in this file.
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @param   bool $addsession if true, the sessionid is added to the salt
 * @param   bool $secure     if security is more important than keeping the old value
 * @return  string
 */
function auth_cookiesalt($addsession = false, $secure = false) {
    global $conf;
    $file = $conf['metadir'].'/_htcookiesalt';
    if ($secure || !file_exists($file)) {
        $file = $conf['metadir'].'/_htcookiesalt2';
    }
    $salt = io_readFile($file);
    if(empty($salt)) {
        $salt = bin2hex(auth_randombytes(64));
        io_saveFile($file, $salt);
    }
    if($addsession) {
        $salt .= session_id();
    }
    return $salt;
}

/**
 * Return truly (pseudo) random bytes if available, otherwise fall back to mt_rand
 *
 * @author Mark Seecof
 * @author Michael Hamann <michael@content-space.de>
 * @link   http://www.php.net/manual/de/function.mt-rand.php#83655
 *
 * @param int $length number of bytes to get
 * @return string binary random strings
 */
function auth_randombytes($length) {
    $strong = false;
    $rbytes = false;

    if (function_exists('openssl_random_pseudo_bytes')
        && (version_compare(PHP_VERSION, '5.3.4') >= 0
            || strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN')
    ) {
        $rbytes = openssl_random_pseudo_bytes($length, $strong);
    }

    if (!$strong && function_exists('mcrypt_create_iv')
        && (version_compare(PHP_VERSION, '5.3.7') >= 0
            || strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN')
    ) {
        $rbytes = mcrypt_create_iv($length, MCRYPT_DEV_URANDOM);
        if ($rbytes !== false && strlen($rbytes) === $length) {
            $strong = true;
        }
    }

    // If no strong randoms available, try OS the specific ways
    if(!$strong) {
        // Unix/Linux platform
        $fp = @fopen('/dev/urandom', 'rb');
        if($fp !== false) {
            $rbytes = fread($fp, $length);
            fclose($fp);
        }

        // MS-Windows platform
        if(class_exists('COM')) {
            // http://msdn.microsoft.com/en-us/library/aa388176(VS.85).aspx
            try {
                $CAPI_Util = new COM('CAPICOM.Utilities.1');
                $rbytes    = $CAPI_Util->GetRandom($length, 0);

                // if we ask for binary data PHP munges it, so we
                // request base64 return value.
                if($rbytes) $rbytes = base64_decode($rbytes);
            } catch(Exception $ex) {
                // fail
            }
        }
    }
    if(strlen($rbytes) < $length) $rbytes = false;

    // still no random bytes available - fall back to mt_rand()
    if($rbytes === false) {
        $rbytes = '';
        for ($i = 0; $i < $length; ++$i) {
            $rbytes .= chr(mt_rand(0, 255));
        }
    }

    return $rbytes;
}

/**
 * Random number generator using the best available source
 *
 * @author Michael Samuel
 * @author Michael Hamann <michael@content-space.de>
 *
 * @param int $min
 * @param int $max
 * @return int
 */
function auth_random($min, $max) {
    $abs_max = $max - $min;

    $nbits = 0;
    for ($n = $abs_max; $n > 0; $n >>= 1) {
        ++$nbits;
    }

    $mask = (1 << $nbits) - 1;
    do {
        $bytes    = auth_randombytes(PHP_INT_SIZE);
        $integers = unpack('Inum', $bytes);
        $integer  = $integers["num"] & $mask;
    } while ($integer > $abs_max);

    return $min + $integer;
}

/**
 * Encrypt data using the given secret using AES
 *
 * The mode is CBC with a random initialization vector, the key is derived
 * using pbkdf2.
 *
 * @param string $data   The data that shall be encrypted
 * @param string $secret The secret/password that shall be used
 * @return string The ciphertext
 */
function auth_encrypt($data, $secret) {
    $iv     = auth_randombytes(16);
    $cipher = new Crypt_AES();
    $cipher->setPassword($secret);

    /*
    this uses the encrypted IV as IV as suggested in
    http://csrc.nist.gov/publications/nistpubs/800-38a/sp800-38a.pdf, Appendix C
    for unique but necessarily random IVs. The resulting ciphertext is
    compatible to ciphertext that was created using a "normal" IV.
    */
    return $cipher->encrypt($iv.$data);
}

/**
 * Decrypt the given AES ciphertext
 *
 * The mode is CBC, the key is derived using pbkdf2
 *
 * @param string $ciphertext The encrypted data
 * @param string $secret     The secret/password that shall be used
 * @return string The decrypted data
 */
function auth_decrypt($ciphertext, $secret) {
    $iv     = substr($ciphertext, 0, 16);
    $cipher = new Crypt_AES();
    $cipher->setPassword($secret);
    $cipher->setIV($iv);

    return $cipher->decrypt(substr($ciphertext, 16));
}

/**
 * Log out the current user
 *
 * This clears all authentication data and thus log the user
 * off. It also clears session data.
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @param bool $keepbc - when true, the breadcrumb data is not cleared
 */
function auth_logoff($keepbc = false) {
    global $conf;
    global $USERINFO;
    /* @var DokuWiki_Auth_Plugin $auth */
    global $auth;
    /* @var Input $INPUT */
    global $INPUT;

    // make sure the session is writable (it usually is)
    @session_start();

    if(isset($_SESSION[DOKU_COOKIE]['auth']['user']))
        unset($_SESSION[DOKU_COOKIE]['auth']['user']);
    if(isset($_SESSION[DOKU_COOKIE]['auth']['pass']))
        unset($_SESSION[DOKU_COOKIE]['auth']['pass']);
    if(isset($_SESSION[DOKU_COOKIE]['auth']['info']))
        unset($_SESSION[DOKU_COOKIE]['auth']['info']);
    if(!$keepbc && isset($_SESSION[DOKU_COOKIE]['bc']))
        unset($_SESSION[DOKU_COOKIE]['bc']);
    $INPUT->server->remove('REMOTE_USER');
    $USERINFO = null; //FIXME

    $cookieDir = empty($conf['cookiedir']) ? DOKU_REL : $conf['cookiedir'];
    setcookie(DOKU_COOKIE, '', time() - 600000, $cookieDir, '', ($conf['securecookie'] && is_ssl()), true);

    if($auth) $auth->logOff();
}

/**
 * Check if a user is a manager
 *
 * Should usually be called without any parameters to check the current
 * user.
 *
 * The info is available through $INFO['ismanager'], too
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 * @see    auth_isadmin
 *
 * @param  string $user       Username
 * @param  array  $groups     List of groups the user is in
 * @param  bool   $adminonly  when true checks if user is admin
 * @return bool
 */
function auth_ismanager($user = null, $groups = null, $adminonly = false) {
    global $conf;
    global $USERINFO;
    /* @var DokuWiki_Auth_Plugin $auth */
    global $auth;
    /* @var Input $INPUT */
    global $INPUT;


    if(!$auth) return false;
    if(is_null($user)) {
        if(!$INPUT->server->has('REMOTE_USER')) {
            return false;
        } else {
            $user = $INPUT->server->str('REMOTE_USER');
        }
    }
    if(is_null($groups)) {
        $groups = (array) $USERINFO['grps'];
    }

    // check superuser match
    if(auth_isMember($conf['superuser'], $user, $groups)) return true;
    if($adminonly) return false;
    // check managers
    if(auth_isMember($conf['manager'], $user, $groups)) return true;

    return false;
}

/**
 * Check if a user is admin
 *
 * Alias to auth_ismanager with adminonly=true
 *
 * The info is available through $INFO['isadmin'], too
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 * @see auth_ismanager()
 *
 * @param  string $user       Username
 * @param  array  $groups     List of groups the user is in
 * @return bool
 */
function auth_isadmin($user = null, $groups = null) {
    return auth_ismanager($user, $groups, true);
}

/**
 * Match a user and his groups against a comma separated list of
 * users and groups to determine membership status
 *
 * Note: all input should NOT be nameencoded.
 *
 * @param string $memberlist commaseparated list of allowed users and groups
 * @param string $user       user to match against
 * @param array  $groups     groups the user is member of
 * @return bool       true for membership acknowledged
 */
function auth_isMember($memberlist, $user, array $groups) {
    /* @var DokuWiki_Auth_Plugin $auth */
    global $auth;
    if(!$auth) return false;

    // clean user and groups
    if(!$auth->isCaseSensitive()) {
        $user   = utf8_strtolower($user);
        $groups = array_map('utf8_strtolower', $groups);
    }
    $user   = $auth->cleanUser($user);
    $groups = array_map(array($auth, 'cleanGroup'), $groups);

    // extract the memberlist
    $members = explode(',', $memberlist);
    $members = array_map('trim', $members);
    $members = array_unique($members);
    $members = array_filter($members);

    // compare cleaned values
    foreach($members as $member) {
        if($member == '@ALL' ) return true;
        if(!$auth->isCaseSensitive()) $member = utf8_strtolower($member);
        if($member[0] == '@') {
            $member = $auth->cleanGroup(substr($member, 1));
            if(in_array($member, $groups)) return true;
        } else {
            $member = $auth->cleanUser($member);
            if($member == $user) return true;
        }
    }

    // still here? not a member!
    return false;
}

/**
 * Convinience function for auth_aclcheck()
 *
 * This checks the permissions for the current user
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @param  string  $id  page ID (needs to be resolved and cleaned)
 * @return int          permission level
 */
function auth_quickaclcheck($id) {
    global $conf;
    global $USERINFO;
    /* @var Input $INPUT */
    global $INPUT;
    # if no ACL is used always return upload rights
    if(!$conf['useacl']) return AUTH_UPLOAD;
    return auth_aclcheck($id, $INPUT->server->str('REMOTE_USER'), $USERINFO['grps']);
}

/**
 * Returns the maximum rights a user has for the given ID or its namespace
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @triggers AUTH_ACL_CHECK
 * @param  string       $id     page ID (needs to be resolved and cleaned)
 * @param  string       $user   Username
 * @param  array|null   $groups Array of groups the user is in
 * @return int             permission level
 */
function auth_aclcheck($id, $user, $groups) {
    $data = array(
        'id'     => $id,
        'user'   => $user,
        'groups' => $groups
    );

    return trigger_event('AUTH_ACL_CHECK', $data, 'auth_aclcheck_cb');
}

/**
 * default ACL check method
 *
 * DO NOT CALL DIRECTLY, use auth_aclcheck() instead
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @param  array $data event data
 * @return int   permission level
 */
function auth_aclcheck_cb($data) {
    $id     =& $data['id'];
    $user   =& $data['user'];
    $groups =& $data['groups'];

    global $conf;
    global $AUTH_ACL;
    /* @var DokuWiki_Auth_Plugin $auth */
    global $auth;

    // if no ACL is used always return upload rights
    if(!$conf['useacl']) return AUTH_UPLOAD;
    if(!$auth) return AUTH_NONE;

    //make sure groups is an array
    if(!is_array($groups)) $groups = array();

    //if user is superuser or in superusergroup return 255 (acl_admin)
    if(auth_isadmin($user, $groups)) {
        return AUTH_ADMIN;
    }

    if(!$auth->isCaseSensitive()) {
        $user   = utf8_strtolower($user);
        $groups = array_map('utf8_strtolower', $groups);
    }
    $user   = auth_nameencode($auth->cleanUser($user));
    $groups = array_map(array($auth, 'cleanGroup'), (array) $groups);

    //prepend groups with @ and nameencode
    foreach($groups as &$group) {
        $group = '@'.auth_nameencode($group);
    }

    $ns   = getNS($id);
    $perm = -1;

    //add ALL group
    $groups[] = '@ALL';

    //add User
    if($user) $groups[] = $user;

    //check exact match first
    $matches = preg_grep('/^'.preg_quote($id, '/').'[ \t]+([^ \t]+)[ \t]+/', $AUTH_ACL);
    if(count($matches)) {
        foreach($matches as $match) {
            $match = preg_replace('/#.*$/', '', $match); //ignore comments
            $acl   = preg_split('/[ \t]+/', $match);
            if(!$auth->isCaseSensitive() && $acl[1] !== '@ALL') {
                $acl[1] = utf8_strtolower($acl[1]);
            }
            if(!in_array($acl[1], $groups)) {
                continue;
            }
            if($acl[2] > AUTH_DELETE) $acl[2] = AUTH_DELETE; //no admins in the ACL!
            if($acl[2] > $perm) {
                $perm = $acl[2];
            }
        }
        if($perm > -1) {
            //we had a match - return it
            return (int) $perm;
        }
    }

    //still here? do the namespace checks
    if($ns) {
        $path = $ns.':*';
    } else {
        $path = '*'; //root document
    }

    do {
        $matches = preg_grep('/^'.preg_quote($path, '/').'[ \t]+([^ \t]+)[ \t]+/', $AUTH_ACL);
        if(count($matches)) {
            foreach($matches as $match) {
                $match = preg_replace('/#.*$/', '', $match); //ignore comments
                $acl   = preg_split('/[ \t]+/', $match);
                if(!$auth->isCaseSensitive() && $acl[1] !== '@ALL') {
                    $acl[1] = utf8_strtolower($acl[1]);
                }
                if(!in_array($acl[1], $groups)) {
                    continue;
                }
                if($acl[2] > AUTH_DELETE) $acl[2] = AUTH_DELETE; //no admins in the ACL!
                if($acl[2] > $perm) {
                    $perm = $acl[2];
                }
            }
            //we had a match - return it
            if($perm != -1) {
                return (int) $perm;
            }
        }
        //get next higher namespace
        $ns = getNS($ns);

        if($path != '*') {
            $path = $ns.':*';
            if($path == ':*') $path = '*';
        } else {
            //we did this already
            //looks like there is something wrong with the ACL
            //break here
            msg('No ACL setup yet! Denying access to everyone.');
            return AUTH_NONE;
        }
    } while(1); //this should never loop endless
    return AUTH_NONE;
}

/**
 * Encode ASCII special chars
 *
 * Some auth backends allow special chars in their user and groupnames
 * The special chars are encoded with this function. Only ASCII chars
 * are encoded UTF-8 multibyte are left as is (different from usual
 * urlencoding!).
 *
 * Decoding can be done with rawurldecode
 *
 * @author Andreas Gohr <gohr@cosmocode.de>
 * @see rawurldecode()
 *
 * @param string $name
 * @param bool $skip_group
 * @return string
 */
function auth_nameencode($name, $skip_group = false) {
    global $cache_authname;
    $cache =& $cache_authname;
    $name  = (string) $name;

    // never encode wildcard FS#1955
    if($name == '%USER%') return $name;
    if($name == '%GROUP%') return $name;

    if(!isset($cache[$name][$skip_group])) {
        if($skip_group && $name{0} == '@') {
            $cache[$name][$skip_group] = '@'.preg_replace_callback(
                '/([\x00-\x2f\x3a-\x40\x5b-\x60\x7b-\x7f])/',
                'auth_nameencode_callback', substr($name, 1)
            );
        } else {
            $cache[$name][$skip_group] = preg_replace_callback(
                '/([\x00-\x2f\x3a-\x40\x5b-\x60\x7b-\x7f])/',
                'auth_nameencode_callback', $name
            );
        }
    }

    return $cache[$name][$skip_group];
}

/**
 * callback encodes the matches
 *
 * @param array $matches first complete match, next matching subpatterms
 * @return string
 */
function auth_nameencode_callback($matches) {
    return '%'.dechex(ord(substr($matches[1],-1)));
}

/**
 * Create a pronouncable password
 *
 * The $foruser variable might be used by plugins to run additional password
 * policy checks, but is not used by the default implementation
 *
 * @author   Andreas Gohr <andi@splitbrain.org>
 * @link     http://www.phpbuilder.com/annotate/message.php3?id=1014451
 * @triggers AUTH_PASSWORD_GENERATE
 *
 * @param  string $foruser username for which the password is generated
 * @return string  pronouncable password
 */
function auth_pwgen($foruser = '') {
    $data = array(
        'password' => '',
        'foruser'  => $foruser
    );

    $evt = new Doku_Event('AUTH_PASSWORD_GENERATE', $data);
    if($evt->advise_before(true)) {
        $c = 'bcdfghjklmnprstvwz'; //consonants except hard to speak ones
        $v = 'aeiou'; //vowels
        $a = $c.$v; //both
        $s = '!$%&?+*~#-_:.;,'; // specials

        //use thre syllables...
        for($i = 0; $i < 3; $i++) {
            $data['password'] .= $c[auth_random(0, strlen($c) - 1)];
            $data['password'] .= $v[auth_random(0, strlen($v) - 1)];
            $data['password'] .= $a[auth_random(0, strlen($a) - 1)];
        }
        //... and add a nice number and special
        $data['password'] .= auth_random(10, 99).$s[auth_random(0, strlen($s) - 1)];
    }
    $evt->advise_after();

    return $data['password'];
}

/**
 * Sends a password to the given user
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @param string $user Login name of the user
 * @param string $password The new password in clear text
 * @return bool  true on success
 */
function auth_sendPassword($user, $password) {
    global $lang;
    /* @var DokuWiki_Auth_Plugin $auth */
    global $auth;
    if(!$auth) return false;

    $user     = $auth->cleanUser($user);
    $userinfo = $auth->getUserData($user, $requireGroups = false);

    if(!$userinfo['mail']) return false;

    $text = rawLocale('password');
    $trep = array(
        'FULLNAME' => $userinfo['name'],
        'LOGIN'    => $user,
        'PASSWORD' => $password
    );

    $mail = new Mailer();
    $mail->to($userinfo['name'].' <'.$userinfo['mail'].'>');
    $mail->subject($lang['regpwmail']);
    $mail->setBody($text, $trep);
    return $mail->send();
}

/**
 * Register a new user
 *
 * This registers a new user - Data is read directly from $_POST
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @return bool  true on success, false on any error
 */
function register() {
    global $lang;
    global $conf;
    /* @var DokuWiki_Auth_Plugin $auth */
    global $auth;
    global $INPUT;

    if(!$INPUT->post->bool('save')) return false;
    if(!actionOK('register')) return false;

    // gather input
    $login    = trim($auth->cleanUser($INPUT->post->str('login')));
    $fullname = trim(preg_replace('/[\x00-\x1f:<>&%,;]+/', '', $INPUT->post->str('fullname')));
    $email    = trim(preg_replace('/[\x00-\x1f:<>&%,;]+/', '', $INPUT->post->str('email')));
    $pass     = $INPUT->post->str('pass');
    $passchk  = $INPUT->post->str('passchk');

    if(empty($login) || empty($fullname) || empty($email)) {
        msg($lang['regmissing'], -1);
        return false;
    }

    if($conf['autopasswd']) {
        $pass = auth_pwgen($login); // automatically generate password
    } elseif(empty($pass) || empty($passchk)) {
        msg($lang['regmissing'], -1); // complain about missing passwords
        return false;
    } elseif($pass != $passchk) {
        msg($lang['regbadpass'], -1); // complain about misspelled passwords
        return false;
    }

    //check mail
    if(!mail_isvalid($email)) {
        msg($lang['regbadmail'], -1);
        return false;
    }

    //okay try to create the user
    if(!$auth->triggerUserMod('create', array($login, $pass, $fullname, $email))) {
        msg($lang['regfail'], -1);
        return false;
    }

    // send notification about the new user
    $subscription = new Subscription();
    $subscription->send_register($login, $fullname, $email);

    // are we done?
    if(!$conf['autopasswd']) {
        msg($lang['regsuccess2'], 1);
        return true;
    }

    // autogenerated password? then send password to user
    if(auth_sendPassword($login, $pass)) {
        msg($lang['regsuccess'], 1);
        return true;
    } else {
        msg($lang['regmailfail'], -1);
        return false;
    }
}

/**
 * Update user profile
 *
 * @author    Christopher Smith <chris@jalakai.co.uk>
 */
function updateprofile() {
    global $conf;
    global $lang;
    /* @var DokuWiki_Auth_Plugin $auth */
    global $auth;
    /* @var Input $INPUT */
    global $INPUT;

    if(!$INPUT->post->bool('save')) return false;
    if(!checkSecurityToken()) return false;

    if(!actionOK('profile')) {
        msg($lang['profna'], -1);
        return false;
    }

    $changes         = array();
    $changes['pass'] = $INPUT->post->str('newpass');
    $changes['name'] = $INPUT->post->str('fullname');
    $changes['mail'] = $INPUT->post->str('email');

    // check misspelled passwords
    if($changes['pass'] != $INPUT->post->str('passchk')) {
        msg($lang['regbadpass'], -1);
        return false;
    }

    // clean fullname and email
    $changes['name'] = trim(preg_replace('/[\x00-\x1f:<>&%,;]+/', '', $changes['name']));
    $changes['mail'] = trim(preg_replace('/[\x00-\x1f:<>&%,;]+/', '', $changes['mail']));

    // no empty name and email (except the backend doesn't support them)
    if((empty($changes['name']) && $auth->canDo('modName')) ||
        (empty($changes['mail']) && $auth->canDo('modMail'))
    ) {
        msg($lang['profnoempty'], -1);
        return false;
    }
    if(!mail_isvalid($changes['mail']) && $auth->canDo('modMail')) {
        msg($lang['regbadmail'], -1);
        return false;
    }

    $changes = array_filter($changes);

    // check for unavailable capabilities
    if(!$auth->canDo('modName')) unset($changes['name']);
    if(!$auth->canDo('modMail')) unset($changes['mail']);
    if(!$auth->canDo('modPass')) unset($changes['pass']);

    // anything to do?
    if(!count($changes)) {
        msg($lang['profnochange'], -1);
        return false;
    }

    if($conf['profileconfirm']) {
        if(!$auth->checkPass($INPUT->server->str('REMOTE_USER'), $INPUT->post->str('oldpass'))) {
            msg($lang['badpassconfirm'], -1);
            return false;
        }
    }

    if(!$auth->triggerUserMod('modify', array($INPUT->server->str('REMOTE_USER'), &$changes))) {
        msg($lang['proffail'], -1);
        return false;
    }

    // update cookie and session with the changed data
    if($changes['pass']) {
        list( /*user*/, $sticky, /*pass*/) = auth_getCookie();
        $pass = auth_encrypt($changes['pass'], auth_cookiesalt(!$sticky, true));
        auth_setCookie($INPUT->server->str('REMOTE_USER'), $pass, (bool) $sticky);
    }
    return true;
}

/**
 * Delete the current logged-in user
 *
 * @return bool true on success, false on any error
 */
function auth_deleteprofile(){
    global $conf;
    global $lang;
    /* @var DokuWiki_Auth_Plugin $auth */
    global $auth;
    /* @var Input $INPUT */
    global $INPUT;

    if(!$INPUT->post->bool('delete')) return false;
    if(!checkSecurityToken()) return false;

    // action prevented or auth module disallows
    if(!actionOK('profile_delete') || !$auth->canDo('delUser')) {
        msg($lang['profnodelete'], -1);
        return false;
    }

    if(!$INPUT->post->bool('confirm_delete')){
        msg($lang['profconfdeletemissing'], -1);
        return false;
    }

    if($conf['profileconfirm']) {
        if(!$auth->checkPass($INPUT->server->str('REMOTE_USER'), $INPUT->post->str('oldpass'))) {
            msg($lang['badpassconfirm'], -1);
            return false;
        }
    }

    $deleted = array();
    $deleted[] = $INPUT->server->str('REMOTE_USER');
    if($auth->triggerUserMod('delete', array($deleted))) {
        // force and immediate logout including removing the sticky cookie
        auth_logoff();
        return true;
    }

    return false;
}

/**
 * Send a  new password
 *
 * This function handles both phases of the password reset:
 *
 *   - handling the first request of password reset
 *   - validating the password reset auth token
 *
 * @author Benoit Chesneau <benoit@bchesneau.info>
 * @author Chris Smith <chris@jalakai.co.uk>
 * @author Andreas Gohr <andi@splitbrain.org>
 *
 * @return bool true on success, false on any error
 */
function act_resendpwd() {
    global $lang;
    global $conf;
    /* @var DokuWiki_Auth_Plugin $auth */
    global $auth;
    /* @var Input $INPUT */
    global $INPUT;

    if(!actionOK('resendpwd')) {
        msg($lang['resendna'], -1);
        return false;
    }

    $token = preg_replace('/[^a-f0-9]+/', '', $INPUT->str('pwauth'));

    if($token) {
        // we're in token phase - get user info from token

        $tfile = $conf['cachedir'].'/'.$token{0}.'/'.$token.'.pwauth';
        if(!file_exists($tfile)) {
            msg($lang['resendpwdbadauth'], -1);
            $INPUT->remove('pwauth');
            return false;
        }
        // token is only valid for 3 days
        if((time() - filemtime($tfile)) > (3 * 60 * 60 * 24)) {
            msg($lang['resendpwdbadauth'], -1);
            $INPUT->remove('pwauth');
            @unlink($tfile);
            return false;
        }

        $user     = io_readfile($tfile);
        $userinfo = $auth->getUserData($user, $requireGroups = false);
        if(!$userinfo['mail']) {
            msg($lang['resendpwdnouser'], -1);
            return false;
        }

        if(!$conf['autopasswd']) { // we let the user choose a password
            $pass = $INPUT->str('pass');

            // password given correctly?
            if(!$pass) return false;
            if($pass != $INPUT->str('passchk')) {
                msg($lang['regbadpass'], -1);
                return false;
            }

            // change it
            if(!$auth->triggerUserMod('modify', array($user, array('pass' => $pass)))) {
                msg($lang['proffail'], -1);
                return false;
            }

        } else { // autogenerate the password and send by mail

            $pass = auth_pwgen($user);
            if(!$auth->triggerUserMod('modify', array($user, array('pass' => $pass)))) {
                msg($lang['proffail'], -1);
                return false;
            }

            if(auth_sendPassword($user, $pass)) {
                msg($lang['resendpwdsuccess'], 1);
            } else {
                msg($lang['regmailfail'], -1);
            }
        }

        @unlink($tfile);
        return true;

    } else {
        // we're in request phase

        if(!$INPUT->post->bool('save')) return false;

        if(!$INPUT->post->str('login')) {
            msg($lang['resendpwdmissing'], -1);
            return false;
        } else {
            $user = trim($auth->cleanUser($INPUT->post->str('login')));
        }

        $userinfo = $auth->getUserData($user, $requireGroups = false);
        if(!$userinfo['mail']) {
            msg($lang['resendpwdnouser'], -1);
            return false;
        }

        // generate auth token
        $token = md5(auth_randombytes(16)); // random secret
        $tfile = $conf['cachedir'].'/'.$token{0}.'/'.$token.'.pwauth';
        $url   = wl('', array('do'=> 'resendpwd', 'pwauth'=> $token), true, '&');

        io_saveFile($tfile, $user);

        $text = rawLocale('pwconfirm');
        $trep = array(
            'FULLNAME' => $userinfo['name'],
            'LOGIN'    => $user,
            'CONFIRM'  => $url
        );

        $mail = new Mailer();
        $mail->to($userinfo['name'].' <'.$userinfo['mail'].'>');
        $mail->subject($lang['regpwmail']);
        $mail->setBody($text, $trep);
        if($mail->send()) {
            msg($lang['resendpwdconfirm'], 1);
        } else {
            msg($lang['regmailfail'], -1);
        }
        return true;
    }
    // never reached
}

/**
 * Encrypts a password using the given method and salt
 *
 * If the selected method needs a salt and none was given, a random one
 * is chosen.
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @param string $clear The clear text password
 * @param string $method The hashing method
 * @param string $salt A salt, null for random
 * @return  string  The crypted password
 */
function auth_cryptPassword($clear, $method = '', $salt = null) {
    global $conf;
    if(empty($method)) $method = $conf['passcrypt'];

    $pass = new PassHash();
    $call = 'hash_'.$method;

    if(!method_exists($pass, $call)) {
        msg("Unsupported crypt method $method", -1);
        return false;
    }

    return $pass->$call($clear, $salt);
}

/**
 * Verifies a cleartext password against a crypted hash
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 *
 * @param  string $clear The clear text password
 * @param  string $crypt The hash to compare with
 * @return bool true if both match
 */
function auth_verifyPassword($clear, $crypt) {
    $pass = new PassHash();
    return $pass->verify_hash($clear, $crypt);
}

/**
 * Set the authentication cookie and add user identification data to the session
 *
 * @param string  $user       username
 * @param string  $pass       encrypted password
 * @param bool    $sticky     whether or not the cookie will last beyond the session
 * @return bool
 */
function auth_setCookie($user, $pass, $sticky) {
    global $conf;
    /* @var DokuWiki_Auth_Plugin $auth */
    global $auth;
    global $USERINFO;

    if(!$auth) return false;
    $USERINFO = $auth->getUserData($user);

    // set cookie
    $cookie    = base64_encode($user).'|'.((int) $sticky).'|'.base64_encode($pass);
    $cookieDir = empty($conf['cookiedir']) ? DOKU_REL : $conf['cookiedir'];
    $time      = $sticky ? (time() + 60 * 60 * 24 * 365) : 0; //one year
    setcookie(DOKU_COOKIE, $cookie, $time, $cookieDir, '', ($conf['securecookie'] && is_ssl()), true);

    // set session
    $_SESSION[DOKU_COOKIE]['auth']['user'] = $user;
    $_SESSION[DOKU_COOKIE]['auth']['pass'] = sha1($pass);
    $_SESSION[DOKU_COOKIE]['auth']['buid'] = auth_browseruid();
    $_SESSION[DOKU_COOKIE]['auth']['info'] = $USERINFO;
    $_SESSION[DOKU_COOKIE]['auth']['time'] = time();

    return true;
}

/**
 * Returns the user, (encrypted) password and sticky bit from cookie
 *
 * @returns array
 */
function auth_getCookie() {
    if(!isset($_COOKIE[DOKU_COOKIE])) {
        return array(null, null, null);
    }
    list($user, $sticky, $pass) = explode('|', $_COOKIE[DOKU_COOKIE], 3);
    $sticky = (bool) $sticky;
    $pass   = base64_decode($pass);
    $user   = base64_decode($user);
    return array($user, $sticky, $pass);
}

//Setup VIM: ex: et ts=2 :
