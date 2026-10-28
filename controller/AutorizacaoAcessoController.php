<?php

require_once __DIR__ . "/../model/dao/AutorizacaoAcessoDAO.php";
require_once __DIR__ . "/../model/dto/AutorizacaoAcessoDTO.php";
require_once __DIR__ . "/PermissaoController.php";
require_once __DIR__ . "/../service/NotificacaoService.php";

class AutorizacaoAcessoController
{
    private AutorizacaoAcessoDAO $dao;

    private NotificacaoService $notificacao;

    public function __construct()
    {
        $this->dao = new AutorizacaoAcessoDAO();
        $this->notificacao = new NotificacaoService();
    }

    /**
     * Lista todas as autorizações para as telas de gestão/portaria.
     */
    public function listar(): array
    {
        PermissaoController::exigirPortaria();

        $this->dao->atualizarExpiradas();

        return $this->dao->listar();
    }

    /**
     * Lista as autorizações pertencentes ao morador logado.
     */
    public function listarPorMorador(int $usuarioId): array
    {
        PermissaoController::exigirMorador();

        $this->dao->atualizarExpiradas();

        return $this->dao->listarPorMorador($usuarioId);
    }

    /**
     * Busca uma autorização pertencente ao morador logado.
     */
    public function buscarPorId(
        int $id,
        int $usuarioId
    ): ?array {

        PermissaoController::exigirMorador();

        $this->dao->atualizarExpiradas();

        return $this->dao->buscarPorId(
            $id,
            $usuarioId
        );
    }

    /**
     * Cadastra uma autorização.
     *
     * Formatos aceitos:
     *
     * 1. cadastrar(AutorizacaoAcessoDTO $autorizacao)
     *
     * 2. cadastrar(
     *      $visitanteId,
     *      $unidadeId,
     *      $usuarioId,
     *      $dataInicio,
     *      $dataFim
     *    )
     */
    public function cadastrar(mixed ...$dados): bool
    {
        PermissaoController::exigirPerfil([
            "Morador",
            "Sindico",
            "Porteiro",
            "Administrador"
        ]);

        $status = "Ativo";
        $qrCode = null;

        if (
            count($dados) === 1 &&
            $dados[0] instanceof AutorizacaoAcessoDTO
        ) {

            $autorizacao = $dados[0];

            $visitanteId =
                $autorizacao->getVisitanteId();

            $unidadeId =
                $autorizacao->getUnidadeId();

            $usuarioId =
                $autorizacao->getUsuarioAutorizadorId();

            $dataInicio =
                $autorizacao->getDataInicio();

            $dataFim =
                $autorizacao->getDataFim();

            $status =
                $autorizacao->getStatus()
                ?: "Ativo";

            $qrCode =
                $autorizacao->getQrCode();

            $usuarioId =
                $usuarioId !== null
                    ? (int) $usuarioId
                    : null;

        } elseif (count($dados) === 5) {

            $visitanteId =
                (int) $dados[0];

            $unidadeId =
                (int) $dados[1];

            $usuarioId =
                (int) $dados[2];

            $dataInicio =
                (string) $dados[3];

            $dataFim =
                (string) $dados[4];

        } else {

            return false;
        }

        /*
         * ======================================================
         * VALIDAÇÕES
         * ======================================================
         */

        if (
            empty($visitanteId) ||
            empty($unidadeId) ||
            empty($dataInicio) ||
            empty($dataFim)
        ) {
            return false;
        }

        if (
            strtotime((string) $dataInicio) === false ||
            strtotime((string) $dataFim) === false
        ) {
            return false;
        }

        if (
            strtotime((string) $dataFim) <=
            strtotime((string) $dataInicio)
        ) {
            return false;
        }

        /*
         * ======================================================
         * STATUS
         * ======================================================
         */

        if (
            !in_array(
                $status,
                [
                    "Ativo",
                    "Expirado",
                    "Cancelado",
                    "Utilizado"
                ],
                true
            )
        ) {
            $status = "Ativo";
        }

        /*
         * ======================================================
         * QR CODE
         * ======================================================
         */

        if (empty($qrCode)) {

            $qrCode =
                "KOINON-" .
                strtoupper(
                    bin2hex(
                        random_bytes(8)
                    )
                );
        }

        /*
         * ======================================================
         * CADASTRA NO BANCO
         * ======================================================
         */

        $sucesso =
            $this->dao->cadastrar(
                (int) $visitanteId,
                (int) $unidadeId,
                $usuarioId !== null
                    ? (int) $usuarioId
                    : null,
                (string) $dataInicio,
                (string) $dataFim,
                $qrCode,
                $status
            );

        /*
         * ======================================================
         * NOTIFICAÇÕES
         *
         * 1. Morador que criou a autorização
         * 2. Síndico
         * 3. Administrador
         * 4. Porteiro, para acompanhamento operacional
         * ======================================================
         */

        if (
            $sucesso &&
            $usuarioId !== null
        ) {

            /*
             * NOTIFICAÇÃO DO MORADOR
             */
            $this->notificacao->autorizacao(
                (int) $usuarioId,
                "Visitante",
                $status
            );

            /*
             * NOTIFICAÇÃO DA GESTÃO
             *
             * O próprio morador é ignorado para não
             * receber uma segunda notificação.
             */
            $this->notificacao->notificarGestao(
                "Autorizacao",
                "🚗 Nova autorização de acesso",
                "Uma nova autorização de acesso foi cadastrada. "
                . "Visitante: Visitante. "
                . "Status: "
                . $status
                . ".",
                "autorizacoes.php",
                (int) $usuarioId
            );

            $this->notificacao->notificarPortaria(
                "Autorizacao",
                "🚪 Nova autorização de visitante",
                "Uma nova autorização de acesso foi cadastrada para atendimento na portaria. Status: "
                . $status
                . ".",
                "autorizacao_acesso/index.php"
            );
        }

        return $sucesso;
    }

    /**
     * Cancela uma autorização criada pelo morador.
     */
    public function cancelar(
        int $id,
        int $usuarioId
    ): bool {

        PermissaoController::exigirMorador();

        $autorizacao =
            $this->dao->buscarPorId(
                $id,
                $usuarioId
            );

        $sucesso =
            $this->dao->cancelar(
                $id,
                $usuarioId
            );

        if (
            $sucesso &&
            $autorizacao !== null
        ) {

            /*
             * Morador
             */
            $this->notificacao->autorizacao(
                $usuarioId,
                $autorizacao["visitante_nome"]
                    ?? "Visitante",
                "Cancelado"
            );

            /*
             * Síndico + Administrador
             *
             * Porteiro não recebe.
             */
            $nomeVisitante =
                $autorizacao["visitante_nome"]
                ?? "Visitante";

            $this->notificacao->notificarGestao(
                "Autorizacao",
                "🚗 Autorização cancelada",
                "A autorização de acesso do visitante "
                . $nomeVisitante
                . " foi cancelada. "
                . "Status: Cancelado.",
                "autorizacoes.php",
                $usuarioId
            );

            $this->notificacao->notificarPortaria(
                "Autorizacao",
                "🚪 Autorização cancelada",
                "A autorização de acesso do visitante "
                . $nomeVisitante
                . " foi cancelada.",
                "autorizacao_acesso/index.php",
                $usuarioId
            );
        }

        return $sucesso;
    }

    /**
     * Exclui uma autorização pela tela administrativa/portaria.
     */
    public function excluir(int $id): bool
    {
        PermissaoController::exigirPortaria();

        if ($id <= 0) {
            return false;
        }

        return $this->dao->excluir($id);
    }
}