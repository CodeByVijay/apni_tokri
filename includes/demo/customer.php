<?php

declare(strict_types=1);

/** Backward-compatible customer API — delegates to CustomerService (Stage 7). */
final class DemoCustomer
{
    public static function isLoggedIn(): bool
    {
        return CustomerService::isLoggedIn();
    }

    /** @return array<string, string> */
    public static function profile(): array
    {
        return CustomerService::profile();
    }

    public static function login(string $mobile, string $name): void
    {
        CustomerService::loginDemoSession($mobile, $name);
    }

    public static function logout(): void
    {
        CustomerService::logout();
    }

    /** @return list<array<string, string>> */
    public static function addresses(): array
    {
        return CustomerService::addresses();
    }

    public static function addAddress(string $line1, string $pincode, string $label = 'Home'): void
    {
        CustomerService::addAddress($line1, $pincode, $label);
    }

    /** @param array<string, mixed> $order */
    public static function saveOrder(array $order): string
    {
        return SessionCustomerBackend::saveOrder($order);
    }

    /** @return array<string, mixed>|null */
    public static function order(string $id): ?array
    {
        return CustomerService::order($id);
    }

    /** @return list<array<string, mixed>> */
    public static function orders(): array
    {
        return CustomerService::orders();
    }
}
