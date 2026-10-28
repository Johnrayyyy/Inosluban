<?php
declare(strict_types=1);

function admin_analytics_data(): array
{
    $pdo = db();
    $overview = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM resident) AS residents,
            (SELECT COUNT(*) FROM household_record) AS households,
            (SELECT COUNT(*) FROM document_request) AS document_requests,
            (SELECT COUNT(*) FROM document_request WHERE status = 'Pending') AS pending_documents,
            (SELECT COUNT(*) FROM appointment WHERE schedule >= CURRENT_TIMESTAMP AND status IN ('Pending', 'Approved')) AS upcoming_appointments,
            (SELECT COUNT(*) FROM blotter WHERE status IN ('Pending', 'Under Review', 'In Progress')) AS open_blotter,
            (SELECT COUNT(*) FROM staff WHERE employment_status = 'Active') AS active_staff,
            (SELECT COUNT(*) FROM announcement WHERE status = 'Published') AS published_announcements,
            (SELECT COUNT(*) FROM disaster_preparedness WHERE expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP) AS active_disaster_alerts,
            (SELECT COUNT(*) FROM notification_log WHERE delivery_status = 'Failed') AS failed_notifications"
    )->fetch();

    $residentStatuses = $pdo->query(
        'SELECT residency_status, COUNT(*) AS item_count
         FROM resident
         GROUP BY residency_status
         ORDER BY item_count DESC, residency_status'
    )->fetchAll();
    $documentTypes = $pdo->query(
        'SELECT document_type, COUNT(*) AS request_count
         FROM document_request
         GROUP BY document_type
         ORDER BY request_count DESC, document_type'
    )->fetchAll();
    $documentStatuses = $pdo->query(
        'SELECT status, COUNT(*) AS request_count
         FROM document_request
         GROUP BY status
         ORDER BY request_count DESC, status'
    )->fetchAll();
    $monthlyDocuments = $pdo->query(
        "SELECT TO_CHAR(DATE_TRUNC('month', request_date), 'YYYY-MM') AS month_key,
                COUNT(*) AS request_count
         FROM document_request
         WHERE request_date >= DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '11 months'
         GROUP BY DATE_TRUNC('month', request_date)
         ORDER BY DATE_TRUNC('month', request_date)"
    )->fetchAll();
    $weekdayDocuments = $pdo->query(
        "SELECT EXTRACT(ISODOW FROM request_date)::INT AS weekday_number,
                COUNT(*) AS request_count
         FROM document_request
         WHERE request_date >= CURRENT_DATE - INTERVAL '2 years'
         GROUP BY EXTRACT(ISODOW FROM request_date)
         ORDER BY weekday_number"
    )->fetchAll();
    $appointmentStatus = $pdo->query(
        'SELECT status, COUNT(*) AS item_count
         FROM appointment
         GROUP BY status
         ORDER BY item_count DESC, status'
    )->fetchAll();
    $blotterStatus = $pdo->query(
        'SELECT status, COUNT(*) AS item_count
         FROM blotter
         GROUP BY status
         ORDER BY item_count DESC, status'
    )->fetchAll();
    $blotterCategories = $pdo->query(
        'SELECT category, COUNT(*) AS item_count
         FROM blotter
         GROUP BY category
         ORDER BY item_count DESC, category
         LIMIT 8'
    )->fetchAll();
    $announcementStatus = $pdo->query(
        'SELECT status, COUNT(*) AS item_count
         FROM announcement
         GROUP BY status
         ORDER BY item_count DESC, status'
    )->fetchAll();
    $notificationStatus = $pdo->query(
        'SELECT channel, delivery_status, COUNT(*) AS item_count
         FROM notification_log
         GROUP BY channel, delivery_status
         ORDER BY channel, delivery_status'
    )->fetchAll();
    $disasterSeverity = $pdo->query(
        "SELECT COALESCE(severity, 'Unspecified') AS severity, COUNT(*) AS item_count
         FROM disaster_preparedness
         WHERE expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP
         GROUP BY severity
         ORDER BY item_count DESC, severity"
    )->fetchAll();
    $weatherHistory = $pdo->query(
        "SELECT DATE_TRUNC('day', observed_at)::DATE AS weather_date,
                ROUND(AVG(temperature)::NUMERIC, 1) AS average_temperature,
                COUNT(*) AS observations,
                MODE() WITHIN GROUP (ORDER BY weather_summary) AS common_condition
         FROM weather_observation
         WHERE observed_at >= CURRENT_DATE - INTERVAL '90 days'
           AND temperature IS NOT NULL
         GROUP BY DATE_TRUNC('day', observed_at)
         ORDER BY weather_date"
    )->fetchAll();
    $staffWorktime = $pdo->query(
        "SELECT s.staff_id,
                CONCAT_WS(' ', s.first_name, s.last_name) AS staff_name,
                COALESCE(s.position, 'Staff') AS position,
                COUNT(t.time_log_id) AS shifts,
                COALESCE(SUM(COALESCE(
                    t.total_seconds,
                    FLOOR(EXTRACT(EPOCH FROM (COALESCE(t.clock_out, CURRENT_TIMESTAMP) - t.clock_in)))::INT
                )), 0) AS total_seconds
         FROM staff s
         LEFT JOIN staff_time_log t
           ON t.user_id = s.user_id
          AND t.clock_in >= DATE_TRUNC('month', CURRENT_DATE)
         WHERE s.employment_status = 'Active'
         GROUP BY s.staff_id, s.first_name, s.last_name, s.position
         ORDER BY total_seconds DESC, staff_name
         LIMIT 10"
    )->fetchAll();

    $monthlyMap = [];
    foreach ($monthlyDocuments as $row) {
        $monthlyMap[$row['month_key']] = (int) $row['request_count'];
    }
    $months = [];
    $today = new DateTimeImmutable('first day of this month', new DateTimeZone(APP_TIMEZONE));
    for ($offset = 11; $offset >= 0; $offset--) {
        $month = $today->modify("-{$offset} months");
        $key = $month->format('Y-m');
        $months[] = [
            'key' => $key,
            'label' => $month->format('M'),
            'count' => $monthlyMap[$key] ?? 0,
        ];
    }

    $weekdayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    $weekdays = [];
    foreach ($weekdayDocuments as $row) {
        $dayNumber = (int) $row['weekday_number'];
        $weekdays[] = [
            'day' => $weekdayNames[$dayNumber] ?? 'Unknown',
            'count' => (int) $row['request_count'],
        ];
    }

    $revenue = [
        'available' => false,
        'amount' => null,
        'message' => 'Payment collection is not configured; revenue is not yet tracked.',
    ];

    return [
        'overview' => array_map('intval', $overview),
        'document_types' => array_map(
            static fn (array $row): array => ['label' => (string) $row['document_type'], 'count' => (int) $row['request_count']],
            $documentTypes
        ),
        'resident_statuses' => array_map(
            static fn (array $row): array => ['label' => (string) $row['residency_status'], 'count' => (int) $row['item_count']],
            $residentStatuses
        ),
        'document_statuses' => array_map(
            static fn (array $row): array => ['label' => (string) $row['status'], 'count' => (int) $row['request_count']],
            $documentStatuses
        ),
        'monthly_documents' => $months,
        'weekday_documents' => $weekdays,
        'appointment_status' => array_map(
            static fn (array $row): array => ['label' => (string) $row['status'], 'count' => (int) $row['item_count']],
            $appointmentStatus
        ),
        'blotter_status' => array_map(
            static fn (array $row): array => ['label' => (string) $row['status'], 'count' => (int) $row['item_count']],
            $blotterStatus
        ),
        'blotter_categories' => array_map(
            static fn (array $row): array => ['label' => (string) $row['category'], 'count' => (int) $row['item_count']],
            $blotterCategories
        ),
        'announcement_status' => array_map(
            static fn (array $row): array => ['label' => (string) $row['status'], 'count' => (int) $row['item_count']],
            $announcementStatus
        ),
        'notification_status' => array_map(
            static fn (array $row): array => [
                'channel' => (string) $row['channel'],
                'status' => (string) $row['delivery_status'],
                'count' => (int) $row['item_count'],
            ],
            $notificationStatus
        ),
        'disaster_severity' => array_map(
            static fn (array $row): array => ['label' => (string) $row['severity'], 'count' => (int) $row['item_count']],
            $disasterSeverity
        ),
        'weather_history' => array_map(
            static fn (array $row): array => [
                'date' => (string) $row['weather_date'],
                'average_temperature' => $row['average_temperature'] === null ? null : (float) $row['average_temperature'],
                'observations' => (int) $row['observations'],
                'common_condition' => (string) ($row['common_condition'] ?? 'Unknown'),
            ],
            $weatherHistory
        ),
        'staff_worktime' => array_map(
            static fn (array $row): array => [
                'name' => (string) $row['staff_name'],
                'position' => (string) $row['position'],
                'shifts' => (int) $row['shifts'],
                'hours' => round((int) $row['total_seconds'] / 3600, 1),
            ],
            $staffWorktime
        ),
        'revenue' => $revenue,
        'generated_at' => (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format(DATE_ATOM),
    ];
}

