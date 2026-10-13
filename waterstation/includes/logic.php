<?php
declare(strict_types=1);
/* Business rules. Pure functions at the top (unit-tested), DB-backed functions below. */

const ORDER_STATUSES = ['Pending', 'Processing', 'Ready for Pickup', 'Out for Delivery', 'Completed', 'Cancelled'];
const PAYMENT_METHODS = ['Cash', 'GCash', 'Bank Transfer'];
const DELIVERY_STATUSES = ['Scheduled', 'Preparing', 'Out for Delivery', 'Delivered', 'Failed'];
const CUSTOMER_TYPES = ['Walk-in', 'Residential', 'Office', 'Business'];
const EXPENSE_CATEGORIES = ['Electricity', 'Water bill', 'Rent', 'Supplies', 'Salaries', 'Maintenance', 'Fuel', 'Other'];
const INVENTORY_CATEGORIES = ['Full gallons', 'Empty gallons', 'Bottles', 'Caps and seals', 'Labels', 'Packaging', 'Filters', 'Maintenance supplies'];

if (!class_exists('UserError')) {
    class UserError extends RuntimeException {}
}

function to_cents($v): int
{
    return (int)round(((float)$v) * 100);
}

/** items: [['unit_price'=>..., 'quantity'=>int], ...]. Discount is clamped to 0..subtotal. */
function calc_totals(array $items, $discount): array
{
    $sub = 0;
    foreach ($items as $it) $sub += to_cents($it['unit_price']) * (int)$it['quantity'];
    $disc = max(0, min(to_cents($discount), $sub));
    return ['subtotal' => $sub / 100, 'discount' => $disc / 100, 'total' => ($sub - $disc) / 100];
}

function order_transitions(): array
{
    return [
        'Pending' => ['Processing', 'Ready for Pickup', 'Out for Delivery', 'Completed', 'Cancelled'],
        'Processing' => ['Ready for Pickup', 'Out for Delivery', 'Completed', 'Cancelled'],
        'Ready for Pickup' => ['Processing', 'Out for Delivery', 'Completed', 'Cancelled'],
        'Out for Delivery' => ['Processing', 'Completed', 'Cancelled'],
        'Completed' => [],
        'Cancelled' => [],
    ];
}
function can_transition(string $from, string $to): bool
{
    return in_array($to, order_transitions()[$from] ?? [], true);
}
function order_is_open(string $status): bool
{
    return !in_array($status, ['Completed', 'Cancelled'], true);
}

function delivery_transitions(): array
{
    return [
        'Scheduled' => ['Preparing', 'Out for Delivery', 'Delivered', 'Failed'],
        'Preparing' => ['Scheduled', 'Out for Delivery', 'Delivered', 'Failed'],
        'Out for Delivery' => ['Delivered', 'Failed'],
        'Failed' => ['Scheduled'],
        'Delivered' => [],
    ];
}
/** Order status that a delivery status implies (null = leave the order alone). */
function delivery_to_order_status(string $d): ?string
{
    return ['Preparing' => 'Processing', 'Out for Delivery' => 'Out for Delivery', 'Delivered' => 'Completed', 'Failed' => 'Processing'][$d] ?? null;
}

/* ---------- DB-backed ---------- */

/** Next code like PREFIX0001. Call inside a transaction (locks matching rows). Table/col are internal constants. */
function next_seq(PDO $pdo, string $table, string $col, string $prefix, int $width): string
{
    $st = $pdo->prepare("SELECT MAX($col) FROM $table WHERE $col LIKE ? FOR UPDATE");
    $st->execute([$prefix . '%']);
    $max = $st->fetchColumn();
    $n = $max ? ((int)substr((string)$max, strlen($prefix))) + 1 : 1;
    return $prefix . str_pad((string)$n, $width, '0', STR_PAD_LEFT);
}

/**
 * Deduct tracked stock for an order. MUST run inside a transaction.
 * Idempotent (orders.stock_deducted). Throws UserError (=> caller rolls back) if stock is insufficient.
 */
