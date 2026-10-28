<?php

class ComprovantePdfService
{
    public static function output(array $comprovante, bool $download = true): void
    {
        $dados = $comprovante['dados'] ?? [];
        $tipo = ($comprovante['tipo'] ?? '') === 'Retirada_Encomenda'
            ? 'COMPROVANTE DE RETIRADA DE ENCOMENDA'
            : 'COMPROVANTE DE PAGAMENTO';

        $lines = [
            'KOINON SERVICES',
            'Sistema de Gestao de Condominios',
            '',
            $tipo,
            'Numero: ' . ($comprovante['numero_comprovante'] ?? '—'),
            'Emitido em: ' . self::brDate($comprovante['data_emissao'] ?? null),
            '',
        ];

        if (($comprovante['tipo'] ?? '') === 'Retirada_Encomenda') {
            $lines[] = 'Unidade: ' . ($dados['unidade_identificacao'] ?? '—');
            $lines[] = 'Morador: ' . ($dados['usuario_nome'] ?? 'Destinatario da unidade');
            $lines[] = 'Descricao: ' . ($dados['descricao'] ?? '—');
            $lines[] = 'Rastreio: ' . ($dados['codigo_rastreio'] ?? 'Nao informado');
            $lines[] = 'Recebida em: ' . self::brDate($dados['data_recebimento'] ?? null);
            $lines[] = 'Retirada em: ' . self::brDate($dados['data_retirada'] ?? null);
            $lines[] = '';
        } else {
            $lines[] = 'Unidade: ' . ($dados['unidade_identificacao'] ?? '—');
            $lines[] = 'Morador: ' . ($dados['usuario_nome'] ?? 'Morador da unidade');
            $lines[] = 'Descricao: ' . ($dados['descricao'] ?? '—');
            $lines[] = 'Valor: R$ ' . number_format((float)($dados['valor'] ?? 0), 2, ',', '.');
            $lines[] = 'Vencimento: ' . self::brDateOnly($dados['vencimento'] ?? null);
            $lines[] = 'Pagamento registrado em: ' . self::brDate($dados['data_pagamento'] ?? null);
            $lines[] = '';
        }

        $pdf = self::buildPdf($lines);
        $filename = 'comprovante-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $comprovante['numero_comprovante'] ?? 'koinon') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        echo $pdf;
        exit;
    }

    private static function brDate(?string $value): string
    {
        if (!$value) return '—';
        $ts = strtotime($value);
        return $ts ? date('d/m/Y H:i:s', $ts) : $value;
    }

    private static function brDateOnly(?string $value): string
    {
        if (!$value) return '—';
        $ts = strtotime($value);
        return $ts ? date('d/m/Y', $ts) : $value;
    }

    private static function encode(string $text): string
    {
        $text = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private static function buildPdf(array $lines): string
    {
        // PDF A4 desenhado diretamente em PDF 1.4, sem Composer/bibliotecas externas.
        $tipo = $lines[3] ?? 'COMPROVANTE';
        $numero = $lines[4] ?? '';
        $emitido = $lines[5] ?? '';
        $body = array_slice($lines, 7);

        $esc = fn(string $t) => self::encode($t);
        $content = "q\n";
        // Fundo e cabeçalho
        $content .= "0.96 0.97 1 rg\n0 0 595 842 re f\n";
        $content .= "0.16 0.18 0.28 rg\n0 742 595 100 re f\n";
        $content .= "0.30 0.24 0.90 rg\n0 738 595 4 re f\n";

        $content .= "BT\n/F1 22 Tf\n40 798 Td\n( KOINON SERVICES ) Tj\nET\n";
        $content .= "BT\n/F1 9 Tf\n40 780 Td\n(Sistema de Gestao de Condominios) Tj\nET\n";

        $content .= "BT\n/F1 13 Tf\n40 706 Td\n(" . $esc($tipo) . ") Tj\nET\n";
        $content .= "0.95 0.95 0.98 rg\n40 635 515 52 re f\n";
        $content .= "0.80 0.82 0.88 RG\n40 635 515 52 re S\n";
        $content .= "0.12 0.14 0.22 rg\nBT\n/F1 10 Tf\n55 667 Td\n(" . $esc($numero) . ") Tj\nET\n";
        $content .= "BT\n/F1 9 Tf\n55 650 Td\n(" . $esc($emitido) . ") Tj\nET\n";

        $y = 606;
        $content .= "BT\n/F1 10 Tf\n40 {$y} Td\n(DETALHES DO REGISTRO) Tj\nET\n";
        $y -= 18;
        $row = 0;
        foreach ($body as $line) {
            if ($line === '') { $y -= 12; continue; }
            if ($row % 2 === 0) {
                $content .= "0.985 0.99 1 rg\n40 " . ($y-9) . " 515 22 re f\n";
            }
            $content .= "0.16 0.18 0.24 rg\nBT\n/F1 9 Tf\n52 {$y} Td\n(" . $esc($line) . ") Tj\nET\n";
            $y -= 22;
            $row++;
        }

        // Assinatura/validação visual e rodapé.
        $content .= "0.75 0.78 0.85 RG\n40 112 515 1 re f\n";
        $content .= "0.38 0.42 0.50 rg\nBT\n/F1 8 Tf\n40 92 Td\n(Documento emitido pelo Koinon Services. Conferencia pelo numero do comprovante.) Tj\nET\n";
        $content .= "BT\n/F1 8 Tf\n40 77 Td\n(Registro destinado a fins de controle interno e rastreabilidade condominial.) Tj\nET\n";
        $content .= "BT\n/F1 8 Tf\n40 42 Td\n(Koinon Services - Sistema de Gestao de Condominios - 2026) Tj\nET\n";
        $content .= "Q\n";

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>';
        $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $num = $i + 1;
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }
}
