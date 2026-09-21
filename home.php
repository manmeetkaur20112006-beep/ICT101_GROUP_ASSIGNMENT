<?php
require_once "config.php";

// members only. admins get their own dashboard, everyone else goes to login
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

// ---- water / sleep logging ----
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if (isset($_POST["waterLitres"])) {
        $waterLitres = $_POST["waterLitres"];
        if (!is_numeric($waterLitres) || $waterLitres < 0 || $waterLitres > 10) {
            $errors["water"] = "Enter a number between 0 and 10 litres.";
        } else {
            $stmt = $conn->prepare("UPDATE members SET water_intake = ? WHERE id = ?");
            $stmt->bind_param("ds", $waterLitres, $memberId);
            $stmt->execute();
            $stmt->close();
            $successMsg = "Water intake saved.";
        }
    }

    if (isset($_POST["sleepHours"])) {
        $sleepHours = $_POST["sleepHours"];
        if (!is_numeric($sleepHours) || $sleepHours < 0 || $sleepHours > 24) {
            $errors["sleep"] = "Enter a number between 0 and 24 hours.";
        } else {
            $stmt = $conn->prepare("UPDATE members SET sleep_hours = ? WHERE id = ?");
            $stmt->bind_param("ds", $sleepHours, $memberId);
            $stmt->execute();
            $stmt->close();
            $successMsg = "Sleep hours saved.";
        }
    }

    // tick or untick one of today's exercises. the join makes sure it really is
    // on this member's plan and scheduled for today, not just any exercise id
    if (isset($_POST["toggleExercise"])) {
        $exerciseId = $_POST["toggleExercise"];
        $todayName = date("l");

        $stmt = $conn->prepare("SELECT e.id FROM exercises e
                                JOIN workout_days d ON d.id = e.day_id
                                JOIN members m ON m.workout_plan_id = d.plan_id
                                WHERE e.id = ? AND m.id = ? AND d.day_name = ?");
        $stmt->bind_param("sss", $exerciseId, $memberId, $todayName);
        $stmt->execute();
        $exerciseOk = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($exerciseOk) {
            $stmt = $conn->prepare("SELECT id FROM exercise_completions WHERE member_id = ? AND exercise_id = ? AND done_on = CURDATE()");
            $stmt->bind_param("ss", $memberId, $exerciseId);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                $stmt = $conn->prepare("DELETE FROM exercise_completions WHERE id = ?");
                $stmt->bind_param("i", $existing["id"]);
            } else {
                $stmt = $conn->prepare("INSERT INTO exercise_completions (member_id, exercise_id, done_on) VALUES (?, ?, CURDATE())");
                $stmt->bind_param("ss", $memberId, $exerciseId);
            }
            $stmt->execute();
            $stmt->close();

            syncDailyBonus($conn, $memberId, "exercises");
        }
    }

    // same idea for meals
    if (isset($_POST["toggleMeal"])) {
        $mealId = $_POST["toggleMeal"];
        $todayName = date("l");

        $stmt = $conn->prepare("SELECT dm.id FROM diet_meals dm
                                JOIN members m ON m.diet_plan_id = dm.plan_id
                                WHERE dm.id = ? AND m.id = ? AND (dm.day_name IS NULL OR dm.day_name = ?)");
        $stmt->bind_param("sss", $mealId, $memberId, $todayName);
        $stmt->execute();
        $mealOk = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($mealOk) {
            $stmt = $conn->prepare("SELECT id FROM meal_completions WHERE member_id = ? AND meal_id = ? AND done_on = CURDATE()");
            $stmt->bind_param("ss", $memberId, $mealId);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                $stmt = $conn->prepare("DELETE FROM meal_completions WHERE id = ?");
                $stmt->bind_param("i", $existing["id"]);
            } else {
                $stmt = $conn->prepare("INSERT INTO meal_completions (member_id, meal_id, done_on) VALUES (?, ?, CURDATE())");
                $stmt->bind_param("ss", $memberId, $mealId);
            }
            $stmt->execute();
            $stmt->close();

            syncDailyBonus($conn, $memberId, "meals");
        }
    }

    // reload the page as a normal visit. otherwise pressing refresh would
    // send the same tick again and undo it
    if (count($errors) == 0) {
        $flag = "";
        if ($successMsg == "Water intake saved.") {
            $flag = "?saved=water";
        } elseif ($successMsg == "Sleep hours saved.") {
            $flag = "?saved=sleep";
        }
        header("Location: home.php" . $flag);
        exit;
    }
}

