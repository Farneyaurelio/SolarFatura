<?php

declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();

use SolarFatura\Calculator;
use SolarFatura\CemigParser;
use SolarFatura\CustomerRepository;
use SolarFatura\AuthRepository;
use SolarFatura\InvoiceRepository;
use SolarFatura\SettingsRepository;

require_once __DIR__ . '/../src/CemigParser.php';
require_once __DIR__ . '/../src/Calculator.php';
require_once __DIR__ . '/../src/CustomerRepository.php';
require_once __DIR__ . '/../src/AuthRepository.php';
require_once __DIR__ . '/../src/InvoiceRepository.php';
require_once __DIR__ . '/../src/SettingsRepository.php';

function escape(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function money(float|string|null $value): string { return 'R$ ' . number_format((float) $value, 2, ',', '.'); }
function field(string $name, mixed $value, string $label, string $step = 'any'): string {
    return '<label><span>' . escape($label) . '</span><input name="' . escape($name) . '" value="' . escape($value) . '" type="number" step="' . escape($step) . '"></label>';
}
function referenceLabel(string $reference): string {
    $months = ['JAN' => 'janeiro', 'FEV' => 'fevereiro', 'MAR' => 'março', 'ABR' => 'abril', 'MAI' => 'maio', 'JUN' => 'junho', 'JUL' => 'julho', 'AGO' => 'agosto', 'SET' => 'setembro', 'OUT' => 'outubro', 'NOV' => 'novembro', 'DEZ' => 'dezembro'];
    [$month, $year] = array_pad(explode('/', $reference, 2), 2, '');
    return ($months[$month] ?? $month) . ($year ? " de {$year}" : '');
}
function renderTemplate(string $template, array $values): string { return strtr($template, $values); }
function csrfToken(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrfValid(): bool { return hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? '')); }
/** @return array{latest?: string, url?: string, published_at?: string, notes?: string, error?: string} */
function githubLatestRelease(string $repository): array {
    if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) { return ['error' => 'Informe o repositório no formato usuario/repositorio.']; }
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) { return ['error' => 'A verificação remota não está habilitada nesta instalação do PHP.']; }
    [$owner, $name] = explode('/', $repository, 2);
    $context = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: SolarFatura-Updater\r\nAccept: application/vnd.github+json\r\n"]]);
    $response = @file_get_contents('https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/releases/latest', false, $context);
    if ($response === false) { return ['error' => 'Não foi possível consultar as releases no GitHub.']; }
    $release = json_decode($response, true);
    if (!is_array($release) || empty($release['tag_name'])) { return ['error' => 'Nenhuma release estável foi encontrada nesse repositório.']; }
    return ['latest' => (string) $release['tag_name'], 'url' => (string) ($release['html_url'] ?? ''), 'published_at' => (string) ($release['published_at'] ?? ''), 'notes' => trim((string) ($release['body'] ?? ''))];
}

$error = null;
$data = null;
$calculation = null;
$invoiceChart = [];
$invoiceSaved = false;
$autoPrint = false;
$appVersion = '1.0.1';
$updateCheck = null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$customers = new CustomerRepository(dirname(__DIR__) . '/storage/solarfatura.sqlite');
$invoices = new InvoiceRepository(dirname(__DIR__) . '/storage/solarfatura.sqlite');
$settings = new SettingsRepository(dirname(__DIR__) . '/storage/solarfatura.sqlite');
$company = $settings->get();
$updateRepository = trim((string) ($company['github_repository'] ?? '')) ?: 'Farneyaurelio/SolarFatura';
$auth = new AuthRepository(dirname(__DIR__) . '/storage/solarfatura.sqlite');
$page = match ($_GET['page'] ?? '') { 'customers' => 'customers', 'customer' => 'customer', 'company' => 'company', 'updates' => 'updates', 'invoice_record' => 'invoice_record', 'invoice_saved' => 'invoice_saved', default => 'upload' };

