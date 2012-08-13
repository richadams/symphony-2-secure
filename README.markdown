# Symphony CMS Secure #

This is an attempt to modify Symphony CMS to make it more secure. My changes are managed on a few different branches,

Main branch:

  * `secure` - This is where all my changes are merged to. This has every change I've made.

Feature branches:

  * `pdo` - Changing the database interaction to use PDO prepared statements instead of `mysql_*` based functions. It is backwards compatible.
  * `passwords` - Modifications to password storage so it uses `crypt()` instead of plain SHA1 hashes. It is backwards compatible, so any users with current SHA1 passwords will be upgraded to the new method on their next login.
  * `sessions` - Various changes to the session management to prevent session fixation, destroy suspicious sessions, remove password hash from being stored in session, etc.
  * `xsrf` - Adds XSRF/CSRF (Cross-site Request Forgery) protection to the admin area. This just adds a git submodule pointing to my respository for the extension (https://github.com/richadams/xsrf_protection).

**Why?**

Why not?

**Can I replace my Symphony CMS install with this version?**

Sure, but fair warning, you should be aware that there may well be bugs, so I'd advise against putting this into a production environment without reviewing the code changes first. Use at your own risk, etc.

See https://github.com/symphonycms/symphony-2 for instructions on how to install and use Symphony CMS.

Here's a description of each change that's been made,

-----

# PDO #

Branch: `pdo`

This is a modification to the Symphony 2 CMS to use PDO in place of `mysql_*` functions. It uses prepared statements whenever possible and it *should be* backwards compatible with all current code. 

**Here's what's bad and why I'm changing it:**

  * `mysql_*` functions are actively discouraged from being used. A badly written extension could leave the CMS open to SQL injection. Using PDO prepared statements would prevent this.

**Here's what's changed:**

  * `symphony/lib/toolkit/class.pdo.php` - The new PDO class. A replacement for the `class.mysql.php` file.
  * `symphony/lib/toolkit/class.mysql.original.php` - The original `class.mysql.php` file, renamed.
  * `symphony/lib/toolkit/class.mysql.php` - A symlink to `class.pdo.php`. Change the symlink to the `class.mysql.original.php` file to revert to default MySQL handling.

No special configuration required, you should just be able to use the file structure as above, or overwrite `class.mysql.php` with `class.pdo.php` and you're good to go.

Tested with v2.3, but use at your own risk!  

***Full disclosure:*** I haven't massively tested this, it's very likely that there are extensions out there that will break with the new class. It's also likely that because not all queries can be parameterised, that there may be a way to inject SQL. Inputs are cleaned whenever possible, but unless prepared statements are always used, it can't be guaranteed that's there's no SQL injection.


-----

# Secure Session Management #

Branch: `sessions`

This is a modification to the Symphony 2 CMS to have more secure session management.  

**Here's what's bad and why I'm changing it:**

  * Password hashes are stored in the session, there's no need for this.
  * The username is stored in the session, but then used to lookup the user ID before authenticating on every session. Storing the user ID instead would prevent this extra lookup.
  * Users are being re-authenticated on every request from the username/password in the session. This is completely pointless since all you need is a valid session ID anyway. This re-auth does nothing extra. A flag to say that the user is logged in is sufficient.
  * When a user logs in, they have the same session ID. This allows for session fixation attacks.
  * The session cookies use the default PHP name, this leaks that PHP is being used (although I guess you could infer that from the fact it's running Symphony).
  * The session cookies can not have the `secure` flag set.
  * The session is not being cleared when a user logs out.
  * Suspicious sessions are not being destroyed. If I suddently change my IP address radically, I still have a valid session, making session fixation easier.

**Here's what's changed:**

  * Password hash is no longer stored in the session.
  * Username is no longer stored in the session.
  * Authentication isn't done on every request, only a "logged_in" flag in the session is used.
  * Session ID is regenerated when privileges are raised.
  * Session cookies now use non-default name.
  * Suspicious sessions are destroyed.
  * `httpOnly` and `secure` flags can now be set from the config file.
  * Session is cleared when a user logs out.

You will need to manually add some things to your configuration file.

    // Session
    "session"   => array("gc_divisor"    => 10,          // Used for garbage collection.
                         "name"          => "sym",       // Used as cookie name.
                         "lifetime"      => "24 hours",  // How long should the session last.
                         "inactive_time" => "15 mins",   // How long can a session be inactive before expiring.
                         "httponly"      => true,        // Set the HttpOnly flag for cookies (HTTP).
                         "secure"        => false),      // Set the secure flag for cookies (HTTPS).

Tested in v2.3, but use at your own risk!

-----

# Secure Password Management #

Branch: `passwords`

This is a modification to the Symphony 2 CMS to have more secure password management.  

Passwords will be stored much more securely than the default method. However, the authentication will remain backwards compatible so that the next time a user logs in, it will upgrade them to the new method.

**Here's what's bad and why I'm changing it:**

  * Passwords are stored as plain (unsalted) SHA1 hashes. This is a far cry from secure. Passwords need to be salted and stretched as an absolute minimum. Google "LinkedIn password leak" to see why plain SHA1 is a bad idea.

**Here's what's changed:**

  * Use crypt() for passwords instead of SHA1. Can use SHA512 with as many rounds as you want, or Blowfish. See the diff, it should be obvious where to change this.
  * Salts are generated using `openssl_random_pseudo_bytes` but will fallback to `/dev/urandom` if that's not available.

You will need to make the following database change to support the longer password field.

    ALTER TABLE `sym_authors` MODIFY COLUMN `password` VARCHAR(150)  CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL;

Tested in v2.3, but use at your own risk!

-----

# XSRF Protection #

Branch: `xsrf`

This adds the `xsrf_protection` extension from my other repository (https://github.com/richadams/xsrf_protection) as a default extension.

**Here's what's bad and why I'm changing it:**

  * The admin area has no form of XSRF/CSRF protection.

**Here's what's changed:**

  * All backend POST forms will now have a nonce value added to the inputs. Any POST request will be checked for a valid value, and those without a valid value will be rejected.

You will need to add the following to your configuration file,
  
        "xsrf-protection" => array("token-lifetime"               => "15 mins", // How long the tokens are valid for.
                                   "invalidate-tokens-on-request" => true),     // If true, then tokens are invalidated on every request or after expiry time, whichever is first. If false, tokens only expire after the lifetime.

Tested with v2.3, but use at your own risk!
