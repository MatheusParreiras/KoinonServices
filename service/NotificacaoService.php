<?php

require_once __DIR__ . "/../controller/NotificacaoController.php";
require_once __DIR__ . "/../model/dto/NotificacaoDTO.php";
require_once __DIR__ . "/../model/dao/Conexao.php";

class NotificacaoService
{
    private NotificacaoController $controller;
    private PDO $conexao;

    public function __construct()
    {
        $this->controller = new NotificacaoController();
        $this->conexao = Conexao::conectar();
    }

    public function criar(
        int $usuarioId,
        string $tipo,
        string $titulo,
        string $mensagem,
        ?string $link = null,
        ?int $publicacaoId = null
    ): bool {

        if (
            $usuarioId <= 0 ||
            trim($tipo) === "" ||
            trim($titulo) === "" ||
            trim($mensagem) === ""
        ) {
            return false;
        }

        $notificacao = new NotificacaoDTO();

        $notificacao->setUsuarioId($usuarioId);
        $notificacao->setPublicacaoId($publicacaoId);
        $notificacao->setTipo($tipo);
        $notificacao->setTitulo($titulo);
        $notificacao->setMensagem($mensagem);
        $notificacao->setLink($link);
        $notificacao->setLida(false);

        return $this->controller->cadastrar(
            $notificacao
        );
    }

    /*
     * ==========================================================
     * MORADORES DE UMA UNIDADE
     * ==========================================================
     */

