<?php

class InteracaoDTO
{
    private ?int $id = null;
    private ?int $publicacao_id = null;
    private ?int $usuario_id = null;
    private string $tipo_interacao = "";
    private ?string $conteudo_texto = null;
    private ?string $created_at = null;

    // Dados auxiliares para exibição
    private ?string $publicacao_titulo = null;
    private ?string $usuario_nome = null;
    private ?string $publicacao_categoria = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getPublicacaoId(): ?int
    {
        return $this->publicacao_id;
    }

    public function setPublicacaoId(?int $publicacao_id): void
    {
        $this->publicacao_id = $publicacao_id;
    }

    public function getUsuarioId(): ?int
    {
        return $this->usuario_id;
    }

    public function setUsuarioId(?int $usuario_id): void
    {
        $this->usuario_id = $usuario_id;
    }

    public function getTipoInteracao(): string
    {
        return $this->tipo_interacao;
    }

    public function setTipoInteracao(string $tipo_interacao): void
    {
        $this->tipo_interacao = $tipo_interacao;
    }

    public function getConteudoTexto(): ?string
    {
        return $this->conteudo_texto;
    }

    public function setConteudoTexto(?string $conteudo_texto): void
    {
        $this->conteudo_texto = $conteudo_texto;
    }

    public function getCreatedAt(): ?string
    {
        return $this->created_at;
    }

    public function setCreatedAt(?string $created_at): void
    {
        $this->created_at = $created_at;
    }

    public function getPublicacaoTitulo(): ?string
    {
        return $this->publicacao_titulo;
    }

    public function setPublicacaoTitulo(?string $publicacao_titulo): void
    {
        $this->publicacao_titulo = $publicacao_titulo;
    }

    public function getUsuarioNome(): ?string
    {
        return $this->usuario_nome;
    }

    public function setUsuarioNome(?string $usuario_nome): void
    {
        $this->usuario_nome = $usuario_nome;
    }

    public function getPublicacaoCategoria(): ?string
    {
        return $this->publicacao_categoria;
    }

    public function setPublicacaoCategoria(?string $publicacao_categoria): void
    {
        $this->publicacao_categoria = $publicacao_categoria;
    }
}