<?php
// send_geo_alert.php
date_default_timezone_set('Asia/Bangkok');

/**
 * ฟังก์ชันส่งแจ้งเตือนภัยช้างป่าไปยังผู้ใช้ที่อยู่ในรัศมีระยะทางที่กำหนด
 *
 * @param int $report_id ID ของรายงานการพบเห็นช้างป่า
 * @param resource $db Connection Resource ของ PostgreSQL (pg_connect)
 * @param float $radius_km รัศมีในการแจ้งเตือน (กิโลเมตร) Default = 5.0
 * @return int จำนวนผู้ใช้ที่ส่งแจ้งเตือนสำเร็จ
 */
function sendElephantAlert($report_id, $db, $radius_km = 5.0) {
    // 🔑 1. กำหนด LINE Channel Access Token ของคุณ
    $channel_access_token = 'YOUR_LINE_CHANNEL_ACCESS_TOKEN'; 
    $base_url = 'https://yourdomain.com/'; // URL หลักของเว็บไซต์สำหรับดูรูปและเปิดแผนที่

    if ($report_id <= 0 || !$db) {
        return 0;
    }

    // 📜 2. ดึงข้อมูลรายงานการพบช้างป่าตาม report_id
    $report_q = "SELECT r.*, 
                        CONCAT(u.first_name, ' ', u.last_name) AS reporter_name
                 FROM tbl_reports r
                 LEFT JOIN tbl_users u ON r.user_id = u.user_id
                 WHERE r.report_id = $1";
    $report_res = pg_query_params($db, $report_q, array($report_id));

    if (!$report_res || pg_num_rows($report_res) === 0) {
        return 0;
    }

    $report = pg_fetch_assoc($report_res);
    $rep_lat = floatval($report['latitude']);
    $rep_lng = floatval($report['longitude']);
    $elephant_count = intval($report['elephant_count'] ?? 1);
    $behavior = !empty($report['behavior_type']) ? $report['behavior_type'] : ($report['behavior'] ?? 'ไม่ระบุ');
    $details = !empty($report['details']) ? $report['details'] : 'โปรดระมัดระวังเมื่อสัญจรผ่านบริเวณนี้';
    $photo_path = !empty($report['photo_path']) ? $base_url . ltrim($report['photo_path'], '/') : '';

    // 🗺️ 3. ค้นหา LINE User ID ของผู้ใช้ในตาราง tbl_users ที่อยู่ในรัศมี $radius_km
    // ใช้สูตร Haversine Formula คำนวณระยะทางทางภูมิศาสตร์ (หน่วยเป็นกิโลเมตร: 6371 * acos(...))
    // รองรับทั้งระบบที่ติดตั้ง Extension PostGIS หรือ PostgreSQL มาตรฐาน
    $geo_sql = "
        SELECT line_user_id,
               (6371 * acos(
                    cos(radians($1)) * cos(radians(last_latitude)) *
                    cos(radians(last_longitude) - radians($2)) +
                    sin(radians($1)) * sin(radians(last_latitude))
               )) AS distance_km
        FROM tbl_users
        WHERE line_user_id IS NOT NULL 
          AND line_user_id != ''
          AND last_latitude IS NOT NULL 
          AND last_longitude IS NOT NULL
          AND (6371 * acos(
                    cos(radians($1)) * cos(radians(last_latitude)) *
                    cos(radians(last_longitude) - radians($2)) +
                    sin(radians($1)) * sin(radians(last_latitude))
               )) <= $3
    ";

    $geo_res = pg_query_params($db, $geo_sql, array($rep_lat, $rep_lng, $radius_km));

    if (!$geo_res || pg_num_rows($geo_res) === 0) {
        return 0; // ไม่มีผู้ใช้อยู่ในรัศมีเตือนภัย
    }

    $users_to_alert = pg_fetch_all($geo_res) ?: [];
    $success_count = 0;

    // 🎨 4. สร้างโครงสร้าง LINE Flex Message การเตือนภัย
    $flex_message_data = [
        "type" => "flex",
        "altText" => "🚨 แจ้งเตือนภัย! พบช้างป่าในรัศมี " . $radius_km . " กม. จากตำแหน่งของคุณ",
        "contents" => [
            "type" => "bubble",
            "size" => "mega",
            "header" => [
                "type" => "box",
                "layout" => "vertical",
                "backgroundColor" => "#dc3545",
                "paddingAll" => "15px",
                "contents" => [
                    [
                        "type" => "text",
                        "text" => "🚨 แจ้งเตือนภัยช้างป่าใกล้ตัว",
                        "weight" => "bold",
                        "color" => "#ffffff",
                        "size" => "lg"
                    ],
                    [
                        "type" => "text",
                        "text" => "พบช้างป่าในระยะห่างประมาณ " . $radius_km . " กม.",
                        "color" => "#ffcccc",
                        "size" => "xs",
                        "marginTop" => "4px"
                    ]
                ]
            ],
            "body" => [
                "type" => "box",
                "layout" => "vertical",
                "spacing" => "md",
                "contents" => array_merge(
                    $photo_path ? [[
                        "type" => "image",
                        "url" => $photo_path,
                        "size" => "full",
                        "aspectRatio" => "20:13",
                        "aspectMode" => "cover",
                        "cornerRadius" => "8px"
                    ]] : [],
                    [
                        [
                            "type" => "box",
                            "layout" => "vertical",
                            "spacing" => "xs",
                            "contents" => [
                                [
                                    "type" => "text",
                                    "text" => "🐘 จำนวนที่พบ: " . $elephant_count . " ตัว",
                                    "weight" => "bold",
                                    "size" => "md",
                                    "color" => "#222222"
                                ],
                                [
                                    "type" => "text",
                                    "text" => "⚠️ พฤติกรรม: " . $behavior,
                                    "size" => "sm",
                                    "color" => "#d9534f",
                                    "weight" => "bold"
                                ],
                                [
                                    "type" => "text",
                                    "text" => "📝 รายละเอียด: " . $details,
                                    "size" => "xs",
                                    "color" => "#666666",
                                    "wrap" => true
                                ],
                                [
                                    "type" => "text",
                                    "text" => "⏰ เวลาแจ้งเหตุ: " . date('d/m/Y H:i น.', strtotime($report['reported_at'])),
                                    "size" => "xs",
                                    "color" => "#888888",
                                    "marginTop" => "6px"
                                ]
                            ]
                        ]
                    ]
                )
            ],
            "footer" => [
                "type" => "box",
                "layout" => "vertical",
                "spacing" => "sm",
                "contents" => [
                    [
                        "type" => "button",
                        "action" => [
                            "type" => "uri",
                            "label" => "🗺️ ดูพิกัดบนแผนที่สาธารณะ",
                            "uri" => $base_url . "public_map.php?highlight_id=" . $report_id
                        ],
                        "style" => "primary",
                        "color" => "#198754",
                        "height" => "sm"
                    ]
                ]
            ]
        ]
    ];

    // 📤 5. วนลูปส่ง Push Message ไปยังผู้ใช้แต่ละคนผ่าน LINE API
    foreach ($users_to_alert as $user) {
        $to_line_id = $user['line_user_id'];
        
        $payload = [
            "to" => $to_line_id,
            "messages" => [$flex_message_data]
        ];

        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $channel_access_token
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            $success_count++;
        }
    }

    return $success_count;
}
?>
