<?php
require_once "config.php";

// Owner only
if (!isset($_SESSION["memberId"]) || $_SESSION["role"] != "admin") {
    header("Location: login.php");
    exit;
}

$errors = [];
$successMsg = "";
$msgSection = "";

// saves an uploaded picture into uploads/ and returns its path, or "" with an error.
// only real image files, under 2 MB. the file is renamed so nothing odd gets through
function saveImage($file, $baseName, &$errorOut) {
    if (!isset($file) || $file["error"] == UPLOAD_ERR_NO_FILE) {
        return "";
    }
    if ($file["error"] != UPLOAD_ERR_OK) {
        $errorOut = "The upload didn't work, please try again.";
        return "";
    }
    if ($file["size"] > 2 * 1024 * 1024) {
        $errorOut = "Keep the image under 2 MB.";
        return "";
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file["tmp_name"]);
    finfo_close($finfo);
    $allowed = ["image/png" => "png", "image/jpeg" => "jpg", "image/webp" => "webp"];
    if (!isset($allowed[$mime])) {
        $errorOut = "Please upload a PNG, JPG or WebP image.";
        return "";
    }
    $path = "uploads/" . $baseName . "-" . time() . "." . $allowed[$mime];
    if (!move_uploaded_file($file["tmp_name"], $path)) {
        $errorOut = "Couldn't save the file. Check the uploads folder can be written to.";
        return "";
    }
    return $path;
}

$config = $conn->query("SELECT upi_id, qr_code_url, logo_url, attend_token FROM gym_config WHERE id = 1")->fetch_assoc();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST["action"] ?? "";

    // ---- payment details ----
    if ($action == "savePayment") {
        $upiId = trim($_POST["upiId"]);
        if ($upiId == "" || !preg_match("/^[A-Za-z0-9.\-_]{2,}@[A-Za-z0-9\-]{2,}$/", $upiId)) {
            $errors["upiId"] = "UPI ID looks like name@bank, e.g. pay@singh-fitness.";
        }
        $qrError = "";
        $qrPath = saveImage($_FILES["qrImage"] ?? null, "payment-qr", $qrError);
        if ($qrError != "") {
            $errors["qrImage"] = $qrError;
        }
        if (count($errors) == 0) {
            if ($qrPath != "") {
                $stmt = $conn->prepare("UPDATE gym_config SET upi_id = ?, qr_code_url = ? WHERE id = 1");
                $stmt->bind_param("ss", $upiId, $qrPath);
            } else {
                $stmt = $conn->prepare("UPDATE gym_config SET upi_id = ? WHERE id = 1");
                $stmt->bind_param("s", $upiId);
            }
            $stmt->execute();
            $stmt->close();
            header("Location: admin-settings.php?msg=Payment+details+saved.&at=payment");
            exit;
        }
        $msgSection = "payment";
        $config["upi_id"] = $upiId;
    }

    // ---- new check-in code. old posters stop working, so the button asks first ----
    if ($action == "newToken") {
        $newToken = "singh-fitness-" . bin2hex(random_bytes(6));
        $stmt = $conn->prepare("UPDATE gym_config SET attend_token = ? WHERE id = 1");
        $stmt->bind_param("s", $newToken);
        $stmt->execute();
        $stmt->close();
        header("Location: admin-settings.php?msg=New+code+made.+Print+it+and+replace+the+poster.&at=checkin");
        exit;
    }

    // ---- announcements ----
    if ($action == "addAnnouncement") {
        $title = trim($_POST["title"]);
        $content = trim($_POST["content"]);
        $important = isset($_POST["important"]) ? 1 : 0;

        if ($title == "") {
            $errors["title"] = "Give it a title.";
        } elseif (strlen($title) > 200) {
            $errors["title"] = "Keep the title under 200 characters.";
        }
        if (strlen($content) < 5) {
            $errors["content"] = "Write a bit more than that.";
        }
        if (count($errors) == 0) {
            $annId = "ann_" . uniqid();
            $author = $_SESSION["memberName"];
            $stmt = $conn->prepare("INSERT INTO announcements (id, posted_on, author, title, content, important) VALUES (?, CURDATE(), ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $annId, $author, $title, $content, $important);
            $stmt->execute();
            $stmt->close();
            header("Location: admin-settings.php?msg=Announcement+posted.&at=announcements");
            exit;
        }
        $msgSection = "announcements";
    }

    if ($action == "deleteAnnouncement") {
        $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->bind_param("s", $_POST["announcementId"]);
        $stmt->execute();
        $stmt->close();
        header("Location: admin-settings.php?msg=Announcement+removed.&at=announcements");
        exit;
    }

    if ($action == "toggleImportant") {
        $stmt = $conn->prepare("UPDATE announcements SET important = 1 - important WHERE id = ?");
        $stmt->bind_param("s", $_POST["announcementId"]);
        $stmt->execute();
        $stmt->close();
        header("Location: admin-settings.php?at=announcements");
        exit;
    }

    // ---- owner password ----
    if ($action == "savePassword") {
        $currentPassword = $_POST["currentPassword"];
        $newPassword = $_POST["newPassword"];
        $confirmPassword = $_POST["confirmPassword"];

        $stmt = $conn->prepare("SELECT password_hash FROM members WHERE id = ?");
        $stmt->bind_param("s", $_SESSION["memberId"]);
        $stmt->execute();
        $hashRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!password_verify($currentPassword, $hashRow["password_hash"])) {
            $errors["currentPassword"] = "That isn't the current password.";
        }
        if (strlen($newPassword) < 8) {
            $errors["newPassword"] = "New password must be at least 8 characters.";
        }
        if ($newPassword !== $confirmPassword) {
            $errors["confirmPassword"] = "Passwords do not match.";
        }
        if (count($errors) == 0) {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE members SET password_hash = ? WHERE id = ?");
            $stmt->bind_param("ss", $newHash, $_SESSION["memberId"]);
            $stmt->execute();
            $stmt->close();
            header("Location: admin-settings.php?msg=Password+changed.&at=account");
            exit;
        }
        $msgSection = "account";
    }
}

