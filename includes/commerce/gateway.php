<?php

declare(strict_types=1);

final class CommerceGateway
{
    public static function useDatabase(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            return ProductRepository::productCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
