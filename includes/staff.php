<?php

function ensure_staff_tables(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS staff_time_log (
            time_log_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id INT NOT NULL REFERENCES user_account(user_id) ON DELETE CASCADE,
            clock_in TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            clock_out TIMESTAMPTZ,
            total_seconds INT,
            CHECK (clock_out IS NULL OR clock_out >= clock_in)
        )"
    );
}

function staff_start_time_log(int $userId): array
{
    ensure_staff_tables();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $closePrevious = $pdo->prepare(
            'UPDATE staff_time_log
             SET clock_out = CURRENT_TIMESTAMP,
                 total_seconds = GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - clock_in)))::INT)
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

function staff_clock_out(int $timeLogId, int $userId): void
{
    ensure_staff_tables();
    $statement = db()->prepare(
        'UPDATE staff_time_log
         SET clock_out = CURRENT_TIMESTAMP,
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
