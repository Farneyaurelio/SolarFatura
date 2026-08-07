<?php

declare(strict_types=1);

namespace SolarFatura;

use PDO;

final class CustomerRepository
{
    private PDO $db;

    public function __construct(string $databasePath)
    {
        $directory = dirname($databasePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
        $this->db = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            display_name TEXT NOT NULL,
            installation_number TEXT NOT NULL UNIQUE,
            address TEXT,
            phone TEXT,
            email TEXT,
            discount_percent NUMERIC NOT NULL DEFAULT 20,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }

    /** @return array<string, mixed>|null */
    public function findByInstallation(string $installationNumber): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM customers WHERE installation_number = :uc LIMIT 1');
        $statement->execute(['uc' => $this->normalizeInstallation($installationNumber)]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @param array<string, string> $input */
    public function save(array $input): void
    {
        $statement = $this->db->prepare('INSERT INTO customers (display_name, installation_number, address, phone, email, discount_percent)
            VALUES (:display_name, :installation_number, :address, :phone, :email, :discount_percent)
            ON CONFLICT(installation_number) DO UPDATE SET
              display_name = excluded.display_name,
              address = excluded.address,
              phone = excluded.phone,
              email = excluded.email,
              discount_percent = excluded.discount_percent,
              updated_at = CURRENT_TIMESTAMP');
        $statement->execute([
            'display_name' => trim($input['display_name']),
            'installation_number' => $this->normalizeInstallation($input['installation_number']),
            'address' => trim($input['address'] ?? ''),
            'phone' => trim($input['phone'] ?? ''),
            'email' => trim($input['email'] ?? ''),
            'discount_percent' => (float) ($input['discount_percent'] ?? 20),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM customers ORDER BY display_name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function normalizeInstallation(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? $value;
    }
}
