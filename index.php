<?php
session_start();
require 'db.php';

$register_error = '';
$login_error = '';

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'] ?? 'consumer';

    if (empty($username) || empty($email) || empty($password)) {
        $register_error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = "Invalid email.";
    } elseif (!in_array($role, ['consumer', 'seller'])) {
        $register_error = "Invalid account type.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
        $stmt->execute(['username' => $username, 'email' => $email]);

        if ($stmt->fetch()) {
            $register_error = "Username or email already exists.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (:username, :email, :password, :role)");
            $stmt->execute(['username' => $username, 'email' => $email, 'password' => $hash, 'role' => $role]);
            // Auto-login after registration
            $user_id = $pdo->lastInsertId();
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $role;

            // Redirect based on role
            if ($role === 'seller') {
                header("Location: dashboard.php");
            } else {
                header("Location: shop.php");
            }
            exit;
        }
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $user = trim($_POST['user']);
    $pass = $_POST['pass'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :user OR email = :user");
    $stmt->execute(['user' => $user]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($account && password_verify($pass, $account['password'])) {
        $_SESSION['user_id'] = $account['id'];
        $_SESSION['username'] = $account['username'];
        $_SESSION['role'] = $account['role'];

        // Redirect based on role
        if ($account['role'] === 'seller' || $account['role'] === 'admin') {
            header("Location: dashboard.php");
        } else {
            header("Location: shop.php");
        }
        exit;
    } else {
        $login_error = "Invalid credentials.";
    }
}

// Determine which form to show by default
$show_register = isset($_GET['register']);
if (!$show_register) {
    // Also show register if there was a register error
    if ($register_error) $show_register = true;
    // Or if login error, show login
    if ($login_error) $show_register = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="style.css" />

<title>Login & Register</title>
<script>
  function toggleForms() {
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    if (loginForm.classList.contains('d-none')) {
      loginForm.classList.remove('d-none');
      registerForm.classList.add('d-none');
    } else {
      loginForm.classList.add('d-none');
      registerForm.classList.remove('d-none');
    }
  }
</script>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-light">
  <div class="container-fluid">
    <span class="navbar-brand">MALL OF CAP</span>
    <div class="d-flex">
      <button class="btn btn-outline-secondary me-2" title="Toggle Theme" aria-label="Toggle Theme">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 16 16">
          <path d="M8 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6zm0 1a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM8 0a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm0 13a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm8-5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2a.5.5 0 0 1 .5.5zM3 8a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2A.5.5 0 0 1 3 8zm10.657-5.657a.5.5 0 0 1 0 .707l-1.414 1.415a.5.5 0 1 1-.707-.708l1.414-1.414a.5.5 0 0 1 .707 0zm-9.193 9.193a.5.5 0 0 1 0 .707L3.05 13.657a.5.5 0 0 1-.707-.707l1.414-1.414a.5.5 0 0 1 .707 0zm9.193-9.193a.5.5 0 0 1-.707 0l-1.414-1.414a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707zM4.464 4.465a.5.5 0 0 1-.707 0L2.343 3.05a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707z"/>
        </svg>
      </button>
    </div>
  </div>
</nav>

<div class="container mt-5" style="margin-top: 100px !important;">
  <div class="row justify-content-center">
    <div class="col-md-6">
      <div class="card shadow">
        <div class="card-body p-5">
          <div id="login-form" class="<?= $show_register ? 'd-none' : '' ?>">
            <h2 class="text-center mb-4">Login</h2>
            <?php if ($login_error): ?>
              <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($login_error) ?>
              </div>
            <?php endif; ?>
            <form method="post" action="">
              <div class="mb-3">
                <input type="text" name="user" class="form-control form-control-lg" placeholder="Username or Email" required>
              </div>
              <div class="mb-3">
                <input type="password" name="pass" class="form-control form-control-lg" placeholder="Password" required>
              </div>
              <div class="d-grid mb-3">
                <button type="submit" name="login" class="btn btn-primary btn-lg">Login</button>
              </div>
            </form>
            <div class="text-center mb-3">
              <a href="forgot_password.php" class="text-muted">Forgot Password?</a>
            </div>
            <div class="text-center">
              Don't have an account? <a href="#" onclick="toggleForms()" class="text-primary">Register here</a>
            </div>
          </div>

          <div id="register-form" class="<?= $show_register ? '' : 'd-none' ?>">
            <h2 class="text-center mb-4">Register</h2>
            <?php if ($register_error): ?>
              <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($register_error) ?>
              </div>
            <?php endif; ?>
            <form method="post" action="index.php">
              <input type="hidden" name="action" value="register">
              <div class="mb-3">
                <input type="text" id="reg_username" name="username" class="form-control form-control-lg" placeholder="Username" required>
              </div>
              <div class="mb-3">
                <input type="email" id="reg_email" name="email" class="form-control form-control-lg" placeholder="Email" required>
              </div>
              <div class="mb-3">
                <input type="password" id="reg_password" name="password" class="form-control form-control-lg" placeholder="Password" required>
              </div>
              <div class="mb-3">
                <select name="role" id="role" class="form-select form-select-lg" required>
                  <option value="consumer">Consumer (Shop for products)</option>
                  <option value="seller">Seller (Manage inventory)</option>
                </select>
              </div>
              <div class="d-grid mb-3">
                <button type="submit" class="btn btn-success btn-lg">Register</button>
              </div>
            </form>
            <p class="text-center mb-0">Already have an account? <a href="#" onclick="toggleForms()" class="text-primary">Login here</a></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Theme toggle functionality
document.addEventListener('DOMContentLoaded', () => {
  const themeToggle = document.querySelector('.btn-outline-secondary');
  const currentTheme = localStorage.getItem('theme') || 'light';
  
  if (currentTheme === 'dark') {
    document.body.setAttribute('data-theme', 'dark');
  }
  
  themeToggle.addEventListener('click', () => {
    const currentTheme = document.body.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    document.body.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
  });
});
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
