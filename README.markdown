# XSRF #

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

See https://github.com/symphonycms/symphony-2 for instructions on how to install and use Symphony CMS.
