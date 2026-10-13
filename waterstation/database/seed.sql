-- ============================================================
-- DEMO DATA ONLY - sample records for trying out the system.
-- Import AFTER schema.sql and AFTER logging in once (so the admin
-- user exists). Do NOT import on a production database.
-- ============================================================
SET NAMES utf8mb4;

INSERT INTO inventory_items (id,name,category,unit,quantity,min_level) VALUES
(1,'Full gallons (20L)','Full gallons','pcs',40,10),
(2,'Empty gallons (20L)','Empty gallons','pcs',25,10),
(3,'Bottles (500ml)','Bottles','pcs',200,100),
(4,'Caps and seals','Caps and seals','pcs',150,100),
(5,'Labels','Labels','pcs',300,100),
(6,'Packaging (plastic bags)','Packaging','pcs',8,20),
(7,'Sediment filter','Filters','pcs',5,2),
(8,'Cleaning supplies','Maintenance supplies','set',4,2);

INSERT INTO products (id,product_code,name,description,unit_price,track_stock,inventory_item_id,status) VALUES
(1,'PRD-0001','Purified refill','Purified water, 20L refill',30.00,0,NULL,'active'),
(2,'PRD-0002','Mineral refill','Mineral water, 20L refill',35.00,0,NULL,'active'),
(3,'PRD-0003','Alkaline refill','Alkaline water, 20L refill',40.00,0,NULL,'active'),
(4,'PRD-0004','New container (sale)','New 20L container with cap (deducts Full gallons)',250.00,1,1,'active'),
(5,'PRD-0005','Delivery fee','Delivery charge',20.00,0,NULL,'active');

INSERT INTO customers (id,customer_code,name,contact_number,address,type,registered_at) VALUES
(1,'CUST-0001','Maria Santos (DEMO)','0917-123-4567','12 Rizal St., Sample Village','Residential',CURDATE()),
(2,'CUST-0002','Sample Office Corp (DEMO)','0918-222-3333','2F Demo Bldg., Main Ave.','Office',CURDATE()),
(3,'CUST-0003','Juan Dela Cruz (DEMO)','0920-555-6666','45 Mabini St., Sample Village','Residential',CURDATE());

INSERT INTO riders (id,name,contact_number) VALUES
(1,'Pedro Reyes (DEMO)','0915-000-1111'),(2,'Ramon Cruz (DEMO)','0915-000-2222');

INSERT INTO orders (id,order_number,receipt_number,customer_id,order_date,subtotal,discount,total,payment_method,status,stock_deducted,processed_by,notes) VALUES
(1,CONCAT('ORD-',DATE_FORMAT(NOW(),'%Y%m%d'),'-0001'),CONCAT('RCT-',DATE_FORMAT(NOW(),'%Y%m%d'),'-0001'),NULL,NOW(),90.00,0,90.00,'Cash','Completed',1,(SELECT id FROM users ORDER BY id LIMIT 1),'DEMO walk-in'),
(2,CONCAT('ORD-',DATE_FORMAT(NOW(),'%Y%m%d'),'-0002'),CONCAT('RCT-',DATE_FORMAT(NOW(),'%Y%m%d'),'-0002'),1,NOW(),310.00,0,310.00,'GCash','Pending',0,(SELECT id FROM users ORDER BY id LIMIT 1),'DEMO: new container + refill + delivery'),
(3,CONCAT('ORD-',DATE_FORMAT(NOW(),'%Y%m%d'),'-0003'),CONCAT('RCT-',DATE_FORMAT(NOW(),'%Y%m%d'),'-0003'),2,NOW(),400.00,20.00,380.00,'Bank Transfer','Completed',1,(SELECT id FROM users ORDER BY id LIMIT 1),'DEMO office order');

INSERT INTO order_items (order_id,product_id,product_name,unit_price,quantity,line_total) VALUES
(1,1,'Purified refill',30.00,3,90.00),
(2,4,'New container (sale)',250.00,1,250.00),
(2,3,'Alkaline refill',40.00,1,40.00),
(2,5,'Delivery fee',20.00,1,20.00),
(3,2,'Mineral refill',35.00,10,350.00),
(3,5,'Delivery fee',20.00,1,20.00),
(3,1,'Purified refill',30.00,1,30.00);

INSERT INTO expenses (category,amount,expense_date,description) VALUES
('Electricity',1850.00,CURDATE(),'DEMO electric bill'),
('Supplies',420.00,CURDATE(),'DEMO caps and seals'),
('Fuel',300.00,CURDATE(),'DEMO delivery fuel');
