-- ======================================
-- خشتەی بەکارهێنەران (Users/Admins)
-- ======================================

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE COMMENT 'ناوی بەکارهێنەر بۆ لۆگین',
    email VARCHAR(255) NOT NULL UNIQUE COMMENT 'ئیمەیڵ',
    password VARCHAR(255) NOT NULL COMMENT 'پاسوۆردی hash کراو',
    full_name VARCHAR(255) NOT NULL COMMENT 'ناوی تەواو',
    phone VARCHAR(20) COMMENT 'ژمارەی مۆبایل',
    avatar VARCHAR(1000) COMMENT 'وێنەی پرۆفایل',
    role ENUM('admin', 'employee') DEFAULT 'employee' COMMENT 'ڕۆڵ: admin یان employee',
    is_active TINYINT(1) DEFAULT 1 COMMENT 'چالاک یان لەکارخستراو',
    last_login TIMESTAMP NULL COMMENT 'کاتی کۆتایی لۆگین',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index بۆ خێراکردن
CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_active ON users(is_active);

-- تێبینی: پاسوۆردی ڕاستەقینە لە PHP دا دروست دەکرێت:
-- password_hash('admin123', PASSWORD_DEFAULT)

-- ======================================
-- Database Schema for Medlan E-commerce
-- Medical Supply Online Store
-- Final Version - December 2025
-- ======================================

-- 1. خشتەی پۆلەکان (Categories)
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    image VARCHAR(1000),
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. خشتەی ژێرپۆلەکان (Subcategories)
CREATE TABLE subcategories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    image VARCHAR(1000),
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. خشتەی براندەکان (Brands)
CREATE TABLE brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    image VARCHAR(1000),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. خشتەی کاڵاکان (Products)
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(100) UNIQUE,
    slug VARCHAR(255) NOT NULL UNIQUE,
    brand_id INT,
    subcategory_id INT,
    category_id INT NOT NULL,
    short_description VARCHAR(512),
    long_description TEXT,
    base_price DECIMAL(12,2) NOT NULL COMMENT 'نرخی فرۆشتنی بنەڕەتی - بەکاردێت وەک default بۆ variants',
    purchase_price DECIMAL(12,2) NOT NULL COMMENT 'نرخی کڕینی ڕاستەقینە - یەکسانە بۆ هەموو variants',
    is_active TINYINT(1) DEFAULT 1,
    is_featured TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
    FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. خشتەی ڕەنگەکان (Colors)
CREATE TABLE colors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    hexa_number VARCHAR(7) NOT NULL COMMENT 'کۆدی ڕەنگ بە فۆرماتی #FFFFFF',
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. خشتەی سایزەکان (Sizes)
CREATE TABLE sizes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. خشتەی وردەکاریەکانی کاڵا / Variants (Product Specifications)
CREATE TABLE product_specifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    sku_variant VARCHAR(64) COMMENT 'کۆدی تایبەتی variant',
    spec_key VARCHAR(255) COMMENT 'ناوی تایبەتمەندی',
    spec_value VARCHAR(1000) COMMENT 'نرخی تایبەتمەندی',
    image VARCHAR(1000) COMMENT 'وێنەی سەرهێڵی ئەم variant ـە',
    price DECIMAL(12,2) NOT NULL COMMENT 'نرخی فرۆشتن بۆ ئەم variant - دەتوانرێت جیاواز بێت لە base_price',
    purchase_price DECIMAL(12,2) NULL COMMENT 'نرخی کڕین بۆ ئەم variant - دەستپێکە بە نرخی کڕینی بەرهەمی سەرهەڵ',
    stock INT DEFAULT 0 COMMENT 'ژمارەی ئێستای کۆگا',
    color_id INT,
    size_id INT,
    gender VARCHAR(16),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (color_id) REFERENCES colors(id) ON DELETE SET NULL,
    FOREIGN KEY (size_id) REFERENCES sizes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. خشتەی وێنەکانی کاڵا (Product Images)
CREATE TABLE product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image VARCHAR(1000) NOT NULL,
    alt_text VARCHAR(255),
    sort_order INT DEFAULT 0,
    is_primary TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Variant Images
CREATE TABLE product_spec_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    spec_id INT NOT NULL,
    image VARCHAR(1000) NOT NULL,
    alt_text VARCHAR(255),
    sort_order INT DEFAULT 0,
    is_primary TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (spec_id) REFERENCES product_specifications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================
-- خشتەکانی Promotions System
-- ======================================

