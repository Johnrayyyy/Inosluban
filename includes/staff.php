<?php

function ensure_staff_tables(): void
{
    db()->exec(
        "ALTER TABLE staff
         ADD COLUMN IF NOT EXISTS staff_role VARCHAR(30) NOT NULL DEFAULT 'General Staff'
         CHECK (staff_role IN ('General Staff', 'BHW'))"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS staff_time_log (
            time_log_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id INT NOT NULL REFERENCES user_account(user_id) ON DELETE CASCADE,
            clock_in TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            clock_out TIMESTAMPTZ,
            last_activity_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            total_seconds INT,
            CHECK (clock_out IS NULL OR clock_out >= clock_in)
        )"
    );
    db()->exec('ALTER TABLE staff_time_log ADD COLUMN IF NOT EXISTS last_activity_at TIMESTAMPTZ');
    db()->exec(
        'UPDATE staff_time_log
         SET last_activity_at = COALESCE(clock_out, clock_in)
         WHERE last_activity_at IS NULL'
    );
    db()->exec('ALTER TABLE staff_time_log ALTER COLUMN last_activity_at SET DEFAULT CURRENT_TIMESTAMP');
    db()->exec('ALTER TABLE staff_time_log ALTER COLUMN last_activity_at SET NOT NULL');
    db()->exec(
        "UPDATE staff_time_log
         SET clock_out = last_activity_at + INTERVAL '10 minutes',
             total_seconds = GREATEST(
                 0,
                 FLOOR(EXTRACT(EPOCH FROM (last_activity_at + INTERVAL '10 minutes' - clock_in)))::INT
             )
         WHERE clock_out IS NULL
           AND last_activity_at < CURRENT_TIMESTAMP - INTERVAL '10 minutes'"
    );
}

function current_staff_role(): ?string
{
    if (($_SESSION['user']['role'] ?? '') !== 'staff') {
        return null;
    }

    ensure_staff_tables();
    $statement = db()->prepare(
        'SELECT staff_role FROM staff WHERE user_id = :user_id'
    );
    $statement->execute(['user_id' => (int) $_SESSION['user']['id']]);
    $staffRole = $statement->fetchColumn();

    return is_string($staffRole) ? $staffRole : null;
}

function require_bhw_or_admin(): void
{
    require_staff();
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return;
    }
    if (current_staff_role() !== 'BHW') {
        http_response_code(403);
        exit('This module is available to BHW staff and administrators only.');
    }
}

function require_general_staff_or_admin(): void
{
    require_staff();
    if (($_SESSION['user']['role'] ?? '') === 'staff' && current_staff_role() === 'BHW') {
        http_response_code(403);
        exit('This module is available to General Staff and administrators only.');
    }
}

function staff_start_time_log(int $userId): array
{
    ensure_staff_tables();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $closePrevious = $pdo->prepare(
            'UPDATE staff_time_log
             SET clock_out = LEAST(CURRENT_TIMESTAMP, last_activity_at + INTERVAL \'10 minutes\'),
                 total_seconds = GREATEST(
                     0,
                     FLOOR(EXTRACT(EPOCH FROM (
                         LEAST(CURRENT_TIMESTAMP, last_activity_at + INTERVAL \'10 minutes\') - clock_in
                     )))::INT
                 )
             WHERE user_id = :user_id AND clock_out IS NULL'
        );
        $closePrevious->execute(['user_id' => $userId]);

        $insert = $pdo->prepare(
            'INSERT INTO staff_time_log (user_id)
             VALUES (:user_id)
             RETURNING time_log_id, clock_in'
        );
        $insert->execute(['user_id' => $userId]);
        $timeLog = $insert->fetch();
        $pdo->commit();

        return $timeLog;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function staff_get_time_log(int $timeLogId, int $userId): ?array
{
    ensure_staff_tables();
    $statement = db()->prepare(
        'SELECT time_log_id, clock_in
         FROM staff_time_log
         WHERE time_log_id = :time_log_id AND user_id = :user_id AND clock_out IS NULL'
    );
    $statement->execute(['time_log_id' => $timeLogId, 'user_id' => $userId]);
    $timeLog = $statement->fetch();

    return $timeLog ?: null;
}

function staff_time_log_elapsed_seconds(int $timeLogId, int $userId): ?int
{
    ensure_staff_tables();
    $statement = db()->prepare(
        'SELECT FLOOR(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - clock_in)))::INT
         FROM staff_time_log
         WHERE time_log_id = :time_log_id
           AND user_id = :user_id
           AND clock_out IS NULL'
    );
    $statement->execute(['time_log_id' => $timeLogId, 'user_id' => $userId]);
    $elapsedSeconds = $statement->fetchColumn();

    return $elapsedSeconds === false ? null : max(0, (int) $elapsedSeconds);
}

function staff_touch_time_log(int $timeLogId, int $userId): bool
{
    ensure_staff_tables();
    $statement = db()->prepare(
        'UPDATE staff_time_log
         SET last_activity_at = CURRENT_TIMESTAMP
         WHERE time_log_id = :time_log_id
           AND user_id = :user_id
           AND clock_out IS NULL
           AND last_activity_at >= CURRENT_TIMESTAMP - INTERVAL \'10 minutes\''
    );
    $statement->execute(['time_log_id' => $timeLogId, 'user_id' => $userId]);

    return $statement->rowCount() === 1;
}

function staff_clock_out(int $timeLogId, int $userId): void
{
    ensure_staff_tables();
    $statement = db()->prepare(
        'UPDATE staff_time_log
         SET clock_out = CURRENT_TIMESTAMP,
             last_activity_at = CURRENT_TIMESTAMP,
             total_seconds = GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - clock_in)))::INT)
         WHERE time_log_id = :time_log_id
           AND user_id = :user_id
           AND clock_out IS NULL'
    );
    $statement->execute(['time_log_id' => $timeLogId, 'user_id' => $userId]);
}

function staff_audit(string $action, string $table, int|string $recordId, array $details = []): void
{
    $statement = db()->prepare(
        'INSERT INTO audit_log (user_id, action, affected_table, affected_record_id, details)
         VALUES (:user_id, :action, :affected_table, :affected_record_id, CAST(:details AS JSONB))'
    );
    $statement->execute([
        'user_id' => $_SESSION['user']['id'] ?? null,
        'action' => $action,
        'affected_table' => $table,
        'affected_record_id' => (string) $recordId,
        'details' => json_encode($details, JSON_THROW_ON_ERROR),
    ]);
}
