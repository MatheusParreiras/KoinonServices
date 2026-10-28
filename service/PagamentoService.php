<?php

/*
 * ==========================================================
 * PAGAMENTOS (SIMULAÇÃO PARA O TCC)
 * ==========================================================
 *
 * Não existe integração com banco ou operadora de cartão: o
 * "banco" é simulado pela tela morador/pagamento.php. Mesmo assim,
 * o fluxo é o de um pagamento real:
 *
 *   cobrança Pendente -> pagamento aprovado -> cobrança Pago
 *     -> comprovante emitido
 *     -> reserva vinculada passa de "Aguardando pagamento" para
 *        "Confirmada" automaticamente
 *     -> morador e gestão recebem a notificação ("push")
 *
 * Nenhuma tabela nova foi criada: a forma de pagamento e o código
 * da transação ficam em dois atributos novos da tabela cobranca.
 * Dados de cartão NUNCA são gravados.
 */

require_once __DIR__ . "/../model/dao/Conexao.php";
require_once __DIR__ . "/NotificacaoService.php";

class PagamentoService
{
    public const FORMAS = [
        "Pix"            => "Pix",
        "Cartao_Credito" => "Cartão de crédito",
        "Cartao_Debito"  => "Cartão de débito",
        "Boleto"         => "Boleto bancário",
        "Manual"         => "Registro manual (gestão)",
    ];

    public static function rotuloForma(?string $forma): string
    {
        return self::FORMAS[$forma ?? ""] ?? "—";
    }

    public static function reservaIdDaDescricao(string $descricao): ?int
    {
        return preg_match('/^Reserva #(\d+)\b/', $descricao, $m) ? (int) $m[1] : null;
    }

    /* ------------------------------------------------------------
     * APÓS O PAGAMENTO
     * ------------------------------------------------------------ */
    public static function aposPagamento(PDO $pdo, int $cobrancaId, ?string $forma = null, ?string $transacao = null): array
    {
        $resultado = ["reserva_confirmada" => false, "reserva_id" => null];

        try {
            $forma = isset(self::FORMAS[$forma ?? ""]) ? $forma : "Manual";

            $up = $pdo->prepare(
                "UPDATE cobranca SET forma_pagamento = :f, codigo_transacao = :t WHERE id = :id"
            );
            $up->execute([":f" => $forma, ":t" => $transacao, ":id" => $cobrancaId]);

            $busca = $pdo->prepare("SELECT * FROM cobranca WHERE id = :id LIMIT 1");
            $busca->execute([":id" => $cobrancaId]);
            $c = $busca->fetch(PDO::FETCH_ASSOC);

            if (!$c) {
                return $resultado;
            }

            $reservaId = ($c["tipo_cobranca"] ?? "") === "Reserva"
                ? self::reservaIdDaDescricao((string) $c["descricao"])
                : null;

            if ($reservaId !== null) {
                $conf = $pdo->prepare(
                    "UPDATE reserva SET status = 'Confirmada'
                     WHERE id = :id AND status IN ('Aguardando_Pagamento', 'Pendente')"
                );
                $conf->execute([":id" => $reservaId]);
                $resultado["reserva_confirmada"] = $conf->rowCount() > 0;
                $resultado["reserva_id"] = $reservaId;
            }

            $valor = "R$ " . number_format((float) $c["valor"], 2, ",", ".");
            $rotulo = self::rotuloForma($forma);

            $svc = new NotificacaoService();

            $textoMorador = "Pagamento de " . $valor . " (" . $c["descricao"] . ") aprovado via " . $rotulo . ".";
            if ($resultado["reserva_confirmada"]) {
                $textoMorador .= " Sua reserva foi confirmada automaticamente.";
            }

            $svc->notificarMoradoresDaUnidade(
                (int) $c["unidade_id"],
                "Pagamento",
                "✅ Pagamento aprovado",
                $textoMorador,
                $reservaId !== null ? "nova_reserva.php?id=" . $reservaId : "financeiro.php"
            );

            $uni = $pdo->prepare("SELECT identificacao FROM unidade WHERE id = :id");
            $uni->execute([":id" => (int) $c["unidade_id"]]);
            $unidade = (string) ($uni->fetchColumn() ?: "Unidade");

            $textoGestao = $unidade . " pagou " . $valor . " (" . $c["descricao"] . ") via " . $rotulo . ".";
            if ($resultado["reserva_confirmada"]) {
                $textoGestao .= " A reserva #" . $reservaId . " foi confirmada automaticamente.";
            }

            $svc->notificarGestao(
                "Pagamento",
                "💰 Pagamento recebido",
                $textoGestao,
                "financeiro/index.php"
            );
        } catch (Throwable $e) {
            // O pagamento já foi registrado; falha em aviso não o desfaz.
        }

        return $resultado;
    }

