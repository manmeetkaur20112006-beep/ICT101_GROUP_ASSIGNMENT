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

// ---- log today's weight ----
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["weightKg"])) {
    $weightKg = $_POST["weightKg"];

    if (!is_numeric($weightKg) || $weightKg < 20 || $weightKg > 300) {
        $errors["weight"] = "Enter a weight between 20 and 300 kg.";
    } else {
        // one entry per day. logging again today just replaces it
        $stmt = $conn->prepare("INSERT INTO member_weights (member_id, logged_on, weight) VALUES (?, CURDATE(), ?)
                                ON DUPLICATE KEY UPDATE weight = ?");
        $stmt->bind_param("sdd", $memberId, $weightKg, $weightKg);
        $stmt->execute();
        $stmt->close();

        // keep the member's current weight up to date too
        $stmt = $conn->prepare("UPDATE members SET weight = ? WHERE id = ?");
        $stmt->bind_param("ds", $weightKg, $memberId);
        $stmt->execute();
        $stmt->close();

        header("Location: progress.php?saved=1");
        exit;
    }
}
if (isset($_GET["saved"])) {
    $successMsg = "Weight saved.";
}

$stmt = $conn->prepare("SELECT name, member_code, weight, target_weight, points, workout_plan_id FROM members WHERE id = ?");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---- weight history, oldest first, last 30 entries ----
$weights = [];
$stmt = $conn->prepare("SELECT logged_on, weight FROM member_weights WHERE member_id = ? ORDER BY logged_on DESC LIMIT 30");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $weights[] = $row;
}
$stmt->close();
$weights = array_reverse($weights);

$weightChange = null;
if (count($weights) >= 2) {
    $weightChange = $weights[count($weights) - 1]["weight"] - $weights[0]["weight"];
}

// ---- build the chart as an SVG picture ----
// plain HTML5 SVG drawn by hand, no chart library (assignment rule)
$chartSvg = "";
if (count($weights) >= 2) {
    $chartW = 320;
    $chartH = 170;
    $padLeft = 34;
    $padRight = 12;
    $padTop = 14;
    $padBottom = 26;

    $values = array_column($weights, "weight");
    $minW = min($values);
    $maxW = max($values);
    if ($member["target_weight"] > 0) {
        $minW = min($minW, $member["target_weight"]);
        $maxW = max($maxW, $member["target_weight"]);
    }
    // a little breathing room above and below so the line isn't on the edge
    $minW = floor($minW - 1);
    $maxW = ceil($maxW + 1);
    if ($maxW == $minW) {
        $maxW = $minW + 2;
    }

    $plotW = $chartW - $padLeft - $padRight;
    $plotH = $chartH - $padTop - $padBottom;
    $count = count($weights);

    $points = [];
    foreach ($weights as $i => $w) {
        $x = $padLeft + ($plotW * $i / ($count - 1));
        $y = $padTop + $plotH - (($w["weight"] - $minW) / ($maxW - $minW)) * $plotH;
        $points[] = [round($x, 1), round($y, 1)];
    }

    $lineD = "";
    foreach ($points as $p) {
        $lineD .= ($lineD == "" ? "M" : " L") . $p[0] . " " . $p[1];
    }
    $fillD = $lineD . " L" . $points[$count - 1][0] . " " . ($padTop + $plotH) . " L" . $points[0][0] . " " . ($padTop + $plotH) . " Z";

    $chartSvg .= '<svg viewBox="0 0 ' . $chartW . ' ' . $chartH . '" role="img" aria-label="Weight over time">';

    // three faint guide lines with the weight written on the left
    for ($g = 0; $g <= 2; $g++) {
        $gy = $padTop + $plotH * $g / 2;
        $gw = $maxW - ($maxW - $minW) * $g / 2;
        $chartSvg .= '<line class="grid" x1="' . $padLeft . '" y1="' . $gy . '" x2="' . ($chartW - $padRight) . '" y2="' . $gy . '"/>';
        $chartSvg .= '<text class="axis" x="' . ($padLeft - 6) . '" y="' . ($gy + 3) . '" text-anchor="end">' . number_format($gw, 0) . '</text>';
    }

    if ($member["target_weight"] > 0) {
        $ty = $padTop + $plotH - (($member["target_weight"] - $minW) / ($maxW - $minW)) * $plotH;
        $chartSvg .= '<line class="target" x1="' . $padLeft . '" y1="' . round($ty, 1) . '" x2="' . ($chartW - $padRight) . '" y2="' . round($ty, 1) . '"/>';
    }

    $chartSvg .= '<path class="fill" d="' . $fillD . '"/>';
    $chartSvg .= '<path class="line" d="' . $lineD . '"/>';
    foreach ($points as $p) {
        $chartSvg .= '<circle class="dot" cx="' . $p[0] . '" cy="' . $p[1] . '" r="3"/>';
    }

    // first and last date underneath
    $chartSvg .= '<text class="axis" x="' . $padLeft . '" y="' . ($chartH - 8) . '">' . date("j M", strtotime($weights[0]["logged_on"])) . '</text>';
    $chartSvg .= '<text class="axis" x="' . ($chartW - $padRight) . '" y="' . ($chartH - 8) . '" text-anchor="end">' . date("j M", strtotime($weights[$count - 1]["logged_on"])) . '</text>';
    $chartSvg .= '</svg>';
}

