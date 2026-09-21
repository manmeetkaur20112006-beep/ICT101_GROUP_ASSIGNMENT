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
$exerciseId = $_GET["id"] ?? ($_POST["exerciseId"] ?? "");

$stmt = $conn->prepare("SELECT name, member_code, workout_plan_id FROM members WHERE id = ?");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

// the exercise plus which day and plan it belongs to
$stmt = $conn->prepare("SELECT e.id, e.name, e.sets_label, e.tutorial, e.youtube_url, e.gif_path, e.gif_credit,
                               d.day_name, d.plan_id, p.name AS plan_name
                        FROM exercises e
                        JOIN workout_days d ON d.id = e.day_id
                        JOIN workout_plans p ON p.id = d.plan_id
                        WHERE e.id = ?");
$stmt->bind_param("s", $exerciseId);
$stmt->execute();
$exercise = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$exercise) {
    header("Location: fitness.php");
    exit;
}

// can only tick it off if it's on my plan and scheduled for today
$canTick = $exercise["plan_id"] == $member["workout_plan_id"] && $exercise["day_name"] == date("l");

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["toggleDone"]) && $canTick) {
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

    // come back as a normal visit so refresh doesn't toggle it again
    header("Location: exercise.php?id=" . urlencode($exerciseId));
    exit;
}

$stmt = $conn->prepare("SELECT id FROM exercise_completions WHERE member_id = ? AND exercise_id = ? AND done_on = CURDATE()");
$stmt->bind_param("ss", $memberId, $exerciseId);
$stmt->execute();
$isDone = $stmt->get_result()->num_rows > 0;
$stmt->close();

$steps = [];
$stmt = $conn->prepare("SELECT body FROM exercise_steps WHERE exercise_id = ? ORDER BY step_no");
$stmt->bind_param("s", $exerciseId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $steps[] = $row["body"];
}
$stmt->close();

$precautions = [];
$stmt = $conn->prepare("SELECT body FROM exercise_precautions WHERE exercise_id = ? ORDER BY sort_order");
$stmt->bind_param("s", $exerciseId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $precautions[] = $row["body"];
}
$stmt->close();

// turn a normal youtube link into the embed link the iframe needs
$embedUrl = "";
if ($exercise["youtube_url"] != "") {
    $queryPart = parse_url($exercise["youtube_url"], PHP_URL_QUERY);
    parse_str($queryPart ?? "", $queryBits);
    if (isset($queryBits["v"]) && preg_match("/^[A-Za-z0-9_-]{11}$/", $queryBits["v"])) {
        $embedUrl = "https://www.youtube.com/embed/" . $queryBits["v"];
    }
}
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo htmlspecialchars($exercise["name"]); ?> - Singh Fitness</title>
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

    <a href="fitness.php" class="back-link">&lsaquo; Back to fitness</a>

    <h1 class="title"><?php echo htmlspecialchars($exercise["name"]); ?></h1>
    <p class="sub">
        <?php echo htmlspecialchars($exercise["sets_label"]); ?>
        &middot; <?php echo htmlspecialchars($exercise["day_name"]); ?>
        &middot; <?php echo htmlspecialchars($exercise["plan_name"]); ?>
    </p>

    <?php if ($embedUrl != "") { ?>
        <div class="video" data-sq="22">
            <iframe src="<?php echo htmlspecialchars($embedUrl); ?>" title="Tutorial video for <?php echo htmlspecialchars($exercise["name"]); ?>" allowfullscreen loading="lazy"></iframe>
        </div>
        <?php if ($exercise["gif_credit"] != "") { ?>
            <p class="credit">Video: <?php echo htmlspecialchars($exercise["gif_credit"]); ?></p>
        <?php } ?>
    <?php } elseif ($exercise["gif_path"] != "") { ?>
        <div class="video" data-sq="22">
            <img src="<?php echo htmlspecialchars($exercise["gif_path"]); ?>" alt="Demo of <?php echo htmlspecialchars($exercise["name"]); ?>">
        </div>
        <?php if ($exercise["gif_credit"] != "") { ?>
            <p class="credit">Animation: <?php echo htmlspecialchars($exercise["gif_credit"]); ?></p>
        <?php } ?>
    <?php } ?>

    <?php if ($canTick) { ?>
        <form method="POST" action="exercise.php">
            <input type="hidden" name="exerciseId" value="<?php echo htmlspecialchars($exercise["id"]); ?>">
            <button type="submit" name="toggleDone" value="1" class="btn <?php echo $isDone ? "frost" : ""; ?>" data-press>
                <?php echo $isDone ? "Done for today. Tap to undo" : "Mark as done for today"; ?>
            </button>
        </form>
    <?php } ?>

    <?php if ($exercise["tutorial"] != "") { ?>
        <h2 class="section-title">How to do it</h2>
        <div class="group plain" data-sq="22">
            <div class="row"><?php echo nl2br(htmlspecialchars($exercise["tutorial"])); ?></div>
        </div>
    <?php } ?>

    <?php if (count($steps) > 0) { ?>
        <h2 class="section-title">Steps</h2>
        <div class="group plain" data-sq="22">
            <div class="row">
                <ol>
                    <?php foreach ($steps as $step) { ?>
                        <li><?php echo htmlspecialchars($step); ?></li>
                    <?php } ?>
                </ol>
            </div>
        </div>
    <?php } ?>

    <?php if (count($precautions) > 0) { ?>
        <h2 class="section-title">Take care</h2>
        <div class="group plain" data-sq="22">
            <div class="row">
                <ul>
                    <?php foreach ($precautions as $p) { ?>
                        <li><?php echo htmlspecialchars($p); ?></li>
                    <?php } ?>
                </ul>
            </div>
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

</body>
</html>
