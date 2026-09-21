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

$stmt = $conn->prepare("SELECT name, member_code FROM members WHERE id = ?");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

// the search word comes in the address bar (GET) so the result page can be
// bookmarked or refreshed. nothing is changed by searching, so GET is fine.
$q = isset($_GET["q"]) ? trim($_GET["q"]) : "";
$searched = false;
$tooShort = false;

$exercises = [];
$workoutPlans = [];
$dietPlans = [];
$meals = [];
$announcements = [];
$members = [];

if ($q != "") {
    $searched = true;
    if (strlen($q) < 2) {
        $tooShort = true;
    } else {
        // % either side so it matches anywhere in the text
        $like = "%" . $q . "%";

        $stmt = $conn->prepare("SELECT e.id, e.name, e.sets_label, d.day_name, p.name AS plan_name
                                FROM exercises e
                                JOIN workout_days d ON d.id = e.day_id
                                JOIN workout_plans p ON p.id = d.plan_id
                                WHERE e.name LIKE ? OR e.tutorial LIKE ?
                                ORDER BY e.name LIMIT 20");
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $exercises[] = $row;
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT name, description FROM workout_plans WHERE name LIKE ? OR description LIKE ? ORDER BY name LIMIT 10");
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $workoutPlans[] = $row;
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT name, description FROM diet_plans WHERE name LIKE ? OR description LIKE ? ORDER BY name LIMIT 10");
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $dietPlans[] = $row;
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT dm.name, dm.description, dm.day_name, p.name AS plan_name
                                FROM diet_meals dm JOIN diet_plans p ON p.id = dm.plan_id
                                WHERE dm.name LIKE ? OR dm.description LIKE ?
                                ORDER BY dm.name LIMIT 20");
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $meals[] = $row;
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT title, content, posted_on FROM announcements WHERE title LIKE ? OR content LIKE ? ORDER BY posted_on DESC LIMIT 10");
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $announcements[] = $row;
        }
        $stmt->close();

        // other members: name and code only, never phone or email
        $stmt = $conn->prepare("SELECT name, member_code, points FROM members
                                WHERE role = 'member' AND status IN ('Active', 'Expiring Soon')
                                  AND (name LIKE ? OR member_code LIKE ?)
                                ORDER BY name LIMIT 10");
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $members[] = $row;
        }
        $stmt->close();
    }
}

$totalHits = count($exercises) + count($workoutPlans) + count($dietPlans) + count($meals) + count($announcements) + count($members);

