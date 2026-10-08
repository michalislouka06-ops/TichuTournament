<?php
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $_SESSION = [];
    session_regenerate_id(true);
    flash('ok', 'You have been logged out. See you at the tables!');
}
redirect('login.php');
