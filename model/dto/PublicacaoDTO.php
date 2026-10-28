<?php

class PublicacaoDTO
{
    private ?int $id = null;
    private ?int $usuario_id = null;

    private string $categoria = "";
    private string $titulo = "";
    private string $conteudo = "";

    private ?string $midia_url = null;
    private ?float $preco = null;

    private string $status = "Ativo";
    private ?string $created_at = null;
    private ?string $usuario_nome = null;

    // Campos de Achados e Perdidos
    private ?string $tipo_achado = null;
    private ?string $local_ocorrencia = null;
    private ?string $data_ocorrencia = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getUsuarioId(): ?int
    {
        return $this->usuario_id;
    }

    public function setUsuarioId(?int $usuario_id): void
    {
        $this->usuario_id = $usuario_id;
    }

    public function getCategoria(): string
    {
        return $this->categoria;
    }

    public function setCategoria(string $categoria): void
    {
        $this->categoria = $categoria;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): void
    {
        $this->titulo = $titulo;
    }

    public function getConteudo(): string
    {
        return $this->conteudo;
    }

    public function setConteudo(string $conteudo): void
    {
        $this->conteudo = $conteudo;
    }

    public function getMidiaUrl(): ?string
    {
        return $this->midia_url;
    }

    public function setMidiaUrl(?string $midia_url): void
    {
        $this->midia_url = $midia_url;
    }

    public function getPreco(): ?float
    {
        return $this->preco;
    }

    public function setPreco(?float $preco): void
    {
        $this->preco = $preco;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): ?string
    {
        return $this->created_at;
    }

    public function setCreatedAt(?string $created_at): void
    {
        $this->created_at = $created_at;
    }

    public function getUsuarioNome(): ?string
    {
        return $this->usuario_nome;
    }

    public function setUsuarioNome(?string $usuario_nome): void
    {
        $this->usuario_nome = $usuario_nome;
    }

    // ==============================
    // ACHADOS E PERDIDOS
    // ==============================

    public function getTipoAchado(): ?string
    {
        return $this->tipo_achado;
    }

    public function setTipoAchado(?string $tipo_achado): void
    {
        $this->tipo_achado = $tipo_achado;
    }

    public function getLocalOcorrencia(): ?string
    {
        return $this->local_ocorrencia;
    }

    public function setLocalOcorrencia(?string $local_ocorrencia): void
    {
        $this->local_ocorrencia = $local_ocorrencia;
    }

    public function getDataOcorrencia(): ?string
    {
        return $this->data_ocorrencia;
    }

    public function setDataOcorrencia(?string $data_ocorrencia): void
    {
        $this->data_ocorrencia = $data_ocorrencia;
    }
}