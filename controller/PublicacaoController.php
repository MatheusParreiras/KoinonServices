<?php

require_once __DIR__ . "/../model/dao/PublicacaoDAO.php";
require_once __DIR__ . "/../service/NotificacaoService.php";
require_once __DIR__ . "/../model/dto/PublicacaoDTO.php";

class PublicacaoController
{
    private NotificacaoService $notificacao;

    private PublicacaoDAO $dao;

    public function __construct()
    {
        $this->dao = new PublicacaoDAO();
        $this->notificacao = new NotificacaoService();
    }

    public function listar(): array
    {
        return $this->dao->listar();
    }

    public function buscarPorId(
        int $id
    ): ?PublicacaoDTO {

        return $this->dao->buscarPorId(
            $id
        );
    }

    public function cadastrar(
        PublicacaoDTO $publicacao
    ): bool {

        $sucesso =
            $this->dao->cadastrar(
                $publicacao
            );

        if ($sucesso) {
            $this->notificarPublicacao(
                $publicacao
            );
        }

        return $sucesso;
    }

    public function cadastrarComOpcoes(
        PublicacaoDTO $publicacao,
        array $opcoes
    ): bool {

        $sucesso =
            $this->dao->cadastrarComOpcoes(
                $publicacao,
                $opcoes
            );

        if ($sucesso) {
            $this->notificarPublicacao(
                $publicacao
            );
        }

        return $sucesso;
    }

    public function editar(
        PublicacaoDTO $publicacao
    ): bool {

        return $this->dao->editar(
            $publicacao
        );
    }

    public function editarComOpcoes(
        PublicacaoDTO $publicacao,
        array $opcoes
    ): bool {

        return $this->dao->editarComOpcoes(
            $publicacao,
            $opcoes
        );
    }

    public function excluir(
        int $id
    ): bool {

        return $this->dao->excluir(
            $id
        );
    }

    public function alterarStatus(
        int $id,
        string $status
    ): bool {

        $publicacao =
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
            $publicacao !== null &&
            $publicacao->getUsuarioId() !== null &&
            $publicacao->getCategoria() === "Achados_Perdidos" &&
            $status !== "Ativo"
        ) {

            $this->notificacao->criar(
                (int) $publicacao->getUsuarioId(),
                "Achado_Perdido",
                "🔎 Atualização do achado/perdido",
                "O item \""
                    . $publicacao->getTitulo()
                    . "\" foi atualizado para o status: "
                    . $status
                    . ".",
                "achados_perdidos.php?id=" . $id,
                $id
            );
        }

        return $sucesso;
    }

    public function listarOpcoesEnquete(
        int $publicacaoId
    ): array {

        return $this->dao->listarOpcoesEnquete(
            $publicacaoId
        );
    }

    public function usuarioJaVotou(
        int $publicacaoId,
        int $usuarioId
    ): bool {

        return $this->dao->usuarioJaVotou(
            $publicacaoId,
            $usuarioId
        );
    }

    public function votarEnquete(
        int $publicacaoId,
        int $usuarioId,
        int $opcaoId
    ): bool {

        return $this->dao->votarEnquete(
            $publicacaoId,
            $usuarioId,
            $opcaoId
        );
    }

    public function contarVotosOpcao(
        int $opcaoId
    ): int {

        return $this->dao->contarVotosOpcao(
            $opcaoId
        );
    }

    public function totalVotosEnquete(
        int $publicacaoId
    ): int {

        return $this->dao->totalVotosEnquete(
            $publicacaoId
        );
    }

    private function notificarPublicacao(
        PublicacaoDTO $publicacao
    ): void {

        $categoria =
            $publicacao->getCategoria();

        $usuarioAutor =
            $publicacao->getUsuarioId();

        /*
         * ======================================================
         * AVISO OFICIAL
         * ======================================================
         */

        if (
            $categoria === "Aviso_Sindico" ||
            $categoria === "Aviso_Administrador"
        ) {

            $this->notificacao->notificarTodosMoradores(
                "Aviso",
                "📢 " . $publicacao->getTitulo(),
                $publicacao->getConteudo(),
                "publicacoes.php",
                $publicacao->getId(),
                $usuarioAutor
            );

            return;
        }

        /*
         * ======================================================
         * ENQUETE
         * ======================================================
         */

        if (
            $categoria === "Enquete"
        ) {

            $this->notificacao->notificarTodosMoradores(
                "Enquete",
                "📊 Nova enquete",
                "Uma nova enquete está disponível para votação: "
                    . $publicacao->getTitulo(),
                "enquetes.php",
                $publicacao->getId(),
                $usuarioAutor
            );

            return;
        }

        /*
         * ======================================================
         * ACHADOS E PERDIDOS
         * ======================================================
         */

        if (
            $categoria === "Achados_Perdidos"
        ) {

            $tipo =
                $publicacao->getTipoAchado()
                ?? "Achado";

            $descricaoTipo =
                $tipo === "Achado"
                    ? "achado"
                    : "item perdido";

            $this->notificacao->notificarTodosMoradores(
                "Achado_Perdido",
                "🔎 Novo " . $descricaoTipo,
                $publicacao->getTitulo()
                    . " - "
                    . $publicacao->getConteudo(),
                "achados_perdidos.php",
                $publicacao->getId(),
                $usuarioAutor
            );
        }
    }
}