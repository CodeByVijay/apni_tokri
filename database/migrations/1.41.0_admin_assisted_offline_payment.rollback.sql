DELETE arp FROM `admin_role_permissions` arp
INNER JOIN `admin_permissions` p ON p.id = arp.permission_id
WHERE p.slug = 'orders.record_offline_payment';

DELETE FROM `admin_permissions` WHERE `slug` = 'orders.record_offline_payment';
