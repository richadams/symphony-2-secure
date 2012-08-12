# Symphony 2 - Secure Password Management #

This is a modification to the Symphony 2 CMS to have more secure password management.  

Passwords will be stored much more securely than the default method. However, the authentication will remain backwards compatible so that the next time a user logs in, it will upgrade them to the new method.

*Here's what's bad and why I'm changing it:*

  * Passwords are stored as plain SHA1 hashes. This is a far cry from secure. Passwords need to be salted and stretched at the very least.

*Here's what's changed:*

  * Use crypt() for passwords instead of SHA1.
  * Salts are generated using `openssl_random_pseudo_bytes` but will fallback to `/dev/urandom` if that's not available.

You will need to make the following database change to support the longer password field.

    ALTER TABLE `sym_authors` MODIFY COLUMN `password` VARCHAR(150)  CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL;

Tested in v2.3, but use at your own risk!

See https://github.com/symphonycms/symphony-2 for instructions on how to install and use Symphony CMS.
