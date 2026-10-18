<?php
require_once 'config.php';
checkAuth(['customer']);
header('Location: cart.php');
exit;