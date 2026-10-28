<?php

require_once __DIR__ . "/../model/dao/MoradorDAO.php";
require_once __DIR__ . "/../model/dto/MoradorDashboardDTO.php";
require_once __DIR__ . "/../model/dto/VeiculoDTO.php";
require_once __DIR__ . "/../model/dto/OcorrenciaDTO.php";
require_once __DIR__ . "/../service/NotificacaoService.php";

class MoradorController
{
    private MoradorDAO $dao;

    public function __construct()
    {
        $this->dao = new MoradorDAO();
    }


    /*
     * ==========================================================
     * DASHBOARD
     * ==========================================================
     */

    public function carregarDashboard(
        int $usuarioId
    ): MoradorDashboardDTO {

        $dashboard = new MoradorDashboardDTO();

        $dashboard->setUsuario(
            $this->dao->buscarUsuario($usuarioId)
        );

        $dashboard->setUnidade(
            $this->dao->buscarUnidade($usuarioId)
        );

        $dashboard->setVeiculos(
            $this->dao->listarVeiculos($usuarioId)
        );

        $dashboard->setEncomendas(
            $this->dao->listarEncomendas($usuarioId)
        );

        $dashboard->setReservas(
            $this->dao->listarReservas($usuarioId)
        );

        $dashboard->setOcorrencias(
            $this->dao->listarOcorrencias($usuarioId)
        );

        $dashboard->setCobrancas(
            $this->dao->listarCobrancas($usuarioId)
        );

        $dashboard->setAutorizacoes(
            $this->dao->listarAutorizacoes($usuarioId)
        );

        $dashboard->setPublicacoes(
            $this->dao->listarPublicacoes()
        );

        $dashboard->setDocumentos(
            $this->dao->listarDocumentos()
        );

        return $dashboard;
    }


    public function buscarCobrancaUsuario(
        int $usuarioId,
        int $cobrancaId
    ): ?array {

        return $this->dao->buscarCobrancaUsuario(
            $usuarioId,
            $cobrancaId
        );
    }


    /*
     * ==========================================================
     * RESERVAS
     * ==========================================================
     */

    public function criarReserva(
        int $usuarioId,
        string $espacoNome,
        string $dataInicio,
        string $dataFim,
        array $listaPresenca = []
    ): bool {

        $resultado = $this->dao->criarReserva(
            $usuarioId,
            $espacoNome,
            $dataInicio,
            $dataFim,
            $listaPresenca
        );

        if (!$resultado) {
            return false;
        }


        /*
         * ------------------------------------------------------
         * NOTIFICAÇÃO DO MORADOR
         * ------------------------------------------------------
         */

        try {

            $notificacaoService =
                new NotificacaoService();

            $notificacaoService->reserva(
                $usuarioId,
                $espacoNome,
                "Pendente"
            );

        } catch (Throwable $e) {

            /*
             * Falha na notificação não desfaz a reserva.
             */
        }


        /*
         * ------------------------------------------------------
         * NOTIFICAÇÃO DA GESTÃO
         *
         * Administrador e Síndico recebem.
         * Porteiro não recebe.
         * ------------------------------------------------------
         */

        try {

            $notificacaoService =
                new NotificacaoService();

            $notificacaoService->notificarGestao(
                "Reserva",
                "📅 Nova reserva solicitada",
                "Uma nova reserva foi solicitada para o espaço "
                    . $espacoNome
                    . ". Status: Pendente.",
                "view/reserva/index.php",
                $usuarioId
            );

        } catch (Throwable $e) {

            /*
             * Falha na notificação não desfaz a reserva.
             */
        }


        return true;
    }


    /*
     * ==========================================================
     * ÚLTIMO ERRO DA RESERVA
     * ==========================================================
     */

    public function getUltimoErroReserva(): string
    {
        return $this->dao->getUltimoErroReserva();
    }


    /*
     * ==========================================================
     * VERIFICAR DISPONIBILIDADE
     * ==========================================================
     */

    public function verificarDisponibilidadeReserva(
        string $espacoNome,
        string $dataInicio,
        string $dataFim
    ): array {

        return $this->dao->verificarDisponibilidadeReserva(
            $espacoNome,
            $dataInicio,
            $dataFim
        );
    }


    /*
     * ==========================================================
     * CANCELAR RESERVA
     * ==========================================================
     */

    public function cancelarReserva(
        int $reservaId,
        int $usuarioId
    ): bool {

        return $this->dao->cancelarReserva(
            $reservaId,
            $usuarioId
        );
    }


    /*
     * ==========================================================
     * VEÍCULOS
     * ==========================================================
     */

    public function cadastrarVeiculo(
        VeiculoDTO $veiculo
    ): bool {

        return $this->dao->cadastrarVeiculo(
            $veiculo
        );
    }


    public function buscarVeiculo(
        int $veiculoId,
        int $usuarioId
    ): ?VeiculoDTO {

        return $this->dao->buscarVeiculo(
            $veiculoId,
            $usuarioId
        );
    }


    public function editarVeiculo(
        VeiculoDTO $veiculo,
        int $usuarioId
    ): bool {

        return $this->dao->editarVeiculo(
            $veiculo,
            $usuarioId
        );
    }


    public function excluirVeiculo(
        int $veiculoId,
        int $usuarioId
    ): bool {

        return $this->dao->excluirVeiculo(
            $veiculoId,
            $usuarioId
        );
    }


    /*
     * ==========================================================
     * OCORRÊNCIAS
     * ==========================================================
     */

    public function cadastrarOcorrencia(
        OcorrenciaDTO $ocorrencia
    ): bool {

        $sucesso = $this->dao->cadastrarOcorrencia(
            $ocorrencia
        );

        /*
         * A gestão (Administrador e Síndico) passa a ser avisada
         * quando um morador registra uma ocorrência.
         * Falha na notificação não desfaz o cadastro.
         */
        if ($sucesso) {

            try {

                $notificacaoService =
                    new NotificacaoService();

                $notificacaoService->notificarGestao(
                    "Ocorrencia",
                    "⚠️ Nova ocorrência registrada",
                    "Um morador registrou a ocorrência \""
                        . $ocorrencia->getTitulo()
                        . "\". Status: Aberta.",
                    "ocorrencia/index.php",
                    $ocorrencia->getUsuarioId()
                );

            } catch (Throwable $e) {
                // Segue normalmente.
            }
        }

        return $sucesso;
    }

    public function ultimaOcorrenciaId(): int
    {
        return $this->dao->getUltimaOcorrenciaId();
    }
}