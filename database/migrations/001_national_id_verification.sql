BEGIN;

CREATE TABLE IF NOT EXISTS national_id_verification (
    verification_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    resident_id INT NOT NULL UNIQUE REFERENCES resident(resident_id)
        ON DELETE CASCADE,
    service_verification_id VARCHAR(128),
    id_format VARCHAR(20) NOT NULL CHECK (id_format IN ('PhilID', 'ePhilID')),
    status VARCHAR(30) NOT NULL CHECK (status IN (
        'PROCESSING', 'PRE_VERIFIED', 'PENDING_STAFF_REVIEW',
        'RETRY_REQUIRED', 'STAFF_APPROVED', 'STAFF_REJECTED',
        'OFFICIAL_CHECK_RECORDED'
    )),
    masked_pcn VARCHAR(32),
    qr_decoded BOOLEAN NOT NULL DEFAULT FALSE,
    front_pcn_match BOOLEAN,
    front_last_name_match BOOLEAN,
    selfie_quality_pass BOOLEAN,
    face_match_pass BOOLEAN,
    face_metric VARCHAR(40),
    face_score NUMERIC(12,8),
    review_reasons JSONB NOT NULL DEFAULT '[]'::JSONB,
    consented_at TIMESTAMPTZ NOT NULL,
    consent_version VARCHAR(40) NOT NULL,
    submitted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMPTZ,
    reviewed_by_user_id INT REFERENCES user_account(user_id) ON DELETE SET NULL,
    reviewed_at TIMESTAMPTZ,
    staff_decision VARCHAR(20) CHECK (staff_decision IN ('Approved', 'Rejected')),
    staff_reason TEXT,
    official_check_source VARCHAR(100),
    official_checked_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (face_score IS NULL OR face_score >= 0)
);

CREATE INDEX IF NOT EXISTS idx_national_id_verification_status
    ON national_id_verification (status, submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_national_id_verification_resident
    ON national_id_verification (resident_id);

COMMIT;
