<?php
require_once "config.php";

// Owner only
if (!isset($_SESSION["memberId"]) || $_SESSION["role"] != "admin") {
    header("Location: login.php");
    exit;
}

$dayNames = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
$errors = [];
$successMsg = "";

// which screen: list, a workout plan, an exercise inside it, or a diet plan
$type = $_GET["type"] ?? "";          // "workout" or "diet"
$planId = $_GET["id"] ?? "";          // plan id, or "new"
$exerciseId = $_GET["exercise"] ?? ""; // exercise id, or "new" (workout only)
$dayId = (int)($_GET["day"] ?? 0);    // which day a new exercise goes on
if ($type != "workout" && $type != "diet") {
    $type = "";
}

// turns a normal youtube link into the embed one. returns "" if it isn't one
function youtubeEmbed($url) {
    $queryPart = parse_url($url, PHP_URL_QUERY);
    parse_str($queryPart ?? "", $bits);
    if (isset($bits["v"]) && preg_match("/^[A-Za-z0-9_-]{11}$/", $bits["v"])) {
        return "https://www.youtube.com/embed/" . $bits["v"];
    }
    return "";
}

// =====================================================================
//  SAVE HANDLERS
// =====================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST["action"] ?? "";

    // ---- save a workout plan's name and description (new or existing) ----
    if ($action == "saveWorkoutPlan") {
        $targetId = $_POST["planId"];
        $name = trim($_POST["name"]);
        $description = trim($_POST["description"]);

        if ($name == "") {
            $errors["name"] = "Give the plan a name.";
        } elseif (strlen($name) > 120) {
            $errors["name"] = "Keep the name under 120 characters.";
        }

        if (count($errors) == 0) {
            if ($targetId == "new") {
                $targetId = "wp_" . uniqid();
                $stmt = $conn->prepare("INSERT INTO workout_plans (id, name, description, price) VALUES (?, ?, ?, 0)");
                $stmt->bind_param("sss", $targetId, $name, $description);
                $stmt->execute();
                $stmt->close();
                // every plan gets the full week, the owner then marks the rest days
                foreach ($dayNames as $d) {
                    $stmt = $conn->prepare("INSERT INTO workout_days (plan_id, day_name, is_rest_day) VALUES (?, ?, 0)");
                    $stmt->bind_param("ss", $targetId, $d);
                    $stmt->execute();
                    $stmt->close();
                }
                header("Location: admin-plans.php?type=workout&id=" . urlencode($targetId) . "&msg=created");
            } else {
                $stmt = $conn->prepare("UPDATE workout_plans SET name = ?, description = ? WHERE id = ?");
                $stmt->bind_param("sss", $name, $description, $targetId);
                $stmt->execute();
                $stmt->close();
                header("Location: admin-plans.php?type=workout&id=" . urlencode($targetId) . "&msg=saved");
            }
            exit;
        }
        $type = "workout";
        $planId = $targetId;
    }

    // ---- flip a day between training and rest ----
    if ($action == "toggleRest") {
        $toggleDay = (int)$_POST["dayId"];
        $stmt = $conn->prepare("UPDATE workout_days SET is_rest_day = 1 - is_rest_day WHERE id = ?");
        $stmt->bind_param("i", $toggleDay);
        $stmt->execute();
        $stmt->close();
        header("Location: admin-plans.php?type=workout&id=" . urlencode($_POST["planId"]));
        exit;
    }

    // ---- save an exercise (new or existing) with its steps and precautions ----
    if ($action == "saveExercise") {
        $targetId = $_POST["exerciseId"];
        $ownerPlan = $_POST["planId"];
        $exDay = (int)$_POST["dayId"];
        $name = trim($_POST["name"]);
        $setsLabel = trim($_POST["setsLabel"]);
        $tutorial = trim($_POST["tutorial"]);
        $youtubeUrl = trim($_POST["youtubeUrl"]);
        $gifCredit = trim($_POST["gifCredit"]);
        $sortOrder = trim($_POST["sortOrder"]);
        $stepsText = trim($_POST["steps"]);
        $precautionsText = trim($_POST["precautions"]);

        if ($name == "") {
            $errors["name"] = "Give the exercise a name.";
        }
        if ($setsLabel == "") {
            $errors["setsLabel"] = "Say how many sets or how long, e.g. 3 Sets x 12 Reps.";
        }
        if ($youtubeUrl != "" && youtubeEmbed($youtubeUrl) == "") {
            $errors["youtubeUrl"] = "That doesn't look like a YouTube video link (needs ?v=...).";
        }
        if ($sortOrder == "" || !ctype_digit($sortOrder)) {
            $errors["sortOrder"] = "Order must be a whole number.";
        }
        // the day must belong to this plan, otherwise someone could attach an exercise anywhere
        $stmt = $conn->prepare("SELECT id FROM workout_days WHERE id = ? AND plan_id = ?");
        $stmt->bind_param("is", $exDay, $ownerPlan);
        $stmt->execute();
        if ($stmt->get_result()->num_rows == 0) {
            $errors["name"] = "That day isn't on this plan.";
        }
        $stmt->close();

        if (count($errors) == 0) {
            $youtubeVal = $youtubeUrl == "" ? null : $youtubeUrl;
            if ($targetId == "new") {
                $targetId = "ex_" . uniqid();
                $stmt = $conn->prepare("INSERT INTO exercises (id, day_id, name, sets_label, tutorial, youtube_url, gif_credit, sort_order)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sisssssi", $targetId, $exDay, $name, $setsLabel, $tutorial, $youtubeVal, $gifCredit, $sortOrder);
            } else {
                $stmt = $conn->prepare("UPDATE exercises SET day_id = ?, name = ?, sets_label = ?, tutorial = ?, youtube_url = ?, gif_credit = ?, sort_order = ?
                                        WHERE id = ?");
                $stmt->bind_param("isssssis", $exDay, $name, $setsLabel, $tutorial, $youtubeVal, $gifCredit, $sortOrder, $targetId);
            }
            $stmt->execute();
            $stmt->close();

            // steps and precautions: one per line in the box. simplest is to
            // wipe and re-insert, they're tiny lists
            $stmt = $conn->prepare("DELETE FROM exercise_steps WHERE exercise_id = ?");
            $stmt->bind_param("s", $targetId);
            $stmt->execute();
            $stmt->close();
            $stepNo = 1;
            foreach (explode("\n", $stepsText) as $line) {
                $line = trim($line);
                if ($line == "") { continue; }
                $stmt = $conn->prepare("INSERT INTO exercise_steps (exercise_id, step_no, body) VALUES (?, ?, ?)");
                $stmt->bind_param("sis", $targetId, $stepNo, $line);
                $stmt->execute();
                $stmt->close();
                $stepNo++;
            }

            $stmt = $conn->prepare("DELETE FROM exercise_precautions WHERE exercise_id = ?");
            $stmt->bind_param("s", $targetId);
            $stmt->execute();
            $stmt->close();
            $pNo = 1;
            foreach (explode("\n", $precautionsText) as $line) {
                $line = trim($line);
                if ($line == "") { continue; }
                $stmt = $conn->prepare("INSERT INTO exercise_precautions (exercise_id, sort_order, body) VALUES (?, ?, ?)");
                $stmt->bind_param("sis", $targetId, $pNo, $line);
                $stmt->execute();
                $stmt->close();
                $pNo++;
            }

            header("Location: admin-plans.php?type=workout&id=" . urlencode($ownerPlan) . "&msg=exercise");
            exit;
        }
        $type = "workout";
        $planId = $ownerPlan;
        $exerciseId = $targetId;
        $dayId = $exDay;
    }

    if ($action == "deleteExercise") {
        $stmt = $conn->prepare("DELETE FROM exercises WHERE id = ?");
        $stmt->bind_param("s", $_POST["exerciseId"]);
        $stmt->execute();
        $stmt->close();
        header("Location: admin-plans.php?type=workout&id=" . urlencode($_POST["planId"]) . "&msg=exdeleted");
        exit;
    }

    // ---- save a diet plan (new or existing) ----
    if ($action == "saveDietPlan") {
        $targetId = $_POST["planId"];
        $name = trim($_POST["name"]);
        $description = trim($_POST["description"]);

        if ($name == "") {
            $errors["name"] = "Give the plan a name.";
        } elseif (strlen($name) > 120) {
            $errors["name"] = "Keep the name under 120 characters.";
        }

        if (count($errors) == 0) {
            if ($targetId == "new") {
                $targetId = "dp_" . uniqid();
                $stmt = $conn->prepare("INSERT INTO diet_plans (id, name, description) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $targetId, $name, $description);
                $stmt->execute();
                $stmt->close();
                header("Location: admin-plans.php?type=diet&id=" . urlencode($targetId) . "&msg=created");
            } else {
                $stmt = $conn->prepare("UPDATE diet_plans SET name = ?, description = ? WHERE id = ?");
                $stmt->bind_param("sss", $name, $description, $targetId);
                $stmt->execute();
                $stmt->close();
                header("Location: admin-plans.php?type=diet&id=" . urlencode($targetId) . "&msg=saved");
            }
            exit;
        }
        $type = "diet";
        $planId = $targetId;
    }

    // ---- add a meal to a diet plan ----
    if ($action == "addMeal") {
        $ownerPlan = $_POST["planId"];
        $name = trim($_POST["mealName"]);
        $description = trim($_POST["mealDescription"]);
        $mealDay = $_POST["mealDay"] ?? "";
        $sortOrder = trim($_POST["mealOrder"]);

        if ($name == "") {
            $errors["mealName"] = "Give the meal a name, e.g. Meal 1: Breakfast.";
        }
        if ($mealDay != "" && !in_array($mealDay, $dayNames)) {
            $errors["mealDay"] = "Pick a day or Every day.";
        }
        if ($sortOrder == "" || !ctype_digit($sortOrder)) {
            $errors["mealOrder"] = "Order must be a whole number.";
        }

        if (count($errors) == 0) {
            $mealId = "meal_" . uniqid();
            $dayVal = $mealDay == "" ? null : $mealDay;
            $stmt = $conn->prepare("INSERT INTO diet_meals (id, plan_id, name, description, day_name, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssi", $mealId, $ownerPlan, $name, $description, $dayVal, $sortOrder);
            $stmt->execute();
            $stmt->close();
            header("Location: admin-plans.php?type=diet&id=" . urlencode($ownerPlan) . "&msg=meal");
            exit;
        }
        $type = "diet";
        $planId = $ownerPlan;
    }

    if ($action == "deleteMeal") {
        $stmt = $conn->prepare("DELETE FROM diet_meals WHERE id = ?");
        $stmt->bind_param("s", $_POST["mealId"]);
        $stmt->execute();
        $stmt->close();
        header("Location: admin-plans.php?type=diet&id=" . urlencode($_POST["planId"]) . "&msg=mealdeleted");
        exit;
    }

    // ---- delete a whole plan. members on it are left with no plan ----
    if ($action == "deletePlan") {
        $delType = $_POST["planType"];
        $delId = $_POST["planId"];
        if ($delType == "workout") {
            $stmt = $conn->prepare("UPDATE members SET workout_plan_id = NULL WHERE workout_plan_id = ?");
            $stmt->bind_param("s", $delId);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("DELETE FROM workout_plans WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE members SET diet_plan_id = NULL WHERE diet_plan_id = ?");
            $stmt->bind_param("s", $delId);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("DELETE FROM diet_plans WHERE id = ?");
        }
        $stmt->bind_param("s", $delId);
        $stmt->execute();
        $stmt->close();
        header("Location: admin-plans.php?msg=plandeleted");
        exit;
    }
}

if (isset($_GET["msg"])) {
    $messages = [
        "created" => "Plan created. Now add the details below.",
        "saved" => "Plan saved.",
        "exercise" => "Exercise saved.",
        "exdeleted" => "Exercise removed.",
        "meal" => "Meal added.",
        "mealdeleted" => "Meal removed.",
        "plandeleted" => "Plan deleted."
    ];
    $successMsg = $messages[$_GET["msg"]] ?? "";
}

// =====================================================================
//  LOAD WHAT THE SCREEN NEEDS
// =====================================================================
$workoutPlans = [];
$dietPlans = [];
$plan = null;
$days = [];
$exercise = null;
$meals = [];

if ($type == "") {
    $result = $conn->query("SELECT p.id, p.name, p.description,
                                   (SELECT COUNT(*) FROM members m WHERE m.workout_plan_id = p.id) AS members_on
                            FROM workout_plans p ORDER BY p.name");
    while ($row = $result->fetch_assoc()) {
        $workoutPlans[] = $row;
    }
    $result = $conn->query("SELECT p.id, p.name, p.description,
                                   (SELECT COUNT(*) FROM members m WHERE m.diet_plan_id = p.id) AS members_on
                            FROM diet_plans p ORDER BY p.name");
    while ($row = $result->fetch_assoc()) {
        $dietPlans[] = $row;
    }
}

if ($type == "workout" && $planId != "") {
    if ($planId == "new") {
        $plan = ["id" => "new", "name" => $_POST["name"] ?? "", "description" => $_POST["description"] ?? ""];
    } else {
        $stmt = $conn->prepare("SELECT id, name, description FROM workout_plans WHERE id = ?");
        $stmt->bind_param("s", $planId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$plan) {
            header("Location: admin-plans.php");
            exit;
        }
        if (isset($_POST["action"]) && $_POST["action"] == "saveWorkoutPlan") {
            $plan["name"] = $_POST["name"];
            $plan["description"] = $_POST["description"];
        }

        // the week with its exercises
        $stmt = $conn->prepare("SELECT d.id, d.day_name, d.is_rest_day, e.id AS ex_id, e.name AS ex_name, e.sets_label
                                FROM workout_days d
                                LEFT JOIN exercises e ON e.day_id = d.id
                                WHERE d.plan_id = ? ORDER BY d.id, e.sort_order");
        $stmt->bind_param("s", $planId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!isset($days[$row["id"]])) {
                $days[$row["id"]] = ["id" => $row["id"], "day_name" => $row["day_name"], "rest" => $row["is_rest_day"] == 1, "exercises" => []];
            }
            if ($row["ex_id"] != "") {
                $days[$row["id"]]["exercises"][] = $row;
            }
        }
        $stmt->close();

        // one exercise being edited
        if ($exerciseId != "") {
            if ($exerciseId == "new") {
                $exercise = ["id" => "new", "day_id" => $dayId, "name" => "", "sets_label" => "", "tutorial" => "",
                             "youtube_url" => "", "gif_credit" => "", "sort_order" => "1", "steps" => "", "precautions" => ""];
            } else {
                $stmt = $conn->prepare("SELECT e.id, e.day_id, e.name, e.sets_label, e.tutorial, e.youtube_url, e.gif_credit, e.sort_order
                                        FROM exercises e JOIN workout_days d ON d.id = e.day_id
                                        WHERE e.id = ? AND d.plan_id = ?");
                $stmt->bind_param("ss", $exerciseId, $planId);
                $stmt->execute();
                $exercise = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$exercise) {
                    header("Location: admin-plans.php?type=workout&id=" . urlencode($planId));
                    exit;
                }
                $lines = [];
                $stmt = $conn->prepare("SELECT body FROM exercise_steps WHERE exercise_id = ? ORDER BY step_no");
                $stmt->bind_param("s", $exerciseId);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) { $lines[] = $row["body"]; }
                $stmt->close();
                $exercise["steps"] = implode("\n", $lines);

                $lines = [];
                $stmt = $conn->prepare("SELECT body FROM exercise_precautions WHERE exercise_id = ? ORDER BY sort_order");
                $stmt->bind_param("s", $exerciseId);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) { $lines[] = $row["body"]; }
                $stmt->close();
                $exercise["precautions"] = implode("\n", $lines);
            }
            // form came back with errors: show what they typed
            if (isset($_POST["action"]) && $_POST["action"] == "saveExercise") {
                $exercise["day_id"] = (int)$_POST["dayId"];
                $exercise["name"] = $_POST["name"];
                $exercise["sets_label"] = $_POST["setsLabel"];
                $exercise["tutorial"] = $_POST["tutorial"];
                $exercise["youtube_url"] = $_POST["youtubeUrl"];
                $exercise["gif_credit"] = $_POST["gifCredit"];
                $exercise["sort_order"] = $_POST["sortOrder"];
                $exercise["steps"] = $_POST["steps"];
                $exercise["precautions"] = $_POST["precautions"];
            }
        }
    }
}

if ($type == "diet" && $planId != "") {
    if ($planId == "new") {
        $plan = ["id" => "new", "name" => $_POST["name"] ?? "", "description" => $_POST["description"] ?? ""];
    } else {
        $stmt = $conn->prepare("SELECT id, name, description FROM diet_plans WHERE id = ?");
        $stmt->bind_param("s", $planId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$plan) {
            header("Location: admin-plans.php");
            exit;
        }
        if (isset($_POST["action"]) && $_POST["action"] == "saveDietPlan") {
            $plan["name"] = $_POST["name"];
            $plan["description"] = $_POST["description"];
        }
        $stmt = $conn->prepare("SELECT id, name, description, day_name, sort_order FROM diet_meals WHERE plan_id = ? ORDER BY sort_order, day_name");
        $stmt->bind_param("s", $planId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $meals[] = $row;
        }
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Plans - Singh Fitness Admin</title>
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

<?php if ($type == "") { ?>

    <!-- ================= LIST OF PLANS ================= -->
    <h1 class="title">Plans</h1>
    <p class="sub">Build the workout and diet plans members follow</p>

    <?php if ($successMsg != "") { ?><p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p><?php } ?>

    <h2 class="section-title">Workout plans</h2>
    <?php if (count($workoutPlans) == 0) { ?>
        <p class="empty-text">No workout plans yet.</p>
    <?php } else { ?>
        <div class="group plain" data-sq="22">
            <?php foreach ($workoutPlans as $p) { ?>
                <a href="admin-plans.php?type=workout&id=<?php echo urlencode($p["id"]); ?>" class="row link" data-tap>
                    <div class="label">
                        <?php echo htmlspecialchars($p["name"]); ?>
                        <span class="hint"><?php echo (int)$p["members_on"]; ?> member<?php echo $p["members_on"] == 1 ? "" : "s"; ?> on it &middot; <?php echo htmlspecialchars($p["description"]); ?></span>
                    </div>
                    <div class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div>
                </a>
            <?php } ?>
        </div>
    <?php } ?>
    <a href="admin-plans.php?type=workout&id=new" class="btn frost" data-press>New workout plan</a>

    <h2 class="section-title">Diet plans</h2>
    <?php if (count($dietPlans) == 0) { ?>
        <p class="empty-text">No diet plans yet.</p>
    <?php } else { ?>
        <div class="group plain" data-sq="22">
            <?php foreach ($dietPlans as $p) { ?>
                <a href="admin-plans.php?type=diet&id=<?php echo urlencode($p["id"]); ?>" class="row link" data-tap>
                    <div class="label">
                        <?php echo htmlspecialchars($p["name"]); ?>
                        <span class="hint"><?php echo (int)$p["members_on"]; ?> member<?php echo $p["members_on"] == 1 ? "" : "s"; ?> on it &middot; <?php echo htmlspecialchars($p["description"]); ?></span>
                    </div>
                    <div class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div>
                </a>
            <?php } ?>
        </div>
    <?php } ?>
    <a href="admin-plans.php?type=diet&id=new" class="btn frost" data-press>New diet plan</a>

<?php } elseif ($type == "workout" && $exerciseId != "") { ?>

    <!-- ================= ONE EXERCISE ================= -->
    <a href="admin-plans.php?type=workout&id=<?php echo urlencode($planId); ?>" class="back-link">&lsaquo; <?php echo htmlspecialchars($plan["name"]); ?></a>
    <h1 class="title"><?php echo $exerciseId == "new" ? "Add exercise" : htmlspecialchars($exercise["name"]); ?></h1>
    <p class="sub">Members see all of this on the exercise page</p>

    <form method="POST" action="admin-plans.php" id="exerciseForm" novalidate>
        <input type="hidden" name="action" value="saveExercise">
        <input type="hidden" name="planId" value="<?php echo htmlspecialchars($planId); ?>">
        <input type="hidden" name="exerciseId" value="<?php echo htmlspecialchars($exercise["id"]); ?>">

        <div class="group plain" data-sq="22">
            <div class="row">
                <span class="field-label">Day</span>
                <select name="dayId" id="dayId">
                    <?php foreach ($days as $d) { ?>
                        <option value="<?php echo $d["id"]; ?>" <?php echo $exercise["day_id"] == $d["id"] ? "selected" : ""; ?>><?php echo $d["day_name"]; ?><?php echo $d["rest"] ? " (rest day)" : ""; ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="row">
                <span class="field-label">Name</span>
                <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($exercise["name"]); ?>" placeholder="e.g. Squats">
                <?php if (isset($errors["name"])) { ?><span class="field-error"><?php echo $errors["name"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Sets / time</span>
                <input type="text" name="setsLabel" id="setsLabel" value="<?php echo htmlspecialchars($exercise["sets_label"]); ?>" placeholder="e.g. 3 Sets x 12 Reps, or 20 minutes">
                <?php if (isset($errors["setsLabel"])) { ?><span class="field-error"><?php echo $errors["setsLabel"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Order on the day (1 = first)</span>
                <input type="number" name="sortOrder" id="sortOrder" min="1" step="1" value="<?php echo htmlspecialchars($exercise["sort_order"]); ?>">
                <?php if (isset($errors["sortOrder"])) { ?><span class="field-error"><?php echo $errors["sortOrder"]; ?></span><?php } ?>
            </div>
        </div>

        <div class="group plain" data-sq="22">
            <div class="row">
                <span class="field-label">How to do it (short)</span>
                <textarea name="tutorial" id="tutorial" placeholder="One or two sentences"><?php echo htmlspecialchars($exercise["tutorial"]); ?></textarea>
            </div>
            <div class="row">
                <span class="field-label">Steps (one per line)</span>
                <textarea name="steps" id="steps" style="min-height:90px;" placeholder="Stand with feet shoulder width apart&#10;Push your hips back and bend your knees&#10;Drive through your heels to stand up"><?php echo htmlspecialchars($exercise["steps"]); ?></textarea>
            </div>
            <div class="row">
                <span class="field-label">Take care (one per line)</span>
                <textarea name="precautions" id="precautions" placeholder="Do not let your knees cave inwards"><?php echo htmlspecialchars($exercise["precautions"]); ?></textarea>
            </div>
        </div>

        <div class="group plain" data-sq="22">
            <div class="row">
                <span class="field-label">YouTube tutorial link (optional)</span>
                <input type="url" name="youtubeUrl" id="youtubeUrl" value="<?php echo htmlspecialchars($exercise["youtube_url"] ?? ""); ?>" placeholder="https://www.youtube.com/watch?v=...">
                <?php if (isset($errors["youtubeUrl"])) { ?><span class="field-error"><?php echo $errors["youtubeUrl"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Video credit, for the report references</span>
                <input type="text" name="gifCredit" id="gifCredit" value="<?php echo htmlspecialchars($exercise["gif_credit"] ?? ""); ?>" placeholder="e.g. ATHLEAN-X on YouTube">
            </div>
        </div>

        <button type="submit" class="btn frost" data-press><?php echo $exerciseId == "new" ? "Add exercise" : "Save exercise"; ?></button>
    </form>

    <?php if ($exerciseId != "new") { ?>
        <form method="POST" action="admin-plans.php"  style="margin-top:20px;">
            <input type="hidden" name="action" value="deleteExercise">
            <input type="hidden" name="planId" value="<?php echo htmlspecialchars($planId); ?>">
            <input type="hidden" name="exerciseId" value="<?php echo htmlspecialchars($exercise["id"]); ?>">
            <button type="submit" class="confirm-btn btn-outline" style="color:var(--red); border-color:var(--red);">Remove exercise</button>
        </form>
    <?php } ?>

<?php } elseif ($type == "workout") { ?>

    <!-- ================= ONE WORKOUT PLAN ================= -->
    <a href="admin-plans.php" class="back-link">&lsaquo; All plans</a>
    <h1 class="title"><?php echo $planId == "new" ? "New workout plan" : htmlspecialchars($plan["name"]); ?></h1>
    <p class="sub">Name it, mark the rest days, then add exercises to each day</p>

    <?php if ($successMsg != "") { ?><p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p><?php } ?>

    <form method="POST" action="admin-plans.php" id="planForm" novalidate>
        <input type="hidden" name="action" value="saveWorkoutPlan">
        <input type="hidden" name="planId" value="<?php echo htmlspecialchars($plan["id"]); ?>">
        <div class="group plain" data-sq="22">
            <div class="row">
                <span class="field-label">Plan name</span>
                <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($plan["name"]); ?>" placeholder="e.g. Beginner Full Body">
                <?php if (isset($errors["name"])) { ?><span class="field-error"><?php echo $errors["name"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Description (members see this)</span>
                <textarea name="description" id="description" placeholder="Who it's for and what it focuses on"><?php echo htmlspecialchars($plan["description"]); ?></textarea>
            </div>
        </div>
        <button type="submit" class="btn frost" data-press><?php echo $planId == "new" ? "Create plan" : "Save name and description"; ?></button>
    </form>

    <?php if ($planId != "new") { ?>
        <?php foreach ($days as $d) { ?>
            <h2 class="section-title">
                <?php echo $d["day_name"]; ?>
                <?php if ($d["rest"]) { ?><span class="pill">Rest day</span><?php } ?>
            </h2>
            <?php if (!$d["rest"]) { ?>
                <?php if (count($d["exercises"]) == 0) { ?>
                    <p class="empty-text">No exercises yet.</p>
                <?php } else { ?>
                    <div class="group plain" data-sq="22">
                        <?php foreach ($d["exercises"] as $e) { ?>
                            <a href="admin-plans.php?type=workout&id=<?php echo urlencode($planId); ?>&exercise=<?php echo urlencode($e["ex_id"]); ?>" class="row link" data-tap>
                                <div class="label">
                                    <?php echo htmlspecialchars($e["ex_name"]); ?>
                                    <span class="hint"><?php echo htmlspecialchars($e["sets_label"]); ?></span>
                                </div>
                                <div class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div>
                            </a>
                        <?php } ?>
                    </div>
                <?php } ?>
            <?php } ?>
            <div class="two-col">
                <?php if (!$d["rest"]) { ?>
                    <a href="admin-plans.php?type=workout&id=<?php echo urlencode($planId); ?>&exercise=new&day=<?php echo $d["id"]; ?>" class="btn frost small" data-press style="width:auto;">Add exercise</a>
                <?php } else { ?>
                    <span></span>
                <?php } ?>
                <form method="POST" action="admin-plans.php">
                    <input type="hidden" name="action" value="toggleRest">
                    <input type="hidden" name="planId" value="<?php echo htmlspecialchars($planId); ?>">
                    <input type="hidden" name="dayId" value="<?php echo $d["id"]; ?>">
                    <button type="submit" class="btn-outline small" style="width:100%; padding:9px 12px; font-size:13px; margin:0;"><?php echo $d["rest"] ? "Make it a training day" : "Make it a rest day"; ?></button>
                </form>
            </div>
        <?php } ?>

        <form method="POST" action="admin-plans.php"  style="margin-top:28px;">
            <input type="hidden" name="action" value="deletePlan">
            <input type="hidden" name="planType" value="workout">
            <input type="hidden" name="planId" value="<?php echo htmlspecialchars($planId); ?>">
            <button type="submit" class="confirm-btn btn-outline" style="color:var(--red); border-color:var(--red);">Delete this plan</button>
        </form>
    <?php } ?>

<?php } elseif ($type == "diet") { ?>

    <!-- ================= ONE DIET PLAN ================= -->
    <a href="admin-plans.php" class="back-link">&lsaquo; All plans</a>
    <h1 class="title"><?php echo $planId == "new" ? "New diet plan" : htmlspecialchars($plan["name"]); ?></h1>
    <p class="sub">Name it, then add the meals. A meal can be every day or one day only</p>

    <?php if ($successMsg != "") { ?><p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p><?php } ?>

    <form method="POST" action="admin-plans.php" id="planForm" novalidate>
        <input type="hidden" name="action" value="saveDietPlan">
        <input type="hidden" name="planId" value="<?php echo htmlspecialchars($plan["id"]); ?>">
        <div class="group plain" data-sq="22">
            <div class="row">
                <span class="field-label">Plan name</span>
                <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($plan["name"]); ?>" placeholder="e.g. High Protein Cut">
                <?php if (isset($errors["name"])) { ?><span class="field-error"><?php echo $errors["name"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Description (members see this)</span>
                <textarea name="description" id="description" placeholder="Calories, who it suits"><?php echo htmlspecialchars($plan["description"]); ?></textarea>
            </div>
        </div>
        <button type="submit" class="btn frost" data-press><?php echo $planId == "new" ? "Create plan" : "Save name and description"; ?></button>
    </form>

    <?php if ($planId != "new") { ?>
        <h2 class="section-title">Meals <span class="muted"><?php echo count($meals); ?></span></h2>
        <?php if (count($meals) == 0) { ?>
            <p class="empty-text">No meals yet. Add the first one below.</p>
        <?php } else { ?>
            <div class="group plain" data-sq="22">
                <?php foreach ($meals as $m) { ?>
                    <div class="row split">
                        <div class="label">
                            <?php echo htmlspecialchars($m["name"]); ?>
                            <span class="pill"><?php echo $m["day_name"] != "" ? htmlspecialchars($m["day_name"]) : "Every day"; ?></span>
                            <span class="hint"><?php echo htmlspecialchars($m["description"]); ?> &middot; order <?php echo (int)$m["sort_order"]; ?></span>
                        </div>
                        <form method="POST" action="admin-plans.php" >
                            <input type="hidden" name="action" value="deleteMeal">
                            <input type="hidden" name="planId" value="<?php echo htmlspecialchars($planId); ?>">
                            <input type="hidden" name="mealId" value="<?php echo htmlspecialchars($m["id"]); ?>">
                            <button type="submit" class="confirm-btn btn-outline small" style="width:auto; padding:6px 10px; font-size:12px; margin:0;">Remove</button>
                        </form>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>

        <h2 class="section-title">Add a meal</h2>
        <form method="POST" action="admin-plans.php" id="mealForm" novalidate>
            <input type="hidden" name="action" value="addMeal">
            <input type="hidden" name="planId" value="<?php echo htmlspecialchars($planId); ?>">
            <div class="group plain" data-sq="22">
                <div class="row">
                    <span class="field-label">Meal name</span>
                    <input type="text" name="mealName" id="mealName" value="<?php echo htmlspecialchars($_POST["mealName"] ?? ""); ?>" placeholder="e.g. Meal 1: Breakfast">
                    <?php if (isset($errors["mealName"])) { ?><span class="field-error"><?php echo $errors["mealName"]; ?></span><?php } ?>
                </div>
                <div class="row">
                    <span class="field-label">What to eat</span>
                    <textarea name="mealDescription" id="mealDescription" placeholder="e.g. Oats with milk, one banana"><?php echo htmlspecialchars($_POST["mealDescription"] ?? ""); ?></textarea>
                </div>
                <div class="row">
                    <span class="field-label">Which day</span>
                    <select name="mealDay" id="mealDay">
                        <option value="">Every day</option>
                        <?php foreach ($dayNames as $dn) { ?>
                            <option value="<?php echo $dn; ?>" <?php echo ($_POST["mealDay"] ?? "") == $dn ? "selected" : ""; ?>><?php echo $dn; ?> only</option>
                        <?php } ?>
                    </select>
                </div>
                <div class="row">
                    <span class="field-label">Order in the day (1 = first)</span>
                    <input type="number" name="mealOrder" id="mealOrder" min="1" step="1" value="<?php echo htmlspecialchars($_POST["mealOrder"] ?? (count($meals) + 1)); ?>">
                    <?php if (isset($errors["mealOrder"])) { ?><span class="field-error"><?php echo $errors["mealOrder"]; ?></span><?php } ?>
                </div>
            </div>
            <button type="submit" class="btn frost" data-press>Add meal</button>
        </form>

        <form method="POST" action="admin-plans.php"  style="margin-top:28px;">
            <input type="hidden" name="action" value="deletePlan">
            <input type="hidden" name="planType" value="diet">
            <input type="hidden" name="planId" value="<?php echo htmlspecialchars($planId); ?>">
            <button type="submit" class="confirm-btn btn-outline" style="color:var(--red); border-color:var(--red);">Delete this plan</button>
        </form>
    <?php } ?>

<?php } ?>

    <footer class="site-footer">
        <p>&copy; <?php echo date("Y"); ?> Singh Fitness Gym. Owner area.</p>
        <p><a href="admin-dashboard.php">Dashboard</a> &middot; <a href="admin-settings.php">Settings</a> &middot; <a href="logout.php">Logout</a></p>
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
            <button class="tab" data-i="0" data-href="admin-dashboard.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h7v6H4zM13 5h7v4h-7zM13 11h7v8h-7zM4 13h7v6H4z"/></svg><span class="t">Dashboard</span>
            </button>
            <button class="tab" data-i="1" data-href="admin-members.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.2 2.7-5.3 6-5.3s6 2.1 6 5.3"/><circle cx="17" cy="9" r="2.4"/><path d="M15.5 14.4c2.8.2 5 2 5 4.6"/></svg><span class="t">Members</span>
            </button>
            <button class="tab on" data-i="2" data-href="admin-plans.php">
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

// plan name form (workout and diet share this)
var planForm = document.getElementById("planForm");
if (planForm) {
    planForm.addEventListener("submit", function (e) {
        clearErrors(planForm);
        var name = document.getElementById("name");
        if (name.value.trim() == "") {
            showError(name, "Give the plan a name.");
            e.preventDefault();
        } else if (name.value.length > 120) {
            showError(name, "Keep the name under 120 characters.");
            e.preventDefault();
        }
    });
}

var exerciseForm = document.getElementById("exerciseForm");
if (exerciseForm) {
    exerciseForm.addEventListener("submit", function (e) {
        clearErrors(exerciseForm);
        var ok = true;

        var name = document.getElementById("name");
        if (name.value.trim() == "") {
            showError(name, "Give the exercise a name.");
            ok = false;
        }
        var setsLabel = document.getElementById("setsLabel");
        if (setsLabel.value.trim() == "") {
            showError(setsLabel, "Say how many sets or how long.");
            ok = false;
        }
        var sortOrder = document.getElementById("sortOrder");
        if (!/^[0-9]+$/.test(sortOrder.value.trim())) {
            showError(sortOrder, "Order must be a whole number.");
            ok = false;
        }
        var youtubeUrl = document.getElementById("youtubeUrl");
        if (youtubeUrl.value.trim() != "" && !/[?&]v=[A-Za-z0-9_-]{11}/.test(youtubeUrl.value)) {
            showError(youtubeUrl, "Paste the normal YouTube link, the one with ?v= in it.");
            ok = false;
        }
        if (!ok) {
            e.preventDefault();
        }
    });
}

var mealForm = document.getElementById("mealForm");
if (mealForm) {
    mealForm.addEventListener("submit", function (e) {
        clearErrors(mealForm);
        var ok = true;
        var mealName = document.getElementById("mealName");
        if (mealName.value.trim() == "") {
            showError(mealName, "Give the meal a name.");
            ok = false;
        }
        var mealOrder = document.getElementById("mealOrder");
        if (!/^[0-9]+$/.test(mealOrder.value.trim())) {
            showError(mealOrder, "Order must be a whole number.");
            ok = false;
        }
        if (!ok) {
            e.preventDefault();
        }
    });
}
</script>

</body>
</html>
