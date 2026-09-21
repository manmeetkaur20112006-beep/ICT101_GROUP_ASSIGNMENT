<?php
require_once "config.php";

if (!isset($_SESSION["memberId"])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION["role"] == "admin") {
    header("Location: admin-dashboard.php");
    exit;
}
if ($_SESSION["role"] != "member") {
    header("Location: logout.php");
    exit;
}

$memberId = $_SESSION["memberId"];
$errors = [];
$successMsg = "";
$shifts = ["Morning Shift", "Evening Shift"];

// ---- dark / light switch ----
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["theme"])) {
    $theme = $_POST["theme"] == "light" ? "light" : "dark";
    // remembered for a year in the browser, every page reads it (see config.php)
    setcookie("theme", $theme, time() + 365 * 86400, "/");
    header("Location: profile.php");
    exit;
}

// ---- update details ----
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["saveDetails"])) {
    $fullName = trim($_POST["fullName"]);
    $phone = trim($_POST["phone"]);
    $address = trim($_POST["address"]);
    $height = trim($_POST["height"]);
    $weight = trim($_POST["weight"]);
    $targetWeight = trim($_POST["targetWeight"]);
    $goals = trim($_POST["goals"]);
    $shift = $_POST["shift"] ?? "";

    if ($fullName == "") {
        $errors["fullName"] = "Please enter your name.";
    }
    if (!preg_match("/^[0-9]{8,15}$/", $phone)) {
        $errors["phone"] = "Phone number should be 8 to 15 digits.";
    }
    if ($height != "" && (!is_numeric($height) || $height < 100 || $height > 250)) {
        $errors["height"] = "Height should be between 100 and 250 cm.";
    }
    if ($weight != "" && (!is_numeric($weight) || $weight < 20 || $weight > 300)) {
        $errors["weight"] = "Weight should be between 20 and 300 kg.";
    }
    if ($targetWeight != "" && (!is_numeric($targetWeight) || $targetWeight < 20 || $targetWeight > 300)) {
        $errors["targetWeight"] = "Target weight should be between 20 and 300 kg.";
    }
    if (!in_array($shift, $shifts)) {
        $errors["shift"] = "Please choose a shift.";
    }

    if (count($errors) == 0) {
        // empty boxes are saved as NULL, not as 0
        $heightVal = $height == "" ? null : $height;
        $weightVal = $weight == "" ? null : $weight;
        $targetVal = $targetWeight == "" ? null : $targetWeight;

        $stmt = $conn->prepare("UPDATE members SET name = ?, phone = ?, address = ?, height = ?, weight = ?,
                                target_weight = ?, goals = ?, preferred_shift = ? WHERE id = ?");
        $stmt->bind_param("sssdddsss", $fullName, $phone, $address, $heightVal, $weightVal, $targetVal, $goals, $shift, $memberId);
        $stmt->execute();
        $stmt->close();

        // the header shows the name from the session, so keep that fresh too
        $_SESSION["memberName"] = $fullName;

        header("Location: profile.php?saved=details");
        exit;
    }
}

// ---- change password ----
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["savePassword"])) {
    $currentPassword = $_POST["currentPassword"];
    $newPassword = $_POST["newPassword"];
    $confirmPassword = $_POST["confirmPassword"];

    $stmt = $conn->prepare("SELECT password_hash FROM members WHERE id = ?");
    $stmt->bind_param("s", $memberId);
    $stmt->execute();
    $hashRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!password_verify($currentPassword, $hashRow["password_hash"])) {
        $errors["currentPassword"] = "That isn't your current password.";
    }
    if (strlen($newPassword) < 8) {
        $errors["newPassword"] = "New password must be at least 8 characters.";
    }
    if ($newPassword !== $confirmPassword) {
        $errors["confirmPassword"] = "Passwords do not match.";
    }

    if (count($errors) == 0) {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE members SET password_hash = ? WHERE id = ?");
        $stmt->bind_param("ss", $newHash, $memberId);
        $stmt->execute();
        $stmt->close();

        header("Location: profile.php?saved=password");
        exit;
    }
}

// which form just saved, so the message shows next to that form
$detailsSaved = isset($_GET["saved"]) && $_GET["saved"] == "details";
$passwordSaved = isset($_GET["saved"]) && $_GET["saved"] == "password";