if (($_GET['asset'] ?? '') === 'company-logo' && !empty($company['logo_path'])) {
    $logoFile = dirname(__DIR__) . '/storage/company/' . basename($company['logo_path']);
    if (is_file($logoFile)) {
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][strtolower(pathinfo($logoFile, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=86400');
        readfile($logoFile);
        exit;
    }
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'setup_admin') {
    $page = 'setup';
    if (!csrfValid()) {
        $error = 'Sessão expirada. Tente novamente.';
    } elseif ($auth->hasUsers()) {
        $error = 'O administrador já foi configurado.';
    } elseif (trim($_POST['name'] ?? '') === '' || !filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) || strlen($_POST['password'] ?? '') < 12 || ($_POST['password'] ?? '') !== ($_POST['password_confirmation'] ?? '')) {
        $error = 'Informe nome, e-mail válido e uma senha com ao menos 12 caracteres. As senhas devem ser iguais.';
    } else {
        $auth->createAdmin($_POST['name'], $_POST['email'], $_POST['password']);
        session_regenerate_id(true);
        $_SESSION['user'] = $auth->authenticate($_POST['email'], $_POST['password']);
        header('Location: ./');
        exit;
    }
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $page = 'login';
    if (!csrfValid()) {
        $error = 'Sessão expirada. Tente novamente.';
    } else {
        $user = $auth->authenticate($_POST['email'] ?? '', $_POST['password'] ?? '');
        if (!$user) {
            $error = 'E-mail ou senha inválidos.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = $user;
            header('Location: ./');
            exit;
        }
    }
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    if (csrfValid()) {
        $_SESSION = [];
        session_destroy();
    }
    header('Location: ./');
    exit;
}

$authenticated = isset($_SESSION['user']['id']);
if (!$authenticated) {
    $page = $auth->hasUsers() ? 'login' : 'setup';
}
$csrfOK = $method !== 'POST' || csrfValid();
if ($authenticated && !$csrfOK && in_array($_POST['action'] ?? '', ['save_customer', 'upload', 'generate', 'save_invoice', 'update_invoice', 'mark_paid', 'delete_invoice', 'save_update_repository', 'check_updates'], true)) {
    $error = 'Sessão expirada. Atualize a página e tente novamente.';
    $page = ($_POST['action'] ?? '') === 'save_customer' ? 'customers' : 'upload';
}

if ($authenticated && $csrfOK && $method === 'POST' && ($_POST['action'] ?? '') === 'save_update_repository') {
    $repository = trim((string) ($_POST['github_repository'] ?? ''));
    if ($repository !== '' && !preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) {
        $error = 'Use o formato usuario/repositorio do GitHub.';
    } else {
        $settings->saveGitHubRepository($repository);
        $company = $settings->get();
        $_GET['saved'] = '1';
    }
    $page = 'updates';
}

if ($authenticated && $csrfOK && $method === 'POST' && ($_POST['action'] ?? '') === 'check_updates') {
    $page = 'updates';
    $updateCheck = githubLatestRelease($updateRepository);
}

if ($authenticated && $csrfOK && $method === 'POST' && ($_POST['action'] ?? '') === 'save_company') {
    $companyInput = $_POST;
    $companyInput['logo_path'] = $company['logo_path'] ?? '';
    $logo = $_FILES['company_logo'] ?? null;
    if ($logo && $logo['error'] !== UPLOAD_ERR_NO_FILE) {
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        $mime = $logo['error'] === UPLOAD_ERR_OK && function_exists('mime_content_type') ? mime_content_type($logo['tmp_name']) : false;
        if ($logo['error'] !== UPLOAD_ERR_OK || $logo['size'] > 2 * 1024 * 1024 || !isset($allowed[$mime])) {
            $error = 'Envie uma logo PNG, JPG ou WEBP de até 2 MB.';
        } else {
            $logoDir = dirname(__DIR__) . '/storage/company';
            if (!is_dir($logoDir)) { mkdir($logoDir, 0750, true); }
            $filename = 'logo.' . $allowed[$mime];
            if (!move_uploaded_file($logo['tmp_name'], $logoDir . '/' . $filename)) {
                $error = 'Não foi possível salvar a logo enviada.';
            } else {
                $companyInput['logo_path'] = $filename;
            }
        }
    }
    if (!$error) {
        $settings->save($companyInput);
        $company = $settings->get();
        $_GET['saved'] = '1';
    }
    $page = 'company';
}

if ($authenticated && $csrfOK && $method === 'POST' && ($_POST['action'] ?? '') === 'update_invoice') {
    $invoices->updateRecord((int) $_POST['invoice_id'], $_POST);
    header('Location: ./?page=invoice_record&id=' . (int) $_POST['invoice_id'] . '&saved=1');
    exit;
}

if ($authenticated && $csrfOK && $method === 'POST' && ($_POST['action'] ?? '') === 'mark_paid') {
    $invoices->markPaid((int) $_POST['invoice_id']);
    header('Location: ./?page=customer&id=' . (int) $_POST['customer_id'] . '&paid=1');
    exit;
}

if ($authenticated && $csrfOK && $method === 'POST' && ($_POST['action'] ?? '') === 'delete_invoice') {
    $customerId = (int) $_POST['customer_id'];
    $invoices->delete((int) $_POST['invoice_id']);
    header('Location: ./?page=customer&id=' . $customerId . '&deleted=1');
    exit;
}

if ($authenticated && $csrfOK && $method === 'POST' && ($_POST['action'] ?? '') === 'save_customer') {
    if (trim($_POST['display_name'] ?? '') === '' || trim($_POST['installation_number'] ?? '') === '') {
        $error = 'Informe o nome de exibição e o número da unidade consumidora.';
        $page = 'customers';
    } else {
        $customers->save($_POST);
        header('Location: ' . (($_POST['resume'] ?? '') === '1' ? './?resume=1' : './?page=customers&saved=1'));
        exit;
    }
}

if ($authenticated && $csrfOK && $method === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $file = $_FILES['utility_bill'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Selecione um PDF da Cemig para continuar.';
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        $error = 'O arquivo precisa estar no formato PDF.';
    } elseif ($file['size'] > 12 * 1024 * 1024) {
        $error = 'O PDF deve ter no máximo 12 MB.';
    } else {
        $storage = dirname(__DIR__) . '/storage/uploads';
        if (!is_dir($storage)) { mkdir($storage, 0750, true); }
        $target = $storage . '/' . bin2hex(random_bytes(12)) . '.pdf';
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            $error = 'Não foi possível armazenar o PDF enviado.';
        } else {
            $pdfToText = getenv('SOLARFATURA_PDFTOTEXT');
            if (!$pdfToText && DIRECTORY_SEPARATOR === '\\' && is_file('C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe')) {
                $pdfToText = 'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe';
            }
            $command = ($pdfToText ? escapeshellarg($pdfToText) : 'pdftotext') . ' -raw ' . escapeshellarg($target) . ' - 2>&1';
            $text = (string) shell_exec($command);
            if (!str_contains($text, 'CEMIG')) {
                $error = 'Não foi possível extrair o texto do PDF. Instale/configure o Poppler (pdftotext) e tente novamente.';
            } else {
                $data = (new CemigParser())->parse($text);
                $data['source_file'] = basename($target);
                $data['utility_holder'] = $data['customer_name'];
                $customer = $data['installation_number'] ? $customers->findByInstallation((string) $data['installation_number']) : null;
                if ($customer) {
                    $data['customer_found'] = true;
                    $data['customer_id'] = $customer['id'];
                    $data['customer_name'] = $customer['display_name'];
                    $data['customer_address'] = $customer['address'];
                    $data['discount_percent'] = $customer['discount_percent'];
                } else {
                    $data['customer_found'] = false;
                    $data['customer_id'] = '';
                    $data['customer_address'] = $data['utility_address'] ?? '';
                    $data['discount_percent'] = 20;
                    $data['warnings'][] = 'Esta UC ainda não tem cadastro interno. O nome da Cemig foi mantido apenas para conferência.';
                }
                $data['bonus_amount'] = 0;
                $data['adjustment_amount'] = 0;
                if (!$customer) {
                    $_SESSION['pending_invoice'] = $data;
                }
                $page = 'review';
            }
        }
    }
}

if ($authenticated && ($_GET['resume'] ?? '') === '1' && isset($_SESSION['pending_invoice'])) {
    $data = $_SESSION['pending_invoice'];
    $resumeCustomer = !empty($data['installation_number']) ? $customers->findByInstallation((string) $data['installation_number']) : null;
    if ($resumeCustomer) {
        $data['customer_found'] = true;
        $data['customer_id'] = $resumeCustomer['id'];
        $data['customer_name'] = $resumeCustomer['display_name'];
        $data['customer_address'] = $resumeCustomer['address'];
        $data['discount_percent'] = $resumeCustomer['discount_percent'];
        $data['bonus_amount'] = $data['bonus_amount'] ?? 0;
        $data['adjustment_amount'] = $data['adjustment_amount'] ?? 0;
        $data['warnings'] = array_values(array_filter($data['warnings'] ?? [], static fn (string $warning): bool => !str_contains($warning, 'ainda não tem cadastro')));
        unset($_SESSION['pending_invoice']);
        $page = 'review';
    }
}

if ($authenticated && $csrfOK && $method === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $data = $_POST;
    $data['consumption_history'] = json_decode($_POST['consumption_history'] ?? '[]', true) ?: [];
    $calculation = (new Calculator())->calculate($data);
    if ((int) ($data['customer_id'] ?? 0) > 0) {
        $invoiceChart = array_reverse(array_slice($invoices->forCustomer((int) $data['customer_id']), 0, 12));
    }
    $invoiceChart[] = ['reference_month' => $data['reference_month'], 'amount_due' => $calculation['amount_due'], 'savings_amount' => $calculation['savings']];
    $page = 'invoice';
}

if ($authenticated && $csrfOK && $method === 'POST' && ($_POST['action'] ?? '') === 'save_invoice') {
    $data = $_POST;
    $data['consumption_history'] = [];
    $calculation = (new Calculator())->calculate($data);
    if ((int) ($data['customer_id'] ?? 0) > 0) {
        $invoices->saveGenerated((int) $data['customer_id'], $data, $calculation);
        $invoiceChart = array_reverse(array_slice($invoices->forCustomer((int) $data['customer_id']), 0, 12));
        $invoiceSaved = true;
    } else {
        $error = 'Cadastre esta unidade consumidora antes de salvar a cobrança no histórico.';
        $invoiceChart = [['reference_month' => $data['reference_month'], 'amount_due' => $calculation['amount_due'], 'savings_amount' => $calculation['savings']]];
    }
    $page = 'invoice';
}

