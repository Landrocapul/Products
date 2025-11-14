<?php
require 'db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Store token
            $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $stmt->execute([$token, $expires, $user['id']]);

            // Send email (basic implementation - in production, use proper mail library)
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/Products/reset_password.php?token=" . $token;
            $subject = "Password Reset Request";
            $message = "Hi {$user['username']},\n\nYou requested a password reset. Click the link below to reset your password:\n\n{$reset_link}\n\nThis link will expire in 1 hour.\n\nIf you didn't request this, please ignore this email.";
            $headers = "From: noreply@yourapp.com";

            if (mail($email, $subject, $message, $headers)) {
                $success = "Password reset link has been sent to your email.";
            } else {
                $error = "Failed to send email. Please try again later.";
            }
        } else {
            $success = "If an account with that email exists, a reset link has been sent."; // Security: don't reveal if email exists
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="style.css" />
<title>Forgot Password</title>
</head>
<body>
<section class="box">
  <h2>Forgot Password</h2>
  <p>Enter your email address and we'll send you a link to reset your password.</p>

  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <form method="post" action="">
    <input type="email" name="email" placeholder="Email" required>
    <button type="submit">Send Reset Link</button>
  </form>

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
</style>
</body>
</html>
