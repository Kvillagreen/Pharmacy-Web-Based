-- Sample 2025 transaction seed for the Pharmacy Web Based database.
-- Target DB: MySQL / MariaDB, matching the current Laravel migration schema.
-- This script uses seeded company/branch records, then inserts owner access,
-- branch 1 and branch 2 inventory, and sample transactions with 2025 dates.

START TRANSACTION;

SELECT @branch_1_id := branch_id
FROM branches
WHERE branch_name = 'Sto. Rosario Main Branch'
ORDER BY branch_id
LIMIT 1;

SELECT @branch_2_id := branch_id
FROM branches
WHERE branch_name = 'Sto. Rosario Main Branch 2'
ORDER BY branch_id
LIMIT 1;

INSERT INTO users (
  branch_id,
  first_name,
  last_name,
  email,
  password,
  status,
  role,
  address,
  login_at,
  registered_ip,
  last_login_ip,
  last_seen_ip,
  created_at,
  updated_at
) VALUES (
  @branch_1_id,
  'Store',
  'Owner',
  'owner@gmail.com',
  '$2y$12$tR7y8Sn.5iEmc1OgjSu5fuOtokyXLg/uCCOIBw1VMuWpXIIeruHO2',
  'approved',
  'owner',
  'Sto. Rosario, San Fernando, Pampanga',
  '2025-01-02 08:07:00',
  '127.0.0.1',
  '127.0.0.1',
  '127.0.0.1',
  '2025-01-02 08:07:00',
  '2025-01-02 08:07:00'
) ON DUPLICATE KEY UPDATE
  user_id = LAST_INSERT_ID(user_id),
  branch_id = VALUES(branch_id),
  first_name = VALUES(first_name),
  last_name = VALUES(last_name),
  password = VALUES(password),
  status = VALUES(status),
  role = VALUES(role),
  updated_at = VALUES(updated_at);
SET @owner_user_id := LAST_INSERT_ID();

INSERT INTO permissions (permission_name, description, created_at, updated_at) VALUES
('dashboard', 'Can access dashboard', '2025-01-02 08:08:00', '2025-01-02 08:08:00'),
('sales', 'Can access sales page', '2025-01-02 08:08:00', '2025-01-02 08:08:00'),
('sms', 'Can access sms page', '2025-01-02 08:08:00', '2025-01-02 08:08:00'),
('inventory', 'Can access inventory page', '2025-01-02 08:08:00', '2025-01-02 08:08:00'),
('fefo', 'Can access fefo page', '2025-01-02 08:08:00', '2025-01-02 08:08:00'),
('drugs', 'Can access drugs page', '2025-01-02 08:08:00', '2025-01-02 08:08:00'),
('delivery', 'Can access delivery page', '2025-01-02 08:08:00', '2025-01-02 08:08:00'),
('reports', 'Can access reports page', '2025-01-02 08:08:00', '2025-01-02 08:08:00'),
('settings', 'Can access settings page', '2025-01-02 08:08:00', '2025-01-02 08:08:00'),
('users', 'Can access users page', '2025-01-02 08:08:00', '2025-01-02 08:08:00'),
('branches', 'Can access branches page', '2025-01-02 08:08:00', '2025-01-02 08:08:00'),
('users_all_branches', 'Can view users across all branches in the same company', '2025-01-02 08:08:00', '2025-01-02 08:08:00')
ON DUPLICATE KEY UPDATE
  description = VALUES(description),
  updated_at = VALUES(updated_at);

INSERT INTO user_permissions (user_id, permission_id, created_at, updated_at)
SELECT @owner_user_id, permission_id, '2025-01-02 08:08:00', '2025-01-02 08:08:00'
FROM permissions
ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at);

INSERT INTO users (
  branch_id,
  first_name,
  last_name,
  email,
  password,
  status,
  role,
  address,
  login_at,
  registered_ip,
  last_login_ip,
  last_seen_ip,
  created_at,
  updated_at
) VALUES (
  @branch_1_id,
  'Sample',
  'Pharmacist',
  'sample.pharmacist.2025@storosariodrugstore.test',
  '$2y$12$QWERTYuiopASDFGHjklZXOqv8Y4uQkFo2Y9Itedx2E6lkK0rD9QxG',
  'approved',
  'pharmacist',
  'Sto. Rosario, Sample City',
  '2025-01-02 08:10:00',
  '127.0.0.1',
  '127.0.0.1',
  '127.0.0.1',
  '2025-01-02 08:10:00',
  '2025-01-02 08:10:00'
) ON DUPLICATE KEY UPDATE
  user_id = LAST_INSERT_ID(user_id),
  branch_id = VALUES(branch_id),
  first_name = VALUES(first_name),
  last_name = VALUES(last_name),
  status = VALUES(status),
  role = VALUES(role),
  updated_at = VALUES(updated_at);
SET @user_id := LAST_INSERT_ID();

INSERT INTO medicines (
  medicine_name,
  generic_name,
  category,
  price,
  reorder_level,
  stocks,
  dosage,
  unit,
  type,
  is_dangerous,
  is_yakap_eligible,
  needs_protection,
  created_at,
  updated_at
) VALUES (
  'Biogesic',
  'Paracetamol',
  'Analgesic',
  8.50,
  20,
  100,
  500,
  'mg',
  'Tablet',
  0,
  0,
  0,
  '2025-01-02 08:20:00',
  '2025-01-02 08:20:00'
);
SET @medicine_id := LAST_INSERT_ID();

