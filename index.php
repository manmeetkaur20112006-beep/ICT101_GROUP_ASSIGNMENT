<?php
require_once "config.php";

// the public front page. anyone already logged in goes straight to their area
if (isset($_SESSION["memberId"])) {
    header("Location: " . ($_SESSION["role"] == "admin" ? "admin-dashboard.php" : "home.php"));
    exit;
}

// same membership plans the sign-up page offers
$memberships = [
    ["name" => "Basic Gym Access", "price" => "1000", "blurb" => "Weights floor and machines, any shift."],
    ["name" => "Full Gym + Cardio", "price" => "1700", "blurb" => "Everything in Basic plus the cardio room."],
    ["name" => "Premium", "price" => "2500", "blurb" => "Gym, cardio and personal training sessions."]
];

$workoutPlans = [];
$result = $conn->query("SELECT name, description FROM workout_plans ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $workoutPlans[] = $row;
}

$dietPlans = [];
$result = $conn->query("SELECT name, description FROM diet_plans ORDER BY name");
while ($row = $result->fetch_assoc()) {
    $dietPlans[] = $row;
}

$notices = [];
$result = $conn->query("SELECT title, content, posted_on FROM announcements ORDER BY important DESC, posted_on DESC LIMIT 2");
while ($row = $result->fetch_assoc()) {
    $notices[] = $row;
}

$memberCount = (int)$conn->query("SELECT COUNT(*) AS n FROM members WHERE role = 'member'")->fetch_assoc()["n"];
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Singh Fitness Gym</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?php echo filemtime("assets/css/base.css"); ?>">
<link rel="stylesheet" href="assets/css/shared.css?v=<?php echo filemtime("assets/css/shared.css"); ?>">
</head>
<body>

<header class="topbar">
    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Singh Fitness logo" width="140" height="42">
    <a href="login.php" class="btn frost small" data-press style="margin:0;">Log in</a>
</header>

<div class="page">

    <h1 class="title">Train with us</h1>
    <p class="sub">A friendly local gym with morning and evening shifts, real trainers and a plan built for you.</p>

    <a href="register.php" class="btn" data-press>Join Singh Fitness</a>
    <p class="hint" style="text-align:center;">Sign up online, pay by UPI, and the owner activates you the same day.</p>

    <!-- ===== MEMBERSHIPS ===== -->
    <h2 class="section-title">Memberships</h2>
    <?php foreach ($memberships as $m) { ?>
        <div class="plan-option" data-sq="22">
            <div class="row kv" style="padding:0;">
                <span>
                    <strong><?php echo htmlspecialchars($m["name"]); ?></strong>
                    <span class="hint"><?php echo htmlspecialchars($m["blurb"]); ?></span>
                </span>
                <span class="val gold">&#8377;<?php echo $m["price"]; ?><span class="hint" style="display:inline;">/month</span></span>
            </div>
        </div>
    <?php } ?>
    <p class="hint" style="text-align:center;">Pay for 1, 2 or 3 months at a time.</p>

    <!-- ===== WHAT YOU GET ===== -->
    <h2 class="section-title">What members get</h2>
    <div class="group plain" data-sq="22">
        <div class="row">
            A workout plan for every day of the week
            <span class="hint">Tick off exercises, watch the tutorial video for each one, ask for a change any time.</span>
        </div>
        <div class="row">
            A diet plan to match
            <span class="hint">Meals for each day, ticked off as you go.</span>
        </div>
        <div class="row">
            Progress tracking
            <span class="hint">Weight chart, attendance calendar, streaks, points and a leaderboard.</span>
        </div>
        <div class="row">
            Book a trainer
            <span class="hint">Personal training, diet consultations and body measurements.</span>
        </div>
    </div>

    <!-- ===== PLANS ===== -->
    <h2 class="section-title">Workout plans</h2>
    <div class="group plain" data-sq="22">
        <?php foreach ($workoutPlans as $p) { ?>
            <div class="row">
                <?php echo htmlspecialchars($p["name"]); ?>
                <span class="hint"><?php echo htmlspecialchars($p["description"]); ?></span>
            </div>
        <?php } ?>
    </div>

    <h2 class="section-title">Diet plans</h2>
    <div class="group plain" data-sq="22">
        <?php foreach ($dietPlans as $p) { ?>
            <div class="row">
                <?php echo htmlspecialchars($p["name"]); ?>
                <span class="hint"><?php echo htmlspecialchars($p["description"]); ?></span>
            </div>
        <?php } ?>
    </div>

    <!-- ===== HOURS ===== -->
    <h2 class="section-title">Opening hours</h2>
    <div class="group plain" data-sq="22">
        <div class="row kv"><span>Morning shift</span><span class="val">6:00 am &ndash; 10:00 am</span></div>
        <div class="row kv"><span>Evening shift</span><span class="val">5:00 pm &ndash; 9:00 pm</span></div>
        <div class="row kv"><span>Members</span><span class="val"><?php echo $memberCount; ?> and counting</span></div>
    </div>

    <?php if (count($notices) > 0) { ?>
        <h2 class="section-title">Notices</h2>
        <?php foreach ($notices as $n) { ?>
            <div class="notice">
                <h3><?php echo htmlspecialchars($n["title"]); ?></h3>
                <p><?php echo nl2br(htmlspecialchars($n["content"])); ?></p>
                <div class="meta"><?php echo date("j M Y", strtotime($n["posted_on"])); ?></div>
            </div>
        <?php } ?>
    <?php } ?>

    <a href="register.php" class="btn frost" data-press style="margin-top:20px;">Join now</a>
    <p class="link-line">Already a member? <a href="login.php">Log in</a> &middot; Got a question? <a href="feedback.php">Send feedback</a></p>

    <footer class="site-footer">
        <p>&copy; <?php echo date("Y"); ?> Singh Fitness Gym. All rights reserved.</p>
        <p><a href="login.php">Login</a> &middot; <a href="register.php">Register</a> &middot; <a href="feedback.php">Feedback</a></p>
    </footer>

</div>

</body>
</html>
