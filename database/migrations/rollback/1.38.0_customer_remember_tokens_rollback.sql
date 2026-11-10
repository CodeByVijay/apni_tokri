-- Rollback 1.38.0 customer remember-login tokens (removes persistent login capability only).
DROP TABLE IF EXISTS `customer_remember_tokens`;
