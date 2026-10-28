<?php

class DocumentoDTO
{
    private ?int $id = null;
    private ?int $usuario_upload_id = null;
    private string $titulo = "";
    private string $categoria = "";
    private ?string $descricao = null;
    private string $arquivo_url = "";
    private ?string $data_upload = null;

    // Dados auxiliares para exibição
    private ?string $usuario_nome = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getUsuarioUploadId(): ?int
    {
        return $this->usuario_upload_id;
    }

    public function setUsuarioUploadId(?int $usuario_upload_id): void
    {
        $this->usuario_upload_id = $usuario_upload_id;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): void
    {
        $this->titulo = $titulo;
    }

    public function getCategoria(): string
    {
        return $this->categoria;
    }

    public function setCategoria(string $categoria): void
    {
        $this->categoria = $categoria;
    }

    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    public function setDescricao(?string $descricao): void
    {
        $this->descricao = $descricao;
    }

    public function getArquivoUrl(): string
    {
        return $this->arquivo_url;
    }

    public function setArquivoUrl(string $arquivo_url): void
    {
        $this->arquivo_url = $arquivo_url;
    }

    public function getDataUpload(): ?string
    {
        return $this->data_upload;
    }

    public function setDataUpload(?string $data_upload): void
    {
        $this->data_upload = $data_upload;
    }

    public function getUsuarioNome(): ?string
    {
        return $this->usuario_nome;
    }

    public function setUsuarioNome(?string $usuario_nome): void
    {
        $this->usuario_nome = $usuario_nome;
    }
}