    public function listarMoradoresPorUnidade(
        int $unidadeId
    ): array {

        if ($unidadeId <= 0) {
            return [];
        }

        $sql = "
            SELECT
                id
            FROM usuario
            WHERE unidade_id = :unidade_id
              AND perfil = 'Morador'
              AND ativo = 1
            ORDER BY id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":unidade_id",
            $unidadeId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return array_map(
            static fn(array $row): int => (int) $row["id"],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /*
     * ==========================================================
     * TODOS OS MORADORES
     * ==========================================================
     */

    public function listarTodosMoradores(
        ?int $ignorarUsuarioId = null
    ): array {

        $sql = "
            SELECT
                id
            FROM usuario
            WHERE perfil = 'Morador'
              AND ativo = 1
        ";

        if (
            $ignorarUsuarioId !== null &&
            $ignorarUsuarioId > 0
        ) {
            $sql .= "
                AND id <> :ignorar_usuario_id
            ";
        }

        $sql .= "
            ORDER BY id
        ";

        $stmt = $this->conexao->prepare($sql);

        if (
            $ignorarUsuarioId !== null &&
            $ignorarUsuarioId > 0
        ) {

            $stmt->bindValue(
                ":ignorar_usuario_id",
                $ignorarUsuarioId,
                PDO::PARAM_INT
            );
        }

        $stmt->execute();

        return array_map(
            static fn(array $row): int => (int) $row["id"],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /*
     * ==========================================================
     * NOTIFICAR MORADORES DA UNIDADE
     * ==========================================================
     */

    public function notificarMoradoresDaUnidade(
        int $unidadeId,
        string $tipo,
        string $titulo,
        string $mensagem,
        ?string $link = null
    ): int {

        $total = 0;

        foreach (
            $this->listarMoradoresPorUnidade($unidadeId)
            as $usuarioId
        ) {

            if (
                $this->criar(
                    $usuarioId,
                    $tipo,
                    $titulo,
                    $mensagem,
                    $link
                )
            ) {
                $total++;
            }
        }

        return $total;
    }

    /*
     * ==========================================================
     * NOTIFICAR TODOS OS MORADORES
     * ==========================================================
     */

    public function notificarTodosMoradores(
        string $tipo,
        string $titulo,
        string $mensagem,
        ?string $link = null,
        ?int $publicacaoId = null,
        ?int $ignorarUsuarioId = null
    ): int {

        $total = 0;

        foreach (
            $this->listarTodosMoradores(
                $ignorarUsuarioId
            ) as $usuarioId
        ) {

            if (
                $this->criar(
                    $usuarioId,
                    $tipo,
                    $titulo,
                    $mensagem,
                    $link,
                    $publicacaoId
                )
            ) {
                $total++;
            }
        }

        return $total;
    }

    /*
     * ==========================================================
     * LISTAR ADMINISTRADOR E SÍNDICO
     *
     * O PORTEIRO NÃO ENTRA AQUI.
     * ==========================================================
     */

    public function listarUsuariosGestao(
        ?int $ignorarUsuarioId = null
    ): array {

        $sql = "
            SELECT
                id
            FROM usuario
            WHERE ativo = 1
              AND perfil IN (
                  'Administrador',
                  'Sindico'
              )
        ";

        if (
            $ignorarUsuarioId !== null &&
            $ignorarUsuarioId > 0
        ) {
            $sql .= "
                AND id <> :ignorar_usuario_id
            ";
        }

        $sql .= "
            ORDER BY id
        ";

        $stmt = $this->conexao->prepare($sql);

        if (
            $ignorarUsuarioId !== null &&
            $ignorarUsuarioId > 0
        ) {

            $stmt->bindValue(
                ":ignorar_usuario_id",
                $ignorarUsuarioId,
                PDO::PARAM_INT
            );
        }

        $stmt->execute();

        return array_map(
            static fn(array $row): int => (int) $row["id"],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /*
     * ==========================================================
     * NOTIFICAR ADMINISTRADOR E SÍNDICO
     *
     * IMPORTANTE:
     * PORTEIRO NÃO RECEBE.
     * ==========================================================
     */

    public function notificarGestao(
        string $tipo,
        string $titulo,
        string $mensagem,
        ?string $link = null,
        ?int $ignorarUsuarioId = null
    ): int {

        $total = 0;

        foreach (
            $this->listarUsuariosGestao(
                $ignorarUsuarioId
            ) as $usuarioId
        ) {

            if (
                $this->criar(
                    $usuarioId,
                    $tipo,
                    $titulo,
                    $mensagem,
                    $link
                )
            ) {
                $total++;
            }
        }

        return $total;
    }

    /*
     * ==========================================================
     * PORTEIROS
     * ==========================================================
     */

    public function listarPorteiros(): array
    {
        $sql = "
            SELECT id
            FROM usuario
            WHERE ativo = 1
              AND perfil = 'Porteiro'
            ORDER BY id
        ";
        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();
        return array_map(
            static fn(array $row): int => (int) $row['id'],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function notificarPortaria(
        string $tipo,
        string $titulo,
        string $mensagem,
        ?string $link = null,
        ?int $ignorarUsuarioId = null
    ): int {
        $total = 0;
        foreach ($this->listarPorteiros() as $usuarioId) {
            if ($ignorarUsuarioId !== null && $usuarioId === $ignorarUsuarioId) continue;
            if ($this->criar($usuarioId, $tipo, $titulo, $mensagem, $link)) $total++;
        }
        return $total;
    }

    /*
     * ==========================================================
     * ENCOMENDA RECEBIDA
     * ==========================================================
     */

    public function encomenda(
        int $usuarioId,
        string $descricao,
        ?int $encomendaId = null
    ): bool {

        $link = "encomendas.php";

        if ($encomendaId !== null) {
            $link .= "?id=" . $encomendaId;
        }

        return $this->criar(
            $usuarioId,
            "Encomenda",
            "📦 Encomenda recebida",
            "Sua encomenda foi recebida pela portaria do condomínio."
                . (
                    $descricao !== ""
                        ? " Descrição: " . $descricao
                        : ""
                ),
            $link
        );
    }

    /*
     * ==========================================================
     * ENCOMENDA RETIRADA
     * ==========================================================
     */

    public function encomendaRetirada(
        int $usuarioId,
        string $descricao,
        ?int $encomendaId = null
    ): bool {

        $link = "encomendas.php";

        if ($encomendaId !== null) {
            $link .= "?id=" . $encomendaId;
        }

        return $this->criar(
            $usuarioId,
            "Encomenda",
            "📦 Encomenda retirada",
            "A encomenda"
                . (
                    $descricao !== ""
                        ? " (" . $descricao . ")"
                        : ""
                )
                . " foi marcada como retirada.",
            $link
        );
    }

    /*
     * ==========================================================
     * STATUS DA ENCOMENDA
     * ==========================================================
     */

    public function encomendaStatus(
        int $usuarioId,
        string $descricao,
        string $status,
        ?int $encomendaId = null
    ): bool {

        $link = "encomendas.php";

        if ($encomendaId !== null) {
            $link .= "?id=" . $encomendaId;
        }

        return $this->criar(
            $usuarioId,
            "Encomenda",
            "📦 Atualização da encomenda",
            "A encomenda"
                . (
                    $descricao !== ""
                        ? " (" . $descricao . ")"
                        : ""
                )
                . " está com status: "
                . $status
                . ".",
            $link
        );
    }

    /*
     * ==========================================================
     * VISITANTE
     * ==========================================================
     */

    public function visitanteEntrou(
        int $usuarioId,
        string $nomeVisitante
    ): bool {

        return $this->criar(
            $usuarioId,
            "Visitante",
            "🚪 Visitante entrou",
            "O visitante "
                . $nomeVisitante
                . " acaba de acessar o condomínio.",
            "autorizacoes.php"
        );
    }

    /*
     * ==========================================================
     * AVISO
     * ==========================================================
     */

    public function aviso(
        int $usuarioId,
        string $titulo,
        string $mensagem,
        ?int $publicacaoId = null
    ): bool {

        $link = "publicacoes.php";

        if ($publicacaoId !== null) {
            $link .= "?id=" . $publicacaoId;
        }

        return $this->criar(
            $usuarioId,
            "Aviso",
            "📢 " . $titulo,
            $mensagem,
            $link,
            $publicacaoId
        );
    }

    /*
     * ==========================================================
     * ENQUETE
     * ==========================================================
     */

    public function enquete(
        int $usuarioId,
        string $titulo,
        ?int $publicacaoId = null
    ): bool {

        $link = "enquetes.php";

        if ($publicacaoId !== null) {
            $link .= "?id=" . $publicacaoId;
        }

        return $this->criar(
            $usuarioId,
            "Enquete",
            "📊 Nova enquete",
            "Uma nova enquete está disponível para votação: "
                . $titulo,
            $link,
            $publicacaoId
        );
    }

    /*
     * ==========================================================
     * ACHADOS E PERDIDOS
     * ==========================================================
     */

    public function achadoPerdido(
        int $usuarioId,
        string $tipoAchado,
        string $titulo,
        ?int $publicacaoId = null
    ): bool {

        $descricaoTipo =
            $tipoAchado === "Achado"
                ? "achado"
                : "item perdido";

        $link = "achados_perdidos.php";

        if ($publicacaoId !== null) {
            $link .= "?id=" . $publicacaoId;
        }

        return $this->criar(
            $usuarioId,
            "Achado_Perdido",
            "🔎 Novo " . $descricaoTipo,
            $titulo,
            $link,
            $publicacaoId
        );
    }

    /*
     * ==========================================================
     * RESERVA
     * ==========================================================
     */

    public function reserva(
        int $usuarioId,
        string $espaco,
        string $status,
        ?int $reservaId = null
    ): bool {

        if ($status === "Aguardando_Pagamento") {
            $status = "Aguardando pagamento (pague pelo Financeiro para confirmar)";
        }

        $link = "nova_reserva.php";

        if ($reservaId !== null) {
            $link .= "?id=" . $reservaId;
        }

        return $this->criar(
            $usuarioId,
            "Reserva",
            "📅 Atualização da reserva",
            "Sua reserva do espaço "
                . $espaco
                . " está com status: "
                . $status
                . ".",
            $link
        );
    }

    /*
     * ==========================================================
     * OCORRÊNCIA
     * ==========================================================
     */

    public function ocorrencia(
        int $usuarioId,
        string $titulo,
        string $status,
        ?int $ocorrenciaId = null
    ): bool {

        $link = "ocorrencias.php";

        if ($ocorrenciaId !== null) {
            $link .= "?id=" . $ocorrenciaId;
        }

        return $this->criar(
            $usuarioId,
            "Ocorrencia",
            "⚠️ Atualização de ocorrência",
            "A ocorrência "
                . $titulo
                . " está com status: "
                . $status
                . ".",
            $link
        );
    }

    /*
     * ==========================================================
     * COBRANÇA
     * ==========================================================
     */

    public function cobranca(
        int $usuarioId,
        string $descricao,
        float $valor,
        string $status
    ): bool {

        $valorFormatado =
            "R$ "
            . number_format(
                $valor,
                2,
                ",",
                "."
            );

        return $this->criar(
            $usuarioId,
            "Cobranca",
            "💰 Atualização financeira",
            "Cobrança: "
                . $descricao
                . " | Valor: "
                . $valorFormatado
                . " | Status: "
                . $status
                . ".",
            "index.php"
        );
    }

    /*
     * ==========================================================
     * DOCUMENTO
     * ==========================================================
     */

    public function documento(
        int $usuarioId,
        string $titulo,
        ?string $link = null
    ): bool {

        return $this->criar(
            $usuarioId,
            "Documento",
            "📄 Novo documento",
            "Um novo documento do condomínio está disponível: "
                . $titulo,
            $link ?? "index.php"
        );
    }

    /*
     * ==========================================================
     * AUTORIZAÇÃO
     * ==========================================================
     */

    public function autorizacao(
        int $usuarioId,
        string $nomeVisitante,
        string $status
    ): bool {

        return $this->criar(
            $usuarioId,
            "Autorizacao",
            "🚗 Atualização de autorização",
            "A autorização para "
                . $nomeVisitante
                . " está com status: "
                . $status
                . ".",
            "autorizacoes.php"
        );
    }
}