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

function staff_clock_in(int $userId): array
{
    ensure_staff_tables();
    $active = db()->prepare('SELECT time_log_id, clock_in FROM staff_time_log WHERE user_id = :user_id AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1');
    $active->execute(['user_id' => $userId]);
    $row = $active->fetch();
    if ($row) {
        return $row;
    }
    $insert = db()->prepare('INSERT INTO staff_time_log (user_id) VALUES (:user_id) RETURNING time_log_id, clock_in');
    $insert->execute(['user_id' => $userId]);
    return $insert->fetch();
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
