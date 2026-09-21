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

$sessionTypes = ["Personal Training", "Diet Consultation", "Body Measurement"];
$timeSlots = ["06:00 - 07:00", "07:00 - 08:00", "08:00 - 09:00", "09:00 - 10:00",
              "17:00 - 18:00", "18:00 - 19:00", "19:00 - 20:00", "20:00 - 21:00"];

$today = date("Y-m-d");
$lastAllowed = date("Y-m-d", strtotime("+30 days"));

// keep what they typed if the form comes back with errors
$sessionType = "";
$bookingDate = "";
$timeSlot = "";
$note = "";

// ---- cancel one of my pending bookings ----
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["cancelBooking"])) {
    $bookingId = (int)$_POST["cancelBooking"];
    // only my own, and only if the admin hasn't dealt with it yet
    $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ? AND member_id = ? AND status = 'Pending'");
    $stmt->bind_param("is", $bookingId, $memberId);
    $stmt->execute();
    $stmt->close();
    header("Location: bookings.php?msg=cancelled");
    exit;
}

// ---- make a booking ----
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["sessionType"])) {
    $sessionType = $_POST["sessionType"];
    $bookingDate = trim($_POST["bookingDate"]);
    $timeSlot = $_POST["timeSlot"] ?? "";
    $note = trim($_POST["note"]);

    if (!in_array($sessionType, $sessionTypes)) {
        $errors["sessionType"] = "Please choose a session type.";
    }
    $dateOk = preg_match("/^\d{4}-\d{2}-\d{2}$/", $bookingDate) && strtotime($bookingDate) !== false;
    if (!$dateOk) {
        $errors["bookingDate"] = "Please pick a date.";
    } elseif ($bookingDate < $today) {
        $errors["bookingDate"] = "That date has already passed.";
    } elseif ($bookingDate > $lastAllowed) {
        $errors["bookingDate"] = "You can book up to 30 days ahead.";
    }
    if (!in_array($timeSlot, $timeSlots)) {
        $errors["timeSlot"] = "Please choose a time.";
    }
    if (strlen($note) > 300) {
        $errors["note"] = "Keep the note under 300 characters.";
    }

    if (count($errors) == 0) {
        $stmt = $conn->prepare("SELECT id FROM bookings WHERE member_id = ? AND booking_date = ? AND time_slot = ? AND status != 'Rejected'");
        $stmt->bind_param("sss", $memberId, $bookingDate, $timeSlot);
        $stmt->execute();
        $clash = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($clash) {
            $errors["timeSlot"] = "You already have a booking in that slot.";
        } else {
            $stmt = $conn->prepare("INSERT INTO bookings (member_id, session_type, booking_date, time_slot, note) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $memberId, $sessionType, $bookingDate, $timeSlot, $note);
            $stmt->execute();
            $stmt->close();
            header("Location: bookings.php?msg=booked");
            exit;
        }
    }
}

if (isset($_GET["msg"])) {
    if ($_GET["msg"] == "booked") {
        $successMsg = "Booking sent. The gym will confirm it shortly.";
    } elseif ($_GET["msg"] == "cancelled") {
        $successMsg = "Booking cancelled.";
    }
}

$stmt = $conn->prepare("SELECT name, member_code FROM members WHERE id = ?");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

// upcoming first, then the past ones
$upcoming = [];
$past = [];
$stmt = $conn->prepare("SELECT id, session_type, booking_date, time_slot, status, note FROM bookings
                        WHERE member_id = ? ORDER BY booking_date DESC, time_slot DESC LIMIT 30");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if ($row["booking_date"] >= $today && $row["status"] != "Completed") {
        $upcoming[] = $row;
    } else {
        $past[] = $row;
    }
}
$stmt->close();
$upcoming = array_reverse($upcoming);   // soonest at the top
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Bookings - Singh Fitness</title>
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

    <a href="profile.php" class="back-link">&lsaquo; Profile</a>
    <h1 class="title">Book a session</h1>
    <p class="sub">Trainer time, a diet chat or a body measurement</p>

    <?php if ($successMsg != "") { ?>
        <p class="success-text"><?php echo $successMsg; ?></p>
    <?php } ?>

    <form method="POST" action="bookings.php" id="bookingForm" novalidate>
        <?php foreach ($sessionTypes as $type) { ?>
            <div class="plan-option" data-sq="22">
                <label>
                    <input type="radio" name="sessionType" value="<?php echo $type; ?>" <?php echo $sessionType == $type ? "checked" : ""; ?>>
                    <span><?php echo $type; ?></span>
                </label>
            </div>
        <?php } ?>
        <?php if (isset($errors["sessionType"])) { ?><span class="field-error"><?php echo $errors["sessionType"]; ?></span><?php } ?>

        <div class="group plain" data-sq="22" style="margin-top:14px;">
            <div class="row">
                <span class="field-label">Date</span>
                <input type="date" name="bookingDate" id="bookingDate" min="<?php echo $today; ?>" max="<?php echo $lastAllowed; ?>" value="<?php echo htmlspecialchars($bookingDate); ?>">
                <?php if (isset($errors["bookingDate"])) { ?><span class="field-error"><?php echo $errors["bookingDate"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Time</span>
                <select name="timeSlot" id="timeSlot">
                    <option value="">Choose a time</option>
                    <?php foreach ($timeSlots as $slot) { ?>
                        <option value="<?php echo $slot; ?>" <?php echo $timeSlot == $slot ? "selected" : ""; ?>><?php echo $slot; ?></option>
                    <?php } ?>
                </select>
                <?php if (isset($errors["timeSlot"])) { ?><span class="field-error"><?php echo $errors["timeSlot"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Note for the trainer (optional)</span>
                <textarea name="note" id="note" placeholder="e.g. Knee has been sore, want to work around it"><?php echo htmlspecialchars($note); ?></textarea>
                <?php if (isset($errors["note"])) { ?><span class="field-error"><?php echo $errors["note"]; ?></span><?php } ?>
            </div>
        </div>

        <button type="submit" class="btn frost" data-press>Book it</button>
    </form>

    <h2 class="section-title">Upcoming</h2>
    <?php if (count($upcoming) == 0) { ?>
        <p class="empty-text">No upcoming bookings.</p>
    <?php } else { ?>
        <div class="group plain" data-sq="22">
            <?php foreach ($upcoming as $b) { ?>
                <?php
                $pillClass = "pending";
                if ($b["status"] == "Confirmed") { $pillClass = "good"; }
                if ($b["status"] == "Rejected") { $pillClass = "bad"; }
                ?>
                <div class="row split">
                    <div class="label">
                        <?php echo htmlspecialchars($b["session_type"]); ?>
                        <span class="pill <?php echo $pillClass; ?>"><?php echo htmlspecialchars($b["status"]); ?></span>
                        <span class="hint">
                            <?php echo date("D j M", strtotime($b["booking_date"])); ?> &middot; <?php echo htmlspecialchars($b["time_slot"]); ?>
                            <?php if ($b["note"] != "") { ?><br><?php echo htmlspecialchars($b["note"]); ?><?php } ?>
                        </span>
                    </div>
                    <?php if ($b["status"] == "Pending") { ?>
                        <form method="POST" action="bookings.php" class="cancel-form">
                            <button type="submit" name="cancelBooking" value="<?php echo $b["id"]; ?>" class="btn-outline confirm-btn" style="width:auto; margin:0; padding:8px 12px; font-size:13px;">Cancel</button>
                        </form>
                    <?php } ?>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if (count($past) > 0) { ?>
        <h2 class="section-title">Past</h2>
        <div class="group plain" data-sq="22">
            <?php foreach ($past as $b) { ?>
                <div class="row">
                    <?php echo htmlspecialchars($b["session_type"]); ?>
                    <span class="pill"><?php echo htmlspecialchars($b["status"]); ?></span>
                    <span class="hint"><?php echo date("D j M Y", strtotime($b["booking_date"])); ?> &middot; <?php echo htmlspecialchars($b["time_slot"]); ?></span>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <footer class="site-footer">
        <p>&copy; <?php echo date("Y"); ?> Singh Fitness Gym. All rights reserved.</p>
        <p><a href="feedback.php">Feedback</a> &middot; <a href="profile.php">My profile</a> &middot; <a href="logout.php">Logout</a></p>
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

function showError(afterEl, message) {
    var span = document.createElement("span");
    span.className = "field-error js-error";
    span.textContent = message;
    afterEl.parentNode.insertBefore(span, afterEl.nextSibling);
}

document.getElementById("bookingForm").addEventListener("submit", function (e) {
    var form = this;
    clearErrors(form);
    var ok = true;

    var typePicked = form.querySelector('input[name="sessionType"]:checked');
    if (!typePicked) {
        var lastOption = form.querySelectorAll(".plan-option");
        showError(lastOption[lastOption.length - 1], "Please choose a session type.");
        ok = false;
    }

    var bookingDate = document.getElementById("bookingDate");
    var today = "<?php echo $today; ?>";
    var lastAllowed = "<?php echo $lastAllowed; ?>";
    if (bookingDate.value == "") {
        showError(bookingDate, "Please pick a date.");
        ok = false;
    } else if (bookingDate.value < today) {
        showError(bookingDate, "That date has already passed.");
        ok = false;
    } else if (bookingDate.value > lastAllowed) {
        showError(bookingDate, "You can book up to 30 days ahead.");
        ok = false;
    }

    var timeSlot = document.getElementById("timeSlot");
    if (timeSlot.value == "") {
        showError(timeSlot, "Please choose a time.");
        ok = false;
    }

    var note = document.getElementById("note");
    if (note.value.length > 300) {
        showError(note, "Keep the note under 300 characters.");
        ok = false;
    }

    if (!ok) {
        e.preventDefault();
    }
});
</script>

</body>
</html>
