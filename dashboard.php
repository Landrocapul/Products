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
$categories = []; // Initialize

// Fetch categories for forms (needed for create/edit)
if ($action === 'create' || $action === 'edit') {
    $cat_stmt = $pdo->prepare("SELECT * FROM categories WHERE created_by = :uid ORDER BY name");
    $cat_stmt->execute(['uid' => $user_id]);
    $categories = $cat_stmt->fetchAll();
}

// Handle product deletion
if ($action === 'delete' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = :id AND created_by = :uid");
    $stmt->execute(['id' => $_GET['id'], 'uid' => $user_id]);
    header("Location: dashboard.php?action=products"); // Redirect back to product list
    exit;
}

// Handle create product
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $price = $_POST['price'] ?? '';
    $category_id = $_POST['category_id'] ?? '';

    if ($name === '' || !is_numeric($price) || empty($category_id)) {
        $error = "Valid product name, price, and category are required.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO products (name, price, category_id, created_by) VALUES (:name, :price, :cid, :uid)");
        $stmt->execute(['name' => $name, 'price' => $price, 'cid' => $category_id, 'uid' => $user_id]);
        header("Location: dashboard.php?action=products"); // Redirect back to product list
        exit;
    }
}

// Handle edit product
if ($action === 'edit' && isset($_GET['id'])) {
    $id = $_GET['id'];

    // Fetch product for editing
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id AND created_by = :uid");
    $stmt->execute(['id' => $id, 'uid' => $user_id]);
    $product = $stmt->fetch();

    if (!$product) {
        die("Product not found or access denied.");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $price = $_POST['price'] ?? '';
        $category_id = $_POST['category_id'] ?? '';

        if ($name === '' || !is_numeric($price) || empty($category_id)) {
            $error = "Valid product name, price, and category are required.";
        } else {
            $stmt = $pdo->prepare("UPDATE products SET name = :name, price = :price, category_id = :cid WHERE id = :id AND created_by = :uid");
            $stmt->execute(['name' => $name, 'price' => $price, 'cid' => $category_id, 'id' => $id, 'uid' => $user_id]);
            header("Location: dashboard.php?action=products"); // Redirect back to product list
            exit;
        }
    }
}

// Fetch data for product list page
if ($action === 'products') {
    // 1. Whitelist of allowed columns for sorting
    $allowed_sort_cols = [
        'name' => 'p.name',
        'category' => 'c.name',
        'price' => 'p.price',
        'created_at' => 'p.created_at'
    ];
    // 2. Get sort parameters from URL, with defaults
    $sort_key = $_GET['sort'] ?? 'created_at';
    $order_key = $_GET['order'] ?? 'desc';
    // 3. Validate and set the SQL sort column
    if (!array_key_exists($sort_key, $allowed_sort_cols)) {
        $sort_key = 'created_at';
    }
    $sort_sql_col = $allowed_sort_cols[$sort_key];
    // 4. Validate and set the SQL sort order
    $order = strtoupper($order_key);
    if ($order !== 'ASC' && $order !== 'DESC') {
        $order = 'DESC';
    }
    // 5. Determine the *next* order for the links
    $next_order = ($order === 'ASC') ? 'desc' : 'asc';
    
    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS category_name 
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.created_by = :uid 
        ORDER BY $sort_sql_col $order
    ");
    $stmt->execute(['uid' => $user_id]);
    $products = $stmt->fetchAll();
}

