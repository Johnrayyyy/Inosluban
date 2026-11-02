<?php
declare(strict_types=1);

const DOCUMENT_REQUEST_FEE_PESOS = 20.00;

function ensure_document_payment_schema(): void
{
    db()->exec(
        "ALTER TABLE document_request
         ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'Unpaid'
             CHECK (payment_status IN ('Unpaid', 'Paid'))"
    );
    db()->exec(
        'ALTER TABLE document_request
         ADD COLUMN IF NOT EXISTS payment_amount NUMERIC(8,2) NOT NULL DEFAULT 20.00
             CHECK (payment_amount = 20.00)'
    );
    db()->exec('ALTER TABLE document_request ADD COLUMN IF NOT EXISTS paid_at TIMESTAMPTZ');
    db()->exec(
        "UPDATE document_request
         SET payment_status = 'Paid',
             payment_amount = 20.00,
             paid_at = COALESCE(paid_at, date_completed, request_date)
         WHERE status IN ('Completed', 'Released')
           AND payment_status <> 'Paid'"
    );
}