// ---- this month's attendance ----
$year = date("Y");
$month = date("n");
$daysInMonth = date("t");
$firstWeekday = date("N", strtotime("$year-$month-01"));   // 1 = Monday
$todayNum = (int)date("j");

$wentDays = [];
$stmt = $conn->prepare("SELECT DAY(attended_on) AS d FROM member_attendance
                        WHERE member_id = ? AND YEAR(attended_on) = ? AND MONTH(attended_on) = ?");
$stmt->bind_param("sii", $memberId, $year, $month);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $wentDays[] = (int)$row["d"];
}
$stmt->close();

$restDayNames = [];
$stmt = $conn->prepare("SELECT day_name FROM workout_days WHERE plan_id = ? AND is_rest_day = 1");
$stmt->bind_param("s", $member["workout_plan_id"]);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $restDayNames[] = $row["day_name"];
}
$stmt->close();

$streak = getStreak($conn, $memberId);

// ---- leaderboard: top 10 active members by points ----
$leaders = [];
$result = $conn->query("SELECT id, name, member_code, points FROM members
                        WHERE role = 'member' AND status IN ('Active', 'Expiring Soon')
                        ORDER BY points DESC, name ASC LIMIT 10");
while ($row = $result->fetch_assoc()) {
    $row["streak"] = getStreak($conn, $row["id"]);
    $leaders[] = $row;
}

// where do I sit if I'm not in the top 10
$myRank = null;
foreach ($leaders as $i => $l) {
    if ($l["id"] == $memberId) {
        $myRank = $i + 1;
    }
}
if ($myRank === null) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS ahead FROM members
                            WHERE role = 'member' AND status IN ('Active', 'Expiring Soon') AND points > ?");
    $stmt->bind_param("i", $member["points"]);
    $stmt->execute();
    $myRank = (int)$stmt->get_result()->fetch_assoc()["ahead"] + 1;
    $stmt->close();
}
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Progress - Singh Fitness</title>
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

    <h1 class="title">Progress</h1>
    <p class="sub">Weight, attendance and where you stand</p>

    <?php if ($successMsg != "") { ?>
        <p class="success-text"><?php echo $successMsg; ?></p>
    <?php } ?>

    <!-- ===== WEIGHT ===== -->
    <h2 class="section-title">
        Weight
        <?php if ($weightChange !== null) { ?>
            <span class="muted"><?php echo $weightChange > 0 ? "+" : ""; ?><?php echo number_format($weightChange, 1); ?> kg since <?php echo date("j M", strtotime($weights[0]["logged_on"])); ?></span>
        <?php } ?>
    </h2>

    <?php if ($chartSvg != "") { ?>
        <div class="chart-box" data-sq="22">
            <?php echo $chartSvg; ?>
            <?php if ($member["target_weight"] > 0) { ?>
                <div class="legend">
                    <span><i style="background:var(--blue);"></i>Your weight</span>
                    <span><i style="background:var(--gold);"></i>Target <?php echo number_format($member["target_weight"], 1); ?> kg</span>
                </div>
            <?php } ?>
        </div>
    <?php } elseif (count($weights) == 1) { ?>
        <p class="empty-text">One entry so far (<?php echo number_format($weights[0]["weight"], 1); ?> kg). Log again another day and the chart will appear.</p>
    <?php } else { ?>
        <p class="empty-text">No weight logged yet. Add today's below to start your chart.</p>
    <?php } ?>

    <div class="group plain" data-sq="22">
        <div class="row">
            <div class="row-label">
                Today's weight (kg)
                <?php if ($member["weight"] > 0) { ?>&middot; last <?php echo number_format($member["weight"], 1); ?> kg<?php } ?>
            </div>
            <form method="POST" action="progress.php" class="inline-form" id="weightForm" novalidate>
                <input type="number" name="weightKg" id="weightKg" step="0.1" min="20" max="300" placeholder="e.g. 72.5">
                <button type="submit" class="btn frost small" data-press>Save</button>
            </form>
            <?php if (isset($errors["weight"])) { ?><span class="field-error"><?php echo $errors["weight"]; ?></span><?php } ?>
        </div>
    </div>

    <!-- ===== ATTENDANCE ===== -->
    <h2 class="section-title">
        <?php echo date("F Y"); ?>
        <span class="muted"><?php echo count($wentDays); ?> visit<?php echo count($wentDays) == 1 ? "" : "s"; ?> &middot; <?php echo $streak; ?> day streak</span>
    </h2>

    <div class="cal" data-sq="22">
        <div class="head">
            <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
        </div>
        <div class="days">
            <?php for ($blank = 1; $blank < $firstWeekday; $blank++) { ?>
                <span class="d"></span>
            <?php } ?>
            <?php for ($d = 1; $d <= $daysInMonth; $d++) { ?>
                <?php
                $dayName = date("l", strtotime("$year-$month-$d"));
                $classes = "d";
                if (in_array($d, $wentDays)) {
                    $classes .= " went";
                } elseif ($d > $todayNum) {
                    $classes .= " future";
                } elseif (in_array($dayName, $restDayNames)) {
                    $classes .= " rest";
                }
                if ($d == $todayNum) {
                    $classes .= " today";
                }
                ?>
                <span class="<?php echo $classes; ?>"><?php echo $d; ?></span>
            <?php } ?>
        </div>
        <div class="legend">
            <span><i style="background:var(--green);"></i>Went</span>
            <span><i style="box-shadow:inset 0 0 0 1.5px var(--blue);"></i>Today</span>
            <span><i style="background:var(--line);"></i>Rest day</span>
        </div>
    </div>

    <!-- ===== LEADERBOARD ===== -->
    <h2 class="section-title">
        Leaderboard
        <span class="muted">you're #<?php echo $myRank; ?> with <?php echo (int)$member["points"]; ?> pts</span>
    </h2>

    <?php if (count($leaders) == 0) { ?>
        <p class="empty-text">No members on the board yet.</p>
    <?php } else { ?>
        <div class="group plain" data-sq="22">
            <?php foreach ($leaders as $i => $l) { ?>
                <div class="row link <?php echo $l["id"] == $memberId ? "me" : ""; ?>">
                    <span class="rank <?php echo $i < 3 ? "top" : ""; ?>"><?php echo $i + 1; ?></span>
                    <div class="label">
                        <?php echo htmlspecialchars($l["name"]); ?><?php if ($l["id"] == $memberId) { ?> <span class="badge">You</span><?php } ?>
                        <span class="hint"><?php echo $l["streak"]; ?> day streak</span>
                    </div>
                    <span class="pts"><?php echo (int)$l["points"]; ?></span>
                </div>
            <?php } ?>
        </div>
        <p class="hint" style="text-align:center;">Points come from checking in at the gym. 100 per visit.</p>
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
            <button class="tab on" data-i="2">
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
document.getElementById("weightForm").addEventListener("submit", function (e) {
    var form = this;
    var old = form.parentNode.querySelector(".field-error.js-error");
    if (old) {
        old.remove();
    }
    var value = document.getElementById("weightKg").value.trim();
    var message = "";
    if (value == "" || isNaN(value)) {
        message = "Please enter your weight as a number.";
    } else if (Number(value) < 20 || Number(value) > 300) {
        message = "Weight must be between 20 and 300 kg.";
    }
    if (message != "") {
        var span = document.createElement("span");
        span.className = "field-error js-error";
        span.textContent = message;
        form.parentNode.appendChild(span);
        e.preventDefault();
    }
});
</script>

</body>
</html>
