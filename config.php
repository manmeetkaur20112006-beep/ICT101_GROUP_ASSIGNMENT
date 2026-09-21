<?php
// connects to the database, starts the session
// every page includes this file first

session_start();

$dbHost = "localhost";
$dbUser = "root";
$dbPass = "";
$dbName = "singh_fitness";

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Dark is the default look. If the member picked light on the profile page,
// a cookie remembers it and every page puts data-theme="light" on <html>.
$themeAttr = "";
if (isset($_COOKIE["theme"]) && $_COOKIE["theme"] == "light") {
    $themeAttr = ' data-theme="light"';
}

// The gym logo. The owner can upload their own on the settings page,
// otherwise the built-in one is used. Every page shows this.
$logoUrl = "assets/img/logo.svg";
$logoRow = $conn->query("SELECT logo_url FROM gym_config WHERE id = 1")->fetch_assoc();
if ($logoRow && $logoRow["logo_url"] != "") {
    $logoUrl = $logoRow["logo_url"];
}

// How many days in a row the member has checked in.
// Counted fresh from the attendance table every time, never stored.
// Walks back from today one day at a time:
//   - a rest day on their workout plan is skipped (doesn't count, doesn't break)
//   - today can be missing (they may not have been in yet)
//   - the first other missing day ends the streak
function getStreak($conn, $memberId) {
    $attended = [];
    $stmt = $conn->prepare("SELECT attended_on FROM member_attendance WHERE member_id = ? ORDER BY attended_on DESC LIMIT 400");
    $stmt->bind_param("s", $memberId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $attended[$row["attended_on"]] = true;
    }
    $stmt->close();

    $restDays = [];
    $stmt = $conn->prepare("SELECT d.day_name FROM workout_days d
                            JOIN members m ON m.workout_plan_id = d.plan_id
                            WHERE m.id = ? AND d.is_rest_day = 1");
    $stmt->bind_param("s", $memberId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $restDays[] = $row["day_name"];
    }
    $stmt->close();

    $streak = 0;
    $today = date("Y-m-d");
    $day = $today;
    for ($i = 0; $i < 400; $i++) {
        if (in_array(date("l", strtotime($day)), $restDays)) {
            // rest day, just step over it
        } elseif (isset($attended[$day])) {
            $streak++;
        } elseif ($day == $today) {
            // not been in yet today, that's fine, keep looking back
        } else {
            break;
        }
        $day = date("Y-m-d", strtotime($day . " -1 day"));
    }

    return $streak;
}

// Bonus points for finishing everything on today's list.
//   +10 when every exercise on today's plan is ticked
//   +5  when every meal on today's plan is ticked
// Given once per day (points_log has a unique key for that). If something is
// unticked afterwards the bonus is taken back, so it can't be farmed.
// Call this after any tick or untick.
function syncDailyBonus($conn, $memberId, $kind) {
    $todayName = date("l");

    if ($kind == "exercises") {
        $bonus = 10;
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM exercises e
                                JOIN workout_days d ON d.id = e.day_id
                                JOIN members m ON m.workout_plan_id = d.plan_id
                                WHERE m.id = ? AND d.day_name = ? AND d.is_rest_day = 0");
        $stmt->bind_param("ss", $memberId, $todayName);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["n"];
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM exercise_completions c
                                JOIN exercises e ON e.id = c.exercise_id
                                JOIN workout_days d ON d.id = e.day_id
                                JOIN members m ON m.workout_plan_id = d.plan_id
                                WHERE c.member_id = ? AND m.id = ? AND c.done_on = CURDATE() AND d.day_name = ?");
        $stmt->bind_param("sss", $memberId, $memberId, $todayName);
        $stmt->execute();
        $done = (int)$stmt->get_result()->fetch_assoc()["n"];
        $stmt->close();
    } else {
        $bonus = 5;
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM diet_meals dm
                                JOIN members m ON m.diet_plan_id = dm.plan_id
                                WHERE m.id = ? AND (dm.day_name IS NULL OR dm.day_name = ?)");
        $stmt->bind_param("ss", $memberId, $todayName);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()["n"];
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM meal_completions c
                                JOIN diet_meals dm ON dm.id = c.meal_id
                                JOIN members m ON m.diet_plan_id = dm.plan_id
                                WHERE c.member_id = ? AND m.id = ? AND c.done_on = CURDATE()
                                  AND (dm.day_name IS NULL OR dm.day_name = ?)");
        $stmt->bind_param("sss", $memberId, $memberId, $todayName);
        $stmt->execute();
        $done = (int)$stmt->get_result()->fetch_assoc()["n"];
        $stmt->close();
    }

    $allDone = $total > 0 && $done >= $total;

    $stmt = $conn->prepare("SELECT id FROM points_log WHERE member_id = ? AND reason = ? AND awarded_on = CURDATE()");
    $stmt->bind_param("ss", $memberId, $kind);
    $stmt->execute();
    $awarded = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($allDone && !$awarded) {
        $stmt = $conn->prepare("INSERT INTO points_log (member_id, reason, points, awarded_on) VALUES (?, ?, ?, CURDATE())");
        $stmt->bind_param("ssi", $memberId, $kind, $bonus);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE members SET points = points + ? WHERE id = ?");
        $stmt->bind_param("is", $bonus, $memberId);
        $stmt->execute();
        $stmt->close();
    } elseif (!$allDone && $awarded) {
        $stmt = $conn->prepare("DELETE FROM points_log WHERE id = ?");
        $stmt->bind_param("i", $awarded["id"]);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE members SET points = GREATEST(points - ?, 0) WHERE id = ?");
        $stmt->bind_param("is", $bonus, $memberId);
        $stmt->execute();
        $stmt->close();
    }
}
?>
