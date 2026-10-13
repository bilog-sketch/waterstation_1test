<?php
use PHPUnit\Framework\TestCase;

/**
 * Integration tests (need a MySQL test database with schema.sql imported).
 * Set env vars: TEST_DB_DSN (e.g. mysql:host=127.0.0.1;dbname=water_station_test;charset=utf8mb4), TEST_DB_USER, TEST_DB_PASS.
 * Every test runs inside a transaction that is rolled back, so the database stays clean.
 */
class StockDeductionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if (!$dsn) $this->markTestSkipped('TEST_DB_DSN not set; skipping DB integration tests.');
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    /** Creates inventory item with $stock, tracked product, and an order of $qty. Returns [orderId, itemId]. */
    private function makeOrder(int $stock, int $qty, string $status = 'Pending'): array
    {
        $u = uniqid();
        $this->pdo->prepare("INSERT INTO inventory_items (name, category, unit, quantity, min_level) VALUES (?, 'Full gallons', 'pcs', ?, 0)")->execute(["T-$u", $stock]);
        $item = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO products (product_code, name, unit_price, track_stock, inventory_item_id) VALUES (?, ?, 250, 1, ?)')->execute(["T$u", "Test $u", $item]);
        $prod = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO orders (order_number, receipt_number, order_date, subtotal, total, status) VALUES (?, ?, NOW(), ?, ?, ?)")
            ->execute(["TO-$u", "TR-$u", 250 * $qty, 250 * $qty, $status]);
        $order = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, line_total) VALUES (?, ?, ?, 250, ?, ?)')
            ->execute([$order, $prod, "Test $u", $qty, 250 * $qty]);
        return [$order, $item];
    }

    private function stock(int $item): float
    {
        return (float)$this->pdo->query("SELECT quantity FROM inventory_items WHERE id = $item")->fetchColumn();
    }

    public function testCompletingOrderDeductsStockAndLogsMovement(): void
    {
        [$order, $item] = $this->makeOrder(10, 3);
        change_order_status($this->pdo, $order, 'Completed', null);
        $this->assertEquals(7, $this->stock($item));
        $this->assertSame('Completed', $this->pdo->query("SELECT status FROM orders WHERE id = $order")->fetchColumn());
        $this->assertEquals(1, $this->pdo->query("SELECT COUNT(*) FROM inventory_transactions WHERE order_id = $order AND type = 'order' AND balance_after = 7")->fetchColumn());
    }

    public function testStockIsNeverDeductedTwice(): void
    {
        [$order, $item] = $this->makeOrder(10, 3);
        deduct_stock_for_order($this->pdo, $order, null);
        deduct_stock_for_order($this->pdo, $order, null);
        $this->assertEquals(7, $this->stock($item));
    }

    public function testInsufficientStockBlocksCompletion(): void
    {
        [$order, $item] = $this->makeOrder(2, 5);
        $this->expectException(UserError::class);
        try {
            change_order_status($this->pdo, $order, 'Completed', null);
        } finally {
            $this->assertEquals(2, $this->stock($item));   // never negative, unchanged
        }
    }

    public function testCancelledOrderCannotBeCompleted(): void
    {
        [$order, $item] = $this->makeOrder(10, 1, 'Cancelled');
        $this->expectException(UserError::class);
        try {
            change_order_status($this->pdo, $order, 'Completed', null);
        } finally {
            $this->assertEquals(10, $this->stock($item));
        }
    }
}
