<?php

declare(strict_types=1);

/**
 * Helpers for admin/auth tests on throwaway databases only.
 */

function disposable_test_set_admin_password(int $adminId = 1): string
{
    $password = 'Tst_' . bin2hex(random_bytes(12)) . '9aA';
    $hash = password_hash($password, PASSWORD_BCRYPT);
    Database::pdo()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')->execute([$hash, $adminId]);

    return $password;
}

/** Seed-hash admin login for disposable DB suites (password via env, never committed). */
function disposable_test_seed_admin_password_from_env(): ?string
{
    $password = getenv('APNI_TEST_SEED_ADMIN_PASSWORD');
    if (!is_string($password) || $password === '') {
        return null;
    }
    if (!password_verify($password, Install::SEED_ADMIN_PASSWORD_HASH)) {
        fwrite(STDERR, "APNI_TEST_SEED_ADMIN_PASSWORD does not match Install::SEED_ADMIN_PASSWORD_HASH.\n");

        return null;
    }

    return $password;
}
