<?php
require_once "config.php";

// Owner only. Checked on the server before anything else happens.
// Hiding the link is not security, this line is.
if (!isset($_SESSION["memberId"]) || $_SESSION["role"] != "admin") {
    header("Location: login.php");
    exit;
}

$successMsg = "";
$msgSection = "";   // which section the message belongs under

// ---- housekeeping: keep membership statuses in step with the dates ----
// runs every time the owner opens the dashboard, cheap enough
$conn->query("UPDATE members SET status = 'Expired'
              WHERE role = 'member' AND expiry_date < CURDATE() AND status IN ('Active', 'Expiring Soon')");
$conn->query("UPDATE members SET status = 'Expiring Soon'
              WHERE role = 'member' AND status = 'Active'
                AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
$conn->query("UPDATE members SET status = 'Active'
              WHERE role = 'member' AND status = 'Expiring Soon'
                AND expiry_date > DATE_ADD(CURDATE(), INTERVAL 7 DAY)");

// ---- actions: approve / reject members, plan requests, bookings, feedback ----
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST["action"] ?? "";
    $targetId = $_POST["id"] ?? "";

    if ($action == "approveMember") {
        // membership starts today, expiry from the duration they picked at signup
        $stmt = $conn->prepare("SELECT membership_duration FROM members WHERE id = ? AND role = 'pending'");
        $stmt->bind_param("s", $targetId);
        $stmt->execute();
        $pendingRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($pendingRow) {
            $months = ["1 Month" => 1, "2 Months" => 2, "3 Months" => 3][$pendingRow["membership_duration"]] ?? 1;
            $joined = date("Y-m-d");
            $expiry = date("Y-m-d", strtotime("+$months month"));

            $stmt = $conn->prepare("UPDATE members SET role = 'member', status = 'Active', joined_date = ?, expiry_date = ? WHERE id = ?");
            $stmt->bind_param("sss", $joined, $expiry, $targetId);
            $stmt->execute();
            $stmt->close();
            $successMsg = "Member approved.";
            $msgSection = "members";
        }
    }

    if ($action == "rejectMember") {
        // they never became a member, so the signup row just goes
        $stmt = $conn->prepare("DELETE FROM members WHERE id = ? AND role = 'pending'");
        $stmt->bind_param("s", $targetId);
        $stmt->execute();
        $stmt->close();
        $successMsg = "Signup removed.";
        $msgSection = "members";
    }

    if ($action == "approveRequest" || $action == "rejectRequest") {
        $stmt = $conn->prepare("SELECT member_id, plan_type, requested_plan_id FROM plan_requests WHERE id = ? AND status = 'Pending'");
        $stmt->bind_param("s", $targetId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($req) {
            if ($action == "approveRequest") {
                $column = $req["plan_type"] == "diet" ? "diet_plan_id" : "workout_plan_id";
                $stmt = $conn->prepare("UPDATE members SET $column = ? WHERE id = ?");
                $stmt->bind_param("ss", $req["requested_plan_id"], $req["member_id"]);
                $stmt->execute();
                $stmt->close();
                $newStatus = "Approved";
            } else {
                $newStatus = "Rejected";
            }
            $stmt = $conn->prepare("UPDATE plan_requests SET status = ? WHERE id = ?");
            $stmt->bind_param("ss", $newStatus, $targetId);
            $stmt->execute();
            $stmt->close();
            $successMsg = "Plan request " . strtolower($newStatus) . ".";
            $msgSection = "requests";
        }
    }

    if ($action == "confirmBooking" || $action == "rejectBooking" || $action == "completeBooking") {
        $newStatus = "Confirmed";
        if ($action == "rejectBooking") {
            $newStatus = "Rejected";
        } elseif ($action == "completeBooking") {
            $newStatus = "Completed";
        }
        $bookingId = (int)$targetId;
        $stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $newStatus, $bookingId);
        $stmt->execute();
        $stmt->close();
        $successMsg = "Booking " . strtolower($newStatus) . ".";
        $msgSection = "bookings";
    }

    if ($action == "seenFeedback") {
        $feedbackId = (int)$targetId;
        $stmt = $conn->prepare("UPDATE feedback SET seen_by_admin = 1 WHERE id = ?");
        $stmt->bind_param("i", $feedbackId);
        $stmt->execute();
        $stmt->close();
        $successMsg = "Marked as seen.";
        $msgSection = "feedback";
    }

    header("Location: admin-dashboard.php?msg=" . urlencode($successMsg) . "&at=" . $msgSection);
    exit;
}

if (isset($_GET["msg"])) {
    $successMsg = $_GET["msg"];
    $msgSection = $_GET["at"] ?? "";
}

// ---- the numbers at the top ----
$counts = $conn->query("SELECT
    SUM(role = 'member') AS members,
    SUM(role = 'member' AND status = 'Active') AS active,
    SUM(role = 'member' AND status = 'Expiring Soon') AS expiring,
    SUM(role = 'member' AND status = 'Expired') AS expired,
    SUM(role = 'pending') AS pending
    FROM members")->fetch_assoc();

$checkedInToday = (int)$conn->query("SELECT COUNT(*) AS n FROM member_attendance WHERE attended_on = CURDATE()")->fetch_assoc()["n"];
$openRequests = (int)$conn->query("SELECT COUNT(*) AS n FROM plan_requests WHERE status = 'Pending'")->fetch_assoc()["n"];
$openBookings = (int)$conn->query("SELECT COUNT(*) AS n FROM bookings WHERE status = 'Pending'")->fetch_assoc()["n"];
$newFeedback = (int)$conn->query("SELECT COUNT(*) AS n FROM feedback WHERE seen_by_admin = 0")->fetch_assoc()["n"];

// ---- lists that need the owner's attention ----
$pendingMembers = [];
$result = $conn->query("SELECT id, name, email, phone, gym_access, membership_duration, created_at
                        FROM members WHERE role = 'pending' ORDER BY created_at");
while ($row = $result->fetch_assoc()) {
    $pendingMembers[] = $row;
}

$pendingRequests = [];
$result = $conn->query("SELECT id, member_name, plan_type, current_plan_name, requested_plan_name, created_at
                        FROM plan_requests WHERE status = 'Pending' ORDER BY created_at");
while ($row = $result->fetch_assoc()) {
    $pendingRequests[] = $row;
}

$pendingBookings = [];
$result = $conn->query("SELECT b.id, b.session_type, b.booking_date, b.time_slot, b.note, m.name
                        FROM bookings b JOIN members m ON m.id = b.member_id
                        WHERE b.status = 'Pending' ORDER BY b.booking_date, b.time_slot");
while ($row = $result->fetch_assoc()) {
    $pendingBookings[] = $row;
}

// confirmed sessions from today onwards, so the owner sees what's coming
$upcomingBookings = [];
$result = $conn->query("SELECT b.id, b.session_type, b.booking_date, b.time_slot, m.name
                        FROM bookings b JOIN members m ON m.id = b.member_id
                        WHERE b.status = 'Confirmed' AND b.booking_date >= CURDATE()
                        ORDER BY b.booking_date, b.time_slot LIMIT 10");
while ($row = $result->fetch_assoc()) {
    $upcomingBookings[] = $row;
}

$unseenFeedback = [];
$result = $conn->query("SELECT id, name, email, subject, message, rating, created_at
                        FROM feedback WHERE seen_by_admin = 0 ORDER BY created_at DESC LIMIT 10");
while ($row = $result->fetch_assoc()) {
    $unseenFeedback[] = $row;
}
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Dashboard - Singh Fitness Admin</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?php echo filemtime("assets/css/base.css"); ?>">
<link rel="stylesheet" href="assets/css/shared.css?v=<?php echo filemtime("assets/css/shared.css"); ?>">
</head>
<body class="has-tabbar">

<header class="topbar">
    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Singh Fitness logo" width="140" height="42">
    <div class="who">
        <?php echo htmlspecialchars($_SESSION["memberName"]); ?><br>
        Owner
    </div>
</header>

<div class="page">

    <h1 class="title">Dashboard</h1>
    <p class="sub"><?php echo date("l j F"); ?> &middot; <?php echo $checkedInToday; ?> checked in today</p>

    <div class="stat-grid three">
        <div class="stat" data-sq="22">
            <div class="num small"><?php echo (int)$counts["members"]; ?></div>
            <div class="lbl">Members</div>
        </div>
        <div class="stat good" data-sq="22">
            <div class="num small"><?php echo (int)$counts["active"]; ?></div>
            <div class="lbl">Active</div>
        </div>
        <div class="stat gold" data-sq="22">
            <div class="num small"><?php echo (int)$counts["expiring"]; ?></div>
            <div class="lbl">Expiring soon</div>
        </div>
    </div>
    <div class="stat-grid three" style="margin-top:12px;">
        <div class="stat <?php echo $counts["pending"] > 0 ? "good" : "muted"; ?>" data-sq="22">
            <div class="num small"><?php echo (int)$counts["pending"]; ?></div>
            <div class="lbl">To approve</div>
        </div>
        <div class="stat <?php echo $openRequests > 0 ? "good" : "muted"; ?>" data-sq="22">
            <div class="num small"><?php echo $openRequests; ?></div>
            <div class="lbl">Plan requests</div>
        </div>
        <div class="stat <?php echo $openBookings > 0 ? "good" : "muted"; ?>" data-sq="22">
            <div class="num small"><?php echo $openBookings; ?></div>
            <div class="lbl">Bookings</div>
        </div>
    </div>

    <!-- ===== NEW SIGNUPS ===== -->
    <h2 class="section-title">New signups <span class="muted"><?php echo count($pendingMembers); ?> waiting</span></h2>
    <?php if ($msgSection == "members") { ?><p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p><?php } ?>
    <?php if (count($pendingMembers) == 0) { ?>
        <p class="empty-text">No one waiting for approval.</p>
    <?php } else { ?>
        <div class="group plain" data-sq="22">
            <?php foreach ($pendingMembers as $p) { ?>
                <div class="row split">
                    <div class="label">
                        <?php echo htmlspecialchars($p["name"]); ?>
                        <span class="hint">
                            <?php echo htmlspecialchars($p["gym_access"]); ?> &middot; <?php echo htmlspecialchars($p["membership_duration"]); ?><br>
                            <?php echo htmlspecialchars($p["email"]); ?> &middot; <?php echo htmlspecialchars($p["phone"]); ?> &middot; signed up <?php echo date("j M", strtotime($p["created_at"])); ?>
                        </span>
                    </div>
                    <form method="POST" action="admin-dashboard.php" class="actions">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($p["id"]); ?>">
                        <button type="submit" name="action" value="approveMember" class="btn frost small" data-press>Approve</button>
                        <button type="submit" name="action" value="rejectMember" class="btn-outline small confirm-btn" data-confirm="Remove this signup? This can't be undone.">Reject</button>
                    </form>
                </div>
            <?php } ?>
        </div>
        <p class="hint" style="text-align:center;">Approve once you've seen their payment. Membership starts from today.</p>
    <?php } ?>

    <!-- ===== PLAN REQUESTS ===== -->
    <h2 class="section-title">Plan change requests <span class="muted"><?php echo count($pendingRequests); ?> waiting</span></h2>
    <?php if ($msgSection == "requests") { ?><p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p><?php } ?>
    <?php if (count($pendingRequests) == 0) { ?>
        <p class="empty-text">No plan requests waiting.</p>
    <?php } else { ?>
        <div class="group plain" data-sq="22">
            <?php foreach ($pendingRequests as $r) { ?>
                <div class="row split">
                    <div class="label">
                        <?php echo htmlspecialchars($r["member_name"]); ?>
                        <span class="pill"><?php echo ucfirst($r["plan_type"]); ?></span>
                        <span class="hint">
                            <?php echo $r["current_plan_name"] != "" ? htmlspecialchars($r["current_plan_name"]) : "none"; ?>
                            &rarr; <strong><?php echo htmlspecialchars($r["requested_plan_name"]); ?></strong>
                            &middot; <?php echo date("j M", strtotime($r["created_at"])); ?>
                        </span>
                    </div>
                    <form method="POST" action="admin-dashboard.php" class="actions">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($r["id"]); ?>">
                        <button type="submit" name="action" value="approveRequest" class="btn frost small" data-press>Approve</button>
                        <button type="submit" name="action" value="rejectRequest" class="btn-outline small">Reject</button>
                    </form>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <!-- ===== BOOKINGS ===== -->
    <h2 class="section-title">Booking requests <span class="muted"><?php echo count($pendingBookings); ?> waiting</span></h2>
    <?php if ($msgSection == "bookings") { ?><p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p><?php } ?>
    <?php if (count($pendingBookings) == 0) { ?>
        <p class="empty-text">No bookings waiting.</p>
    <?php } else { ?>
        <div class="group plain" data-sq="22">
            <?php foreach ($pendingBookings as $b) { ?>
                <div class="row split">
                    <div class="label">
                        <?php echo htmlspecialchars($b["name"]); ?> &middot; <?php echo htmlspecialchars($b["session_type"]); ?>
                        <span class="hint">
                            <?php echo date("D j M", strtotime($b["booking_date"])); ?> &middot; <?php echo htmlspecialchars($b["time_slot"]); ?>
                            <?php if ($b["note"] != "") { ?><br>"<?php echo htmlspecialchars($b["note"]); ?>"<?php } ?>
                        </span>
                    </div>
                    <form method="POST" action="admin-dashboard.php" class="actions">
                        <input type="hidden" name="id" value="<?php echo $b["id"]; ?>">
                        <button type="submit" name="action" value="confirmBooking" class="btn frost small" data-press>Confirm</button>
                        <button type="submit" name="action" value="rejectBooking" class="btn-outline small">Reject</button>
                    </form>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if (count($upcomingBookings) > 0) { ?>
        <h2 class="section-title">Confirmed sessions coming up</h2>
        <div class="group plain" data-sq="22">
            <?php foreach ($upcomingBookings as $b) { ?>
                <div class="row split">
                    <div class="label">
                        <?php echo htmlspecialchars($b["name"]); ?> &middot; <?php echo htmlspecialchars($b["session_type"]); ?>
                        <span class="hint"><?php echo date("D j M", strtotime($b["booking_date"])); ?> &middot; <?php echo htmlspecialchars($b["time_slot"]); ?></span>
                    </div>
                    <form method="POST" action="admin-dashboard.php" class="actions">
                        <input type="hidden" name="id" value="<?php echo $b["id"]; ?>">
                        <button type="submit" name="action" value="completeBooking" class="btn-outline small">Done</button>
                    </form>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <!-- ===== FEEDBACK ===== -->
    <h2 class="section-title">New feedback <span class="muted"><?php echo $newFeedback; ?> unread</span></h2>
    <?php if ($msgSection == "feedback") { ?><p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p><?php } ?>
    <?php if (count($unseenFeedback) == 0) { ?>
        <p class="empty-text">Nothing new.</p>
    <?php } else { ?>
        <?php foreach ($unseenFeedback as $f) { ?>
            <div class="notice <?php echo $f["rating"] != "" && $f["rating"] <= 2 ? "important" : ""; ?>">
                <h3>
                    <?php echo htmlspecialchars($f["subject"]); ?>
                    <?php if ($f["rating"] != "") { ?><span class="pill"><?php echo $f["rating"]; ?>/5</span><?php } ?>
                </h3>
                <p><?php echo nl2br(htmlspecialchars($f["message"])); ?></p>
                <div class="meta">
                    <?php echo htmlspecialchars($f["name"]); ?> &middot; <?php echo htmlspecialchars($f["email"]); ?> &middot; <?php echo date("j M, g:i a", strtotime($f["created_at"])); ?>
                </div>
                <form method="POST" action="admin-dashboard.php" style="margin-top:8px;">
                    <input type="hidden" name="id" value="<?php echo $f["id"]; ?>">
                    <button type="submit" name="action" value="seenFeedback" class="btn-outline small" style="width:auto; padding:6px 12px; font-size:13px; margin:0;">Mark as seen</button>
                </form>
            </div>
        <?php } ?>
    <?php } ?>

    <footer class="site-footer">
        <p>&copy; <?php echo date("Y"); ?> Singh Fitness Gym. Owner area.</p>
        <p><a href="admin-members.php">Members</a> &middot; <a href="admin-plans.php">Plans</a> &middot; <a href="admin-settings.php">Settings</a> &middot; <a href="logout.php">Logout</a></p>
    </footer>

</div>

<div class="scrim"></div>

<div class="dock">
    <nav class="bar" id="bar">
        <div class="bar__glass"></div><div class="bar__sheen"></div>
        <svg class="bar__edge" id="edge" preserveAspectRatio="none">
            <defs><linearGradient id="rimgrad" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0"    stop-color="var(--tabbar-rim-hot)"/>
                <stop offset="0.26" stop-color="var(--tabbar-rim-cool)"/>
                <stop offset="0.49" stop-color="transparent"/>
                <stop offset="0.73" stop-color="var(--tabbar-rim-cool)"/>
                <stop offset="1"    stop-color="var(--tabbar-rim-hot)"/>
            </linearGradient></defs>
            <path id="edgePath" stroke="url(#rimgrad)"/>
        </svg>
        <div class="tabs" id="tabs">
            <div class="lens" id="lens"></div>
            <button class="tab on" data-i="0" data-href="admin-dashboard.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h7v6H4zM13 5h7v4h-7zM13 11h7v8h-7zM4 13h7v6H4z"/></svg><span class="t">Dashboard</span>
            </button>
            <button class="tab" data-i="1" data-href="admin-members.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.2 2.7-5.3 6-5.3s6 2.1 6 5.3"/><circle cx="17" cy="9" r="2.4"/><path d="M15.5 14.4c2.8.2 5 2 5 4.6"/></svg><span class="t">Members</span>
            </button>
            <button class="tab" data-i="2" data-href="admin-plans.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 6.5h11v11h-11z"/><path d="M3 9v6M21 9v6"/></svg><span class="t">Plans</span>
            </button>
            <button class="tab" data-i="3" data-href="admin-settings.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/></svg><span class="t">Settings</span>
            </button>
        </div>
    </nav>
</div>

<script src="assets/js/liquid-tabbar.js?v=<?php echo filemtime("assets/js/liquid-tabbar.js"); ?>"></script>
<script src="assets/js/app.js?v=<?php echo filemtime("assets/js/app.js"); ?>"></script>
<script></script>

</body>
</html>
