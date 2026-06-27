<?php
session_start();
session_destroy();
header("Location: ../index.php"); // ระบุ path แบบ absolute
exit;