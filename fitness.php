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
$openPanel = "workout";

$dayNames = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
$todayName = date("l");

$stmt = $conn->prepare("SELECT name, member_code, workout_plan_id, diet_plan_id FROM members WHERE id = ?");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---- tick or untick a meal for today ----
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["toggleMeal"])) {
    $mealId = $_POST["toggleMeal"];
    $openPanel = "diet";

    // only meals on this member's own plan, and only ones that are on today's list
    $stmt = $conn->prepare("SELECT id FROM diet_meals WHERE id = ? AND plan_id = ? AND (day_name IS NULL OR day_name = ?)");
    $stmt->bind_param("sss", $mealId, $member["diet_plan_id"], $todayName);
    $stmt->execute();
    $mealOk = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($mealOk) {
        $stmt = $conn->prepare("SELECT id FROM meal_completions WHERE member_id = ? AND meal_id = ? AND done_on = CURDATE()");
        $stmt->bind_param("ss", $memberId, $mealId);
        $stmt->execute();
        $alreadyDone = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($alreadyDone) {
            $stmt = $conn->prepare("DELETE FROM meal_completions WHERE id = ?");
            $stmt->bind_param("i", $alreadyDone["id"]);
        } else {
            $stmt = $conn->prepare("INSERT INTO meal_completions (member_id, meal_id, done_on) VALUES (?, ?, CURDATE())");
            $stmt->bind_param("ss", $memberId, $mealId);
        }
        $stmt->execute();
        $stmt->close();

        syncDailyBonus($conn, $memberId, "meals");
    }

    // reload as a normal visit so refresh can't send the same tick twice
    header("Location: fitness.php?panel=diet");
    exit;
}

// ---- request, update or cancel a plan change (workout or diet) ----
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["planType"])) {
    $planType = $_POST["planType"];
    $requestAction = $_POST["requestAction"] ?? "send";
    $requestedPlanId = $_POST["requestedPlan"] ?? "";
    $openPanel = "request";

    if ($planType != "workout" && $planType != "diet") {
        $planType = "workout";
    }
    // workout plans and diet plans live in different tables
    $planTable = $planType == "workout" ? "workout_plans" : "diet_plans";
    $currentPlanId = $planType == "workout" ? $member["workout_plan_id"] : $member["diet_plan_id"];

    $stmt = $conn->prepare("SELECT id FROM plan_requests WHERE member_id = ? AND plan_type = ? AND status = 'Pending'");
    $stmt->bind_param("ss", $memberId, $planType);
    $stmt->execute();
    $pendingRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($requestAction == "cancel") {
        if ($pendingRow) {
            $stmt = $conn->prepare("DELETE FROM plan_requests WHERE id = ?");
            $stmt->bind_param("s", $pendingRow["id"]);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: fitness.php?panel=request&msg=cancelled");
        exit;
    }

    $stmt = $conn->prepare("SELECT name FROM $planTable WHERE id = ?");
    $stmt->bind_param("s", $requestedPlanId);
    $stmt->execute();
    $requestedPlan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$requestedPlan) {
        $errors[$planType] = "Please choose a plan.";
    } elseif ($requestedPlanId == $currentPlanId) {
        $errors[$planType] = "You are already on that plan.";
    } else {
        $currentPlanName = "";
        if ($currentPlanId != "") {
            $stmt = $conn->prepare("SELECT name FROM $planTable WHERE id = ?");
            $stmt->bind_param("s", $currentPlanId);
            $stmt->execute();
            $currentRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $currentPlanName = $currentRow ? $currentRow["name"] : "";
        }

        if ($pendingRow) {
            // already waiting, so just change what they asked for
            $stmt = $conn->prepare("UPDATE plan_requests SET requested_plan_id = ?, requested_plan_name = ?, created_at = NOW() WHERE id = ?");
            $stmt->bind_param("sss", $requestedPlanId, $requestedPlan["name"], $pendingRow["id"]);
            $stmt->execute();
            $stmt->close();
            header("Location: fitness.php?panel=request&msg=updated");
            exit;
        } else {
            $requestId = "req_" . uniqid();
            $stmt = $conn->prepare("INSERT INTO plan_requests
                (id, member_id, member_name, plan_type, current_plan_name, requested_plan_id, requested_plan_name)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $requestId, $memberId, $member["name"], $planType, $currentPlanName, $requestedPlanId, $requestedPlan["name"]);
            $stmt->execute();
            $stmt->close();
            header("Location: fitness.php?panel=request&msg=sent");
            exit;
        }
    }
}

