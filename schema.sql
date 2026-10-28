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

    -- Public registration inherits this value. Staff/admin accounts must be
    -- created by authorized administration code with an explicit role.
    role VARCHAR(30) NOT NULL DEFAULT 'resident'
        CHECK (role IN ('resident', 'staff', 'admin')),

    account_status VARCHAR(30) NOT NULL DEFAULT 'pending'
        CHECK (account_status IN
            ('pending', 'active', 'disabled', 'locked')),

    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at TIMESTAMPTZ
);

COMMENT ON COLUMN user_account.role IS
    'Defaults to resident for self-registration; staff and admin must be assigned by authorized administration code.';

-- 3A. PASSWORD RESET TOKENS
CREATE TABLE password_reset_token (
    reset_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id INT NOT NULL REFERENCES user_account(user_id)
        ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
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

    document_type VARCHAR(100) NOT NULL
        CHECK (document_type IN (
            'Barangay Clearance',
            'Certificate of Indigency',
            'Certificate of Residency'
        )),

    name VARCHAR(255) NOT NULL,
    birthday DATE NOT NULL,
    age INT NOT NULL CHECK (age >= 0 AND age <= 150),
    purok_address TEXT NOT NULL,
    civil_status VARCHAR(50) NOT NULL
        CHECK (civil_status IN (
            'Single',
            'Married',
            'Widowed',
            'Separated',
            'Divorced'
        )),

    purpose VARCHAR(100) NOT NULL
        CHECK (purpose IN (
            'Travel Abroad',
            'Legal Purpose',
            'Loan Application',
            'Financial/Medical',
            'Police/NBI Clearance',
            'Postal ID',
            'Local Employment',
            'School Requirement',
            'Water/Electrical Installation',
            'Personal Identification',
            'Other'
        )),
    purpose_other TEXT,

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
    ),

    CHECK (
        (purpose = 'Other' AND NULLIF(BTRIM(purpose_other), '') IS NOT NULL)
        OR (purpose <> 'Other' AND purpose_other IS NULL)
    )
);

