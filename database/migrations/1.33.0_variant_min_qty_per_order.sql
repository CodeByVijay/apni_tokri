-- Minimum units required per variant line in a single order (default 1).
ALTER TABLE `product_variants`
  ADD COLUMN `min_qty_per_order` INT NOT NULL DEFAULT 1
  COMMENT 'Minimum units per order'
  AFTER `max_qty_per_order`;
