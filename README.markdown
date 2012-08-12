# Symphony 2 Secure Session Management #

This is a modification to the Symphony 2 CMS to have more secure session management.  

*Here's what's bad and why I'm changing it:*

  * Password hashes are stored in the session, there's no need for this.
  * The username is stored in the session, but then used to lookup the user ID before authenticating on every session. Storing the user ID instead would prevent this extra lookup.
  * Users are being re-authenticated on every request from the username/password in the session. This is completely pointless since all you need is a valid session ID anyway. This re-auth does nothing extra. A flag to say that the user is logged in is sufficient.
  * When a user logs in, they have the same session ID. This allows for session fixation attacks.
  * The session cookies use the default PHP name, this leaks that PHP is being used (although I guess you could infer that from the fact it's running Symphony).
  * The session cookies can not have the `secure` flag set.
  * The session is not being cleared when a user logs out.
  * Suspicious sessions are not being destroyed. If I suddently change my IP address radically, I still have a valid session, making session fixation easier.

*Here's what's changed:*

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

See https://github.com/symphonycms/symphony-2 for instructions on how to install and use Symphony CMS.