INSERT INTO batches (
  batch_number,
  expiry_date,
  received_date,
  mfg_date,
  location,
  status,
  created_at,
  updated_at
) VALUES (
  '230145',
  '2026-12-31',
  '2025-01-02',
  '2024-12-01',
  'Shelf 3-A',
  'active',
  '2025-01-02 08:25:00',
  '2025-01-02 08:25:00'
);
SET @batch_id := LAST_INSERT_ID();

INSERT INTO inventories (
  branch_id,
  medicine_id,
  batch_id,
  stocks,
  created_at,
  updated_at
) VALUES (
  @branch_1_id,
  @medicine_id,
  @batch_id,
  98,
  '2025-01-02 08:30:00',
  '2025-01-15 14:30:00'
);
SET @inventory_id := LAST_INSERT_ID();

INSERT INTO inventories (
  branch_id,
  medicine_id,
  batch_id,
  stocks,
  created_at,
  updated_at
) VALUES (
  @branch_2_id,
  @medicine_id,
  @batch_id,
  75,
  '2025-01-02 08:31:00',
  '2025-01-15 15:10:00'
);
SET @branch_2_inventory_id := LAST_INSERT_ID();

INSERT INTO transactions (
  user_id,
  branch_id,
  transaction_type,
  patient_name,
  membership_id,
  prescription_path,
  member_id_image_path,
  documents_submitted,
  total_amount,
  payment_method,
  reference_number,
  sub_total,
  `change`,
  used_amount,
  discount,
  discount_type,
  scpwd_id_number,
  regulated_customer_id,
  customer_contact_number,
  customer_id_number,
  customer_address_line,
  customer_barangay,
  customer_city_municipality,
  customer_province,
  customer_postal_code,
  customer_country,
  customer_formatted_address,
  regulated_classification,
  regulated_details,
  created_at,
  updated_at
) VALUES (
  @user_id,
  @branch_1_id,
  'regular',
  NULL,
  NULL,
  NULL,
  NULL,
  0,
  17.00,
  'Cash',
  'BR1-20250115-0001',
  17.00,
  3.00,
  20.00,
  0.00,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  'Philippines',
  NULL,
  NULL,
  NULL,
  '2025-01-15 14:30:00',
  '2025-01-15 14:30:00'
);
SET @branch_1_transaction_id := LAST_INSERT_ID();

INSERT INTO transaction_items (
  medicine_id,
  transaction_id,
  batch_id,
  batch_number,
  expiry_date,
  mfg_date,
  quantity,
  price,
  created_at,
  updated_at
) VALUES (
  @medicine_id,
  @branch_1_transaction_id,
  @batch_id,
  '230145',
  '2026-12-31',
  '2024-12-01',
  2,
  8.50,
  '2025-01-15 14:30:00',
  '2025-01-15 14:30:00'
);

INSERT INTO transactions (
  user_id,
  branch_id,
  transaction_type,
  patient_name,
  membership_id,
  prescription_path,
  member_id_image_path,
  documents_submitted,
  total_amount,
  payment_method,
  reference_number,
  sub_total,
  `change`,
  used_amount,
  discount,
  discount_type,
  scpwd_id_number,
  regulated_customer_id,
  customer_contact_number,
  customer_id_number,
  customer_address_line,
  customer_barangay,
  customer_city_municipality,
  customer_province,
  customer_postal_code,
  customer_country,
  customer_formatted_address,
  regulated_classification,
  regulated_details,
  created_at,
  updated_at
) VALUES (
  @owner_user_id,
  @branch_2_id,
  'regular',
  NULL,
  NULL,
  NULL,
  NULL,
  0,
  25.50,
  'Cash',
  'BR2-20250115-0001',
  25.50,
  24.50,
  50.00,
  0.00,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  NULL,
  'Philippines',
  NULL,
  NULL,
  NULL,
  '2025-01-15 15:10:00',
  '2025-01-15 15:10:00'
);
SET @branch_2_transaction_id := LAST_INSERT_ID();

INSERT INTO transaction_items (
  medicine_id,
  transaction_id,
  batch_id,
  batch_number,
  expiry_date,
  mfg_date,
  quantity,
  price,
  created_at,
  updated_at
) VALUES (
  @medicine_id,
  @branch_2_transaction_id,
  @batch_id,
  '230145',
  '2026-12-31',
  '2024-12-01',
  3,
  8.50,
  '2025-01-15 15:10:00',
  '2025-01-15 15:10:00'
);

COMMIT;

-- Created rows:
-- uses branch_1_id: @branch_1_id
-- uses branch_2_id: @branch_2_id
-- user_id:         @user_id
-- owner_user_id:   @owner_user_id
-- medicine_id:     @medicine_id
-- batch_id:        @batch_id
-- inventory_id:    @inventory_id
-- branch_1_transaction_id:  @branch_1_transaction_id
-- branch_2_transaction_id:  @branch_2_transaction_id
