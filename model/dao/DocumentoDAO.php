<?php

require_once __DIR__ . "/Conexao.php";
require_once __DIR__ . "/../dto/DocumentoDTO.php";

class DocumentoDAO
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
                d.*,
                u.nome AS usuario_nome
            FROM documento d

            LEFT JOIN usuario u
                ON d.usuario_upload_id = u.id

            ORDER BY d.data_upload DESC
        ";

        $stmt = $this->conexao->prepare($sql);
        $stmt->execute();

        $lista = [];

        while ($row = $stmt->fetch()) {
            $lista[] = $this->montarDTO($row);
        }

        return $lista;
    }

    public function buscarPorId(int $id): ?DocumentoDTO
    {
        $sql = "
            SELECT
                d.*,
                u.nome AS usuario_nome
            FROM documento d

            LEFT JOIN usuario u
                ON d.usuario_upload_id = u.id

            WHERE d.id = :id
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

    public function cadastrar(DocumentoDTO $documento): bool
    {
        $this->validarDocumento($documento);

        $sql = "
            INSERT INTO documento (
                usuario_upload_id,
                titulo,
                categoria,
                descricao,
                arquivo_url
            ) VALUES (
                :usuario_upload_id,
                :titulo,
                :categoria,
                :descricao,
                :arquivo_url
            )
        ";

        $stmt = $this->conexao->prepare($sql);

        if ($documento->getUsuarioUploadId() !== null) {
            $stmt->bindValue(
                ":usuario_upload_id",
                $documento->getUsuarioUploadId(),
                PDO::PARAM_INT
            );
        } else {
            $stmt->bindValue(
                ":usuario_upload_id",
                null,
                PDO::PARAM_NULL
            );
        }

        $stmt->bindValue(
            ":titulo",
            $documento->getTitulo()
        );

        $stmt->bindValue(
            ":categoria",
            $documento->getCategoria()
        );

        if ($documento->getDescricao() !== null) {
            $stmt->bindValue(
                ":descricao",
                $documento->getDescricao()
            );
        } else {
            $stmt->bindValue(
                ":descricao",
                null,
                PDO::PARAM_NULL
            );
        }

        $stmt->bindValue(
            ":arquivo_url",
            $documento->getArquivoUrl()
        );

        return $stmt->execute();
    }

    public function editar(DocumentoDTO $documento): bool
    {
        $this->validarDocumento($documento);

        $sql = "
            UPDATE documento
            SET
                titulo = :titulo,
                categoria = :categoria,
                descricao = :descricao,
                arquivo_url = :arquivo_url
            WHERE id = :id
        ";

        $stmt = $this->conexao->prepare($sql);

        $stmt->bindValue(
            ":titulo",
            $documento->getTitulo()
        );

        $stmt->bindValue(
            ":categoria",
            $documento->getCategoria()
        );

        if ($documento->getDescricao() !== null) {
            $stmt->bindValue(
                ":descricao",
                $documento->getDescricao()
            );
        } else {
            $stmt->bindValue(
                ":descricao",
                null,
                PDO::PARAM_NULL
            );
        }

        $stmt->bindValue(
            ":arquivo_url",
            $documento->getArquivoUrl()
        );

        $stmt->bindValue(
            ":id",
            $documento->getId(),
            PDO::PARAM_INT
        );

        return $stmt->execute();
    }

    public function excluir(int $id): bool
    {
        $sql = "
            DELETE FROM documento
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

    private function validarDocumento(
        DocumentoDTO $documento
    ): void {

        $categoriasPermitidas = [
            "Atas",
            "Convenção",
            "Regimento_Interno",
            "Prestação_Contas",
            "Contrato",
            "Outros"
        ];

        if (
            trim($documento->getTitulo()) === ""
        ) {
            throw new Exception(
                "Informe o título do documento."
            );
        }

        if (
            !in_array(
                $documento->getCategoria(),
                $categoriasPermitidas,
                true
            )
        ) {
            throw new Exception(
                "Categoria de documento inválida."
            );
        }

        if (
            trim($documento->getArquivoUrl()) === ""
        ) {
            throw new Exception(
                "O arquivo do documento é obrigatório."
            );
        }
    }

    private function montarDTO(
        array $row
    ): DocumentoDTO {

        $dto = new DocumentoDTO();

        $dto->setId(
            (int) $row["id"]
        );

        if ($row["usuario_upload_id"] !== null) {
            $dto->setUsuarioUploadId(
                (int) $row["usuario_upload_id"]
            );
        }

        $dto->setTitulo(
            $row["titulo"]
        );

        $dto->setCategoria(
            $row["categoria"]
        );

        $dto->setDescricao(
            $row["descricao"]
        );

        $dto->setArquivoUrl(
            $row["arquivo_url"]
        );

        $dto->setDataUpload(
            $row["data_upload"]
        );

        $dto->setUsuarioNome(
            $row["usuario_nome"] ?? null
        );

        return $dto;
    }
}