// which panel to open, and any message from a redirect above
if (isset($_GET["panel"]) && in_array($_GET["panel"], ["workout", "diet", "request"])) {
    $openPanel = $_GET["panel"];
}
if (isset($_GET["msg"])) {
    if ($_GET["msg"] == "sent") {
        $successMsg = "Request sent. The admin will approve or reject it.";
    } elseif ($_GET["msg"] == "updated") {
        $successMsg = "Request updated.";
    } elseif ($_GET["msg"] == "cancelled") {
        $successMsg = "Request cancelled.";
    }
}

// ---- the full week ----
$planName = "";
$planDescription = "";
$week = [];   // day name => ["rest" => bool, "exercises" => [...]]

if ($member["workout_plan_id"] != "") {
    $stmt = $conn->prepare("SELECT name, description FROM workout_plans WHERE id = ?");
    $stmt->bind_param("s", $member["workout_plan_id"]);
    $stmt->execute();
    $planRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($planRow) {
        $planName = $planRow["name"];
        $planDescription = $planRow["description"];

        $stmt = $conn->prepare("SELECT d.day_name, d.is_rest_day, e.id, e.name, e.sets_label
                                FROM workout_days d
                                LEFT JOIN exercises e ON e.day_id = d.id
                                WHERE d.plan_id = ?
                                ORDER BY d.id, e.sort_order");
        $stmt->bind_param("s", $member["workout_plan_id"]);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $day = $row["day_name"];
            if (!isset($week[$day])) {
                $week[$day] = ["rest" => $row["is_rest_day"] == 1, "exercises" => []];
            }
            if ($row["id"] != "") {
                $week[$day]["exercises"][] = $row;
            }
        }
        $stmt->close();
    }
}

$doneToday = [];
$stmt = $conn->prepare("SELECT exercise_id FROM exercise_completions WHERE member_id = ? AND done_on = CURDATE()");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $doneToday[] = $row["exercise_id"];
}
$stmt->close();

// ---- diet plan ----
$dietName = "";
$dietDescription = "";
$meals = [];
$mealsDone = [];

if ($member["diet_plan_id"] != "") {
    $stmt = $conn->prepare("SELECT name, description FROM diet_plans WHERE id = ?");
    $stmt->bind_param("s", $member["diet_plan_id"]);
    $stmt->execute();
    $dietRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($dietRow) {
        $dietName = $dietRow["name"];
        $dietDescription = $dietRow["description"];

        // every meal on the plan. a meal with no day_name is eaten every day,
        // so it gets copied into all seven days when we build the week below
        $stmt = $conn->prepare("SELECT id, name, description, day_name FROM diet_meals WHERE plan_id = ? ORDER BY sort_order");
        $stmt->bind_param("s", $member["diet_plan_id"]);
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
    }
}

$dietWeek = [];   // day name => list of meals for that day
foreach ($dayNames as $day) {
    $dietWeek[$day] = [];
    foreach ($meals as $meal) {
        if ($meal["day_name"] == "" || $meal["day_name"] == $day) {
            $dietWeek[$day][] = $meal;
        }
    }
}
$todayMealCount = count($dietWeek[$todayName]);

// ---- plans available to request, and any request still waiting, for each type ----
$otherWorkouts = [];
$result = $conn->query("SELECT id, name, description FROM workout_plans ORDER BY name");
while ($row = $result->fetch_assoc()) {
    if ($row["id"] != $member["workout_plan_id"]) {
        $otherWorkouts[] = $row;
    }
}

