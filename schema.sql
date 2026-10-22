-- Barangay Inosluban database schema
--
-- This file creates the application schema inside the PostgreSQL database
-- selected by the restore command. It intentionally does not create a
-- database with a machine-specific name, host, user, or filesystem path.
--
-- Restore to an existing database with:
--   psql -U <user> -d <database> -v ON_ERROR_STOP=1 -f schema.sql
--
-- Create the target database first when needed:
--   createdb -U <user> <database>
--
-- This is a schema backup, not a data backup. Use pg_dump without
-- --schema-only when the backup must include resident/account data.

BEGIN;

SET TIME ZONE 'UTC';

-- 1. HOUSEHOLD
CREATE TABLE household_record (
    household_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    address TEXT NOT NULL,
    purok VARCHAR(100) NOT NULL,
    household_head_resident_id INT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. RESIDENT
CREATE TABLE resident (
    resident_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    household_id INT REFERENCES household_record(household_id)
        ON DELETE SET NULL,

    username VARCHAR(100) NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    last_name VARCHAR(100) NOT NULL,
    birthdate DATE NOT NULL,
    place_of_birth VARCHAR(255),
    gender VARCHAR(30),
    civil_status VARCHAR(50),
    nationality VARCHAR(100) NOT NULL DEFAULT 'Filipino',
    religion VARCHAR(100),

    complete_address TEXT NOT NULL,
    purok VARCHAR(100),
    residency_start_date DATE,

    mobile_number VARCHAR(20),
    email VARCHAR(255),

    voters_status VARCHAR(50),
    employment_status VARCHAR(50),
    occupation VARCHAR(150),
    educational_attainment VARCHAR(100),
    blood_type VARCHAR(10),
    disability_special_needs TEXT,

    emergency_contact_person VARCHAR(255),
    emergency_contact_number VARCHAR(20),

    valid_id_file_path TEXT,
    profile_image_path TEXT,

    residency_status VARCHAR(30) NOT NULL DEFAULT 'Active'
        CHECK (residency_status IN
            ('Active', 'Inactive', 'Moved', 'Deceased')),

    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Connect the household head to a resident.
-- This is added after creating RESIDENT to handle the circular reference.
ALTER TABLE household_record
ADD CONSTRAINT fk_household_head
FOREIGN KEY (household_head_resident_id)
REFERENCES resident(resident_id)
ON DELETE SET NULL;

-- 3. USER ACCOUNT
CREATE TABLE user_account (
    user_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    resident_id INT UNIQUE REFERENCES resident(resident_id)
        ON DELETE SET NULL,

    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,

    role VARCHAR(30) NOT NULL DEFAULT 'resident'
        CHECK (role IN ('resident', 'staff', 'admin')),

    account_status VARCHAR(30) NOT NULL DEFAULT 'pending'
        CHECK (account_status IN
            ('pending', 'active', 'disabled', 'locked')),

    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at TIMESTAMPTZ
);

-- 4. STAFF
CREATE TABLE staff (
    staff_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id INT UNIQUE REFERENCES user_account(user_id)
        ON DELETE SET NULL,

    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    position VARCHAR(100),
    employment_status VARCHAR(30) NOT NULL DEFAULT 'Active'
        CHECK (employment_status IN
            ('Active', 'Inactive', 'Resigned')),

    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 5. DOCUMENT REQUEST
CREATE TABLE document_request (
    request_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    resident_id INT NOT NULL REFERENCES resident(resident_id),
    assigned_staff_id INT REFERENCES staff(staff_id)
        ON DELETE SET NULL,

    document_type VARCHAR(100) NOT NULL,
    purpose TEXT,
    request_date TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_completed TIMESTAMPTZ,

    status VARCHAR(30) NOT NULL DEFAULT 'Pending'
        CHECK (status IN (
            'Pending', 'Processing', 'Approved',
            'Rejected', 'Completed', 'Cancelled'
        )),

    remarks TEXT,

    CHECK (
        date_completed IS NULL
        OR date_completed >= request_date
    )
);

-- 6. BLOTTER / INCIDENT REPORT
CREATE TABLE blotter (
    report_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    resident_id INT REFERENCES resident(resident_id)
        ON DELETE SET NULL,
    assigned_staff_id INT REFERENCES staff(staff_id)
        ON DELETE SET NULL,

    category VARCHAR(100) NOT NULL,
    location TEXT,
    description TEXT NOT NULL,
    report_date TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_resolved TIMESTAMPTZ,

    status VARCHAR(30) NOT NULL DEFAULT 'Pending'
        CHECK (status IN (
            'Pending', 'Under Review', 'In Progress',
            'Resolved', 'Closed', 'Rejected'
        )),

    resolution_notes TEXT,

    CHECK (
        date_resolved IS NULL
        OR date_resolved >= report_date
    )
);

-- 7. APPOINTMENT
CREATE TABLE appointment (
    appointment_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    resident_id INT NOT NULL REFERENCES resident(resident_id),
    assigned_staff_id INT REFERENCES staff(staff_id)
        ON DELETE SET NULL,

    schedule TIMESTAMPTZ NOT NULL,
    service_type VARCHAR(100) NOT NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'Pending'
        CHECK (status IN (
            'Pending', 'Approved', 'Completed',
            'Cancelled', 'Missed'
        )),

    notes TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 8. ANNOUNCEMENT
CREATE TABLE announcement (
    announcement_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    created_by_user_id INT REFERENCES user_account(user_id)
        ON DELETE SET NULL,

    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    publication_date TIMESTAMPTZ,
    expires_at TIMESTAMPTZ,

    status VARCHAR(30) NOT NULL DEFAULT 'Draft'
        CHECK (status IN ('Draft', 'Published', 'Archived')),

    CHECK (
        expires_at IS NULL
        OR publication_date IS NULL
        OR expires_at >= publication_date
    )
);

-- 9. DISASTER ALERT
CREATE TABLE disaster_preparedness (
    disaster_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    source VARCHAR(100),
    external_reference VARCHAR(255),
    alert_type VARCHAR(100) NOT NULL,
    severity VARCHAR(30),
    affected_area VARCHAR(255),
    alert_message TEXT NOT NULL,

    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ,

    CHECK (
        expires_at IS NULL
        OR expires_at >= created_at
    )
);

-- 10. NOTIFICATION / SMS DELIVERY LOG
CREATE TABLE notification_log (
    notification_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    resident_id INT REFERENCES resident(resident_id)
        ON DELETE SET NULL,
    disaster_id INT REFERENCES disaster_preparedness(disaster_id)
        ON DELETE SET NULL,
    announcement_id INT REFERENCES announcement(announcement_id)
        ON DELETE SET NULL,

    recipient VARCHAR(255) NOT NULL,
    channel VARCHAR(20) NOT NULL
        CHECK (channel IN ('SMS', 'Email', 'Portal')),

    message TEXT NOT NULL,

    delivery_status VARCHAR(30) NOT NULL DEFAULT 'Queued'
        CHECK (delivery_status IN (
            'Queued', 'Sent', 'Delivered', 'Failed'
        )),

    provider_reference VARCHAR(255),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMPTZ,
    delivered_at TIMESTAMPTZ
);

-- 11. AUDIT LOG
CREATE TABLE audit_log (
    audit_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    user_id INT REFERENCES user_account(user_id)
        ON DELETE SET NULL,

    action VARCHAR(100) NOT NULL,
    affected_table VARCHAR(100),
    affected_record_id VARCHAR(100),
    details JSONB,

    action_timestamp TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================
-- 12. INDEXES FOR COMMON SEARCHES AND DASHBOARDS
-- =========================================================

CREATE INDEX idx_resident_name
    ON resident(last_name, first_name);

CREATE INDEX idx_resident_household
    ON resident(household_id);

CREATE INDEX idx_resident_purok
    ON resident(purok);

CREATE INDEX idx_resident_status
    ON resident(residency_status);

CREATE INDEX idx_document_request_resident
    ON document_request(resident_id);

CREATE INDEX idx_document_request_status_date
    ON document_request(status, request_date);

CREATE INDEX idx_blotter_status_date
    ON blotter(status, report_date);

CREATE INDEX idx_blotter_category
    ON blotter(category);

CREATE INDEX idx_appointment_schedule_status
    ON appointment(schedule, status);

CREATE INDEX idx_announcement_status
    ON announcement(status, publication_date);

CREATE INDEX idx_disaster_alert_created
    ON disaster_preparedness(created_at);

CREATE INDEX idx_notification_status_date
    ON notification_log(delivery_status, created_at);

CREATE INDEX idx_audit_timestamp
    ON audit_log(action_timestamp);

COMMIT;