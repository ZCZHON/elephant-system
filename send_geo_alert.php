<?php
// send_geo_alert.php
include('db.php');

define('LINE_CHANNEL_ACCESS_TOKEN', 'YOUR_LINE_CHANNEL_ACCESS_TOKEN');

function sendElephantAlert($report_id, $db) {
    // 1. ดึงข้อมูลรายงานเหตุการณ์ช้างป่า
    $q_report = "SELECT latitude, longitude, elephant_count, behavior_type, details, reported_at 
                 FROM tbl_reports WHERE report_id = $1";
    $res_report = pg_query_params($db, $q_report, array($report_id));
    
    if (!$res_report || pg_num_rows($res_report) === 0) {
        return 0;
    }
    $report = pg_fetch_assoc($res_report);
    $lat = (float)$report['latitude'];
    $lng = (float)$report['longitude'];

    // 2. ค้นหาผู้ใช้ในรัศมี 5 กม. ที่ "ยังไม่เคยได้รับแจ้งเตือนสำหรับ report_id นี้" (ป้องกันการส่งซ้ำ)
    $q_users = "SELECT DISTINCT u.user_id, u.line_user_id,
                       ROUND((ST_DistanceSphere(
                           ST_MakePoint(r.longitude, r.latitude),
                           ST_MakePoint($1, $2)
                       ) / 1000)::numeric, 2) AS distance_km
                FROM tbl_users u
                JOIN tbl_reports r ON u.user_id = r.user_id
                WHERE u.line_user_id IS NOT NULL 
                  AND u.line_user_id != ''
                  AND ST_DistanceSphere(
                        ST_MakePoint(r.longitude, r.latitude),
                        ST_MakePoint($1, $2)
                      ) <= 5000
                  AND u.user_id NOT IN (
                      -- กรองคนเคยได้รับแจ้งเตือนจาก tbl_alert สำหรับรายงานนี้ไปแล้วออก
                      SELECT user_id FROM tbl_alert WHERE report_id = $3
                  )";

    $res_users = pg_query_params($db, $q_users, array($lng, $lat, $report_id));
    
    if (!$res_users || pg_num_rows($res_users) === 0) {
        return 0; // ไม่มีผู้ใช้อยู่ในรัศมี หรือส่งเตือนไปครบทุกคนแล้ว
    }

    $target_users = [];
    $target_line_ids = [];

    while ($row = pg_fetch_assoc($res_users)) {
        $target_users[] = $row;
        $target_line_ids[] = $row['line_user_id'];
    }

    // 3. สร้างข้อความ Flex Message
    $message = [
        'type' => 'flex',
        'altText' => '⚠️ แจ้งเตือนภัย! พบช้างป่าในรัศมี 5 กิโลเมตรจากจุดของคุณ',
        'contents' => [
            'type' => 'bubble',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#DE350B',
                'contents' => [
                    ['type' => 'text', 'text' => '⚠️ เตือนภัยช้างป่าใกล้ตัว', 'weight' => 'bold', 'color' => '#FFFFFF', 'size' => 'lg']
                ]
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => 'พบช้างป่าในรัศมีไม่เกิน 5 กม. จากพื้นที่ของคุณ โปรดระมัดระวัง!', 'wrap' => true, 'color' => '#333333', 'size' => 'sm'],
                    ['type' => 'separator', 'margin' => 'md'],
                    [
                        'type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'sm',
                        'contents' => [
                            ['type' => 'text', 'text' => '🐘 จำนวน: ' . ($report['elephant_count'] ?? 1) . ' ตัว', 'size' => 'sm', 'weight' => 'bold'],
                            ['type' => 'text', 'text' => '📌 พฤติกรรม: ' . ($report['behavior_type'] ?: 'ไม่ระบุ'), 'size' => 'sm'],
                            ['type' => 'text', 'text' => '📝 รายละเอียด: ' . ($report['details'] ?: '-'), 'size' => 'sm', 'wrap' => true]
                        ]
                    ]
                ]
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'button',
                        'action' => [
                            'type' => 'uri',
                            'label' => '🗺️ ดูตำแหน่งบนแผนที่',
                            'uri' => 'https://' . $_SERVER['HTTP_HOST'] . '/public_map.php?highlight_id=' . $report_id
                        ],
                        'style' => 'primary', 'color' => '#DE350B'
                    ]
                ]
            ]
        ]
    ];

    // 4. ส่งข้อความผ่าน LINE Multicast API และ บันทึกลง tbl_alert
    $chunks = array_chunk($target_users, 500); // LINE รองรับไม่เกิน 500 user/รอบ
    $total_sent = 0;

    foreach ($chunks as $chunk) {
        $chunk_line_ids = array_column($chunk, 'line_user_id');

        $data = [
            'to' => $chunk_line_ids,
            'messages' => [$message]
        ];

        $ch = curl_init('https://api.line.me/v2/bot/message/multicast');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 5. บันทึกลง tbl_alert
        if ($http_code == 200) {
            foreach ($chunk as $u) {
                $q_log = "INSERT INTO tbl_alert (report_id, user_id, distance_km, sent_status) 
                          VALUES ($1, $2, $3, 'SENT')";
                pg_query_params($db, $q_log, array($report_id, $u['user_id'], $u['distance_km']));
            }
            $total_sent += count($chunk);
        } else {
            // กรณีส่งไม่สำเร็จ
            foreach ($chunk as $u) {
                $q_log = "INSERT INTO tbl_alert (report_id, user_id, distance_km, sent_status) 
                          VALUES ($1, $2, $3, 'FAILED')";
                pg_query_params($db, $q_log, array($report_id, $u['user_id'], $u['distance_km']));
            }
        }
    }

    return $total_sent;
}