if (isset($_GET["msg"])) {
    $successMsg = $_GET["msg"];
    $msgSection = $_GET["at"] ?? "";
}

$announcements = [];
$result = $conn->query("SELECT id, title, content, important, posted_on FROM announcements ORDER BY posted_on DESC, created_at DESC");
while ($row = $result->fetch_assoc()) {
    $announcements[] = $row;
}

// the check-in QR is drawn from the code in the settings table
$checkinQr = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=0&data=" . urlencode($config["attend_token"]);
$paymentQr = $config["qr_code_url"] != "" ? $config["qr_code_url"] : "assets/img/qr-placeholder.svg";
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Settings - Singh Fitness Admin</title>
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

    <h1 class="title">Settings</h1>
    <p class="sub">Payment, the check-in poster, notices and your account</p>

    <!-- ===== CHECK-IN QR ===== -->
    <h2 class="section-title">Check-in poster</h2>
    <?php if ($msgSection == "checkin" && $successMsg != "") { ?><p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p><?php } ?>
    <div class="qr-box print-area" data-sq="22">
        <img src="<?php echo htmlspecialchars($checkinQr); ?>" alt="Check-in QR code" width="180" height="180">
        <p class="upi-id">Scan to check in</p>
        <p class="hint" style="margin:6px 0 0;">Singh Fitness Gym &middot; code: <?php echo htmlspecialchars($config["attend_token"]); ?></p>
    </div>
    <div class="two-col">
        <button type="button" class="btn frost" id="printBtn" data-press>Print poster</button>
        <form method="POST" action="admin-settings.php" >
            <input type="hidden" name="action" value="newToken">
            <button type="submit" class="confirm-btn btn-outline" style="width:100%;">New code</button>
        </form>
    </div>
    <p class="hint" style="text-align:center;">Print this and stick it at the entrance. Members scan it, or type the code, to check in.</p>

    <!-- ===== PAYMENT ===== -->
    <h2 class="section-title">Payment</h2>
    <?php if ($msgSection == "payment" && $successMsg != "") { ?><p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p><?php } ?>
    <form method="POST" action="admin-settings.php" id="paymentForm" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="action" value="savePayment">
        <div class="qr-box" data-sq="22">
            <img src="<?php echo htmlspecialchars($paymentQr); ?>" alt="Payment QR code" width="120" height="120">
            <p class="hint" style="margin:8px 0 0;">This is what new members see on the sign-up page.</p>
        </div>
        <div class="group plain" data-sq="22">
            <div class="row">
                <span class="field-label">UPI ID</span>
                <input type="text" name="upiId" id="upiId" value="<?php echo htmlspecialchars($config["upi_id"]); ?>" placeholder="pay@singh-fitness">
                <?php if (isset($errors["upiId"])) { ?><span class="field-error"><?php echo $errors["upiId"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Replace payment QR image (PNG or JPG, under 2 MB)</span>
                <input type="file" name="qrImage" id="qrImage" accept="image/png,image/jpeg,image/webp">
                <?php if (isset($errors["qrImage"])) { ?><span class="field-error"><?php echo $errors["qrImage"]; ?></span><?php } ?>
            </div>
        </div>
        <button type="submit" class="btn frost" data-press>Save payment details</button>
    </form>

    <!-- ===== ANNOUNCEMENTS ===== -->
    <h2 class="section-title">Announcements <span class="muted"><?php echo count($announcements); ?></span></h2>
    <?php if ($msgSection == "announcements" && $successMsg != "") { ?><p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p><?php } ?>
    <form method="POST" action="admin-settings.php" id="announcementForm" novalidate>
        <input type="hidden" name="action" value="addAnnouncement">
        <div class="group plain" data-sq="22">
            <div class="row">
                <span class="field-label">Title</span>
                <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($_POST["title"] ?? ""); ?>" placeholder="e.g. Closed on Monday for maintenance">
                <?php if (isset($errors["title"])) { ?><span class="field-error"><?php echo $errors["title"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <span class="field-label">Message</span>
                <textarea name="content" id="content" placeholder="What members need to know"><?php echo htmlspecialchars($_POST["content"] ?? ""); ?></textarea>
                <?php if (isset($errors["content"])) { ?><span class="field-error"><?php echo $errors["content"]; ?></span><?php } ?>
            </div>
            <div class="row checkbox-row">
                <input type="checkbox" name="important" id="important" <?php echo isset($_POST["important"]) ? "checked" : ""; ?>>
                <label for="important">Important (shows at the top with a gold edge)</label>
            </div>
        </div>
        <button type="submit" class="btn frost" data-press>Post announcement</button>
    </form>

    <?php if (count($announcements) > 0) { ?>
        <div style="margin-top:16px;">
        <?php foreach ($announcements as $a) { ?>
            <div class="notice <?php echo $a["important"] ? "important" : ""; ?>">
                <h3><?php echo htmlspecialchars($a["title"]); ?></h3>
                <p><?php echo nl2br(htmlspecialchars($a["content"])); ?></p>
                <div class="meta"><?php echo date("j M Y", strtotime($a["posted_on"])); ?></div>
                <div class="actions" style="margin-top:8px;">
                    <form method="POST" action="admin-settings.php">
                        <input type="hidden" name="action" value="toggleImportant">
                        <input type="hidden" name="announcementId" value="<?php echo htmlspecialchars($a["id"]); ?>">
                        <button type="submit" class="btn-outline small"><?php echo $a["important"] ? "Not important" : "Mark important"; ?></button>
                    </form>
                    <form method="POST" action="admin-settings.php" >
                        <input type="hidden" name="action" value="deleteAnnouncement">
                        <input type="hidden" name="announcementId" value="<?php echo htmlspecialchars($a["id"]); ?>">
                        <button type="submit" class="confirm-btn btn-outline small" style="color:var(--red); border-color:var(--red);">Remove</button>
                    </form>
                </div>
            </div>
        <?php } ?>
        </div>
    <?php } ?>

    <!-- ===== ACCOUNT ===== -->
    <h2 class="section-title">Your account</h2>
    <?php if ($msgSection == "account" && $successMsg != "") { ?><p class="success-text"><?php echo htmlspecialchars($successMsg); ?></p><?php } ?>
    <form method="POST" action="admin-settings.php" id="passwordForm" novalidate>
        <input type="hidden" name="action" value="savePassword">
        <div class="group plain" data-sq="22">
            <div class="row">
                <input type="password" name="currentPassword" id="currentPassword" placeholder="Current password">
                <?php if (isset($errors["currentPassword"])) { ?><span class="field-error"><?php echo $errors["currentPassword"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <input type="password" name="newPassword" id="newPassword" placeholder="New password (min 8 characters)">
                <?php if (isset($errors["newPassword"])) { ?><span class="field-error"><?php echo $errors["newPassword"]; ?></span><?php } ?>
            </div>
            <div class="row">
                <input type="password" name="confirmPassword" id="confirmPassword" placeholder="Confirm new password">
                <?php if (isset($errors["confirmPassword"])) { ?><span class="field-error"><?php echo $errors["confirmPassword"]; ?></span><?php } ?>
            </div>
        </div>
        <button type="submit" class="btn frost" data-press>Change password</button>
    </form>

    <a href="logout.php" class="btn-outline" style="text-align:center; display:block; text-decoration:none; margin-top:24px;">Log out</a>

    <footer class="site-footer">
        <p>&copy; <?php echo date("Y"); ?> Singh Fitness Gym. Owner area.</p>
        <p><a href="admin-dashboard.php">Dashboard</a> &middot; <a href="admin-plans.php">Plans</a> &middot; <a href="logout.php">Logout</a></p>
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
            <button class="tab" data-i="2" data-href="admin-plans.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 6.5h11v11h-11z"/><path d="M3 9v6M21 9v6"/></svg><span class="t">Plans</span>
            </button>
            <button class="tab on" data-i="3" data-href="admin-settings.php">
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

// only real image files, and not huge ones. the server checks again anyway
function checkImage(input) {
    if (input.files.length == 0) {
        return true;
    }
    var file = input.files[0];
    if (["image/png", "image/jpeg", "image/webp"].indexOf(file.type) == -1) {
        showError(input, "Please choose a PNG, JPG or WebP image.");
        return false;
    }
    if (file.size > 2 * 1024 * 1024) {
        showError(input, "Keep the image under 2 MB.");
        return false;
    }
    return true;
}

document.getElementById("paymentForm").addEventListener("submit", function (e) {
    clearErrors(this);
    var ok = true;
    var upiId = document.getElementById("upiId");
    if (!/^[A-Za-z0-9.\-_]{2,}@[A-Za-z0-9\-]{2,}$/.test(upiId.value.trim())) {
        showError(upiId, "UPI ID looks like name@bank.");
        ok = false;
    }
    if (!checkImage(document.getElementById("qrImage"))) {
        ok = false;
    }
    if (!ok) {
        e.preventDefault();
    }
});

document.getElementById("announcementForm").addEventListener("submit", function (e) {
    clearErrors(this);
    var ok = true;
    var title = document.getElementById("title");
    if (title.value.trim() == "") {
        showError(title, "Give it a title.");
        ok = false;
    }
    var content = document.getElementById("content");
    if (content.value.trim().length < 5) {
        showError(content, "Write a bit more than that.");
        ok = false;
    }
    if (!ok) {
        e.preventDefault();
    }
});

document.getElementById("passwordForm").addEventListener("submit", function (e) {
    clearErrors(this);
    var ok = true;
    var currentPassword = document.getElementById("currentPassword");
    if (currentPassword.value == "") {
        showError(currentPassword, "Type the current password.");
        ok = false;
    }
    var newPassword = document.getElementById("newPassword");
    if (newPassword.value.length < 8) {
        showError(newPassword, "New password must be at least 8 characters.");
        ok = false;
    }
    var confirmPassword = document.getElementById("confirmPassword");
    if (confirmPassword.value !== newPassword.value) {
        showError(confirmPassword, "Passwords do not match.");
        ok = false;
    }
    if (!ok) {
        e.preventDefault();
    }
});

document.getElementById("printBtn").addEventListener("click", function () {
    window.print();
});
</script>

</body>
</html>
