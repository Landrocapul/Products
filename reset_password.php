<?php
require 'db.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    die("Invalid reset link.");
}

// Verify token
$stmt = $pdo->prepare("SELECT id, username FROM users WHERE reset_token = ? AND reset_expires > NOW()");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    die("Invalid or expired reset link.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $stmt->execute([$hashed_password, $user['id']]);
        $success = "Password reset successfully. <a href='index.php'>Login here</a>.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="style.css" />
<title>Reset Password</title>
</head>
<body>
<section class="box">
  <h2>Reset Password</h2>
  <p>Enter your new password below.</p>

  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <?php if (!$success): ?>
  <form method="post" action="">
    <input type="password" name="password" placeholder="New Password" required>
    <input type="password" name="confirm_password" placeholder="Confirm New Password" required>
    <button type="submit">Reset Password</button>
  </form>
  <?php endif; ?>

  <div class="toggle-link">
    <a href="index.php">Back to Login</a>
  </div>
</section>

<style>
.success {
  background: #d4edda;
  color: #155724;
  border: 1px solid #c3e6cb;
  padding: 12px 15px;
  border-radius: 6px;
  margin-bottom: 15px;
}
.success a {
  color: #155724;
  font-weight: 600;
}
</style>
</body>
</html>
