<?php
session_start();
require 'db.php';

// Check if user is logged in as consumer
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'consumer') {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Debug: Check user session
error_log("User ID: " . $user_id);
error_log("User Role: " . $user_role);

$action = $_GET['action'] ?? 'browse';

// Handle add to cart
if ($action === 'add_to_cart' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = (int)$_POST['product_id'];
    $quantity = (int)($_POST['quantity'] ?? 1);

    error_log("Add to cart - User ID: " . $user_id . ", Product ID: " . $product_id . ", Quantity: " . $quantity);

    if ($quantity > 0) {
        // Check if item already in cart
        $stmt = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = :uid AND product_id = :pid");
        $stmt->execute(['uid' => $user_id, 'pid' => $product_id]);
        $existing = $stmt->fetch();

        error_log("Existing cart item: " . print_r($existing, true));

        if ($existing) {
            // Update quantity
            $stmt = $pdo->prepare("UPDATE cart SET quantity = quantity + :qty WHERE id = :id");
            $stmt->execute(['qty' => $quantity, 'id' => $existing['id']]);
            error_log("Updated cart item quantity");
        } else {
            // Add new item
            $stmt = $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (:uid, :pid, :qty)");
            $stmt->execute(['uid' => $user_id, 'pid' => $product_id, 'qty' => $quantity]);
            error_log("Added new cart item");
        }
    }
    header("Location: shop.php?added=1");
    exit;
}

// Handle cart operations
if ($action === 'cart') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_cart'])) {
            foreach ($_POST['quantities'] as $cart_id => $quantity) {
                $quantity = (int)$quantity;
                if ($quantity <= 0) {
                    $stmt = $pdo->prepare("DELETE FROM cart WHERE id = :id AND user_id = :uid");
                    $stmt->execute(['id' => $cart_id, 'uid' => $user_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE cart SET quantity = :qty WHERE id = :id AND user_id = :uid");
                    $stmt->execute(['qty' => $quantity, 'id' => $cart_id, 'uid' => $user_id]);
                }
            }
        } elseif (isset($_POST['remove_item'])) {
            $cart_id = (int)$_POST['cart_id'];
            $stmt = $pdo->prepare("DELETE FROM cart WHERE id = :id AND user_id = :uid");
            $stmt->execute(['id' => $cart_id, 'uid' => $user_id]);
        } elseif (isset($_POST['checkout'])) {
            // Redirect to checkout
            header("Location: shop.php?action=checkout");
            exit;
        }
    }

    // Get cart items
    $stmt = $pdo->prepare("
        SELECT c.*, p.name, p.price, p.stock_quantity, p.status,
               (c.quantity * p.price) as total
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = :uid AND p.status = 'active'
        ORDER BY c.added_at DESC
    ");
    $stmt->execute(['uid' => $user_id]);
    $cart_items = $stmt->fetchAll();

    // Debug: Check cart contents
    error_log("User ID: " . $user_id);
    error_log("Cart items count: " . count($cart_items));
    if (!empty($cart_items)) {
        error_log("First cart item: " . print_r($cart_items[0], true));
    }

    $cart_total = array_sum(array_column($cart_items, 'total'));
}

// Handle checkout
if ($action === 'checkout') {
    // Get cart items for checkout
    $stmt = $pdo->prepare("
        SELECT c.*, p.name, p.price, p.stock_quantity,
               (c.quantity * p.price) as total
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = :uid AND p.status = 'active'
        ORDER BY c.added_at DESC
    ");
    $stmt->execute(['uid' => $user_id]);
    $checkout_items = $stmt->fetchAll();
    $checkout_total = array_sum(array_column($checkout_items, 'total'));

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
        // Validate required fields
        $shipping_name = trim($_POST['shipping_name'] ?? '');
        $shipping_email = trim($_POST['shipping_email'] ?? '');
        $shipping_address = trim($_POST['shipping_address'] ?? '');
        $payment_method = $_POST['payment_method'] ?? '';

        if (empty($shipping_name) || empty($shipping_email) || empty($shipping_address) || empty($payment_method)) {
            $checkout_error = "All fields are required.";
        } elseif (empty($checkout_items)) {
            $checkout_error = "Your cart is empty.";
        } else {
            // Generate order number
            $order_number = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Start transaction
            $pdo->beginTransaction();
            try {
                // Create order
                $order_stmt = $pdo->prepare("
                    INSERT INTO orders (order_number, user_id, total_amount, status, shipping_address, payment_method)
                    VALUES (:order_num, :user_id, :total, 'pending', :address, :payment)
                ");
                $order_stmt->execute([
                    'order_num' => $order_number,
                    'user_id' => $user_id,
                    'total' => $checkout_total,
                    'address' => $shipping_address,
                    'payment' => $payment_method
                ]);

                $order_id = $pdo->lastInsertId();

                // Create order items and update stock
                foreach ($checkout_items as $item) {
                    // Insert order item
                    $item_stmt = $pdo->prepare("
                        INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price)
                        VALUES (:order_id, :product_id, :quantity, :unit_price, :total_price)
                    ");
                    $item_stmt->execute([
                        'order_id' => $order_id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['price'],
                        'total_price' => $item['total']
                    ]);

                    // Update product stock
                    $stock_stmt = $pdo->prepare("
                        UPDATE products SET stock_quantity = stock_quantity - :qty
                        WHERE id = :product_id AND stock_quantity >= :qty
                    ");
                    $stock_stmt->execute([
                        'qty' => $item['quantity'],
                        'product_id' => $item['product_id']
                    ]);
                }

                // Clear cart
                $clear_cart_stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = :uid");
                $clear_cart_stmt->execute(['uid' => $user_id]);

                $pdo->commit();

                // Redirect to success page
                header("Location: shop.php?action=order_success&order_id=" . $order_id);
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $checkout_error = "Failed to process order. Please try again.";
            }
        }
    }
}

// Handle order success
if ($action === 'order_success') {
    $order_id = (int)($_GET['order_id'] ?? 0);
    if ($order_id > 0) {
        // Get order details
        $order_stmt = $pdo->prepare("
            SELECT o.*, GROUP_CONCAT(oi.quantity, 'x ', p.name SEPARATOR '; ') as items
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE o.id = :order_id AND o.user_id = :user_id
            GROUP BY o.id
        ");
        $order_stmt->execute(['order_id' => $order_id, 'user_id' => $user_id]);
        $order_details = $order_stmt->fetch();
    }
}

// Get cart count for header
$cart_count_stmt = $pdo->prepare("SELECT SUM(quantity) FROM cart WHERE user_id = :uid");
$cart_count_stmt->execute(['uid' => $user_id]);
$cart_count = $cart_count_stmt->fetchColumn() ?? 0;

// Handle product browsing
if ($action === 'browse') {
    // Get search and filter parameters
    $search = trim($_GET['search'] ?? '');
    $category_filter = $_GET['category'] ?? '';
    $min_price = is_numeric($_GET['min_price'] ?? '') ? (float)$_GET['min_price'] : '';
    $max_price = is_numeric($_GET['max_price'] ?? '') ? (float)$_GET['max_price'] : '';

    // Save search history if search query exists
    if (!empty($search)) {
        $history_stmt = $pdo->prepare("INSERT INTO search_history (user_id, search_query) VALUES (:uid, :query)");
        $history_stmt->execute(['uid' => $user_id, 'query' => $search]);
    }

    // Build WHERE conditions
    $where_conditions = ["p.status = 'active'"];
    $params = [];

    if (!empty($search)) {
        $where_conditions[] = '(p.name LIKE :search OR p.description LIKE :search OR t.name LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }
    if (!empty($category_filter)) {
        $where_conditions[] = 'p.category_id = :category';
        $params['category'] = $category_filter;
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

    // Get products
    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS category_name, u.username AS seller_name, GROUP_CONCAT(DISTINCT t.name) as tags
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN users u ON p.seller_id = u.id
        LEFT JOIN product_tags pt ON p.id = pt.product_id
        LEFT JOIN tags t ON pt.tag_id = t.id
        WHERE $where_clause
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    // Get categories for filter
    $cat_stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
    $categories = $cat_stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <title>MALL OF CAP - Online Store</title>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-light">
        <div class="container-fluid">
            <a href="shop.php" class="navbar-brand">MALL OF CAP</a>
            <div class="d-flex align-items-center">
                <a href="shop.php?action=cart" class="btn btn-outline-primary me-3 position-relative">
                    🛒 Cart
                    <?php if ($cart_count > 0): ?>
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle"><?= $cart_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="account.php" class="btn btn-outline-secondary me-3">👤 Account</a>
                <button class="btn btn-outline-secondary" title="Toggle Theme">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M8 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6zm0 1a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM8 0a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm0 13a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm8-5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2a.5.5 0 0 1 .5.5zM3 8a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2A.5.5 0 0 1 3 8zm10.657-5.657a.5.5 0 0 1 0 .707l-1.414 1.415a.5.5 0 1 1-.707-.708l1.414-1.414a.5.5 0 0 1 .707 0zm-9.193 9.193a.5.5 0 0 1 0 .707L3.05 13.657a.5.5 0 0 1-.707-.707l1.414-1.414a.5.5 0 0 1 .707 0zm9.193-9.193a.5.5 0 0 1-.707 0l-1.414-1.414a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707zM4.464 4.465a.5.5 0 0 1-.707 0L2.343 3.05a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707z"/>
                    </svg>
                </button>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-3 col-md-4">
                <div class="bg-light p-3 rounded shadow-sm mb-4">
                    <h4 class="mb-4">🔍 Search & Filter</h4>

                    <!-- Search Form -->
                    <div class="mb-4">
                        <h5 class="mb-3">Search Products</h5>
                        <form method="get" action="shop.php">
                            <input type="hidden" name="action" value="browse">
                            <div class="mb-3">
                                <input type="text" name="search" class="form-control" placeholder="Search products..." value="<?= htmlspecialchars($search ?? '') ?>" autocomplete="off">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">🔍 Search</button>
                        </form>
                    </div>

                    <!-- Filters -->
                    <div class="mb-4">
                        <h5 class="mb-3">Filters</h5>
                        <form method="get" action="shop.php">
                            <input type="hidden" name="action" value="browse">
                            <input type="hidden" name="search" value="<?= htmlspecialchars($search ?? '') ?>">

                            <div class="mb-3">
                                <label class="form-label">Category</label>
                                <select name="category" class="form-select">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>" <?= ($category_filter == $cat['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Price Range</label>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input type="number" name="min_price" class="form-control" placeholder="Min" step="0.01" value="<?= htmlspecialchars($min_price ?? '') ?>">
                                    </div>
                                    <div class="col-6">
                                        <input type="number" name="max_price" class="form-control" placeholder="Max" step="0.01" value="<?= htmlspecialchars($max_price ?? '') ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success">Apply Filters</button>
                                <a href="shop.php?action=browse" class="btn btn-outline-secondary">Clear All</a>
                            </div>
                        </form>
                    </div>

                    <!-- Quick Links -->
                    <div>
                        <h5 class="mb-3">Quick Links</h5>
                        <div class="list-group list-group-flush">
                            <a href="shop.php?action=browse" class="list-group-item list-group-item-action">🏠 All Products</a>
                            <a href="shop.php?action=cart" class="list-group-item list-group-item-action">🛒 My Cart (<?= $cart_count ?>)</a>
                            <a href="account.php" class="list-group-item list-group-item-action">👤 My Account</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-lg-9 col-md-8">
                <div class="container-fluid">
                    <?php if ($action === 'browse'): ?>
                        <!-- Product Browse Page -->
                        <div class="text-center mb-5">
                            <h1 class="display-4 mb-3">Welcome to MALL OF CAP</h1>
                            <p class="lead text-muted">Discover amazing products from our trusted sellers</p>
                            <?php if (!empty($search) || !empty($category_filter) || !empty($min_price) || !empty($max_price)): ?>
                                <div class="alert alert-info">
                                    <strong>Active filters:</strong>
                                    <?php if (!empty($search)): ?><span class="badge bg-primary me-2">Search: "<?= htmlspecialchars($search) ?>"</span><?php endif; ?>
                                    <?php if (!empty($category_filter)): ?><span class="badge bg-secondary me-2">Category: <?= htmlspecialchars($categories[array_search($category_filter, array_column($categories, 'id'))]['name'] ?? '') ?></span><?php endif; ?>
                                    <?php if (!empty($min_price)): ?><span class="badge bg-success me-2">Min: $<?= htmlspecialchars($min_price) ?></span><?php endif; ?>
                                    <?php if (!empty($max_price)): ?><span class="badge bg-success me-2">Max: $<?= htmlspecialchars($max_price) ?></span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Products Header -->
                        <?php if (!empty($products)): ?>
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <p class="mb-0 text-muted">Showing <?= count($products) ?> product<?= count($products) !== 1 ? 's' : '' ?></p>
                            </div>
                        <?php endif; ?>

                        <!-- Products Grid -->
                        <div class="row g-4">
                            <?php if (empty($products)): ?>
                                <div class="col-12">
                                    <div class="text-center py-5">
                                        <div class="mb-4">
                                            <h3 class="text-muted">No products found</h3>
                                            <p class="text-muted">Try adjusting your search or filters</p>
                                        </div>
                                        <a href="shop.php?action=browse" class="btn btn-primary">Clear all filters</a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($products as $product): ?>
                                    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
                                        <div class="card h-100 shadow-sm">
                                            <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height: 200px;">
                                                <div class="text-center text-muted">
                                                    📦<br><small>Product Image</small>
                                                </div>
                                            </div>
                                            <div class="card-body d-flex flex-column">
                                                <h5 class="card-title mb-2">
                                                    <a href="product.php?id=<?= $product['id'] ?>" class="text-decoration-none text-dark">
                                                        <?= htmlspecialchars($product['name']) ?>
                                                    </a>
                                                </h5>
                                                <div class="mb-2">
                                                    <span class="badge bg-primary">$<?= number_format($product['price'], 2) ?></span>
                                                </div>
                                                <div class="mb-2">
                                                    <small class="text-muted">👤 Sold by: <?= htmlspecialchars($product['seller_name'] ?? 'Unknown') ?></small>
                                                </div>
                                                <p class="card-text text-muted small mb-3">
                                                    <?= htmlspecialchars(substr($product['description'] ?? '', 0, 80)) ?>
                                                    <?php if (strlen($product['description'] ?? '') > 80): ?>...<?php endif; ?>
                                                </p>
                                                <div class="mt-auto">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <small class="text-muted">📁 <?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?></small>
                                                        <small class="text-muted">📦 Stock: <?= htmlspecialchars($product['stock_quantity'] ?? 0) ?></small>
                                                    </div>
                                                    <?php if (!empty($product['tags'])): ?>
                                                        <div class="mb-3">
                                                            <?php foreach (explode(',', $product['tags']) as $tag): ?>
                                                                <span class="badge bg-light text-dark me-1 mb-1"><?= htmlspecialchars(trim($tag)) ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <a href="product.php?id=<?= $product['id'] ?>" class="btn btn-primary btn-sm w-100">
                                                        View Details
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                <?php elseif ($action === 'cart'): ?>
                    <!-- Shopping Cart Page -->
                    <h1>Your Shopping Cart</h1>

                    <!-- Debug Info -->
                    <div style="background: #f0f0f0; padding: 10px; margin-bottom: 20px; border: 1px solid #ccc;">
                        <strong>Debug Info:</strong><br>
                        User ID: <?= $user_id ?><br>
                        User Role: <?= $user_role ?><br>
                        Cart Items Count: <?= count($cart_items) ?><br>
                        Cart Total: $<?= number_format($cart_total, 2) ?><br>
                        Session Data: <?= print_r($_SESSION, true) ?>
                    </div>

                    <?php if (isset($_GET['updated'])): ?>
                        <div class="success-message">Cart updated successfully!</div>
                    <?php endif; ?>

                    <?php if (empty($cart_items)): ?>
                        <div class="empty-cart">
                            <h3>Your cart is empty</h3>
                            <p><a href="shop.php?action=browse">Continue Shopping</a></p>
                        </div>
                    <?php else: ?>
                        <div class="cart-container">
                            <form method="post" action="shop.php?action=cart">
                                <div class="cart-items">
                                    <?php foreach ($cart_items as $item): ?>
                                        <div class="cart-item">
                                            <div class="cart-item-info">
                                                <h4><?= htmlspecialchars($item['name']) ?></h4>
                                                <p>$<?= number_format($item['price'], 2) ?> each</p>
                                            </div>
                                            <div class="cart-item-controls">
                                                <input type="number" name="quantities[<?= $item['id'] ?>]" value="<?= $item['quantity'] ?>" min="0" max="<?= $item['stock_quantity'] ?>" class="cart-quantity">
                                                <span class="item-total">$<?= number_format($item['total'], 2) ?></span>
                                                <button type="submit" name="remove_item" value="1" class="remove-btn" onclick="return confirm('Remove this item?')">
                                                    <input type="hidden" name="cart_id" value="<?= $item['id'] ?>">
                                                    Remove
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="cart-summary">
                                    <div class="cart-total">
                                        <strong>Total: $<?= number_format($cart_total, 2) ?></strong>
                                    </div>
                                    <div class="cart-actions">
                                        <button type="submit" name="update_cart" class="update-cart-btn">Update Cart</button>
                                        <button type="submit" name="checkout" class="checkout-btn">Proceed to Checkout</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                <?php elseif ($action === 'checkout'): ?>
                    <!-- Checkout Page -->
                    <h1>Checkout</h1>

                    <?php if (isset($checkout_error)): ?>
                        <div class="error-message" style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 20px;">
                            <?= htmlspecialchars($checkout_error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($checkout_items)): ?>
                        <div class="checkout-notice">
                            <p>Your cart is empty.</p>
                            <a href="shop.php?action=browse">← Continue Shopping</a>
                        </div>
                    <?php else: ?>
                        <div class="checkout-container">
                            <!-- Order Summary -->
                            <div class="checkout-summary">
                                <h2>Order Summary</h2>
                                <div class="checkout-items">
                                    <?php foreach ($checkout_items as $item): ?>
                                        <div class="checkout-item">
                                            <div class="item-info">
                                                <h4><?= htmlspecialchars($item['name']) ?></h4>
                                                <p>$<?= number_format($item['price'], 2) ?> × <?= $item['quantity'] ?></p>
                                            </div>
                                            <div class="item-total">
                                                $<?= number_format($item['total'], 2) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="checkout-total">
                                    <strong>Total: $<?= number_format($checkout_total, 2) ?></strong>
                                </div>
                            </div>

                            <!-- Checkout Form -->
                            <div class="checkout-form-section">
                                <h2>Shipping & Payment</h2>
                                <form method="post" action="shop.php?action=checkout">
                                    <div class="form-group">
                                        <label for="shipping_name">Full Name</label>
                                        <input type="text" id="shipping_name" name="shipping_name" required
                                               value="<?= htmlspecialchars($_SESSION['username'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="shipping_email">Email Address</label>
                                        <input type="email" id="shipping_email" name="shipping_email" required
                                               value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="shipping_address">Shipping Address</label>
                                        <textarea id="shipping_address" name="shipping_address" rows="3" required
                                                  placeholder="Enter your full shipping address"></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label for="payment_method">Payment Method</label>
                                        <select name="payment_method" id="payment_method" required>
                                            <option value="">Select Payment Method</option>
                                            <option value="credit_card">Credit Card</option>
                                            <option value="debit_card">Debit Card</option>
                                            <option value="paypal">PayPal</option>
                                            <option value="bank_transfer">Bank Transfer</option>
                                            <option value="cash_on_delivery">Cash on Delivery</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="place_order" class="checkout-submit-btn">
                                        Place Order - $<?= number_format($checkout_total, 2) ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="checkout-actions">
                            <a href="shop.php?action=cart" class="button secondary-button">← Back to Cart</a>
                        </div>
                    <?php endif; ?>

                <?php elseif ($action === 'order_success'): ?>
                    <!-- Order Success Page -->
                    <h1>🎉 Order Placed Successfully!</h1>

                    <?php if (isset($order_details)): ?>
                        <div class="order-success">
                            <div class="success-icon">✅</div>
                            <h2>Thank you for your order!</h2>
                            <div class="order-details">
                                <p><strong>Order Number:</strong> <?= htmlspecialchars($order_details['order_number']) ?></p>
                                <p><strong>Total Amount:</strong> $<?= number_format($order_details['total_amount'], 2) ?></p>
                                <p><strong>Status:</strong> <?= ucfirst($order_details['status']) ?></p>
                                <p><strong>Items:</strong> <?= htmlspecialchars($order_details['items']) ?></p>
                                <p><strong>Shipping Address:</strong><br>
                                   <?= nl2br(htmlspecialchars($order_details['shipping_address'])) ?></p>
                                <p><strong>Payment Method:</strong> <?= ucfirst(str_replace('_', ' ', $order_details['payment_method'])) ?></p>
                            </div>
                            <div class="order-actions">
                                <a href="shop.php?action=browse" class="button">Continue Shopping</a>
                                <a href="account.php" class="button secondary-button">View Account</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="error-message">
                            <p>Order details not found.</p>
                            <a href="shop.php?action=browse">← Continue Shopping</a>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>
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
