<?php
require_once "config.php";

// Anyone can leave feedback. Logged-in members get their name filled in and
// see what they've sent before. Visitors just type their name and email.

$isMember = isset($_SESSION["memberId"]) && $_SESSION["role"] == "member";
if (isset($_SESSION["memberId"]) && $_SESSION["role"] == "admin") {
    header("Location: admin-dashboard.php");
    exit;
}

$errors = [];
$successMsg = "";

$name = "";
$email = "";
$subject = "";
$message = "";
$rating = "";

$member = null;
if ($isMember) {
    $stmt = $conn->prepare("SELECT name, email, member_code FROM members WHERE id = ?");
    $stmt->bind_param("s", $_SESSION["memberId"]);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $name = $member["name"];
    $email = $member["email"];
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!$isMember) {
        $name = trim($_POST["name"]);
        $email = trim($_POST["email"]);
    }
    $subject = trim($_POST["subject"]);
    $message = trim($_POST["message"]);
    $rating = $_POST["rating"] ?? "";

    if ($name == "") {
        $errors["name"] = "Please enter your name.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors["email"] = "Please enter a valid email address.";
    }
    if ($subject == "") {
        $errors["subject"] = "Please give it a subject.";
    } elseif (strlen($subject) > 200) {
        $errors["subject"] = "Keep the subject under 200 characters.";
    }
    if (strlen($message) < 10) {
        $errors["message"] = "Please write at least 10 characters.";
    } elseif (strlen($message) > 2000) {
        $errors["message"] = "Keep it under 2000 characters.";
    }
    if ($rating != "" && !in_array($rating, ["1", "2", "3", "4", "5"])) {
        $errors["rating"] = "Rating should be 1 to 5.";
    }

    if (count($errors) == 0) {
        $memberIdVal = $isMember ? $_SESSION["memberId"] : null;
        $ratingVal = $rating == "" ? null : (int)$rating;

        $stmt = $conn->prepare("INSERT INTO feedback (member_id, name, email, subject, message, rating) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssi", $memberIdVal, $name, $email, $subject, $message, $ratingVal);
        $stmt->execute();
        $stmt->close();

        header("Location: feedback.php?sent=1");
        exit;
    }
}

if (isset($_GET["sent"])) {
    $successMsg = "Thanks, your feedback has been sent to the gym.";
}

$myFeedback = [];
if ($isMember) {
    $stmt = $conn->prepare("SELECT subject, rating, created_at, seen_by_admin FROM feedback WHERE member_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->bind_param("s", $_SESSION["memberId"]);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $myFeedback[] = $row;
    }
    $stmt->close();
}
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Feedback - Singh Fitness</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?php echo filemtime("assets/css/base.css"); ?>">
<link rel="stylesheet" href="assets/css/shared.css?v=<?php echo filemtime("assets/css/shared.css"); ?>">
</head>
<body class="<?php echo $isMember ? "has-tabbar" : ""; ?>">

<?php if ($isMember) { ?>
    <header class="topbar">
        <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Singh Fitness logo" width="140" height="42">
        <div class="who">
            <?php echo htmlspecialchars($member["name"]); ?><br>
            <?php echo htmlspecialchars($member["member_code"]); ?>
        </div>
    </header>
<?php } ?>

