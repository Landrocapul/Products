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

  // 4. NEW: Data for Pie Chart (Products by Category)
  $chart_stmt = $pdo->prepare("
      SELECT c.name, COUNT(p.id) as product_count
      FROM categories c
      LEFT JOIN products p ON c.id = p.category_id
      WHERE p.created_by = :uid
      GROUP BY c.id, c.name
      HAVING product_count > 0
      ORDER BY product_count DESC
  ");
  $chart_stmt->execute(['uid' => $user_id]);
  $chart_data = $chart_stmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Format data for JavaScript
  $chart_labels = json_encode(array_column($chart_data, 'name'));
  $chart_values = json_encode(array_column($chart_data, 'product_count'));

  // 5. NEW: Recently Added Products
  $recent_stmt = $pdo->prepare("
      SELECT p.id, p.name, p.price, c.name as category_name
      FROM products p
      LEFT JOIN categories c ON p.category_id = c.id
      WHERE p.created_by = :uid
      ORDER BY p.created_at DESC
      LIMIT 5
  ");
  $recent_stmt->execute(['uid' => $user_id]);
  $recent_products = $recent_stmt->fetchAll();
}
?>

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="style.css" />
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<title>Dashboard</title>
</head>
<body>

<nav class="navbar">
  <div class="navbar-left">
    <span class="company-name">MALL OF CAP</span>
  </div>
  <div class="navbar-right">
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
    <?php
      // This makes "Products" active even when on create/edit
      $products_active = ($action === 'products' || $action === 'create' || $action === 'edit');
    ?>
    <li><a href="dashboard.php" class="<?= ($action === '') ? 'active' : '' ?>">
        <span class="menu-icon">🏠</span> <span>Home</span>
    </a></li>
    <li><a href="dashboard.php?action=products" class="<?= $products_active ? 'active' : '' ?>">
        <span class="menu-icon">📦</span> <span>Products</span>
    </a></li>
    <li><a href="categories.php">
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

<?php if ($action === 'create'): ?>
  <h1>Add New Product</h1>
  <div class="card">
    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if (empty($categories)): ?>
      <p class="error">You must <a href="categories.php">create a category</a> before you can add a product.</p>
    <?php else: ?>
      <form method="post" action="dashboard.php?action=create">
        <div class="form-group">
          <label for="name">Product Name</label>
          <input type="text" id="name" name="name" placeholder="e.g. Wireless Mouse" required />
        </div>
        <div class="form-group">
          <label for="price">Price</label>
          <input type="number" id="price" step="0.01" name="price" placeholder="e.g. 29.99" required />
        </div>
        <div class="form-group">
          <label for="category_id">Category</label>
          <select name="category_id" id="category_id" required>
            <option value="">-- Select a Category --</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit">Create Product</button>
      </form>
    <?php endif; ?>
  </div>
  <p><a href="dashboard.php?action=products" class="button secondary-button">Back to Products</a></p>

<?php elseif ($action === 'edit' && isset($product)): ?>
  <h1>Edit Product</h1>
  <div class="card">
    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" action="dashboard.php?action=edit&id=<?= $product['id'] ?>">
      <div class="form-group">
        <label for="name">Product Name</label>
        <input type="text" id="name" name="name" value="<?= htmlspecialchars($product['name']) ?>" required />
      </div>
      <div class="form-group">
        <label for="price">Price</label>
        <input type="number" id="price" step="0.01" name="price" value="<?= htmlspecialchars($product['price']) ?>" required />
      </div>
      <div class="form-group">
        <label for="category_id">Category</label>
        <select name="category_id" id="category_id" required>
          <option value="">-- Select a Category --</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= ($cat['id'] == $product['category_id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($cat['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit">Update Product</button>
    </form>
  </div>
  <p><a href="dashboard.php?action=products" class="button secondary-button">Back to Products</a></p>

<?php elseif ($action === 'products'): ?>
  <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
    <h1>Your Products</h1>
    <a href="dashboard.php?action=create" class="button">+ Add New Product</a>
  </div>
  
  <div class="card">
    <?php if (empty($products)): ?>
      <p>You have no products yet.</p>
    <?php else: ?>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <?php
              function sort_link($column, $text, $current_sort, $current_order, $next_order) {
                  $arrow = '';
                  $order_for_link = 'asc';
                  if ($column === $current_sort) {
                      $order_for_link = $next_order;
                      $arrow = ($current_order === 'ASC') ? ' &uarr;' : ' &darr;';
                  }
                  echo "<th><a href=\"?action=products&sort=$column&order=$order_for_link\">$text$arrow</a></th>";
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
              <td><?= (new DateTime($p['created_at']))->format('M j, Y') ?></td>
              <td>
                <a href="dashboard.php?action=edit&id=<?= $p['id'] ?>" class="action-link">Edit</a>
                <a href="dashboard.php?action=delete&id=<?= $p['id'] ?>" class="action-link delete-link" onclick="return confirm('Are you sure?');">Delete</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div> <?php endif; ?>
  </div> <?php else: // $action === '' (Home Dashboard) ?>
  <h1>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</h1>
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