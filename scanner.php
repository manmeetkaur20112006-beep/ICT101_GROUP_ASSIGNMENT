<?php
require_once "config.php";

// Member check-in. The gym has one fixed QR code stuck at the entrance.
// A member scans it (or types the code) and this page marks them present.
//
// Safety rules (do not loosen these):
//  - the member id comes from the session only, never from the browser
//  - today's date comes from the server, never from the browser
//  - the code is checked against gym_config on the server before saving
//  - one row per member per day, a second scan changes nothing
//  - nothing happens on a plain page visit, only on a POST

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
$result = "";

// is today a rest day on this member's workout plan? no check-ins on rest days
$todayName = date("l");
$stmt = $conn->prepare("SELECT d.id FROM workout_days d
                        JOIN members m ON m.workout_plan_id = d.plan_id
                        WHERE m.id = ? AND d.day_name = ? AND d.is_rest_day = 1");
$stmt->bind_param("ss", $memberId, $todayName);
$stmt->execute();
$isRestDay = $stmt->get_result()->num_rows > 0;
$stmt->close();

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["code"])) {
    $code = trim($_POST["code"]);

    $configRow = $conn->query("SELECT attend_token FROM gym_config WHERE id = 1")->fetch_assoc();

    if ($isRestDay) {
        $result = "rest";
    } elseif ($code == "" || $code !== $configRow["attend_token"]) {
        $result = "wrong";
    } else {
        $stmt = $conn->prepare("SELECT id FROM member_attendance WHERE member_id = ? AND attended_on = CURDATE()");
        $stmt->bind_param("s", $memberId);
        $stmt->execute();
        $alreadyIn = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($alreadyIn) {
            $result = "already";
        } else {
            $stmt = $conn->prepare("INSERT INTO member_attendance (member_id, attended_on) VALUES (?, CURDATE())");
            $stmt->bind_param("s", $memberId);
            $stmt->execute();
            $stmt->close();

            // +100 points, logged so it shows where the points came from
            $checkinPoints = 100;
            $reason = "checkin";
            $stmt = $conn->prepare("INSERT INTO points_log (member_id, reason, points, awarded_on) VALUES (?, ?, ?, CURDATE())");
            $stmt->bind_param("ssi", $memberId, $reason, $checkinPoints);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE members SET points = points + ? WHERE id = ?");
            $stmt->bind_param("is", $checkinPoints, $memberId);
            $stmt->execute();
            $stmt->close();

            $result = "ok";
        }
    }

    header("Location: scanner.php?result=" . $result);
    exit;
}

if (isset($_GET["result"]) && in_array($_GET["result"], ["ok", "already", "wrong", "rest"])) {
    $result = $_GET["result"];
}

$stmt = $conn->prepare("SELECT name, member_code, points FROM members WHERE id = ?");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT scanned_at FROM member_attendance WHERE member_id = ? AND attended_on = CURDATE()");
$stmt->bind_param("s", $memberId);
$stmt->execute();
$todayRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$checkedInToday = $todayRow ? true : false;