<div class="page">

    <?php if ($isMember) { ?>
        <a href="profile.php" class="back-link">&lsaquo; Profile</a>
    <?php } else { ?>
        <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Singh Fitness logo" class="site-logo" width="160" height="48">
    <?php } ?>

    <h1 class="title">Feedback</h1>
    <p class="sub">Tell us what's working and what isn't</p>

    <?php if ($successMsg != "") { ?>
        <p class="success-text"><?php echo $successMsg; ?></p>
    <?php } ?>

    <form method="POST" action="feedback.php" id="feedbackForm" novalidate>
        <div class="group plain" data-sq="22">
            <?php if ($isMember) { ?>
                <div class="row">
                    <span class="field-label">From</span>
                    <?php echo htmlspecialchars($name); ?> &middot; <span class="hint" style="display:inline;"><?php echo htmlspecialchars($email); ?></span>
                </div>
            <?php } else { ?>
                <div class="row">
                    <span class="field-label">Your name</span>
                    <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($name); ?>">
                    <?php if (isset($errors["name"])) { ?><span class="field-error"><?php echo $errors["name"]; ?></span><?php } ?>
                </div>
                <div class="row">
                    <span class="field-label">Your email</span>
                    <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($email); ?>">
                    <?php if (isset($errors["email"])) { ?><span class="field-error"><?php echo $errors["email"]; ?></span><?php } ?>
                </div>
            <?php } ?>
            <div class="row">
                <span class="field-label">Subject</span>
                <input type="text" name="subject" id="subject" value="<?php echo htmlspecialchars($subject); ?>" placeholder="e.g. Music too loud in the evening">
                <?php if (isset($errors["subject"])) { ?><span class="field-error"><?php echo $errors["subject"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Message</span>
                <textarea name="message" id="message" placeholder="What happened, and what would make it better?" style="min-height:110px;"><?php echo htmlspecialchars($message); ?></textarea>
                <?php if (isset($errors["message"])) { ?><span class="field-error"><?php echo $errors["message"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">How would you rate the gym? (optional)</span>
                <div class="stars">
                    <?php for ($s = 1; $s <= 5; $s++) { ?>
                        <input type="radio" name="rating" id="star<?php echo $s; ?>" value="<?php echo $s; ?>" <?php echo $rating == $s ? "checked" : ""; ?>>
                        <label for="star<?php echo $s; ?>"><?php echo $s; ?></label>
                    <?php } ?>
                </div>
                <?php if (isset($errors["rating"])) { ?><span class="field-error"><?php echo $errors["rating"]; ?></span><?php } ?>
            </div>
        </div>

        <button type="submit" class="btn frost" data-press>Send feedback</button>
    </form>

    <?php if ($isMember && count($myFeedback) > 0) { ?>
        <h2 class="section-title">What you've sent</h2>
        <div class="group plain" data-sq="22">
            <?php foreach ($myFeedback as $f) { ?>
                <div class="row">
                    <?php echo htmlspecialchars($f["subject"]); ?>
                    <?php if ($f["rating"] != "") { ?><span class="pill"><?php echo $f["rating"]; ?>/5</span><?php } ?>
                    <?php if ($f["seen_by_admin"]) { ?><span class="pill good">Seen</span><?php } ?>
                    <span class="hint"><?php echo date("j M Y", strtotime($f["created_at"])); ?></span>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if (!$isMember) { ?>
        <p class="link-line">Member? <a href="login.php">Log in</a> so we know who it's from.</p>
    <?php } ?>

    <footer class="site-footer">
        <p>&copy; <?php echo date("Y"); ?> Singh Fitness Gym. All rights reserved.</p>
        <?php if ($isMember) { ?>
            <p><a href="bookings.php">Bookings</a> &middot; <a href="profile.php">My profile</a> &middot; <a href="logout.php">Logout</a></p>
        <?php } else { ?>
            <p><a href="login.php">Login</a> &middot; <a href="register.php">Register</a></p>
        <?php } ?>
    </footer>

</div>

<?php if ($isMember) { ?>
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
<?php } ?>
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

document.getElementById("feedbackForm").addEventListener("submit", function (e) {
    var form = this;
    clearErrors(form);
    var ok = true;

    var name = document.getElementById("name");
    if (name && name.value.trim() == "") {
        showError(name, "Please enter your name.");
        ok = false;
    }

    var email = document.getElementById("email");
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
        showError(email, "Please enter a valid email address.");
        ok = false;
    }

    var subject = document.getElementById("subject");
    if (subject.value.trim() == "") {
        showError(subject, "Please give it a subject.");
        ok = false;
    } else if (subject.value.length > 200) {
        showError(subject, "Keep the subject under 200 characters.");
        ok = false;
    }

    var message = document.getElementById("message");
    if (message.value.trim().length < 10) {
        showError(message, "Please write at least 10 characters.");
        ok = false;
    } else if (message.value.length > 2000) {
        showError(message, "Keep it under 2000 characters.");
        ok = false;
    }

    if (!ok) {
        e.preventDefault();
    }
});
</script>

</body>
</html>
