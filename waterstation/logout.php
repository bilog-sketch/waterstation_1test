<?php
require __DIR__ . '/includes/bootstrap.php';
if (uid()) audit('logout', 'user', uid());
$_SESSION = [];
session_regenerate_id(true);
session_destroy();
session_start();
flash('success', 'You have been logged out.');
redirect('login.php');
