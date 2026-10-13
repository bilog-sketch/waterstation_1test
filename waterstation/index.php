<?php
require __DIR__ . '/includes/bootstrap.php';
redirect(uid() ? 'modules/dashboard.php' : 'login.php');
