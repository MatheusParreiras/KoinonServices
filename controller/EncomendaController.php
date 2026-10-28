<?php

require_once __DIR__ . "/../model/dao/EncomendaDAO.php";
require_once __DIR__ . "/../service/NotificacaoService.php";
require_once __DIR__ . "/../model/dto/EncomendaDTO.php";
require_once __DIR__ . "/PermissaoController.php";

class EncomendaController
{
    private NotificacaoService $notificacao;

    private EncomendaDAO $dao;

    public function __construct()
    {
        $this->dao = new EncomendaDAO();
        $this->notificacao = new NotificacaoService();
    }

    public function listar(): array
    {
        PermissaoController::exigirPortaria();

        return $this->dao->listar();
    }

    public function buscarPorId(
        int $id
    ): ?EncomendaDTO {

        PermissaoController::exigirPortaria();

        return $this->dao->buscarPorId(
            $id
        );
    }

    public function cadastrar(
        EncomendaDTO $encomenda
    ): bool {

        PermissaoController::exigirPortaria();

        $sucesso =
            $this->dao->cadastrar(
                $encomenda
            );

        if ($sucesso) {

            $usuarioId =
                $encomenda->getUsuarioRecebedorId();

            if (
                $usuarioId !== null
            ) {

                $this->notificacao->encomenda(
                    (int) $usuarioId,
                    $encomenda->getDescricao(),
                    $encomenda->getId()
                );

            } else {

                $this->notificacao->notificarMoradoresDaUnidade(
                    (int) $encomenda->getUnidadeId(),
                    "Encomenda",
                    "📦 Nova encomenda",
                    "Uma encomenda foi registrada para sua unidade."
                        . (
                            $encomenda->getDescricao() !== ""
                                ? " Descrição: "
                                    . $encomenda->getDescricao()
                                : ""
                        ),
                    "encomendas.php"
                );
            }
        }

        return $sucesso;
    }

    public function editar(
        EncomendaDTO $encomenda
    ): bool {

        PermissaoController::exigirPortaria();

        return $this->dao->editar(
            $encomenda
        );
    }

    public function alterarStatus(
        int $id,
        string $status
    ): bool {

        PermissaoController::exigirPortaria();

        $encomenda =
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
            $encomenda !== null
        ) {

            $usuarioId =
                $encomenda->getUsuarioRecebedorId();

            if (
                $usuarioId !== null
            ) {

                if (
                    $status === "Entregue"
                ) {

                    $this->notificacao->encomendaRetirada(
                        (int) $usuarioId,
                        $encomenda->getDescricao(),
                        $id
                    );

                } else {

                    $this->notificacao->encomendaStatus(
                        (int) $usuarioId,
                        $encomenda->getDescricao(),
                        $status,
                        $id
                    );
                }

            } else {

                $this->notificacao->notificarMoradoresDaUnidade(
                    (int) $encomenda->getUnidadeId(),
                    "Encomenda",
                    "📦 Atualização da encomenda",
                    "Uma encomenda da sua unidade está com status: "
                        . $status
                        . ".",
                    "encomendas.php"
                );
            }
        }

        return $sucesso;
    }

    public function marcarEntregue(
        int $id
    ): bool {

        PermissaoController::exigirPortaria();

        $encomenda =
            $this->dao->buscarPorId(
                $id
            );

        $sucesso =
            $this->dao->marcarEntregue(
                $id
            );

        if (
            $sucesso &&
            $encomenda !== null
        ) {

            $usuarioId =
                $encomenda->getUsuarioRecebedorId();

            if (
                $usuarioId !== null
            ) {

                $this->notificacao->encomendaRetirada(
                    (int) $usuarioId,
                    $encomenda->getDescricao(),
                    $id
                );
            }
        }

        return $sucesso;
    }

    public function reabrir(
        int $id
    ): bool {

        PermissaoController::exigirPortaria();

        $encomenda =
            $this->dao->buscarPorId(
                $id
            );

        $sucesso =
            $this->dao->reabrir(
                $id
            );

        if (
            $sucesso &&
            $encomenda !== null
        ) {

            $usuarioId =
                $encomenda->getUsuarioRecebedorId();

            if (
                $usuarioId !== null
            ) {

                $this->notificacao->encomendaStatus(
                    (int) $usuarioId,
                    $encomenda->getDescricao(),
                    "Aguardando_Retirada",
                    $id
                );
            }
        }

        return $sucesso;
    }

    public function excluir(
        int $id
    ): bool {

        PermissaoController::exigirAdministrador();

        return $this->dao->excluir(
            $id
        );
    }

    public function listarUnidades(): array
    {
        PermissaoController::exigirPortaria();

        return $this->dao->listarUnidades();
    }

    public function listarMoradoresPorUnidade(
        int $unidadeId
    ): array {

        PermissaoController::exigirPortaria();

        return $this->dao->listarMoradoresPorUnidade(
            $unidadeId
        );
    }
}