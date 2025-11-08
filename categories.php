<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$error = '';
$category = null;

// Handle delete
if ($action === 'delete' && isset($_GET['id'])) {
    // Note: We should also check if any products are using this category first
    $stmt = $pdo->prepare("DELETE FROM categories WHERE id = :id AND created_by = :uid");
    $stmt->execute(['id' => $_GET['id'], 'uid' => $user_id]);
    header("Location: categories.php");
    exit;
}

// Handle create/edit POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $id = $_POST['id'] ?? null;

    if (empty($name)) {
        $error = "Category name is required.";
    } else {
        try {
            if ($id) { // Update
                $stmt = $pdo->prepare("UPDATE categories SET name = :name WHERE id = :id AND created_by = :uid");
                $stmt->execute(['name' => $name, 'id' => $id, 'uid' => $user_id]);
            } else { // Create
                $stmt = $pdo->prepare("INSERT INTO categories (name, created_by) VALUES (:name, :uid)");
                $stmt->execute(['name' => $name, 'uid' => $user_id]);
            }
            header("Location: categories.php");
            exit;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) { // Duplicate entry
                $error = "You already have a category with that name.";
            } else {
                $error = "An error occurred.";
            }
        }
    }
}

// Handle edit (fetch for form)
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id AND created_by = :uid");
    $stmt->execute(['id' => $_GET['id'], 'uid' => $user_id]);
    $category = $stmt->fetch();
    if (!$category) {
        die("Category not found.");
    }
}

// Fetch all categories for listing
$stmt = $pdo->prepare("SELECT * FROM categories WHERE created_by = :uid ORDER BY name");
$stmt->execute(['uid' => $user_id]);
$categories = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="style.css" />
<title>Categories - Dashboard</title>
</head>
<body>

<nav class="navbar">
  <div class="navbar-left">
    <span class="company-name">MALL OF CAP</span>
  </div>
  <div class="navbar-right">
    </div>
</nav>

<aside class="sidebar">
  <ul class="sidebar-menu">
    <li><a href="dashboard.php">
        <span class="menu-icon">🏠</span> <span>Home</span>
    </a></li>
    <li><a href="dashboard.php?action=products">
        <span class="menu-icon">📦</span> <span>Products</span>
    </a></li>
    <li><a href="categories.php" class="active">
        <span class="menu-icon">🗂️</span> <span>Categories</span>
    </a></li>
    <li><a href="#">
        <span class="menu-icon">🏬</span> <span>Stores</span>
    </a></li>
  </ul>
  <ul class="sidebar-menu logout-menu">
    <li><a href="logout.php"><span class="menu-icon">🚪</span> <span>Logout</span></a></li>
  </ul>
</aside>

<main class="main-content">
<h1>Manage Categories</h1>

<?php if ($error): ?>
  <p class="error"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<div class="card">
  <h3><?php echo $category ? 'Edit Category' : 'Add New Category'; ?></h3>
  <form method="post" action="categories.php" style="padding: 0; box-shadow: none;">
    <input type="hidden" name="id" value="<?= $category['id'] ?? '' ?>" />
    <div class="form-group">
      <label for="name">Category Name</label>
      <input type="text" id="name" name="name" placeholder="e.g. Electronics" value="<?= htmlspecialchars($category['name'] ?? '') ?>" required />
    </div>
    <button type="submit"><?php echo $category ? 'Update' : 'Create'; ?></button>
    <?php if ($category): ?>
      <a href="categories.php" class="button secondary-button">Cancel Edit</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <h2>Your Categories</h2>
  <?php if (empty($categories)): ?>
    <p>You have no categories yet.</p>
  <?php else: ?>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($categories as $cat): ?>
          <tr>
            <td><?= htmlspecialchars($cat['name']) ?></td>
            <td>
              <a href="categories.php?action=edit&id=<?= $cat['id'] ?>" class="action-link">Edit</a>
              <a href="categories.php?action=delete&id=<?= $cat['id'] ?>" class="action-link delete-link" onclick="return confirm('Are you sure?');">Delete</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div> <?php endif; ?>
</div> </main>

</body>
</html>