<?php
require_once "config.php";

// options used for both the form controls and server-side validation
$securityQuestions = [
    "What was the name of your first pet?",
    "What is your mother's maiden name?",
    "What city were you born in?",
    "What was the name of your primary school?"
];

$plans = [
    "Basic Gym Access [ 1000 rupee/month ]",
    "Full Gym + Cardio [ 1700 rupee/month ]",
    "Premium - Gym + Cardio + Personal Training [ 2500 rupee/month ]"
];

$durations = ["1 Month", "2 Months", "3 Months"];
$shifts = ["Morning Shift", "Evening Shift"];

// current UPI id / QR image for the payment step, set by the admin later
$gymConfig = $conn->query("SELECT upi_id, qr_code_url FROM gym_config WHERE id = 1")->fetch_assoc();
$upiId = $gymConfig["upi_id"];
$qrImage = $gymConfig["qr_code_url"] != "" ? $gymConfig["qr_code_url"] : "assets/img/qr-placeholder.svg";

$errors = [];
$activeStep = 1;
$registered = false;
$newMemberCode = "";

// keep whatever the user typed so the form doesn't clear on error
$fullName = "";
$email = "";
$phone = "";
$address = "";
$securityQuestion = $securityQuestions[0];
$plan = $plans[0];
$duration = $durations[0];
$shift = $shifts[0];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullName = trim($_POST["fullName"]);
    $email = trim($_POST["email"]);
    $phone = trim($_POST["phone"]);
    $password = $_POST["password"];
    $confirmPassword = $_POST["confirmPassword"];
    $address = trim($_POST["address"]);
    $securityQuestion = $_POST["securityQuestion"];
    $securityAnswer = trim($_POST["securityAnswer"]);

    $plan = $_POST["plan"] ?? "";
    $duration = $_POST["duration"] ?? "";
    $shift = $_POST["shift"] ?? "";
    $paymentConfirmed = isset($_POST["paymentConfirmed"]);

    // ---- step 1 checks (details) ----
    if ($fullName == "") {
        $errors["fullName"] = "Please enter your full name.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors["email"] = "Please enter a valid email address.";
    }
    if (!preg_match("/^[0-9]{8,15}$/", $phone)) {
        $errors["phone"] = "Phone number should be 8 to 15 digits.";
    }
    if (strlen($password) < 8) {
        $errors["password"] = "Password must be at least 8 characters.";
    }
    if ($password !== $confirmPassword) {
        $errors["confirmPassword"] = "Passwords do not match.";
    }
    if (!in_array($securityQuestion, $securityQuestions)) {
        $errors["securityQuestion"] = "Please choose a security question.";
    }
    if ($securityAnswer == "") {
        $errors["securityAnswer"] = "Please answer the security question.";
    }

    // only bother hitting the database if the basic fields are already valid
    if ($email != "" && !isset($errors["email"])) {
        $checkEmail = $conn->prepare("SELECT id FROM members WHERE email = ?");
        $checkEmail->bind_param("s", $email);
        $checkEmail->execute();
        $checkEmail->store_result();
        if ($checkEmail->num_rows > 0) {
            $errors["email"] = "An account with this email already exists. Try logging in instead.";
        }
        $checkEmail->close();
    }

    // ---- step 2 checks (plan + payment) ----
    if (!in_array($plan, $plans)) {
        $errors["plan"] = "Please choose a membership plan.";
    }
    if (!in_array($duration, $durations)) {
        $errors["duration"] = "Please choose a membership duration.";
    }
    if (!in_array($shift, $shifts)) {
        $errors["shift"] = "Please choose a preferred shift.";
    }
    if (!$paymentConfirmed) {
        $errors["paymentConfirmed"] = "Please confirm you have made the payment before submitting.";
    }

    // send the user back to whichever step still has a problem
    $step1Fields = ["fullName", "email", "phone", "password", "confirmPassword", "securityQuestion", "securityAnswer"];
    $activeStep = 2;
    foreach ($step1Fields as $field) {
        if (isset($errors[$field])) {
            $activeStep = 1;
            break;
        }
    }

    if (count($errors) == 0) {
        // membership number shown on the pass, e.g. SFG-1042
        $totalRow = $conn->query("SELECT COUNT(*) AS total FROM members")->fetch_assoc();
        $nextNumber = 1000 + (int)$totalRow["total"] + 1;
        do {
            $memberCode = "SFG-" . $nextNumber;
            $checkCode = $conn->prepare("SELECT id FROM members WHERE member_code = ?");
            $checkCode->bind_param("s", $memberCode);
            $checkCode->execute();
            $codeTaken = $checkCode->get_result()->num_rows > 0;
            $checkCode->close();
            $nextNumber++;
        } while ($codeTaken);

        $memberId = "mem_" . uniqid();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        // lowercase the answer first so recovery isn't case-sensitive
        $securityAnswerHash = password_hash(strtolower($securityAnswer), PASSWORD_DEFAULT);

        $durationMonths = ["1 Month" => 1, "2 Months" => 2, "3 Months" => 3][$duration];
        $joinedDate = date("Y-m-d");
        $expiryDate = date("Y-m-d", strtotime("+$durationMonths month"));

        $insert = $conn->prepare("INSERT INTO members
            (id, member_code, name, email, password_hash, phone, security_question, security_answer_hash,
             address, preferred_shift, gym_access, membership_duration, status, role, joined_date, expiry_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Approval', 'pending', ?, ?)");
        $insert->bind_param(
            "ssssssssssssss",
            $memberId, $memberCode, $fullName, $email, $passwordHash, $phone, $securityQuestion, $securityAnswerHash,
            $address, $shift, $plan, $duration, $joinedDate, $expiryDate
        );

        if ($insert->execute()) {
            $registered = true;
            $newMemberCode = $memberCode;
        } else {
            // most likely the email unique constraint, in case two people submit at the same instant
            $errors["email"] = "An account with this email already exists. Try logging in instead.";
            $activeStep = 1;
        }
        $insert->close();
    }
}
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Register - Singh Fitness</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?php echo filemtime("assets/css/base.css"); ?>">
<link rel="stylesheet" href="assets/css/shared.css?v=<?php echo filemtime("assets/css/shared.css"); ?>">
</head>
<body>

<div class="page">

    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Singh Fitness logo" class="site-logo" width="160" height="48">

    <?php if ($registered) { ?>

        <h1 class="title">You're almost in!</h1>
        <p class="sub">Your account has been created and is waiting on admin approval.</p>

        <div class="group plain">
            <div class="row">
                <p style="margin:0 0 6px;"><strong>Membership number:</strong> <?php echo htmlspecialchars($newMemberCode); ?></p>
                <p style="margin:0; color:var(--text-soft); font-size:14px;">
                    An admin will check your payment and approve your account. Once approved you can log in and start using the gym.
                </p>
            </div>
        </div>

        <a href="login.php" class="btn" style="text-align:center; display:block;">Go to login</a>

    <?php } else { ?>

        <h1 class="title">Create your account</h1>
        <p class="sub">Join Singh Fitness in two quick steps</p>
        <p class="step-count" id="stepCount">Step <span id="stepNumber"><?php echo $activeStep; ?></span> of 2</p>

        <form method="POST" action="register.php" id="registerForm" novalidate>

            <!-- STEP 1: personal details + security question -->
            <div class="step <?php echo $activeStep == 1 ? "active" : ""; ?>" id="step1">

                <div class="group plain">
                    <div class="row">
                        <input type="text" name="fullName" id="fullName" placeholder="Full name" value="<?php echo htmlspecialchars($fullName); ?>">
                        <?php if (isset($errors["fullName"])) { ?><span class="field-error"><?php echo $errors["fullName"]; ?></span><?php } ?>
                    </div>
                    <div class="row">
                        <input type="email" name="email" id="email" placeholder="Email" value="<?php echo htmlspecialchars($email); ?>">
                        <?php if (isset($errors["email"])) { ?><span class="field-error"><?php echo $errors["email"]; ?></span><?php } ?>
                    </div>
                    <div class="row">
                        <input type="tel" name="phone" id="phone" placeholder="Phone number" value="<?php echo htmlspecialchars($phone); ?>">
                        <?php if (isset($errors["phone"])) { ?><span class="field-error"><?php echo $errors["phone"]; ?></span><?php } ?>
                    </div>
                    <div class="row">
                        <input type="text" name="address" id="address" placeholder="Address (optional)" value="<?php echo htmlspecialchars($address); ?>">
                    </div>
                </div>

                <div class="group plain">
                    <div class="row">
                        <input type="password" name="password" id="password" placeholder="Password (min 8 characters)">
                        <?php if (isset($errors["password"])) { ?><span class="field-error"><?php echo $errors["password"]; ?></span><?php } ?>
                    </div>
                    <div class="row">
                        <input type="password" name="confirmPassword" id="confirmPassword" placeholder="Confirm password">
                        <?php if (isset($errors["confirmPassword"])) { ?><span class="field-error"><?php echo $errors["confirmPassword"]; ?></span><?php } ?>
                    </div>
                </div>

                <div class="group plain">
                    <div class="row">
                        <select name="securityQuestion" id="securityQuestion">
                            <?php foreach ($securityQuestions as $q) { ?>
                                <option value="<?php echo htmlspecialchars($q); ?>" <?php echo $securityQuestion == $q ? "selected" : ""; ?>><?php echo htmlspecialchars($q); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="row">
                        <input type="text" name="securityAnswer" id="securityAnswer" placeholder="Your answer">
                        <?php if (isset($errors["securityAnswer"])) { ?><span class="field-error"><?php echo $errors["securityAnswer"]; ?></span><?php } ?>
                        <span class="hint">Used to recover your password later, so pick something you'll remember.</span>
                    </div>
                </div>

                <button type="button" class="btn" id="nextBtn">Next: Choose plan</button>

                <p class="link-line">
                    Already have an account? <a href="login.php">Login</a>
                </p>
            </div>

            <!-- STEP 2: plan + payment -->
            <div class="step <?php echo $activeStep == 2 ? "active" : ""; ?>" id="step2">

                <h2 style="font-size:18px; margin:0 0 10px;">Choose a plan</h2>
                <?php if (isset($errors["plan"])) { ?><span class="field-error"><?php echo $errors["plan"]; ?></span><?php } ?>
                <?php foreach ($plans as $p) { ?>
                    <div class="plan-option">
                        <label>
                            <input type="radio" name="plan" value="<?php echo htmlspecialchars($p); ?>" <?php echo $plan == $p ? "checked" : ""; ?>>
                            <?php echo htmlspecialchars($p); ?>
                        </label>
                    </div>
                <?php } ?>

                <div class="group plain">
                    <div class="row">
                        <select name="duration" id="duration">
                            <?php foreach ($durations as $d) { ?>
                                <option value="<?php echo $d; ?>" <?php echo $duration == $d ? "selected" : ""; ?>><?php echo $d; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="row">
                        <select name="shift" id="shift">
                            <?php foreach ($shifts as $s) { ?>
                                <option value="<?php echo $s; ?>" <?php echo $shift == $s ? "selected" : ""; ?>><?php echo $s; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <h2 style="font-size:18px; margin:18px 0 10px;">Payment</h2>
                <div class="qr-box">
                    <img src="<?php echo htmlspecialchars($qrImage); ?>" alt="Payment QR code" width="120" height="120">
                    <p class="upi-id">UPI ID: <?php echo htmlspecialchars($upiId); ?></p>
                    <p style="font-size:13px; color:var(--text-soft); margin:6px 0 0;">
                        Scan the code or pay to the UPI ID above, then confirm below. An admin will verify the payment before your account is activated.
                    </p>
                </div>

                <div class="group plain">
                    <div class="row checkbox-row">
                        <input type="checkbox" name="paymentConfirmed" id="paymentConfirmed" <?php echo isset($_POST["paymentConfirmed"]) ? "checked" : ""; ?>>
                        <label for="paymentConfirmed">I have completed the payment for the plan selected above.</label>
                    </div>
                </div>
                <?php if (isset($errors["paymentConfirmed"])) { ?><span class="field-error"><?php echo $errors["paymentConfirmed"]; ?></span><?php } ?>

                <button type="submit" class="btn">Create account</button>
                <button type="button" class="btn-outline" id="backBtn">Back</button>
            </div>

        </form>

    <?php } ?>

    <footer class="site-footer">
        <p>&copy; <?php echo date("Y"); ?> Singh Fitness Gym. All rights reserved.</p>
        <p><a href="login.php">Login</a></p>
    </footer>

</div>

<script>
var step1 = document.getElementById("step1");
var step2 = document.getElementById("step2");
var stepNumber = document.getElementById("stepNumber");

// clears every .field-error text node that isn't the static hint under the security answer
function clearErrors(container) {
    var errorSpans = container.querySelectorAll(".field-error.js-error");
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

function validateStep1() {
    clearErrors(step1);
    var ok = true;

    var fullName = document.getElementById("fullName");
    if (fullName.value.trim() == "") {
        showError(fullName, "Please enter your full name.");
        ok = false;
    }

    var email = document.getElementById("email");
    var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(email.value.trim())) {
        showError(email, "Please enter a valid email address.");
        ok = false;
    }

    var phone = document.getElementById("phone");
    var phonePattern = /^[0-9]{8,15}$/;
    if (!phonePattern.test(phone.value.trim())) {
        showError(phone, "Phone number should be 8 to 15 digits.");
        ok = false;
    }

    var password = document.getElementById("password");
    if (password.value.length < 8) {
        showError(password, "Password must be at least 8 characters.");
        ok = false;
    }

    var confirmPassword = document.getElementById("confirmPassword");
    if (confirmPassword.value !== password.value) {
        showError(confirmPassword, "Passwords do not match.");
        ok = false;
    }

    var securityAnswer = document.getElementById("securityAnswer");
    if (securityAnswer.value.trim() == "") {
        showError(securityAnswer, "Please answer the security question.");
        ok = false;
    }

    return ok;
}

function validateStep2() {
    clearErrors(step2);
    var ok = true;

    var planChosen = document.querySelector('input[name="plan"]:checked');
    if (!planChosen) {
        showError(document.querySelector(".plan-option"), "Please choose a membership plan.");
        ok = false;
    }

    var paymentConfirmed = document.getElementById("paymentConfirmed");
    if (!paymentConfirmed.checked) {
        showError(paymentConfirmed.parentNode, "Please confirm you have made the payment.");
        ok = false;
    }

    return ok;
}

document.getElementById("nextBtn").addEventListener("click", function () {
    if (validateStep1()) {
        step1.classList.remove("active");
        step2.classList.add("active");
        stepNumber.textContent = "2";
        window.scrollTo(0, 0);
    }
});

document.getElementById("backBtn").addEventListener("click", function () {
    step2.classList.remove("active");
    step1.classList.add("active");
    stepNumber.textContent = "1";
    window.scrollTo(0, 0);
});

document.getElementById("registerForm").addEventListener("submit", function (e) {
    // step 2 is the only one visible at this point, but re-check step 1 too
    // in case someone edits the DOM or comes back from a server-side error
    var step1Ok = validateStep1();
    var step2Ok = validateStep2();

    if (!step1Ok) {
        step1.classList.add("active");
        step2.classList.remove("active");
        stepNumber.textContent = "1";
        e.preventDefault();
        return;
    }

    if (!step2Ok) {
        e.preventDefault();
    }
});
</script>

</body>
</html>
