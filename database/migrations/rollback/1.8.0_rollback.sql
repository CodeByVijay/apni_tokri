-- Manual rollback for 1.8.0 — run only after disabling distance_hybrid in admin (pincode_legacy).
-- Order: drop FK if added later, drop columns, reset settings.

SET NAMES utf8mb4;

UPDATE `settings` SET `setting_value` = 'pincode_legacy' WHERE `setting_key` = 'delivery.zone_resolution_mode';

ALTER TABLE `product_variants` DROP COLUMN IF EXISTS `weight_kg`;

ALTER TABLE `delivery_slots`
  DROP COLUMN IF EXISTS `max_distance_km`,
  DROP COLUMN IF EXISTS `booking_cutoff_minutes`,
  DROP COLUMN IF EXISTS `prep_buffer_minutes`,
  DROP COLUMN IF EXISTS `weekday_mask`;

ALTER TABLE `delivery_zones`
  DROP COLUMN IF EXISTS `pincode_required`,
  DROP COLUMN IF EXISTS `eta_display`,
  DROP COLUMN IF EXISTS `max_order_weight_kg`,
  DROP COLUMN IF EXISTS `max_distance_km`,
  DROP COLUMN IF EXISTS `min_distance_km`,
  DROP COLUMN IF EXISTS `zone_kind`;

ALTER TABLE `delivery_store`
  DROP COLUMN IF EXISTS `manual_delivery_when_provider_unavailable`,
  DROP COLUMN IF EXISTS `require_coordinates_for_checkout`,
  DROP COLUMN IF EXISTS `fallback_radius_zone_id`,
  DROP COLUMN IF EXISTS `overlap_policy`,
  DROP COLUMN IF EXISTS `road_distance_config_json`,
  DROP COLUMN IF EXISTS `road_distance_provider`,
  DROP COLUMN IF EXISTS `default_distance_mode`;

UPDATE `settings` SET `setting_value` = '1.7.1' WHERE `setting_key` = 'schema_version';