$stmt = $conn->prepare("SELECT name, member_code, email, phone, address, height, weight, target_weight, goals,
                               preferred_shift, gym_access, membership_duration, status, joined_date, expiry_date,
                               points, trainer_name, trainer_phone, trainer_note, workout_plan_id, diet_plan_id
                        FROM members WHERE id = ?");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

// if the form was sent back with errors, show what they typed, not the saved values
if (isset($_POST["saveDetails"]) && count($errors) > 0) {
    $member["name"] = $_POST["fullName"];
    $member["phone"] = $_POST["phone"];
    $member["address"] = $_POST["address"];
    $member["height"] = $_POST["height"];
    $member["weight"] = $_POST["weight"];
    $member["target_weight"] = $_POST["targetWeight"];
    $member["goals"] = $_POST["goals"];
    $member["preferred_shift"] = $_POST["shift"];
}

$daysLeft = null;
if ($member["expiry_date"] != "") {
    $daysLeft = floor((strtotime($member["expiry_date"]) - strtotime(date("Y-m-d"))) / 86400);
}

$statusClass = "";
if ($member["status"] == "Expiring Soon") {
    $statusClass = "warn";
} elseif ($member["status"] == "Expired" || $member["status"] == "Pending Approval") {
    $statusClass = "bad";
}

// plan names for the membership box
$workoutPlanName = "Not assigned";
$dietPlanName = "Not assigned";
if ($member["workout_plan_id"] != "") {
    $stmt = $conn->prepare("SELECT name FROM workout_plans WHERE id = ?");
    $stmt->bind_param("s", $member["workout_plan_id"]);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $workoutPlanName = $row["name"];
    }
}
if ($member["diet_plan_id"] != "") {
    $stmt = $conn->prepare("SELECT name FROM diet_plans WHERE id = ?");
    $stmt->bind_param("s", $member["diet_plan_id"]);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $dietPlanName = $row["name"];
    }
}

$streak = getStreak($conn, $memberId);

// the pass QR holds the membership number. drawn by a free online QR service,
// so it needs the internet. the number is printed next to it as a backup
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=0&data=" . urlencode($member["member_code"]);

$currentTheme = (isset($_COOKIE["theme"]) && $_COOKIE["theme"] == "light") ? "light" : "dark";
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Profile - Singh Fitness</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?php echo filemtime("assets/css/base.css"); ?>">
<link rel="stylesheet" href="assets/css/shared.css?v=<?php echo filemtime("assets/css/shared.css"); ?>">
</head>
<body class="has-tabbar">

<header class="topbar">
    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Singh Fitness logo" width="140" height="42">
    <div class="who">
        <?php echo htmlspecialchars($member["name"]); ?><br>
        <?php echo htmlspecialchars($member["member_code"]); ?>
    </div>
</header>