$dashboard = $authenticated && $page === 'upload' ? $invoices->dashboard() : null;
$profileCustomer = null;
$profileInvoices = [];
$profileSummary = ['pending' => 0, 'overdue' => 0, 'amount_pending' => 0.0];
if ($authenticated && $page === 'customer') {
    $profileCustomer = $customers->findById((int) ($_GET['id'] ?? 0));
    if ($profileCustomer) {
        $profileInvoices = $invoices->forCustomer((int) $profileCustomer['id']);
        $profileSummary = $invoices->summaryForCustomer((int) $profileCustomer['id']);
    } else {
        $page = 'customers';
        $error = 'Cliente não encontrado.';
    }
}

$invoiceRecord = null;
if ($authenticated && $page === 'invoice_record') {
    $invoiceRecord = $invoices->findById((int) ($_GET['id'] ?? 0));
    if (!$invoiceRecord) {
        $page = 'customers';
        $error = 'Cobrança não encontrada.';
    }
}

if ($authenticated && $page === 'invoice_saved') {
    $savedInvoice = $invoices->findById((int) ($_GET['id'] ?? 0));
    $savedPayload = $savedInvoice ? json_decode((string) $savedInvoice['payload_json'], true) : null;
    if (!$savedInvoice || !is_array($savedPayload)) {
        $sourcePath = $savedInvoice ? dirname(__DIR__) . '/storage/uploads/' . basename((string) $savedInvoice['source_file']) : '';
        $pdfToText = getenv('SOLARFATURA_PDFTOTEXT');
        if (!$pdfToText && DIRECTORY_SEPARATOR === '\\' && is_file('C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe')) {
            $pdfToText = 'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe';
        }
        $sourceText = is_file($sourcePath) ? (string) shell_exec(($pdfToText ? escapeshellarg($pdfToText) : 'pdftotext') . ' -raw ' . escapeshellarg($sourcePath) . ' - 2>&1') : '';
        if (str_contains($sourceText, 'CEMIG')) {
            $data = (new CemigParser())->parse($sourceText);
            $legacyCustomer = $customers->findById((int) $savedInvoice['customer_id']);
            $data['source_file'] = $savedInvoice['source_file'];
            $data['customer_id'] = $savedInvoice['customer_id'];
            $data['customer_found'] = true;
            $data['customer_name'] = $legacyCustomer['display_name'] ?? $data['customer_name'];
            $data['customer_address'] = $legacyCustomer['address'] ?? $data['utility_address'] ?? '';
            $data['discount_percent'] = $legacyCustomer['discount_percent'] ?? 20;
            $data['bonus_amount'] = 0;
            $data['adjustment_amount'] = 0;
            $data['consumption_history'] = [];
            $calculation = (new Calculator())->calculate($data);
            $invoiceChart = array_reverse(array_slice($invoices->forCustomer((int) $savedInvoice['customer_id']), 0, 12));
            $invoiceSaved = true;
            $autoPrint = isset($_GET['print']);
            $page = 'invoice';
        } else {
            $page = 'invoice_record';
            $invoiceRecord = $savedInvoice;
            $error = 'Não foi possível reconstruir esta fatura porque o PDF de origem não está disponível.';
        }
    } else {
        $data = $savedPayload;
        $calculation = (new Calculator())->calculate($data);
        $invoiceChart = array_reverse(array_slice($invoices->forCustomer((int) $savedInvoice['customer_id']), 0, 12));
        $invoiceSaved = true;
        $autoPrint = isset($_GET['print']);
        $page = 'invoice';
    }
}

