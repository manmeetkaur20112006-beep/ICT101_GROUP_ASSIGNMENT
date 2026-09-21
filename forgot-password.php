<?php
require_once "config.php";

// XAMPP has no mail server, so there is no emailed reset link. Instead the
// member answers the security question they set when they registered.

$errors = [];
$currentStep = 1;
$done = false;
$email = "";

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    // fresh visit - throw away anything left over from an earlier attempt
    unset($_SESSION["recoverMemberId"]);
    unset($_SESSION["recoverQuestion"]);
    unset($_SESSION["recoverVerified"]);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $step = $_POST["step"] ?? "1";

    // ---- step 1: find the account ----
    if ($step == "1") {
        $email = trim($_POST["email"]);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors["email"] = "Please enter a valid email address.";
        } else {
            $stmt = $conn->prepare("SELECT id, security_question, security_answer_hash FROM members WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $member = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$member) {
                $errors["email"] = "No account found with that email address.";
            } elseif ($member["security_question"] == "" || $member["security_answer_hash"] == "") {
                // members the admin added by hand may not have a question yet
                $errors["email"] = "This account has no security question set. Please ask the gym admin to reset it for you.";
            } else {
                $_SESSION["recoverMemberId"] = $member["id"];
                $_SESSION["recoverQuestion"] = $member["security_question"];
                $_SESSION["recoverVerified"] = false;
                $currentStep = 2;
            }
        }
    }

    // ---- step 2: check the security answer ----
    if ($step == "2") {
        $securityAnswer = trim($_POST["securityAnswer"]);

        if (!isset($_SESSION["recoverMemberId"])) {
            $errors["email"] = "Your session timed out. Please start again.";
            $currentStep = 1;
        } elseif ($securityAnswer == "") {
            $errors["securityAnswer"] = "Please type your answer.";
            $currentStep = 2;
        } else {
            $stmt = $conn->prepare("SELECT security_answer_hash FROM members WHERE id = ?");
            $stmt->bind_param("s", $_SESSION["recoverMemberId"]);
            $stmt->execute();
            $answerRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // the answer was lowercased before hashing at registration
            if (password_verify(strtolower($securityAnswer), $answerRow["security_answer_hash"])) {
                $_SESSION["recoverVerified"] = true;
                $currentStep = 3;
            } else {
                $errors["securityAnswer"] = "That answer doesn't match what we have on file.";
                $currentStep = 2;
            }
        }
    }

    // ---- step 3: save the new password ----
    if ($step == "3") {
        $newPassword = $_POST["newPassword"];
        $confirmPassword = $_POST["confirmPassword"];

        // the session flag is the only thing that proves they got step 2 right,
        // otherwise someone could post straight to this step
        if (!isset($_SESSION["recoverMemberId"]) || $_SESSION["recoverVerified"] !== true) {
            $errors["email"] = "Your session timed out. Please start again.";
            $currentStep = 1;
        } else {
            if (strlen($newPassword) < 8) {
                $errors["newPassword"] = "Password must be at least 8 characters.";
            }
            if ($newPassword !== $confirmPassword) {
                $errors["confirmPassword"] = "Passwords do not match.";
            }

            if (count($errors) == 0) {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $update = $conn->prepare("UPDATE members SET password_hash = ? WHERE id = ?");
                $update->bind_param("ss", $passwordHash, $_SESSION["recoverMemberId"]);
                $update->execute();
                $update->close();

                // clear the flags so the same session can't reset it twice
                unset($_SESSION["recoverMemberId"]);
                unset($_SESSION["recoverQuestion"]);
                unset($_SESSION["recoverVerified"]);
                $done = true;
            } else {
                $currentStep = 3;
            }
        }
    }
}
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot Password - Singh Fitness</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?php echo filemtime("assets/css/base.css"); ?>">
<link rel="stylesheet" href="assets/css/shared.css?v=<?php echo filemtime("assets/css/shared.css"); ?>">
</head>
<body>