-- 9. خشتەی کامپەینەکانی داشکاندن (Promotions)
CREATE TABLE promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'ناوی کامپەین، بۆ نموونە "Winter Sale 2025"',
    description TEXT COMMENT 'وەسفی کامپەینەکە',
    discount_type ENUM('percentage', 'fixed') NOT NULL COMMENT 'جۆری داشکاندن: سەدی یان نرخی ڕاستەوخۆ',
    discount_value DECIMAL(10,2) NOT NULL COMMENT 'نرخی داشکاندن',
    start_date DATE NOT NULL COMMENT 'بەرواری دەستپێکردن',
    end_date DATE NOT NULL COMMENT 'بەرواری کۆتایی',
    is_active TINYINT(1) DEFAULT 1 COMMENT 'چالاک یان ناچالاک',
    priority INT DEFAULT 0 COMMENT 'ئەگەر چەندین promotion لەسەر یەک کاڵا هەبوو، ئەوەی priority زیاتری هەیە کار دەکات',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. خشتەی پەیوەندکردنی Promotions بە Variants (Promotion Items)
CREATE TABLE promotion_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    promotion_id INT NOT NULL,
    product_spec_id INT NOT NULL COMMENT 'تایبەت بە variant دیاریکراو (ڕەنگ/سایز)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    FOREIGN KEY (product_spec_id) REFERENCES product_specifications(id) ON DELETE CASCADE,
    UNIQUE KEY unique_promo_spec (promotion_id, product_spec_id) COMMENT 'بۆ ڕێگری لە دووبارەبوونەوە'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================
-- خشتەکانی Cart و Orders
-- ======================================

-- 11. خشتەی سەبەتە (Carts)
CREATE TABLE carts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(255) NOT NULL UNIQUE COMMENT 'بۆ یوزەری بێ لۆگین',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. خشتەی کاڵاکانی سەبەتە (Cart Items)
CREATE TABLE cart_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cart_id INT NOT NULL,
    product_id INT NOT NULL,
    product_spec_id INT COMMENT 'بۆ دیاریکردنی variant (ڕەنگ/سایز)',
    quantity INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (product_spec_id) REFERENCES product_specifications(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. خشتەی داواکارییەکان (Orders)
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    address TEXT,
    total_price DECIMAL(12,2) NOT NULL,
    order_discount DECIMAL(12,2) NOT NULL DEFAULT 0;
    status ENUM('pending', 'processing', 'shipped', 'completed', 'cancelled', 'returned') DEFAULT 'pending',
    order_source ENUM('website', 'whatsapp', 'instagram', 'manual') DEFAULT 'website' COMMENT 'سەرچاوەی داواکاری',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. خشتەی کاڵاکانی داواکاری (Order Items)
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    product_spec_id INT COMMENT 'بۆ دیاریکردنی variant',
    quantity INT NOT NULL,
    price DECIMAL(12,2) NOT NULL COMMENT 'نرخی فرۆشتن لەو کاتەدا (دوای discount)',
    original_price DECIMAL(12,2) NOT NULL COMMENT 'نرخی سەرەتایی پێش discount',
    cost DECIMAL(12,2) NOT NULL COMMENT 'نرخی کڕین لەو کاتەدا - بۆ حیسابی قازانج و پاراستن لە گۆڕانکاری',
    discount_amount DECIMAL(12,2) DEFAULT 0 COMMENT 'بڕی داشکاندن',
    promotion_id INT NULL COMMENT 'ئاماژە بە promotion ئەگەر بەکارهاتبێت',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_spec_id) REFERENCES product_specifications(id) ON DELETE SET NULL,
    FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. خشتەی جوڵەی کۆگا (Stock Movements)
CREATE TABLE stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_spec_id INT NOT NULL,
    type ENUM('sale', 'return', 'purchase', 'damage', 'adjustment') NOT NULL,
    quantity INT NOT NULL COMMENT 'موجەب (+) بۆ زیادبوون، سالب (-) بۆ کەمبوون',
    order_item_id INT NULL COMMENT 'تەنها بۆ sale و return پڕ دەبێتەوە',
    description TEXT COMMENT 'تێبینی',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_spec_id) REFERENCES product_specifications(id) ON DELETE CASCADE,
    FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. خشتەی خەرجی (Expenses)
CREATE TABLE expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    category VARCHAR(100) DEFAULT 'general',
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. خشتەی فیدباک (Feedback)
CREATE TABLE feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    customer_name VARCHAR(255) NOT NULL,
    rating TINYINT CHECK(rating >= 1 AND rating <= 5),
    comment TEXT,
    is_approved TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================
