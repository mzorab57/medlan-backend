# Medlan Backend

Medlan بەکێشەی بازرگانی ئێ-کۆمەرسی بۆ کەلوپەلی پزیشکییە. ئەم پەڕگەیە ڕێنمایی تەواوە بۆ چۆنیەتی دامەزراندن، شێوەی فۆڵدەرەکان، پەیوەندی بە بنکەدراوە، و ناوڕاستی API و ڕوتەکان.

## پێویستییەکان
- PHP 8.x (XAMPP)
- MySQL 8.x
- phpMyAdmin یان `mysql` CLI بۆ ئەنجامدانی `sql/database.sql`

## دامەزراندن
- دروستکردنی بنکەدراوە: ناوی بانگەشە `medlan` (دەتوانی ناوەکە بگۆڕی)
- خشتەکردن: `sql/database.sql` دەخواتە ناو بانگەشەکە لە ڕێگای phpMyAdmin یان:
  ```bash
  mysql -u <USER> -p medlan < sql/database.sql
  ```
- یوزەری بنەڕەتی: `admin / admin123` لە خشتەی `users` هەیە، بە هاشی PHP دروستکراوە

## شێوەی فۆڵدەرەکان
```
medlan-backend/
├── .env
├── .env.example
├── .htaccess
├── .gitignore
│
├── config/
│   ├── database.php
│   ├── app.php
│   └── cors.php
│
├── controllers/
│   ├── AuthController.php
│   ├── CategoryController.php
│   ├── SubcategoryController.php
│   ├── BrandController.php
│   ├── ProductController.php
│   ├── PromotionController.php
│   ├── CartController.php
│   ├── OrderController.php
│   ├── StockController.php
│   ├── FeedbackController.php
│   ├── ColorController.php
│   ├── SizeController.php
│   └── DashboardController.php
│
├── models/
│   ├── Category.php
│   ├── Subcategory.php
│   ├── Brand.php
│   ├── Product.php
│   ├── ProductSpecification.php
│   ├── ProductImage.php
│   ├── Promotion.php
│   ├── PromotionItem.php
│   ├── Cart.php
│   ├── CartItem.php
│   ├── Order.php
│   ├── OrderItem.php
│   ├── StockMovement.php
│   ├── Feedback.php
│   ├── Color.php
│   ├── Size.php
│   └── User.php
│
├── routes/
│   ├── api.php
│   └── web.php
│
├── middleware/
│   ├── AuthMiddleware.php
│   ├── AdminMiddleware.php
│   ├── CorsMiddleware.php
│   └── ValidationMiddleware.php
│
├── utils/
│   ├── JWT.php
│   ├── Response.php
│   ├── Validator.php
│   ├── FileUpload.php
│   └── Session.php
│
├── helpers/
│   ├── helpers.php
│   └── constants.php
│
├── uploads/
│   ├── categories/
│   ├── products/
│   ├── brands/
│   └── temp/
│
├── logs/
│   ├── error.log
│   └── access.log
│
├── public/
│   └── index.php
│
├── database/
│   ├── schema.sql
│   └── seeds.sql
│
├── docs/
│   ├── API.md
│   └── postman-collection.json
│
├── tests/
│   └── .gitkeep
│
├── README.md
└── bootstrap.php
```

## بنکەدراوە
- خشتەکان و ڕوانگەکان لە `database/schema.sql` هەیە
- ڕوانگەکان: `vw_product_prices` بۆ نرخی کۆتایی، `vw_sales_report` بۆ ڕاپۆرتی فرۆشتن
- سێد: `database/seeds.sql` بۆ داتای بنەڕەتی (admin, colors, sizes)
- Hash/Verify پاسوۆرد: `password_hash` و `password_verify` لە PHP

## API و ڕوتەکان (REST)

### Auth
- `POST /api/auth/login` — دەخاتە ژوورەوە بە `username/password`
- `GET /api/auth/profile` — زانیاری یوزەری هاتووە ژوورەوە
- `POST /api/auth/logout` — داخستن

