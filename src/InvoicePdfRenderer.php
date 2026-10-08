<?php

declare(strict_types=1);

namespace SolarFatura;

/** Creates a self-contained, printable customer invoice PDF without an external service. */
final class InvoicePdfRenderer
{
    /** @param array<string, mixed> $data @param array<string, float> $calculation @param array<string, mixed> $company */
    public function render(array $data, array $calculation, array $company): string
    {
        $lines = [
            ['SolarFatura', 22, true, [0.10, 0.48, 0.33]],
            ['Fatura de energia compensada', 11, false, [0.25, 0.38, 0.34]],
            ['', 8, false, [0, 0, 0]],
            ['FORNECEDORA', 9, true, [0.10, 0.48, 0.33]],
            [(string) ($company['trade_name'] ?? 'SolarFatura'), 12, true, [0.09, 0.20, 0.18]],
            ['', 8, false, [0, 0, 0]],
            ['CLIENTE / UNIDADE CONSUMIDORA', 9, true, [0.10, 0.48, 0.33]],
            [(string) ($data['customer_name'] ?? ''), 12, true, [0.09, 0.20, 0.18]],
            [(string) ($data['customer_address'] ?? ''), 10, false, [0.09, 0.20, 0.18]],
            ['UC: ' . (string) ($data['installation_number'] ?? '') . '  |  Referência: ' . (string) ($data['reference_month'] ?? ''), 10, false, [0.09, 0.20, 0.18]],
            ['Vencimento: ' . (string) ($data['due_date'] ?? '') . '  |  Consumo: ' . (string) ($data['consumption_kwh'] ?? '0') . ' kWh', 10, false, [0.09, 0.20, 0.18]],
            ['', 8, false, [0, 0, 0]],
            ['RESUMO', 9, true, [0.10, 0.48, 0.33]],
            ['Energia compensada: ' . (string) ($data['compensated_kwh'] ?? '0') . ' kWh', 10, false, [0.09, 0.20, 0.18]],
            ['Economia nesta fatura: ' . $this->money($calculation['savings'] ?? 0) . ' (' . $this->number($calculation['savings_percent'] ?? 0) . '%)', 10, false, [0.09, 0.20, 0.18]],
            ['', 8, false, [0, 0, 0]],
            ['MEMÓRIA DE CÁLCULO', 9, true, [0.10, 0.48, 0.33]],
            ['Energia compensada com desconto de ' . $this->number((float) ($data['discount_percent'] ?? 0)) . '%: ' . $this->money($calculation['solar_energy'] ?? 0), 10, false, [0.09, 0.20, 0.18]],
            [(string) ($data['availability_label'] ?? 'Disponibilidade') . ': ' . $this->money((float) ($data['availability_amount'] ?? 0)), 10, false, [0.09, 0.20, 0.18]],
            ['Iluminação pública: ' . $this->money((float) ($data['public_lighting'] ?? 0)), 10, false, [0.09, 0.20, 0.18]],
            ['Acerto anterior: ' . $this->money((float) ($data['adjustment_amount'] ?? 0)), 10, false, [0.09, 0.20, 0.18]],
            ['Bônus: - ' . $this->money((float) ($data['bonus_amount'] ?? 0)), 10, false, [0.09, 0.20, 0.18]],
            ['', 8, false, [0, 0, 0]],
            ['TOTAL A PAGAR: ' . $this->money($calculation['amount_due'] ?? 0), 16, true, [0.03, 0.35, 0.21]],
            ['Simulação sem energia fotovoltaica: ' . $this->money($calculation['without_solar'] ?? 0), 10, false, [0.09, 0.20, 0.18]],
        ];
        foreach (array_reverse($this->companyDetails($company)) as $detail) {
            array_splice($lines, 5, 0, [[$detail, 10, false, [0.09, 0.20, 0.18]]]);
        }
        $pixPayload = PixPayload::build(
            (string) ($company['pix_key'] ?? ''),
            (float) ($calculation['amount_due'] ?? 0),
            (string) ($company['trade_name'] ?? ''),
            (string) ($company['pix_city'] ?? '')
        );
        if ($pixPayload !== null) {
            array_splice($lines, -1, 0, [
                ['PAGUE COM PIX (copia e cola)', 9, true, [0.10, 0.48, 0.33]],
                [$pixPayload, 8, false, [0.09, 0.20, 0.18]],
            ]);
        }

        $commands = ["0.10 0.66 0.45 rg", "36 800 523 4 re f"];
        $y = 770;
        foreach ($lines as [$text, $size, $bold, $color]) {
            foreach ($this->wrap((string) $text, 86) as $part) {
                if ($y < 56) {
                    break;
                }
                $font = $bold ? '/F2' : '/F1';
                $commands[] = sprintf('%.2F %.2F %.2F rg BT %s %.1F Tf 42 %.1F Td (%s) Tj ET', $color[0], $color[1], $color[2], $font, $size, $y, $this->escapeText($part));
                $y -= $size + 5;
            }
        }
        $commands[] = '0.40 0.50 0.47 rg BT /F1 8 Tf 42 34 Td (Documento gerado pelo SolarFatura em ' . $this->escapeText(date('d/m/Y H:i')) . ') Tj ET';

        $content = implode("\n", $commands) . "\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
            '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($index = 1; $index <= count($objects); $index++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$index]) . "\n";
        }
        return $pdf . 'trailer' . "\n<< /Size " . (count($objects) + 1) . ' /Root 1 0 R >>' . "\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    /** @param array<string, mixed> $company */
    private function companyDetails(array $company): array
    {
        return array_values(array_filter([
            (string) ($company['legal_name'] ?? ''),
            !empty($company['cnpj']) ? 'CNPJ: ' . $company['cnpj'] : '',
            (string) ($company['address'] ?? ''),
            trim((string) ($company['phone'] ?? '') . (!empty($company['phone']) && !empty($company['email']) ? ' | ' : '') . (string) ($company['email'] ?? '')),
        ]));
    }

    /** @return array<int, string> */
    private function wrap(string $text, int $width): array
    {
        $text = trim($text);
        if ($text === '') {
            return [''];
        }
        return explode("\n", wordwrap($text, $width, "\n", true));
    }

    private function escapeText(string $value): string
    {
        $value = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value) ?: '';
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $value);
    }

    private function money(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    private function number(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
