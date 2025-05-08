<?php
// index.php — Single-file ledger app with Flatpickr date/datetime pickers

// Ensure SQLite file exists
$dbFile = __DIR__ . '/database.sqlite';
if (!file_exists($dbFile)) {
    touch($dbFile);
}

// Connect to SQLite
$pdo = new PDO('sqlite:' . $dbFile);
// Create table if needed
$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_type TEXT NOT NULL,
    amount REAL NOT NULL,
    description TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    datetime TEXT NOT NULL
);
SQL
);

// Helpers
function addTransaction(PDO $pdo): int {
    $stmt = $pdo->prepare(
        'INSERT INTO transactions (transaction_type, amount, description, user_id, datetime)
         VALUES (:type, :amount, :desc, :uid, :dt)'
    );
    $stmt->execute([
        ':type'   => $_POST['transaction_type'],
        ':amount' => $_POST['amount'],
        ':desc'   => $_POST['description'],
        ':uid'    => $_POST['user_id'],
        ':dt'     => $_POST['datetime'],
    ]);
    return (int)$pdo->lastInsertId();
}

function getBalance(PDO $pdo, int $userId, string $date): float {
    $stmt = $pdo->prepare(
        'SELECT transaction_type, SUM(amount) AS total
         FROM transactions
         WHERE user_id = :uid
           AND datetime <= :dt
         GROUP BY transaction_type'
    );
    $stmt->execute([':uid' => $userId, ':dt' => $date]);
    $credit = 0; $debit = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['transaction_type'] === 'credit') {
            $credit = (float)$row['total'];
        } else {
            $debit = (float)$row['total'];
        }
    }
    return $credit - $debit;
}

function getTransactions(PDO $pdo, int $userId, string $start, string $end): array {
    $stmt = $pdo->prepare(
        'SELECT * FROM transactions
         WHERE user_id = :uid
           AND datetime BETWEEN :start AND :end
         ORDER BY datetime ASC'
    );
    $stmt->execute([':uid'=>$userId, ':start'=>$start, ':end'=>$end]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Process forms
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $id = addTransaction($pdo);
    $message = "Transaction added with ID $id.";
}

// GET queries
$balanceResult = null;
if ($_GET['action'] ?? '' === 'balance') {
    $balanceResult = getBalance(
        $pdo,
        (int)$_GET['user_id'],
        $_GET['date'] . 'T23:59:59'
    );
}

$txList = null;
if ($_GET['action'] ?? '' === 'list') {
    $txList = getTransactions(
        $pdo,
        (int)$_GET['user_id'],
        $_GET['start'] . 'T00:00:00',
        $_GET['end']   . 'T23:59:59'
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ledger App</title>
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        body { font-family: sans-serif; margin: 2rem; }
        fieldset { margin-bottom: 1.5rem; padding: 1rem; }
        label, input, select, button { display: block; width: 100%; margin: .5rem 0; }
        pre { background: #f4f4f4; padding: .5rem; }
    </style>
</head>
<body>
    <h1>Ledger App</h1>
    <?php if ($message): ?>
        <p><strong><?= htmlspecialchars($message) ?></strong></p>
    <?php endif; ?>

    <fieldset>
        <legend>Add Transaction</legend>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <label>Type:
                <select name="transaction_type">
                    <option value="credit">Credit</option>
                    <option value="debit">Debit</option>
                </select>
            </label>
            <label>Amount:
                <input type="number" name="amount" step="0.01" required>
            </label>
            <label>Description:
                <input type="text" name="description" required>
            </label>
            <label>User ID:
                <input type="number" name="user_id" value="1" required>
            </label>
            <label>Date & Time:
                <input type="text" id="dtpicker" name="datetime" required>
            </label>
            <button type="submit">Submit</button>
        </form>
    </fieldset>

    <fieldset>
        <legend>Get Balance</legend>
        <form method="GET">
            <input type="hidden" name="action" value="balance">
            <label>User ID:
                <input type="number" name="user_id" value="1" required>
            </label>
            <label>Date:
                <input type="text" id="datepicker_balance" name="date" required>
            </label>
            <button type="submit">Fetch Balance</button>
        </form>
        <?php if ($balanceResult !== null): ?>
            <pre>Balance: <?= $balanceResult ?></pre>
        <?php endif; ?>
    </fieldset>

    <fieldset>
        <legend>Get Transactions</legend>
        <form method="GET">
            <input type="hidden" name="action" value="list">
            <label>User ID:
                <input type="number" name="user_id" value="1" required>
            </label>
            <label>Start Date:
                <input type="text" id="datepicker_start" name="start" required>
            </label>
            <label>End Date:
                <input type="text" id="datepicker_end" name="end" required>
            </label>
            <button type="submit">Fetch Transactions</button>
        </form>
        <?php if (is_array($txList)): ?>
            <pre><?= htmlspecialchars(json_encode($txList, JSON_PRETTY_PRINT)) ?></pre>
        <?php endif; ?>
    </fieldset>

    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        flatpickr("#dtpicker", {
            enableTime: true,
            dateFormat: "Y-m-d\TH:i",
        });
        flatpickr("#datepicker_balance", { dateFormat: "Y-m-d" });
        flatpickr("#datepicker_start", { dateFormat: "Y-m-d" });
        flatpickr("#datepicker_end",   { dateFormat: "Y-m-d" });
    </script>
</body>
</html>