-- 6. BLOTTER / INCIDENT REPORT
CREATE TABLE blotter (
    report_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    resident_id INT REFERENCES resident(resident_id)
        ON DELETE SET NULL,
    assigned_staff_id INT REFERENCES staff(staff_id)
        ON DELETE SET NULL,

    report_title VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    location TEXT,
    latitude NUMERIC(10, 7),
    longitude NUMERIC(10, 7),
    description TEXT NOT NULL,
    attachment_file_path TEXT,
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
    image_path TEXT,
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

-- 9. BARANGAY KNOWLEDGE BASE
CREATE TABLE barangay_knowledge (
    knowledge_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    category VARCHAR(100) NOT NULL,
    question VARCHAR(255) NOT NULL,
    answer TEXT NOT NULL,
    source_url TEXT,
    source_name VARCHAR(255),
    is_published BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (category, question)
);

INSERT INTO barangay_knowledge
    (category, question, answer, source_url, source_name)
VALUES
    ('Identity', 'Where is Barangay Inosloban located?',
     'Barangay Inosloban is in the City of Lipa, Province of Batangas, CALABARZON (Region IV-A), on the island of Luzon.',
     'https://www.philatlas.com/luzon/r04a/batangas/lipa/inosloban.html', 'PhilAtlas - Inosloban, Lipa, Batangas'),
    ('Identity', 'What is the barangay geographic location?',
     'Inosloban is situated at approximately 13.9888 latitude and 121.1710 longitude. Its estimated elevation is 335.2 meters (1,099.7 feet) above mean sea level.',
     'https://www.philatlas.com/luzon/r04a/batangas/lipa/inosloban.html', 'PhilAtlas - Inosloban, Lipa, Batangas'),
    ('Demographics', 'What was the population of Inosloban in the 2020 Census?',
     'The population of Inosloban was 14,904 in the 2020 Census, representing approximately 4.00% of the total population of Lipa.',
     'https://www.philatlas.com/luzon/r04a/batangas/lipa/inosloban.html', 'PhilAtlas, using Philippine Statistics Authority data'),
    ('Demographics', 'How many households were recorded in Inosloban?',
     'The 2015 Census recorded a household population of 12,688 in 3,186 households, with an average household size of 3.98 members.',
     'https://www.philatlas.com/luzon/r04a/batangas/lipa/inosloban.html', 'PhilAtlas, using Philippine Statistics Authority data'),
    ('Demographics', 'What is the largest age group in Inosloban?',
     'Based on the 2015 Census, the 20 to 24 age group had the highest population, with 1,419 individuals.',
     'https://www.philatlas.com/luzon/r04a/batangas/lipa/inosloban.html', 'PhilAtlas, using Philippine Statistics Authority data'),
    ('History', 'How has the population of Inosloban changed?',
     'The population grew from 4,463 in 1990 to 14,904 in 2020. PhilAtlas reports an annualized growth rate of 3.40% from 2015 to 2020.',
     'https://www.philatlas.com/luzon/r04a/batangas/lipa/inosloban.html', 'PhilAtlas, using Philippine Statistics Authority data'),
    ('Location', 'Which barangays are adjacent to Inosloban?',
     'Inosloban shares borders with Plaridel (Lipa), San Andres (Malvar), Bugtong na Pulo (Lipa), Santiago (Malvar), Lumbang (Lipa), Pusil (Lipa), Marauoy (Lipa), and Dagatan (Lipa).',
     'https://www.philatlas.com/luzon/r04a/batangas/lipa/inosloban.html', 'PhilAtlas - Inosloban, Lipa, Batangas'),
    ('Identity', 'What is the alternate spelling of Barangay Inosluban?',
     'Barangay Inosluban is also spelled Inosloban in some references and is a barangay of Lipa City, Batangas.',
     'https://brgy.to/batangas/lipa/inosloban', 'brgy.to - Inosloban, Lipa, Batangas'),
    ('Demographics', 'What language is commonly associated with Inosluban?',
     'brgy.to identifies Tagalog, including the Batangas dialect, as the language associated with Inosloban.',
     'https://brgy.to/batangas/lipa/inosloban', 'brgy.to - Inosloban, Lipa, Batangas'),
    ('History', 'How did Barangay Inosluban get its name?',
     'According to the history published by the City of Lipa, elders associated the name with a robbery at a woman named Ines'' home. The phrase “Si Ines, nilooban!” was later connected to the name Inosluban.',
     'https://lipa.gov.ph/barangay-profiles/', 'City of Lipa - Barangay Profiles'),
    ('Local Leadership', 'Who is listed in the Inosluban barangay directory?',
     'The City of Lipa barangay directory lists Miguel L. Olgado as Chairman, Lovely V. Dungo as Secretary, and Blesilda H. Ludovice as Treasurer for Inosluban. Directory information may change, so residents should confirm current officers with the barangay office.',
     'https://lipa.gov.ph/barangay-profiles/', 'City of Lipa - Barangay Profiles'),
    ('Economy and Landmarks', 'What industry is specifically mentioned for Inosluban?',
     'The City of Lipa barangay profile states that Inosluban caters the first semiconductor industry in Lipa, PKI (Pilipinas Kyohritsu, Inc.).',
     'https://lipa.gov.ph/barangay-profiles/', 'City of Lipa - Barangay Profiles'),
    ('Location', 'What road and coordinates are shown in the Wikimedia Inosluban photo?',
     'The Wikimedia Commons file describes a location in Inosluban along the Manila-Batangas Road, also called Jose P. Laurel Highway, and records approximate coordinates of 13.981667 latitude and 121.169444 longitude for the image.',
     'https://commons.wikimedia.org/wiki/File:728Inosluban,_Lipa_City,_Batangas_10.jpg', 'Wikimedia Commons - Inosluban, Lipa City, Batangas 10'),
    ('Office Hours', 'What are the Barangay Hall of Inosluban office hours?',
     'The supplied public office-hours information lists Monday to Friday from 8:00 AM to 5:00 PM, Saturday from 8:00 AM to 12:00 PM for basic desk services, and Sunday closed. Residents should verify schedules before visiting because hours may change.',
     'https://www.facebook.com/61553988621875/posts/barangay-office-hoursmonday-friday-800am-500pmsaturday-800am-1200-noonsunday-clo/122124795758132954/', 'Barangay Inosluban public office-hours notice'),
    ('Emergency Services', 'Are emergency responders and Barangay Tanods available outside office hours?',
     'The supplied public office-hours information states that local emergency responders and Barangay Tanods remain on duty 24/7 for urgent community matters, while desk operations follow daytime office hours.',
     'https://www.facebook.com/61553988621875/posts/barangay-office-hoursmonday-friday-800am-500pmsaturday-800am-1200-noonsunday-clo/122124795758132954/', 'Barangay Inosluban public office-hours notice'),
    ('Contact Information', 'How can residents contact Barangay Hall of Inosluban?',
     'The supplied public contact information lists Barangay Hall landline 404-3035, Barangay Outpost landline 233-2499, and Barangay Outpost mobile 0966-854-7444. Residents should verify contact details through the official channels before relying on them.',
     'https://www.facebook.com/61553651555583/posts/updated-contact-numbersbarangay-hall-404-3035barangay-outpost-landline-233-2499c/122190825068121718/', 'Brgy. Inosluban | Lipa City public contact notice'),
    ('Contact Information', 'What is the official Facebook page of Barangay Inosluban?',
     'The supplied public contact information identifies “Brgy. Inosluban | Lipa City” as the official Facebook page: https://www.facebook.com/p/Brgy-Inosluban-61553651555583/.',
     'https://www.facebook.com/p/Brgy-Inosluban-61553651555583/', 'Brgy. Inosluban | Lipa City official Facebook page')
ON CONFLICT (category, question) DO NOTHING;

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

-- 12. STAFF TIME SHEETS
CREATE TABLE staff_time_log (
    time_log_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id INT NOT NULL REFERENCES user_account(user_id) ON DELETE CASCADE,
    clock_in TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    clock_out TIMESTAMPTZ,
    total_seconds INT,
    CHECK (clock_out IS NULL OR clock_out >= clock_in)
);

CREATE TABLE weather_advisory (
    advisory_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    created_by_user_id INT REFERENCES user_account(user_id) ON DELETE SET NULL,
    location VARCHAR(255) NOT NULL,
    weather_summary TEXT NOT NULL,
    temperature NUMERIC(6,2),
    severity VARCHAR(30) NOT NULL DEFAULT 'Information',
    recommendation TEXT NOT NULL,
    source_url TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending'
        CHECK (status IN ('Pending', 'Approved', 'Dismissed'))
);

-- =========================================================
-- 13. INDEXES FOR COMMON SEARCHES AND DASHBOARDS
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