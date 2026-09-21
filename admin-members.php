<?php
require_once "config.php";

// Owner only, checked on the server first
if (!isset($_SESSION["memberId"]) || $_SESSION["role"] != "admin") {
    header("Location: login.php");
    exit;
}

// same lists the signup page uses, so the two agree
$plans = [
    "Basic Gym Access [ 1000 rupee/month ]",
    "Full Gym + Cardio [ 1700 rupee/month ]",
    "Premium - Gym + Cardio + Personal Training [ 2500 rupee/month ]"
];
$durations = ["1 Month", "2 Months", "3 Months"];
$shifts = ["Morning Shift", "Evening Shift"];
$statuses = ["Active", "Expiring Soon", "Expired", "Pending Approval"];

$workoutPlans = [];
$result = $conn->query("SELECT id, name FROM workout_plans ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $workoutPlans[] = $row;
}
$dietPlans = [];
$result = $conn->query("SELECT id, name FROM diet_plans ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $dietPlans[] = $row;
}

$errors = [];
$successMsg = "";

// list filters. the address bar keeps them so the list can be refreshed or shared
$q = trim($_GET["q"] ?? "");
$statusFilter = $_GET["status"] ?? "";
if (!in_array($statusFilter, $statuses)) {
    $statusFilter = "";
}
$editId = $_GET["id"] ?? "";      // "" = list, "new" = add form, otherwise a member id

// ---- one query serves the list, the count and the CSV ----
// '%' on its own matches everything, so an empty search or "all statuses"
// still goes through the same prepared statement
$like = "%" . $q . "%";
$statusLike = $statusFilter == "" ? "%" : $statusFilter;
$listSql = "SELECT id, member_code, name, email, phone, status, role, gym_access, membership_duration,
                   preferred_shift, joined_date, expiry_date, points, workout_plan_id, diet_plan_id
            FROM members
            WHERE role != 'admin'
              AND (name LIKE ? OR member_code LIKE ? OR email LIKE ? OR phone LIKE ?)
              AND status LIKE ?
            ORDER BY (role = 'pending') DESC, name";

// ---- CSV export of whatever the list is currently showing ----
if (isset($_GET["export"]) && $_GET["export"] == "csv") {
    $stmt = $conn->prepare($listSql);
    $stmt->bind_param("sssss", $like, $like, $like, $like, $statusLike);
    $stmt->execute();
    $rows = $stmt->get_result();

    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"singh-fitness-members-" . date("Y-m-d") . ".csv\"");
    $out = fopen("php://output", "w");
    fputcsv($out, ["Code", "Name", "Email", "Phone", "Status", "Plan", "Duration", "Shift", "Joined", "Expires", "Points", "Streak"]);
    while ($m = $rows->fetch_assoc()) {
        fputcsv($out, [
            $m["member_code"], $m["name"], $m["email"], $m["phone"], $m["status"], $m["gym_access"],
            $m["membership_duration"], $m["preferred_shift"], $m["joined_date"], $m["expiry_date"],
            $m["points"], getStreak($conn, $m["id"])
        ]);
    }
    fclose($out);
    $stmt->close();
    exit;
}

// ---- delete a member ----
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["deleteMember"])) {
    $targetId = $_POST["deleteMember"];
    // never the admin's own account
    $stmt = $conn->prepare("DELETE FROM members WHERE id = ? AND role != 'admin'");
    $stmt->bind_param("s", $targetId);
    $stmt->execute();
    $stmt->close();
    header("Location: admin-members.php?msg=deleted");
    exit;
}

