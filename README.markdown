# Symphony 2 PDO #

This is a modification to the Symphony 2 CMS to use PDO in place of `mysql_*` functions. It uses prepared statements whenever possible and it *should be* backwards compatible with all current code. 

*Here's what's bad and why I'm changing it:*

  * `mysql_*` functions are actively discouraged from being used. A badly written extension could leave the CMS open to SQL injection. Using PDO prepared statements would prevent this.

*Here's what's changed:*

  * `symphony/lib/toolkit/class.pdo.php` - The new PDO class. A replacement for the `class.mysql.php` file.
  * `symphony/lib/toolkit/class.mysql.original.php` - The original `class.mysql.php` file, renamed.
  * `symphony/lib/toolkit/class.mysql.php` - A symlink to `class.pdo.php`. Change the symlink to the `class.mysql.original.php` file to revert to default MySQL handling.

No special configuration required, you should just be able to use the file structure as above, or overwrite `class.mysql.php` with `class.pdo.php` and you're good to go.

Tested with v2.3, but use at your own risk!  

***Full disclosure:*** I haven't massively tested this, it's very likely that there are extensions out there that will break with the new class. It's also likely that because not all queries can be parameterised, that there may be a way to inject SQL. Inputs are cleaned whenever possible, but unless prepared statements are always used, it can't be guaranteed that's there's no SQL injection.

See https://github.com/symphonycms/symphony-2 for instructions on how to install and use Symphony CMS.
