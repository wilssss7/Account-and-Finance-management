<?php
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    logout_user();
    set_flash('success', 'You have been logged out.');
}
redirect('login.php');