-- Index بۆ خێراکردنی گەڕانەکان
-- ======================================
CREATE INDEX idx_products_slug ON products(slug);
CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_products_subcategory ON products(subcategory_id);
CREATE INDEX idx_products_brand ON products(brand_id);
CREATE INDEX idx_products_active ON products(is_active);
CREATE INDEX idx_products_featured ON products(is_featured);

CREATE INDEX idx_product_specs_product ON product_specifications(product_id);
CREATE INDEX idx_product_specs_color ON product_specifications(color_id);
CREATE INDEX idx_product_specs_size ON product_specifications(size_id);
CREATE INDEX idx_product_specs_active ON product_specifications(is_active);

CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_source ON orders(order_source);
CREATE INDEX idx_orders_created ON orders(created_at);

CREATE INDEX idx_carts_session ON carts(session_id);

CREATE INDEX idx_stock_movements_type ON stock_movements(type);
CREATE INDEX idx_stock_movements_spec ON stock_movements(product_spec_id);
CREATE INDEX idx_stock_movements_created ON stock_movements(created_at);

CREATE INDEX idx_promotions_dates ON promotions(start_date, end_date);
CREATE INDEX idx_promotions_active ON promotions(is_active);

CREATE INDEX idx_promotion_items_spec ON promotion_items(product_spec_id);
CREATE INDEX idx_promotion_items_promo ON promotion_items(promotion_id);

-- ======================================
-- Views بۆ ئاسانکاری لە Backend
-- ======================================

-- View بۆ بینینی نرخی کۆتایی لەگەڵ discount
CREATE VIEW vw_product_prices AS
SELECT
    ps.id AS spec_id,
    ps.product_id,
    p.name AS product_name,
    p.code AS product_code,
    ps.sku_variant,
    ps.price AS original_price,
    p.purchase_price AS cost,
    ps.stock,
    pr.id AS promotion_id,
    pr.name AS promotion_name,
    pr.discount_type,
    pr.discount_value,
    pr.start_date AS promo_start,
    pr.end_date AS promo_end,
    CASE
        WHEN pr.discount_type = 'percentage' THEN
            ROUND(ps.price - (ps.price * pr.discount_value / 100), 2)
        WHEN pr.discount_type = 'fixed' THEN
            ROUND(ps.price - pr.discount_value, 2)
        ELSE ps.price
    END AS final_price,
    CASE
        WHEN pr.discount_type = 'percentage' THEN
            ROUND((ps.price * pr.discount_value / 100), 2)
        WHEN pr.discount_type = 'fixed' THEN
            pr.discount_value
        ELSE 0
    END AS discount_amount,
    CASE
        WHEN pr.id IS NOT NULL THEN 1
        ELSE 0
    END AS has_discount,
    c.name AS color_name,
    c.hexa_number AS color_code,
    s.name AS size_name,
    cat.name AS category_name,
    subcat.name AS subcategory_name,
    b.name AS brand_name
FROM product_specifications ps
INNER JOIN products p ON p.id = ps.product_id
LEFT JOIN colors c ON c.id = ps.color_id
LEFT JOIN sizes s ON s.id = ps.size_id
LEFT JOIN categories cat ON cat.id = p.category_id
LEFT JOIN subcategories subcat ON subcat.id = p.subcategory_id
LEFT JOIN brands b ON b.id = p.brand_id
LEFT JOIN promotion_items pi ON pi.product_spec_id = ps.id
LEFT JOIN promotions pr ON pr.id = pi.promotion_id
    AND pr.is_active = 1
    AND CURDATE() BETWEEN pr.start_date AND pr.end_date
WHERE ps.is_active = 1 AND p.is_active = 1;

-- View بۆ ڕاپۆرتی فرۆشتن و قازانج
CREATE VIEW vw_sales_report AS
SELECT
    o.id AS order_id,
    o.customer_name,
    o.phone_number,
    o.status AS order_status,
    o.order_source,
    o.created_at AS order_date,
    oi.id AS order_item_id,
    p.name AS product_name,
    p.code AS product_code,
    oi.quantity,
    oi.original_price,
    oi.discount_amount,
    oi.price AS sale_price,
    oi.cost AS purchase_cost,
    (oi.price - oi.cost) AS profit_per_unit,
    ((oi.price - oi.cost) * oi.quantity) AS total_profit,
    (oi.price * oi.quantity) AS total_sale_amount,
    pr.name AS promotion_used
FROM order_items oi
INNER JOIN orders o ON o.id = oi.order_id
INNER JOIN products p ON p.id = oi.product_id
LEFT JOIN promotions pr ON pr.id = oi.promotion_id;