// wraps the matched word in <mark> so it stands out. escapes first, then marks.
function highlight($text, $q) {
    $safe = htmlspecialchars($text);
    if ($q == "") {
        return $safe;
    }
    return preg_replace("/" . preg_quote(htmlspecialchars($q), "/") . "/i", "<mark>$0</mark>", $safe);
}
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Search - Singh Fitness</title>
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
    <h1 class="title">Search</h1>
    <p class="sub">Exercises, plans, meals, notices and members</p>

    <form method="GET" action="search.php" id="searchForm" novalidate>
        <div class="group plain" data-sq="22">
            <div class="row">
                <div class="inline-form">
                    <input type="search" name="q" id="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="e.g. squat, protein, holiday" autofocus>
                    <button type="submit" class="btn frost small" data-press>Search</button>
                </div>
                <?php if ($tooShort) { ?><span class="field-error">Type at least 2 letters.</span><?php } ?>
            </div>
        </div>
    </form>

    <?php if ($searched && !$tooShort) { ?>

        <p class="sub" style="margin-top:6px;"><?php echo $totalHits; ?> result<?php echo $totalHits == 1 ? "" : "s"; ?> for "<?php echo htmlspecialchars($q); ?>"</p>

        <?php if ($totalHits == 0) { ?>
            <p class="empty-text">Nothing matched. Try a shorter or different word.</p>
        <?php } ?>

        <?php if (count($exercises) > 0) { ?>
            <h2 class="section-title">Exercises</h2>
            <div class="group plain" data-sq="22">
                <?php foreach ($exercises as $e) { ?>
                    <a href="exercise.php?id=<?php echo urlencode($e["id"]); ?>" class="row link" data-tap>
                        <div class="label">
                            <?php echo highlight($e["name"], $q); ?>
                            <span class="hint"><?php echo htmlspecialchars($e["sets_label"]); ?> &middot; <?php echo htmlspecialchars($e["day_name"]); ?> &middot; <?php echo htmlspecialchars($e["plan_name"]); ?></span>
                        </div>
                        <div class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div>
                    </a>
                <?php } ?>
            </div>
        <?php } ?>

        <?php if (count($workoutPlans) > 0) { ?>
            <h2 class="section-title">Workout plans</h2>
            <div class="group plain" data-sq="22">
                <?php foreach ($workoutPlans as $p) { ?>
                    <a href="fitness.php?panel=request" class="row link" data-tap>
                        <div class="label">
                            <?php echo highlight($p["name"], $q); ?>
                            <span class="hint"><?php echo highlight($p["description"], $q); ?></span>
                        </div>
                        <div class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div>
                    </a>
                <?php } ?>
            </div>
        <?php } ?>

        <?php if (count($dietPlans) > 0) { ?>
            <h2 class="section-title">Diet plans</h2>
            <div class="group plain" data-sq="22">
                <?php foreach ($dietPlans as $p) { ?>
                    <a href="fitness.php?panel=request" class="row link" data-tap>
                        <div class="label">
                            <?php echo highlight($p["name"], $q); ?>
                            <span class="hint"><?php echo highlight($p["description"], $q); ?></span>
                        </div>
                        <div class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></div>
                    </a>
                <?php } ?>
            </div>
        <?php } ?>

        <?php if (count($meals) > 0) { ?>
            <h2 class="section-title">Meals</h2>
            <div class="group plain" data-sq="22">
                <?php foreach ($meals as $m) { ?>
                    <div class="row">
                        <?php echo highlight($m["name"], $q); ?>
                        <span class="hint">
                            <?php echo highlight($m["description"], $q); ?><br>
                            <?php echo $m["day_name"] != "" ? htmlspecialchars($m["day_name"]) . " only" : "Every day"; ?> &middot; <?php echo htmlspecialchars($m["plan_name"]); ?>
                        </span>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>

        <?php if (count($announcements) > 0) { ?>
            <h2 class="section-title">Announcements</h2>
            <?php foreach ($announcements as $a) { ?>
                <div class="notice">
                    <h3><?php echo highlight($a["title"], $q); ?></h3>
                    <p><?php echo highlight($a["content"], $q); ?></p>
                    <div class="meta"><?php echo date("j M Y", strtotime($a["posted_on"])); ?></div>
                </div>
            <?php } ?>
        <?php } ?>

        <?php if (count($members) > 0) { ?>
            <h2 class="section-title">Members</h2>
            <div class="group plain" data-sq="22">
                <?php foreach ($members as $m) { ?>
                    <div class="row split">
                        <div class="label">
                            <?php echo highlight($m["name"], $q); ?>
                            <span class="hint"><?php echo highlight($m["member_code"], $q); ?></span>
                        </div>
                        <span class="pts"><?php echo (int)$m["points"]; ?> pts</span>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>

    <?php } elseif (!$searched) { ?>
        <p class="empty-text">Type a word above to search the whole site.</p>
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
document.getElementById("searchForm").addEventListener("submit", function (e) {
    var form = this;
    var q = document.getElementById("q");
    var old = form.querySelector(".field-error.js-error");
    if (old) {
        old.remove();
    }
    if (q.value.trim().length < 2) {
        var span = document.createElement("span");
        span.className = "field-error js-error";
        span.textContent = "Type at least 2 letters.";
        q.parentNode.parentNode.appendChild(span);
        e.preventDefault();
    }
});
</script>

</body>
</html>