$streak = getStreak($conn, $memberId);
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Check in - Singh Fitness</title>
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

    <a href="home.php" class="back-link">&lsaquo; Home</a>
    <h1 class="title">Check in</h1>
    <p class="sub">Scan the QR code at the gym entrance</p>

    <?php if ($result == "ok") { ?>
        <div class="result-box" data-sq="22">
            <div class="big">&#10003;</div>
            <h2>You're checked in</h2>
            <p>+100 points. Streak is now <?php echo $streak; ?> day<?php echo $streak == 1 ? "" : "s"; ?>. Have a good session.</p>
        </div>
    <?php } elseif ($result == "already") { ?>
        <div class="result-box" data-sq="22">
            <div class="big">&#128075;</div>
            <h2>Already checked in today</h2>
            <p>Nothing changed. Come back tomorrow to keep the streak going.</p>
        </div>
    <?php } elseif ($result == "wrong") { ?>
        <div class="result-box" data-sq="22">
            <div class="big">&#10007;</div>
            <h2>That code isn't right</h2>
            <p>Make sure you're scanning the Singh Fitness code at the entrance, or ask at reception.</p>
        </div>
    <?php } ?>

    <?php if ($isRestDay && !$checkedInToday) { ?>

        <div class="result-box" data-sq="22">
            <div class="big">&#128164;</div>
            <h2>Rest day</h2>
            <p><?php echo $todayName; ?> is a rest day on your plan, so there's nothing to check in for. Your streak is safe at <?php echo $streak; ?> day<?php echo $streak == 1 ? "" : "s"; ?>.</p>
        </div>
        <a href="home.php" class="btn frost" data-press>Back to home</a>

    <?php } elseif ($checkedInToday) { ?>

        <div class="stat-grid">
            <div class="stat good" data-sq="22">
                <div class="num">Yes</div>
                <div class="lbl">Checked in at <?php echo date("g:i a", strtotime($todayRow["scanned_at"])); ?></div>
            </div>
            <div class="stat gold" data-sq="22">
                <div class="num"><?php echo $streak; ?></div>
                <div class="lbl">Day streak</div>
            </div>
        </div>
        <a href="home.php" class="btn frost" data-press>Back to home</a>

    <?php } else { ?>

        <div class="cam" id="cam" data-sq="22">
            <video id="video" playsinline muted></video>
            <div class="frame"></div>
            <div class="msg" id="camMsg">Starting camera&hellip;</div>
        </div>

        <!-- the scan and the typed code both go through this same form -->
        <form method="POST" action="scanner.php" id="codeForm" novalidate>
            <div class="group plain" data-sq="22">
                <div class="row">
                    <span class="field-label">Or type the code from the poster</span>
                    <input type="text" name="code" id="code" placeholder="e.g. singh-fitness-gym-attend-v1" autocomplete="off">
                </div>
            </div>
            <button type="submit" class="btn frost" data-press>Check in</button>
        </form>
        <p class="hint" style="text-align:center;">One check-in per day. Points and streak only count real visits.</p>

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
var codeForm = document.getElementById("codeForm");
if (codeForm) {
    // typed code: just make sure it isn't empty before sending
    codeForm.addEventListener("submit", function (e) {
        var code = document.getElementById("code");
        var old = codeForm.querySelector(".field-error.js-error");
        if (old) {
            old.remove();
        }
        if (code.value.trim() == "") {
            var span = document.createElement("span");
            span.className = "field-error js-error";
            span.textContent = "Type the code from the poster, or scan it above.";
            code.parentNode.appendChild(span);
            e.preventDefault();
        }
    });

    // camera scanning, plain browser features only.
    // The browser reads the QR with its built-in BarcodeDetector. When it
    // finds one we drop the text into the same form and submit it, so the
    // server does exactly the same checks as a typed code.
    var video = document.getElementById("video");
    var camMsg = document.getElementById("camMsg");
    var sent = false;

    function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            camMsg.textContent = "This browser can't use the camera here. Type the code below instead.";
            return;
        }
        if (!("BarcodeDetector" in window)) {
            camMsg.textContent = "This browser can't read QR codes. Type the code below instead.";
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
            .then(function (stream) {
                video.srcObject = stream;
                video.play();
                camMsg.textContent = "Point the camera at the QR code";

                var detector = new BarcodeDetector({ formats: ["qr_code"] });
                var timer = setInterval(function () {
                    if (sent || video.readyState < 2) {
                        return;
                    }
                    detector.detect(video).then(function (codes) {
                        if (codes.length > 0 && !sent) {
                            sent = true;
                            clearInterval(timer);
                            camMsg.textContent = "Code found, checking in…";
                            document.getElementById("code").value = codes[0].rawValue;
                            codeForm.submit();
                        }
                    }).catch(function () {
                        // a frame failed to read, try again on the next tick
                    });
                }, 400);
            })
            .catch(function () {
                camMsg.textContent = "Camera blocked. Allow it in the browser, or type the code below.";
            });
    }

    startCamera();
}
</script>

</body>
</html>