$otherDiets = [];
$result = $conn->query("SELECT id, name, description FROM diet_plans ORDER BY name");
while ($row = $result->fetch_assoc()) {
    if ($row["id"] != $member["diet_plan_id"]) {
        $otherDiets[] = $row;
    }
}

$pendingWorkout = null;
$pendingDiet = null;
$stmt = $conn->prepare("SELECT plan_type, requested_plan_id, requested_plan_name FROM plan_requests
                        WHERE member_id = ? AND status = 'Pending'");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if ($row["plan_type"] == "workout") {
        $pendingWorkout = $row;
    } else {
        $pendingDiet = $row;
    }
}
$stmt->close();

$stmt = $conn->prepare("SELECT plan_type, requested_plan_name, status, created_at FROM plan_requests
                        WHERE member_id = ? AND status != 'Pending' ORDER BY created_at DESC LIMIT 5");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$result = $stmt->get_result();
$pastRequests = [];
while ($row = $result->fetch_assoc()) {
    $pastRequests[] = $row;
}
$stmt->close();
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Fitness - Singh Fitness</title>
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

    <h1 class="title">Fitness</h1>
    <p class="sub">Your week, your meals, and plan changes</p>

    <div class="seg" id="seg">
        <button type="button" data-panel="workout" class="<?php echo $openPanel == "workout" ? "on" : ""; ?>">Workout</button>
        <button type="button" data-panel="diet" class="<?php echo $openPanel == "diet" ? "on" : ""; ?>">Diet</button>
        <button type="button" data-panel="request" class="<?php echo $openPanel == "request" ? "on" : ""; ?>">Change plan</button>
    </div>

    <!-- ===== WORKOUT ===== -->
    <div class="panel <?php echo $openPanel == "workout" ? "on" : ""; ?>" id="panel-workout">

        <?php if ($planName == "") { ?>
            <p class="empty-text">No workout plan assigned yet. Your trainer will set one up for you.</p>
        <?php } else { ?>
            <p class="sub"><strong><?php echo htmlspecialchars($planName); ?></strong> &middot; <?php echo htmlspecialchars($planDescription); ?></p>

            <?php foreach ($dayNames as $day) { ?>
                <?php $isToday = $day == $todayName; ?>
                <h2 class="section-title">
                    <?php echo $day; ?>
                    <?php if ($isToday) { ?><span class="badge">Today</span><?php } ?>
                </h2>

                <?php if (!isset($week[$day]) || $week[$day]["rest"]) { ?>
                    <p class="empty-text">Rest day</p>
                <?php } elseif (count($week[$day]["exercises"]) == 0) { ?>
                    <p class="empty-text">Nothing scheduled</p>
                <?php } else { ?>
                    <div class="group plain" data-sq="22">
                        <?php foreach ($week[$day]["exercises"] as $exercise) { ?>
                            <?php $isDone = $isToday && in_array($exercise["id"], $doneToday); ?>
                            <a href="exercise.php?id=<?php echo urlencode($exercise["id"]); ?>" class="row link" data-tap>
                                <?php if ($isDone) { ?><span class="tick">&#10003;</span><?php } ?>
                                <div class="label <?php echo $isDone ? "done" : ""; ?>">
                                    <?php echo htmlspecialchars($exercise["name"]); ?>
                                    <span class="hint"><?php echo htmlspecialchars($exercise["sets_label"]); ?></span>
                                </div>
                                <div class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div>
                            </a>
                        <?php } ?>
                    </div>
                <?php } ?>
            <?php } ?>
        <?php } ?>
    </div>

    <!-- ===== DIET ===== -->
    <div class="panel <?php echo $openPanel == "diet" ? "on" : ""; ?>" id="panel-diet">

        <?php if ($dietName == "") { ?>
            <p class="empty-text">No diet plan assigned yet. Your trainer will set one up for you.</p>
        <?php } else { ?>
            <p class="sub"><strong><?php echo htmlspecialchars($dietName); ?></strong> &middot; <?php echo htmlspecialchars($dietDescription); ?></p>

            <?php foreach ($dayNames as $day) { ?>
                <?php $isToday = $day == $todayName; ?>
                <h2 class="section-title">
                    <?php echo $day; ?>
                    <?php if ($isToday) { ?>
                        <span class="badge">Today</span>
                        <span class="muted"><?php echo count($mealsDone); ?> of <?php echo $todayMealCount; ?> done</span>
                    <?php } ?>
                </h2>

                <?php if (count($dietWeek[$day]) == 0) { ?>
                    <p class="empty-text">Nothing planned</p>
                <?php } elseif ($isToday) { ?>
                    <!-- only today's meals can be ticked -->
                    <div class="group plain" data-sq="22">
                        <?php foreach ($dietWeek[$day] as $meal) { ?>
                            <?php $isDone = in_array($meal["id"], $mealsDone); ?>
                            <div class="row split">
                                <div class="label <?php echo $isDone ? "done" : ""; ?>">
                                    <?php echo htmlspecialchars($meal["name"]); ?>
                                    <span class="hint"><?php echo htmlspecialchars($meal["description"]); ?></span>
                                </div>
                                <form method="POST" action="fitness.php">
                                    <button type="submit" name="toggleMeal" value="<?php echo htmlspecialchars($meal["id"]); ?>" class="check <?php echo $isDone ? "on" : ""; ?>" aria-label="Tick off <?php echo htmlspecialchars($meal["name"]); ?>">&#10003;</button>
                                </form>
                            </div>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <div class="group plain" data-sq="22">
                        <?php foreach ($dietWeek[$day] as $meal) { ?>
                            <div class="row">
                                <?php echo htmlspecialchars($meal["name"]); ?>
                                <span class="hint"><?php echo htmlspecialchars($meal["description"]); ?></span>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            <?php } ?>
        <?php } ?>
    </div>

    <!-- ===== CHANGE PLAN ===== -->
    <div class="panel <?php echo $openPanel == "request" ? "on" : ""; ?>" id="panel-request">

        <?php if ($successMsg != "") { ?>
            <p class="success-text"><?php echo $successMsg; ?></p>
        <?php } ?>

        <!-- ---- workout ---- -->
        <h2 class="section-title">Workout plan</h2>
        <p class="sub">
            Current: <strong><?php echo $planName != "" ? htmlspecialchars($planName) : "none"; ?></strong>
            <?php if ($pendingWorkout) { ?>
                <br>Requested: <strong><?php echo htmlspecialchars($pendingWorkout["requested_plan_name"]); ?></strong> &middot; waiting for the admin
            <?php } ?>
        </p>

        <?php if (count($otherWorkouts) == 0) { ?>
            <p class="empty-text">There are no other workout plans to choose from right now.</p>
        <?php } else { ?>
            <form method="POST" action="fitness.php" class="request-form" novalidate>
                <input type="hidden" name="planType" value="workout">
                <?php foreach ($otherWorkouts as $plan) { ?>
                    <?php $picked = $pendingWorkout && $pendingWorkout["requested_plan_id"] == $plan["id"]; ?>
                    <div class="plan-option" data-sq="22">
                        <label>
                            <input type="radio" name="requestedPlan" value="<?php echo htmlspecialchars($plan["id"]); ?>" <?php echo $picked ? "checked" : ""; ?>>
                            <span>
                                <?php echo htmlspecialchars($plan["name"]); ?>
                                <span class="hint"><?php echo htmlspecialchars($plan["description"]); ?></span>
                            </span>
                        </label>
                    </div>
                <?php } ?>
                <?php if (isset($errors["workout"])) { ?><span class="field-error"><?php echo $errors["workout"]; ?></span><?php } ?>

                <?php if ($pendingWorkout) { ?>
                    <button type="submit" name="requestAction" value="update" class="btn frost" data-press>Update request</button>
                    <button type="submit" name="requestAction" value="cancel" class="btn-outline cancel-btn">Cancel request</button>
                <?php } else { ?>
                    <button type="submit" name="requestAction" value="send" class="btn frost" data-press>Send request</button>
                <?php } ?>
            </form>
        <?php } ?>

        <!-- ---- diet ---- -->
        <h2 class="section-title">Diet plan</h2>
        <p class="sub">
            Current: <strong><?php echo $dietName != "" ? htmlspecialchars($dietName) : "none"; ?></strong>
            <?php if ($pendingDiet) { ?>
                <br>Requested: <strong><?php echo htmlspecialchars($pendingDiet["requested_plan_name"]); ?></strong> &middot; waiting for the admin
            <?php } ?>
        </p>

        <?php if (count($otherDiets) == 0) { ?>
            <p class="empty-text">There are no other diet plans to choose from right now.</p>
        <?php } else { ?>
            <form method="POST" action="fitness.php" class="request-form" novalidate>
                <input type="hidden" name="planType" value="diet">
                <?php foreach ($otherDiets as $plan) { ?>
                    <?php $picked = $pendingDiet && $pendingDiet["requested_plan_id"] == $plan["id"]; ?>
                    <div class="plan-option" data-sq="22">
                        <label>
                            <input type="radio" name="requestedPlan" value="<?php echo htmlspecialchars($plan["id"]); ?>" <?php echo $picked ? "checked" : ""; ?>>
                            <span>
                                <?php echo htmlspecialchars($plan["name"]); ?>
                                <span class="hint"><?php echo htmlspecialchars($plan["description"]); ?></span>
                            </span>
                        </label>
                    </div>
                <?php } ?>
                <?php if (isset($errors["diet"])) { ?><span class="field-error"><?php echo $errors["diet"]; ?></span><?php } ?>

                <?php if ($pendingDiet) { ?>
                    <button type="submit" name="requestAction" value="update" class="btn frost" data-press>Update request</button>
                    <button type="submit" name="requestAction" value="cancel" class="btn-outline cancel-btn">Cancel request</button>
                <?php } else { ?>
                    <button type="submit" name="requestAction" value="send" class="btn frost" data-press>Send request</button>
                <?php } ?>
            </form>
        <?php } ?>

        <?php if (count($pastRequests) > 0) { ?>
            <h2 class="section-title">Past requests</h2>
            <div class="group plain" data-sq="22">
                <?php foreach ($pastRequests as $req) { ?>
                    <div class="row">
                        <?php echo htmlspecialchars($req["requested_plan_name"]); ?>
                        <span class="hint">
                            <?php echo ucfirst($req["plan_type"]); ?> &middot; <?php echo htmlspecialchars($req["status"]); ?> &middot; <?php echo date("j M Y", strtotime($req["created_at"])); ?>
                        </span>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </div>

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
            <button class="tab on" data-i="1">
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
// workout / diet / change plan switcher
var segButtons = document.querySelectorAll("#seg button");
for (var i = 0; i < segButtons.length; i++) {
    segButtons[i].addEventListener("click", function () {
        var want = this.dataset.panel;
        for (var j = 0; j < segButtons.length; j++) {
            segButtons[j].classList.toggle("on", segButtons[j] === this);
        }
        var panels = document.querySelectorAll(".panel");
        for (var k = 0; k < panels.length; k++) {
            panels[k].classList.toggle("on", panels[k].id === "panel-" + want);
        }
        window.scrollTo(0, 0);
    });
}

// plan change forms: must pick a plan, unless they're cancelling
var requestForms = document.querySelectorAll(".request-form");
for (var r = 0; r < requestForms.length; r++) {
    requestForms[r].addEventListener("submit", function (e) {
        var form = this;
        var old = form.querySelector(".field-error.js-error");
        if (old) {
            old.remove();
        }
        // the cancel button doesn't need a plan chosen
        if (e.submitter && e.submitter.value == "cancel") {
            return;
        }
        var picked = form.querySelector('input[name="requestedPlan"]:checked');
        if (!picked) {
            var span = document.createElement("span");
            span.className = "field-error js-error";
            span.textContent = "Please choose a plan first.";
            form.querySelector(".btn").insertAdjacentElement("beforebegin", span);
            e.preventDefault();
        }
    });
}
</script>

</body>
</html>
