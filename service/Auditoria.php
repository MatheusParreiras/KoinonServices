<?php

/*
 * ==========================================================
 * AUDITORIA — "quem fez o quê"
 * ==========================================================
 *
 * Registra, SEM criar tabela nova, as ações que alteram dados
 * (envio de formulários e links de ação como excluir, pagar,
 * mudar status). Cada ação vira uma linha JSON em
 * storage/auditoria/AAAA-MM.log.
 *
 * Privacidade (LGPD): são gravados apenas usuário, perfil, IP,
 * tela, tipo de ação e o número do registro. Valores digitados
 * (senhas, CPF, textos) NUNCA são gravados.
 */
class Auditoria
{
    private const MODULOS = [
        "usuario" => "Usuários", "unidade" => "Unidades", "financeiro" => "Financeiro",
        "reserva" => "Reservas", "local_reserva" => "Locais de reserva", "visitante" => "Visitantes",
        "autorizacao_acesso" => "Autorizações de acesso", "portaria" => "Portaria",
        "registro_acesso" => "Registro de acesso", "encomenda" => "Encomendas",
        "ocorrencia" => "Ocorrências", "veiculo" => "Veículos", "publicacao" => "Publicações",
        "interacao" => "Interações", "documento" => "Documentos", "morador" => "Área do morador",
        "assembleia" => "Assembleias", "relatorios" => "Relatórios", "auditoria" => "Auditoria",
    ];

    private const ACOES = [
        "excluir" => "Excluiu", "pagar" => "Registrou pagamento em", "reativar" => "Reativou",
        "aprovar" => "Aprovou", "rejeitar" => "Rejeitou", "cancelar" => "Cancelou",
        "entregar" => "Marcou como entregue em", "devolver" => "Marcou como devolvida em",
        "reabrir" => "Reabriu", "encerrar" => "Encerrou", "resolver" => "Resolveu",
        "cadastrar" => "Cadastrou em", "editar" => "Alterou", "atualizar_vencimentos" => "Atualizou vencimentos em",
    ];

    private const CAMPOS_ID = ["id", "excluir"];

    private static function pasta(): string
    {
        $pasta = __DIR__ . "/../storage/auditoria";

        if (!is_dir($pasta)) {
            @mkdir($pasta, 0775, true);
        }

        $protecao = $pasta . "/.htaccess";
        if (is_dir($pasta) && !file_exists($protecao)) {
            @file_put_contents($protecao, "Require all denied\nDeny from all\n");
        }

        return $pasta;
    }

    /** Chamado automaticamente por Csrf::proteger() em toda ação que altera dados. */
    public static function registrarRequisicao(): void
    {
        try {
            $usuarioId = $_SESSION["usuario_id"] ?? null;
            if (!$usuarioId) {
                return;
            }

            $script = str_replace("\\", "/", $_SERVER["SCRIPT_NAME"] ?? "");
            $arquivo = basename($script);

            // Ruído: marcar notificação como lida não é uma ação de negócio.
            if (strpos($arquivo, "notificacao_") === 0) {
                return;
            }

            $partes = explode("/", trim($script, "/"));
            $pos = array_search("view", $partes, true);
            $modulo = ($pos !== false && isset($partes[$pos + 1]) && count($partes) > $pos + 2)
                ? $partes[$pos + 1] : "";

            $metodo = $_SERVER["REQUEST_METHOD"] ?? "GET";
            $dados = $metodo === "POST" ? $_POST : $_GET;

            $acao = (string) ($dados["acao"] ?? "");
            if (isset($_GET["excluir"])) {
                $acao = "excluir";
            }
            if ($acao === "" && isset($_GET["status"]) && isset($_GET["id"])) {
                $acao = "status";
            }
            if ($acao === "") {
                $nome = pathinfo($arquivo, PATHINFO_FILENAME);
                $acao = in_array($nome, ["cadastrar", "editar"], true) ? $nome : "enviou";
            }

            $registro = self::idDoRegistro($dados);

            $verbo = self::ACOES[$acao] ?? null;
            if ($acao === "status") {
                $verbo = "Alterou o status em";
                if (!empty($_GET["status"])) {
                    $verbo .= " (" . substr(preg_replace("/[^\\p{L}\\p{N}_ -]/u", "", (string) $_GET["status"]), 0, 20) . ")";
                }
            }
            if ($verbo === null) {
                $verbo = "Executou ação em";
            }

            $descricao = $verbo . " " . (self::MODULOS[$modulo] ?? ($modulo !== "" ? $modulo : $arquivo));

            self::gravar([
                "data" => date("Y-m-d H:i:s"),
                "usuario_id" => (int) $usuarioId,
                "usuario" => (string) ($_SESSION["usuario_nome"] ?? ""),
                "perfil" => (string) ($_SESSION["usuario_perfil"] ?? ""),
                "ip" => (string) ($_SERVER["REMOTE_ADDR"] ?? ""),
                "tela" => $modulo !== "" ? $modulo . "/" . $arquivo : $arquivo,
                "acao" => $descricao,
                "registro" => $registro,
            ]);
        } catch (Throwable $e) {
            // A auditoria nunca pode impedir a operação do sistema.
        }
    }

    private static function idDoRegistro(array $dados): ?int
    {
        foreach (self::CAMPOS_ID as $campo) {
            if (isset($dados[$campo]) && is_numeric($dados[$campo])) {
                return (int) $dados[$campo];
            }
        }
        return null;
    }

    public static function gravar(array $linha): void
    {
        $arquivo = self::pasta() . "/" . date("Y-m") . ".log";
        @file_put_contents(
            $arquivo,
            json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Lê os registros mais recentes (dos últimos 3 meses), do mais novo ao mais antigo.
     * Filtros opcionais: usuario (texto), perfil, data_inicio, data_fim (Y-m-d).
     */
    public static function ler(int $limite = 300, array $filtros = []): array
    {
        $registros = [];
        $pasta = self::pasta();

        for ($i = 0; $i < 3; $i++) {
            $arquivo = $pasta . "/" . date("Y-m", strtotime("-$i month")) . ".log";
            if (!is_file($arquivo)) {
                continue;
            }
            foreach (array_reverse(file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $linha) {
                $r = json_decode($linha, true);
                if (!is_array($r)) {
                    continue;
                }
                if (!empty($filtros["perfil"]) && ($r["perfil"] ?? "") !== $filtros["perfil"]) {
                    continue;
                }
                if (!empty($filtros["usuario"]) && stripos($r["usuario"] ?? "", $filtros["usuario"]) === false) {
                    continue;
                }
                $dia = substr($r["data"] ?? "", 0, 10);
                if (!empty($filtros["data_inicio"]) && $dia < $filtros["data_inicio"]) {
                    continue;
                }
                if (!empty($filtros["data_fim"]) && $dia > $filtros["data_fim"]) {
                    continue;
                }
                $registros[] = $r;
                if (count($registros) >= $limite) {
                    break 2;
                }
            }
        }

        return $registros;
    }
}
