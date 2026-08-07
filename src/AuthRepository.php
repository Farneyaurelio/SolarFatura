<?php

declare(strict_types=1);

namespace SolarFatura;

use PDO;

final class AuthRepository
{
    private PDO $db;

    public function __construct(string $databasePath)
    {
        $this->db = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }

    public function hasUsers(): bool
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    public function createAdmin(string $name, string $email, string $password): void
    {
        $statement = $this->db->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)');
        $statement->execute([
            'name' => trim($name),
            'email' => mb_strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    /** @return array{id: int, name: string, email: string}|null */
    public function authenticate(string $email, string $password): ?array
    {
        $statement = $this->db->prepare('SELECT id, name, email, password_hash FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => mb_strtolower(trim($email))]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }
        return ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email']];
    }
}
