<?php

function ensure_household_schema(): void
{
    db()->exec('ALTER TABLE household_record ADD COLUMN IF NOT EXISTS house_number VARCHAR(50)');
    db()->exec(
        "CREATE TABLE IF NOT EXISTS household_member (
            member_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            household_id INT NOT NULL REFERENCES household_record(household_id) ON DELETE CASCADE,
            resident_id INT UNIQUE REFERENCES resident(resident_id) ON DELETE SET NULL,
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(100),
            last_name VARCHAR(100) NOT NULL,
            birthdate DATE NOT NULL,
            gender VARCHAR(30),
            relationship_to_head VARCHAR(80) NOT NULL,
            contact_number VARCHAR(20),
            is_household_head BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    db()->exec(
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_household_record_purok_house_number
         ON household_record (LOWER(BTRIM(purok)), LOWER(BTRIM(house_number)))
         WHERE NULLIF(BTRIM(house_number), '') IS NOT NULL"
    );
    db()->exec('CREATE INDEX IF NOT EXISTS idx_household_member_household ON household_member (household_id)');
    db()->exec(
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_household_member_single_head
         ON household_member (household_id) WHERE is_household_head'
    );
    db()->exec(
        "CREATE INDEX IF NOT EXISTS idx_household_member_identity
         ON household_member (LOWER(BTRIM(first_name)), LOWER(BTRIM(last_name)), birthdate)
         WHERE resident_id IS NULL"
    );
    db()->exec(
        "INSERT INTO household_member (
            household_id, resident_id, first_name, middle_name, last_name, birthdate,
            gender, relationship_to_head, contact_number, is_household_head
         )
         SELECT r.household_id, r.resident_id, r.first_name, r.middle_name, r.last_name,
                r.birthdate, r.gender,
                CASE WHEN h.household_head_resident_id = r.resident_id THEN 'Head' ELSE 'Household member' END,
                r.mobile_number, h.household_head_resident_id = r.resident_id
         FROM resident r
         JOIN household_record h ON h.household_id = r.household_id
         WHERE NOT EXISTS (
             SELECT 1 FROM household_member hm WHERE hm.resident_id = r.resident_id
         )"
    );
}

function link_registered_resident_to_household(PDO $pdo, int $residentId): bool
{
    $residentQuery = $pdo->prepare(
        "SELECT resident_id, first_name, middle_name, last_name, birthdate
         FROM resident
         WHERE resident_id = :resident_id AND household_id IS NULL"
    );
    $residentQuery->execute(['resident_id' => $residentId]);
    $resident = $residentQuery->fetch();
    if (!$resident) {
        return false;
    }

    $memberQuery = $pdo->prepare(
        "SELECT member_id, household_id,
                CASE WHEN is_household_head THEN 1 ELSE 0 END AS is_household_head
         FROM household_member
         WHERE resident_id IS NULL
           AND LOWER(BTRIM(first_name)) = LOWER(BTRIM(:first_name))
           AND LOWER(BTRIM(COALESCE(middle_name, ''))) = LOWER(BTRIM(:middle_name))
           AND LOWER(BTRIM(last_name)) = LOWER(BTRIM(:last_name))
           AND birthdate = CAST(:birthdate AS DATE)
         ORDER BY member_id
         LIMIT 2"
    );
    $memberQuery->execute([
        'first_name' => $resident['first_name'],
        'middle_name' => (string) ($resident['middle_name'] ?? ''),
        'last_name' => $resident['last_name'],
        'birthdate' => $resident['birthdate'],
    ]);
    $members = $memberQuery->fetchAll();
    if (count($members) !== 1) {
        return false;
    }

    $member = $members[0];
    $linkMember = $pdo->prepare(
        'UPDATE household_member
         SET resident_id = :resident_id, updated_at = CURRENT_TIMESTAMP
         WHERE member_id = :member_id AND resident_id IS NULL'
    );
    $linkMember->execute(['resident_id' => $residentId, 'member_id' => $member['member_id']]);
    if ($linkMember->rowCount() !== 1) {
        return false;
    }

    $linkResident = $pdo->prepare(
        'UPDATE resident SET household_id = :household_id, updated_at = CURRENT_TIMESTAMP
         WHERE resident_id = :resident_id AND household_id IS NULL'
    );
    $linkResident->execute([
        'household_id' => $member['household_id'],
        'resident_id' => $residentId,
    ]);
    if ($linkResident->rowCount() !== 1) {
        throw new RuntimeException('The matching household member could not be linked to the resident account.');
    }

    if ($member['is_household_head']) {
        $head = $pdo->prepare(
            'UPDATE household_record
             SET household_head_resident_id = :resident_id
             WHERE household_id = :household_id'
        );
        $head->execute(['resident_id' => $residentId, 'household_id' => $member['household_id']]);
    }

    return true;
}
