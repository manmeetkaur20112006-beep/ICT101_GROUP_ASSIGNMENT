<?php
require_once "config.php";

$errorMsg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    $stmt = $conn->prepare("SELECT id, name, password_hash, role FROM members WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();

    if ($member && $member["password_hash"] != "" && password_verify($password, $member["password_hash"])) {
        if ($member["role"] == "pending") {
            // password is right but the admin hasn't approved them yet
            $errorMsg = "Your account is still waiting for admin approval. Please check back later.";
        } else {
            $_SESSION["memberId"] = $member["id"];
            $_SESSION["memberName"] = $member["name"];
            $_SESSION["role"] = $member["role"];

            if ($member["role"] == "admin") {
                header("Location: admin-dashboard.php");
            } else {
                header("Location: home.php");
            }
            exit;
        }
    } else {
        $errorMsg = "Wrong email or password. Please register if you don't have an account.";
    }

    $stmt->close();
}
?>
<!doctype html>
<html lang="en"<?php echo $themeAttr; ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - Singh Fitness</title>
<link rel="stylesheet" href="assets/css/base.css?v=<?php echo filemtime("assets/css/base.css"); ?>">
<link rel="stylesheet" href="assets/css/shared.css?v=<?php echo filemtime("assets/css/shared.css"); ?>">
</head>
<body>

<div class="page">
    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Singh Fitness logo" class="site-logo" width="160" height="48">

    <h1 class="title">Login</h1>
    <p class="sub">Sign in to your Singh Fitness account</p>

    <?php if ($errorMsg != "") { ?>
        <p class="error-text"><?php echo $errorMsg; ?></p>
    <?php } ?>

    <form method="POST" action="login.php" id="loginForm" novalidate>
        <div class="group plain">
            <div class="row">
                <input type="email" name="email" id="email" placeholder="Email">
            </div>
            <div class="row">
                <input type="password" name="password" id="password" placeholder="Password">
            </div>
        </div>

        <button type="submit" class="btn">Login</button>
    </form>

    <p class="link-line">
        <a href="forgot-password.php">Forgot password?</a>
    </p>
    <p class="link-line">
        Don't have an account? <a href="register.php">Register</a>
    </p>

    <footer class="site-footer">
        <p>&copy; <?php echo date("Y"); ?> Singh Fitness Gym. All rights reserved.</p>
        <p><a href="register.php">Register</a> &middot; <a href="forgot-password.php">Forgot password</a></p>
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

document.getElementById("loginForm").addEventListener("submit", function (e) {
    var form = this;
    clearErrors(form);
    var ok = true;

    var email = document.getElementById("email");
    var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(email.value.trim())) {
        showError(email, "Please enter a valid email address.");
        ok = false;
    }

    var password = document.getElementById("password");
    if (password.value == "") {
        showError(password, "Please enter your password.");
        ok = false;
    }

    if (!ok) {
        e.preventDefault();
    }
});
</script>

</body>
</html>