$documentTitle = 'SolarFatura';
$deliveryCustomer = null;
$emailHref = '';
$whatsAppHref = '';
if ($page === 'invoice' && $data) {
    $documentTitle = 'SolarFatura - ' . preg_replace('/[^\pL\pN]+/u', '-', (string) $data['customer_name']) . ' - ' . str_replace('/', '-', (string) $data['reference_month']);
    $deliveryCustomer = (int) ($data['customer_id'] ?? 0) > 0 ? $customers->findById((int) $data['customer_id']) : null;
    $referenceText = referenceLabel((string) $data['reference_month']);
    $templateValues = ['{{empresa}}' => $company['trade_name'], '{{cliente}}' => (string) $data['customer_name'], '{{referencia}}' => $referenceText, '{{unidade_consumidora}}' => (string) ($data['installation_number'] ?? ''), '{{data_envio}}' => date('d/m/Y'), '{{vencimento}}' => (string) $data['due_date'], '{{valor_total}}' => money($calculation['amount_due']), '{{economia}}' => money($calculation['savings']), '{{economia_percentual}}' => number_format($calculation['savings_percent'], 2, ',', '.') . '%'];
    $message = renderTemplate($company['email_body'], $templateValues);
    if ($deliveryCustomer && $deliveryCustomer['email']) {
        $emailHref = 'mailto:' . rawurlencode($deliveryCustomer['email']) . '?subject=' . rawurlencode(renderTemplate($company['email_subject'], $templateValues)) . '&body=' . rawurlencode($message);
    }
    $phone = $deliveryCustomer ? preg_replace('/\D/', '', (string) $deliveryCustomer['phone']) : '';
    if ($phone) {
        $whatsAppHref = 'https://wa.me/' . (str_starts_with($phone, '55') ? $phone : '55' . $phone) . '?text=' . rawurlencode($message);
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= escape($documentTitle) ?></title>
  <style>
    :root{--green:#19a974;--ink:#17342d;--muted:#668078;--line:#dce8e3;--soft:#f4faf7;--amber:#f7b955}*,*::before,*::after{box-sizing:border-box} body{margin:0;background:var(--soft);color:var(--ink);font:16px/1.45 Inter,Segoe UI,Arial,sans-serif}.wrap{max-width:1050px;margin:auto;padding:32px 22px 60px}.brand{display:flex;align-items:center;gap:12px;font-weight:800;font-size:23px}.brand i{display:inline-grid;place-items:center;width:38px;height:38px;border-radius:12px;background:var(--green);color:#fff;font-style:normal}.tag{color:var(--muted);margin:4px 0 28px}.panel{background:#fff;border:1px solid var(--line);border-radius:18px;padding:28px;box-shadow:0 14px 40px #154a3510}.upload{border:2px dashed #9dcdbb;border-radius:16px;background:#f9fdfb;padding:42px;text-align:center}.upload input{display:block;margin:20px auto}.btn{border:0;border-radius:10px;padding:12px 18px;background:var(--green);color:#fff;font-weight:700;cursor:pointer}.btn.light{background:#e7f6ef;color:#126747}.alert{padding:14px 16px;border-radius:10px;background:#fff1ef;color:#a33126;margin-bottom:18px}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.grid.two{grid-template-columns:repeat(2,1fr)}label{display:block;font-size:13px;font-weight:700;color:#3f6056}input{width:100%;margin-top:5px;border:1px solid #bdd3ca;border-radius:8px;padding:10px;font:inherit;color:var(--ink)}.warning{background:#fff8e8;border-left:4px solid var(--amber);padding:12px;margin:16px 0}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:22px 0}.card{border:1px solid var(--line);border-radius:13px;padding:16px}.card small{display:block;color:var(--muted)}.card strong{display:block;font-size:22px;margin-top:4px}.invoice{background:#fff;max-width:860px;margin:auto;padding:42px;border-radius:10px}.invoice-head{display:flex;justify-content:space-between;border-bottom:2px solid var(--green);padding-bottom:20px}.invoice h1{margin:0;font-size:28px}.invoice h2{font-size:16px;margin-top:28px}.table{width:100%;border-collapse:collapse}.table td{padding:10px 0;border-bottom:1px solid var(--line)}.table td:last-child{text-align:right;font-weight:700}.total{display:flex;justify-content:space-between;align-items:center;background:#e8f7ef;padding:16px;border-radius:12px;font-size:19px;font-weight:800;margin-top:18px}.chart{width:100%;height:190px;border:1px solid var(--line);border-radius:12px;padding:12px;margin-top:14px}.actions{display:flex;justify-content:flex-end;gap:10px;margin:20px auto;max-width:860px}@media(max-width:700px){.grid,.grid.two,.summary{grid-template-columns:1fr 1fr}.invoice{padding:22px}}@media print{body{background:#fff}.wrap{padding:0}.brand,.tag,.actions{display:none}.invoice{max-width:none;box-shadow:none}.panel{border:0;box-shadow:none;padding:0}}
  </style>
  <style>
    .grid{grid-template-columns:repeat(3,minmax(0,1fr))}.grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}.grid>label{min-width:0}input,textarea{min-width:0;max-width:100%}textarea{width:100%;margin-top:5px;border:1px solid #bdd3ca;border-radius:8px;padding:10px;font:inherit;color:var(--ink);min-height:210px;resize:vertical}.customer-form{max-width:920px}.customer-form .grid{column-gap:24px;row-gap:18px}.customer-form label{width:100%;min-width:0}.customer-form input{height:44px}.chart{height:310px}.actions form{margin:0}.icon-btn{display:inline-grid;place-items:center;width:40px;height:40px;margin:0 3px;border:0;border-radius:10px;background:#e7f6ef;color:#126747;font:20px/1 Segoe UI Symbol,Arial,sans-serif;text-decoration:none;vertical-align:middle;cursor:pointer}.icon-btn.primary{background:var(--green);color:#fff}.icon-btn:hover{filter:brightness(.95);transform:translateY(-1px)}.main-nav{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 18px}.main-nav .btn{white-space:nowrap}.invoice-actions{max-width:860px;justify-content:flex-start;flex-wrap:wrap;margin:0 auto 24px}.invoice-top{display:flex;justify-content:space-between;gap:24px;border-bottom:3px solid var(--green);padding-bottom:14px}.invoice-branding{display:flex;align-items:center;gap:14px}.invoice-branding img{width:92px;height:58px;object-fit:contain}.invoice-branding .brand{font-size:25px}.invoice-branding p{margin:3px 0 0;color:var(--muted)}.invoice-meta{min-width:205px;border-left:1px solid var(--line);padding-left:20px}.invoice-meta small{display:block;color:var(--muted);font-weight:700;text-transform:uppercase}.invoice-meta strong{display:block;font-size:21px}.party-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:16px 0}.party-card{border:1px solid #b8d5c9;border-radius:10px;padding:14px;min-height:142px}.party-card h3{margin:0 0 10px;color:#126747;font-size:13px;text-transform:uppercase;letter-spacing:.04em}.party-card strong{display:block;font-size:17px}.party-card p{margin:4px 0}.party-card .credit{margin-top:10px;padding-top:8px;border-top:1px dashed #b8d5c9;color:#27574a}.auth{max-width:510px;margin:9vh auto}.auth .panel{padding:34px}.auth h1{margin-top:0}.auth label{margin-top:14px}.auth .btn{width:100%;margin-top:22px}.account{display:flex;justify-content:space-between;align-items:center;margin:0 0 18px}.account form{margin:0}.account .btn{padding:8px 12px}@media(max-width:700px){.grid,.grid.two,.party-grid{grid-template-columns:1fr}.invoice-top{flex-direction:column}.invoice-meta{border-left:0;border-top:1px solid var(--line);padding:12px 0 0}.customer-form{max-width:none}.chart{height:270px}.wrap{padding:22px 14px}.panel{padding:20px}}@media print{@page{size:A4;margin:8mm}body{font-size:9pt}.wrap{max-width:none!important;padding:0!important}.wrap>.brand,.wrap>.tag,.wrap>.account,.wrap>p,.actions{display:none!important}.invoice{max-width:none!important;padding:0!important;border-radius:0!important}.invoice-top{padding-bottom:7px!important;gap:14px}.invoice-branding img{width:70px;height:45px}.invoice-branding .brand{font-size:19px}.invoice-meta strong{font-size:16px}.party-grid{gap:8px;margin:9px 0}.party-card{min-height:0;padding:9px}.party-card h3{margin-bottom:4px}.invoice h2{margin:10px 0 4px!important;font-size:13px!important}.invoice p{margin:4px 0}.summary{margin:7px 0!important;gap:6px!important}.card{padding:7px!important}.card strong{font-size:14px!important}.table td{padding:4px 0!important}.total{margin-top:8px!important;padding:10px!important}.chart{height:165px!important;margin:5px 0!important;break-inside:avoid;page-break-inside:avoid}}
    .logo-fallback{display:grid;place-items:center;width:58px;height:58px;border-radius:12px;background:var(--green);color:#fff;font-size:27px}.invoice-branding .brand{gap:0}.invoice-branding .brand::before{display:none}@media print{.logo-fallback{width:45px;height:45px;font-size:21px}.main-nav{display:none!important}}
  </style>
  <style>
    .system-mark{display:grid;place-items:center;width:58px;height:58px;border-radius:14px;background:var(--green);color:#fff;font-size:29px;font-weight:700}.home-brand{text-decoration:none;color:inherit;width:max-content}.home-brand:hover{color:#126747}.invoice-branding .brand{gap:0;font-size:27px}.invoice-branding .brand::before{display:none}.invoice-branding p{line-height:1.35}.invoice-branding p small{color:var(--muted);font-size:12px}.manager-identity{display:flex;align-items:center;gap:10px;margin:0 0 9px}.manager-identity .manager-logo{display:block;max-width:120px;max-height:42px;object-fit:contain;object-position:left center;flex:0 0 auto}.manager-identity strong{margin:0}.party-card .manager-identity+p{margin-top:0}.customer-form input[name="amount_due"],.customer-form input[name="savings_amount"]{background:#f4faf7;color:#668078;pointer-events:none}.dashboard{margin-bottom:22px}.dashboard h1{margin-bottom:4px}.dashboard .summary{grid-template-columns:repeat(4,1fr);margin:18px 0}.dashboard .chart{height:250px;background:#fff}.dashboard-note{color:var(--muted);font-size:13px;margin:8px 0 0}@media(max-width:700px){.dashboard .summary{grid-template-columns:1fr 1fr}}@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}.invoice-branding .brand{display:flex!important;font-size:22px}.system-mark{width:45px;height:45px;font-size:23px}.manager-identity{gap:7px;margin-bottom:5px}.manager-identity .manager-logo{max-width:95px;max-height:32px}}
  </style>
</head>
<body><main class="wrap">
  <a class="brand home-brand" href="./" aria-label="Ir para a página inicial do SolarFatura"><i>☀</i> SolarFatura</a><p class="tag">Fatura inteligente de energia compensada</p>
  <?php if ($authenticated): ?><div class="account"><span>Conectado como <?= escape($_SESSION['user']['name']) ?></span><form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><button class="btn light">Sair</button></form></div><nav class="main-nav"><a class="btn light" href="./?page=company">Dados da Gestora</a><a class="btn light" href="./?page=customers">Clientes e unidades consumidoras</a><a class="btn light" href="./">Nova fatura</a><a class="btn light" href="./?page=updates">Atualizações</a></nav><?php endif; ?>
  <?php if ($page === 'setup'): ?>
    <section class="auth"><div class="panel"><h1>Configurar acesso</h1><p>Crie a conta de administrador deste computador. Ela será necessária para acessar dados de clientes e faturas.</p><?php if ($error): ?><div class="alert"><?= escape($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="action" value="setup_admin"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><label><span>Seu nome</span><input name="name" required autocomplete="name"></label><label><span>E-mail</span><input name="email" type="email" required autocomplete="email"></label><label><span>Senha</span><input name="password" type="password" minlength="12" required autocomplete="new-password"></label><label><span>Confirmar senha</span><input name="password_confirmation" type="password" minlength="12" required autocomplete="new-password"></label><button class="btn">Criar acesso seguro</button></form></div></section>
  <?php elseif ($page === 'login'): ?>
    <section class="auth"><div class="panel"><h1>Entrar no SolarFatura</h1><p>Use sua conta de administrador para continuar.</p><?php if ($error): ?><div class="alert"><?= escape($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><label><span>E-mail</span><input name="email" type="email" required autocomplete="username"></label><label><span>Senha</span><input name="password" type="password" required autocomplete="current-password"></label><button class="btn">Entrar</button></form></div></section>
  <?php elseif ($page === 'updates'): ?>
    <section class="panel customer-form"><h1>Atualizações</h1><p>Confira as versões publicadas no GitHub e mantenha o SolarFatura atualizado.</p><?php if ($error): ?><div class="alert"><?= escape($error) ?></div><?php endif; ?><?php if (isset($_GET['saved'])): ?><div class="warning">Repositório oficial salvo.</div><?php endif; ?><div class="grid two"><div class="card"><small>Versão instalada</small><strong>v<?= escape($appVersion) ?></strong></div><div class="card"><small>Canal de atualização</small><strong>Release estável</strong></div></div><form method="post" class="customer-form"><input type="hidden" name="action" value="save_update_repository"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><label><span>Repositório oficial no GitHub</span><input name="github_repository" placeholder="usuario/SolarFatura" value="<?= escape($company['github_repository'] ?? '') ?>"></label><p class="tag">Após criar o repositório público, informe-o aqui. O sistema consulta somente a última Release estável.</p><button class="btn">Salvar repositório</button></form><?php if (!empty($company['github_repository'])): ?><form method="post" class="actions" style="justify-content:flex-start"><input type="hidden" name="action" value="check_updates"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><button class="btn">Verificar atualizações</button></form><?php endif; ?><?php if ($updateCheck): ?><?php if (!empty($updateCheck['error'])): ?><div class="alert"><?= escape($updateCheck['error']) ?></div><?php else: ?><?php $hasUpdate = version_compare(ltrim((string) $updateCheck['latest'], 'vV'), $appVersion, '>'); ?><div class="warning"><strong><?= $hasUpdate ? 'Nova versão disponível: ' . escape($updateCheck['latest']) : 'Você já está na versão mais recente.' ?></strong><?php if (!empty($updateCheck['published_at'])): ?><br>Publicada em <?= escape(date('d/m/Y H:i', strtotime($updateCheck['published_at']))) ?>.<?php endif; ?><?php if (!empty($updateCheck['notes'])): ?><br><br><?= nl2br(escape($updateCheck['notes'])) ?><?php endif; ?><?php if ($hasUpdate && !empty($updateCheck['url'])): ?><p><a class="btn" target="_blank" rel="noopener" href="<?= escape($updateCheck['url']) ?>">Abrir download seguro da release</a></p><small>A instalação automática será ativada no aplicativo Windows, com backup e validação do pacote.</small><?php endif; ?></div><?php endif; ?><?php endif; ?></section>
  <?php elseif ($page === 'company'): ?>
    <section class="panel"><h1>Empresa e modelo de e-mail</h1><p>Estes dados identificam a fornecedora do serviço nas faturas e mensagens.</p><?php if ($error): ?><div class="alert"><?= escape($error) ?></div><?php endif; ?><?php if (isset($_GET['saved'])): ?><div class="warning">Dados da empresa e modelo de e-mail salvos.</div><?php endif; ?><form method="post" enctype="multipart/form-data" class="customer-form"><input type="hidden" name="action" value="save_company"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><h2>Dados da fornecedora</h2><div class="grid two"><label><span>Nome fantasia</span><input name="trade_name" value="<?= escape($company['trade_name']) ?>" required></label><label><span>Razão social</span><input name="legal_name" value="<?= escape($company['legal_name']) ?>"></label><label><span>CNPJ</span><input name="cnpj" value="<?= escape($company['cnpj']) ?>"></label><label><span>Telefone</span><input name="phone" value="<?= escape($company['phone']) ?>"></label><label><span>E-mail</span><input name="email" type="email" value="<?= escape($company['email']) ?>"></label><label><span>Chave Pix</span><input name="pix_key" value="<?= escape($company['pix_key']) ?>"></label></div><label><span>Endereço comercial</span><input name="address" value="<?= escape($company['address']) ?>"></label><h2>Logo da empresa</h2><?php if (!empty($company['logo_path'])): ?><p><img src="?asset=company-logo" alt="Logo atual" style="max-width:220px;max-height:90px;object-fit:contain;border:1px solid #dce8e3;border-radius:8px;padding:6px"></p><?php endif; ?><label><span>Enviar logo (PNG, JPG ou WEBP; até 2 MB)</span><input name="company_logo" type="file" accept="image/png,image/jpeg,image/webp"></label><h2>Mensagem automática de e-mail</h2><label><span>Assunto</span><input name="email_subject" value="<?= escape($company['email_subject']) ?>"></label><label><span>Corpo da mensagem</span><textarea name="email_body"><?= escape($company['email_body']) ?></textarea></label><p class="tag">Variáveis disponíveis: {{empresa}}, {{cliente}}, {{referencia}}, {{unidade_consumidora}}, {{data_envio}}, {{vencimento}}, {{valor_total}}, {{economia}}, {{economia_percentual}}.</p><p class="actions"><button class="btn">Salvar configurações</button></p></form></section>
  <?php elseif ($page === 'invoice_record' && $invoiceRecord): ?>
    <section class="panel customer-form"><p><a class="btn light" href="./?page=customer&id=<?= (int) $invoiceRecord['customer_id'] ?>">← Voltar para o cliente</a><?php if (!empty($invoiceRecord['payload_json'])): ?> <a class="btn" href="./?page=invoice_saved&id=<?= (int) $invoiceRecord['id'] ?>&print=1">Reimprimir fatura</a><?php endif; ?></p><h1>Editar cobrança</h1><p class="tag">Atualize os dados financeiros ou o status. A exclusão remove esta cobrança do histórico.</p><?php if ($error): ?><div class="alert"><?= escape($error) ?></div><?php endif; ?><?php if (isset($_GET['saved'])): ?><div class="warning">Cobrança atualizada.</div><?php endif; ?><form method="post"><input type="hidden" name="action" value="update_invoice"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="invoice_id" value="<?= (int) $invoiceRecord['id'] ?>"><div class="grid two"><label><span>Mês de referência</span><input name="reference_month" value="<?= escape($invoiceRecord['reference_month']) ?>" required></label><label><span>Vencimento</span><input name="due_date" value="<?= $invoiceRecord['due_date'] ? escape(date('d/m/Y', strtotime($invoiceRecord['due_date']))) : '' ?>" required></label><label><span>Valor cobrado (R$)</span><input name="amount_due" type="number" step="0.01" value="<?= escape($invoiceRecord['amount_due']) ?>" required></label><label><span>Economia (R$)</span><input name="savings_amount" type="number" step="0.01" value="<?= escape($invoiceRecord['savings_amount']) ?>" required></label><label><span>Status</span><select name="status"><option value="pending" <?= $invoiceRecord['status'] === 'pending' ? 'selected' : '' ?>>Pendente</option><option value="paid" <?= $invoiceRecord['status'] === 'paid' ? 'selected' : '' ?>>Paga</option></select></label></div><p class="actions"><button class="btn">Salvar alterações</button></p></form><form method="post" onsubmit="return confirm('Excluir esta cobrança do histórico?');"><input type="hidden" name="action" value="delete_invoice"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="invoice_id" value="<?= (int) $invoiceRecord['id'] ?>"><input type="hidden" name="customer_id" value="<?= (int) $invoiceRecord['customer_id'] ?>"><button class="btn light">Excluir cobrança</button></form></section>
  <?php elseif ($page === 'customer' && $profileCustomer): ?>
    <section class="panel"><p><a class="btn light" href="./?page=customers">← Voltar para clientes</a></p><h1><?= escape($profileCustomer['display_name']) ?></h1><p class="tag">Perfil da unidade consumidora <?= escape($profileCustomer['installation_number']) ?></p>
      <div class="grid two"><div class="card"><small>Endereço da instalação</small><strong><?= escape($profileCustomer['address'] ?: 'Não informado') ?></strong></div><div class="card"><small>Contato</small><strong><?= escape($profileCustomer['phone'] ?: 'Não informado') ?></strong><small><?= escape($profileCustomer['email']) ?></small></div><div class="card"><small>Desconto comercial</small><strong><?= escape($profileCustomer['discount_percent']) ?>%</strong></div><div class="card"><small>Faturas pendentes</small><strong><?= $profileSummary['pending'] ?> · <?= money($profileSummary['amount_pending']) ?></strong></div></div>
      <?php if ($profileSummary['overdue'] > 0): ?><div class="alert">Atenção: há <?= $profileSummary['overdue'] ?> fatura(s) vencida(s). O sistema poderá usar este indicador para alertas futuros.</div><?php else: ?><div class="warning">Sem faturas vencidas. Alertas de atraso serão incorporados a partir deste histórico.</div><?php endif; ?>
      <h2>Histórico de cobrança e pagamentos</h2><?php if (isset($_GET['paid'])): ?><div class="warning">Pagamento registrado como quitado.</div><?php endif; ?><?php if (isset($_GET['deleted'])): ?><div class="warning">Cobrança excluída do histórico.</div><?php endif; ?><table class="table"><tr><td><strong>Referência</strong></td><td><strong>Vencimento</strong></td><td><strong>Valor</strong></td><td><strong>Economia</strong></td><td><strong>Status</strong></td><td><strong>Ações</strong></td></tr><?php if (!$profileInvoices): ?><tr><td colspan="6">Ainda não há faturas geradas para esta unidade.</td></tr><?php endif; ?><?php foreach ($profileInvoices as $invoice): ?><tr><td><?= escape($invoice['reference_month']) ?></td><td><?= $invoice['due_date'] ? escape(date('d/m/Y', strtotime($invoice['due_date']))) : '—' ?></td><td><?= money($invoice['amount_due']) ?></td><td><?= money($invoice['savings_amount']) ?></td><td><?= $invoice['status'] === 'paid' ? 'Paga' : 'Pendente' ?></td><td><a class="icon-btn primary" title="Reimprimir fatura" aria-label="Reimprimir fatura" href="./?page=invoice_saved&id=<?= (int) $invoice['id'] ?>&print=1">🖨</a><?php if (!empty($invoice['payload_json'])): ?><a class="icon-btn" title="Ver fatura" aria-label="Ver fatura" href="./?page=invoice_saved&id=<?= (int) $invoice['id'] ?>">◉</a><?php endif; ?><a class="icon-btn" title="Editar cobrança" aria-label="Editar cobrança" href="./?page=invoice_record&id=<?= (int) $invoice['id'] ?>">✎</a><?php if ($invoice['status'] !== 'paid'): ?><form method="post" style="display:inline"><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>"><input type="hidden" name="customer_id" value="<?= (int) $profileCustomer['id'] ?>"><button class="icon-btn primary" title="Marcar como paga" aria-label="Marcar como paga">✓</button></form><?php endif; ?></td></tr><?php endforeach; ?></table>
    </section>
  <?php elseif ($page === 'customers'): ?>
    <section class="panel"><h1>Clientes e unidades consumidoras</h1><p>Cadastre o nome que deve aparecer na fatura. A identificação é feita pelo número da UC lido no PDF Cemig.</p>
      <?php if ($error): ?><div class="alert"><?= escape($error) ?></div><?php endif; ?>
      <?php if (isset($_GET['saved'])): ?><div class="warning">Cadastro salvo. Ao importar uma conta dessa UC, este nome será usado automaticamente.</div><?php endif; ?>
      <form method="post" class="customer-form"><input type="hidden" name="action" value="save_customer"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="resume" value="<?= escape($_GET['resume'] ?? '') ?>"><div class="grid two"><label><span>Nome exibido na fatura</span><input name="display_name" value="<?= escape($_GET['name'] ?? '') ?>" placeholder="Ex.: Farney" required></label><label><span>Número da unidade consumidora</span><input name="installation_number" value="<?= escape($_GET['uc'] ?? '') ?>" placeholder="Ex.: 6.720.115.018-01" required></label><label><span>Endereço da instalação</span><input name="address" value="<?= escape($_GET['address'] ?? '') ?>" placeholder="Ex.: Floresta, Belo Horizonte"></label><label><span>Telefone</span><input name="phone"></label><label><span>E-mail</span><input name="email" type="email"></label><label><span>Desconto padrão sobre kWh (%)</span><input name="discount_percent" type="number" value="20" min="0" max="100" step="0.01"></label></div><p class="actions"><button class="btn">Salvar cliente</button></p></form>
      <h2>Cadastros atuais</h2><table class="table"><tr><td><strong>Nome exibido</strong></td><td><strong>UC</strong></td><td><strong>Desconto</strong></td><td></td></tr><?php foreach ($customers->all() as $customer): ?><tr><td><?= escape($customer['display_name']) ?></td><td><?= escape($customer['installation_number']) ?></td><td><?= escape($customer['discount_percent']) ?>%</td><td><a class="btn light" href="./?page=customer&id=<?= (int) $customer['id'] ?>">Ver perfil</a></td></tr><?php endforeach; ?></table>
    </section>
  <?php elseif ($page === 'upload'): ?>
    <section class="panel dashboard"><h1>Visão geral</h1><p class="tag">Acompanhe cobranças e recebimentos dos últimos 12 meses.</p><div class="summary"><div class="card"><small>Total recebido</small><strong><?= money($dashboard['received']) ?></strong><small><?= $dashboard['paid_count'] ?> cobrança(s) quitada(s)</small></div><div class="card"><small>Em aberto</small><strong><?= money($dashboard['pending']) ?></strong><small><?= $dashboard['pending_count'] ?> cobrança(s) pendente(s)</small></div><div class="card"><small>Em atraso</small><strong><?= money($dashboard['overdue']) ?></strong><small>Vencidas e ainda pendentes</small></div><div class="card"><small>Faturas registradas</small><strong><?= $dashboard['paid_count'] + $dashboard['pending_count'] ?></strong><small>Base para a gestão financeira</small></div></div><h2>Recebimentos e pendências por mês</h2><canvas id="dashboardChart" class="chart"></canvas><p class="dashboard-note"><span style="color:#19a974">■</span> Recebido &nbsp; <span style="color:#f7b955">■</span> Pendente</p></section>
    <script>const dashboardMonths=<?= json_encode($dashboard['months'], JSON_UNESCAPED_UNICODE) ?>,dashboardCanvas=document.getElementById('dashboardChart');if(dashboardCanvas){const d=dashboardCanvas.getContext('2d'),w=dashboardCanvas.clientWidth,h=dashboardCanvas.clientHeight;dashboardCanvas.width=w*devicePixelRatio;dashboardCanvas.height=h*devicePixelRatio;d.scale(devicePixelRatio,devicePixelRatio);const p={l:52,r:14,t:18,b:42},max=Math.max(...dashboardMonths.map(v=>Number(v.received)+Number(v.pending)),1),plot=h-p.t-p.b,band=(w-p.l-p.r)/dashboardMonths.length,bw=Math.min(30,band*.62),fmt=v=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL',maximumFractionDigits:0}).format(v);d.strokeStyle='#dce8e3';d.font='11px Arial';[0,.5,1].forEach(r=>{const y=p.t+plot*(1-r);d.beginPath();d.moveTo(p.l,y);d.lineTo(w-p.r,y);d.stroke();d.fillStyle='#668078';d.textAlign='right';d.fillText(fmt(max*r),p.l-7,y+4)});dashboardMonths.forEach((v,i)=>{const x=p.l+i*band+(band-bw)/2,base=h-p.b,received=plot*Number(v.received)/max,pending=plot*Number(v.pending)/max;d.fillStyle='#19a974';d.fillRect(x,base-received,bw,received);d.fillStyle='#f7b955';d.fillRect(x,base-received-pending,bw,pending);d.fillStyle='#668078';d.textAlign='center';d.fillText(v.label,x+bw/2,base+18)});}</script>
    <section class="panel"><h1>Gerar nova fatura</h1><p>Envie o PDF original da Cemig. Os dados serão extraídos e você poderá conferi-los antes de gerar.</p>
      <?php if ($error): ?><div class="alert"><?= escape($error) ?></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="upload"><input type="hidden" name="action" value="upload"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><strong>Selecione uma fatura da Cemig</strong><input type="file" name="utility_bill" accept="application/pdf" required><button class="btn">Ler PDF e continuar</button><p class="tag">PDF de até 12 MB. O arquivo não fica acessível publicamente.</p></form>
    </section>
  <?php elseif ($page === 'review' && $data): ?>
    <section class="panel"><h1>Conferir leitura</h1><p>Revise os campos destacados. O desconto é aplicado somente sobre a energia compensada.</p>
      <?php foreach ($data['warnings'] as $warning): ?><div class="warning"><?= escape($warning) ?></div><?php endforeach; ?>
      <?php if (!$data['customer_found']): ?><p><a class="btn light" href="./?page=customers&resume=1&uc=<?= urlencode((string) $data['installation_number']) ?>&name=<?= urlencode((string) $data['customer_name']) ?>&address=<?= urlencode((string) ($data['utility_address'] ?? '')) ?>">Cadastrar nova UC com os dados encontrados</a></p><?php endif; ?>
      <form method="post"><input type="hidden" name="action" value="generate"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="source_file" value="<?= escape($data['source_file']) ?>"><input type="hidden" name="customer_id" value="<?= escape($data['customer_id']) ?>"><input type="hidden" name="consumption_history" value='<?= escape(json_encode($data['consumption_history'], JSON_UNESCAPED_UNICODE)) ?>'><input type="hidden" name="utility_holder" value="<?= escape($data['utility_holder']) ?>">
        <div class="grid two"><label><span>Cliente exibido na fatura</span><input name="customer_name" value="<?= escape($data['customer_name']) ?>"></label><label><span>Endereço da instalação</span><input name="customer_address" value="<?= escape($data['customer_address']) ?>"></label><label><span>Unidade consumidora</span><input name="installation_number" value="<?= escape($data['installation_number']) ?>"></label><label><span>Mês de referência</span><input name="reference_month" value="<?= escape($data['reference_month']) ?>"></label><label><span>Vencimento</span><input name="due_date" value="<?= escape($data['due_date']) ?>"></label><label><span>Tipo de ligação</span><input name="connection_type" value="<?= escape($data['connection_type']) ?>"></label><label><span>Saldo de geração (kWh)</span><input name="generation_balance_kwh" value="<?= escape($data['generation_balance_kwh']) ?>"></label></div>
        <h2>Dados para cálculo</h2><div class="grid"><?= field('consumption_kwh',$data['consumption_kwh'],'Consumo total (kWh)') ?><?= field('compensated_kwh',$data['compensated_kwh'],'Energia compensada (kWh)') ?><?= field('full_energy_rate',$data['full_energy_rate'],'Tarifa cheia (R$/kWh)','0.00000001') ?><?= field('availability_amount',$data['availability_amount'],(string) $data['availability_label'].' (R$)','0.01') ?><?= field('public_lighting',$data['public_lighting'],'Iluminação pública (R$)','0.01') ?><?= field('discount_percent',$data['discount_percent'],'Desconto sobre kWh (%)','0.01') ?><?= field('bonus_amount',$data['bonus_amount'] ?? 0,'Bônus (R$)','0.01') ?><?= field('adjustment_amount',$data['adjustment_amount'] ?? 0,'Acerto anterior (R$)','0.01') ?></div>
        <p class="actions"><a class="btn light" href="./">Cancelar</a><button class="btn">Gerar fatura</button></p>
      </form>
    </section>
  <?php elseif ($page === 'invoice' && $data && $calculation): ?>
    <div class="actions invoice-actions"><a class="btn light" href="./">Nova fatura</a><?php if ($invoiceSaved): ?><span class="btn light">Cobrança salva no histórico</span><?php else: ?><form method="post"><input type="hidden" name="action" value="save_invoice"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><?php foreach (['customer_id','source_file','customer_name','customer_address','reference_month','due_date','installation_number','connection_type','generation_balance_kwh','consumption_kwh','compensated_kwh','full_energy_rate','availability_amount','availability_label','public_lighting','discount_percent','bonus_amount','adjustment_amount'] as $name): ?><input type="hidden" name="<?= escape($name) ?>" value="<?= escape($data[$name] ?? '') ?>"><?php endforeach; ?><button class="btn">Salvar no histórico</button></form><?php endif; ?><button class="btn" onclick="window.print()">Imprimir / Salvar PDF</button><?php if ($emailHref): ?><a class="btn light" href="<?= escape($emailHref) ?>">Preparar e-mail (anexar PDF)</a><?php endif; ?><?php if ($whatsAppHref): ?><a class="btn light" target="_blank" rel="noopener" href="<?= escape($whatsAppHref) ?>">Abrir WhatsApp</a><?php endif; ?></div>
    <article class="invoice"><header class="invoice-top"><div class="invoice-branding"><div class="system-mark" aria-hidden="true">☀</div><div><div class="brand">SolarFatura</div><p>Fatura inteligente de energia compensada<br><small>Versão <?= escape($appVersion) ?> (software livre)</small></p></div></div><div class="invoice-meta"><small>Referência</small><strong><?= escape($data['reference_month'] ?? '') ?></strong><small>Vencimento</small><strong><?= escape($data['due_date'] ?? '') ?></strong></div></header>
      <section class="party-grid"><section class="party-card"><h3>Gestora / fornecedora</h3><div class="manager-identity"><?php if (!empty($company['logo_path'])): ?><img class="manager-logo" src="?asset=company-logo" alt="Logo da <?= escape($company['trade_name']) ?>"><?php endif; ?><strong><?= escape($company['trade_name']) ?></strong></div><?php if (!empty($company['legal_name'])): ?><p><?= escape($company['legal_name']) ?></p><?php endif; ?><?php if (!empty($company['cnpj'])): ?><p>CNPJ: <?= escape($company['cnpj']) ?></p><?php endif; ?><?php if (!empty($company['address'])): ?><p><?= escape($company['address']) ?></p><?php endif; ?><?php if (!empty($company['phone']) || !empty($company['email'])): ?><p><?= escape($company['phone']) ?><?= !empty($company['phone']) && !empty($company['email']) ? ' · ' : '' ?><?= escape($company['email']) ?></p><?php endif; ?></section><section class="party-card"><h3>Cliente / unidade consumidora</h3><strong><?= escape($data['customer_name'] ?? '') ?></strong><?php if (!empty($data['customer_address'])): ?><p><?= escape($data['customer_address']) ?></p><?php endif; ?><p><b>UC:</b> <?= escape($data['installation_number'] ?? '') ?></p><?php if (!empty($data['connection_type'])): ?><p><?= escape($data['connection_type']) ?></p><?php endif; ?><p class="credit"><b>Saldo de geração:</b> <?= escape($data['generation_balance_kwh'] ?? '0') ?> kWh<br><small>Créditos informados pela Cemig na fatura de origem.</small></p></section></section>
      <div class="summary"><div class="card"><small>Economia nesta fatura</small><strong><?= money($calculation['savings']) ?></strong></div><div class="card"><small>Economia percentual</small><strong><?= number_format($calculation['savings_percent'],2,',','.') ?>%</strong></div><div class="card"><small>Energia compensada</small><strong><?= escape($data['compensated_kwh']) ?> kWh</strong></div><div class="card"><small>Consumo do mês</small><strong><?= escape($data['consumption_kwh']) ?> kWh</strong></div></div>
      <h2>Memória de cálculo</h2><table class="table"><tr><td>Energia compensada com desconto de <?= escape($data['discount_percent']) ?>%</td><td><?= money($calculation['solar_energy']) ?></td></tr><tr><td><?= escape($data['availability_label'] ?? 'Disponibilidade') ?></td><td><?= money($data['availability_amount']) ?></td></tr><tr><td>Iluminação pública</td><td><?= money($data['public_lighting']) ?></td></tr><tr><td>Acerto anterior</td><td><?= money($data['adjustment_amount']) ?></td></tr><tr><td>Bônus</td><td>- <?= money($data['bonus_amount']) ?></td></tr></table><div class="total"><span>Total a pagar</span><span><?= money($calculation['amount_due']) ?></span></div><p><strong>Simulação sem energia fotovoltaica:</strong> <?= money($calculation['without_solar']) ?> · <strong>Economia:</strong> <?= money($calculation['savings']) ?></p>
      <h2>Histórico financeiro da unidade</h2><p class="tag">A cada fatura gerada, uma nova barra é adicionada. Azul é o valor cobrado; verde é a economia obtida. Juntos, representam a simulação sem energia fotovoltaica.</p><canvas id="financialChart" class="chart"></canvas><div class="summary"><div class="card"><small>Valor cobrado nesta fatura</small><strong><?= money($calculation['amount_due']) ?></strong></div><div class="card"><small>Economia nesta fatura</small><strong><?= money($calculation['savings']) ?></strong></div><div class="card"><small>Simulação sem fotovoltaica</small><strong><?= money($calculation['without_solar']) ?></strong></div></div>
    </article>
    <script>const history=<?= json_encode($invoiceChart, JSON_UNESCAPED_UNICODE) ?>;const c=document.getElementById('financialChart'),x=c.getContext('2d'),money=v=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(v);c.width=c.clientWidth*devicePixelRatio;c.height=c.clientHeight*devicePixelRatio;x.scale(devicePixelRatio,devicePixelRatio);const w=c.clientWidth,h=c.clientHeight,p={l:58,r:16,t:32,b:54},max=Math.max(...history.map(v=>Number(v.amount_due)+Number(v.savings_amount)),1),plotH=h-p.t-p.b,bw=Math.min(72,(w-p.l-p.r)/Math.max(history.length,1)*.62);x.strokeStyle='#dce8e3';x.lineWidth=1;[0,.25,.5,.75,1].forEach(r=>{const y=p.t+plotH*(1-r);x.beginPath();x.moveTo(p.l,y);x.lineTo(w-p.r,y);x.stroke();x.fillStyle='#668078';x.font='11px Arial';x.textAlign='right';x.fillText(money(max*r),p.l-8,y+4)});history.forEach((v,i)=>{const due=Number(v.amount_due),save=Number(v.savings_amount),total=due+save,cx=p.l+(i+.5)*(w-p.l-p.r)/history.length,blueH=plotH*due/max,greenH=plotH*save/max,base=h-p.b;x.fillStyle='#2878c8';x.fillRect(cx-bw/2,base-blueH,bw,blueH);x.fillStyle='#19a974';x.fillRect(cx-bw/2,base-blueH-greenH,bw,greenH);x.fillStyle='#17342d';x.font='bold 11px Arial';x.textAlign='center';x.fillText(money(total),cx,Math.max(14,base-blueH-greenH-8));x.fillStyle='#fff';x.font='bold 10px Arial';if(blueH>24)x.fillText(money(due),cx,base-blueH/2+4);if(greenH>24)x.fillText(money(save),cx,base-blueH-greenH/2+4);x.fillStyle='#668078';x.font='11px Arial';x.fillText(v.reference_month,cx,base+20)});x.textAlign='left';x.fillStyle='#2878c8';x.fillRect(p.l,8,12,12);x.fillStyle='#17342d';x.font='12px Arial';x.fillText('Valor cobrado',p.l+18,18);x.fillStyle='#19a974';x.fillRect(p.l+132,8,12,12);x.fillStyle='#17342d';x.fillText('Economia',p.l+150,18);</script><?php if ($autoPrint): ?><script>window.addEventListener('load',()=>window.print());</script><?php endif; ?>
  <?php endif; ?>
</main></body></html>
