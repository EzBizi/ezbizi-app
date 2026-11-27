<?php
/**
 * Export A1 Tools' tools and equipment held by people, for
 * `php artisan hr:import-a1-equipment` (Jose, 2026-11-03: "yes" to moving A1
 * Tools' tools over). Read-only on A1. Run on the box as www-data from A1's
 * api directory:  php /path/a1-export-equipment.php > equipment.json
 *
 * One row per thing held, in A1's shape: who (email, phone, name), what, how
 * many, issued and returned dates, and a ref so the import is idempotent.
 */
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require '/var/www/app.a-1chimney.com/public_html/api/config.php';
ob_end_clean();
$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$rows = [];
foreach ($pdo->query('SELECT a.id, a.qty, a.notes, a.assigned_at, a.returned_at, t.name AS item,
        u.email, u.phone, a.technician_name AS name
    FROM crm_tool_assignments a JOIN crm_tools t ON t.id = a.tool_id
    LEFT JOIN a1tools_users u ON u.username = a.technician_username') as $r) {
    $rows[] = ['ref' => 'a1-tool-assignment:'.$r['id'], 'email' => $r['email'], 'phone' => $r['phone'],
        'name' => $r['name'], 'item' => $r['item'], 'category' => 'tool', 'quantity' => (int) $r['qty'],
        'issued_on' => $r['assigned_at'] ? substr($r['assigned_at'], 0, 10) : null,
        'returned_on' => $r['returned_at'] ? substr($r['returned_at'], 0, 10) : null, 'note' => $r['notes']];
}
foreach ($pdo->query('SELECT q.id, q.kind, q.item, q.qty, q.received_date, e.email, e.phone,
        TRIM(CONCAT(e.first_name, " ", e.last_name)) AS name
    FROM hr_employee_equipment q LEFT JOIN hr_employees e ON e.id = q.employee_id') as $r) {
    $rows[] = ['ref' => 'a1-hr-equipment:'.$r['id'], 'email' => $r['email'], 'phone' => $r['phone'],
        'name' => $r['name'], 'item' => $r['item'], 'category' => $r['kind'] === 'equipment' ? 'equipment' : 'tool',
        'quantity' => max(1, (int) $r['qty']), 'issued_on' => $r['received_date'] ?: null,
        'returned_on' => null, 'note' => null];
}
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