function admin_analytics_ai_prompt(array $analytics): string
{
    $snapshot = latest_weather_snapshot()['weather'] ?? null;
    $latestWeather = null;
    if (is_array($snapshot)) {
        $latestWeather = [
            'source' => $snapshot['source_name'] ?? 'Unknown',
            'summary' => $snapshot['summary'] ?? 'Unknown',
            'severity' => $snapshot['severity'] ?? 'Unknown',
            'current' => [
                'time' => $snapshot['current']['time'] ?? null,
                'temperature_c' => $snapshot['current']['temperature'] ?? null,
                'rain_mm' => $snapshot['current']['rain'] ?? null,
                'wind_kmh' => $snapshot['current']['wind_speed'] ?? null,
            ],
            'forecast_next_days' => array_map(
                static fn (array $day): array => [
                    'date' => $day['date'] ?? null,
                    'condition' => $day['summary'] ?? null,
                    'min_c' => $day['temperature_min'] ?? null,
                    'max_c' => $day['temperature_max'] ?? null,
                    'rain_mm' => $day['rain_sum'] ?? null,
                ],
                array_slice($snapshot['forecast'] ?? [], 0, 7)
            ),
        ];
    }

    $evidence = [
        'as_of' => $analytics['generated_at'],
        'coverage_note' => 'Predictions must be cautious. Document request records are all-time totals, monthly counts cover the last 12 months, weekday counts cover the last 2 years. Weather history only includes saved advisory observations and may be sparse. The latest weather snapshot is not a historical series.',
        'requests_by_month_last_12_months' => $analytics['monthly_documents'],
        'requests_by_weekday_last_2_years' => $analytics['weekday_documents'],
        'requests_by_document_type_all_time' => $analytics['document_types'],
        'current_request_statuses' => $analytics['document_statuses'],
        'resident_statuses' => $analytics['resident_statuses'],
        'appointment_statuses' => $analytics['appointment_status'],
        'blotter_statuses' => $analytics['blotter_status'],
        'blotter_categories' => $analytics['blotter_categories'],
        'announcement_statuses' => $analytics['announcement_status'],
        'notification_delivery_statuses' => $analytics['notification_status'],
        'active_disaster_alert_severity' => $analytics['disaster_severity'],
        'historical_weather_observations_last_90_days' => $analytics['weather_history'],
        'latest_weather_snapshot' => $latestWeather,
    ];

    return 'You are an analytics assistant for Barangay Inosluban administrators. '
        . 'Use only the aggregate JSON evidence below; do not infer identities or request private records. '
        . 'Provide a concise predictive analysis with these headings: Weather outlook, Document demand outlook, Confidence and data limits, Recommended preparation. '
        . 'Distinguish observed facts from predictions. Forecast likely busy weekdays or months only when trends support it. '
        . 'Do not invent numeric probabilities or claim scientific weather certainty. State clearly when the historical sample is too small. '
        . 'Weather history is advisory observations, not a continuous meteorological time series, and should not be presented as reliable climate prediction. '
        . 'Evidence: ' . json_encode($evidence, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}