    /* ------------------------------------------------------------
     * BOLETO (linha digitável e código de barras simulados)
     * ------------------------------------------------------------ */
    private static function mod10(string $n): int
    {
        $soma = 0;
        $peso = 2;
        for ($i = strlen($n) - 1; $i >= 0; $i--) {
            $p = (int) $n[$i] * $peso;
            $soma += $p > 9 ? intdiv($p, 10) + ($p % 10) : $p;
            $peso = $peso === 2 ? 1 : 2;
        }
        return (10 - ($soma % 10)) % 10;
    }

    private static function mod11(string $n): int
    {
        $soma = 0;
        $peso = 2;
        for ($i = strlen($n) - 1; $i >= 0; $i--) {
            $soma += (int) $n[$i] * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }
        $dv = 11 - ($soma % 11);
        return ($dv === 0 || $dv === 10 || $dv === 11) ? 1 : $dv;
    }

    /** @return array{linha:string, barras:string} */
    public static function calcularBoleto(array $c): array
    {
        $fator = (int) floor((strtotime((string) $c["vencimento"]) - strtotime("1997-10-07")) / 86400) % 10000;
        $valor = str_pad((string) (int) round(((float) $c["valor"]) * 100), 10, "0", STR_PAD_LEFT);
        $livre = str_pad((string) (int) $c["unidade_id"], 5, "0", STR_PAD_LEFT)
            . str_pad((string) (int) $c["id"], 8, "0", STR_PAD_LEFT)
            . date("ym", strtotime((string) $c["vencimento"]))
            . "0000000";
        $livre = substr(str_pad($livre, 25, "0"), 0, 25);

        $semDv = "9999" . str_pad((string) $fator, 4, "0", STR_PAD_LEFT) . $valor . $livre;
        $dv = self::mod11($semDv);
        $barras = substr($semDv, 0, 4) . $dv . substr($semDv, 4);

        $c1 = substr($barras, 0, 4) . substr($barras, 19, 1) . substr($barras, 20, 4);
        $c2 = substr($barras, 24, 10);
        $c3 = substr($barras, 34, 10);

        $f1 = substr($c1, 0, 5) . "." . substr($c1, 5) . self::mod10($c1);
        $f2 = substr($c2, 0, 5) . "." . substr($c2, 5) . self::mod10($c2);
        $f3 = substr($c3, 0, 5) . "." . substr($c3, 5) . self::mod10($c3);

        $linha = $f1 . " " . $f2 . " " . $f3 . " " . $dv . " " . substr($barras, 5, 14);

        return ["linha" => $linha, "barras" => $barras];
    }

    /** Garante linha digitável e link do boleto (cobranças antigas não tinham). */
    public static function garantirBoleto(PDO $pdo, array $c): array
    {
        $b = self::calcularBoleto($c);

        if (empty($c["linha_digitavel"]) || empty($c["link_boleto"])) {
            try {
                $up = $pdo->prepare(
                    "UPDATE cobranca SET linha_digitavel = :l, link_boleto = :k WHERE id = :id"
                );
                $up->execute([
                    ":l"  => $b["linha"],
                    ":k"  => "pagamento.php?id=" . (int) $c["id"],
                    ":id" => (int) $c["id"],
                ]);
            } catch (Throwable $e) {
            }
        }

        return $b;
    }

