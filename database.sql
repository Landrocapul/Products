-- CREATE DATABASE lazada CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE lazada;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('seller', 'consumer', 'admin') DEFAULT 'consumer',
    reset_token VARCHAR(255) NULL,
    reset_expires DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert sample users
INSERT INTO users (username, email, password, role) VALUES
('seller1', 'seller1@gmail.com', '$2y$10$i0T/gVP2dfKqGRaAMNmhO.Xx973IjrJkJqI0Z9dpdZORdiB0hs8oe', 'seller'),
('consumer1', 'consumer1@gmail.com', '$2y$10$i0T/gVP2dfKqGRaAMNmhO.Xx973IjrJkJqI0Z9dpdZORdiB0hs8oe', 'consumer');

-- Categories table
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_category_per_user (name, created_by)
);

-- Insert sample categories
INSERT INTO categories (name, created_by) VALUES
('Electronics', 1),
('Clothing', 1),
('Home & Garden', 1),
('Sports & Outdoors', 1),
('Books', 1);

-- Products table
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL,
    category_id INT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    stock_quantity INT DEFAULT 0,
    seller_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- Insert sample products
INSERT INTO products (name, description, price, category_id, status, stock_quantity, seller_id) VALUES
('Wireless Bluetooth Headphones', 'High-quality wireless headphones with noise cancellation and 30-hour battery life.', 89.99, 1, 'active', 50, 1),
('Mechanical Gaming Keyboard', 'RGB backlit mechanical keyboard with blue switches for gaming enthusiasts.', 129.99, 1, 'active', 25, 1),
('Cotton T-Shirt', 'Comfortable 100% cotton t-shirt available in multiple colors and sizes.', 19.99, 2, 'active', 100, 1),
('Garden Hose 50ft', 'Durable garden hose with brass fittings and adjustable spray nozzle.', 34.99, 3, 'active', 30, 1),
('Yoga Mat', 'Non-slip yoga mat perfect for home workouts and fitness classes.', 24.99, 4, 'active', 40, 1),
('Programming Book', 'Comprehensive guide to modern web development with practical examples.', 49.99, 5, 'active', 15, 1),
('Smart Watch', 'Fitness tracking smartwatch with heart rate monitor and GPS.', 199.99, 1, 'active', 20, 1),
('Denim Jeans', 'Classic fit denim jeans made from premium cotton blend.', 79.99, 2, 'active', 35, 1),
('LED Desk Lamp', 'Adjustable LED desk lamp with multiple brightness settings.', 45.99, 3, 'active', 22, 1),
('Basketball', 'Professional grade basketball for indoor and outdoor use.', 29.99, 4, 'active', 18, 1);

-- Tags table
CREATE TABLE tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_tag_per_user (name, created_by)
);

-- Product tags junction table (many-to-many)
CREATE TABLE product_tags (
    product_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (product_id, tag_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

-- Search history table
CREATE TABLE search_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    search_query VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Shopping cart table
CREATE TABLE cart (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_cart_item (user_id, product_id)
);

-- Orders table
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(20) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    shipping_address TEXT NULL,
    payment_method VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Order items table
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
