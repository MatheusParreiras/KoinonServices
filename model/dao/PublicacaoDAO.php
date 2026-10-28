<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/PublicacaoDTO.php";

class PublicacaoDAO
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
                p.*,
                u.nome AS usuario_nome
            FROM publicacao p
            LEFT JOIN usuario u
                ON u.id = p.usuario_id
            ORDER BY p.created_at DESC
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();

        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lista = [];

        foreach ($dados as $item) {
            $lista[] = $this->montarDTO($item);
        }

        return $lista;
    }

    public function buscarPorId(int $id): ?PublicacaoDTO
    {
        $sql = "
            SELECT
                p.*,
                u.nome AS usuario_nome
            FROM publicacao p
            LEFT JOIN usuario u
                ON u.id = p.usuario_id
            WHERE p.id = ?
            LIMIT 1
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute([$id]);

        $dados = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dados) {
            return null;
        }

        return $this->montarDTO($dados);
    }

    public function cadastrar(PublicacaoDTO $publicacao): bool
    {
        $sql = "
            INSERT INTO publicacao (
                usuario_id,
                categoria,
                titulo,
                conteudo,
                midia_url,
                preco,
                status,
                tipo_achado,
                local_ocorrencia,
                data_ocorrencia
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $this->conexao->prepare($sql);

        return $stmt->execute([
            $publicacao->getUsuarioId(),
            $publicacao->getCategoria(),
            $publicacao->getTitulo(),
            $publicacao->getConteudo(),
            $publicacao->getMidiaUrl(),
            $publicacao->getPreco(),
            $publicacao->getStatus(),
            $publicacao->getTipoAchado(),
            $publicacao->getLocalOcorrencia(),
            $publicacao->getDataOcorrencia()
        ]);
    }

    public function cadastrarComOpcoes(
        PublicacaoDTO $publicacao,
        array $opcoes
    ): bool {
        try {
            $this->conexao->beginTransaction();

            $sql = "
                INSERT INTO publicacao (
                    usuario_id,
                    categoria,
                    titulo,
                    conteudo,
                    midia_url,
                    preco,
                    status,
                    tipo_achado,
                    local_ocorrencia,
                    data_ocorrencia
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = $this->conexao->prepare($sql);

            $stmt->execute([
                $publicacao->getUsuarioId(),
                $publicacao->getCategoria(),
                $publicacao->getTitulo(),
                $publicacao->getConteudo(),
                $publicacao->getMidiaUrl(),
                $publicacao->getPreco(),
                $publicacao->getStatus(),
                $publicacao->getTipoAchado(),
                $publicacao->getLocalOcorrencia(),
                $publicacao->getDataOcorrencia()
            ]);

            $publicacaoId = (int) $this->conexao->lastInsertId();

$publicacao->setId(
    $publicacaoId
);

            $sqlOpcao = "
                INSERT INTO enquete_opcao (
                    publicacao_id,
                    descricao,
                    ordem
                )
                VALUES (?, ?, ?)
            ";

            $stmtOpcao = $this->conexao->prepare($sqlOpcao);

            $ordem = 1;

            foreach ($opcoes as $opcao) {
                $opcao = trim($opcao);

                if ($opcao === "") {
                    continue;
                }

                $stmtOpcao->execute([
                    $publicacaoId,
                    $opcao,
                    $ordem
                ]);

                $ordem++;
            }

            $this->conexao->commit();

            return true;

        } catch (Throwable $e) {

            if ($this->conexao->inTransaction()) {
                $this->conexao->rollBack();
            }

            return false;
        }
    }

    public function editar(PublicacaoDTO $publicacao): bool
    {
        $sql = "
            UPDATE publicacao
            SET
                categoria = ?,
                titulo = ?,
                conteudo = ?,
                midia_url = ?,
                preco = ?,
                status = ?,
                tipo_achado = ?,
                local_ocorrencia = ?,
                data_ocorrencia = ?
            WHERE id = ?
        ";

        $stmt = $this->conexao->prepare($sql);

$sucesso = $stmt->execute([
    $publicacao->getUsuarioId(),
    $publicacao->getCategoria(),
    $publicacao->getTitulo(),
    $publicacao->getConteudo(),
    $publicacao->getMidiaUrl(),
    $publicacao->getPreco(),
    $publicacao->getStatus(),
    $publicacao->getTipoAchado(),
    $publicacao->getLocalOcorrencia(),
    $publicacao->getDataOcorrencia()
]);

if ($sucesso) {
    $publicacao->setId(
        (int) $this->conexao->lastInsertId()
    );
}

return $sucesso;
    }

    public function editarComOpcoes(
        PublicacaoDTO $publicacao,
        array $opcoes
    ): bool {
        try {
            $this->conexao->beginTransaction();

            $sql = "
                UPDATE publicacao
                SET
                    categoria = ?,
                    titulo = ?,
                    conteudo = ?,
                    midia_url = ?,
                    preco = ?,
                    status = ?,
                    tipo_achado = ?,
                    local_ocorrencia = ?,
                    data_ocorrencia = ?
                WHERE id = ?
            ";

            $stmt = $this->conexao->prepare($sql);

            $stmt->execute([
                $publicacao->getCategoria(),
                $publicacao->getTitulo(),
                $publicacao->getConteudo(),
                $publicacao->getMidiaUrl(),
                $publicacao->getPreco(),
                $publicacao->getStatus(),
                $publicacao->getTipoAchado(),
                $publicacao->getLocalOcorrencia(),
                $publicacao->getDataOcorrencia(),
                $publicacao->getId()
            ]);

            $sqlExcluir = "
                DELETE FROM enquete_opcao
                WHERE publicacao_id = ?
            ";

            $stmtExcluir = $this->conexao->prepare($sqlExcluir);

            $stmtExcluir->execute([
                $publicacao->getId()
            ]);

            $sqlOpcao = "
                INSERT INTO enquete_opcao (
                    publicacao_id,
                    descricao,
                    ordem
                )
                VALUES (?, ?, ?)
            ";

            $stmtOpcao = $this->conexao->prepare($sqlOpcao);

            $ordem = 1;

            foreach ($opcoes as $opcao) {
                $opcao = trim($opcao);

                if ($opcao === "") {
                    continue;
                }

                $stmtOpcao->execute([
                    $publicacao->getId(),
                    $opcao,
                    $ordem
                ]);

                $ordem++;
            }

            $this->conexao->commit();

            return true;

        } catch (Throwable $e) {

            if ($this->conexao->inTransaction()) {
                $this->conexao->rollBack();
            }

            return false;
        }
    }

    public function excluir(int $id): bool
    {
        $sql = "
            DELETE FROM publicacao
            WHERE id = ?
        ";

        $stmt = $this->conexao->prepare($sql);

        return $stmt->execute([$id]);
    }

    public function alterarStatus(
        int $id,
        string $status
    ): bool {
        $statusPermitidos = [
            "Ativo",
            "Encerrado",
            "Arquivado",
            "Removido"
        ];

        if (!in_array($status, $statusPermitidos, true)) {
            return false;
        }

        $sql = "
            UPDATE publicacao
            SET status = ?
            WHERE id = ?
        ";

        $stmt = $this->conexao->prepare($sql);

        return $stmt->execute([
            $status,
            $id
        ]);
    }

    public function listarOpcoesEnquete(
        int $publicacaoId
    ): array {
        $sql = "
            SELECT
                id,
                publicacao_id,
                descricao,
                ordem
            FROM enquete_opcao
            WHERE publicacao_id = ?
            ORDER BY ordem ASC
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->execute([
            $publicacaoId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function usuarioJaVotou(
        int $publicacaoId,
        int $usuarioId
    ): bool {
        $sql = "
            SELECT COUNT(*)
            FROM interacao
            WHERE publicacao_id = ?
              AND usuario_id = ?
              AND tipo_interacao = 'Voto_Enquete'
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->execute([
            $publicacaoId,
            $usuarioId
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function votarEnquete(
        int $publicacaoId,
        int $usuarioId,
        int $opcaoId
    ): bool {
        try {
            if (
                $this->usuarioJaVotou(
                    $publicacaoId,
                    $usuarioId
                )
            ) {
                return false;
            }

            $sql = "
                INSERT INTO interacao (
                    publicacao_id,
                    usuario_id,
                    tipo_interacao,
                    enquete_opcao_id
                )
                VALUES (?, ?, 'Voto_Enquete', ?)
            ";

            $stmt = $this->conexao->prepare($sql);

            return $stmt->execute([
                $publicacaoId,
                $usuarioId,
                $opcaoId
            ]);

        } catch (Throwable $e) {
            return false;
        }
    }

    public function contarVotosOpcao(
        int $opcaoId
    ): int {
        $sql = "
            SELECT COUNT(*)
            FROM interacao
            WHERE enquete_opcao_id = ?
              AND tipo_interacao = 'Voto_Enquete'
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->execute([
            $opcaoId
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function totalVotosEnquete(
        int $publicacaoId
    ): int {
        $sql = "
            SELECT COUNT(*)
            FROM interacao
            WHERE publicacao_id = ?
              AND tipo_interacao = 'Voto_Enquete'
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->execute([
            $publicacaoId
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function montarDTO(
        array $dados
    ): PublicacaoDTO {
        $dto = new PublicacaoDTO();

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

        $dto->setCategoria(
            $dados["categoria"] ?? ""
        );

        $dto->setTitulo(
            $dados["titulo"] ?? ""
        );

        $dto->setConteudo(
            $dados["conteudo"] ?? ""
        );

        $dto->setMidiaUrl(
            $dados["midia_url"] ?? null
        );

        $dto->setPreco(
            isset($dados["preco"])
                ? (float) $dados["preco"]
                : null
        );

        $dto->setStatus(
            $dados["status"] ?? "Ativo"
        );

        $dto->setCreatedAt(
            $dados["created_at"] ?? null
        );

        $dto->setUsuarioNome(
            $dados["usuario_nome"] ?? null
        );

        $dto->setTipoAchado(
            $dados["tipo_achado"] ?? null
        );

        $dto->setLocalOcorrencia(
            $dados["local_ocorrencia"] ?? null
        );

        $dto->setDataOcorrencia(
            $dados["data_ocorrencia"] ?? null
        );

        return $dto;
    }
}