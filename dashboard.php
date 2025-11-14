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
if ($action === 'create' || $action === 'edit' || $action === 'products') {
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
    $description = trim($_POST['description'] ?? '');
    $price = $_POST['price'] ?? '';
    $category_id = $_POST['category_id'] ?? '';
    $status = $_POST['status'] ?? 'active';
    $tags = $_POST['tags'] ?? '';

    if ($name === '' || !is_numeric($price) || empty($category_id)) {
        $error = "Valid product name, price, and category are required.";
    } else {
        $pdo->beginTransaction();
        try {
            // Insert product
            $stmt = $pdo->prepare("INSERT INTO products (name, description, price, category_id, status, created_by) VALUES (:name, :desc, :price, :cid, :status, :uid)");
            $stmt->execute([
                'name' => $name, 
                'desc' => $description, 
                'price' => $price, 
                'cid' => $category_id, 
                'status' => $status, 
                'uid' => $user_id
            ]);
            $product_id = $pdo->lastInsertId();

            // Handle tags
            if (!empty($tags)) {
                $tag_names = array_map('trim', explode(',', $tags));
                foreach ($tag_names as $tag_name) {
                    if (!empty($tag_name)) {
                        // Insert or get tag ID
                        $tag_stmt = $pdo->prepare("INSERT INTO tags (name, created_by) VALUES (:name, :uid) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
                        $tag_stmt->execute(['name' => $tag_name, 'uid' => $user_id]);
                        $tag_id = $pdo->lastInsertId();

                        // Link product to tag
                        $link_stmt = $pdo->prepare("INSERT IGNORE INTO product_tags (product_id, tag_id) VALUES (:pid, :tid)");
                        $link_stmt->execute(['pid' => $product_id, 'tid' => $tag_id]);
                    }
                }
            }

            $pdo->commit();
            header("Location: dashboard.php?action=products"); // Redirect back to product list
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "An error occurred while creating the product.";
        }
    }
}

// Handle edit product
if ($action === 'edit' && isset($_GET['id'])) {
    $id = $_GET['id'];

    // Fetch product for editing (including tags)
    $stmt = $pdo->prepare("
        SELECT p.*, GROUP_CONCAT(t.name) as tags
        FROM products p
        LEFT JOIN product_tags pt ON p.id = pt.product_id
        LEFT JOIN tags t ON pt.tag_id = t.id
        WHERE p.id = :id AND p.created_by = :uid
        GROUP BY p.id
    ");
    $stmt->execute(['id' => $id, 'uid' => $user_id]);
    $product = $stmt->fetch();

    if (!$product) {
        die("Product not found or access denied.");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = $_POST['price'] ?? '';
        $category_id = $_POST['category_id'] ?? '';
        $status = $_POST['status'] ?? 'active';
        $tags = $_POST['tags'] ?? '';

        if ($name === '' || !is_numeric($price) || empty($category_id)) {
            $error = "Valid product name, price, and category are required.";
        } else {
            $pdo->beginTransaction();
            try {
                // Update product
                $stmt = $pdo->prepare("UPDATE products SET name = :name, description = :desc, price = :price, category_id = :cid, status = :status WHERE id = :id AND created_by = :uid");
                $stmt->execute([
                    'name' => $name, 
                    'desc' => $description, 
                    'price' => $price, 
                    'cid' => $category_id, 
                    'status' => $status, 
                    'id' => $id, 
                    'uid' => $user_id
                ]);

                // Remove existing tag associations
                $pdo->prepare("DELETE FROM product_tags WHERE product_id = :pid")->execute(['pid' => $id]);

                // Handle new tags
                if (!empty($tags)) {
                    $tag_names = array_map('trim', explode(',', $tags));
                    foreach ($tag_names as $tag_name) {
                        if (!empty($tag_name)) {
                            // Insert or get tag ID
                            $tag_stmt = $pdo->prepare("INSERT INTO tags (name, created_by) VALUES (:name, :uid) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
                            $tag_stmt->execute(['name' => $tag_name, 'uid' => $user_id]);
                            $tag_id = $pdo->lastInsertId();

                            // Link product to tag
                            $link_stmt = $pdo->prepare("INSERT IGNORE INTO product_tags (product_id, tag_id) VALUES (:pid, :tid)");
                            $link_stmt->execute(['pid' => $id, 'tid' => $tag_id]);
                        }
                    }
                }

                $pdo->commit();
                header("Location: dashboard.php?action=products"); // Redirect back to product list
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "An error occurred while updating the product.";
            }
        }
    }
}

// Fetch data for product list page
if ($action === 'products') {
    // Handle bulk actions first
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
        $action_type = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_products'] ?? [];
        
        if (!empty($selected_ids)) {
            if ($action_type === 'delete') {
                $placeholders = str_repeat('?,', count($selected_ids) - 1) . '?';
                $stmt = $pdo->prepare("DELETE FROM products WHERE id IN ($placeholders) AND created_by = ?");
                $stmt->execute(array_merge($selected_ids, [$user_id]));
                header("Location: dashboard.php?action=products");
                exit;
            } elseif ($action_type === 'export') {
                // For export, we'll handle it below
            }
        }
    }

    // 1. Get search and filter parameters
    $search = trim($_GET['search'] ?? '');
    $category_filter = $_GET['category'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $min_price = is_numeric($_GET['min_price'] ?? '') ? (float)$_GET['min_price'] : '';
    $max_price = is_numeric($_GET['max_price'] ?? '') ? (float)$_GET['max_price'] : '';

    // 2. Save search history if search query exists
    if (!empty($search)) {
        $history_stmt = $pdo->prepare("INSERT INTO search_history (user_id, search_query) VALUES (:uid, :query)");
        $history_stmt->execute(['uid' => $user_id, 'query' => $search]);
    }

    // 3. Whitelist of allowed columns for sorting
    $allowed_sort_cols = [
        'name' => 'p.name',
        'category' => 'c.name',
        'price' => 'p.price',
        'status' => 'p.status',
        'created_at' => 'p.created_at'
    ];
    // 4. Get sort parameters from URL, with defaults
    $sort_key = $_GET['sort'] ?? 'created_at';
    $order_key = $_GET['order'] ?? 'desc';
    // 5. Validate and set the SQL sort column
    if (!array_key_exists($sort_key, $allowed_sort_cols)) {
        $sort_key = 'created_at';
    }
    $sort_sql_col = $allowed_sort_cols[$sort_key];
    // 6. Validate and set the SQL sort order
    $order = strtoupper($order_key);
    if ($order !== 'ASC' && $order !== 'DESC') {
        $order = 'DESC';
    }
    // 7. Determine the *next* order for the links
    $next_order = ($order === 'ASC') ? 'desc' : 'asc';
    
    // 8. Build WHERE conditions
    $where_conditions = ['p.created_by = :uid'];
    $params = ['uid' => $user_id];
    
    if (!empty($search)) {
        $where_conditions[] = '(p.name LIKE :search OR p.description LIKE :search OR t.name LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }
    if (!empty($category_filter)) {
        $where_conditions[] = 'p.category_id = :category';
        $params['category'] = $category_filter;
    }
    if (!empty($status_filter)) {
        $where_conditions[] = 'p.status = :status';
        $params['status'] = $status_filter;
    }
    if ($min_price !== '') {
        $where_conditions[] = 'p.price >= :min_price';
        $params['min_price'] = $min_price;
    }
    if ($max_price !== '') {
        $where_conditions[] = 'p.price <= :max_price';
        $params['max_price'] = $max_price;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS category_name, GROUP_CONCAT(DISTINCT t.name) as tags
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_tags pt ON p.id = pt.product_id
        LEFT JOIN tags t ON pt.tag_id = t.id
        WHERE $where_clause 
        GROUP BY p.id
        ORDER BY $sort_sql_col $order
    ");
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    // 8. For export, generate CSV if requested
    if (isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'export' && !empty($selected_ids)) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="products_export.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Name', 'Description', 'Category', 'Tags', 'Price', 'Status', 'Created At']);
        
        foreach ($products as $p) {
            if (in_array($p['id'], $selected_ids)) {
                fputcsv($output, [
                    $p['id'],
                    $p['name'],
                    $p['description'] ?? '',
                    $p['category_name'] ?? 'N/A',
                    $p['tags'] ?? '',
                    $p['price'],
                    $p['status'],
                    $p['created_at']
                ]);
            }
        }
        fclose($output);
        exit;
    }

    // 9. Pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 10;
    $total_products = count($products);
    $total_pages = ceil($total_products / $per_page);
    $offset = ($page - 1) * $per_page;
    $products = array_slice($products, $offset, $per_page);
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

// ALL PHP LOGIC MUST BE ABOVE THIS LINE
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
          <label for="description">Description</label>
          <textarea id="description" name="description" placeholder="Product description..." rows="3"></textarea>
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
        <div class="form-group">
          <label for="status">Status</label>
          <select name="status" id="status">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        <div class="form-group">
          <label for="tags">Tags (comma-separated)</label>
          <input type="text" id="tags" name="tags" placeholder="e.g. electronics, wireless, gaming" />
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
        <label for="description">Description</label>
        <textarea id="description" name="description" placeholder="Product description..." rows="3"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
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
      <div class="form-group">
        <label for="status">Status</label>
        <select name="status" id="status">
          <option value="active" <?= ($product['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= ($product['status'] ?? 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="form-group">
        <label for="tags">Tags (comma-separated)</label>
        <input type="text" id="tags" name="tags" value="<?= htmlspecialchars($product['tags'] ?? '') ?>" placeholder="e.g. electronics, wireless, gaming" />
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
  
  <!-- Search and Filter Form -->
  <div class="card search-filter-card">
    <h3>Search & Filter Products</h3>
    <form method="get" action="dashboard.php" class="search-form">
      <input type="hidden" name="action" value="products" />
      <div class="form-row">
        <div class="form-group search-group">
          <label for="search">Search by Name</label>
          <input type="text" id="search" name="search" value="<?= htmlspecialchars($search ?? '') ?>" placeholder="Enter product name, description, or tags" autocomplete="off" />
          <div id="search-history" class="search-history-dropdown">
            <?php
            $history_stmt = $pdo->prepare("SELECT DISTINCT search_query FROM search_history WHERE user_id = :uid ORDER BY created_at DESC LIMIT 5");
            $history_stmt->execute(['uid' => $user_id]);
            $search_history = $history_stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($search_history)): ?>
              <div class="search-history-header">Recent Searches:</div>
              <?php foreach ($search_history as $query): ?>
                <div class="search-history-item" data-query="<?= htmlspecialchars($query) ?>"><?= htmlspecialchars($query) ?></div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="form-group">
          <label for="category">Category</label>
          <select name="category" id="category">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>" <?= ($category_filter == $cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="status">Status</label>
          <select name="status" id="status">
            <option value="">All Status</option>
            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
        <div class="form-group">
          <label for="min_price">Min Price</label>
          <input type="number" id="min_price" name="min_price" step="0.01" value="<?= htmlspecialchars($min_price ?? '') ?>" placeholder="0.00" />
        </div>
        <div class="form-group">
          <label for="max_price">Max Price</label>
          <input type="number" id="max_price" name="max_price" step="0.01" value="<?= htmlspecialchars($max_price ?? '') ?>" placeholder="999.99" />
        </div>
        <div class="form-group button-group">
          <button type="submit" class="button">Search</button>
          <a href="dashboard.php?action=products" class="button secondary-button">Clear</a>
        </div>
      </div>
    </form>
  </div>
  
  <!-- Bulk Actions Form -->
  <form method="post" action="dashboard.php?action=products" id="bulk-form" class="bulk-actions-form">
    <div class="bulk-actions-bar">
      <select name="bulk_action" id="bulk_action">
        <option value="">-- Choose Action --</option>
        <option value="delete">Delete Selected</option>
        <option value="export">Export Selected to CSV</option>
      </select>
      <button type="submit" class="button" onclick="return confirmBulkAction();">Apply</button>
    </div>
    
    <div class="card">
      <?php if (empty($products)): ?>
        <p>You have no products yet.</p>
      <?php else: ?>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th><input type="checkbox" id="select-all" /></th>
                <?php
                function sort_link($column, $text, $current_sort, $current_order, $next_order, $search, $category_filter, $min_price, $max_price) {
                    $params = ['action' => 'products', 'sort' => $column, 'order' => ($column === $current_sort ? $next_order : 'asc')];
                    if (!empty($search)) $params['search'] = $search;
                    if (!empty($category_filter)) $params['category'] = $category_filter;
                    if ($min_price !== '') $params['min_price'] = $min_price;
                    if ($max_price !== '') $params['max_price'] = $max_price;
                    $query = http_build_query($params);
                    $arrow = '';
                    if ($column === $current_sort) {
                        $arrow = ($current_order === 'ASC') ? ' &uarr;' : ' &darr;';
                    }
                    echo "<th><a href='?{$query}'>{$text}{$arrow}</a></th>";
                }
                
                sort_link('name', 'Name', $sort_key, $order, $next_order, $search ?? '', $category_filter ?? '', $min_price ?? '', $max_price ?? '', $status_filter ?? '');
                sort_link('category', 'Category', $sort_key, $order, $next_order, $search ?? '', $category_filter ?? '', $min_price ?? '', $max_price ?? '', $status_filter ?? '');
                sort_link('price', 'Price', $sort_key, $order, $next_order, $search ?? '', $category_filter ?? '', $min_price ?? '', $max_price ?? '', $status_filter ?? '');
                sort_link('status', 'Status', $sort_key, $order, $next_order, $search ?? '', $category_filter ?? '', $min_price ?? '', $max_price ?? '', $status_filter ?? '');
                sort_link('created_at', 'Created At', $sort_key, $order, $next_order, $search ?? '', $category_filter ?? '', $min_price ?? '', $max_price ?? '', $status_filter ?? '');
                ?>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($products as $p): ?>
              <tr>
                <td><input type="checkbox" name="selected_products[]" value="<?= $p['id'] ?>" class="product-checkbox" /></td>
                <td>
                  <div class="product-name-cell">
                    <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
                    <?php if (!empty($p['description'])): ?>
                      <div class="product-description-preview" title="<?= htmlspecialchars($p['description']) ?>">
                        <?= htmlspecialchars(substr($p['description'], 0, 50)) ?><?php if (strlen($p['description']) > 50): ?>...<?php endif; ?>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($p['tags'])): ?>
                      <div class="product-tags">
                        <?php foreach (explode(',', $p['tags']) as $tag): ?>
                          <span class="tag-badge"><?= htmlspecialchars(trim($tag)) ?></span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </td>
                <td><?= htmlspecialchars($p['category_name'] ?? 'N/A') ?></td>
                <td>$<?= number_format($p['price'], 2) ?></td>
                <td>
                  <span class="status-badge status-<?= $p['status'] ?? 'active' ?>">
                    <?= ucfirst($p['status'] ?? 'active') ?>
                  </span>
                </td>
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

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php
          $params = $_GET;
          unset($params['page']); // Remove page from params for clean URLs

          // Previous button
          if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($params, ['page' => $page - 1])) ?>" class="pagination-link">&laquo; Previous</a>
          <?php endif; ?>

          <?php
          $start_page = max(1, $page - 2);
          $end_page = min($total_pages, $page + 2);

          // First page
          if ($start_page > 1): ?>
            <a href="?<?= http_build_query(array_merge($params, ['page' => 1])) ?>" class="pagination-link">1</a>
            <?php if ($start_page > 2): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
          <?php endif; ?>

          // Page numbers
          <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
            <?php if ($i == $page): ?>
              <span class="pagination-link current"><?= $i ?></span>
            <?php else: ?>
              <a href="?<?= http_build_query(array_merge($params, ['page' => $i])) ?>" class="pagination-link"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          // Last page
          <?php if ($end_page < $total_pages): ?>
            <?php if ($end_page < $total_pages - 1): ?><span class="pagination-ellipsis">...</span><?php endif; ?>
            <a href="?<?= http_build_query(array_merge($params, ['page' => $total_pages])) ?>" class="pagination-link"><?= $total_pages ?></a>
          <?php endif; ?>

          // Next button
          <?php if ($page < $total_pages): ?>
            <a href="?<?= http_build_query(array_merge($params, ['page' => $page + 1])) ?>" class="pagination-link">Next &raquo;</a>
          <?php endif; ?>
        </div>
        <div class="pagination-info">
          Showing <?= ($offset + 1) ?>-<?= min($offset + $per_page, $total_products) ?> of <?= $total_products ?> products
        </div>
        <?php endif; ?>
      </div>
    </form>
  
  <script>
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

      // Search history functionality
      const searchInput = document.getElementById('search');
      const searchHistory = document.getElementById('search-history');

      if (searchInput && searchHistory) {
        // Show dropdown when input is focused
        searchInput.addEventListener('focus', () => {
          if (searchHistory.children.length > 0) {
            searchHistory.classList.add('show');
          }
        });

        // Hide dropdown when clicking outside
        document.addEventListener('click', (e) => {
          if (!searchInput.contains(e.target) && !searchHistory.contains(e.target)) {
            searchHistory.classList.remove('show');
          }
        });

        // Handle search history item clicks
        searchHistory.addEventListener('click', (e) => {
          if (e.target.classList.contains('search-history-item')) {
            searchInput.value = e.target.dataset.query;
            searchHistory.classList.remove('show');
            // Optionally submit the form
            // searchInput.form.submit();
          }
        });
      }
    });
  </script> <?php else: // $action === '' (The new Home Dashboard) ?>
  <h1>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</h1>
  <p>Here is a summary of your account.</p>
  
  <div class="stat-container">
      <div class="stat-box">
          <div class="stat-icon">📦</div>
          <div>
              <h3>Total Products</h3>
              <p><?= $total_products ?></p>
          </div>
      </div>
      <div class="stat-box">
          <div class="stat-icon">🗂️</div>
          <div>
              <h3>Total Categories</h3>
              <p><?= $total_categories ?></p>
          </div>
      </div>
      <div class="stat-box">
          <div class="stat-icon">💰</div>
          <div>
              <h3>Inventory Value</h3>
              <p>$<?= number_format($total_value ?? 0, 2) ?></p>
          </div>
      </div>
  </div>

  <div class="dashboard-grid" id="dashboard-grid">
    <div class="dashboard-col-main">
      
      <div class="card">
        <h2>Quick Actions</h2>
        <div class="quick-actions">
          <a href="dashboard.php?action=create" class="button">
            <span class="button-icon">+</span> Add New Product
          </a>
          <a href="categories.php" class="button secondary-button">
            Manage Categories
          </a>
        </div>
      </div>

      <div class="card">
        <h2>Product Overview</h2>
        <?php if (!empty($chart_data)): ?>
          <div class="chart-container">
            <canvas id="categoryPieChart"></canvas>
          </div>
        <?php else: ?>
          <p>You have no products with categories to display in the chart.</p>
        <?php endif; ?>
      </div>

    </div>
    <div class="dashboard-col-side">
      
      <div class="card">
        <h2>Recently Added</h2>
        <?php if (empty($recent_products)): ?>
          <p>No products added yet.</p>
        <?php else: ?>
          <table class="recent-products-list">
            <tbody>
              <?php foreach ($recent_products as $p): ?>
                <tr>
                  <td>
                    <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
                    <div class="category-name"><?= htmlspecialchars($p['category_name'] ?? 'N/A') ?></div>
                  </td>
                  <td class="product-price">
                    $<?= number_format($p['price'], 2) ?>
                  </td>
                  <td>
                    <a href="dashboard.php?action=edit&id=<?= $p['id'] ?>" class="button-small">Edit</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    </div>
  </div>


  <?php if (!empty($chart_data)): ?>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const ctx = document.getElementById('categoryPieChart').getContext('2d');
      
      new Chart(ctx, {
        type: 'pie',
        data: {
          labels: <?= $chart_labels; ?>,
          datasets: [{
            label: 'Products',
            data: <?= $chart_values; ?>,
            backgroundColor: [
              '#007bff',
              '#28a745',
              '#ffc107',
              '#dc3545',
              '#17a2b8',
              '#6c757d',
              '#343a40'
            ],
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: {
              position: 'right',
            }
          }
        }
      });
    });
  </script>
  <?php endif; ?>

<?php endif; ?>
</main>

</body>
</html>