<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'consumer';
$action = $_GET['action'] ?? 'profile';
$error = '';
$success = '';

// Fetch user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (empty($username) || empty($email)) {
        $error = "Username and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
            $stmt->execute([$username, $email, $user_id]);
            $success = "Profile updated successfully.";
            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                $error = "Username or email already exists.";
            } else {
                $error = "An error occurred.";
            }
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "All password fields are required.";
    } elseif (!password_verify($current_password, $user['password'])) {
        $error = "Current password is incorrect.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed_password, $user_id]);
        $success = "Password changed successfully.";
    }
}

// Fetch user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get account activity based on role
if ($user_role === 'seller' || $user_role === 'admin') {
    $product_count = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id = ?");
    $product_count->execute([$user_id]);
    $total_products = $product_count->fetchColumn();

    $category_count = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE created_by = ?");
    $category_count->execute([$user_id]);
    $total_categories = $category_count->fetchColumn();

    $order_count = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id IN (SELECT id FROM users WHERE role = 'consumer') AND EXISTS (SELECT 1 FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE p.seller_id = ?)");
    $order_count->execute([$user_id]);
    $total_orders = $order_count->fetchColumn();
} else {
    // Consumer stats
    $order_count = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
    $order_count->execute([$user_id]);
    $total_orders = $order_count->fetchColumn();

    $cart_count = $pdo->prepare("SELECT SUM(quantity) FROM cart WHERE user_id = ?");
    $cart_count->execute([$user_id]);
    $total_cart_items = $cart_count->fetchColumn() ?? 0;

    $total_products = 0; // Consumers don't own products
    $total_categories = 0; // Consumers don't own categories
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
<title>Account - Dashboard</title>
</head>
<body>

<nav class="navbar">
  <div class="navbar-left">
    <span class="company-name">MALL OF CAP</span>
  </div>
  <div class="navbar-right">
    <button class="icon-button theme-toggle" title="Toggle Theme" aria-label="Toggle Theme">
      <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6zm0 1a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM8 0a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm0 13a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm8-5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2a.5.5 0 0 1 .5.5zM3 8a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2A.5.5 0 0 1 3 8zm10.657-5.657a.5.5 0 0 1 0 .707l-1.414 1.415a.5.5 0 1 1-.707-.708l1.414-1.414a.5.5 0 0 1 .707 0zm-9.193 9.193a.5.5 0 0 1 0 .707L3.05 13.657a.5.5 0 0 1-.707-.707l1.414-1.414a.5.5 0 0 1 .707 0zm9.193-9.193a.5.5 0 0 1-.707 0l-1.414-1.414a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707zM4.464 4.465a.5.5 0 0 1-.707 0L2.343 3.05a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707z"/>
      </svg>
    </button>
    <button class="icon-button" title="Notifications" aria-label="Notifications">
      <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 16a2 2 0 0 0 1.985-1.75H6.015A2 2 0 0 0 8 16zm.104-14.11c.058-.3-.12-.575-.43-.575-.318 0-.489.282-.43.575C7.522 1.488 7 2.863 7 4v2.5l-.5.5V7h3v-.5l-.5-.5V4c0-1.137-.522-2.512-1.396-2.11z"/>
        <path d="M8 1a3 3 0 0 1 3 3v3.5c0 .5.5 1 1 1v.5h-8v-.5c.5 0 1-.5 1-1V4a3 3 0 0 1 3-3z"/>
      </svg>
      <span class="notification-badge">3</span>
    </button>
    <button class="icon-button" title="Settings" aria-label="Settings">
      <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492zM5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0z"/>
        <path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.318.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.54 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.318c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.901 2.54-2.54l-.159-.292a.873.873 0 0 1 .52-1.255l.318-.094c1.79-.527 1.79-3.065 0 3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.434-2.54-2.54l-.292.159a.873.873 0 0 1-1.255-.52l-.094-.319zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.416.764-.42 1.6-1.185 1.184l-.292-.159a1.873 1.873 0 0 0-2.692 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.693-1.115l-.291.16c-.764.415-1.6-.42-1.184-1.185l.159-.292a1.873 1.873 0 0 0-1.116-2.692l-.318-.094c-.835-.246-.835-1.428 0 1.674l.319-.094a1.873 1.873 0 0 0 1.115-2.693l-.16-.291c-.416-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.116l.094-.318z"/>
      </svg>
    </button>
    <button class="icon-button" title="Account" aria-label="Account" onclick="window.location.href='account.php'">
      <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 16 16">
        <path d="M3 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1H3zm5-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/>
      </svg>
    </button>
  </div>
</nav>

<aside class="sidebar">
  <ul class="sidebar-menu">
    <?php if ($user_role === 'seller' || $user_role === 'admin'): ?>
      <li><a href="dashboard.php">
          <span class="menu-icon">🏠</span> <span>Dashboard</span>
      </a></li>
      <li><a href="dashboard.php?action=products">
          <span class="menu-icon">📦</span> <span>My Products</span>
      </a></li>
      <li><a href="categories.php">
          <span class="menu-icon">🗂️</span> <span>Categories</span>
      </a></li>
    <?php else: ?>
      <li><a href="shop.php">
          <span class="menu-icon">🛒</span> <span>Shop</span>
      </a></li>
      <li><a href="shop.php?action=cart">
          <span class="menu-icon">🛒</span> <span>My Cart</span>
      </a></li>
      <li><a href="shop.php?action=orders">
          <span class="menu-icon">📋</span> <span>My Orders</span>
      </a></li>
    <?php endif; ?>
  </ul>
  <ul class="sidebar-menu logout-menu">
    <li><a href="logout.php"><span class="menu-icon">🚪</span> <span>Logout</span></a></li>
  </ul>
</aside>

<main class="main-content">
<h1>My Account</h1>

<?php if ($error): ?>
  <div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="account-tabs">
  <button class="tab-button <?= $action === 'profile' ? 'active' : '' ?>" onclick="showTab('profile')">Edit Profile</button>
  <button class="tab-button <?= $action === 'password' ? 'active' : '' ?>" onclick="showTab('password')">Change Password</button>
  <button class="tab-button <?= $action === 'activity' ? 'active' : '' ?>" onclick="showTab('activity')">Account Activity</button>
</div>

<div id="profile-tab" class="tab-content" style="display: <?= $action === 'profile' ? 'block' : 'none' ?>;">
  <div class="card">
    <h2>Edit Profile</h2>
    <form method="post">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required />
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required />
      </div>
      <button type="submit" name="update_profile" class="button">Update Profile</button>
    </form>
  </div>
</div>

<div id="password-tab" class="tab-content" style="display: <?= $action === 'password' ? 'block' : 'none' ?>;">
  <div class="card">
    <h2>Change Password</h2>
    <form method="post">
      <div class="form-group">
        <label for="current_password">Current Password</label>
        <input type="password" id="current_password" name="current_password" required />
      </div>
      <div class="form-group">
        <label for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password" required />
      </div>
      <div class="form-group">
        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" required />
      </div>
      <button type="submit" name="change_password" class="button">Change Password</button>
    </form>
  </div>
</div>

<div id="activity-tab" class="tab-content" style="display: <?= $action === 'activity' ? 'block' : 'none' ?>;">
  <div class="card">
    <h2>Account Activity</h2>
    <div class="activity-info">
      <p><strong>Account Created:</strong> <?= (new DateTime($user['created_at']))->format('F j, Y \a\t g:i A') ?></p>
      <p><strong>Account Type:</strong> <?= ucfirst($user_role) ?></p>

      <?php if ($user_role === 'seller' || $user_role === 'admin'): ?>
        <p><strong>Total Products:</strong> <?= $total_products ?></p>
        <p><strong>Total Categories:</strong> <?= $total_categories ?></p>
        <p><strong>Orders Received:</strong> <?= $total_orders ?></p>
      <?php else: ?>
        <p><strong>Total Orders:</strong> <?= $total_orders ?></p>
        <p><strong>Items in Cart:</strong> <?= $total_cart_items ?></p>
        <p><strong>Member Since:</strong> <?= (new DateTime($user['created_at']))->format('F Y') ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>

</main>

<script>
function showTab(tabName) {
  // Hide all tabs
  document.querySelectorAll('.tab-content').forEach(tab => tab.style.display = 'none');
  document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
  
  // Show selected tab
  document.getElementById(tabName + '-tab').style.display = 'block';
  event.target.classList.add('active');
  
  // Update URL
  window.history.pushState(null, null, '?action=' + tabName);
}

// Theme toggle functionality
document.addEventListener('DOMContentLoaded', () => {
  const themeToggle = document.querySelector('.theme-toggle');
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

<style>
.account-tabs {
  display: flex;
  margin-bottom: 20px;
  border-bottom: 1px solid var(--card-border);
}

.tab-button {
  background: none;
  border: none;
  padding: 10px 20px;
  cursor: pointer;
  font-size: 1rem;
  border-bottom: 2px solid transparent;
  transition: border-color 0.2s;
  color: var(--text-color);
}

.tab-button.active {
  border-bottom-color: var(--button-bg);
  font-weight: 600;
}

.tab-content {
  display: none;
}

.success {
  background: var(--success-bg);
  color: var(--success-text);
  border: 1px solid rgba(255,255,255,0.1);
  padding: 12px 15px;
  border-radius: 6px;
  margin-bottom: 15px;
}

.activity-info p {
  margin-bottom: 10px;
  padding: 8px 0;
  border-bottom: 1px solid var(--table-border);
  color: var(--text-color);
}

.activity-info strong {
  color: var(--text-color);
  font-weight: 600;
}

.activity-info p:last-child {
  border-bottom: none;
}

/* Form labels and headings */
.card h2 {
  color: var(--text-color);
  margin-bottom: 20px;
  font-weight: 600;
}

.card label {
  color: var(--text-color);
  opacity: 0.9;
  font-weight: 500;
}
</style>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
