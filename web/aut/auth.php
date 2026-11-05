<?php
require_once __DIR__ . '/../lib/bootstrap.php';

function checkAuth(string $login, string $pass): bool
{
    return relay_check_password($login, $pass);
}

function getUserLogin(): ?string
{
    return relay_current_user();
}
