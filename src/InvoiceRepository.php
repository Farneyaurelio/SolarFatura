<?php

declare(strict_types=1);

namespace SolarFatura;

use DateTimeImmutable;
use PDO;

final class InvoiceRepository
{
    private PDO $db;

    public function __construct(string $databasePath)
    {
        $this->db = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL,
            source_file TEXT NOT NULL UNIQUE,
            reference_month TEXT NOT NULL,
            due_date TEXT,
            amount_due NUMERIC NOT NULL,
            savings_amount NUMERIC NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            paid_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(customer_id) REFERENCES customers(id)
        )');
        $columns = $this->db->query('PRAGMA table_info(invoices)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('payload_json', $columns, true)) {
            $this->db->exec('ALTER TABLE invoices ADD COLUMN payload_json TEXT');
        }
    }

    /** @param array<string, mixed> $data @param array<string, float> $calculation */
    public function saveGenerated(int $customerId, array $data, array $calculation): void
    {
        $statement = $this->db->prepare('INSERT INTO invoices (customer_id, source_file, reference_month, due_date, amount_due, savings_amount, payload_json)
            VALUES (:customer_id, :source_file, :reference_month, :due_date, :amount_due, :savings_amount, :payload_json)
            ON CONFLICT(source_file) DO UPDATE SET
              customer_id = excluded.customer_id,
              reference_month = excluded.reference_month,
              due_date = excluded.due_date,
              amount_due = excluded.amount_due,
              savings_amount = excluded.savings_amount,
              payload_json = excluded.payload_json,
              updated_at = CURRENT_TIMESTAMP');
        $statement->execute([
            'customer_id' => $customerId,
            'source_file' => (string) $data['source_file'],
            'reference_month' => (string) $data['reference_month'],
            'due_date' => $this->isoDate((string) $data['due_date']),
            'amount_due' => $calculation['amount_due'],
            'savings_amount' => $calculation['savings'],
            'payload_json' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM invoices WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @param array<string, string> $input */
    public function updateRecord(int $id, array $input): void
    {
        $status = $input['status'] === 'paid' ? 'paid' : 'pending';
        $statement = $this->db->prepare('UPDATE invoices SET reference_month = :reference_month, due_date = :due_date, amount_due = :amount_due, savings_amount = :savings_amount, status = :status, paid_at = :paid_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute([
            'id' => $id,
            'reference_month' => trim($input['reference_month']),
            'due_date' => $this->isoDate($input['due_date']),
            'amount_due' => (float) $input['amount_due'],
            'savings_amount' => (float) $input['savings_amount'],
            'status' => $status,
            'paid_at' => $status === 'paid' ? date('Y-m-d H:i:s') : null,
        ]);
    }

    public function markPaid(int $id): void
    {
        $statement = $this->db->prepare("UPDATE invoices SET status = 'paid', paid_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $statement->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM invoices WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function forCustomer(int $customerId): array
    {
        $statement = $this->db->prepare('SELECT * FROM invoices WHERE customer_id = :customer_id ORDER BY due_date DESC, id DESC');
        $statement->execute(['customer_id' => $customerId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{pending: int, overdue: int, amount_pending: float} */
    public function summaryForCustomer(int $customerId): array
    {
        $statement = $this->db->prepare("SELECT
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'pending' AND due_date < date('now') THEN 1 ELSE 0 END) AS overdue,
            SUM(CASE WHEN status = 'pending' THEN amount_due ELSE 0 END) AS amount_pending
            FROM invoices WHERE customer_id = :customer_id");
        $statement->execute(['customer_id' => $customerId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['pending' => (int) ($row['pending'] ?? 0), 'overdue' => (int) ($row['overdue'] ?? 0), 'amount_pending' => (float) ($row['amount_pending'] ?? 0)];
    }

    private function isoDate(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('d/m/Y', $value);
        return $date ? $date->format('Y-m-d') : null;
    }
}