    /** Código de barras ITF-25 (padrão do boleto) em SVG. */
    public static function barrasSvg(string $digitos, int $altura = 56): string
    {
        $pad = ["00110", "10001", "01001", "11000", "00101", "10100", "01100", "00011", "10010", "01010"];

        $seq = [1, 1, 1, 1];                      // início
        foreach (str_split($digitos, 2) as $par) {
            $a = $pad[(int) $par[0]];             // dígito 1 -> barras
            $b = $pad[(int) $par[1]];             // dígito 2 -> espaços
            for ($i = 0; $i < 5; $i++) {
                $seq[] = $a[$i] === "1" ? 3 : 1;
                $seq[] = $b[$i] === "1" ? 3 : 1;
            }
        }
        $seq = array_merge($seq, [3, 1, 1]);      // fim

        $x = 0;
        $rects = "";
        foreach ($seq as $k => $w) {
            if ($k % 2 === 0) {
                $rects .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $altura . '"/>';
            }
            $x += $w;
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $x . ' ' . $altura
            . '" preserveAspectRatio="none" style="width:100%;height:' . $altura . 'px" fill="#111">'
            . $rects . '</svg>';
    }

    /* ------------------------------------------------------------
     * PIX (copia e cola simulado, no formato EMV com CRC16)
     * ------------------------------------------------------------ */
    private static function crc16(string $s): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $crc ^= ord($s[$i]) << 8;
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, "0", STR_PAD_LEFT));
    }

    private static function tlv(string $id, string $valor): string
    {
        return $id . str_pad((string) strlen($valor), 2, "0", STR_PAD_LEFT) . $valor;
    }

    public static function pixCopiaECola(array $c): string
    {
        $conta = self::tlv("00", "BR.GOV.BCB.PIX") . self::tlv("01", "pix@koinonservice.simulado");
        $txid = "KS" . str_pad((string) (int) $c["id"], 8, "0", STR_PAD_LEFT);
        $payload = self::tlv("00", "01")
            . self::tlv("26", $conta)
            . self::tlv("52", "0000")
            . self::tlv("53", "986")
            . self::tlv("54", number_format((float) $c["valor"], 2, ".", ""))
            . self::tlv("58", "BR")
            . self::tlv("59", "KOINON SERVICE")
            . self::tlv("60", "BRASILIA")
            . self::tlv("62", self::tlv("05", $txid))
            . "6304";

        return $payload . self::crc16($payload);
    }

    /* ------------------------------------------------------------
     * CARTÃO (validação apenas; nada é gravado)
     * ------------------------------------------------------------ */
    public static function numeroCartaoValido(string $numero): bool
    {
        $n = preg_replace('/\D/', '', $numero);
        if (strlen($n) < 13 || strlen($n) > 19) {
            return false;
        }
        $soma = 0;
        $alt = false;
        for ($i = strlen($n) - 1; $i >= 0; $i--) {
            $d = (int) $n[$i];
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $soma += $d;
            $alt = !$alt;
        }
        return $soma % 10 === 0;
    }

    public static function validadeCartaoValida(string $mmaa): bool
    {
        if (!preg_match('/^(\d{2})\s*\/\s*(\d{2})$/', trim($mmaa), $m)) {
            return false;
        }
        $mes = (int) $m[1];
        $ano = 2000 + (int) $m[2];
        if ($mes < 1 || $mes > 12) {
            return false;
        }
        return mktime(23, 59, 59, $mes + 1, 0, $ano) >= time();
    }

    public static function novaTransacao(int $cobrancaId): string
    {
        return "KS" . date("ymdHis") . str_pad((string) $cobrancaId, 4, "0", STR_PAD_LEFT)
            . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
    }
}