<div class="page">

    <h1 class="title">Profile</h1>
    <p class="sub">Your pass, your details and settings</p>

    <!-- ===== PASS ===== -->
    <div class="pass" data-sq="22">
        <div class="qr">
            <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="QR code for <?php echo htmlspecialchars($member["member_code"]); ?>" width="200" height="200">
        </div>
        <div>
            <div class="code"><?php echo htmlspecialchars($member["member_code"]); ?></div>
            <div class="name"><?php echo htmlspecialchars($member["name"]); ?></div>
            <span class="status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($member["status"]); ?></span>
        </div>
    </div>

    <!-- ===== MEMBERSHIP ===== -->
    <h2 class="section-title">Membership</h2>
    <div class="group plain" data-sq="22">
        <div class="row kv"><span>Plan</span><span class="val"><?php echo htmlspecialchars($member["gym_access"]); ?></span></div>
        <div class="row kv"><span>Duration</span><span class="val"><?php echo htmlspecialchars($member["membership_duration"]); ?></span></div>
        <div class="row kv"><span>Joined</span><span class="val"><?php echo $member["joined_date"] != "" ? date("j M Y", strtotime($member["joined_date"])) : "-"; ?></span></div>
        <div class="row kv">
            <span>Expires</span>
            <span class="val">
                <?php if ($member["expiry_date"] != "") { ?>
                    <?php echo date("j M Y", strtotime($member["expiry_date"])); ?>
                    <?php echo $daysLeft >= 0 ? "(" . $daysLeft . " days left)" : "(expired)"; ?>
                <?php } else { ?>-<?php } ?>
            </span>
        </div>
        <div class="row kv"><span>Shift</span><span class="val"><?php echo htmlspecialchars($member["preferred_shift"]); ?></span></div>
        <div class="row kv"><span>Workout plan</span><span class="val"><?php echo htmlspecialchars($workoutPlanName); ?></span></div>
        <div class="row kv"><span>Diet plan</span><span class="val"><?php echo htmlspecialchars($dietPlanName); ?></span></div>
        <div class="row kv"><span>Points</span><span class="val gold"><?php echo (int)$member["points"]; ?></span></div>
        <div class="row kv"><span>Streak</span><span class="val gold"><?php echo $streak; ?> days</span></div>
    </div>

    <?php if ($member["trainer_name"] != "") { ?>
        <h2 class="section-title">Your trainer</h2>
        <div class="group plain" data-sq="22">
            <div class="row kv"><span>Name</span><span class="val"><?php echo htmlspecialchars($member["trainer_name"]); ?></span></div>
            <?php if ($member["trainer_phone"] != "") { ?>
                <div class="row kv"><span>Phone</span><span class="val"><a href="tel:<?php echo htmlspecialchars($member["trainer_phone"]); ?>"><?php echo htmlspecialchars($member["trainer_phone"]); ?></a></span></div>
            <?php } ?>
            <?php if ($member["trainer_note"] != "") { ?>
                <div class="row"><?php echo nl2br(htmlspecialchars($member["trainer_note"])); ?></div>
            <?php } ?>
        </div>
    <?php } ?>

    <!-- ===== MORE ===== -->
    <h2 class="section-title">More</h2>
    <div class="group plain" data-sq="22">
        <a href="bookings.php" class="row link" data-tap>
            <div class="label">Book a session<span class="hint">Trainer, diet consult or body measurement</span></div>
            <div class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div>
        </a>
        <a href="feedback.php" class="row link" data-tap>
            <div class="label">Feedback and suggestions</div>
            <div class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div>
        </a>
        <a href="search.php" class="row link" data-tap>
            <div class="label">Search<span class="hint">Find exercises, plans and members</span></div>
            <div class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div>
        </a>
    </div>

    <!-- ===== APPEARANCE ===== -->
    <h2 class="section-title">Appearance</h2>
    <form method="POST" action="profile.php">
        <div class="seg">
            <button type="submit" name="theme" value="dark" class="<?php echo $currentTheme == "dark" ? "on" : ""; ?>">Dark</button>
            <button type="submit" name="theme" value="light" class="<?php echo $currentTheme == "light" ? "on" : ""; ?>">Light</button>
        </div>
    </form>

    <!-- ===== DETAILS ===== -->
    <h2 class="section-title">Your details</h2>
    <?php if ($detailsSaved) { ?>
        <p class="success-text">Details saved.</p>
    <?php } ?>
    <form method="POST" action="profile.php" id="detailsForm" novalidate>
        <div class="group plain" data-sq="22">
            <div class="row">
                <span class="field-label">Full name</span>
                <input type="text" name="fullName" id="fullName" value="<?php echo htmlspecialchars($member["name"]); ?>">
                <?php if (isset($errors["fullName"])) { ?><span class="field-error"><?php echo $errors["fullName"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Email (used to log in, cannot be changed here)</span>
                <input type="email" value="<?php echo htmlspecialchars($member["email"]); ?>" disabled>
            </div>
            <div class="row">
                <span class="field-label">Phone</span>
                <input type="tel" name="phone" id="phone" value="<?php echo htmlspecialchars($member["phone"]); ?>">
                <?php if (isset($errors["phone"])) { ?><span class="field-error"><?php echo $errors["phone"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Address</span>
                <input type="text" name="address" id="address" value="<?php echo htmlspecialchars($member["address"]); ?>" placeholder="Optional">
            </div>
            <div class="row">
                <span class="field-label">Preferred shift</span>
                <select name="shift" id="shift">
                    <?php foreach ($shifts as $s) { ?>
                        <option value="<?php echo $s; ?>" <?php echo $member["preferred_shift"] == $s ? "selected" : ""; ?>><?php echo $s; ?></option>
                    <?php } ?>
                </select>
                <?php if (isset($errors["shift"])) { ?><span class="field-error"><?php echo $errors["shift"]; ?></span><?php } ?>
            </div>
        </div>

        <div class="group plain" data-sq="22">
            <div class="row">
                <span class="field-label">Height (cm)</span>
                <input type="number" name="height" id="height" step="0.1" value="<?php echo $member["height"] > 0 ? htmlspecialchars($member["height"]) : ""; ?>" placeholder="e.g. 175">
                <?php if (isset($errors["height"])) { ?><span class="field-error"><?php echo $errors["height"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Current weight (kg)</span>
                <input type="number" name="weight" id="weight" step="0.1" value="<?php echo $member["weight"] > 0 ? htmlspecialchars($member["weight"]) : ""; ?>" placeholder="e.g. 72.5">
                <?php if (isset($errors["weight"])) { ?><span class="field-error"><?php echo $errors["weight"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Target weight (kg)</span>
                <input type="number" name="targetWeight" id="targetWeight" step="0.1" value="<?php echo $member["target_weight"] > 0 ? htmlspecialchars($member["target_weight"]) : ""; ?>" placeholder="Shows as a line on your chart">
                <?php if (isset($errors["targetWeight"])) { ?><span class="field-error"><?php echo $errors["targetWeight"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Goals</span>
                <textarea name="goals" id="goals" placeholder="e.g. Lose 5 kg before December, run 5 km without stopping"><?php echo htmlspecialchars($member["goals"]); ?></textarea>
            </div>
        </div>

        <button type="submit" name="saveDetails" value="1" class="btn frost" data-press>Save details</button>
    </form>

    <!-- ===== PASSWORD ===== -->
    <h2 class="section-title">Change password</h2>
    <?php if ($passwordSaved) { ?>
        <p class="success-text">Password changed.</p>
    <?php } ?>
    <form method="POST" action="profile.php" id="passwordForm" novalidate>
        <div class="group plain" data-sq="22">
            <div class="row">
                <input type="password" name="currentPassword" id="currentPassword" placeholder="Current password">
                <?php if (isset($errors["currentPassword"])) { ?><span class="field-error"><?php echo $errors["currentPassword"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <input type="password" name="newPassword" id="newPassword" placeholder="New password (min 8 characters)">
                <?php if (isset($errors["newPassword"])) { ?><span class="field-error"><?php echo $errors["newPassword"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <input type="password" name="confirmPassword" id="confirmPassword" placeholder="Confirm new password">
                <?php if (isset($errors["confirmPassword"])) { ?><span class="field-error"><?php echo $errors["confirmPassword"]; ?></span><?php } ?>
            </div>
        </div>
        <button type="submit" name="savePassword" value="1" class="btn frost" data-press>Change password</button>
    </form>

    <a href="logout.php" class="btn-outline" style="text-align:center; display:block; text-decoration:none; margin-top:24px;">Log out</a>

    <footer class="site-footer">
        <p>&copy; <?php echo date("Y"); ?> Singh Fitness Gym. All rights reserved.</p>
        <p><a href="feedback.php">Feedback</a> &middot; <a href="bookings.php">Bookings</a> &middot; <a href="logout.php">Logout</a></p>
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
            <button class="tab" data-i="0">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10l9-7 9 7v10a1 1 0 01-1 1h-5v-7H9v7H4a1 1 0 01-1-1z"/></svg><span class="t">Home</span>
            </button>
            <button class="tab" data-i="1">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M6.5 6.5h11v11h-11z"/><path d="M3 9v6M21 9v6"/></svg><span class="t">Fitness</span>
            </button>
            <button class="tab" data-i="2">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 18l5-6 4 3 6-8"/></svg><span class="t">Progress</span>
            </button>
            <button class="tab on" data-i="3">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="8" r="3.4"/><path d="M5 20c0-3.3 3.1-5.5 7-5.5s7 2.2 7 5.5"/></svg><span class="t">Profile</span>
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

// blank is fine for the optional numbers, but if typed it must be in range
function checkOptionalNumber(input, min, max, label) {
    var value = input.value.trim();
    if (value == "") {
        return true;
    }
    if (isNaN(value) || Number(value) < min || Number(value) > max) {
        showError(input, label + " should be between " + min + " and " + max + ".");
        return false;
    }
    return true;
}

document.getElementById("detailsForm").addEventListener("submit", function (e) {
    var form = this;
    clearErrors(form);
    var ok = true;

    var fullName = document.getElementById("fullName");
    if (fullName.value.trim() == "") {
        showError(fullName, "Please enter your name.");
        ok = false;
    }

    var phone = document.getElementById("phone");
    if (!/^[0-9]{8,15}$/.test(phone.value.trim())) {
        showError(phone, "Phone number should be 8 to 15 digits.");
        ok = false;
    }

    if (!checkOptionalNumber(document.getElementById("height"), 100, 250, "Height")) { ok = false; }
    if (!checkOptionalNumber(document.getElementById("weight"), 20, 300, "Weight")) { ok = false; }
    if (!checkOptionalNumber(document.getElementById("targetWeight"), 20, 300, "Target weight")) { ok = false; }

    if (!ok) {
        e.preventDefault();
    }
});

document.getElementById("passwordForm").addEventListener("submit", function (e) {
    var form = this;
    clearErrors(form);
    var ok = true;

    var currentPassword = document.getElementById("currentPassword");
    if (currentPassword.value == "") {
        showError(currentPassword, "Please type your current password.");
        ok = false;
    }

    var newPassword = document.getElementById("newPassword");
    if (newPassword.value.length < 8) {
        showError(newPassword, "New password must be at least 8 characters.");
        ok = false;
    }

    var confirmPassword = document.getElementById("confirmPassword");
    if (confirmPassword.value !== newPassword.value) {
        showError(confirmPassword, "Passwords do not match.");
        ok = false;
    }

    if (!ok) {
        e.preventDefault();
    }
});
</script>

</body>
</html>
