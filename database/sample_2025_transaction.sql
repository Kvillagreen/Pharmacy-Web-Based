-- Sample 2025 transaction seed for the Pharmacy Web Based database.
-- Target DB: MySQL / MariaDB, matching the current Laravel migration schema.
-- This script creates the needed parent records first, then inserts one sale
-- transaction and one transaction item with 2025 created_at/updated_at values.

START TRANSACTION;

INSERT INTO companies (
  company_name,
  tin_number,
  company_email,
  created_at,
  updated_at
) VALUES (
  'Sto. Rosario Drug Store',
  '123-456-789-000',
  'sample-2025@storosariodrugstore.test',
  '2025-01-02 08:00:00',
  '2025-01-02 08:00:00'
);
SET @company_id := LAST_INSERT_ID();

INSERT INTO branches (
  company_id,
  branch_name,
  branch_address,
  branch_contact,
  status,
  created_at,
  updated_at
) VALUES (
  @company_id,
  'Sto. Rosario Main Branch',
  'Sto. Rosario, Sample City',
  '09171194119',
  'active',
  '2025-01-02 08:05:00',
  '2025-01-02 08:05:00'
);
SET @branch_id := LAST_INSERT_ID();

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
  @branch_id,
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
  @branch_id,
  @medicine_id,
  @batch_id,
  98,
  '2025-01-02 08:30:00',
  '2025-01-15 14:30:00'
);
SET @inventory_id := LAST_INSERT_ID();

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
  @branch_id,
  'regular',
  NULL,
  NULL,
  NULL,
  NULL,
  0,
  17.00,
  'Cash',
  NULL,
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
SET @transaction_id := LAST_INSERT_ID();

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
  @transaction_id,
  @batch_id,
  '230145',
  '2026-12-31',
  '2024-12-01',
  2,
  8.50,
  '2025-01-15 14:30:00',
  '2025-01-15 14:30:00'
);

COMMIT;

-- Created rows:
-- company_id:      @company_id
-- branch_id:       @branch_id
-- user_id:         @user_id
-- medicine_id:     @medicine_id
-- batch_id:        @batch_id
-- inventory_id:    @inventory_id
-- transaction_id:  @transaction_id
