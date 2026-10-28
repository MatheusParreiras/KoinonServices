<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/NotificacaoDTO.php";

class NotificacaoDAO
{
    private PDO $conexao;

    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }


    // ==========================================================
    // CADASTRAR NOTIFICAÇÃO
    // ==========================================================

    public function cadastrar(
        NotificacaoDTO $notificacao
    ): bool {

        $sql = "
            INSERT INTO notificacao (
                usuario_id,
                publicacao_id,
                tipo,
                titulo,
                mensagem,
                link,
                lida
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt =
            $this->conexao->prepare($sql);

        return $stmt->execute([
            $notificacao->getUsuarioId(),
            $notificacao->getPublicacaoId(),
            $notificacao->getTipo(),
            $notificacao->getTitulo(),
            $notificacao->getMensagem(),
            $notificacao->getLink(),
            $notificacao->getLida() ? 1 : 0
        ]);
    }


    // ==========================================================
    // LISTAR TODAS AS NOTIFICAÇÕES DO USUÁRIO
    // ==========================================================

    public function listarPorUsuario(
        int $usuarioId
    ): array {

        $sql = "
            SELECT
                n.*,
                p.titulo AS publicacao_titulo
            FROM notificacao n
            LEFT JOIN publicacao p
                ON p.id = n.publicacao_id
            WHERE n.usuario_id = ?
            ORDER BY n.created_at DESC
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->execute([
            $usuarioId
        ]);

        $dados =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        $lista = [];

        foreach ($dados as $item) {

            $lista[] =
                $this->montarDTO($item);
        }

        return $lista;
    }


    // ==========================================================
    // LISTAR SOMENTE NÃO LIDAS
    // ==========================================================

    public function listarNaoLidas(
        int $usuarioId
    ): array {

        $sql = "
            SELECT
                n.*,
                p.titulo AS publicacao_titulo
            FROM notificacao n
            LEFT JOIN publicacao p
                ON p.id = n.publicacao_id
            WHERE n.usuario_id = ?
              AND n.lida = 0
            ORDER BY n.created_at DESC
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->execute([
            $usuarioId
        ]);

        $dados =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        $lista = [];

        foreach ($dados as $item) {

            $lista[] =
                $this->montarDTO($item);
        }

        return $lista;
    }


    // ==========================================================
    // BUSCAR POR ID
    // ==========================================================

    public function buscarPorId(
        int $id,
        int $usuarioId
    ): ?NotificacaoDTO {

        $sql = "
            SELECT
                n.*,
                p.titulo AS publicacao_titulo
            FROM notificacao n
            LEFT JOIN publicacao p
                ON p.id = n.publicacao_id
            WHERE n.id = ?
              AND n.usuario_id = ?
            LIMIT 1
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->execute([
            $id,
            $usuarioId
        ]);

        $dados =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$dados) {
            return null;
        }

        return $this->montarDTO(
            $dados
        );
    }


    // ==========================================================
    // CONTAR NÃO LIDAS
    // ==========================================================

    public function contarNaoLidas(
        int $usuarioId
    ): int {

        $sql = "
            SELECT COUNT(*)
            FROM notificacao
            WHERE usuario_id = ?
              AND lida = 0
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->execute([
            $usuarioId
        ]);

        return (int)
            $stmt->fetchColumn();
    }


    // ==========================================================
    // MARCAR UMA COMO LIDA
    // ==========================================================

    public function marcarComoLida(
        int $id,
        int $usuarioId
    ): bool {

        $sql = "
            UPDATE notificacao
            SET lida = 1
            WHERE id = ?
              AND usuario_id = ?
        ";

        $stmt =
            $this->conexao->prepare($sql);

        return $stmt->execute([
            $id,
            $usuarioId
        ]);
    }


    // ==========================================================
    // MARCAR TODAS COMO LIDAS
    // ==========================================================

    public function marcarTodasComoLidas(
        int $usuarioId
    ): bool {

        $sql = "
            UPDATE notificacao
            SET lida = 1
            WHERE usuario_id = ?
              AND lida = 0
        ";

        $stmt =
            $this->conexao->prepare($sql);

        return $stmt->execute([
            $usuarioId
        ]);
    }


    // ==========================================================
    // EXCLUIR NOTIFICAÇÃO
    // ==========================================================

    public function excluir(
        int $id,
        int $usuarioId
    ): bool {

        $sql = "
            DELETE FROM notificacao
            WHERE id = ?
              AND usuario_id = ?
        ";

        $stmt =
            $this->conexao->prepare($sql);

        return $stmt->execute([
            $id,
            $usuarioId
        ]);
    }


    // ==========================================================
    // MONTAR DTO
    // ==========================================================

    private function montarDTO(
        array $dados
    ): NotificacaoDTO {

        $dto =
            new NotificacaoDTO();

        $dto->setId(
            isset($dados["id"])
                ? (int) $dados["id"]
                : null
        );

        $dto->setUsuarioId(
            isset($dados["usuario_id"])
                ? (int) $dados["usuario_id"]
                : null
        );

        $dto->setPublicacaoId(
            isset($dados["publicacao_id"])
                ? (int) $dados["publicacao_id"]
                : null
        );

        $dto->setTipo(
            $dados["tipo"] ?? ""
        );

        $dto->setTitulo(
            $dados["titulo"] ?? ""
        );

        $dto->setMensagem(
            $dados["mensagem"] ?? ""
        );

        $dto->setLink(
            $dados["link"] ?? null
        );

        $dto->setLida(
            isset($dados["lida"]) &&
            (int) $dados["lida"] === 1
        );

        $dto->setCreatedAt(
            $dados["created_at"] ?? null
        );

        return $dto;
    }
}