### Categories
- `GET /api/categories`
- `GET /api/categories/:id`
- `POST /api/categories`
- `PATCH /api/categories/:id`
- `DELETE /api/categories/:id`

### Subcategories
- `GET /api/subcategories?category_id=`
- `GET /api/subcategories/:id`
- `POST /api/subcategories`
- `PATCH /api/subcategories/:id`
- `DELETE /api/subcategories/:id`

### Brands
- `GET /api/brands`
- `GET /api/brands/:id`
- `POST /api/brands`
- `PATCH /api/brands/:id`
- `DELETE /api/brands/:id`

### Products
- `GET /api/products` — فلتەرەکان: `category_id`, `subcategory_id`, `brand_id`, `search`, `featured`
- `GET /api/products/:id` — بە وردەکاری، variants و images
- `POST /api/products`
- `PATCH /api/products/:id`
- `DELETE /api/products/:id`

### Variants (Product Specifications)
- `GET /api/products/:id/specs`
- `POST /api/products/:id/specs`
- `PATCH /api/specs/:id`
- `DELETE /api/specs/:id`

### Product Images
- `POST /api/products/:id/images` — `multipart/form-data`
- `DELETE /api/images/:id`

### Promotions
- `GET /api/promotions` — دەتوانرێت `active` فلتەر بێت
- `POST /api/promotions`
- `PATCH /api/promotions/:id`
- `DELETE /api/promotions/:id`
- `POST /api/promotions/:id/items` — لینککردنی variant بە promotion
- `DELETE /api/promotions/:id/items/:spec_id`

### Cart
- `GET /api/cart/:session_id`
- `POST /api/cart` — دروستکردنی cart بۆ سێشنی نوێ
- `POST /api/cart/items` — زیادکردنی کاڵا بە cart
- `PATCH /api/cart/items/:id` — گۆڕینی `quantity`
- `DELETE /api/cart/items/:id`

### Orders
- `POST /api/orders`
- `GET /api/orders` — فلتەر بە `status`, `source`, `date`
- `GET /api/orders/:id`
- `PATCH /api/orders/:id/status`

### Stock Movements
- `GET /api/stock?product_spec_id=`
- `POST /api/stock/adjust` — `type=adjustment` بۆ ڕاستکردنەوەی کۆگا
- تێبینی: `sale` و `return` بەشێوەی ئۆتۆماتیکی لە کاتی order هەڵدەکرێن

### Feedback
- `POST /api/products/:id/feedback`
- `GET /api/products/:id/feedback`
- `PATCH /api/feedback/:id/approve`

### Views APIs
- `GET /api/products/prices` — لە `vw_product_prices`
- `GET /api/reports/sales` — لە `vw_sales_report` بە فلتەری بەروار

## ڕێکخستنی DB و ئەمنییەت
- `FOREIGN KEY`-ەکان بە درووستی دانراون
- `ON DELETE` بە حەزمەندی: `CASCADE`, `SET NULL`, `RESTRICT`
- Index-ەکان بۆ خێرابوونی گەڕان دانراون
- پاسوۆرد هیچ کاتێک بە سادەیی نانێررێت؛ تەنها hash و verify

## هەڵسەنگاندن
- لە phpMyAdmin ڕوانگەکان (`VW`) و خشتەکان پشتڕاست بکەوە
- نموونەی Queries لە کۆتایی `database/schema.sql` هەیە

## کارەکانی داهاتوو
- دامەزراندنی `routes/api.php` و Controllers لە `src/`
- دانانی Middleware بۆ Auth و Rate-limit
- زیادکردنی `uploads/` بە پاراستنی وێنە

---

ڕەنگدان: هەموو شێوە و ڕوتەکان ئەوچۆنەیە کە لەسەر خشتەی MySQL-ەکە خراون. دەتوانرێت بە گۆڕانکارییەکی بچوک بخوێندرێتەوە پێش ئەوەی پیاده‌سازی بکەین.
