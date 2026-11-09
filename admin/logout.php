<?php
session_start();
session_destroy();
header("Location: ../index.php?mode=staff"); // ระบุ path แบบ absolute
exit;