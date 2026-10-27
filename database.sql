-- =====================================================================
-- Hotel Management System - Accounts and Finance Department
-- Database : hotel_finance_db        Engine : MySQL / MariaDB (XAMPP)
-- WARNING  : this script DROPS and recreates hotel_finance_db.
-- Import   : phpMyAdmin > Import  (or: mysql -u root < database.sql)
-- =====================================================================

DROP DATABASE IF EXISTS hotel_finance_db;
CREATE DATABASE hotel_finance_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hotel_finance_db;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. ACCESS CONTROL
-- ---------------------------------------------------------------------
CREATE TABLE roles (
    role_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name    VARCHAR(50)  NOT NULL UNIQUE,
    description  VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE permissions (
    permission_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(60)  NOT NULL UNIQUE,
    description    VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id       INT UNSIGNED NOT NULL,
    full_name     VARCHAR(120) NOT NULL,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    email         VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login    DATETIME     NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(role_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. HOTEL DATA (demo data; replaceable by a future reservation/PMS module)
-- ---------------------------------------------------------------------
CREATE TABLE guests (
    guest_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    guest_code   VARCHAR(20)  NOT NULL UNIQUE,
    full_name    VARCHAR(120) NOT NULL,
    guest_type   ENUM('individual','corporate') NOT NULL DEFAULT 'individual',
    company_name VARCHAR(120) NULL,
    email        VARCHAR(120) NULL,
    phone        VARCHAR(30)  NULL,
    address      VARCHAR(255) NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_guest_name (full_name)
) ENGINE=InnoDB;

CREATE TABLE rooms (
    room_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_number    VARCHAR(10)  NOT NULL UNIQUE,
    room_type      VARCHAR(50)  NOT NULL,
    rate_per_night DECIMAL(12,2) NOT NULL CHECK (rate_per_night >= 0)
) ENGINE=InnoDB;

CREATE TABLE reservations (
    reservation_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_code VARCHAR(20) NOT NULL UNIQUE,
    guest_id         INT UNSIGNED NOT NULL,
    room_id          INT UNSIGNED NOT NULL,
    check_in         DATE NOT NULL,
    check_out        DATE NOT NULL,
    status           ENUM('reserved','checked_in','checked_out','cancelled') NOT NULL DEFAULT 'reserved',
    CONSTRAINT fk_res_guest FOREIGN KEY (guest_id) REFERENCES guests(guest_id),
    CONSTRAINT fk_res_room  FOREIGN KEY (room_id)  REFERENCES rooms(room_id),
    CONSTRAINT chk_res_dates CHECK (check_out >= check_in),
    INDEX idx_res_dates (check_in, check_out)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. FINANCIAL CONTROL TABLES
-- ---------------------------------------------------------------------
CREATE TABLE financial_periods (
    period_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_name      VARCHAR(50) NOT NULL UNIQUE,
    start_date       DATE NOT NULL,
    end_date         DATE NOT NULL,
    status           ENUM('open','closed') NOT NULL DEFAULT 'open',
    closed_by        INT UNSIGNED NULL,
    closed_at        DATETIME NULL,
    closing_notes    VARCHAR(255) NULL,
    reopened_by      INT UNSIGNED NULL,
    reopened_at      DATETIME NULL,
    reopen_authorization VARCHAR(255) NULL,
    CONSTRAINT fk_per_closed  FOREIGN KEY (closed_by)   REFERENCES users(user_id),
    CONSTRAINT fk_per_reopen  FOREIGN KEY (reopened_by) REFERENCES users(user_id),
    CONSTRAINT chk_per_dates CHECK (end_date >= start_date),
    INDEX idx_period_dates (start_date, end_date)
) ENGINE=InnoDB;

CREATE TABLE financial_settings (
    setting_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(60)  NOT NULL UNIQUE,
    setting_value VARCHAR(500) NOT NULL,
    description   VARCHAR(255) NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_set_user FOREIGN KEY (updated_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- Rate history: invoices store the rates applied, so changing a rate never alters old invoices
CREATE TABLE tax_service_rates (
    rate_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rate_type      ENUM('tax','service_charge') NOT NULL,
    rate_name      VARCHAR(60) NOT NULL,
    rate_percent   DECIMAL(5,2) NOT NULL CHECK (rate_percent >= 0 AND rate_percent <= 100),
    effective_from DATE NOT NULL,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_by     INT UNSIGNED NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rate_user FOREIGN KEY (created_by) REFERENCES users(user_id),
    INDEX idx_rate_lookup (rate_type, is_active, effective_from)
) ENGINE=InnoDB;

-- Atomic document numbering (use SELECT ... FOR UPDATE inside a transaction)
CREATE TABLE document_sequences (
    doc_type    VARCHAR(10) NOT NULL,
    seq_year    SMALLINT    NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (doc_type, seq_year)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. GUEST FOLIOS, INVOICES
-- ---------------------------------------------------------------------
CREATE TABLE guest_folios (
    folio_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folio_number   VARCHAR(20) NOT NULL UNIQUE,
    reservation_id INT UNSIGNED NULL,
    guest_id       INT UNSIGNED NOT NULL,
    status         ENUM('open','verified','invoiced','closed') NOT NULL DEFAULT 'open',
    opened_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes          VARCHAR(255) NULL,
    CONSTRAINT fk_folio_res   FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id),
    CONSTRAINT fk_folio_guest FOREIGN KEY (guest_id) REFERENCES guests(guest_id),
    INDEX idx_folio_status (status)
) ENGINE=InnoDB;

CREATE TABLE folio_charges (
    charge_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folio_id            INT UNSIGNED NOT NULL,
    charge_date         DATE NOT NULL,
    category            ENUM('room','restaurant','minibar','laundry','spa','other') NOT NULL,
    description         VARCHAR(200) NOT NULL,
    quantity            DECIMAL(10,2) NOT NULL DEFAULT 1 CHECK (quantity > 0),
    unit_price          DECIMAL(12,2) NOT NULL CHECK (unit_price >= 0),
    amount              DECIMAL(12,2) AS (ROUND(quantity * unit_price, 2)) STORED,
    is_taxable          TINYINT(1) NOT NULL DEFAULT 1,
    verification_status ENUM('pending','verified','flagged') NOT NULL DEFAULT 'pending',
    verification_notes  VARCHAR(255) NULL,
    verified_by         INT UNSIGNED NULL,
    verified_at         DATETIME NULL,
    CONSTRAINT fk_charge_folio FOREIGN KEY (folio_id) REFERENCES guest_folios(folio_id),
    CONSTRAINT fk_charge_user  FOREIGN KEY (verified_by) REFERENCES users(user_id),
    INDEX idx_charge_status (verification_status)
) ENGINE=InnoDB;

CREATE TABLE invoices (
    invoice_id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number        VARCHAR(20) NOT NULL UNIQUE,
    folio_id              INT UNSIGNED NULL,
    guest_id              INT UNSIGNED NOT NULL,
    period_id             INT UNSIGNED NOT NULL,
    customer_type         ENUM('guest','corporate','other') NOT NULL DEFAULT 'guest',
    issue_date            DATE NOT NULL,
    due_date              DATE NOT NULL,
    subtotal              DECIMAL(12,2) NOT NULL DEFAULT 0 CHECK (subtotal >= 0),
    service_rate_applied  DECIMAL(5,2)  NOT NULL DEFAULT 0,
    service_charge_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_rate_applied      DECIMAL(5,2)  NOT NULL DEFAULT 0,
    tax_amount            DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount       DECIMAL(12,2) NOT NULL DEFAULT 0 CHECK (discount_amount >= 0),
    adjustment_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount          DECIMAL(12,2) NOT NULL DEFAULT 0,
    status                ENUM('draft','issued','partially_paid','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
    cancellation_reason   VARCHAR(255) NULL,
    created_by            INT UNSIGNED NOT NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inv_folio  FOREIGN KEY (folio_id)  REFERENCES guest_folios(folio_id),
    CONSTRAINT fk_inv_guest  FOREIGN KEY (guest_id)  REFERENCES guests(guest_id),
    CONSTRAINT fk_inv_period FOREIGN KEY (period_id) REFERENCES financial_periods(period_id),
    CONSTRAINT fk_inv_user   FOREIGN KEY (created_by) REFERENCES users(user_id),
    INDEX idx_inv_status (status),
    INDEX idx_inv_dates (issue_date, due_date)
) ENGINE=InnoDB;

CREATE TABLE invoice_items (
    item_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id       INT UNSIGNED NOT NULL,
    folio_charge_id  INT UNSIGNED NULL,
    description      VARCHAR(200) NOT NULL,
    quantity         DECIMAL(10,2) NOT NULL DEFAULT 1 CHECK (quantity > 0),
    unit_price       DECIMAL(12,2) NOT NULL CHECK (unit_price >= 0),
    line_total       DECIMAL(12,2) AS (ROUND(quantity * unit_price, 2)) STORED,
    is_taxable       TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id),
    CONSTRAINT fk_item_charge  FOREIGN KEY (folio_charge_id) REFERENCES folio_charges(charge_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. PAYMENTS, DEPOSITS, REFUNDS, DISCOUNTS, ADJUSTMENTS, RECEIPTS
-- ---------------------------------------------------------------------
CREATE TABLE payments (
    payment_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_number   VARCHAR(20) NOT NULL UNIQUE,
    invoice_id       INT UNSIGNED NOT NULL,
    period_id        INT UNSIGNED NOT NULL,
    amount           DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    payment_date     DATE NOT NULL,
    payment_method   ENUM('cash','card','gcash','maya','bank_transfer') NOT NULL,
    reference_number VARCHAR(60) NULL,
    cashier_name     VARCHAR(100) NULL,           -- cash
    card_type        VARCHAR(30)  NULL,           -- card (never store full card number or CVV)
    card_last4       CHAR(4)      NULL,
    bank_name        VARCHAR(80)  NULL,           -- bank transfer
    status           ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    recorded_by      INT UNSIGNED NOT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id),
    CONSTRAINT fk_pay_period  FOREIGN KEY (period_id)  REFERENCES financial_periods(period_id),
    CONSTRAINT fk_pay_user    FOREIGN KEY (recorded_by) REFERENCES users(user_id),
    INDEX idx_pay_status (status),
    INDEX idx_pay_date (payment_date),
    INDEX idx_pay_method (payment_method)
) ENGINE=InnoDB;

CREATE TABLE payment_verifications (
    verification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id      INT UNSIGNED NOT NULL,
    verified_by     INT UNSIGNED NOT NULL,
    result          ENUM('verified','rejected') NOT NULL,
    notes           VARCHAR(255) NULL,
    verified_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pv_pay  FOREIGN KEY (payment_id) REFERENCES payments(payment_id),
    CONSTRAINT fk_pv_user FOREIGN KEY (verified_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE receipts (
    receipt_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_number  VARCHAR(20) NOT NULL UNIQUE,
    payment_id      INT UNSIGNED NOT NULL UNIQUE,
    invoice_id      INT UNSIGNED NOT NULL,
    balance_after   DECIMAL(12,2) NOT NULL,
    issued_by       INT UNSIGNED NOT NULL,
    issued_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rec_pay     FOREIGN KEY (payment_id) REFERENCES payments(payment_id),
    CONSTRAINT fk_rec_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id),
    CONSTRAINT fk_rec_user    FOREIGN KEY (issued_by)  REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE guest_deposits (
    deposit_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deposit_number   VARCHAR(20) NOT NULL UNIQUE,
    guest_id         INT UNSIGNED NOT NULL,
    reservation_id   INT UNSIGNED NULL,
    deposit_date     DATE NOT NULL,
    payment_method   ENUM('cash','card','gcash','maya','bank_transfer') NOT NULL,
    reference_number VARCHAR(60) NULL,
    amount_received  DECIMAL(12,2) NOT NULL CHECK (amount_received > 0),
    amount_applied   DECIMAL(12,2) NOT NULL DEFAULT 0 CHECK (amount_applied >= 0),
    amount_refunded  DECIMAL(12,2) NOT NULL DEFAULT 0 CHECK (amount_refunded >= 0),
    remaining_amount DECIMAL(12,2) AS (amount_received - amount_applied - amount_refunded) STORED,
    status           ENUM('held','partially_applied','fully_applied','refunded') NOT NULL DEFAULT 'held',
    recorded_by      INT UNSIGNED NOT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dep_guest FOREIGN KEY (guest_id) REFERENCES guests(guest_id),
    CONSTRAINT fk_dep_res   FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id),
    CONSTRAINT fk_dep_user  FOREIGN KEY (recorded_by) REFERENCES users(user_id),
    CONSTRAINT chk_dep_balance CHECK (amount_applied + amount_refunded <= amount_received)
) ENGINE=InnoDB;

-- A deposit applied to an invoice reduces the balance but is not a new payment (no double counting)
CREATE TABLE deposit_applications (
    application_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deposit_id     INT UNSIGNED NOT NULL,
    invoice_id     INT UNSIGNED NOT NULL,
    amount         DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    applied_by     INT UNSIGNED NOT NULL,
    applied_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_da_dep  FOREIGN KEY (deposit_id) REFERENCES guest_deposits(deposit_id),
    CONSTRAINT fk_da_inv  FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id),
    CONSTRAINT fk_da_user FOREIGN KEY (applied_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE refunds (
    refund_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    refund_number    VARCHAR(20) NOT NULL UNIQUE,
    payment_id       INT UNSIGNED NULL,      -- refund of an invoice payment, OR
    deposit_id       INT UNSIGNED NULL,      -- refund of a guest deposit
    invoice_id       INT UNSIGNED NULL,
    amount           DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    reason           VARCHAR(255) NOT NULL,
    reference_number VARCHAR(60) NULL,
    status           ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
    requested_by     INT UNSIGNED NOT NULL,
    approved_by      INT UNSIGNED NULL,
    approved_at      DATETIME NULL,
    completed_at     DATETIME NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ref_pay  FOREIGN KEY (payment_id) REFERENCES payments(payment_id),
    CONSTRAINT fk_ref_dep  FOREIGN KEY (deposit_id) REFERENCES guest_deposits(deposit_id),
    CONSTRAINT fk_ref_inv  FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id),
    CONSTRAINT fk_ref_req  FOREIGN KEY (requested_by) REFERENCES users(user_id),
    CONSTRAINT fk_ref_app  FOREIGN KEY (approved_by)  REFERENCES users(user_id),
    CONSTRAINT chk_ref_target CHECK (payment_id IS NOT NULL OR deposit_id IS NOT NULL),
    INDEX idx_ref_status (status)
) ENGINE=InnoDB;

CREATE TABLE discounts (
    discount_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id    INT UNSIGNED NOT NULL,
    discount_type ENUM('fixed','percentage') NOT NULL,
    value         DECIMAL(12,2) NOT NULL CHECK (value > 0),   -- peso amount or percent
    amount        DECIMAL(12,2) NOT NULL CHECK (amount > 0),  -- resulting peso discount
    reason        VARCHAR(255) NOT NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_by  INT UNSIGNED NOT NULL,
    approved_by   INT UNSIGNED NULL,
    approved_at   DATETIME NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_disc_inv FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id),
    CONSTRAINT fk_disc_req FOREIGN KEY (requested_by) REFERENCES users(user_id),
    CONSTRAINT fk_disc_app FOREIGN KEY (approved_by)  REFERENCES users(user_id),
    INDEX idx_disc_status (status)
) ENGINE=InnoDB;

CREATE TABLE billing_adjustments (
    adjustment_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id           INT UNSIGNED NOT NULL,
    adjustment_type      ENUM('increase','decrease') NOT NULL,
    amount               DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    reason               VARCHAR(255) NOT NULL,
    invoice_total_before DECIMAL(12,2) NOT NULL,   -- preserves the original record
    status               ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_by         INT UNSIGNED NOT NULL,
    approved_by          INT UNSIGNED NULL,
    approved_at          DATETIME NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_adj_inv FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id),
    CONSTRAINT fk_adj_req FOREIGN KEY (requested_by) REFERENCES users(user_id),
    CONSTRAINT fk_adj_app FOREIGN KEY (approved_by)  REFERENCES users(user_id),
    INDEX idx_adj_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. EXPENSES, SUPPLIERS, PAYABLES, PAYROLL, BUDGETS
-- ---------------------------------------------------------------------
CREATE TABLE expense_categories (
    category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE expenses (
    expense_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_number   VARCHAR(20) NOT NULL UNIQUE,
    category_id      INT UNSIGNED NOT NULL,
    period_id        INT UNSIGNED NOT NULL,
    expense_date     DATE NOT NULL,
    amount           DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    description      VARCHAR(255) NOT NULL,
    reference_number VARCHAR(60) NULL,
    status           ENUM('recorded','void') NOT NULL DEFAULT 'recorded',
    void_reason      VARCHAR(255) NULL,
    recorded_by      INT UNSIGNED NOT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_exp_cat    FOREIGN KEY (category_id) REFERENCES expense_categories(category_id),
    CONSTRAINT fk_exp_period FOREIGN KEY (period_id)   REFERENCES financial_periods(period_id),
    CONSTRAINT fk_exp_user   FOREIGN KEY (recorded_by) REFERENCES users(user_id),
    INDEX idx_exp_date (expense_date)
) ENGINE=InnoDB;

CREATE TABLE suppliers (
    supplier_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_name  VARCHAR(120) NOT NULL UNIQUE,
    contact_person VARCHAR(100) NULL,
    phone          VARCHAR(30)  NULL,
    email          VARCHAR(120) NULL,
    address        VARCHAR(255) NULL,
    is_active      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE supplier_invoices (
    supplier_invoice_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id         INT UNSIGNED NOT NULL,
    category_id         INT UNSIGNED NULL,
    invoice_number      VARCHAR(40) NOT NULL,
    invoice_date        DATE NOT NULL,
    due_date            DATE NOT NULL,
    amount              DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    status              ENUM('unpaid','partially_paid','paid','overdue','cancelled') NOT NULL DEFAULT 'unpaid',
    notes               VARCHAR(255) NULL,
    recorded_by         INT UNSIGNED NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_si_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
    CONSTRAINT fk_si_cat      FOREIGN KEY (category_id) REFERENCES expense_categories(category_id),
    CONSTRAINT fk_si_user     FOREIGN KEY (recorded_by) REFERENCES users(user_id),
    UNIQUE KEY uq_supplier_invoice (supplier_id, invoice_number),
    INDEX idx_si_status (status, due_date)
) ENGINE=InnoDB;

CREATE TABLE supplier_payments (
    sp_payment_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_invoice_id INT UNSIGNED NOT NULL,
    period_id           INT UNSIGNED NOT NULL,
    amount              DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    payment_date        DATE NOT NULL,
    payment_method      ENUM('cash','card','gcash','maya','bank_transfer','check') NOT NULL,
    reference_number    VARCHAR(60) NULL,
    recorded_by         INT UNSIGNED NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sp_si     FOREIGN KEY (supplier_invoice_id) REFERENCES supplier_invoices(supplier_invoice_id),
    CONSTRAINT fk_sp_period FOREIGN KEY (period_id) REFERENCES financial_periods(period_id),
    CONSTRAINT fk_sp_user   FOREIGN KEY (recorded_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE payroll_expenses (
    payroll_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_id        INT UNSIGNED NOT NULL,
    pay_period_start DATE NOT NULL,
    pay_period_end   DATE NOT NULL,
    department       VARCHAR(80) NOT NULL,
    total_amount     DECIMAL(12,2) NOT NULL CHECK (total_amount > 0),
    notes            VARCHAR(255) NULL,
    status           ENUM('recorded','void') NOT NULL DEFAULT 'recorded',
    void_reason      VARCHAR(255) NULL,
    recorded_by      INT UNSIGNED NOT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pr_period FOREIGN KEY (period_id) REFERENCES financial_periods(period_id),
    CONSTRAINT fk_pr_user   FOREIGN KEY (recorded_by) REFERENCES users(user_id),
    CONSTRAINT chk_pr_dates CHECK (pay_period_end >= pay_period_start)
) ENGINE=InnoDB;

CREATE TABLE budgets (
    budget_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id  INT UNSIGNED NOT NULL,
    period_type  ENUM('monthly','yearly') NOT NULL,
    budget_year  SMALLINT NOT NULL,
    budget_month TINYINT NOT NULL DEFAULT 0,        -- 1-12 for monthly, 0 for yearly
    amount       DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    notes        VARCHAR(255) NULL,
    created_by   INT UNSIGNED NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bud_cat  FOREIGN KEY (category_id) REFERENCES expense_categories(category_id),
    CONSTRAINT fk_bud_user FOREIGN KEY (created_by) REFERENCES users(user_id),
    UNIQUE KEY uq_budget (category_id, period_type, budget_year, budget_month)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. RECONCILIATION AND AUDIT
-- ---------------------------------------------------------------------
CREATE TABLE cash_reconciliations (
    recon_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recon_date    DATE NOT NULL,
    cashier_id    INT UNSIGNED NOT NULL,
    expected_cash DECIMAL(12,2) NOT NULL,
    actual_cash   DECIMAL(12,2) NOT NULL,
    discrepancy   DECIMAL(12,2) AS (actual_cash - expected_cash) STORED,
    status        ENUM('balanced','discrepancy','explained') NOT NULL DEFAULT 'balanced',
    explanation   VARCHAR(255) NULL,
    recorded_by   INT UNSIGNED NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cr_cashier FOREIGN KEY (cashier_id) REFERENCES users(user_id),
    CONSTRAINT fk_cr_user    FOREIGN KEY (recorded_by) REFERENCES users(user_id),
    UNIQUE KEY uq_cash_recon (recon_date, cashier_id)
) ENGINE=InnoDB;

CREATE TABLE payment_reconciliations (
    recon_id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider            ENUM('bank_transfer','card','gcash','maya') NOT NULL,
    statement_reference VARCHAR(60) NOT NULL,
    statement_date      DATE NOT NULL,
    statement_amount    DECIMAL(12,2) NOT NULL,
    payment_id          INT UNSIGNED NULL,
    recorded_amount     DECIMAL(12,2) NULL,
    variance            DECIMAL(12,2) AS (statement_amount - recorded_amount) STORED,
    status              ENUM('matched','unmatched','discrepant') NOT NULL DEFAULT 'unmatched',
    notes               VARCHAR(255) NULL,
    recorded_by         INT UNSIGNED NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_prc_pay  FOREIGN KEY (payment_id) REFERENCES payments(payment_id),
    CONSTRAINT fk_prc_user FOREIGN KEY (recorded_by) REFERENCES users(user_id),
    INDEX idx_prc_status (status)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    log_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action_type VARCHAR(40)  NOT NULL,
    table_name  VARCHAR(60)  NULL,
    record_id   VARCHAR(40)  NULL,
    old_values  TEXT NULL,
    new_values  TEXT NULL,
    reason      VARCHAR(255) NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    INDEX idx_audit_action (action_type),
    INDEX idx_audit_date (created_at)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 8. VIEWS (used by dashboard, outstanding balances, AR/AP and deposits)
-- ---------------------------------------------------------------------
-- Outstanding balance = invoice total - (verified payments - completed payment refunds) - deposits applied
-- "verified_paid" is therefore the NET amount received from payments.
CREATE VIEW v_invoice_balances AS
SELECT i.invoice_id, i.invoice_number, i.guest_id, i.customer_type, i.issue_date, i.due_date,
       i.total_amount,
       (COALESCE(p.verified_paid, 0) - COALESCE(rf.refunded, 0)) AS verified_paid,
       COALESCE(d.deposit_applied,0) AS deposit_applied,
       (i.total_amount - (COALESCE(p.verified_paid,0) - COALESCE(rf.refunded,0)) - COALESCE(d.deposit_applied,0)) AS outstanding_balance,
       i.status AS stored_status,
       CASE
         WHEN i.status = 'cancelled' THEN 'cancelled'
         WHEN i.status = 'draft' THEN 'draft'
         WHEN (i.total_amount - (COALESCE(p.verified_paid,0) - COALESCE(rf.refunded,0)) - COALESCE(d.deposit_applied,0)) <= 0 THEN 'paid'
         WHEN i.due_date < CURDATE() THEN 'overdue'
         WHEN ((COALESCE(p.verified_paid,0) - COALESCE(rf.refunded,0)) + COALESCE(d.deposit_applied,0)) > 0 THEN 'partially_paid'
         ELSE 'unpaid'
       END AS payment_status
FROM invoices i
LEFT JOIN (SELECT invoice_id, SUM(amount) AS verified_paid FROM payments
           WHERE status = 'verified' GROUP BY invoice_id) p ON p.invoice_id = i.invoice_id
LEFT JOIN (SELECT py.invoice_id, SUM(r.amount) AS refunded FROM refunds r
           JOIN payments py ON py.payment_id = r.payment_id
           WHERE r.status = 'completed' GROUP BY py.invoice_id) rf ON rf.invoice_id = i.invoice_id
LEFT JOIN (SELECT invoice_id, SUM(amount) AS deposit_applied FROM deposit_applications
           GROUP BY invoice_id) d ON d.invoice_id = i.invoice_id;

-- Accounts receivable per guest / corporate client
CREATE VIEW v_accounts_receivable AS
SELECT g.guest_id, g.guest_code, g.full_name, g.guest_type, g.company_name,
       COUNT(b.invoice_id) AS open_invoices,
       SUM(b.outstanding_balance) AS total_receivable,
       SUM(CASE WHEN b.payment_status = 'overdue' THEN b.outstanding_balance ELSE 0 END) AS overdue_amount,
       MIN(b.due_date) AS earliest_due_date
FROM v_invoice_balances b
JOIN guests g ON g.guest_id = b.guest_id
WHERE b.payment_status IN ('unpaid','partially_paid','overdue') AND b.outstanding_balance > 0
GROUP BY g.guest_id, g.guest_code, g.full_name, g.guest_type, g.company_name;

-- Accounts payable per supplier invoice
CREATE VIEW v_supplier_balances AS
SELECT si.supplier_invoice_id, si.supplier_id, s.supplier_name, si.invoice_number, si.invoice_date,
       si.due_date, si.amount,
       COALESCE(sp.paid,0) AS amount_paid,
       (si.amount - COALESCE(sp.paid,0)) AS balance,
       CASE
         WHEN si.status = 'cancelled' THEN 'cancelled'
         WHEN (si.amount - COALESCE(sp.paid,0)) <= 0 THEN 'paid'
         WHEN si.due_date < CURDATE() THEN 'overdue'
         WHEN COALESCE(sp.paid,0) > 0 THEN 'partially_paid'
         ELSE 'unpaid'
       END AS payment_status
FROM supplier_invoices si
JOIN suppliers s ON s.supplier_id = si.supplier_id
LEFT JOIN (SELECT supplier_invoice_id, SUM(amount) AS paid FROM supplier_payments
           GROUP BY supplier_invoice_id) sp ON sp.supplier_invoice_id = si.supplier_invoice_id;

-- Recognized revenue by date (cash basis): verified payments minus completed payment refunds
CREATE VIEW v_revenue_events AS
SELECT p.payment_date AS event_date, p.payment_method, 'payment' AS source, p.amount AS amount,
       p.invoice_id, p.period_id
FROM payments p WHERE p.status = 'verified'
UNION ALL
SELECT DATE(r.completed_at), p.payment_method, 'refund', -r.amount, r.invoice_id, p.period_id
FROM refunds r JOIN payments p ON p.payment_id = r.payment_id
WHERE r.status = 'completed' AND r.payment_id IS NOT NULL;

-- Recognized expenses by date: operating expenses + supplier payments + payroll
CREATE VIEW v_expense_events AS
SELECT e.expense_date AS event_date, e.category_id, 'operating' AS source, e.amount, e.period_id
FROM expenses e WHERE e.status = 'recorded'
UNION ALL
SELECT sp.payment_date, si.category_id, 'supplier_payment', sp.amount, sp.period_id
FROM supplier_payments sp JOIN supplier_invoices si ON si.supplier_invoice_id = sp.supplier_invoice_id
UNION ALL
SELECT pr.pay_period_end, NULL, 'payroll', pr.total_amount, pr.period_id
FROM payroll_expenses pr WHERE pr.status = 'recorded';

-- ---------------------------------------------------------------------
-- 9. TRIGGERS (protect audit logs and issued financial history)
-- ---------------------------------------------------------------------
DELIMITER $$
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs cannot be modified.'; END$$
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs cannot be deleted.'; END$$
CREATE TRIGGER trg_invoice_no_delete BEFORE DELETE ON invoices FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invoices cannot be deleted. Use cancellation or adjustment.'; END$$
CREATE TRIGGER trg_payment_no_delete BEFORE DELETE ON payments FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payments cannot be deleted. Use rejection or refund.'; END$$
CREATE TRIGGER trg_receipt_no_delete BEFORE DELETE ON receipts FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receipts cannot be deleted.'; END$$
CREATE TRIGGER trg_refund_no_delete BEFORE DELETE ON refunds FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refunds cannot be deleted.'; END$$
DELIMITER ;

-- =====================================================================
-- SAMPLE DATA
-- Test logins:  admin / Admin@123   staff / Staff@123   manager / Manager@123
-- =====================================================================

INSERT INTO roles (role_id, role_name, description) VALUES
(1,'Finance Administrator','Manages users, settings and all finance modules'),
(2,'Finance Staff','Handles billing, invoices, payments, expenses and suppliers'),
(3,'Finance Manager','Approves refunds, discounts, adjustments and closes periods');

INSERT INTO permissions (permission_key, description) VALUES
('dashboard.view','View the finance dashboard'),
('billing.manage','Create and manage guest bills'),
('folio.verify','Verify or flag folio charges'),
('invoices.manage','Generate and manage invoices'),
('payments.record','Record payments'),
('payments.verify','Verify or reject payments'),
('deposits.manage','Manage guest deposits'),
('refunds.request','Request refunds'),
('refunds.approve','Approve and complete refunds'),
('discounts.request','Request discounts'),
('discounts.approve','Approve discounts'),
('adjustments.request','Request billing adjustments'),
('adjustments.approve','Approve billing adjustments'),
('receipts.generate','Generate receipts'),
('expenses.manage','Record expenses'),
('suppliers.manage','Manage suppliers and supplier invoices'),
('payables.view','View accounts payable'),
('receivables.view','View accounts receivable'),
('purchase_payments.manage','Record supplier payments'),
('payroll.view','View payroll expenses'),
('payroll.manage','Record payroll expenses'),
('settings.manage','Configure tax, service charge and financial settings'),
('reports.view','View financial reports'),
('exports.generate','Export and print reports'),
('reconciliation.cash','Record and review cash reconciliation'),
('reconciliation.payment','Record and review payment-provider reconciliation'),
('audit.view','View the audit trail'),
('period.close','Close financial periods'),
('period.reopen','Reopen closed financial periods with authorization'),
('budgets.manage','Create and monitor budgets'),
('users.manage','Manage users and role permissions');

-- Administrator: every permission
INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, permission_id FROM permissions;

-- Staff
INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, permission_id FROM permissions WHERE permission_key IN
('dashboard.view','billing.manage','folio.verify','invoices.manage','payments.record','deposits.manage',
 'refunds.request','discounts.request','adjustments.request','receipts.generate','expenses.manage',
 'suppliers.manage','payables.view','receivables.view','purchase_payments.manage','reports.view',
 'exports.generate','reconciliation.cash','budgets.manage');

-- Manager
INSERT INTO role_permissions (role_id, permission_id)
SELECT 3, permission_id FROM permissions WHERE permission_key IN
('dashboard.view','folio.verify','payments.verify','refunds.approve','discounts.approve','adjustments.approve',
 'payables.view','receivables.view','payroll.view','payroll.manage','reports.view','exports.generate','reconciliation.cash',
 'reconciliation.payment','audit.view','period.close','period.reopen','budgets.manage','receipts.generate');

INSERT INTO users (user_id, role_id, full_name, username, email, password_hash) VALUES
(1,1,'Angela Villanueva','admin','admin@hotelfinance.local','$2y$10$LU2nDXVK.aXZELxnsDVmRuYXJoFvue90EtfozCBsEA7mdgA06GwJS'),
(2,2,'Rosa Lim','staff','staff@hotelfinance.local','$2y$10$1vBj2tPZ/GW4CjYXXV9rJ./9uHLqQ34fZQSK/Qiwebct6Qfo2hn6a'),
(3,3,'Carlos Mendoza','manager','manager@hotelfinance.local','$2y$10$Sv0N8qh0UxvokDIj6aSSteWMOqyA0fGqD/HPjOAEGQKjJr0Kplu0q');

INSERT INTO guests (guest_id, guest_code, full_name, guest_type, company_name, email, phone, address) VALUES
(1,'GST-0001','Maria Santos','individual',NULL,'maria.santos@example.com','0917-100-2001','Olongapo City, Zambales'),
(2,'GST-0002','Juan Dela Cruz','individual',NULL,'juan.dc@example.com','0918-200-3002','Angeles City, Pampanga'),
(3,'GST-0003','Pacific Trading Corp. (Rafael Ocampo)','corporate','Pacific Trading Corp.','accounts@pacifictrading.example','0919-300-4003','Subic Bay Freeport Zone'),
(4,'GST-0004','Ana Reyes','individual',NULL,'ana.reyes@example.com','0920-400-5004','Quezon City, Metro Manila'),
(5,'GST-0005','Daniel Bautista','individual',NULL,'d.bautista@example.com','0921-500-6005','Baguio City, Benguet'),
(6,'GST-0006','Liza Fernandez','individual',NULL,'liza.f@example.com','0922-600-7006','Tarlac City, Tarlac');

INSERT INTO rooms (room_id, room_number, room_type, rate_per_night) VALUES
(1,'101','Standard',3800.00),(2,'102','Standard',3800.00),
(3,'201','Deluxe',4500.00),(4,'202','Deluxe',4500.00),
(5,'301','Suite',5200.00),(6,'302','Executive Suite',7500.00);

INSERT INTO reservations (reservation_id, reservation_code, guest_id, room_id, check_in, check_out, status) VALUES
(1,'RSV-2026-0001',1,3,'2026-10-02','2026-10-05','checked_out'),
(2,'RSV-2026-0002',2,1,'2026-10-08','2026-10-10','checked_out'),
(3,'RSV-2026-0003',3,5,'2026-09-10','2026-09-14','checked_out'),
(4,'RSV-2026-0004',4,2,'2026-10-15','2026-10-16','checked_out'),
(5,'RSV-2026-0005',5,3,'2026-10-18','2026-10-20','checked_in'),
(6,'RSV-2026-0006',6,6,'2026-10-25','2026-10-27','cancelled'),
(7,'RSV-2026-0007',2,4,'2026-10-19','2026-10-21','reserved');

INSERT INTO financial_periods (period_id, period_name, start_date, end_date, status, closed_by, closed_at, closing_notes) VALUES
(1,'August 2026','2026-08-01','2026-08-31','closed',3,'2026-09-03 10:00:00','All items settled.'),
(2,'September 2026','2026-09-01','2026-09-30','closed',3,'2026-10-02 09:30:00','Closed with one unpaid corporate invoice (INV-2026-0003) carried forward.'),
(3,'October 2026','2026-10-01','2026-10-31','open',NULL,NULL,NULL);

INSERT INTO financial_settings (setting_key, setting_value, description, updated_by) VALUES
('hotel_name','Dusty Rose Boutique Hotel','Hotel name printed on invoices and receipts',1),
('hotel_address','12 Rizal Avenue, Olongapo City, Zambales, Philippines','Hotel address',1),
('hotel_tin','000-123-456-000','Hotel TIN printed on invoices',1),
('currency_symbol','₱','Default currency symbol (Philippine peso)',1),
('invoice_due_days','7','Default number of days until an invoice is due',1),
('discount_manager_approval_threshold','300.00','Discounts above this peso amount require manager approval',1),
('tax_computation_rule','Tax = (taxable subtotal + service charge) x tax rate','How tax is computed on invoices',1),
('revenue_recognition_rule','Cash basis: verified payments are revenue on the payment date; completed refunds of payments reduce revenue; deposits are not revenue until applied to an invoice.','Revenue recognition rule used by reports',1),
('expense_recognition_rule','Operating expenses on expense date; supplier invoices only when paid (supplier payment date); payroll on pay-period end date.','Expense recognition rule used by reports',1);

INSERT INTO tax_service_rates (rate_type, rate_name, rate_percent, effective_from, is_active, created_by) VALUES
('tax','VAT',12.00,'2026-01-01',1,1),
('service_charge','Service Charge',10.00,'2026-01-01',1,1);

INSERT INTO document_sequences (doc_type, seq_year, last_number) VALUES
('INV',2026,4),('PAY',2026,5),('OR',2026,4),('REF',2026,2),('DEP',2026,2),('FOL',2026,5),('EXP',2026,6);

-- Folios and charges ---------------------------------------------------
INSERT INTO guest_folios (folio_id, folio_number, reservation_id, guest_id, status, opened_at) VALUES
(1,'FOL-2026-0001',1,1,'invoiced','2026-10-02 14:00:00'),
(2,'FOL-2026-0002',2,2,'invoiced','2026-10-08 14:30:00'),
(3,'FOL-2026-0003',3,3,'invoiced','2026-09-10 13:00:00'),
(4,'FOL-2026-0004',4,4,'invoiced','2026-10-15 15:00:00'),
(5,'FOL-2026-0005',5,5,'open','2026-10-18 13:30:00');

INSERT INTO folio_charges (charge_id, folio_id, charge_date, category, description, quantity, unit_price, verification_status, verification_notes, verified_by, verified_at) VALUES
(1,1,'2026-10-02','room','Room 201 - Deluxe (3 nights)',3,4500.00,'verified','Matches reservation',2,'2026-10-05 09:00:00'),
(2,1,'2026-10-03','restaurant','Restaurant - dinner and breakfast',1,2350.00,'verified',NULL,2,'2026-10-05 09:00:00'),
(3,1,'2026-10-04','minibar','Minibar consumption',1,680.00,'verified',NULL,2,'2026-10-05 09:00:00'),
(4,1,'2026-10-04','laundry','Laundry service',1,450.00,'verified',NULL,2,'2026-10-05 09:00:00'),
(5,2,'2026-10-08','room','Room 101 - Standard (2 nights)',2,3800.00,'verified','Matches reservation',2,'2026-10-10 09:15:00'),
(6,2,'2026-10-09','restaurant','Restaurant - set dinner',1,1420.00,'verified',NULL,2,'2026-10-10 09:15:00'),
(7,2,'2026-10-09','spa','Spa - massage package',1,2500.00,'verified',NULL,2,'2026-10-10 09:15:00'),
(8,3,'2026-09-10','room','Room 301 - Suite (4 nights)',4,5200.00,'verified','Corporate rate per contract',2,'2026-09-15 10:00:00'),
(9,3,'2026-09-11','other','Function room rental',1,6000.00,'verified',NULL,2,'2026-09-15 10:00:00'),
(10,3,'2026-09-12','restaurant','Meeting catering',1,3200.00,'verified',NULL,2,'2026-09-15 10:00:00'),
(11,4,'2026-10-15','room','Room 102 - Standard (1 night)',1,3800.00,'verified',NULL,2,'2026-10-16 08:45:00'),
(12,4,'2026-10-15','restaurant','Restaurant - dinner',1,900.00,'verified',NULL,2,'2026-10-16 08:45:00'),
(13,5,'2026-10-18','room','Room 201 - Deluxe (1 night, first night posted)',1,4500.00,'pending',NULL,NULL,NULL),
(14,5,'2026-10-18','restaurant','Restaurant - lunch',1,1250.00,'pending',NULL,NULL,NULL),
(15,5,'2026-10-18','minibar','Minibar consumption',1,980.00,'flagged','Guest states items were not consumed; check housekeeping log.',2,'2026-10-18 10:20:00');

-- Invoices (tax = (subtotal + service) x 12%; service = subtotal x 10%) ---
INSERT INTO invoices (invoice_id, invoice_number, folio_id, guest_id, period_id, customer_type, issue_date, due_date,
  subtotal, service_rate_applied, service_charge_amount, tax_rate_applied, tax_amount, discount_amount, adjustment_amount,
  total_amount, status, created_by) VALUES
(1,'INV-2026-0001',1,1,3,'guest','2026-10-05','2026-10-12',16980.00,10.00,1698.00,12.00,2241.36,0.00,0.00,20919.36,'paid',2),
(2,'INV-2026-0002',2,2,3,'guest','2026-10-10','2026-10-24',11520.00,10.00,1152.00,12.00,1520.64,500.00,0.00,13692.64,'partially_paid',2),
(3,'INV-2026-0003',3,3,2,'corporate','2026-09-15','2026-09-30',30000.00,10.00,3000.00,12.00,3960.00,0.00,0.00,36960.00,'overdue',2),
(4,'INV-2026-0004',4,4,3,'guest','2026-10-16','2026-10-23',4700.00,10.00,470.00,12.00,620.40,0.00,0.00,5790.40,'paid',2);

INSERT INTO invoice_items (invoice_id, folio_charge_id, description, quantity, unit_price) VALUES
(1,1,'Room 201 - Deluxe (3 nights)',3,4500.00),(1,2,'Restaurant - dinner and breakfast',1,2350.00),
(1,3,'Minibar consumption',1,680.00),(1,4,'Laundry service',1,450.00),
(2,5,'Room 101 - Standard (2 nights)',2,3800.00),(2,6,'Restaurant - set dinner',1,1420.00),(2,7,'Spa - massage package',1,2500.00),
(3,8,'Room 301 - Suite (4 nights)',4,5200.00),(3,9,'Function room rental',1,6000.00),(3,10,'Meeting catering',1,3200.00),
(4,11,'Room 102 - Standard (1 night)',1,3800.00),(4,12,'Restaurant - dinner',1,900.00);

-- Payments -----------------------------------------------------------------
INSERT INTO payments (payment_id, payment_number, invoice_id, period_id, amount, payment_date, payment_method,
  reference_number, cashier_name, card_type, card_last4, bank_name, status, recorded_by) VALUES
(1,'PAY-2026-0001',1,3,10000.00,'2026-10-05','cash','CASH-1005-01','Rosa Lim',NULL,NULL,NULL,'verified',2),
(2,'PAY-2026-0002',1,3,10919.36,'2026-10-05','gcash','GC-883412',NULL,NULL,NULL,NULL,'verified',2),
(3,'PAY-2026-0003',2,3,8000.00,'2026-10-10','card','CARD-55120',NULL,'Visa','4242',NULL,'verified',2),
(4,'PAY-2026-0004',2,3,5692.64,'2026-10-14','bank_transfer','BT-20261014-77',NULL,NULL,NULL,'BDO','pending',2),
(5,'PAY-2026-0005',4,3,5790.40,'2026-10-16','card','CARD-55987',NULL,'Mastercard','5100',NULL,'verified',2);

INSERT INTO payment_verifications (payment_id, verified_by, result, notes, verified_at) VALUES
(1,3,'verified','Cash count confirmed','2026-10-05 17:30:00'),
(2,3,'verified','GCash reference checked','2026-10-05 17:35:00'),
(3,3,'verified','Card slip matched','2026-10-10 16:00:00'),
(5,3,'verified','Card slip matched','2026-10-16 15:30:00');

INSERT INTO receipts (receipt_id, receipt_number, payment_id, invoice_id, balance_after, issued_by, issued_at) VALUES
(1,'OR-2026-0001',1,1,10919.36,2,'2026-10-05 17:40:00'),
(2,'OR-2026-0002',2,1,0.00,2,'2026-10-05 17:41:00'),
(3,'OR-2026-0003',3,2,5692.64,2,'2026-10-10 16:05:00'),
(4,'OR-2026-0004',5,4,0.00,2,'2026-10-16 15:35:00');

-- Deposits, refunds, discounts, adjustments --------------------------------
INSERT INTO guest_deposits (deposit_id, deposit_number, guest_id, reservation_id, deposit_date, payment_method,
  reference_number, amount_received, amount_applied, amount_refunded, status, recorded_by) VALUES
(1,'DEP-2026-0001',5,5,'2026-10-10','gcash','GC-901233',5000.00,0.00,0.00,'held',2),
(2,'DEP-2026-0002',6,6,'2026-10-01','bank_transfer','BT-20261001-12',3000.00,0.00,3000.00,'refunded',2);

INSERT INTO refunds (refund_id, refund_number, payment_id, deposit_id, invoice_id, amount, reason, reference_number,
  status, requested_by, approved_by, approved_at, completed_at) VALUES
(1,'REF-2026-0001',5,NULL,4,500.00,'Guest complaint - air-conditioning unit was faulty',NULL,'pending',2,NULL,NULL,NULL),
(2,'REF-2026-0002',NULL,2,NULL,3000.00,'Reservation cancelled - deposit returned','BT-20261012-45','completed',2,3,'2026-10-12 11:00:00','2026-10-12 14:00:00');

INSERT INTO discounts (discount_id, invoice_id, discount_type, value, amount, reason, status, requested_by, approved_by, approved_at) VALUES
(1,2,'fixed',500.00,500.00,'Loyalty guest - frequent stays','approved',2,3,'2026-10-10 10:30:00'),
(2,2,'percentage',5.00,576.00,'Service recovery for delayed room service','pending',2,NULL,NULL);

INSERT INTO billing_adjustments (adjustment_id, invoice_id, adjustment_type, amount, reason, invoice_total_before, status, requested_by) VALUES
(1,2,'increase',250.00,'Late check-out fee was not posted to the folio',13692.64,'pending',2);

-- Expenses -------------------------------------------------------------------
INSERT INTO expense_categories (category_id, name, description) VALUES
(1,'Utilities','Electricity, water and internet'),
(2,'Maintenance','Repairs and building maintenance'),
(3,'Supplies','Guest amenities and office supplies'),
(4,'Marketing','Advertising and promotions'),
(5,'Housekeeping','Cleaning and laundry consumables'),
(6,'Food and Beverage Purchases','Kitchen and restaurant inventory');

INSERT INTO expenses (expense_id, expense_number, category_id, period_id, expense_date, amount, description, reference_number, recorded_by) VALUES
(1,'EXP-2026-0001',1,3,'2026-10-03',48200.00,'Electricity bill - September consumption','ELEC-0917',2),
(2,'EXP-2026-0002',1,3,'2026-10-03',9350.00,'Water bill - September consumption','WATER-0917',2),
(3,'EXP-2026-0003',3,3,'2026-10-07',5600.00,'Guest amenities restock','OR-33415',2),
(4,'EXP-2026-0004',2,3,'2026-10-09',7800.00,'Air-conditioning repair, Room 102','OR-33502',2),
(5,'EXP-2026-0005',4,3,'2026-10-11',4000.00,'Social media advertising','ADS-1011',2),
(6,'EXP-2026-0006',5,3,'2026-10-13',3250.00,'Housekeeping consumables','OR-33671',2);

INSERT INTO suppliers (supplier_id, supplier_name, contact_person, phone, email, address) VALUES
(1,'Olongapo Linen Supply','Ben Aquino','047-222-1001','sales@olongapolinen.example','Olongapo City'),
(2,'Central Luzon Foods Trading','Marites Cruz','045-333-2002','orders@clfoods.example','San Fernando, Pampanga'),
(3,'CleanPro Janitorial Supplies','Gil Ramos','047-444-3003','info@cleanpro.example','Subic, Zambales'),
(4,'Luzon Maintenance Services','Tony Salonga','045-555-4004','service@luzonmaint.example','Angeles City');

INSERT INTO supplier_invoices (supplier_invoice_id, supplier_id, category_id, invoice_number, invoice_date, due_date, amount, status, recorded_by) VALUES
(1,1,5,'LINEN-1042','2026-09-20','2026-10-05',12500.00,'overdue',2),
(2,2,6,'CLFT-7781','2026-10-01','2026-10-31',28400.00,'paid',2),
(3,3,5,'CP-2290','2026-10-10','2026-11-10',6750.00,'unpaid',2),
(4,4,2,'LMS-0315','2026-10-12','2026-10-27',9800.00,'unpaid',2);

INSERT INTO supplier_payments (sp_payment_id, supplier_invoice_id, period_id, amount, payment_date, payment_method, reference_number, recorded_by) VALUES
(1,1,3,5000.00,'2026-10-01','bank_transfer','BT-OUT-1001',2),
(2,2,3,28400.00,'2026-10-12','bank_transfer','BT-OUT-1012',2);

INSERT INTO payroll_expenses (period_id, pay_period_start, pay_period_end, department, total_amount, notes, recorded_by) VALUES
(3,'2026-10-01','2026-10-15','Front Office',38500.00,'First half of October',3),
(3,'2026-10-01','2026-10-15','Housekeeping',42000.00,'First half of October',3),
(3,'2026-10-01','2026-10-15','Finance',36000.00,'First half of October',3);

INSERT INTO budgets (category_id, period_type, budget_year, budget_month, amount, notes, created_by) VALUES
(1,'monthly',2026,10,65000.00,'October utilities budget',3),
(2,'monthly',2026,10,15000.00,NULL,3),
(3,'monthly',2026,10,12000.00,NULL,3),
(4,'monthly',2026,10,8000.00,NULL,3),
(5,'monthly',2026,10,3000.00,'Intentionally low to demonstrate an overspending warning',3),
(1,'yearly',2026,0,720000.00,'Annual utilities budget',3);

-- Reconciliations and audit ---------------------------------------------------
INSERT INTO cash_reconciliations (recon_date, cashier_id, expected_cash, actual_cash, status, explanation, recorded_by) VALUES
('2026-10-05',2,10000.00,9950.00,'explained','Change shortage of PHP 50 reported by cashier.',2),
('2026-10-16',2,0.00,0.00,'balanced',NULL,2);

INSERT INTO payment_reconciliations (provider, statement_reference, statement_date, statement_amount, payment_id, recorded_amount, status, notes, recorded_by) VALUES
('gcash','GC-883412','2026-10-06',10919.36,2,10919.36,'matched',NULL,3),
('card','CARD-55120','2026-10-11',8000.00,3,8000.00,'matched',NULL,3),
('card','CARD-55987','2026-10-17',5700.00,5,5790.40,'discrepant','Settlement is lower than the recorded payment; possible merchant fee. Review with the acquiring bank.',3),
('bank_transfer','BT-20261014-77','2026-10-15',5692.64,NULL,NULL,'unmatched','Funds appear on the statement; the related payment is still pending verification.',3);

INSERT INTO audit_logs (user_id, action_type, table_name, record_id, old_values, new_values, reason, ip_address, created_at) VALUES
(2,'invoice_issued','invoices','1',NULL,'{"invoice_number":"INV-2026-0001","total_amount":20919.36}','Guest checkout','127.0.0.1','2026-10-05 09:10:00'),
(3,'payment_verified','payments','1','{"status":"pending"}','{"status":"verified"}','Cash count confirmed','127.0.0.1','2026-10-05 17:30:00'),
(3,'discount_approved','discounts','1','{"status":"pending"}','{"status":"approved"}','Loyalty guest','127.0.0.1','2026-10-10 10:30:00'),
(3,'refund_completed','refunds','2','{"status":"approved"}','{"status":"completed"}','Deposit returned after cancellation','127.0.0.1','2026-10-12 14:00:00'),
(3,'period_closed','financial_periods','2','{"status":"open"}','{"status":"closed"}','Month-end close','127.0.0.1','2026-10-02 09:30:00');
