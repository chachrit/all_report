# SQL Queries for Dashboard

## Overview Dashboard (dashboard.php)

### Total Revenue (Online + Offline)
```sql
-- Online Sales
SELECT SUM(net_sales) as total_sales 
FROM jst_sale_detail 
WHERE sale_date >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)

-- Offline Sales  
SELECT SUM(net_amount) as total_sales 
FROM pos_sale_detail 
WHERE CONVERT(date, order_date) >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)
```

### Total Orders
```sql
-- Online Orders
SELECT COUNT(*) as total_orders 
FROM jst_sale_detail 
WHERE sale_date >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)

-- Offline Orders
SELECT COUNT(DISTINCT order_id) as total_orders 
FROM pos_sale_detail 
WHERE CONVERT(date, order_date) >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)
```

### Platform Performance (Online)
```sql
SELECT 
    platform,
    SUM(net_sales) as total_sales,
    COUNT(*) as total_orders,
    AVG(net_sales) as avg_order_value
FROM jst_sale_detail 
WHERE sale_date >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)
GROUP BY platform
ORDER BY total_sales DESC
```

### Branch Performance (Offline)
```sql
SELECT 
    branch,
    SUM(net_amount) as total_sales,
    COUNT(DISTINCT order_id) as total_orders,
    AVG(net_amount) as avg_order_value
FROM pos_sale_detail 
WHERE CONVERT(date, order_date) >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)
GROUP BY branch
ORDER BY total_sales DESC
```

### Top Products (Online)
```sql
-- Note: Need to join with product table if available
SELECT 
    'Mock Product' as product_name,
    SUM(net_sales) as total_sales,
    COUNT(*) as total_orders
FROM jst_sale_detail 
WHERE sale_date >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)
GROUP BY product_name
ORDER BY total_sales DESC
TOP 5
```

### Monthly Trend
```sql
-- Online
SELECT 
    FORMAT(sale_date, 'MMM') as month,
    SUM(net_sales) as total_sales,
    COUNT(*) as total_orders
FROM jst_sale_detail 
WHERE sale_date >= DATEADD(month, -5, DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0))
GROUP BY FORMAT(sale_date, 'MMM'), YEAR(sale_date), MONTH(sale_date)
ORDER BY YEAR(sale_date), MONTH(sale_date)

-- Offline
SELECT 
    FORMAT(CONVERT(date, order_date), 'MMM') as month,
    SUM(net_amount) as total_sales,
    COUNT(DISTINCT order_id) as total_orders
FROM pos_sale_detail 
WHERE CONVERT(date, order_date) >= DATEADD(month, -5, DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0))
GROUP BY FORMAT(CONVERT(date, order_date), 'MMM'), YEAR(CONVERT(date, order_date)), MONTH(CONVERT(date, order_date))
ORDER BY YEAR(CONVERT(date, order_date)), MONTH(CONVERT(date, order_date))
```

## Online Sales Dashboard (dashboard_online.php)

### Platform Share
```sql
SELECT 
    platform,
    SUM(net_sales) as total_sales,
    COUNT(*) as total_orders,
    AVG(net_sales) as avg_order_value,
    (SUM(net_sales) * 100.0 / (SELECT SUM(net_sales) FROM jst_sale_detail WHERE sale_date >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0))) as market_share
FROM jst_sale_detail 
WHERE sale_date >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)
GROUP BY platform
ORDER BY total_sales DESC
```

### Payment Channel Distribution
```sql
SELECT 
    payment_channel,
    SUM(net_sales) as total_sales,
    COUNT(*) as total_orders,
    (COUNT(*) * 100.0 / (SELECT COUNT(*) FROM jst_sale_detail WHERE sale_date >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0))) as percentage
FROM jst_sale_detail 
WHERE sale_date >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)
GROUP BY payment_channel
ORDER BY total_sales DESC
```