function deduct_stock_for_order(PDO $pdo, int $orderId, ?int $userId): void
{
    $o = $pdo->prepare('SELECT order_number, stock_deducted FROM orders WHERE id = ? FOR UPDATE');
    $o->execute([$orderId]);
    $order = $o->fetch();
    if (!$order) throw new UserError('Order not found.');
    if ((int)$order['stock_deducted'] === 1) return;

    $st = $pdo->prepare('SELECT p.inventory_item_id AS item_id, SUM(oi.quantity) AS qty
        FROM order_items oi JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ? AND p.track_stock = 1 AND p.inventory_item_id IS NOT NULL
        GROUP BY p.inventory_item_id ORDER BY p.inventory_item_id');
    $st->execute([$orderId]);
    foreach ($st->fetchAll() as $need) {
        $lock = $pdo->prepare('SELECT name, quantity FROM inventory_items WHERE id = ? FOR UPDATE');
        $lock->execute([$need['item_id']]);
        $item = $lock->fetch();
        if (!$item) continue;
        $qty = (float)$need['qty'];
        if ((float)$item['quantity'] < $qty) {
            throw new UserError(sprintf('Not enough stock of "%s": %s available, %s needed. Add stock first, then complete the order.',
                $item['name'], rtrim(rtrim((string)$item['quantity'], '0'), '.'), rtrim(rtrim((string)$qty, '0'), '.')));
        }
        $newBal = round((float)$item['quantity'] - $qty, 2);
        $pdo->prepare('UPDATE inventory_items SET quantity = ? WHERE id = ?')->execute([$newBal, $need['item_id']]);
        $pdo->prepare('INSERT INTO inventory_transactions (item_id, type, quantity, balance_after, txn_date, remarks, order_id, user_id)
            VALUES (?, \'order\', ?, ?, CURDATE(), ?, ?, ?)')
            ->execute([$need['item_id'], $qty, $newBal, 'Order ' . $order['order_number'], $orderId, $userId]);
    }
    $pdo->prepare('UPDATE orders SET stock_deducted = 1 WHERE id = ?')->execute([$orderId]);
}

/** Change order status with rule check; Completed deducts stock atomically. Joins an outer transaction if present. */
function change_order_status(PDO $pdo, int $orderId, string $new, ?int $userId): void
{
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT order_number, status FROM orders WHERE id = ? FOR UPDATE');
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) throw new UserError('Order not found.');
        if ($o['status'] !== $new) {
            if (!can_transition($o['status'], $new)) {
                throw new UserError("An order that is {$o['status']} cannot be changed to $new.");
            }
            if ($new === 'Completed') deduct_stock_for_order($pdo, $orderId, $userId);
            $pdo->prepare('UPDATE orders SET status = ?, cancelled_at = ? WHERE id = ?')
                ->execute([$new, $new === 'Cancelled' ? date('Y-m-d H:i:s') : null, $orderId]);
            audit($new === 'Cancelled' ? 'order_cancelled' : 'order_status', 'order', $orderId, "{$o['order_number']}: {$o['status']} -> $new");
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Change delivery status and sync the order status in ONE transaction. */
function change_delivery_status(PDO $pdo, int $deliveryId, string $new, ?int $userId, array $confirm = []): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM deliveries WHERE id = ? FOR UPDATE');
        $st->execute([$deliveryId]);
        $d = $st->fetch();
        if (!$d) throw new UserError('Delivery not found.');
        if ($d['status'] !== $new && !in_array($new, delivery_transitions()[$d['status']] ?? [], true)) {
            throw new UserError("A delivery that is {$d['status']} cannot be changed to $new.");
        }
        $orderTarget = delivery_to_order_status($new);
        if ($orderTarget) {
            $os = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
            $os->execute([$d['order_id']]);
            $cur = $os->fetchColumn();
            if ($cur === 'Cancelled') throw new UserError('This order was cancelled, so its delivery cannot continue.');
            if ($cur !== $orderTarget && can_transition((string)$cur, $orderTarget)) {
                change_order_status($pdo, (int)$d['order_id'], $orderTarget, $userId);
            }
        }
        $pdo->prepare('UPDATE deliveries SET status = ?, delivered_at = ?, received_by = COALESCE(?, received_by),
            confirmation_notes = COALESCE(?, confirmation_notes), photo_path = COALESCE(?, photo_path) WHERE id = ?')
            ->execute([$new, $new === 'Delivered' ? date('Y-m-d H:i:s') : null,
                $confirm['received_by'] ?? null, $confirm['notes'] ?? null, $confirm['photo'] ?? null, $deliveryId]);
        audit('delivery_status', 'delivery', $deliveryId, "{$d['status']} -> $new");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
