<?php

require_once __DIR__ . "/../model/dao/CobrancaDAO.php";
require_once __DIR__ . "/../service/NotificacaoService.php";
require_once __DIR__ . "/../model/dto/CobrancaDTO.php";
require_once __DIR__ . "/PermissaoController.php";

class CobrancaController
{
    private NotificacaoService $notificacao;

    private CobrancaDAO $dao;

    public function __construct()
    {
        $this->dao = new CobrancaDAO();
        $this->notificacao = new NotificacaoService();
    }

    public function listar(): array
    {
        PermissaoController::exigirLogin();

        return $this->dao->listar();
    }

    public function buscarPorId(
        int $id
    ): ?CobrancaDTO {

        PermissaoController::exigirLogin();

        return $this->dao->buscarPorId(
            $id
        );
    }

    public function cadastrar(
        CobrancaDTO $cobranca
    ): bool {

        PermissaoController::exigirGestao();

        $sucesso =
            $this->dao->cadastrar(
                $cobranca
            );

        if ($sucesso) {

            $this->notificacao->notificarMoradoresDaUnidade(
                (int) $cobranca->getUnidadeId(),
                "Cobranca",
                "💰 Nova cobrança",
                "Foi registrada uma nova cobrança: "
                    . $cobranca->getDescricao()
                    . " | Valor: R$ "
                    . number_format(
                        $cobranca->getValor(),
                        2,
                        ",",
                        "."
                    )
                    . " | Vencimento: "
                    . date(
                        "d/m/Y",
                        strtotime(
                            $cobranca->getVencimento()
                        )
                    )
                    . ".",
                "index.php"
            );
        }

        return $sucesso;
    }

    public function editar(
        CobrancaDTO $cobranca
    ): bool {

        PermissaoController::exigirGestao();

        $sucesso =
            $this->dao->editar(
                $cobranca
            );

        if ($sucesso) {

            $this->notificacao->notificarMoradoresDaUnidade(
                (int) $cobranca->getUnidadeId(),
                "Cobranca",
                "💰 Cobrança atualizada",
                "A cobrança "
                    . $cobranca->getDescricao()
                    . " foi atualizada. Status: "
                    . $cobranca->getStatus()
                    . ".",
                "index.php"
            );
        }

        return $sucesso;
    }

    public function excluir(
        int $id
    ): bool {

        PermissaoController::exigirGestao();

        return $this->dao->excluir(
            $id
        );
    }

    public function alterarStatus(
        int $id,
        string $status
    ): bool {

        PermissaoController::exigirGestao();

        $cobranca =
            $this->dao->buscarPorId(
                $id
            );

        $sucesso =
            $this->dao->alterarStatus(
                $id,
                $status
            );

        if (
            $sucesso &&
            $cobranca !== null
        ) {

            $this->notificacao->notificarMoradoresDaUnidade(
                (int) $cobranca->getUnidadeId(),
                "Cobranca",
                "💰 Atualização financeira",
                "A cobrança "
                    . $cobranca->getDescricao()
                    . " está com status: "
                    . $status
                    . ".",
                "index.php"
            );
        }

        return $sucesso;
    }

    public function registrarPagamento(
        int $id
    ): bool {

        PermissaoController::exigirGestao();

        $cobranca =
            $this->dao->buscarPorId(
                $id
            );

        $sucesso =
            $this->dao->registrarPagamento(
                $id,
                date(
                    "Y-m-d H:i:s"
                )
            );

        if (
            $sucesso &&
            $cobranca !== null
        ) {

            $this->notificacao->notificarMoradoresDaUnidade(
                (int) $cobranca->getUnidadeId(),
                "Cobranca",
                "💰 Cobrança paga",
                "A cobrança "
                    . $cobranca->getDescricao()
                    . " foi registrada como paga.",
                "index.php"
            );
        }

        return $sucesso;
    }

    public function atualizarVencimentos(): void
    {
        PermissaoController::exigirGestao();

        $this->dao->atualizarVencimentos();
    }
}