### Growth Comparison (Current vs Previous Month)
```sql
-- Current Month
SELECT SUM(net_sales) as current_month_sales
FROM jst_sale_detail 
WHERE sale_date >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)

-- Previous Month
SELECT SUM(net_sales) as previous_month_sales
FROM jst_sale_detail 
WHERE sale_date >= DATEADD(month, -1, DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)
AND sale_date < DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)
```

## Offline Sales Dashboard (dashboard_offline.php)

### Zone Distribution
```sql
SELECT 
    zone,
    SUM(net_amount) as total_sales,
    COUNT(DISTINCT order_id) as total_orders,
    COUNT(DISTINCT branch) as total_branches,
    (SUM(net_amount) * 100.0 / (SELECT SUM(net_amount) FROM pos_sale_detail WHERE CONVERT(date, order_date) >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0))) as percentage
FROM pos_sale_detail 
WHERE CONVERT(date, order_date) >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)
GROUP BY zone
ORDER BY total_sales DESC
```

### Customer Type Distribution
```sql
SELECT 
    customer_type,
    SUM(net_amount) as total_sales,
    COUNT(DISTINCT order_id) as total_orders,
    (COUNT(DISTINCT order_id) * 100.0 / (SELECT COUNT(DISTINCT order_id) FROM pos_sale_detail WHERE CONVERT(date, order_date) >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0))) as percentage
FROM pos_sale_detail 
WHERE CONVERT(date, order_date) >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)
GROUP BY customer_type
ORDER BY total_sales DESC
```

### Branch Performance by Zone
```sql
SELECT 
    branch,
    zone,
    SUM(net_amount) as total_sales,
    COUNT(DISTINCT order_id) as total_orders,
    AVG(net_amount) as avg_order_value
FROM pos_sale_detail 
WHERE CONVERT(date, order_date) >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)
GROUP BY branch, zone
ORDER BY zone, total_sales DESC
```

## Database Schema Requirements

### jst_sale_detail (Online Sales)
```sql
CREATE TABLE jst_sale_detail (
    id INT IDENTITY(1,1) PRIMARY KEY,
    orderId VARCHAR(50) UNIQUE,
    orderNumber VARCHAR(100),
    platformOrderId VARCHAR(100),
    sale_date DATE,
    order_datetime DATETIME,
    status VARCHAR(50),
    statusDisplay VARCHAR(100),
    platform VARCHAR(50),
    subtotal DECIMAL(10,2),
    net_sales DECIMAL(10,2),
    shop_discount DECIMAL(10,2),
    total_discount DECIMAL(10,2),
    freight DECIMAL(10,2),
    payment_channel VARCHAR(100),
    buyer_name VARCHAR(255),
    import_batch VARCHAR(50),
    created_at DATETIME DEFAULT GETDATE()
);
```

### pos_sale_detail (Offline Sales)
```sql
CREATE TABLE pos_sale_detail (
    id INT IDENTITY(1,1) PRIMARY KEY,
    order_id VARCHAR(50) UNIQUE,
    order_date DATETIME,
    customer_name VARCHAR(255),
    net_amount DECIMAL(10,2),
    payment_method VARCHAR(100),
    branch VARCHAR(100),
    zone VARCHAR(50),
    product_code VARCHAR(50),
    product_name VARCHAR(255),
    quantity INT,
    customer_type VARCHAR(50),
    import_batch VARCHAR(50),
    created_at DATETIME DEFAULT GETDATE()
);
```

## Notes

1. **Date Filtering**: All queries use current month by default. Change `DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)` to adjust date range.

2. **Performance**: Add indexes on frequently queried columns:
   - `jst_sale_detail(sale_date, platform, payment_channel)`
   - `pos_sale_detail(order_date, branch, zone)`

3. **Data Quality**: Ensure CSV data is properly imported before running dashboard queries.

4. **Real-time Updates**: Consider creating stored procedures or views for complex queries to improve performance.

5. **Currency**: All amounts are in Thai Baht (THB).

## Implementation Steps

1. Import CSV data using the import scripts
2. Verify data integrity in database
3. Replace mock data arrays in dashboard PHP files with actual SQL queries
4. Test dashboard with real data
5. Add error handling for missing data
6. Implement caching for better performance
