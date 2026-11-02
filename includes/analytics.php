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

    $kpiPeriods = $pdo->query(
        "WITH periods AS (
            SELECT DATE_TRUNC('month', CURRENT_TIMESTAMP) AS current_start,
                   CURRENT_TIMESTAMP AS current_end,
                   DATE_TRUNC('month', CURRENT_TIMESTAMP) - INTERVAL '1 month' AS previous_start,
                   LEAST(
                       DATE_TRUNC('month', CURRENT_TIMESTAMP),
                       DATE_TRUNC('month', CURRENT_TIMESTAMP) - INTERVAL '1 month'
                           + (CURRENT_TIMESTAMP - DATE_TRUNC('month', CURRENT_TIMESTAMP))
                   ) AS previous_end
        )
        SELECT 'residents' AS metric,
               COUNT(*) FILTER (WHERE r.created_at >= p.current_start AND r.created_at < p.current_end) AS current_count,
               COUNT(*) FILTER (WHERE r.created_at >= p.previous_start AND r.created_at < p.previous_end) AS previous_count
        FROM resident r CROSS JOIN periods p
        UNION ALL
        SELECT 'households',
               COUNT(*) FILTER (WHERE h.created_at >= p.current_start AND h.created_at < p.current_end),
               COUNT(*) FILTER (WHERE h.created_at >= p.previous_start AND h.created_at < p.previous_end)
        FROM household_record h CROSS JOIN periods p
        UNION ALL
        SELECT 'documents',
               COUNT(*) FILTER (WHERE d.request_date >= p.current_start AND d.request_date < p.current_end),
               COUNT(*) FILTER (WHERE d.request_date >= p.previous_start AND d.request_date < p.previous_end)
        FROM document_request d CROSS JOIN periods p
        UNION ALL
        SELECT 'appointments',
               COUNT(*) FILTER (WHERE a.created_at >= p.current_start AND a.created_at < p.current_end),
               COUNT(*) FILTER (WHERE a.created_at >= p.previous_start AND a.created_at < p.previous_end)
        FROM appointment a CROSS JOIN periods p
        UNION ALL
        SELECT 'blotter',
               COUNT(*) FILTER (WHERE b.report_date >= p.current_start AND b.report_date < p.current_end),
               COUNT(*) FILTER (WHERE b.report_date >= p.previous_start AND b.report_date < p.previous_end)
        FROM blotter b CROSS JOIN periods p
        UNION ALL
        SELECT 'staff',
               COUNT(*) FILTER (WHERE s.created_at >= p.current_start AND s.created_at < p.current_end),
               COUNT(*) FILTER (WHERE s.created_at >= p.previous_start AND s.created_at < p.previous_end)
        FROM staff s CROSS JOIN periods p
        UNION ALL
        SELECT 'alerts',
               COUNT(*) FILTER (WHERE d.created_at >= p.current_start AND d.created_at < p.current_end),
               COUNT(*) FILTER (WHERE d.created_at >= p.previous_start AND d.created_at < p.previous_end)
        FROM disaster_preparedness d CROSS JOIN periods p"
    )->fetchAll();
    $kpiChanges = [];
    foreach ($kpiPeriods as $period) {
        $currentCount = (int) $period['current_count'];
        $previousCount = (int) $period['previous_count'];
        $kpiChanges[(string) $period['metric']] = [
            'current' => $currentCount,
            'previous' => $previousCount,
            'percent' => $previousCount > 0
                ? round(($currentCount - $previousCount) / $previousCount * 100, 1)
                : null,
        ];
    }

    $residentStatuses = $pdo->query(
        'SELECT residency_status, COUNT(*) AS item_count
         FROM resident
         GROUP BY residency_status
         ORDER BY item_count DESC, residency_status'
    )->fetchAll();
    $residentAgeGroups = $pdo->query(
        "WITH population AS (
            SELECT birthdate
            FROM resident
            WHERE residency_status = 'Active'
            UNION ALL
            SELECT hm.birthdate
            FROM household_member hm
            WHERE hm.resident_id IS NULL
        ),
        age_bands AS (
            SELECT *
            FROM (VALUES
                ('Infant', 0, 2, 1),
                ('Minor', 3, 17, 2),
                ('Adult', 18, 59, 3),
                ('Senior', 60, NULL::INT, 4)
            ) AS bands(label, minimum_age, maximum_age, sort_order)
        )
        SELECT bands.label, COUNT(population.birthdate) AS item_count
        FROM age_bands bands
        LEFT JOIN population
          ON EXTRACT(YEAR FROM AGE(CURRENT_DATE, population.birthdate)) >= bands.minimum_age
         AND (bands.maximum_age IS NULL
              OR EXTRACT(YEAR FROM AGE(CURRENT_DATE, population.birthdate)) <= bands.maximum_age)
        GROUP BY bands.label, bands.sort_order
        ORDER BY bands.sort_order"
    )->fetchAll();
    $householdSummary = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM household_record) AS households,
            (SELECT COUNT(*) FROM household_member) AS members,
            (SELECT COUNT(*) FROM (
                SELECT household_id
                FROM household_member
                GROUP BY household_id
            ) populated) AS populated_households,
            (SELECT COALESCE(ROUND(AVG(member_count)::NUMERIC, 1), 0)
             FROM (
                SELECT COUNT(*) AS member_count
                FROM household_member
                GROUP BY household_id
             ) sizes) AS average_size"
    )->fetch();
    $householdSizeGroups = $pdo->query(
        "SELECT CASE
                    WHEN member_count = 1 THEN '1 member'
                    WHEN member_count BETWEEN 2 AND 3 THEN '2–3 members'
                    WHEN member_count BETWEEN 4 AND 5 THEN '4–5 members'
                    ELSE '6+ members'
                END AS label,
                COUNT(*) AS household_count,
                MIN(CASE
                    WHEN member_count = 1 THEN 1
                    WHEN member_count BETWEEN 2 AND 3 THEN 2
                    WHEN member_count BETWEEN 4 AND 5 THEN 3
                    ELSE 4
                END) AS sort_order
         FROM (
             SELECT household_id, COUNT(*) AS member_count
             FROM household_member
             GROUP BY household_id
         ) household_sizes
         GROUP BY label
         ORDER BY sort_order"
    )->fetchAll();
    $householdsByPurok = $pdo->query(
        'SELECT h.purok, COUNT(DISTINCT h.household_id) AS household_count,
                COUNT(hm.member_id) AS member_count
         FROM household_record h
         LEFT JOIN household_member hm ON hm.household_id = h.household_id
         GROUP BY h.purok
         ORDER BY household_count DESC, member_count DESC, h.purok
         LIMIT 6'
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
    $monthlyActivity = $pdo->query(
        "SELECT TO_CHAR(activity_month, 'YYYY-MM') AS month_key,
                SUM(document_count) AS document_count,
                SUM(blotter_count) AS blotter_count,
                SUM(appointment_count) AS appointment_count
         FROM (
             SELECT DATE_TRUNC('month', request_date) AS activity_month,
                    COUNT(*) AS document_count, 0 AS blotter_count, 0 AS appointment_count
             FROM document_request
             WHERE request_date >= DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '11 months'
             GROUP BY DATE_TRUNC('month', request_date)
             UNION ALL
             SELECT DATE_TRUNC('month', report_date), 0, COUNT(*), 0
             FROM blotter
             WHERE report_date >= DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '11 months'
             GROUP BY DATE_TRUNC('month', report_date)
             UNION ALL
             SELECT DATE_TRUNC('month', created_at), 0, 0, COUNT(*)
             FROM appointment
             WHERE created_at >= DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '11 months'
             GROUP BY DATE_TRUNC('month', created_at)
         ) activity
         GROUP BY activity_month
         ORDER BY activity_month"
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
    $activeDisasterAlerts = $pdo->query(
        "SELECT d.disaster_id, d.alert_type, d.severity, d.affected_area, d.alert_message,
                d.created_at, d.expires_at,
                COUNT(n.notification_id) FILTER (
                    WHERE n.delivery_status IN ('Sent', 'Delivered')
                ) AS delivered_count,
                COUNT(n.notification_id) FILTER (
                    WHERE n.delivery_status = 'Failed'
                ) AS failed_count
         FROM disaster_preparedness d
         LEFT JOIN notification_log n ON n.disaster_id = d.disaster_id
         WHERE d.expires_at IS NULL OR d.expires_at >= CURRENT_TIMESTAMP
         GROUP BY d.disaster_id
         ORDER BY d.created_at DESC
         LIMIT 5"
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
                    FLOOR(EXTRACT(EPOCH FROM (
                        COALESCE(
                            t.clock_out,
                            LEAST(CURRENT_TIMESTAMP, COALESCE(t.last_activity_at, t.clock_in) + INTERVAL '10 minutes')
                        ) - t.clock_in
                    )))::INT
                )), 0) AS total_seconds
         FROM staff s
         LEFT JOIN staff_time_log t
           ON t.user_id = s.user_id
          AND t.clock_in >= DATE_TRUNC(
              'month',
              CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila'
          ) AT TIME ZONE 'Asia/Manila'
         WHERE s.employment_status = 'Active'
         GROUP BY s.staff_id, s.first_name, s.last_name, s.position
         ORDER BY total_seconds DESC, staff_name
         LIMIT 10"
    )->fetchAll();
    $revenue = $pdo->query(
        "SELECT COALESCE(SUM(payment_amount) FILTER (WHERE payment_status = 'Paid'), 0) AS total_paid,
                COUNT(*) FILTER (WHERE payment_status = 'Paid') AS paid_requests,
                COUNT(*) FILTER (
                    WHERE payment_status = 'Paid'
                      AND paid_at >= DATE_TRUNC('month', CURRENT_DATE)
                ) AS paid_this_month
         FROM document_request"
    )->fetch();

    $monthlyMap = [];
    foreach ($monthlyActivity as $row) {
        $monthlyMap[$row['month_key']] = [
            'documents' => (int) $row['document_count'],
            'blotter' => (int) $row['blotter_count'],
            'appointments' => (int) $row['appointment_count'],
        ];
    }
    $months = [];
    $activityMonths = [];
    $today = new DateTimeImmutable('first day of this month', new DateTimeZone(APP_TIMEZONE));
    for ($offset = 11; $offset >= 0; $offset--) {
        $month = $today->modify("-{$offset} months");
        $key = $month->format('Y-m');
        $activity = $monthlyMap[$key] ?? ['documents' => 0, 'blotter' => 0, 'appointments' => 0];
        $months[] = [
            'key' => $key,
            'label' => $month->format('M'),
            'count' => $activity['documents'],
        ];
        $activityMonths[] = [
            'key' => $key,
            'label' => $month->format('M'),
            'count' => $activity['documents'],
            'blotter_count' => $activity['blotter'],
            'appointment_count' => $activity['appointments'],
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

    return [
        'overview' => array_map('intval', $overview),
        'household_summary' => [
            'households' => (int) $householdSummary['households'],
            'members' => (int) $householdSummary['members'],
            'populated_households' => (int) $householdSummary['populated_households'],
            'average_size' => (float) $householdSummary['average_size'],
        ],
        'household_size_groups' => array_map(
            static fn (array $row): array => [
                'label' => (string) $row['label'],
                'count' => (int) $row['household_count'],
            ],
            $householdSizeGroups
        ),
        'households_by_purok' => array_map(
            static fn (array $row): array => [
                'label' => (string) $row['purok'],
                'households' => (int) $row['household_count'],
                'members' => (int) $row['member_count'],
            ],
            $householdsByPurok
        ),
        'resident_age_groups' => array_map(
            static fn (array $row): array => [
                'label' => (string) $row['label'],
                'count' => (int) $row['item_count'],
            ],
            $residentAgeGroups
        ),
        'kpi_changes' => $kpiChanges,
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
        'monthly_activity' => $activityMonths,
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
        'active_disaster_alerts' => array_map(
            static fn (array $row): array => [
                'id' => (int) $row['disaster_id'],
                'type' => (string) $row['alert_type'],
                'severity' => (string) ($row['severity'] ?? 'Unspecified'),
                'area' => (string) ($row['affected_area'] ?? ''),
                'message' => (string) $row['alert_message'],
                'created_at' => (string) $row['created_at'],
                'expires_at' => $row['expires_at'] === null ? null : (string) $row['expires_at'],
                'delivered' => (int) $row['delivered_count'],
                'failed' => (int) $row['failed_count'],
            ],
            $activeDisasterAlerts
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
        'revenue' => [
            'available' => true,
            'amount' => (float) $revenue['total_paid'],
            'paid_requests' => (int) $revenue['paid_requests'],
            'paid_this_month' => (int) $revenue['paid_this_month'],
        ],
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
