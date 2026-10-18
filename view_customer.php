<?php
/**
 * view_customer.php
 *
 * The canonical customer directory page is staff_customers.php.
 * This alias exists so old bookmarks and external links keep working.
 */
require_once 'config.php';
checkAuth(['admin', 'staff']);

header('Location: staff_customers.php', true, 302);
exit;