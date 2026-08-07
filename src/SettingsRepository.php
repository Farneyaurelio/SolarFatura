<?php

declare(strict_types=1);

namespace SolarFatura;

use PDO;

final class SettingsRepository
{
    private PDO $db;

    public function __construct(string $databasePath)
    {
        $this->db = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE IF NOT EXISTS company_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            trade_name TEXT NOT NULL DEFAULT "SolarFatura",
            legal_name TEXT,
            cnpj TEXT,
            address TEXT,
            phone TEXT,
            email TEXT,
            pix_key TEXT,
            email_subject TEXT NOT NULL DEFAULT "{{empresa}} | {{cliente}} | {{referencia}}",
            email_body TEXT NOT NULL DEFAULT "Olá, {{cliente}}.\n\nEncaminhamos a sua fatura {{empresa}} referente ao período de {{referencia}}.\n\nDados da cobrança\n• Unidade consumidora: {{unidade_consumidora}}\n• Data de envio: {{data_envio}}\n• Vencimento: {{vencimento}}\n• Valor total a pagar: {{valor_total}}\n• Economia obtida nesta fatura: {{economia}} ({{economia_percentual}})\n\nPor gentileza, anexe o PDF desta fatura a esta mensagem antes do envio. Em caso de dúvidas, estamos à disposição.\n\nAtenciosamente,\n{{empresa}}"
        )');
        $this->db->exec('INSERT OR IGNORE INTO company_settings (id) VALUES (1)');
        $columns = $this->db->query('PRAGMA table_info(company_settings)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('logo_path', $columns, true)) {
            $this->db->exec('ALTER TABLE company_settings ADD COLUMN logo_path TEXT');
        }
        if (!in_array('github_repository', $columns, true)) {
            $this->db->exec('ALTER TABLE company_settings ADD COLUMN github_repository TEXT');
        }
    }

    /** @return array<string, string> */
    public function get(): array
    {
        $settings = $this->db->query('SELECT * FROM company_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        if (($settings['github_repository'] ?? '') === '') {
            $settings['github_repository'] = 'Farneyaurelio/SolarFatura';
        }
        return $settings;
    }

    /** @param array<string, string> $input */
    public function save(array $input): void
    {
        $statement = $this->db->prepare('UPDATE company_settings SET
            trade_name = :trade_name, legal_name = :legal_name, cnpj = :cnpj, address = :address,
            phone = :phone, email = :email, pix_key = :pix_key, logo_path = :logo_path, email_subject = :email_subject,
            email_body = :email_body WHERE id = 1');
        $statement->execute([
            'trade_name' => trim($input['trade_name']) ?: 'SolarFatura',
            'legal_name' => trim($input['legal_name'] ?? ''),
            'cnpj' => trim($input['cnpj'] ?? ''),
            'address' => trim($input['address'] ?? ''),
            'phone' => trim($input['phone'] ?? ''),
            'email' => trim($input['email'] ?? ''),
            'pix_key' => trim($input['pix_key'] ?? ''),
            'logo_path' => trim($input['logo_path'] ?? ''),
            'email_subject' => trim($input['email_subject']) ?: '{{empresa}} | {{cliente}} | {{referencia}}',
            'email_body' => trim($input['email_body']) ?: 'Olá, {{cliente}}.',
        ]);
    }

    public function saveGitHubRepository(string $repository): void
    {
        $statement = $this->db->prepare('UPDATE company_settings SET github_repository = :github_repository WHERE id = 1');
        $statement->execute(['github_repository' => trim($repository)]);
    }
}
