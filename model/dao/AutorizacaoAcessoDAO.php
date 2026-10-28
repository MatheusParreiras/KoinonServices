<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/AutorizacaoAcessoDTO.php";

class AutorizacaoAcessoDAO
{
    private PDO $conexao;

    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }

    /**
     * Lista todas as autorizações com os dados relacionados.
     */
    public function listar(): array
    {
        $sql = "
            SELECT
                a.id,
                a.visitante_id,
                a.unidade_id,
                a.usuario_autorizador_id,
                a.data_inicio,
                a.data_fim,
                a.qr_code,
                a.status,
                a.created_at,

                v.nome AS visitante_nome,
                v.documento AS visitante_documento,
                v.foto_url AS visitante_foto,

                u.identificacao AS unidade_identificacao,

                us.nome AS usuario_autorizador_nome

            FROM autorizacao_acesso a

            INNER JOIN visitante v
                ON v.id = a.visitante_id

            INNER JOIN unidade u
                ON u.id = a.unidade_id

            LEFT JOIN usuario us
                ON us.id = a.usuario_autorizador_id

            ORDER BY
                a.created_at DESC,
                a.id DESC
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->execute();

        $autorizacoes = [];

        while (
            $dados =
                $stmt->fetch(PDO::FETCH_ASSOC)
        ) {

            $autorizacoes[] =
                $this->montarDTO($dados);
        }

        return $autorizacoes;
    }

    /**
     * Lista as autorizações de um morador.
     */
    public function listarPorMorador(
        int $usuarioId
    ): array {

        $sql = "
            SELECT
                a.id,
                a.visitante_id,
                a.unidade_id,
                a.usuario_autorizador_id,
                a.data_inicio,
                a.data_fim,
                a.qr_code,
                a.status,
                a.created_at,

                v.nome AS visitante_nome,
                v.documento AS visitante_documento,
                v.foto_url AS visitante_foto,

                u.identificacao AS unidade_identificacao,

                us.nome AS usuario_autorizador_nome

            FROM autorizacao_acesso a

            INNER JOIN visitante v
                ON v.id = a.visitante_id

            INNER JOIN unidade u
                ON u.id = a.unidade_id

            LEFT JOIN usuario us
                ON us.id = a.usuario_autorizador_id

            WHERE
                a.usuario_autorizador_id = :usuario_id

            ORDER BY
                a.created_at DESC,
                a.id DESC
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }

    /**
     * Busca uma autorização pertencente ao morador informado.
     */
    public function buscarPorId(
        int $id,
        int $usuarioId
    ): ?array {

        $sql = "
            SELECT
                a.id,
                a.visitante_id,
                a.unidade_id,
                a.usuario_autorizador_id,
                a.data_inicio,
                a.data_fim,
                a.qr_code,
                a.status,
                a.created_at,

                v.nome AS visitante_nome,
                v.documento AS visitante_documento,
                v.foto_url AS visitante_foto,

                u.identificacao AS unidade_identificacao,

                us.nome AS usuario_autorizador_nome

            FROM autorizacao_acesso a

            INNER JOIN visitante v
                ON v.id = a.visitante_id

            INNER JOIN unidade u
                ON u.id = a.unidade_id

            LEFT JOIN usuario us
                ON us.id = a.usuario_autorizador_id

            WHERE
                a.id = :id
                AND a.usuario_autorizador_id = :usuario_id

            LIMIT 1
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $resultado =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return $resultado ?: null;
    }

    /**
     * Cadastra uma autorização.
     */
    public function cadastrar(
        int $visitanteId,
        int $unidadeId,
        ?int $usuarioAutorizadorId,
        string $dataInicio,
        string $dataFim,
        string $qrCode,
        string $status = "Ativo"
    ): bool {

        $sql = "
            INSERT INTO autorizacao_acesso (
                visitante_id,
                unidade_id,
                usuario_autorizador_id,
                data_inicio,
                data_fim,
                qr_code,
                status
            )
            VALUES (
                :visitante_id,
                :unidade_id,
                :usuario_autorizador_id,
                :data_inicio,
                :data_fim,
                :qr_code,
                :status
            )
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":visitante_id",
            $visitanteId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":unidade_id",
            $unidadeId,
            PDO::PARAM_INT
        );

        if (
            $usuarioAutorizadorId === null
        ) {

            $stmt->bindValue(
                ":usuario_autorizador_id",
                null,
                PDO::PARAM_NULL
            );

        } else {

            $stmt->bindValue(
                ":usuario_autorizador_id",
                $usuarioAutorizadorId,
                PDO::PARAM_INT
            );
        }

        $stmt->bindValue(
            ":data_inicio",
            $dataInicio
        );

        $stmt->bindValue(
            ":data_fim",
            $dataFim
        );

        $stmt->bindValue(
            ":qr_code",
            $qrCode
        );

        $stmt->bindValue(
            ":status",
            $status
        );

        return $stmt->execute();
    }

    /**
     * Cancela uma autorização do morador.
     */
    public function cancelar(
        int $id,
        int $usuarioId
    ): bool {

        $sql = "
            UPDATE autorizacao_acesso
            SET
                status = 'Cancelado'

            WHERE
                id = :id
                AND usuario_autorizador_id = :usuario_id
                AND status = 'Ativo'
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":usuario_id",
            $usuarioId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Exclui uma autorização da gestão administrativa.
     */
    public function excluir(
        int $id
    ): bool {

        $sql = "
            DELETE FROM autorizacao_acesso
            WHERE id = :id
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Atualiza autorizações ativas cuja data final já passou.
     */
    public function atualizarExpiradas(): void
    {
        $sql = "
            UPDATE autorizacao_acesso
            SET
                status = 'Expirado'

            WHERE
                status = 'Ativo'
                AND data_fim < NOW()
        ";

        $stmt =
            $this->conexao->prepare($sql);

        $stmt->execute();
    }

    /**
     * Converte o registro do banco para DTO.
     */
    private function montarDTO(
        array $dados
    ): AutorizacaoAcessoDTO {

        $dto =
            new AutorizacaoAcessoDTO();

        $dto->setId(
            isset($dados["id"])
                ? (int) $dados["id"]
                : null
        );

        $dto->setVisitanteId(
            isset($dados["visitante_id"])
                ? (int) $dados["visitante_id"]
                : null
        );

        $dto->setUnidadeId(
            isset($dados["unidade_id"])
                ? (int) $dados["unidade_id"]
                : null
        );

        $dto->setUsuarioAutorizadorId(
            isset(
                $dados["usuario_autorizador_id"]
            )
                ? (int)
                    $dados["usuario_autorizador_id"]
                : null
        );

        $dto->setDataInicio(
            $dados["data_inicio"]
            ?? null
        );

        $dto->setDataFim(
            $dados["data_fim"]
            ?? null
        );

        $dto->setQrCode(
            $dados["qr_code"]
            ?? null
        );

        $dto->setStatus(
            $dados["status"]
            ?? "Ativo"
        );

        $dto->setVisitanteNome(
            $dados["visitante_nome"]
            ?? null
        );

        $dto->setVisitanteDocumento(
            $dados["visitante_documento"]
            ?? null
        );

        $dto->setVisitanteFoto(
            $dados["visitante_foto"]
            ?? null
        );

        $dto->setUnidadeIdentificacao(
            $dados["unidade_identificacao"]
            ?? null
        );

        $dto->setUsuarioAutorizadorNome(
            $dados["usuario_autorizador_nome"]
            ?? null
        );

        return $dto;
    }
}