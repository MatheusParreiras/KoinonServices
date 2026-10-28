<?php

require_once __DIR__ . "/../model/dao/ReservaDAO.php";
require_once __DIR__ . "/../service/NotificacaoService.php";
require_once __DIR__ . "/../model/dto/ReservaDTO.php";
require_once __DIR__ . "/../model/dao/Conexao.php";

class ReservaController
{
    private NotificacaoService $notificacao;

    private ReservaDAO $dao;

    private PDO $conexao;


    public function __construct()
    {
        $this->dao =
            new ReservaDAO();

        $this->notificacao =
            new NotificacaoService();

        $this->conexao =
            Conexao::conectar();
    }


    /*
     * ==========================================================
     * ÚLTIMO ERRO
     * ==========================================================
     */

    public function getUltimoErro(): string
    {
        return $this->dao->getUltimoErro();
    }


    /*
     * ==========================================================
     * LISTAR
     * ==========================================================
     */

    public function listar(): array
    {
        return $this->dao->listar();
    }


    /*
     * ==========================================================
     * BUSCAR POR ID
     * ==========================================================
     */

    public function buscarPorId(
        int $id
    ): ?ReservaDTO {

        return $this->dao->buscarPorId(
            $id
        );
    }


    /*
     * ==========================================================
     * CADASTRAR
     * ==========================================================
     */

    public function cadastrar(
        ReservaDTO $reserva
    ): bool {

        $sucesso =
            $this->dao->cadastrar(
                $reserva
            );


        if (
            !$sucesso ||
            $reserva->getUsuarioId() === null
        ) {

            return $sucesso;
        }


        $usuarioId =
            (int) $reserva->getUsuarioId();


        /*
         * NOTIFICA O MORADOR
         */

        $this->notificacao->reserva(
            $usuarioId,
            $reserva->getEspacoNome(),
            $reserva->getStatus(),
            $reserva->getId()
        );


        /*
         * NOTIFICA SÍNDICO E ADMINISTRADOR
         */

        $this->notificarGestaoReserva(
            $usuarioId,
            $reserva
        );


        return $sucesso;
    }


    /*
     * ==========================================================
     * EDITAR
     * ==========================================================
     */

    public function editar(
        ReservaDTO $reserva
    ): bool {

        $sucesso =
            $this->dao->editar(
                $reserva
            );


        if (
            !$sucesso ||
            $reserva->getUsuarioId() === null
        ) {

            return $sucesso;
        }


        $usuarioId =
            (int) $reserva->getUsuarioId();


        $this->notificacao->reserva(
            $usuarioId,
            $reserva->getEspacoNome(),
            $reserva->getStatus(),
            $reserva->getId()
        );


        $this->notificarGestaoReserva(
            $usuarioId,
            $reserva
        );


        return $sucesso;
    }


    /*
     * ==========================================================
     * EXCLUIR
     * ==========================================================
     */

    public function excluir(
        int $id
    ): bool {

        return $this->dao->excluir(
            $id
        );
    }


    /*
     * ==========================================================
     * ALTERAR STATUS
     * ==========================================================
     */

    public function alterarStatus(
        int $id,
        string $status
    ): bool {

        $reserva =
            $this->dao->buscarPorId(
                $id
            );


        $sucesso =
            $this->dao->alterarStatus(
                $id,
                $status
            );


        if (
            !$sucesso ||
            $reserva === null ||
            $reserva->getUsuarioId() === null
        ) {

            return $sucesso;
        }


        $usuarioId =
            (int) $reserva->getUsuarioId();


        $this->notificacao->reserva(
            $usuarioId,
            $reserva->getEspacoNome(),
            $status,
            $id
        );


        $reserva->setStatus(
            $status
        );


        $this->notificarGestaoReserva(
            $usuarioId,
            $reserva,
            $status
        );


        return $sucesso;
    }


    /*
     * ==========================================================
     * NOTIFICAR GESTÃO
     * ==========================================================
     */

    private function notificarGestaoReserva(
        int $usuarioMoradorId,
        ReservaDTO $reserva,
        ?string $statusPersonalizado = null
    ): void {

        $sql = "
            SELECT
                id,
                perfil
            FROM usuario
            WHERE ativo = 1
              AND perfil IN (
                  'Administrador',
                  'Sindico'
              )
              AND id <> :usuario_morador_id
            ORDER BY
                perfil,
                id
        ";


        $stmt =
            $this->conexao->prepare(
                $sql
            );


        $stmt->bindValue(
            ":usuario_morador_id",
            $usuarioMoradorId,
            PDO::PARAM_INT
        );


        $stmt->execute();


        $usuarios =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        if (
            empty($usuarios)
        ) {

            return;
        }


        $status =
            $statusPersonalizado
            ?? $reserva->getStatus();


        $titulo =
            "📅 Nova reserva cadastrada";


        $mensagem =
            "Uma nova reserva foi cadastrada "
            . "para o espaço "
            . $reserva->getEspacoNome()
            . ". Status: "
            . $status
            . ".";


        if (
            $statusPersonalizado !== null
        ) {

            $titulo =
                "📅 Reserva atualizada";


            $mensagem =
                "A reserva do espaço "
                . $reserva->getEspacoNome()
                . " teve seu status alterado para: "
                . ($status === "Aguardando_Pagamento" ? "Aguardando pagamento" : $status)
                . ".";
        }


        $link =
            "nova_reserva.php?id="
            . $reserva->getId();


        foreach (
            $usuarios as $usuario
        ) {

            $usuarioId =
                (int) $usuario["id"];


            $this->notificacao->criar(
                $usuarioId,
                "Reserva",
                $titulo,
                $mensagem,
                $link
            );
        }
    }


    /*
     * ==========================================================
     * STATUS AUTOMÁTICOS
     * ==========================================================
     */

    public function atualizarStatusAutomaticos(): bool
    {
        return $this->dao
            ->atualizarStatusAutomaticos();
    }
}