// Fetch data for Home Dashboard (default action)
if ($action === '') {
    // 1. Total products
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM products WHERE created_by = :uid");
    $stmt_count->execute(['uid' => $user_id]);
    $total_products = $stmt_count->fetchColumn();

    // 2. Total categories
    $stmt_cat = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE created_by = :uid");
    $stmt_cat->execute(['uid' => $user_id]);
    $total_categories = $stmt_cat->fetchColumn();
    
    // 3. Total inventory value
    $stmt_val = $pdo->prepare("SELECT SUM(price) FROM products WHERE created_by = :uid");
    $stmt_val->execute(['uid' => $user_id]);
    $total_value = $stmt_val->fetchColumn();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="style.css" />
<title>Dashboard - Products</title>
</head>
<body>

<nav class="navbar">
  </nav>

<aside class="sidebar">
  <ul class="sidebar-menu">
    <li><a href="dashboard.php"><span class="menu-icon">🏠</span> Home</a></li>
    <li><a href="dashboard.php?action=products"><span class="menu-icon">📦</span> Products</a></li>
    <li><a href="categories.php"><span class="menu-icon">🗂️</span> Categories</a></li>
    <li><a href="#"><span class="menu-icon">🏬</span> Stores</a></li>
  </ul>
  <ul class="sidebar-menu logout-menu">
    <li><a href="logout.php"><span class="menu-icon">🚪</span> Logout</a></li>
  </ul>
</aside>

<main class="main-content">
<h1>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</h1>

<?php if ($action === 'create'): ?>

  <h2>Add New Product</h2>

  <?php if ($error): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
  <?php endif; ?>

  <?php if (empty($categories)): ?>
    <p class="error">You must <a href="categories.php">create a category</a> before you can add a product.</p>
  <?php else: ?>
    <form method="post" action="dashboard.php?action=create">
      <input type="text" name="name" placeholder="Product Name" required />
      <input type="number" step="0.01" name="price" placeholder="Price" required />
      <label for="category_id">Category:</label>
      <select name="category_id" id="category_id" required>
        <option value="">-- Select a Category --</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Create Product</button>
    </form>
  <?php endif; ?>

  <p><a href="dashboard.php?action=products" class="button secondary-button">Back to Products</a></p>

<?php elseif ($action === 'edit' && isset($product)): ?>

  <h2>Edit Product</h2>

  <?php if ($error): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
  <?php endif; ?>

  <form method="post" action="dashboard.php?action=edit&id=<?= $product['id'] ?>">
    <input type="text" name="name" value="<?= htmlspecialchars($product['name']) ?>" required />
    <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($product['price']) ?>" required />
    <label for="category_id">Category:</label>
    <select name="category_id" id="category_id" required>
      <option value="">-- Select a Category --</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= $cat['id'] ?>" <?= ($cat['id'] == $product['category_id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($cat['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit">Update Product</button>
  </form>

  <p><a href="dashboard.php?action=products" class="button secondary-button">Back to Products</a></p>

<?php elseif ($action === 'products'): ?>

  <h2>Your Products</h2>
  <p><a href="dashboard.php?action=create" class="button">+ Add New Product</a></p>

  <?php if (empty($products)): ?>
    <p>You have no products yet.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <?php
          function sort_link($column, $text, $current_sort, $current_order, $next_order) {
              $arrow = '';
              $order_for_link = 'asc';
              if ($column === $current_sort) {
                  $order_for_link = $next_order;
                  $arrow = ($current_order === 'ASC') ? '&uarr;' : '&darr;'; // Up/Down arrow
              }
              echo "<th><a href=\"?action=products&sort=$column&order=$order_for_link\">$text $arrow</a></th>";
          }
          
          sort_link('name', 'Name', $sort_key, $order, $next_order);
          sort_link('category', 'Category', $sort_key, $order, $next_order);
          sort_link('price', 'Price', $sort_key, $order, $next_order);
          sort_link('created_at', 'Created At', $sort_key, $order, $next_order);
          ?>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): ?>
        <tr>
          <td><?= htmlspecialchars($p['name']) ?></td>
          <td><?= htmlspecialchars($p['category_name'] ?? 'N/A') ?></td>
          <td>$<?= number_format($p['price'], 2) ?></td>
          <td><?= htmlspecialchars($p['created_at']) ?></td>
          <td>
            <a href="dashboard.php?action=edit&id=<?= $p['id'] ?>" class="action-link">Edit</a> |
            <a href="dashboard.php?action=delete&id=<?= $p['id'] ?>" class="action-link delete-link" onclick="return confirm('Are you sure you want to delete this product?');">Delete</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

<?php else: // $action === '' (The new Home Dashboard) ?>

  <h2>Dashboard</h2>
  <p>Here is a summary of your account.</p>
  
  <div class="stat-container">
      <div class="stat-box">
          <h3>Total Products</h3>
          <p><?= $total_products ?></p>
      </div>
      <div class="stat-box">
          <h3>Total Categories</h3>
          <p><?= $total_categories ?></p>
      </div>
      <div class="stat-box">
          <h3>Inventory Value</h3>
          <p>$<?= number_format($total_value ?? 0, 2) ?></p>
      </div>
  </div>

<?php endif; ?>
</main>

</body>
</html>