if (isset($_GET["saved"])) {
    $successMsg = $_GET["saved"] == "water" ? "Water intake saved." : "Sleep hours saved.";
}

// ---- member details (fetched after the update so the numbers are fresh) ----
$stmt = $conn->prepare("SELECT name, member_code, status, expiry_date, streak, points,
                               water_intake, sleep_hours, workout_plan_id, diet_plan_id, gym_access
                        FROM members WHERE id = ?");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

$daysLeft = null;
if ($member["expiry_date"] != "") {
    $daysLeft = floor((strtotime($member["expiry_date"]) - strtotime(date("Y-m-d"))) / 86400);
}

// ---- checked in today? ----
$stmt = $conn->prepare("SELECT id FROM member_attendance WHERE member_id = ? AND attended_on = CURDATE()");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$checkedInToday = $stmt->get_result()->num_rows > 0;
$stmt->close();

// ---- streak, counted fresh from check-ins (see getStreak in config.php) ----
$streak = getStreak($conn, $memberId);

// ---- today's workout ----
$todayName = date("l");
$planName = "";
$isRestDay = false;
$exercises = [];
$doneToday = [];

if ($member["workout_plan_id"] != "") {
    $stmt = $conn->prepare("SELECT p.name, d.id AS day_id, d.is_rest_day
                            FROM workout_plans p
                            LEFT JOIN workout_days d ON d.plan_id = p.id AND d.day_name = ?
                            WHERE p.id = ?");
    $stmt->bind_param("ss", $todayName, $member["workout_plan_id"]);
    $stmt->execute();
    $planRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($planRow) {
        $planName = $planRow["name"];
        $isRestDay = $planRow["is_rest_day"] == 1;

        if ($planRow["day_id"] != "" && !$isRestDay) {
            $stmt = $conn->prepare("SELECT id, name, sets_label FROM exercises WHERE day_id = ? ORDER BY sort_order");
            $stmt->bind_param("i", $planRow["day_id"]);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $exercises[] = $row;
            }
            $stmt->close();

            $stmt = $conn->prepare("SELECT exercise_id FROM exercise_completions WHERE member_id = ? AND done_on = CURDATE()");
            $stmt->bind_param("s", $memberId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $doneToday[] = $row["exercise_id"];
            }
            $stmt->close();
        }
    }
}

// ---- today's meals ----
$meals = [];
$mealsDone = [];
$nextMealId = "";

if ($member["diet_plan_id"] != "") {
    $stmt = $conn->prepare("SELECT id, name, description FROM diet_meals
                            WHERE plan_id = ? AND (day_name IS NULL OR day_name = ?)
                            ORDER BY sort_order");
    $stmt->bind_param("ss", $member["diet_plan_id"], $todayName);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $meals[] = $row;
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT meal_id FROM meal_completions WHERE member_id = ? AND done_on = CURDATE()");
    $stmt->bind_param("s", $memberId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $mealsDone[] = $row["meal_id"];
    }
    $stmt->close();

    // the first one not ticked yet is "next"
    foreach ($meals as $meal) {
        if (!in_array($meal["id"], $mealsDone)) {
            $nextMealId = $meal["id"];
            break;
        }
    }
}

// ---- announcements, important ones first ----
$announcements = [];
$result = $conn->query("SELECT title, content, author, posted_on, important
                        FROM announcements ORDER BY important DESC, posted_on DESC LIMIT 5");
while ($row = $result->fetch_assoc()) {
    $announcements[] = $row;
}
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Home - Singh Fitness</title>
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

    <h1 class="title">Hi, <?php echo htmlspecialchars(explode(" ", $member["name"])[0]); ?></h1>
    <p class="sub">
        <?php echo htmlspecialchars($member["status"]); ?>
        <?php if ($daysLeft !== null) { ?>
            &middot; <?php echo $daysLeft >= 0 ? $daysLeft . " days left" : "expired " . abs($daysLeft) . " days ago"; ?>
        <?php } ?>
    </p>

    <?php if ($successMsg != "") { ?>
        <p class="success-text"><?php echo $successMsg; ?></p>
    <?php } ?>

    <div class="stat-grid">
        <div class="stat gold" data-sq="22">
            <div class="num"><?php echo $streak; ?></div>
            <div class="lbl">Day streak</div>
        </div>
        <div class="stat gold" data-sq="22">
            <div class="num"><?php echo (int)$member["points"]; ?></div>
            <div class="lbl">Points</div>
        </div>
        <div class="stat <?php echo $checkedInToday ? "good" : "muted"; ?>" data-sq="22">
            <div class="num"><?php echo $checkedInToday ? "Yes" : "No"; ?></div>
            <div class="lbl">Checked in today</div>
        </div>
        <div class="stat" data-sq="22">
            <div class="num"><?php echo count($doneToday); ?>/<?php echo count($exercises); ?></div>
            <div class="lbl">Exercises done</div>
        </div>
    </div>

    <?php if (!$checkedInToday && !$isRestDay) { ?>
        <a href="scanner.php" class="btn frost" data-press>Check in now &middot; scan the gym QR</a>
    <?php } elseif (!$checkedInToday && $isRestDay) { ?>
        <p class="hint" style="text-align:center;">Rest day today, no check-in needed. Your streak carries over.</p>
    <?php } ?>

    <h2 class="section-title">Today's workout &middot; <?php echo $todayName; ?></h2>

    <?php if ($planName == "") { ?>
        <p class="empty-text">No workout plan assigned yet. Your trainer will set one up for you.</p>
    <?php } elseif ($isRestDay) { ?>
        <p class="empty-text">Rest day on the <?php echo htmlspecialchars($planName); ?> plan. Stretch, hydrate and recover.</p>
    <?php } elseif (count($exercises) == 0) { ?>
        <p class="empty-text">Nothing scheduled for today on the <?php echo htmlspecialchars($planName); ?> plan.</p>
    <?php } else { ?>
        <div class="group plain" data-sq="22">
            <?php foreach ($exercises as $exercise) { ?>
                <?php $isDone = in_array($exercise["id"], $doneToday); ?>
                <div class="row split">
                    <a href="exercise.php?id=<?php echo urlencode($exercise["id"]); ?>" class="label <?php echo $isDone ? "done" : ""; ?>">
                        <?php echo htmlspecialchars($exercise["name"]); ?>
                        <span class="hint"><?php echo htmlspecialchars($exercise["sets_label"]); ?></span>
                    </a>
                    <form method="POST" action="home.php">
                        <button type="submit" name="toggleExercise" value="<?php echo htmlspecialchars($exercise["id"]); ?>" class="check <?php echo $isDone ? "on" : ""; ?>" aria-label="Tick off <?php echo htmlspecialchars($exercise["name"]); ?>">&#10003;</button>
                    </form>
                </div>
            <?php } ?>
        </div>
        <p class="hint" style="text-align:center;">Tap the circle to tick it off. Tap the name for the guide and video.</p>
    <?php } ?>

    <h2 class="section-title">Today's diet <span class="muted"><?php echo count($mealsDone); ?> of <?php echo count($meals); ?> done</span></h2>

    <?php if (count($meals) == 0) { ?>
        <p class="empty-text">No diet plan assigned yet. Your trainer will set one up for you.</p>
    <?php } else { ?>
        <div class="group plain" data-sq="22">
            <?php foreach ($meals as $meal) { ?>
                <?php $isDone = in_array($meal["id"], $mealsDone); ?>
                <div class="row split">
                    <div class="label <?php echo $isDone ? "done" : ""; ?>">
                        <?php echo htmlspecialchars($meal["name"]); ?>
                        <?php if ($meal["id"] == $nextMealId) { ?><span class="badge">Next</span><?php } ?>
                        <span class="hint"><?php echo htmlspecialchars($meal["description"]); ?></span>
                    </div>
                    <form method="POST" action="home.php">
                        <button type="submit" name="toggleMeal" value="<?php echo htmlspecialchars($meal["id"]); ?>" class="check <?php echo $isDone ? "on" : ""; ?>" aria-label="Tick off <?php echo htmlspecialchars($meal["name"]); ?>">&#10003;</button>
                    </form>
                </div>
            <?php } ?>
        </div>
        <?php if ($nextMealId == "") { ?>
            <p class="hint" style="text-align:center;">All meals done for today. Nice work.</p>
        <?php } ?>
    <?php } ?>

    <h2 class="section-title">Daily log</h2>

    <div class="group plain" data-sq="22">
        <div class="row">
            <div class="row-label">Water today (litres) &middot; currently <?php echo number_format($member["water_intake"], 2); ?> L</div>
            <form method="POST" action="home.php" class="inline-form" id="waterForm" novalidate>
                <input type="number" name="waterLitres" id="waterLitres" step="0.25" min="0" max="10" placeholder="e.g. 2.5">
                <button type="submit" class="btn frost small" data-press>Save</button>
            </form>
            <?php if (isset($errors["water"])) { ?><span class="field-error"><?php echo $errors["water"]; ?></span><?php } ?>
        </div>
        <div class="row">
            <div class="row-label">Sleep last night (hours) &middot; currently <?php echo number_format($member["sleep_hours"], 1); ?> h</div>
            <form method="POST" action="home.php" class="inline-form" id="sleepForm" novalidate>
                <input type="number" name="sleepHours" id="sleepHours" step="0.5" min="0" max="24" placeholder="e.g. 7.5">
                <button type="submit" class="btn frost small" data-press>Save</button>
            </form>
            <?php if (isset($errors["sleep"])) { ?><span class="field-error"><?php echo $errors["sleep"]; ?></span><?php } ?>
        </div>
    </div>

    <h2 class="section-title">Announcements</h2>

    <?php if (count($announcements) == 0) { ?>
        <p class="empty-text">No announcements right now.</p>
    <?php } else { ?>
        <?php foreach ($announcements as $a) { ?>
            <div class="notice <?php echo $a["important"] ? "important" : ""; ?>">
                <h3><?php echo htmlspecialchars($a["title"]); ?></h3>
                <p><?php echo nl2br(htmlspecialchars($a["content"])); ?></p>
                <div class="meta">
                    <?php echo htmlspecialchars($a["author"]); ?> &middot; <?php echo date("j M Y", strtotime($a["posted_on"])); ?>
                </div>
            </div>
        <?php } ?>
    <?php } ?>

    <footer class="site-footer">
        <p>&copy; <?php echo date("Y"); ?> Singh Fitness Gym. All rights reserved.</p>
        <p><a href="feedback.php">Feedback</a> &middot; <a href="profile.php">My profile</a> &middot; <a href="logout.php">Logout</a></p>
    </footer>

</div>

<!-- bottom tab bar, markup as built in design-reference.html -->
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
            <button class="tab on" data-i="0">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10l9-7 9 7v10a1 1 0 01-1 1h-5v-7H9v7H4a1 1 0 01-1-1z"/></svg><span class="t">Home</span>
            </button>
            <button class="tab" data-i="1">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M6.5 6.5h11v11h-11z"/><path d="M3 9v6M21 9v6"/></svg><span class="t">Fitness</span>
            </button>
            <button class="tab" data-i="2">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 18l5-6 4 3 6-8"/></svg><span class="t">Progress</span>
            </button>
            <button class="tab" data-i="3">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="8" r="3.4"/><path d="M5 20c0-3.3 3.1-5.5 7-5.5s7 2.2 7 5.5"/></svg><span class="t">Profile</span>
            </button>
        </div>
    </nav>
</div>

<script src="assets/js/liquid-tabbar.js?v=<?php echo filemtime("assets/js/liquid-tabbar.js"); ?>"></script>
<script src="assets/js/app.js?v=<?php echo filemtime("assets/js/app.js"); ?>"></script>

<script>
function clearErrors(form) {
    var errorSpans = form.parentNode.querySelectorAll(".field-error.js-error");
    for (var i = 0; i < errorSpans.length; i++) {
        errorSpans[i].remove();
    }
}

// the error goes after the form, not inside it, so the flex row doesn't squash it
function showError(form, message) {
    var span = document.createElement("span");
    span.className = "field-error js-error";
    span.textContent = message;
    form.parentNode.appendChild(span);
}

function checkNumber(form, input, min, max, label) {
    clearErrors(form);
    var value = input.value.trim();

    if (value == "" || isNaN(value)) {
        showError(form, "Please enter a number for " + label + ".");
        return false;
    }
    if (Number(value) < min || Number(value) > max) {
        showError(form, label + " must be between " + min + " and " + max + ".");
        return false;
    }
    return true;
}

document.getElementById("waterForm").addEventListener("submit", function (e) {
    if (!checkNumber(this, document.getElementById("waterLitres"), 0, 10, "water")) {
        e.preventDefault();
    }
});

document.getElementById("sleepForm").addEventListener("submit", function (e) {
    if (!checkNumber(this, document.getElementById("sleepHours"), 0, 24, "sleep")) {
        e.preventDefault();
    }
});
</script>

</body>
</html>