// ---- save (add or edit) ----
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["saveMember"])) {
    $targetId = $_POST["memberId"];       // "new" or an existing id
    $isNew = $targetId == "new";

    $fullName = trim($_POST["fullName"]);
    $email = trim($_POST["email"]);
    $phone = trim($_POST["phone"]);
    $address = trim($_POST["address"]);
    $shift = $_POST["shift"] ?? "";
    $gymAccess = $_POST["gymAccess"] ?? "";
    $duration = $_POST["duration"] ?? "";
    $status = $_POST["status"] ?? "";
    $joinedDate = trim($_POST["joinedDate"]);
    $expiryDate = trim($_POST["expiryDate"]);
    $workoutPlanId = $_POST["workoutPlanId"] ?? "";
    $dietPlanId = $_POST["dietPlanId"] ?? "";
    $points = trim($_POST["points"]);
    $trainerName = trim($_POST["trainerName"]);
    $trainerPhone = trim($_POST["trainerPhone"]);
    $trainerNote = trim($_POST["trainerNote"]);
    $newPassword = $_POST["newPassword"];

    if ($fullName == "") {
        $errors["fullName"] = "Name is needed.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors["email"] = "Enter a valid email.";
    } else {
        // no two members with the same email (ignore this member's own row)
        $stmt = $conn->prepare("SELECT id FROM members WHERE email = ? AND id != ?");
        $stmt->bind_param("ss", $email, $targetId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors["email"] = "Another member already uses that email.";
        }
        $stmt->close();
    }
    if (!preg_match("/^[0-9]{8,15}$/", $phone)) {
        $errors["phone"] = "Phone should be 8 to 15 digits.";
    }
    if (!in_array($shift, $shifts)) {
        $errors["shift"] = "Pick a shift.";
    }
    if (!in_array($gymAccess, $plans)) {
        $errors["gymAccess"] = "Pick a membership plan.";
    }
    if (!in_array($duration, $durations)) {
        $errors["duration"] = "Pick a duration.";
    }
    if (!in_array($status, $statuses)) {
        $errors["status"] = "Pick a status.";
    }
    if ($joinedDate == "" || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $joinedDate)) {
        $errors["joinedDate"] = "Enter the joined date.";
    }
    if ($expiryDate != "" && !preg_match("/^\d{4}-\d{2}-\d{2}$/", $expiryDate)) {
        $errors["expiryDate"] = "Expiry date doesn't look right.";
    }
    if ($workoutPlanId != "" && !in_array($workoutPlanId, array_column($workoutPlans, "id"))) {
        $errors["workoutPlanId"] = "Unknown workout plan.";
    }
    if ($dietPlanId != "" && !in_array($dietPlanId, array_column($dietPlans, "id"))) {
        $errors["dietPlanId"] = "Unknown diet plan.";
    }
    if ($points == "" || !ctype_digit($points)) {
        $errors["points"] = "Points must be a whole number.";
    }
    if ($trainerPhone != "" && !preg_match("/^[0-9]{8,15}$/", $trainerPhone)) {
        $errors["trainerPhone"] = "Trainer phone should be 8 to 15 digits.";
    }
    if ($newPassword != "" && strlen($newPassword) < 8) {
        $errors["newPassword"] = "Password must be at least 8 characters.";
    }

    if (count($errors) == 0) {
        $expiryVal = $expiryDate == "" ? null : $expiryDate;
        $workoutVal = $workoutPlanId == "" ? null : $workoutPlanId;
        $dietVal = $dietPlanId == "" ? null : $dietPlanId;
        // pending signups become members once the owner saves them as anything but pending
        $role = $status == "Pending Approval" ? "pending" : "member";

        if ($isNew) {
            // next membership number, same way the signup page does it
            $totalRow = $conn->query("SELECT COUNT(*) AS total FROM members")->fetch_assoc();
            $nextNumber = 1000 + (int)$totalRow["total"] + 1;
            do {
                $memberCode = "SFG-" . $nextNumber;
                $stmt = $conn->prepare("SELECT id FROM members WHERE member_code = ?");
                $stmt->bind_param("s", $memberCode);
                $stmt->execute();
                $codeTaken = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                $nextNumber++;
            } while ($codeTaken);

            $newId = "mem_" . uniqid();
            $passwordHash = $newPassword != "" ? password_hash($newPassword, PASSWORD_DEFAULT) : null;

            $stmt = $conn->prepare("INSERT INTO members
                (id, member_code, name, email, password_hash, phone, address, preferred_shift, gym_access,
                 membership_duration, status, role, joined_date, expiry_date, workout_plan_id, diet_plan_id,
                 points, trainer_name, trainer_phone, trainer_note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssssssssssssisss",
                $newId, $memberCode, $fullName, $email, $passwordHash, $phone, $address, $shift, $gymAccess,
                $duration, $status, $role, $joinedDate, $expiryVal, $workoutVal, $dietVal,
                $points, $trainerName, $trainerPhone, $trainerNote);
            $stmt->execute();
            $stmt->close();

            header("Location: admin-members.php?id=" . urlencode($newId) . "&msg=added");
            exit;
        } else {
            $stmt = $conn->prepare("UPDATE members SET name = ?, email = ?, phone = ?, address = ?, preferred_shift = ?,
                gym_access = ?, membership_duration = ?, status = ?, role = ?, joined_date = ?, expiry_date = ?,
                workout_plan_id = ?, diet_plan_id = ?, points = ?, trainer_name = ?, trainer_phone = ?, trainer_note = ?
                WHERE id = ? AND role != 'admin'");
            $stmt->bind_param("sssssssssssssissss",
                $fullName, $email, $phone, $address, $shift, $gymAccess, $duration, $status, $role,
                $joinedDate, $expiryVal, $workoutVal, $dietVal, $points, $trainerName, $trainerPhone, $trainerNote,
                $targetId);
            $stmt->execute();
            $stmt->close();

            if ($newPassword != "") {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE members SET password_hash = ? WHERE id = ?");
                $stmt->bind_param("ss", $passwordHash, $targetId);
                $stmt->execute();
                $stmt->close();
            }

            header("Location: admin-members.php?id=" . urlencode($targetId) . "&msg=saved");
            exit;
        }
    }

    // fell through with errors: stay on the form showing what they typed
    $editId = $targetId;
    $member = [
        "id" => $targetId, "member_code" => $isNew ? "(new)" : ($_POST["memberCode"] ?? ""),
        "name" => $fullName, "email" => $email, "phone" => $phone, "address" => $address,
        "preferred_shift" => $shift, "gym_access" => $gymAccess, "membership_duration" => $duration,
        "status" => $status, "joined_date" => $joinedDate, "expiry_date" => $expiryDate,
        "workout_plan_id" => $workoutPlanId, "diet_plan_id" => $dietPlanId, "points" => $points,
        "trainer_name" => $trainerName, "trainer_phone" => $trainerPhone, "trainer_note" => $trainerNote,
        "password_hash" => ""
    ];
}

if (isset($_GET["msg"])) {
    $messages = ["saved" => "Member saved.", "added" => "Member added.", "deleted" => "Member deleted."];
    $successMsg = $messages[$_GET["msg"]] ?? "";
}

// ---- load the member being edited (unless the form already filled $member) ----
if ($editId != "" && !isset($member)) {
    if ($editId == "new") {
        $member = [
            "id" => "new", "member_code" => "(new)", "name" => "", "email" => "", "phone" => "", "address" => "",
            "preferred_shift" => "Morning Shift", "gym_access" => $plans[0], "membership_duration" => "1 Month",
            "status" => "Active", "joined_date" => date("Y-m-d"), "expiry_date" => date("Y-m-d", strtotime("+1 month")),
            "workout_plan_id" => "", "diet_plan_id" => "", "points" => "0",
            "trainer_name" => "", "trainer_phone" => "", "trainer_note" => "", "password_hash" => ""
        ];
    } else {
        $stmt = $conn->prepare("SELECT * FROM members WHERE id = ? AND role != 'admin'");
        $stmt->bind_param("s", $editId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$member) {
            header("Location: admin-members.php");
            exit;
        }
    }
}

// recent check-ins for the edit screen
$recentVisits = [];
if ($editId != "" && $editId != "new") {
    $stmt = $conn->prepare("SELECT attended_on FROM member_attendance WHERE member_id = ? ORDER BY attended_on DESC LIMIT 5");
    $stmt->bind_param("s", $editId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recentVisits[] = $row["attended_on"];
    }
    $stmt->close();
}

// ---- the list ----
$members = [];
if ($editId == "") {
    $stmt = $conn->prepare($listSql);
    $stmt->bind_param("sssss", $like, $like, $like, $like, $statusLike);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();
}

function pillClass($status) {
    if ($status == "Active") { return "good"; }
    if ($status == "Expiring Soon") { return "pending"; }
    if ($status == "Expired") { return "bad"; }
    return "";
}
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Members - Singh Fitness Admin</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?php echo filemtime("assets/css/base.css"); ?>">
<link rel="stylesheet" href="assets/css/shared.css?v=<?php echo filemtime("assets/css/shared.css"); ?>">
</head>
<body class="has-tabbar <?php echo $editId == "" ? "has-searchbar" : ""; ?>">

<header class="topbar">
    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Singh Fitness logo" width="140" height="42">
    <div class="who">
        <?php echo htmlspecialchars($_SESSION["memberName"]); ?><br>
        Owner
    </div>
</header>

<div class="page">

<?php if ($editId == "") { ?>

    <!-- ================= LIST ================= -->
    <h1 class="title">Members</h1>
    <p class="sub"><?php echo count($members); ?> shown</p>

    <?php if ($successMsg != "") { ?>
        <p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php } ?>

    <?php if ($q != "") { ?>
        <p class="sub" style="margin-top:-14px;">Results for "<?php echo htmlspecialchars($q); ?>" &middot; <a href="admin-members.php?status=<?php echo urlencode($statusFilter); ?>">clear</a></p>
    <?php } ?>

    <?php
    // one capsule per filter. filled icons like the iPhone ones. the lines
    // "cut out" of a filled shape are drawn in the button's own colour
    $filterIcons = [
        "" => '<path fill="currentColor" d="M3 5.5A2.5 2.5 0 015.5 3h13A2.5 2.5 0 0121 5.5V12h-4.2a1 1 0 00-.9.55l-.7 1.4a1 1 0 01-.9.55h-4.6a1 1 0 01-.9-.55l-.7-1.4a1 1 0 00-.9-.55H3V5.5zM3 14h3.6l.7 1.4a2.5 2.5 0 002.2 1.35h5a2.5 2.5 0 002.2-1.35l.7-1.4H21v4.5a2.5 2.5 0 01-2.5 2.5h-13A2.5 2.5 0 013 18.5V14z"/>',
        "Active" => '<circle cx="12" cy="12" r="10" fill="currentColor"/><path d="M7.5 12.5l3 3 6-7" fill="none" stroke="var(--icon-bg)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
        "Expiring Soon" => '<circle cx="12" cy="12" r="10" fill="currentColor"/><path d="M12 6.5V12l3.5 2.2" fill="none" stroke="var(--icon-bg)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
        "Expired" => '<circle cx="12" cy="12" r="10" fill="currentColor"/><path d="M8.5 8.5l7 7M15.5 8.5l-7 7" fill="none" stroke="var(--icon-bg)" stroke-width="2.4" stroke-linecap="round"/>',
        "Pending Approval" => '<circle cx="10" cy="7.5" r="4" fill="currentColor"/><path fill="currentColor" d="M2.5 19.5c0-4 3.4-6.5 7.5-6.5 1.6 0 3 .3 4.2.9a5.5 5.5 0 00.3 5.6H3.5a1 1 0 01-1-1z"/><circle cx="18" cy="17" r="4.2" fill="currentColor"/><path d="M18 14.8v4.4M15.8 17h4.4" fill="none" stroke="var(--icon-bg)" stroke-width="1.8" stroke-linecap="round"/>'
    ];
    $filterNames = ["" => "All", "Active" => "Active", "Expiring Soon" => "Expiring", "Expired" => "Expired", "Pending Approval" => "Pending"];
    ?>
    <div class="filters">
        <?php foreach ($filterNames as $value => $label) { ?>
            <a href="admin-members.php?q=<?php echo urlencode($q); ?>&status=<?php echo urlencode($value); ?>"
               class="<?php echo $statusFilter == $value ? "on" : ""; ?>" title="<?php echo $label; ?>" aria-label="<?php echo $label; ?>">
                <svg viewBox="0 0 24 24"><?php echo $filterIcons[$value]; ?></svg>
                <span class="t"><?php echo $label; ?></span>
            </a>
        <?php } ?>
    </div>

    <div class="two-col">
        <a href="admin-members.php?id=new" class="btn frost" data-press>Add member</a>
        <a href="admin-members.php?export=csv&q=<?php echo urlencode($q); ?>&status=<?php echo urlencode($statusFilter); ?>" class="btn-outline" style="text-align:center; text-decoration:none;">Export CSV</a>
    </div>

    <?php if (count($members) == 0) { ?>
        <p class="empty-text" style="margin-top:16px;">No members match.</p>
    <?php } else { ?>
        <div class="group plain" data-sq="22" style="margin-top:16px;">
            <?php foreach ($members as $m) { ?>
                <a href="admin-members.php?id=<?php echo urlencode($m["id"]); ?>" class="row link" data-tap>
                    <div class="label">
                        <?php echo htmlspecialchars($m["name"]); ?>
                        <span class="pill <?php echo pillClass($m["status"]); ?>"><?php echo htmlspecialchars($m["status"]); ?></span>
                        <span class="hint">
                            <?php echo htmlspecialchars($m["member_code"]); ?> &middot; <?php echo htmlspecialchars($m["phone"]); ?>
                            <?php if ($m["expiry_date"] != "") { ?>&middot; expires <?php echo date("j M Y", strtotime($m["expiry_date"])); ?><?php } ?>
                            &middot; <?php echo (int)$m["points"]; ?> pts
                        </span>
                    </div>
                    <div class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div>
                </a>
            <?php } ?>
        </div>
    <?php } ?>

<?php } else { ?>

    <!-- ================= ADD / EDIT ================= -->
    <a href="admin-members.php" class="back-link">&lsaquo; All members</a>
    <h1 class="title"><?php echo $editId == "new" ? "Add member" : htmlspecialchars($member["name"]); ?></h1>
    <p class="sub">
        <?php echo htmlspecialchars($member["member_code"]); ?>
        <?php if ($editId != "new") { ?>
            &middot; <?php echo getStreak($conn, $editId); ?> day streak
            <?php if ($member["password_hash"] == "") { ?>&middot; <strong>no password set yet</strong><?php } ?>
        <?php } ?>
    </p>

    <?php if ($successMsg != "") { ?>
        <p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p>
    <?php } ?>

    <form method="POST" action="admin-members.php" id="memberForm" novalidate>
        <input type="hidden" name="memberId" value="<?php echo htmlspecialchars($member["id"]); ?>">
        <input type="hidden" name="memberCode" value="<?php echo htmlspecialchars($member["member_code"]); ?>">

        <h2 class="section-title">Details</h2>
        <div class="group plain" data-sq="22">
            <div class="row">
                <span class="field-label">Full name</span>
                <input type="text" name="fullName" id="fullName" value="<?php echo htmlspecialchars($member["name"]); ?>">
                <?php if (isset($errors["fullName"])) { ?><span class="field-error"><?php echo $errors["fullName"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Email (their login)</span>
                <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($member["email"]); ?>">
                <?php if (isset($errors["email"])) { ?><span class="field-error"><?php echo $errors["email"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Phone</span>
                <input type="tel" name="phone" id="phone" value="<?php echo htmlspecialchars($member["phone"]); ?>">
                <?php if (isset($errors["phone"])) { ?><span class="field-error"><?php echo $errors["phone"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Address</span>
                <input type="text" name="address" id="address" value="<?php echo htmlspecialchars($member["address"]); ?>">
            </div>
            <div class="row">
                <span class="field-label">Shift</span>
                <select name="shift" id="shift">
                    <?php foreach ($shifts as $s) { ?>
                        <option value="<?php echo $s; ?>" <?php echo $member["preferred_shift"] == $s ? "selected" : ""; ?>><?php echo $s; ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="row">
                <span class="field-label"><?php echo $editId == "new" ? "Password (optional, they can use Forgot password later)" : "Set a new password (leave blank to keep it)"; ?></span>
                <input type="password" name="newPassword" id="newPassword" placeholder="Min 8 characters" autocomplete="new-password">
                <?php if (isset($errors["newPassword"])) { ?><span class="field-error"><?php echo $errors["newPassword"]; ?></span><?php } ?>
            </div>
        </div>

        <h2 class="section-title">Membership</h2>
        <div class="group plain" data-sq="22">
            <div class="row">
                <span class="field-label">Plan</span>
                <select name="gymAccess" id="gymAccess">
                    <?php foreach ($plans as $p) { ?>
                        <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $member["gym_access"] == $p ? "selected" : ""; ?>><?php echo htmlspecialchars($p); ?></option>
                    <?php } ?>
                </select>
                <?php if (isset($errors["gymAccess"])) { ?><span class="field-error"><?php echo $errors["gymAccess"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Duration</span>
                <select name="duration" id="duration">
                    <?php foreach ($durations as $d) { ?>
                        <option value="<?php echo $d; ?>" <?php echo $member["membership_duration"] == $d ? "selected" : ""; ?>><?php echo $d; ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="row">
                <span class="field-label">Status</span>
                <select name="status" id="status">
                    <?php foreach ($statuses as $s) { ?>
                        <option value="<?php echo $s; ?>" <?php echo $member["status"] == $s ? "selected" : ""; ?>><?php echo $s; ?></option>
                    <?php } ?>
                </select>
                <span class="hint">Pending Approval means they can't log in yet.</span>
            </div>
            <div class="row">
                <span class="field-label">Joined</span>
                <input type="date" name="joinedDate" id="joinedDate" value="<?php echo htmlspecialchars($member["joined_date"]); ?>">
                <?php if (isset($errors["joinedDate"])) { ?><span class="field-error"><?php echo $errors["joinedDate"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Expires</span>
                <input type="date" name="expiryDate" id="expiryDate" value="<?php echo htmlspecialchars($member["expiry_date"]); ?>">
                <?php if (isset($errors["expiryDate"])) { ?><span class="field-error"><?php echo $errors["expiryDate"]; ?></span><?php } ?>
                <span class="hint">Renewing? Move this date forward and set status to Active.</span>
            </div>
            <div class="row">
                <span class="field-label">Points</span>
                <input type="number" name="points" id="points" min="0" step="1" value="<?php echo htmlspecialchars($member["points"]); ?>">
                <?php if (isset($errors["points"])) { ?><span class="field-error"><?php echo $errors["points"]; ?></span><?php } ?>
            </div>
        </div>

        <h2 class="section-title">Plans and trainer</h2>
        <div class="group plain" data-sq="22">
            <div class="row">
                <span class="field-label">Workout plan</span>
                <select name="workoutPlanId" id="workoutPlanId">
                    <option value="">Not assigned</option>
                    <?php foreach ($workoutPlans as $wp) { ?>
                        <option value="<?php echo htmlspecialchars($wp["id"]); ?>" <?php echo $member["workout_plan_id"] == $wp["id"] ? "selected" : ""; ?>><?php echo htmlspecialchars($wp["name"]); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="row">
                <span class="field-label">Diet plan</span>
                <select name="dietPlanId" id="dietPlanId">
                    <option value="">Not assigned</option>
                    <?php foreach ($dietPlans as $dp) { ?>
                        <option value="<?php echo htmlspecialchars($dp["id"]); ?>" <?php echo $member["diet_plan_id"] == $dp["id"] ? "selected" : ""; ?>><?php echo htmlspecialchars($dp["name"]); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="row">
                <span class="field-label">Trainer name</span>
                <input type="text" name="trainerName" id="trainerName" value="<?php echo htmlspecialchars($member["trainer_name"]); ?>" placeholder="Optional">
            </div>
            <div class="row">
                <span class="field-label">Trainer phone</span>
                <input type="tel" name="trainerPhone" id="trainerPhone" value="<?php echo htmlspecialchars($member["trainer_phone"]); ?>" placeholder="Optional">
                <?php if (isset($errors["trainerPhone"])) { ?><span class="field-error"><?php echo $errors["trainerPhone"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Note from trainer (the member sees this)</span>
                <textarea name="trainerNote" id="trainerNote"><?php echo htmlspecialchars($member["trainer_note"]); ?></textarea>
            </div>
        </div>

        <button type="submit" name="saveMember" value="1" class="btn frost" data-press><?php echo $editId == "new" ? "Add member" : "Save changes"; ?></button>
    </form>

    <?php if ($editId != "new") { ?>
        <?php if (count($recentVisits) > 0) { ?>
            <h2 class="section-title">Recent check-ins</h2>
            <div class="group plain" data-sq="22">
                <?php foreach ($recentVisits as $v) { ?>
                    <div class="row"><?php echo date("l j M Y", strtotime($v)); ?></div>
                <?php } ?>
            </div>
        <?php } ?>

        <form method="POST" action="admin-members.php" id="deleteForm" style="margin-top:24px;">
            <button type="submit" name="deleteMember" value="<?php echo htmlspecialchars($member["id"]); ?>" class="btn-outline confirm-btn" style="color:var(--red); border-color:var(--red);">Delete this member</button>
        </form>
        <p class="hint" style="text-align:center;">Removes their check-ins, weights and bookings too. Can't be undone.</p>
    <?php } ?>

<?php } ?>

    <footer class="site-footer">
        <p>&copy; <?php echo date("Y"); ?> Singh Fitness Gym. Owner area.</p>
        <p><a href="admin-dashboard.php">Dashboard</a> &middot; <a href="admin-plans.php">Plans</a> &middot; <a href="admin-settings.php">Settings</a> &middot; <a href="logout.php">Logout</a></p>
    </footer>

</div>

<div class="scrim"></div>

<?php if ($editId == "") { ?>
<!-- floating search, sits just above the tab bar -->
<div class="search-dock">
    <form method="GET" action="admin-members.php" class="search-bar" id="searchForm" novalidate>
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
        <input type="search" name="q" id="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search members" autocomplete="off">
        <button type="submit" class="btn frost small" data-press>Search</button>
    </form>
</div>
<?php } ?>

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
            <button class="tab" data-i="0" data-href="admin-dashboard.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h7v6H4zM13 5h7v4h-7zM13 11h7v8h-7zM4 13h7v6H4z"/></svg><span class="t">Dashboard</span>
            </button>
            <button class="tab on" data-i="1" data-href="admin-members.php">
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
<script>
function clearErrors(form) {
    var errorSpans = form.querySelectorAll(".field-error.js-error");
    for (var i = 0; i < errorSpans.length; i++) {
        errorSpans[i].remove();
    }
}

function showError(inputEl, message) {
    var span = document.createElement("span");
    span.className = "field-error js-error";
    span.textContent = message;
    inputEl.parentNode.insertBefore(span, inputEl.nextSibling);
}

var memberForm = document.getElementById("memberForm");
if (memberForm) {
    memberForm.addEventListener("submit", function (e) {
        clearErrors(memberForm);
        var ok = true;

        var fullName = document.getElementById("fullName");
        if (fullName.value.trim() == "") {
            showError(fullName, "Name is needed.");
            ok = false;
        }

        var email = document.getElementById("email");
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
            showError(email, "Enter a valid email.");
            ok = false;
        }

        var phone = document.getElementById("phone");
        if (!/^[0-9]{8,15}$/.test(phone.value.trim())) {
            showError(phone, "Phone should be 8 to 15 digits.");
            ok = false;
        }

        var joinedDate = document.getElementById("joinedDate");
        if (joinedDate.value == "") {
            showError(joinedDate, "Enter the joined date.");
            ok = false;
        }

        var expiryDate = document.getElementById("expiryDate");
        if (expiryDate.value != "" && joinedDate.value != "" && expiryDate.value < joinedDate.value) {
            showError(expiryDate, "Expiry can't be before the joined date.");
            ok = false;
        }

        var points = document.getElementById("points");
        if (points.value.trim() == "" || !/^[0-9]+$/.test(points.value.trim())) {
            showError(points, "Points must be a whole number.");
            ok = false;
        }

        var trainerPhone = document.getElementById("trainerPhone");
        if (trainerPhone.value.trim() != "" && !/^[0-9]{8,15}$/.test(trainerPhone.value.trim())) {
            showError(trainerPhone, "Trainer phone should be 8 to 15 digits.");
            ok = false;
        }

        var newPassword = document.getElementById("newPassword");
        if (newPassword.value != "" && newPassword.value.length < 8) {
            showError(newPassword, "Password must be at least 8 characters.");
            ok = false;
        }

        if (!ok) {
            e.preventDefault();
        }
    });
}

var searchForm = document.getElementById("searchForm");
if (searchForm) {
    searchForm.addEventListener("submit", function (e) {
        // an empty search just shows everyone, that's fine, but trim it
        var q = document.getElementById("q");
        q.value = q.value.trim();
    });
}
</script>

</body>
</html>