<div class="page">

    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Singh Fitness logo" class="site-logo" width="160" height="48">

    <?php if ($done) { ?>

        <h1 class="title">Password updated</h1>
        <p class="sub">You can now log in with your new password.</p>

        <a href="login.php" class="btn" style="text-align:center; display:block;">Go to login</a>

    <?php } else { ?>

        <h1 class="title">Reset your password</h1>
        <p class="sub">Answer your security question to set a new password</p>
        <p class="step-count">Step <?php echo $currentStep; ?> of 3</p>

        <?php if ($currentStep == 1) { ?>

            <form method="POST" action="forgot-password.php" id="emailForm" novalidate>
                <input type="hidden" name="step" value="1">

                <div class="group plain">
                    <div class="row">
                        <input type="email" name="email" id="email" placeholder="Email" value="<?php echo htmlspecialchars($email); ?>">
                        <?php if (isset($errors["email"])) { ?><span class="field-error"><?php echo $errors["email"]; ?></span><?php } ?>
                        <span class="hint">Enter the email you registered with. We don't send reset emails, you'll answer your security question instead.</span>
                    </div>
                </div>

                <button type="submit" class="btn">Continue</button>
            </form>

        <?php } elseif ($currentStep == 2) { ?>

            <form method="POST" action="forgot-password.php" id="answerForm" novalidate>
                <input type="hidden" name="step" value="2">

                <div class="group plain">
                    <div class="row">
                        <p class="info-label">Your security question</p>
                        <p class="info-value"><?php echo htmlspecialchars($_SESSION["recoverQuestion"]); ?></p>
                    </div>
                    <div class="row">
                        <input type="text" name="securityAnswer" id="securityAnswer" placeholder="Your answer">
                        <?php if (isset($errors["securityAnswer"])) { ?><span class="field-error"><?php echo $errors["securityAnswer"]; ?></span><?php } ?>
                        <span class="hint">Capital letters don't matter.</span>
                    </div>
                </div>

                <button type="submit" class="btn">Check answer</button>
                <a href="forgot-password.php" class="btn-outline" style="text-align:center; display:block; text-decoration:none;">Start again</a>
            </form>

        <?php } else { ?>

            <form method="POST" action="forgot-password.php" id="passwordForm" novalidate>
                <input type="hidden" name="step" value="3">

                <div class="group plain">
                    <div class="row">
                        <input type="password" name="newPassword" id="newPassword" placeholder="New password (min 8 characters)">
                        <?php if (isset($errors["newPassword"])) { ?><span class="field-error"><?php echo $errors["newPassword"]; ?></span><?php } ?>
                    </div>
                    <div class="row">
                        <input type="password" name="confirmPassword" id="confirmPassword" placeholder="Confirm new password">
                        <?php if (isset($errors["confirmPassword"])) { ?><span class="field-error"><?php echo $errors["confirmPassword"]; ?></span><?php } ?>
                    </div>
                </div>

                <button type="submit" class="btn">Save new password</button>
            </form>

        <?php } ?>

        <p class="link-line">
            Remembered it? <a href="login.php">Back to login</a>
        </p>

    <?php } ?>

    <footer class="site-footer">
        <p>&copy; <?php echo date("Y"); ?> Singh Fitness Gym. All rights reserved.</p>
        <p><a href="login.php">Login</a> &middot; <a href="register.php">Register</a></p>
    </footer>

</div>

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

// only one of the three forms is on the page at a time
var emailForm = document.getElementById("emailForm");
if (emailForm) {
    emailForm.addEventListener("submit", function (e) {
        clearErrors(emailForm);
        var email = document.getElementById("email");
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        if (!emailPattern.test(email.value.trim())) {
            showError(email, "Please enter a valid email address.");
            e.preventDefault();
        }
    });
}

var answerForm = document.getElementById("answerForm");
if (answerForm) {
    answerForm.addEventListener("submit", function (e) {
        clearErrors(answerForm);
        var securityAnswer = document.getElementById("securityAnswer");

        if (securityAnswer.value.trim() == "") {
            showError(securityAnswer, "Please type your answer.");
            e.preventDefault();
        }
    });
}

var passwordForm = document.getElementById("passwordForm");
if (passwordForm) {
    passwordForm.addEventListener("submit", function (e) {
        clearErrors(passwordForm);
        var ok = true;

        var newPassword = document.getElementById("newPassword");
        if (newPassword.value.length < 8) {
            showError(newPassword, "Password must be at least 8 characters.");
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
}
</script>

</body>
</html>
