/* Logout - logout.php */
<?php
session_start();
session_destroy();
header("Location: https://edrd11.creativeworkspace-bz.com/");
exit();
?>