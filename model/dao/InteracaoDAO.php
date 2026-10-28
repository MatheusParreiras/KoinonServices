<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/InteracaoDTO.php";

class InteracaoDAO
{
    private PDO $conexao;

    public function __construct()
    {
        $this->conexao = Conexao::conectar();
    }

    public function listar(): array
    {
        $sql = "
            SELECT
                i.*,
                p.titulo AS publicacao_titulo,
                p.categoria AS publicacao_categoria,
                u.nome AS usuario_nome
            FROM interacao i

            INNER JOIN publicacao p
                ON i.publicacao_id = p.id

            INNER JOIN usuario u
                ON i.usuario_id = u.id

            ORDER BY i.created_at DESC
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();

        $lista = [];

        while ($row = $stmt->fetch()) {
            $lista[] = $this->montarDTO($row);
        }

        return $lista;
    }

    public function buscarPorId(int $id): ?InteracaoDTO
    {
        $sql = "
            SELECT
                i.*,
                p.titulo AS publicacao_titulo,
                p.categoria AS publicacao_categoria,
                u.nome AS usuario_nome
            FROM interacao i

            INNER JOIN publicacao p
                ON i.publicacao_id = p.id

            INNER JOIN usuario u
                ON i.usuario_id = u.id

            WHERE i.id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->montarDTO($row);
    }

    public function cadastrar(InteracaoDTO $interacao): bool
    {
        $this->validarInteracao($interacao);

        /*
         * Uma mesma pessoa não pode curtir duas vezes
         * a mesma publicação.
         */
        if (
            $interacao->getTipoInteracao()
            === "Curtida"
        ) {

            $sqlVerifica = "
                SELECT id
                FROM interacao
                WHERE publicacao_id = :publicacao_id
                  AND usuario_id = :usuario_id
                  AND tipo_interacao = 'Curtida'
                LIMIT 1
            ";

            $stmtVerifica =
                $this->conexao->prepare(
                    $sqlVerifica
                );

            $stmtVerifica->bindValue(
                ":publicacao_id",
                $interacao->getPublicacaoId(),
                PDO::PARAM_INT
            );

            $stmtVerifica->bindValue(
                ":usuario_id",
                $interacao->getUsuarioId(),
                PDO::PARAM_INT
            );

            $stmtVerifica->execute();

            if ($stmtVerifica->fetch()) {
                throw new Exception(
                    "Este usuário já curtiu esta publicação."
                );
            }
        }

        /*
         * Uma pessoa pode votar apenas uma vez
         * em uma enquete.
         */
        if (
            $interacao->getTipoInteracao()
            === "Voto_Enquete"
        ) {

            $sqlVerifica = "
                SELECT id
                FROM interacao
                WHERE publicacao_id = :publicacao_id
                  AND usuario_id = :usuario_id
                  AND tipo_interacao = 'Voto_Enquete'
                LIMIT 1
            ";

            $stmtVerifica =
                $this->conexao->prepare(
                    $sqlVerifica
                );

            $stmtVerifica->bindValue(
                ":publicacao_id",
                $interacao->getPublicacaoId(),
                PDO::PARAM_INT
            );

            $stmtVerifica->bindValue(
                ":usuario_id",
                $interacao->getUsuarioId(),
                PDO::PARAM_INT
            );

            $stmtVerifica->execute();

            if ($stmtVerifica->fetch()) {
                throw new Exception(
                    "Este usuário já votou nesta enquete."
                );
            }
        }

        $sql = "
            INSERT INTO interacao (
                publicacao_id,
                usuario_id,
                tipo_interacao,
                conteudo_texto
            ) VALUES (
                :publicacao_id,
                :usuario_id,
                :tipo_interacao,
                :conteudo_texto
            )
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":publicacao_id",
            $interacao->getPublicacaoId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":usuario_id",
            $interacao->getUsuarioId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":tipo_interacao",
            $interacao->getTipoInteracao()
        );

