<?php
/*

Module: autoload
Version: 1.0
Author: Richard Catto
Creation Date: 2014-08-23

Description:
This function loads class files as required

*/

// load Swiftmailer email sending library
require_once "/usr/local/lib/php/swiftmailer/lib/swift_required.php";

// Load recaptcha library
// require_once "/usr/local/lib/php/recaptchalib.php";

function ctnlist_autoload($class_name) {
  $path = CTNLIST_CLASS_DIRECTORY . $class_name . '.php';
  if (file_exists($path)) {
    require_once $path;
  }
}

spl_autoload_register('ctnlist_autoload');

?>
