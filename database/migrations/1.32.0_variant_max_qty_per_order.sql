-- Per-variant maximum quantity per storefront order (NULL = unlimited per order; default 20).
ALTER TABLE `product_variants`
  ADD COLUMN `max_qty_per_order` INT NULL DEFAULT 20
  COMMENT 'Max units per order; NULL = unlimited'
  AFTER `low_stock_threshold`;