        if (
            $interacao->getConteudoTexto() !== null
        ) {

            $stmt->bindValue(
                ":conteudo_texto",
                $interacao->getConteudoTexto()
            );

        } else {

            $stmt->bindValue(
                ":conteudo_texto",
                null,
                PDO::PARAM_NULL
            );
        }

        return $stmt->execute();
    }

    public function editar(InteracaoDTO $interacao): bool
    {
        $this->validarInteracao($interacao);

        $sql = "
            UPDATE interacao
            SET
                publicacao_id = :publicacao_id,
                tipo_interacao = :tipo_interacao,
                conteudo_texto = :conteudo_texto
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":publicacao_id",
            $interacao->getPublicacaoId(),
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ":tipo_interacao",
            $interacao->getTipoInteracao()
        );

        if (
            $interacao->getConteudoTexto() !== null
        ) {

            $stmt->bindValue(
                ":conteudo_texto",
                $interacao->getConteudoTexto()
            );

        } else {

            $stmt->bindValue(
                ":conteudo_texto",
                null,
                PDO::PARAM_NULL
            );
        }

        $stmt->bindValue(
            ":id",
            $interacao->getId(),
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }

    public function excluir(int $id): bool
    {
        $sql = "
            DELETE FROM interacao
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $id,
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }

    private function validarInteracao(
        InteracaoDTO $interacao
    ): void {

        $tiposPermitidos = [
            "Curtida",
            "Comentario",
            "Voto_Enquete"
        ];

        if (
            !in_array(
                $interacao->getTipoInteracao(),
                $tiposPermitidos,
                true
            )
        ) {
            throw new Exception(
                "Tipo de interação inválido."
            );
        }

        if (
            !$interacao->getPublicacaoId()
        ) {
            throw new Exception(
                "Selecione uma publicação."
            );
        }

        if (
            !$interacao->getUsuarioId()
        ) {
            throw new Exception(
                "Usuário inválido."
            );
        }

        /*
         * Verifica se a publicação existe.
         */
        $sql = "
            SELECT categoria
            FROM publicacao
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":id",
            $interacao->getPublicacaoId(),
            PDO::PARAM_INT
        );

        $stmt->execute();

        $publicacao = $stmt->fetch();

        if (!$publicacao) {
            throw new Exception(
                "A publicação selecionada não existe."
            );
        }

        /*
         * Voto só pode ocorrer em Enquete.
         */
        if (
            $interacao->getTipoInteracao()
            === "Voto_Enquete"
            &&
            $publicacao["categoria"]
            !== "Enquete"
        ) {

            throw new Exception(
                "Voto em enquete só pode ser realizado em uma publicação da categoria Enquete."
            );
        }

        /*
         * Curtida não precisa de texto.
         */
        if (
            $interacao->getTipoInteracao()
            === "Curtida"
        ) {
            return;
        }

        /*
         * Comentário e voto precisam
         * possuir conteúdo.
         */
        if (
            trim(
                (string)
                $interacao->getConteudoTexto()
            ) === ""
        ) {

            throw new Exception(
                $interacao->getTipoInteracao()
                === "Comentario"
                    ? "Digite o comentário."
                    : "Informe a opção do voto."
            );
        }
    }

    private function montarDTO(
        array $row
    ): InteracaoDTO {

        $dto = new InteracaoDTO();

        $dto->setId(
            (int) $row["id"]
        );

        $dto->setPublicacaoId(
            (int) $row["publicacao_id"]
        );

        $dto->setUsuarioId(
            (int) $row["usuario_id"]
        );

        $dto->setTipoInteracao(
            $row["tipo_interacao"]
        );

        $dto->setConteudoTexto(
            $row["conteudo_texto"]
        );

        $dto->setCreatedAt(
            $row["created_at"]
        );

        $dto->setPublicacaoTitulo(
            $row["publicacao_titulo"] ?? null
        );

        $dto->setPublicacaoCategoria(
            $row["publicacao_categoria"] ?? null
        );

        $dto->setUsuarioNome(
            $row["usuario_nome"] ?? null
        );

        return $dto;
    }
}