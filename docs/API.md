# Medlan Backend API

## Auth
- POST /api/auth/login
- GET /api/auth/profile
- POST /api/auth/logout

## Categories
- GET /api/categories
- GET /api/categories?id=
- POST /api/categories
- PATCH /api/categories?id=
- DELETE /api/categories?id=

## Subcategories
- GET /api/subcategories?category_id=
- GET /api/subcategories?id=
- POST /api/subcategories
- PATCH /api/subcategories?id=
- DELETE /api/subcategories?id=

## Brands
- GET /api/brands
- GET /api/brands?id=
- POST /api/brands
- PATCH /api/brands?id=
- DELETE /api/brands?id=

## Products
- GET /api/products
- GET /api/products?id=
- POST /api/products
- PATCH /api/products?id=
- DELETE /api/products?id=

## Variants
- GET /api/products/:id/specs
- POST /api/products/:id/specs
- PATCH /api/specs/:id
- DELETE /api/specs/:id

## Images
- POST /api/products/:id/images
- DELETE /api/images?id=

## Promotions
- GET /api/promotions
- GET /api/promotions?id=
- POST /api/promotions
- PATCH /api/promotions?id=
- DELETE /api/promotions?id=
- POST /api/promotions/:id/items
- DELETE /api/promotions/:id/items?spec_id=

## Colors
- GET /api/colors
- GET /api/colors?id=
- POST /api/colors
- PATCH /api/colors?id=
- DELETE /api/colors?id=

## Sizes
- GET /api/sizes
- GET /api/sizes?id=
- POST /api/sizes
- PATCH /api/sizes?id=
- DELETE /api/sizes?id=

## Cart
- GET /api/cart?session_id=
- POST /api/cart
- POST /api/cart/items
- PATCH /api/cart/items?id=
- DELETE /api/cart/items?id=

## Orders
- POST /api/orders
- GET /api/orders
- GET /api/orders?id=
- PATCH /api/orders/status?id=

## Stock
- GET /api/stock?product_spec_id=
- POST /api/stock/adjust

## Feedback
- POST /api/products/:id/feedback
- GET /api/products/:id/feedback
- PATCH /api/feedback/approve?id=

## Dashboard
- GET /api/dashboard/summary
- GET /api/dashboard/top-products
- GET /api/dashboard/revenue?from=&to=
